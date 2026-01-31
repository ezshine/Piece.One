<?php

/**
 * dropmoneyrecovery.php - Verify and confirm Drop Money transaction
 * 
 * Similar to buyrecovery.php, this API:
 * 1. Takes a txHash and dropId
 * 2. Verifies the transaction on-chain
 * 3. Checks the token balance received
 * 4. Updates the op_squares collection
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/rpc_helper.php';

use MongoDB\Client;

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['errcode' => 1, 'errmsg' => 'Only POST method is allowed']);
    return;
}

// Get input
$input = '';
if (isset($GLOBALS['request'])) {
    $request = $GLOBALS['request'];
    if (method_exists($request, 'rawBody')) {
        $input = $request->rawBody();
    } elseif (method_exists($request, 'body')) {
        $input = $request->body();
    }
}
if (empty($input)) {
    $input = file_get_contents('php://input');
}

$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['errcode' => 1, 'errmsg' => 'Invalid JSON format']);
    return;
}

// Validate required parameters
if (!isset($data['txHash']) || !isset($data['dropId'])) {
    echo json_encode(['errcode' => 2, 'errmsg' => 'Missing required parameters (txHash, dropId)']);
    return;
}

$txHash = $data['txHash'];
$dropId = $data['dropId'];

// Validate txHash format
if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
    echo json_encode(['errcode' => 3, 'errmsg' => 'Invalid txHash format']);
    return;
}

// Redis connection
$redisHost = getenv('REDIS_HOST') ?: 'redis';
$redisPort = getenv('REDIS_PORT') ?: 6379;
$redis = new Redis();
try {
    $redis->connect($redisHost, $redisPort);
} catch (Exception $e) {
    echo json_encode(['errcode' => 500, 'errmsg' => 'Redis connection error']);
    return;
}

// Find pending drop from Redis
$redisKey = 'drop:pending:' . $dropId;
$dropJson = $redis->get($redisKey);
if (!$dropJson) {
    // 尝试获取所有匹配的 key 进行调试
    $allKeys = $redis->keys('drop:pending:*');
    echo json_encode([
        'errcode' => 4, 
        'errmsg' => 'Drop not found or expired',
        'debug' => [
            'searchedKey' => $redisKey,
            'existingKeys' => count($allKeys),
            'dropId' => $dropId
        ]
    ]);
    return;
}

$drop = json_decode($dropJson, true);

// Debug: 确认 drop 数据结构
if (!isset($drop['address']) || !isset($drop['tokenContract'])) {
    echo json_encode([
        'errcode' => 4, 
        'errmsg' => 'Invalid drop data structure',
        'debug' => ['keys' => array_keys($drop)]
    ]);
    return;
}

// MongoDB connection
try {
    global $mongoClient;
    if (isset($mongoClient)) {
        $client = $mongoClient;
    } else {
        $mongoUri = getenv('MONGO_URI');
        $client = new Client($mongoUri);
    }
    $db = $client->test;
    $squaresCollection = $db->op_squares;
} catch (Exception $e) {
    echo json_encode(['errcode' => 500, 'errmsg' => 'Database connection error']);
    return;
}

// Check if txHash already used
$existingDrop = $squaresCollection->findOne(['txHash' => $txHash]);
if ($existingDrop) {
    echo json_encode([
        'errcode' => 0,
        'errmsg' => 'Transaction already confirmed',
        'data' => [
            'x' => $existingDrop['x'],
            'y' => $existingDrop['y'],
            'tokenSymbol' => $existingDrop['tokenSymbol'] ?? 'TOKEN',
            'amount' => $existingDrop['amountFormatted'] ?? $existingDrop['amount'],
            'status' => 'confirmed'
        ]
    ]);
    return;
}

// Base L2 RPC endpoint
$rpcEndpoint = getenv('BASE_RPC_URL') ?: 'https://mainnet.base.org';

// Get transaction details from chain
$txDetails = getTransactionDetails($txHash, $rpcEndpoint);

if (!$txDetails) {
    echo json_encode(['errcode' => 5, 'errmsg' => 'Transaction not found on blockchain']);
    return;
}

// Check transaction status
if ($txDetails['status'] !== '0x1') {
    echo json_encode(['errcode' => 6, 'errmsg' => 'Transaction failed on blockchain']);
    return;
}

// Check confirmations (need at least 3)
if (!$txDetails['confirmed']) {
    echo json_encode([
        'errcode' => 7,
        'errmsg' => 'Transaction not confirmed yet',
        'data' => [
            'confirmations' => $txDetails['confirmations'],
            'required' => 3
        ]
    ]);
    return;
}

// 使用 Transfer 事件日志验证转账（支持直接转账和通过 Delegation 的转账）
$dropAddress = strtolower($drop['address']);
$tokenContract = $drop['tokenContract'];

$paidAmount = parseTransferLogs(
    $txDetails['logs'] ?? [],
    $tokenContract,
    $dropAddress
);

if ($paidAmount === '0') {
    // 添加调试信息
    $logsCount = count($txDetails['logs'] ?? []);
    echo json_encode([
        'errcode' => 8,
        'errmsg' => 'No token transfer to drop wallet found in transaction',
        'debug' => [
            'logsCount' => $logsCount,
            'tokenContract' => $tokenContract,
            'dropAddress' => $dropAddress
        ]
    ]);
    return;
}

// 变量已在上面定义，此处直接使用
$targetAddress = $drop['address'];

// Query balance using RPC
$paddedAddress = str_pad(substr(strtolower($targetAddress), 2), 64, '0', STR_PAD_LEFT);
$balanceData = '0x70a08231' . $paddedAddress;
$balanceResult = callRPC($rpcEndpoint, 'eth_call', [
    ['to' => $tokenContract, 'data' => $balanceData],
    'latest'
]);

$actualBalanceWei = '0';
if ($balanceResult && $balanceResult !== '0x') {
    $actualBalanceWei = gmp_strval(gmp_init($balanceResult, 16));
}

if ($actualBalanceWei === '0') {
    echo json_encode(['errcode' => 10, 'errmsg' => 'No tokens received at target address']);
    return;
}

// Format the balance for display
$decimals = $drop['tokenDecimals'];
// 使用 gmp 代替 bc 函数（环境没有 bcmath 扩展）
$divisor = gmp_pow('10', (int)$decimals);
$balanceGmp = gmp_init($actualBalanceWei);
$intPart = gmp_div_q($balanceGmp, $divisor);
$remainder = gmp_mod($balanceGmp, $divisor);
$remainderStr = str_pad(gmp_strval($remainder), $decimals, '0', STR_PAD_LEFT);
$formattedBalance = gmp_strval($intPart) . '.' . $remainderStr;
// Remove trailing zeros
$formattedBalance = rtrim(rtrim($formattedBalance, '0'), '.');

// Create square item in MongoDB
$squareData = [
    'type' => 'money',
    'x' => $drop['x'],
    'y' => $drop['y'],
    'w' => 100,
    'h' => 100,
    'tokenContract' => $drop['tokenContract'],
    'tokenSymbol' => $drop['tokenSymbol'],
    'tokenDecimals' => $drop['tokenDecimals'],
    'amount' => $actualBalanceWei,
    'amountFormatted' => $formattedBalance,
    'wallet_address' => $drop['address'],
    'wallet_private_key' => $drop['privateKey'],
    'txHash' => $txHash,
    'status' => 1, // 1 = available, 2 = claimed
    'createdAt' => new MongoDB\BSON\UTCDateTime(),
];

$squaresCollection->insertOne($squareData);

// Delete from Redis
$redis->del($redisKey);

echo json_encode([
    'errcode' => 0,
    'errmsg' => 'Drop confirmed successfully',
    'data' => [
        'x' => $drop['x'],
        'y' => $drop['y'],
        'tokenSymbol' => $drop['tokenSymbol'],
        'amount' => $formattedBalance,
        'actualReceived' => $formattedBalance,
        'confirmations' => $txDetails['confirmations']
    ]
]);
