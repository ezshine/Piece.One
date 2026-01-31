<?php

/**
 * dropmoney.php - Drop Any Token API
 * 
 * Two-step process:
 * 1. action=create: Generate wallet, store in Redis (1 hour TTL)
 * 2. action=confirm: Verify transaction on-chain and save to MongoDB
 * 
 * Supports any ERC20/BEP20 token including tokens with transfer taxes.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client;
use Web3p\EthereumUtil\Util;

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

if (!isset($data['action'])) {
    echo json_encode(['errcode' => 2, 'errmsg' => 'Missing action parameter']);
    return;
}

$action = $data['action'];

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

// Base L2 RPC endpoint
$rpcUrl = getenv('BASE_RPC_URL') ?: 'https://mainnet.base.org';

/**
 * Make a JSON-RPC call to Base L2 node
 */
function rpcCall($method, $params = []) {
    global $rpcUrl;
    
    $payload = [
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
        'id' => 1
    ];
    
    $ch = curl_init($rpcUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || $response === false) {
        return null;
    }
    
    $result = json_decode($response, true);
    return $result['result'] ?? null;
}

/**
 * Get ERC20 token balance of an address
 */
function getTokenBalance($tokenContract, $address) {
    // balanceOf(address) = 0x70a08231
    $paddedAddress = str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);
    $data = '0x70a08231' . $paddedAddress;
    
    $result = rpcCall('eth_call', [
        ['to' => $tokenContract, 'data' => $data],
        'latest'
    ]);
    
    if ($result === null || $result === '0x') {
        return '0';
    }
    
    // Convert hex to decimal string (handle large numbers)
    return gmp_strval(gmp_init($result, 16));
}

/**
 * Wait for transaction confirmation and return receipt
 */
function waitForTransactionReceipt($txHash, $maxAttempts = 30, $intervalSeconds = 2) {
    for ($i = 0; $i < $maxAttempts; $i++) {
        $receipt = rpcCall('eth_getTransactionReceipt', [$txHash]);
        
        if ($receipt !== null) {
            return $receipt;
        }
        
        sleep($intervalSeconds);
    }
    
    return null;
}

// MongoDB connection helper
function getMongoCollection() {
    global $mongoClient, $squaresCollection;
    if ($squaresCollection) return $squaresCollection;
    
    try {
        if (isset($GLOBALS['mongoClient'])) {
            $mongoClient = $GLOBALS['mongoClient'];
        } else {
            $mongoUri = getenv('MONGO_URI');
            $mongoClient = new Client($mongoUri);
        }
        $db = $mongoClient->test;
        $squaresCollection = $db->op_squares;
        return $squaresCollection;
    } catch (Exception $e) {
        return null;
    }
}

// ========== ACTION: CREATE ==========
if ($action === 'create') {
    // Validate parameters
    if (!isset($data['amount']) || floatval($data['amount']) <= 0) {
        echo json_encode(['errcode' => 3, 'errmsg' => 'Invalid amount']);
        return;
    }
    
    // Token parameters (required for any token)
    $tokenContract = isset($data['tokenContract']) ? strtolower($data['tokenContract']) : '';
    $tokenSymbol = isset($data['tokenSymbol']) ? $data['tokenSymbol'] : '';
    $tokenDecimals = isset($data['tokenDecimals']) ? (int)$data['tokenDecimals'] : 18;
    
    if (empty($tokenContract) || !preg_match('/^0x[a-fA-F0-9]{40}$/', $tokenContract)) {
        echo json_encode(['errcode' => 3, 'errmsg' => 'Invalid token contract address']);
        return;
    }
    
    if (empty($tokenSymbol)) {
        echo json_encode(['errcode' => 3, 'errmsg' => 'Token symbol is required']);
        return;
    }
    
    $amount = floatval($data['amount']);
    $x = isset($data['x']) ? (int)$data['x'] : null;
    $y = isset($data['y']) ? (int)$data['y'] : null;
    
    // Generate random position if not provided
    if ($x === null || $y === null) {
        $range = 100000;
        $cellSize = 100;
        // Align to grid (100x100)
        $x = (int)(floor(mt_rand(-$range, $range) / $cellSize) * $cellSize);
        $y = (int)(floor(mt_rand(-$range, $range) / $cellSize) * $cellSize);
        
        // Avoid the center area (禁售区)
        $nfsSize = 500;
        while ($x > -$nfsSize && $x < $nfsSize && $y > -$nfsSize && $y < $nfsSize) {
            $x = (int)(floor(mt_rand(-$range, $range) / $cellSize) * $cellSize);
            $y = (int)(floor(mt_rand(-$range, $range) / $cellSize) * $cellSize);
        }
    }
    
    // Generate new EVM wallet
    $privateKeyBytes = random_bytes(32);
    $privateKey = '0x' . bin2hex($privateKeyBytes);
    
    // Convert private key to address using web3p utility
    $util = new Util();
    $publicKey = $util->privateKeyToPublicKey($privateKey);
    $address = $util->publicKeyToAddress($publicKey);
    
    // Generate drop ID
    $dropId = bin2hex(random_bytes(16));
    
    // Encrypt private key before storage
    $encryptionKey = getenv('WALLET_ENCRYPTION_KEY') ?: 'default_key_change_in_production';
    $iv = random_bytes(16);
    $encryptedPrivateKey = openssl_encrypt(
        $privateKey,
        'AES-256-CBC',
        hash('sha256', $encryptionKey, true),
        OPENSSL_RAW_DATA,
        $iv
    );
    $encryptedData = base64_encode($iv . $encryptedPrivateKey);
    
    // Save pending drop to Redis with 1 hour TTL
    $dropData = [
        'dropId' => $dropId,
        'address' => strtolower($address),
        'privateKey' => $encryptedData,
        'amount' => $amount,
        'tokenContract' => $tokenContract,
        'tokenSymbol' => $tokenSymbol,
        'tokenDecimals' => $tokenDecimals,
        'x' => $x,
        'y' => $y,
        'createdAt' => time(),
    ];
    
    $redisKey = 'drop:pending:' . $dropId;
    $redis->setex($redisKey, 3600, json_encode($dropData)); // 1 hour TTL
    
    echo json_encode([
        'errcode' => 0,
        'errmsg' => 'Wallet created',
        'data' => [
            'dropId' => $dropId,
            'address' => strtolower($address),
            'x' => $x,
            'y' => $y,
        ]
    ]);
    return;
}

// ========== ACTION: CONFIRM ==========
if ($action === 'confirm') {
    if (!isset($data['dropId']) || !isset($data['txHash'])) {
        echo json_encode(['errcode' => 3, 'errmsg' => 'Missing dropId or txHash']);
        return;
    }
    
    $dropId = $data['dropId'];
    $txHash = $data['txHash'];
    
    // Validate txHash format
    if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
        echo json_encode(['errcode' => 4, 'errmsg' => 'Invalid txHash format']);
        return;
    }
    
    // Find pending drop from Redis
    $redisKey = 'drop:pending:' . $dropId;
    $dropJson = $redis->get($redisKey);
    if (!$dropJson) {
        echo json_encode(['errcode' => 5, 'errmsg' => 'Drop not found or expired']);
        return;
    }
    
    $drop = json_decode($dropJson, true);
    
    // Get MongoDB collection
    $squaresCollection = getMongoCollection();
    if (!$squaresCollection) {
        echo json_encode(['errcode' => 500, 'errmsg' => 'Database connection error']);
        return;
    }
    
    // Check if txHash already used
    $existingDrop = $squaresCollection->findOne(['txHash' => $txHash]);
    if ($existingDrop) {
        echo json_encode(['errcode' => 6, 'errmsg' => 'Transaction hash already used']);
        return;
    }
    
    // Wait for transaction confirmation
    $receipt = waitForTransactionReceipt($txHash, 30, 2);
    if ($receipt === null) {
        echo json_encode(['errcode' => 7, 'errmsg' => 'Transaction not confirmed within timeout']);
        return;
    }
    
    // Check transaction status (0x1 = success)
    $txStatus = $receipt['status'] ?? '0x0';
    if ($txStatus !== '0x1') {
        echo json_encode(['errcode' => 8, 'errmsg' => 'Transaction failed on-chain']);
        return;
    }
    
    // Get the actual token balance of the receiving address
    // This handles tax tokens correctly - we store what was actually received
    $actualBalanceWei = getTokenBalance($drop['tokenContract'], $drop['address']);
    
    if ($actualBalanceWei === '0' || $actualBalanceWei === '') {
        echo json_encode(['errcode' => 9, 'errmsg' => 'No tokens received at target address']);
        return;
    }
    
    // Format the balance for display
    $decimals = $drop['tokenDecimals'];
    $divisor = bcpow('10', (string)$decimals);
    $formattedBalance = bcdiv($actualBalanceWei, $divisor, $decimals);
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
        'amount' => $actualBalanceWei, // Store Wei value as string
        'amountFormatted' => $formattedBalance, // Human-readable amount
        'wallet_address' => $drop['address'],
        'wallet_private_key' => $drop['privateKey'],
        'txHash' => $txHash,
        'status' => 1, // 1 = available, 2 = claimed
        'createdAt' => new MongoDB\BSON\UTCDateTime(),
    ];
    
    $squaresCollection->insertOne($squareData);
    
    // Delete from Redis (已确认，无需保留)
    $redis->del($redisKey);
    
    echo json_encode([
        'errcode' => 0,
        'errmsg' => 'Drop confirmed successfully',
        'data' => [
            'x' => $drop['x'],
            'y' => $drop['y'],
            'tokenSymbol' => $drop['tokenSymbol'],
            'amount' => $formattedBalance,
            'actualReceived' => $formattedBalance
        ]
    ]);
    return;
}

echo json_encode(['errcode' => 10, 'errmsg' => 'Unknown action']);
