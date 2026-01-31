<?php
/**
 * 清空 op_squares 和 op_purchases 集合的脚本
 * 
 * 使用方法:
 *   php clear_collections.php
 * 
 * 警告: 此操作不可逆，请谨慎使用！
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client;

// 加载环境变量
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // 处理变量引用 ${VAR}
        $value = preg_replace_callback('/\$\{([^}]+)\}/', function($matches) {
            return getenv($matches[1]) ?: '';
        }, $value);
        
        putenv("$key=$value");
    }
}

echo "=== 清空 op_squares 和 op_purchases 集合 ===\n\n";

try {
    // 连接数据库
    $mongoUri = getenv('MONGO_URI');
    if (!$mongoUri) {
        throw new Exception("MONGO_URI 环境变量未设置");
    }
    
    echo "连接数据库...\n";
    $client = new Client($mongoUri);
    $db = $client->test;
    
    // 清空 op_squares 集合
    $squaresCollection = $db->op_squares;
    $squaresCount = $squaresCollection->countDocuments();
    echo "op_squares 集合当前有 {$squaresCount} 条记录\n";
    
    $squaresResult = $squaresCollection->deleteMany([]);
    echo "已删除 op_squares 集合中的 {$squaresResult->getDeletedCount()} 条记录\n\n";
    
    // 清空 op_purchases 集合
    $purchasesCollection = $db->op_purchases;
    $purchasesCount = $purchasesCollection->countDocuments();
    echo "op_purchases 集合当前有 {$purchasesCount} 条记录\n";
    
    $purchasesResult = $purchasesCollection->deleteMany([]);
    echo "已删除 op_purchases 集合中的 {$purchasesResult->getDeletedCount()} 条记录\n\n";
    
    echo "=== 清空完成 ===\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
