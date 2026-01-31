<?php 
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
use \Workerman\Worker;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Connection\TcpConnection;

use MongoDB\Client;

require_once __DIR__ . '/../../vendor/autoload.php';

// WebServer
$web = new Worker("http://0.0.0.0:8383");
// WebServer数量
$web->count = 2;

$web->name = 'api';

$web->onWorkerStart = function($worker) {
    global $mongoClient;
    $mongoUri = getenv('MONGO_URI');
    try {
        $mongoClient = new Client($mongoUri);
    } catch (\Exception $e) {
        // Log error or handle it
        echo "Failed to connect to MongoDB: " . $e->getMessage() . "\n";
    }
};

define('APIROOT', realpath(__DIR__ . '/../api'));

$web->onMessage = function (TcpConnection $connection, Request $request) {
    $path = $request->path();
    
    // CORS Logic
    $origin = $request->header('origin');
    $allow_origin = '*'; // Default fallback, or null if you want to be strict
    
    $allowed_origins = [];

    // Read from ENV
    $env_origins = getenv('ALLOWED_ORIGINS');
    if($env_origins){
        $allowed_origins = array_merge($allowed_origins, explode(',', $env_origins));
    }

    if ($origin && in_array($origin, $allowed_origins)) {
        $allow_origin = $origin;
    }

    // CORS Headers
    $headers = [
        'Access-Control-Allow-Origin' => $allow_origin,
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-Original-Filename',
        'Access-Control-Allow-Credentials' => 'true'
    ];

    if ($request->method() === 'OPTIONS') {
        $connection->send(new Response(200, $headers));
        return;
    }

    if ($path === '/') {
        $content = exec_php_file(APIROOT.'/index.php', $request);
        $connection->send(new Response(200, $headers, $content));
        return;
    }
    $file = realpath(APIROOT. $path);
    if (false === $file) {
        $connection->send(new Response(404, $headers, '<h3>404 Not Found</h3>'));
        return;
    }
    // Security check! Very important!!!
    if (strpos($file, APIROOT) !== 0) {
        $connection->send(new Response(400, $headers));
        return;
    }
    if (\pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        $content = exec_php_file($file, $request);
        $connection->send(new Response(200, $headers, $content));
        return;
    }

    $if_modified_since = $request->header('if-modified-since');
    if (!empty($if_modified_since)) {
        // Check 304.
        $info = \stat($file);
        $modified_time = $info ? \date('D, d M Y H:i:s', $info['mtime']) . ' ' . \date_default_timezone_get() : '';
        if ($modified_time === $if_modified_since) {
            $connection->send(new Response(304, $headers));
            return;
        }
    }
    $connection->send((new Response())->withHeaders($headers)->withFile($file));
};

function exec_php_file($file, $request) {
    // 让 $request 对象在被包含的文件中可用
    $GLOBALS['request'] = $request;
    
    $_GET = $request->get();
    $_POST = $request->post();
    $_REQUEST = array_merge($_GET, $_POST);
    $_SERVER['REQUEST_METHOD'] = $request->method();
    $_SERVER['REMOTE_ADDR'] = $request->connection->getRemoteIp();
    $_SERVER['REQUEST_URI'] = $request->uri();
    
    \ob_start();
    // Try to include php file.
    try {
        include $file;
    } catch (\Exception $e) {
        echo $e;
    }
    return \ob_get_clean();
}


// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}

