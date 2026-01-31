<?php

/**
 * claimitem.php - Claim USD1 item API
 * 
 * Requires MetaMask signature verification
 * Uses atomic findOneAndUpdate to prevent race conditions
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
$userX = isset($data['userX']) ? (int)$data['userX'] : 0;
$userY = isset($data['userY']) ? (int)$data['userY'] : 0;
$claimedUser = isset($data['claimedUser']) ? $data['claimedUser'] : '';

// Validate timestamp (within 5 minutes)
$now = time();
if (abs($now - $timestamp) > 300) {
    echo json_encode(['errcode' => 3, 'errmsg' => 'Request expired or timestamp invalid']);
    return;
}

// Verify signature and recover wallet address FIRST (before any DB operations)
$message = "Claim Item\nID: $itemId\nTime: $timestamp";

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
    $claimerWallet = strtolower($util->publicKeyToAddress($pubKey));
    
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

// ATOMIC OPERATION: Find and update in one step to prevent race conditions
// Only the first user to execute this will get the item (status changes from 1 to 2)
try {
    $item = $squaresCollection->findOneAndUpdate(
        [
            '_id' => new ObjectId($itemId),
            'type' => 'money',
            'status' => 1 // Must be available
        ],
        [
            '$set' => [
                'status' => 2, // Mark as claimed immediately
                'claimedWallet' => $claimerWallet,
                'claimedUser' => $claimedUser,
                'claimedAt' => new MongoDB\BSON\UTCDateTime()
            ]
        ],
        [
            'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_BEFORE
        ]
    );
} catch (Exception $e) {
    echo json_encode(['errcode' => 5, 'errmsg' => 'Invalid item ID']);
    return;
}

// If item is null, it means either:
// 1. Item doesn't exist
// 2. Item was already claimed (status != 1)
// 3. Another user claimed it first (race condition prevented!)
if (!$item) {
    echo json_encode(['errcode' => 6, 'errmsg' => 'Item not found or already claimed']);
    return;
}

// Verify user position (must be within 150px of item center)
$itemCenterX = $item['x'] + ($item['w'] / 2);
$itemCenterY = $item['y'] + ($item['h'] / 2);
$distance = sqrt(pow($userX - $itemCenterX, 2) + pow($userY - $itemCenterY, 2));
$maxClaimDistance = 150;

if ($distance > $maxClaimDistance) {
    // Rollback: restore status to 1
    $squaresCollection->updateOne(
        ['_id' => $item['_id']],
        [
            '$set' => ['status' => 1],
            '$unset' => ['claimedWallet' => '', 'claimedAt' => '']
        ]
    );
    
    echo json_encode([
        'errcode' => 7, 
        'errmsg' => 'Too far from item. Move closer to claim.',
        'data' => [
            'distance' => round($distance),
            'maxDistance' => $maxClaimDistance
        ]
    ]);
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
    // Rollback on decryption failure
    $squaresCollection->updateOne(
        ['_id' => $item['_id']],
        [
            '$set' => ['status' => 1],
            '$unset' => ['claimedWallet' => '', 'claimedAt' => '']
        ]
    );
    echo json_encode(['errcode' => 500, 'errmsg' => 'Failed to decrypt wallet key']);
    return;
}

// Success!
echo json_encode([
    'errcode' => 0,
    'errmsg' => 'Item claimed successfully',
    'data' => [
        'privateKey' => $privateKey,
        'address' => $item['wallet_address'],
        'amount' => isset($item['amountFormatted']) ? $item['amountFormatted'] : $item['amount'],
        'tokenContract' => isset($item['tokenContract']) ? $item['tokenContract'] : null,
        'tokenSymbol' => isset($item['tokenSymbol']) ? $item['tokenSymbol'] : (isset($item['currency']) ? $item['currency'] : 'USD1'),
        'tokenDecimals' => isset($item['tokenDecimals']) ? $item['tokenDecimals'] : 18,
        'claimedWallet' => $claimerWallet
    ]
]);
