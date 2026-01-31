<?php

/**
 * getstatics.php - 获取统计数据 API（优化版）
 * 使用单个聚合管道一次性获取所有统计数据
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client;

header('Content-Type: application/json');

try {
    global $mongoClient;
    if (isset($mongoClient)) {
        $client = $mongoClient;
    } else {
        $mongoUri = getenv('MONGO_URI');
        $client = new Client($mongoUri);
    }
    $collection = $client->test->op_squares;
    
    // 单个聚合管道统计所有数据
    $pipeline = [
        // 使用 $facet 并行执行多个聚合
        [
            '$facet' => [
                // 统计已购买的土地数量
                'lands' => [
                    ['$match' => ['type' => 'land', 'status' => 2]],
                    ['$count' => 'count']
                ],
                // 统计 money 数据按 status 分组
                'money' => [
                    ['$match' => ['type' => 'money']],
                    [
                        '$group' => [
                            '_id' => '$status',
                            'count' => ['$sum' => 1],
                            'amount' => ['$sum' => '$amount']
                        ]
                    ]
                ]
            ]
        ]
    ];
    
    $result = $collection->aggregate($pipeline)->toArray();
    
    // 解析结果
    $lands = 0;
    $unclaimed = 0;
    $unclaimedAmount = 0;
    $claimed = 0;
    $claimedAmount = 0;
    
    if (!empty($result)) {
        $data = $result[0];
        
        // 土地数量
        if (!empty($data['lands'])) {
            $lands = $data['lands'][0]['count'];
        }
        
        // Money 统计
        if (!empty($data['money'])) {
            foreach ($data['money'] as $row) {
                if ($row['_id'] === 1) {
                    $unclaimed = $row['count'];
                    $unclaimedAmount = $row['amount'];
                } elseif ($row['_id'] === 2) {
                    $claimed = $row['count'];
                    $claimedAmount = $row['amount'];
                }
            }
        }
    }
    
    echo json_encode([
        'errcode' => 0,
        'errmsg' => 'success',
        'data' => [
            'lands' => $lands,
            'unclaimed' => $unclaimed,
            'unclaimedAmount' => $unclaimedAmount,
            'claimed' => $claimed,
            'claimedAmount' => $claimedAmount
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'errcode' => 1,
        'errmsg' => $e->getMessage(),
        'data' => null
    ]);
}
