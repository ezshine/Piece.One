<?php

// 获取 Workerman 的 request 对象
// start_api.php 中定义了 $GLOBALS['request']
$request = $GLOBALS['request'];
$rawBody = $request->rawBody();
$inputData = json_decode($rawBody, true);

$provider = $inputData['provider'] ?? 'siliconflow';
$prompt = trim($inputData['prompt'] ?? '') !== ''
    ? $inputData['prompt']
    : 'You are a real human player in a game chat, never an AI or language model, always reply in the same language as the user, Chinese replies must be 10–15 characters, English replies 5–10 words, very short casual indifferent and slightly cold, never polite or explanatory, no emojis no punctuation at the end, sometimes make small natural typos, reply like typing quickly during gameplay, if asked about identity or origin never mention AI LLM model system or assistant and respond like a normal human player';
$content = $inputData['content'] ?? '';

$providerApiUrl = '';
$providerApiKey = '';
$providerApiBody = '';

if ($provider == 'siliconflow') {
    $providerApiUrl = 'https://api.siliconflow.cn/v1/chat/completions';
    $providerApiKey = getenv('SILLICONFLOW_KEY');

    $providerApiBody = json_encode([
        'model' => "deepseek-ai/DeepSeek-V3.1",
        'messages' => [
            ['role' => "system", 'content' => $prompt],
            ['role' => "user", 'content' => $content]
        ],
        'stream' => false,
        'enable_thinking' => false,
        'temperature' => 0.9,
        'max_tokens' => 20,
        'top_p' => 1,
        'frequency_penalty' => 0,
        'presence_penalty' => 0,
        'response_format' => ['type' => "text"]
    ]);

} else {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid provider: ' . $provider
    ]);
    return;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $providerApiUrl);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $providerApiBody);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $providerApiKey,
    'Content-Type: application/json'
]);

// 增加超时设置，防止请求卡死
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode([
        'success' => false,
        'error' => 'Curl error: ' . $curlError
    ]);
    return;
}

$responseData = json_decode($response, true);

if (isset($responseData['choices'][0]['message']['content'])) {
    //
    echo json_encode([
        'success' => true,
        'data' => $responseData['choices'][0]['message']['content']
    ]);
} else {
    // 尝试返回上游的错误信息，如果没有则返回通用错误
    $errorMessage = $responseData['error']['message'] ?? 'Unknown error from provider';
    echo json_encode([
        'success' => false,
        'error' => $errorMessage,
        'raw_response' => $responseData
    ]);
}
