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
            return;
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
            return;
}

// 验证必需参数
if (!isset($data['txHash']) || !isset($data['walletAddress'])) {
    echo json_encode([
        'errcode' => 2,
        'errmsg' => 'Missing required parameters'
    ]);
            return;
}

$txHash = $data['txHash'];
$walletAddress = strtolower($data['walletAddress']);

// 验证参数格式
if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
    echo json_encode([
        'errcode' => 3,
        'errmsg' => 'Invalid transaction hash format'
    ]);
            return;
}

if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $walletAddress)) {
    echo json_encode([
        'errcode' => 4,
        'errmsg' => 'Invalid wallet address format'
    ]);
            return;
}

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
    
    // 检查订单是否存在
    $existingPurchase = $purchasesCollection->findOne(['txHash' => $txHash]);
    
    if (!$existingPurchase) {
        echo json_encode([
            'errcode' => 7,
            'errmsg' => 'Transaction not found. Please make purchase first.'
        ]);
                return;
    }
    
    // 如果已经确认过了
    if ($existingPurchase['status'] === 'confirmed') {
        echo json_encode([
            'errcode' => 0,
            'errmsg' => 'Transaction already confirmed',
            'data' => [
                'purchaseId' => (string)$existingPurchase['_id'],
                'status' => 'confirmed',
                'totalLands' => count($existingPurchase['lands'])
            ]
        ]);
                return;
    }
    
    // 从链上验证交易
    require_once __DIR__ . '/rpc_helper.php';
    
    // 使用 Base L2 公开 RPC 端点
    $rpcEndpoint = getenv('BASE_RPC_URL') ?: 'https://mainnet.base.org';
    
    // USDC 代币合约地址和收款地址配置（Base L2 链上）
    $USD1_TOKEN_ADDRESS = '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913';
    $RECIPIENT_ADDRESS = '0x58Af1c29F282988b19852D0747E00A26c30D6aAa';
    
    // 查询链上交易详情
    $txDetails = getTransactionDetails($txHash, $rpcEndpoint);
    
    if (!$txDetails) {
        echo json_encode([
            'errcode' => 8,
            'errmsg' => 'Failed to fetch transaction from blockchain'
        ]);
                return;
    }
    
    // 验证交易状态
    if ($txDetails['status'] !== '0x1') {
        echo json_encode([
            'errcode' => 9,
            'errmsg' => 'Transaction failed on blockchain'
        ]);
                return;
    }
    
    // 验证交易确认数
    if (!$txDetails['confirmed']) {
        echo json_encode([
            'errcode' => 10,
            'errmsg' => 'Transaction not confirmed yet (need at least 3 confirmations)'
        ]);
                return;
    }
    
    // 注意：不再验证 txDetails['from'] === walletAddress
    // 因为用户可能通过代理合约（如 MetaMask Delegation）发送交易
    // Transfer 事件日志验证已足够确保支付到账
    
    // 使用 Transfer 事件日志验证支付（支持直接转账和通过中间合约的转账）
    $paidAmount = parseTransferLogs(
        $txDetails['logs'] ?? [],
        $USD1_TOKEN_ADDRESS,
        $RECIPIENT_ADDRESS
    );
    
    if ($paidAmount === '0') {
        // 添加调试信息
        $logsCount = count($txDetails['logs'] ?? []);
        echo json_encode([
            'errcode' => 12,
            'errmsg' => 'No USDC transfer to recipient found in transaction',
            'debug' => [
                'logsCount' => $logsCount,
                'tokenContract' => $USD1_TOKEN_ADDRESS,
                'recipientAddress' => $RECIPIENT_ADDRESS,
                'logs' => array_map(function($log) {
                    return [
                        'address' => $log['address'] ?? 'N/A',
                        'topics' => $log['topics'] ?? [],
                        'data' => substr($log['data'] ?? '', 0, 66) . '...'
                    ];
                }, $txDetails['logs'] ?? [])
            ]
        ]);
                return;
    }
    
    // 验证金额（USDC 有 6 位小数）
    $expectedAmount = (int)round($existingPurchase['totalPrice'] * 1000000); // Convert to smallest unit
    $paidAmountInt = (int)$paidAmount;
    if ($paidAmountInt < $expectedAmount) {
        echo json_encode([
            'errcode' => 15,
            'errmsg' => 'Payment amount insufficient. Expected: ' . $expectedAmount . ', Got: ' . $paidAmountInt
        ]);
                return;
    }
    
    // === 全有或全无 (All or Nothing) 逻辑检查 ===
    // 在这之前不修改任何数据
    
    $landsToProcess = []; // 准备处理的数据
    $priceBase = floatval(getenv('LAND_BASE_PRICE')); // 基础价格
    $totalValidatedPrice = 0;
    $shouldRefund = false;
    $refundReason = '';
    
    // 计算平均每块地的实付价格
    $paidPricePerLand = $existingPurchase['totalPrice'] / count($existingPurchase['lands']);
    
    foreach ($existingPurchase['lands'] as $land) {
        $existing = $squaresCollection->findOne([
            'x' => $land['x'],
            'y' => $land['y'],
            'status' => 2 // 已售
        ]);
        
        if ($existing) {
            // == 尝试回购 ==
            // 检查价格是否足够 (last_price * 2)
            $lastPrice = isset($existing['last_price']) ? $existing['last_price'] : $priceBase;
            $requiredPrice = $lastPrice * 2;
            
            // 允许微小的浮动误差 (0.001)
            if ($paidPricePerLand < ($requiredPrice - 0.001)) {
                $shouldRefund = true;
                $refundReason = "Insufficient payment for land ({$land['x']}, {$land['y']}). Required: $requiredPrice, Paid: $paidPricePerLand";
                break;
            }
            
            // 记录回购操作
            $landsToProcess[] = [
                'action' => 'update',
                'filter' => ['_id' => $existing['_id']],
                'update' => [
                    '$set' => [
                        'owner' => $walletAddress,
                        'last_price' => $paidPricePerLand,
                        'updatedAt' => new MongoDB\BSON\UTCDateTime()
                    ]
                ]
            ];
            
        } else {
            // == 普通购买 ==
            // 检查价格是否足够基础价格
            if ($paidPricePerLand < ($priceBase - 0.001)) {
                $shouldRefund = true;
                $refundReason = "Insufficient payment for land ({$land['x']}, {$land['y']}). Required: $priceBase, Paid: $paidPricePerLand";
                break;
            }
            
            // 记录新购操作
            $landsToProcess[] = [
                'action' => 'insert',
                'data' => [
                    'type' => 'land',
                    'x' => $land['x'],
                    'y' => $land['y'],
                    'w' => $land['w'] ?? 100,
                    'h' => $land['h'] ?? 100,
                    'owner' => $walletAddress,
                    'text' => $land['text'] ?? '',
                    'status' => 2,
                    'last_price' => $paidPricePerLand,
                    'purchaseId' => (string)$existingPurchase['_id'],
                    'txHash' => $txHash,
                    'createdAt' => new MongoDB\BSON\UTCDateTime(),
                    'updatedAt' => new MongoDB\BSON\UTCDateTime()
                ]
            ];
        }
    }
    
    // === 执行阶段 ===
    
    if ($shouldRefund) {
        // 标记整个订单为待退款
        $purchasesCollection->updateOne(
            ['_id' => $existingPurchase['_id']],
            ['$set' => [
                'status' => 'refund_pending',
                'refundReason' => $refundReason,
                'confirmedAt' => new MongoDB\BSON\UTCDateTime(),
                'confirmations' => $txDetails['confirmations']
            ]]
        );
        
        echo json_encode([
            'errcode' => 20, // 特殊错误码表示变为退款状态
            'errmsg' => 'Purchase failed due to price/status change. Funds marked for refund.',
            'data' => [
                'status' => 'refund_pending',
                'reason' => $refundReason
            ]
        ]);
                return;
    }
    
    // 一切正常，执行数据更新
    $purchasesCollection->updateOne(
        ['_id' => $existingPurchase['_id']],
        ['$set' => [
            'status' => 'confirmed',
            'confirmedAt' => new MongoDB\BSON\UTCDateTime(),
            'confirmations' => $txDetails['confirmations']
        ]]
    );
    
    // 批量执行地块更新
    foreach ($landsToProcess as $op) {
        if ($op['action'] === 'insert') {
            $squaresCollection->insertOne($op['data']);
        } elseif ($op['action'] === 'update') {
            $squaresCollection->updateOne($op['filter'], $op['update']);
        }
    }
    
    echo json_encode([
        'errcode' => 0,
        'errmsg' => 'Purchase confirmed successfully',
        'data' => [
            'purchaseId' => (string)$existingPurchase['_id'],
            'status' => 'confirmed',
            'totalLands' => count($landsToProcess),
            'confirmations' => $txDetails['confirmations']
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'errcode' => 500,
        'errmsg' => 'Server error: ' . $e->getMessage()
    ]);
}
