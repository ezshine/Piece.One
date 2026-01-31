<?php

// RPC 辅助函数 - 使用公开的 Polygon RPC 端点

/**
 * 调用 JSON-RPC 方法
 */
function callRPC($endpoint, $method, $params = []) {
    $data = json_encode([
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
        'id' => 1
    ]);
    
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data)
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $result = json_decode($response, true);
    return isset($result['result']) ? $result['result'] : null;
}

/**
 * 获取交易详情
 */
function getTransactionDetails($txHash, $rpcEndpoint) {
    $tx = callRPC($rpcEndpoint, 'eth_getTransactionByHash', [$txHash]);
    
    if (!$tx) {
        return null;
    }
    
    // 获取交易收据（包含确认状态）
    $receipt = callRPC($rpcEndpoint, 'eth_getTransactionReceipt', [$txHash]);
    
    if (!$receipt) {
        return null;
    }
    
    // 获取最新区块号
    $latestBlock = callRPC($rpcEndpoint, 'eth_blockNumber', []);
    $latestBlockNum = hexdec($latestBlock);
    $txBlockNum = hexdec($receipt['blockNumber']);
    
    // 计算确认数
    $confirmations = $latestBlockNum - $txBlockNum;
    
    return [
        'from' => $tx['from'],
        'to' => $tx['to'],
        'value' => $tx['value'],
        'input' => $tx['input'],
        'hash' => $tx['hash'],
        'blockNumber' => $txBlockNum,
        'confirmations' => $confirmations,
        'status' => $receipt['status'], // 0x1 = success, 0x0 = failed
        'confirmed' => $confirmations >= 3,  // 至少3个确认
        'logs' => $receipt['logs'] ?? []  // 包含事件日志
    ];
}

/**
 * 从交易 input 数据中解析 ERC-20 transfer 的参数
 */
function parseERC20Transfer($input) {
    // ERC-20 transfer 格式: 0xa9059cbb + (32字节地址) + (32字节金额)
    if (strlen($input) < 138) { // 0x + 8 + 64 + 64
        return null;
    }
    
    $methodId = substr($input, 0, 10); // 0xa9059cbb
    if ($methodId !== '0xa9059cbb') {
        return null; // 不是 transfer 方法
    }
    
    // 解析接收地址 (去掉前导0)
    $toAddress = '0x' . substr($input, 34, 40);
    
    // 解析金额 (hex to decimal)
    $amountHex = substr($input, 74, 64);
    $amount = hexdec($amountHex);
    
    return [
        'to' => strtolower($toAddress),
        'amount' => $amount  // Wei
    ];
}

/**
 * Parse TreasureBridge drop transaction input
 * Method ID: 0x...(drop)
 * sig: drop(address,address,uint256)
 */
function parseTreasureBridgeDrop($input) {
    // drop(address token, address to, uint256 amount)
    // keccak256("drop(address,address,uint256)") = 0x520f5627
    
    if (strlen($input) < 202) { // 0x + 8 + 64 + 64 + 64
        return null;
    }

    $methodId = substr($input, 0, 10);
    if ($methodId !== '0x520f5627') {
        return null;
    }

    $token = '0x' . substr($input, 34, 40);
    $to = '0x' . substr($input, 98, 40);
    $amountHex = substr($input, 138, 64);
    $amount = hexdec($amountHex);

    return [
        'token' => strtolower($token),
        'to' => strtolower($to),
        'amount' => $amount
    ];
}

/**
 * 解析交易日志中的 ERC-20 Transfer 事件
 * Transfer(address indexed from, address indexed to, uint256 value)
 * Topic0: 0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef
 */
function parseTransferLogs($logs, $tokenContract, $recipientAddress) {
    $TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    $tokenContract = strtolower($tokenContract);
    $recipientAddress = strtolower($recipientAddress);
    
    $totalAmount = '0';
    
    foreach ($logs as $log) {
        // 检查是否是 Transfer 事件
        if (!isset($log['topics']) || count($log['topics']) < 3) {
            continue;
        }
        
        if (strtolower($log['topics'][0]) !== $TRANSFER_TOPIC) {
            continue;
        }
        
        // 检查是否是目标代币合约
        if (strtolower($log['address']) !== $tokenContract) {
            continue;
        }
        
        // 解析接收地址 (topic[2] 是 to 地址)
        $toAddress = '0x' . substr($log['topics'][2], 26);
        
        if (strtolower($toAddress) !== $recipientAddress) {
            continue;
        }
        
        // 解析金额 (data 字段)
        $amountHex = $log['data'];
        $amount = gmp_strval(gmp_init($amountHex, 16));
        
        // 累加金额
        $totalAmount = gmp_strval(gmp_add($totalAmount, $amount));
    }
    
    return $totalAmount;
}
