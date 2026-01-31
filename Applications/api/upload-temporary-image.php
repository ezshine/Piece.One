<?php
/**
 * upload-temporary-image.php
 * Proxy for uploading temporary images to litterbox.catbox.moe
 * Accepts JSON: { "image": "data:image/png;base64,...", "filename": "..." }
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['errcode' => 1, 'errmsg' => 'Only POST method is allowed']);
    return;
}

// Get raw input
$input = '';
if (isset($GLOBALS['request'])) {
    if (method_exists($GLOBALS['request'], 'rawBody')) {
        $input = $GLOBALS['request']->rawBody();
    } elseif (method_exists($GLOBALS['request'], 'body')) {
        $input = $GLOBALS['request']->body();
    }
}
if (empty($input)) {
    $input = file_get_contents('php://input');
}

// Debug raw input
error_log("Upload Proxy Raw Input Length: " . strlen($input));
error_log("Upload Proxy Raw Input Preview: " . substr($input, 0, 100));


$data = json_decode($input, true);
if (!$data || !isset($data['image'])) {
    echo json_encode(['errcode' => 2, 'errmsg' => 'Invalid JSON or missing image data']);
    return;
}

// Decode Base64 image
$base64String = $data['image'];
// Remove data URI scheme header if present (e.g., data:image/png;base64,)
if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
    $base64String = substr($base64String, strpos($base64String, ',') + 1);
    $type = strtolower($type[1]); // jpg, png, gif
    if (!in_array($type, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
         echo json_encode(['errcode' => 3, 'errmsg' => 'Invalid image type']);
         return;
    }
} else {
     echo json_encode(['errcode' => 3, 'errmsg' => 'Invalid base64 string']);
     return;
}

$base64String = str_replace(' ', '+', $base64String);
$imageData = base64_decode($base64String);

if ($imageData === false) {
    echo json_encode(['errcode' => 4, 'errmsg' => 'Base64 decode failed']);
    return;
}

// Create temp file
$tmpFilePath = tempnam(sys_get_temp_dir(), 'upl');
if (file_put_contents($tmpFilePath, $imageData) === false) {
     echo json_encode(['errcode' => 5, 'errmsg' => 'Failed to save temp file']);
     return;
}

$fileName = $data['filename'] ?? ('image.' . $type);
$mimeType = 'image/' . $type;

$upstreamUrl = 'https://litterbox.catbox.moe/resources/internals/api.php';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $upstreamUrl);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

// Create CURLFile
$cfile = new CURLFile($tmpFilePath, $mimeType, $fileName);
$postData = [
    'reqtype' => 'fileupload',
    'time' => '1h',
    'fileToUpload' => $cfile
];

curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo json_encode(['errcode' => 500, 'errmsg' => 'Curl error: ' . curl_error($ch)]);
} else {
    echo $response;
}

curl_close($ch);

// Cleanup
@unlink($tmpFilePath);
