<?php

// CORS Logic handled in start_api.php

require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client;

// ========== 获取并验证视口参数 ==========
$viewX = isset($_GET['x']) ? (int)$_GET['x'] : 0;
$viewY = isset($_GET['y']) ? (int)$_GET['y'] : 0;
$viewW = isset($_GET['w']) ? (int)$_GET['w'] : 1920;
$viewH = isset($_GET['h']) ? (int)$_GET['h'] : 1080;

// 限制 viewW 和 viewH 最大值为 1000
$maxViewSize = 1920;
$viewW = min($viewW, $maxViewSize);
$viewH = min($viewH, $maxViewSize);

// ========== 安全检查（IP 频率限制） ==========
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ipHash = md5($clientIP);
$rateLimitKey = 'rate_limit_getsquares_' . $ipHash;
$rateLimitSeconds = 1; // 冷却时间（秒）

$isRateLimited = false;
$redis = null;

try {
    // 检查 Redis 扩展是否可用
    if (!class_exists('Redis')) {
        throw new Exception('Redis extension not available');
    }
    
    $redisHost = getenv('REDIS_HOST') ?: 'localhost';
    $redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
    
    $redis = new Redis();
    $connected = @$redis->connect($redisHost, $redisPort, 0.5);
    
    if (!$connected) {
        throw new Exception('Redis connection failed');
    }
    
    // 检查 IP 频率限制
    $isRateLimited = $redis->exists($rateLimitKey);
    
    // 更新频率限制（仅在未被限制时）
    if (!$isRateLimited) {
        $redis->setex($rateLimitKey, $rateLimitSeconds, 1);
    }
    
    $redis->close();
} catch (Exception $e) {
    // Redis 连接失败时，允许请求通过（降级处理）
    if ($redis) {
        @$redis->close();
    }
}

if ($isRateLimited) {
    // 静默返回空数据，不暴露限制行为
    echo json_encode([
        'errcode' => 0,
        'errmsg' => 'success.',
        'data' => []
    ]);
    return;
}

// 视口边界
$viewRight = $viewX + $viewW;
$viewBottom = $viewY + $viewH;

try {
    global $mongoClient;
    if (isset($mongoClient)) {
        $client = $mongoClient;
    } else {
        $mongoUri = getenv('MONGO_URI');
        $client = new Client($mongoUri);
    }
    $collection = $client->test->op_squares;
    
    // 24小时前的时间戳
    $hoursAgo24 = new MongoDB\BSON\UTCDateTime((time() - 24 * 60 * 60) * 1000);
    
    // 优化：使用 range query 利用索引 (x, y)
    // 假设最大物品尺寸为 3000 (足以覆盖大部分大图)
    $maxItemSize = 3000;
    
    // 基础范围筛选：利用索引快速缩小范围
    // x 必须在 [viewX - maxW, viewRight] 之间才**可能**相交
    // y 必须在 [viewY - maxH, viewBottom] 之间才**可能**相交
    $query = [
        'x' => ['$gte' => $viewX - $maxItemSize, '$lt' => $viewRight],
        'y' => ['$gte' => $viewY - $maxItemSize, '$lt' => $viewBottom],
        '$or' => [
            // 不是 money 类型
            ['type' => ['$ne' => 'money']],
            // money 且未领取
            ['type' => 'money', 'status' => 1],
            // money 且已领取但在 24 小时内
            ['type' => 'money', 'status' => 2, 'claimedAt' => ['$gte' => $hoursAgo24]]
        ]
    ];
    
    $cursor = $collection->find($query);
    
    $result = [];
    $occupied = [];
    $cellSize = 100;
    
    foreach ($cursor as $document) {
        $item = [
            '_id' => (string)$document['_id'], // MongoDB ObjectId 转字符串
            'x' => $document['x'],
            'y' => $document['y'],
            'w' => $document['w'],
            'h' => $document['h']
        ];
        
        // PHP 侧进行严格的视口碰撞检测 (Double Check)
        // 排除掉仅仅是还在 Padding 范围内但实际不相交的物体
        if (!($item['x'] < $viewRight && ($item['x'] + $item['w']) > $viewX &&
              $item['y'] < $viewBottom && ($item['y'] + $item['h']) > $viewY)) {
            continue;
        }
        // type 和 status 作为可选字段
        if (isset($document['type'])) $item['type'] = $document['type'];
        if (isset($document['status'])) $item['status'] = $document['status'];
        // 可选字段
        if (isset($document['image'])) $item['image'] = $document['image'];
        if (isset($document['text'])) $item['text'] = $document['text'];
        if (isset($document['link'])) $item['link'] = $document['link'];
        if (isset($document['last_price'])) $item['last_price'] = $document['last_price'];
        if (isset($document['owner'])) $item['owner'] = $document['owner'];
        if (isset($document['skin'])) {
            $item['skin'] = json_decode(json_encode($document['skin']), true);
        }
        if (isset($document['npcs'])) {
            $item['npcs'] = json_decode(json_encode($document['npcs']), true);
        }
        // Token item fields (不返回私钥)
        if (isset($document['amount'])) $item['amount'] = $document['amount'];
        if (isset($document['amountFormatted'])) $item['amountFormatted'] = $document['amountFormatted'];
        if (isset($document['currency'])) $item['currency'] = $document['currency'];
        if (isset($document['tokenContract'])) $item['tokenContract'] = $document['tokenContract'];
        if (isset($document['tokenSymbol'])) $item['tokenSymbol'] = $document['tokenSymbol'];
        if (isset($document['tokenDecimals'])) $item['tokenDecimals'] = $document['tokenDecimals'];
        if (isset($document['wallet_address'])) $item['wallet_address'] = $document['wallet_address'];
        
        // Handle createdAt
        if (isset($document['createdAt'])) {
            $val = $document['createdAt'];
            if ($val instanceof MongoDB\BSON\UTCDateTime) {
                $item['createdAt'] = $val->toDateTime()->getTimestamp() * 1000;
            } else {
                $item['createdAt'] = $val;
            }
        }

        // Handle claimedAt
        if (isset($document['claimedAt'])) {
            $val = $document['claimedAt'];
            if ($val instanceof MongoDB\BSON\UTCDateTime) {
                $item['claimedAt'] = $val->toDateTime()->getTimestamp() * 1000;
            } else {
                $item['claimedAt'] = $val;
            }
        }

        if (isset($document['claimedUser'])) $item['claimedUser'] = $document['claimedUser'];
        if (isset($document['claimedWallet'])) $item['claimedWallet'] = $document['claimedWallet'];
        // 注意：wallet_private_key 不返回给前端
        
        $result[] = $item;
        
        // 标记被占用的网格
        // 假设地块都是按照 100x100 对齐的
        for ($ox = $item['x']; $ox < $item['x'] + $item['w']; $ox += $cellSize) {
            for ($oy = $item['y']; $oy < $item['y'] + $item['h']; $oy += $cellSize) {
                $occupied["$ox,$oy"] = true;
            }
        }
    }
    
    // 生成空闲地块
    // 对齐视口到网格
    $startX = floor($viewX / $cellSize) * $cellSize;
    $startY = floor($viewY / $cellSize) * $cellSize;
    $endX = ceil(($viewX + $viewW) / $cellSize) * $cellSize;
    $endY = ceil(($viewY + $viewH) / $cellSize) * $cellSize;
    
    $nfsSize = 0; // 禁售区像素宽度
    $maxGridSize = 1000000; // 最大世界范围

    for ($x = $startX; $x < $endX; $x += $cellSize) {
        for ($y = $startY; $y < $endY; $y += $cellSize) {
            // 检查最大范围
            if ($x <= -$maxGridSize || $x >= $maxGridSize || $y <= -$maxGridSize || $y >= $maxGridSize) {
                continue;
            }

            // 检查禁售区
            if ($x > -$nfsSize && $x < $nfsSize && $y > -$nfsSize && $y < $nfsSize) {
                $result[] = [
                    'x' => $x,
                    'y' => $y,
                    'w' => $cellSize,
                    'h' => $cellSize,
                    'type' => 'land',
                    'status'=> 0
                ];
                continue;
            }

            if (!isset($occupied["$x,$y"])) {
                $result[] = [
                    'x' => $x,
                    'y' => $y,
                    'w' => $cellSize,
                    'h' => $cellSize,
                    'type' => 'land',
                    'status'=> 1
                ];
            }
        }
    }
    
    // 排序：type='land' 排在前面，其它类型排在后面
    usort($result, function($a, $b) {
        $aType = $a['type'] ?? '';
        $bType = $b['type'] ?? '';
        $aIsLand = ($aType === 'land') ? 0 : 1;
        $bIsLand = ($bType === 'land') ? 0 : 1;
        return $aIsLand - $bIsLand;
    });
    
    $data = [
        'errcode' => 0,
        'errmsg' => 'success',
        'data' => $result
    ];
} catch (Exception $e) {
    $data = [
        'errcode' => 1,
        'errmsg' => $e->getMessage(),
        'data' => []
    ];
}

echo json_encode($data);