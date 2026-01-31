<?php

/**
 * getclaimedinfo.php - Get claimed item private key
 * 
 * Only the claimer can retrieve the private key by verifying signature
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
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

// Validate required parameters
if (!isset($data['itemId']) || !isset($data['signature']) || !isset($data['timestamp'])) {
    echo json_encode(['errcode' => 2, 'errmsg' => 'Missing required parameters (itemId, signature, timestamp)']);
    return;
}

$itemId = $data['itemId'];
$signature = $data['signature'];
$timestamp = (int)$data['timestamp'];

// Validate timestamp (within 5 minutes)
$now = time();
if (abs($now - $timestamp) > 300) {
    echo json_encode(['errcode' => 3, 'errmsg' => 'Request expired or timestamp invalid']);
    return;
}

// Verify signature and recover wallet address
$message = "View Private Key\nID: $itemId\nTime: $timestamp";

$util = new Util();

try {
    $prefix = sprintf("\x19Ethereum Signed Message:\n%d", strlen($message));
    $hash = $util->sha3($prefix . $message);

    $sig = $signature;
    if (strpos($sig, '0x') === 0) {
        $sig = substr($sig, 2);
    }
    
    if (strlen($sig) !== 130) {
        throw new Exception("Invalid signature length");
    }
    
    $r = substr($sig, 0, 64);
    $s = substr($sig, 64, 64);
    $v = hexdec(substr($sig, 128, 2));
    
    if ($v >= 27) {
        $v -= 27;
    }
    
    if ($v > 3) {
        $v = 0;
    }
    
    $pubKey = $util->recoverPublicKey($hash, $r, $s, $v);
    $requestWallet = strtolower($util->publicKeyToAddress($pubKey));
    
} catch (Exception $e) {
    echo json_encode(['errcode' => 4, 'errmsg' => 'Signature verification failed: ' . $e->getMessage()]);
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

// Find the item
try {
    $item = $squaresCollection->findOne([
        '_id' => new ObjectId($itemId),
        'type' => 'money',
        'status' => 2 // Must be claimed
    ]);
} catch (Exception $e) {
    echo json_encode(['errcode' => 5, 'errmsg' => 'Invalid item ID']);
    return;
}

if (!$item) {
    echo json_encode(['errcode' => 6, 'errmsg' => 'Item not found or not claimed']);
    return;
}

// CRITICAL: Verify request wallet matches claimedWallet
$claimedWallet = strtolower($item['claimedWallet'] ?? '');
if ($requestWallet !== $claimedWallet) {
    echo json_encode(['errcode' => 7, 'errmsg' => 'Only the claimer can view the private key']);
    return;
}

// Decrypt private key
$encryptionKey = getenv('WALLET_ENCRYPTION_KEY') ?: 'default_key_change_in_production';
$encryptedData = base64_decode($item['wallet_private_key']);
$iv = substr($encryptedData, 0, 16);
$ciphertext = substr($encryptedData, 16);
$privateKey = openssl_decrypt(
    $ciphertext,
    'AES-256-CBC',
    hash('sha256', $encryptionKey, true),
    OPENSSL_RAW_DATA,
    $iv
);

if ($privateKey === false) {
    echo json_encode(['errcode' => 500, 'errmsg' => 'Failed to decrypt wallet key']);
    return;
}

// Success!
echo json_encode([
    'errcode' => 0,
    'errmsg' => 'Private key retrieved successfully',
    'data' => [
        'privateKey' => $privateKey,
        'address' => $item['wallet_address'],
        'amount' => $item['amount'],
        'currency' => isset($item['currency']) ? $item['currency'] : 'USD1'
    ]
]);
