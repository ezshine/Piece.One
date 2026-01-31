<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client;
use Web3p\EthereumUtil\Util;

// CORS Logic handled in start_api.php or inherited if included

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

if (!isset($data['signature']) || !isset($data['timestamp'])) {
    echo json_encode(['errcode' => 2, 'errmsg' => 'Missing parameters']);
    return;
}

$signature = $data['signature'];
$timestamp = (int)$data['timestamp'];
$newText = trim($data['text'] ?? '');
$newLink = trim($data['link'] ?? '');
$removeImageGlobal = isset($data['removeImage']) ? (bool)$data['removeImage'] : false;

// 1. Verify Timestamp (prevent replay attacks, e.g., within 5 minutes)
if (abs(time() - $timestamp) > 300) {
    echo json_encode(['errcode' => 3, 'errmsg' => 'Request expired or timestamp invalid']);
    return;
}

try {
    global $mongoClient;
    if (isset($mongoClient)) {
        $client = $mongoClient;
    } else {
        $mongoUri = getenv('MONGO_URI');
        $client = new Client($mongoUri);
    }
    
    $collection = $client->test->op_squares;
    $util = new Util();

    // Determine Mode: Batch vs Single
    $isBatch = isset($data['squares']) && is_array($data['squares']);
    $squaresToUpdate = [];

    if ($isBatch) {
        $squaresToUpdate = $data['squares'];
        
        // Construct Batch Message
        // Format: "Batch Update {N} Lands\n(x1,y1), (x2,y2)...\nText: ...\nLink: ...\nTime: ..."
        $coordsList = [];
        foreach ($squaresToUpdate as $sq) {
            $coordsList[] = "(" . $sq['x'] . "," . $sq['y'] . ")";
        }
        $coordsString = implode(", ", $coordsList);
        $count = count($squaresToUpdate);
        
        $message = "Batch Update $count Lands\n$coordsString\nText: $newText\nLink: $newLink\nTime: $timestamp";
        
    } else {
        // Single Mode (Legacy)
        if (!isset($data['x']) || !isset($data['y'])) {
             echo json_encode(['errcode' => 2, 'errmsg' => 'Missing x/y or squares']);
             return;
        }
        
        $x = (int)$data['x'];
        $y = (int)$data['y'];
        $newImage = $data['image'] ?? null;
        
        $squaresToUpdate[] = [
            'x' => $x,
            'y' => $y,
            'image' => $newImage,
            'removeImage' => $removeImageGlobal
        ];

        // Construct Single Message
        if ($removeImageGlobal) {
            $imageStatus = 'Removed';
        } else {
            $imageStatus = $newImage ? 'Yes' : 'Unchanged';
        }
        
        $message = "Update Land ($x, $y)\nText: $newText\nLink: $newLink\nImage: $imageStatus\nTime: $timestamp";
    }

    // 2. Verify Signature
    $prefix = sprintf("\x19Ethereum Signed Message:\n%d", strlen($message));
    $hash = $util->sha3($prefix . $message);
    
    try {
        $sig = $signature;
        if (strpos($sig, '0x') === 0) $sig = substr($sig, 2);
        
        $r = substr($sig, 0, 64);
        $s = substr($sig, 64, 64);
        $v = hexdec(substr($sig, 128, 2));
        
        if ($v >= 27) $v -= 27;
        if ($v > 3) $v = 0;
        
        $pubKey = $util->recoverPublicKey($hash, $r, $s, $v);
        $recoveredAddress = strtolower($util->publicKeyToAddress($pubKey));
        
    } catch (\Exception $e) {
        echo json_encode(['errcode' => 5, 'errmsg' => 'Signature verification failed: ' . $e->getMessage()]);
        return;
    }

    // 3. Process Updates
    $successCount = 0;
    
    foreach ($squaresToUpdate as $sq) {
        $x = (int)$sq['x'];
        $y = (int)$sq['y'];
        
        // Check Ownership
        $land = $collection->findOne(['x' => $x, 'y' => $y, '$or' => [['status' => 2], ['status' => 3]]]);
        
        if (!$land) {
             if ($isBatch) continue; // Skip if not found in batch
             else {
                 echo json_encode(['errcode' => 4, 'errmsg' => 'Land not found']);
                 return;
             }
        }
        
        if (strtolower($land['owner']) !== $recoveredAddress) {
             if ($isBatch) continue; // Skip if not owned in batch
             else {
                 echo json_encode(['errcode' => 6, 'errmsg' => 'Permission denied']);
                 return;
             }
        }
        
        // Prepare Update Data
        $updateOps = ['$set' => [
            'text' => $newText, 
            'link' => $newLink,
            'updatedAt' => new MongoDB\BSON\UTCDateTime()
        ]];
        
        $sqRemoveImage = isset($sq['removeImage']) ? (bool)$sq['removeImage'] : $removeImageGlobal;
        $sqImage = $sq['image'] ?? null;

        if ($sqRemoveImage) {
            $updateOps['$unset'] = ['image' => ""];
        } elseif ($sqImage) {
            $updateOps['$set']['image'] = $sqImage;
        }
        
        $collection->updateOne(['_id' => $land['_id']], $updateOps);
        $successCount++;
    }
    
    if ($successCount === 0) {
        echo json_encode(['errcode' => 7, 'errmsg' => 'No lands were updated. Check ownership.']);
        return;
    }
    
    echo json_encode([
        'errcode' => 0, 
        'errmsg' => 'Land updated successfully',
        'data' => [
            'count' => $successCount,
            'text' => $newText,
            'link' => $newLink
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode(['errcode' => 500, 'errmsg' => 'Server error: ' . $e->getMessage()]);
}
