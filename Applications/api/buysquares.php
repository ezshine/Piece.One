<?php

// CORS Logic handled in start_api.php

require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client;

header('Content-Type: application/json');

// 只接受 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'errcode' => 1,
        'errmsg' => 'Only POST method is allowed'
    ]);
    exit;
}

// 在 Workerman 环境下，需要使用全局 $request 对象
$input = '';

if (isset($GLOBALS['request'])) {
    $request = $GLOBALS['request'];
    // 尝试获取原始 body
    if (method_exists($request, 'rawBody')) {
        $input = $request->rawBody();
    } elseif (method_exists($request, 'body')) {
        $input = $request->body();
    }
}

// 如果还是空的，尝试从 $_POST 或 php://input 获取
if (empty($input)) {
    // 检查是否有 JSON POST 数据
    if (!empty($_POST)) {
        $input = json_encode($_POST);
    } else {
        $input = file_get_contents('php://input');
    }
}

$data = json_decode($input, true);

// 检查 JSON 解析是否成功
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'errcode' => 1,
        'errmsg' => 'Invalid JSON format: ' . json_last_error_msg()
    ]);
    exit;
}

// 检查是否是补录请求
$isRecovery = isset($data['isRecovery']) && $data['isRecovery'] === true;

// 验证必需参数
if (!isset($data['txHash']) || !isset($data['walletAddress'])) {
    echo json_encode([
        'errcode' => 2,
        'errmsg' => 'Missing required parameters'
    ]);
    exit;
}

// 补录请求不需要 lands 和 totalPrice，会从链上查询
if (!$isRecovery && (!isset($data['lands']) || !isset($data['totalPrice']))) {
    echo json_encode([
        'errcode' => 2,
        'errmsg' => 'Missing required parameters for purchase'
    ]);
    exit;
}

$txHash = $data['txHash'];
$walletAddress = strtolower($data['walletAddress']);
$lands = $isRecovery ? null : $data['lands'];
$totalPrice = $isRecovery ? null : floatval($data['totalPrice']);

// 验证参数格式
if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
    echo json_encode([
        'errcode' => 3,
        'errmsg' => 'Invalid transaction hash format'
    ]);
    exit;
}

if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $walletAddress)) {
    echo json_encode([
        'errcode' => 4,
        'errmsg' => 'Invalid wallet address format'
    ]);
    exit;
}

if (!$isRecovery && (!is_array($lands) || empty($lands))) {
    echo json_encode([
        'errcode' => 5,
        'errmsg' => 'Invalid lands data'
    ]);
    exit;
}

// 检查是否是预检查请求
$checkOnly = isset($data['checkOnly']) && $data['checkOnly'] === true;

try {
    // 连接数据库
    global $mongoClient;
    if (isset($mongoClient)) {
        $client = $mongoClient;
    } else {
        $mongoUri = getenv('MONGO_URI');
        $client = new Client($mongoUri);
    }
    
    $db = $client->test;
    $purchasesCollection = $db->op_purchases;
    $squaresCollection = $db->op_squares;
    
    // 如果是预检查或普通购买，都需要验证地块状态
    if (!$isRecovery) {
        $validLands = [];
        $totalExpectedPrice = 0;
        $priceBase = floatval(getenv('LAND_BASE_PRICE')); // 默认基础价格，应与配置一致
        $errorLands = [];

        foreach ($lands as $land) {
            $existing = $squaresCollection->findOne([
                'x' => $land['x'],
                'y' => $land['y'],
                'status' => 2 // 已售
            ]);
            
            if ($existing) {
                // 已售地块，检查是否允许加倍购买
                // 预期价格为 last_price * 2，如果没有 last_price 则使用 priceBase * 2
                $lastPrice = isset($existing['last_price']) ? $existing['last_price'] : $priceBase;
                $expectedPrice = $lastPrice * 2;
                $totalExpectedPrice += $expectedPrice;
            } else {
                // 未售地块
                $totalExpectedPrice += $priceBase;
            }
        }
        
        // 如果是预检查
        if ($checkOnly) {
            echo json_encode([
                'errcode' => 0,
                'errmsg' => 'Verification passed',
                'data' => [
                    'allowed' => true,
                    'totalExpectedPrice' => $totalExpectedPrice
                ]
            ]);
            return;
        }
        
        // 普通购买继续执行订单创建...
    }
    
    // 检查交易哈希是否已被使用
    $existingPurchase = $purchasesCollection->findOne(['txHash' => $txHash]);
    
    if ($existingPurchase) {
        echo json_encode([
            'errcode' => 6,
            'errmsg' => 'Transaction hash already used',
            'data' => [
                'purchaseId' => (string)$existingPurchase['_id'],
                'status' => $existingPurchase['status']
            ]
        ]);
        exit;
    }
    
    // 创建待确认订单
    $purchaseData = [
        'txHash' => $txHash,
        'walletAddress' => $walletAddress,
        'lands' => $lands,
        'totalPrice' => $totalPrice, // 前端传来的价格，后续 recover 时会校验
        'status' => 'pending',  // 待确认状态
        'createdAt' => new MongoDB\BSON\UTCDateTime(),
        'updatedAt' => new MongoDB\BSON\UTCDateTime()
    ];
    
    $result = $purchasesCollection->insertOne($purchaseData);
    
    echo json_encode([
        'errcode' => 0,
        'errmsg' => 'Purchase submitted successfully',
        'data' => [
            'purchaseId' => (string)$result->getInsertedId(),
            'status' => 'pending',
            'totalLands' => count($lands),
            'message' => 'Please wait for blockchain confirmation'
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'errcode' => 500,
        'errmsg' => 'Server error: ' . $e->getMessage()
    ]);
}
