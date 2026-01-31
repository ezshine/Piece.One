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

require_once __DIR__ . '/../../vendor/autoload.php';

// WebServer for Static Assets (Built Frontend)
$web = new Worker("http://0.0.0.0:3000");
// WebServer process count
$web->count = 2;

$web->name = 'static_web';

// Set the root to the built web assets directory as defined in gulpfile.js
define('STATIC_WEBROOT', realpath(__DIR__ . '/../web'));

$web->onMessage = function (TcpConnection $connection, Request $request) {
    // If the root directory doesn't exist (e.g., build failed or not run), return 404
    if (!STATIC_WEBROOT) {
         $connection->send(new Response(404, array(), '<h3>404 Not Found - Build Missing</h3>'));
         return;
    }

    $path = $request->path();
    
    // Default to index.php for root
    if ($path === '/') {
        if (file_exists(STATIC_WEBROOT . '/index.php')) {
            $path = '/index.php';
        }
    }

    $file = realpath(STATIC_WEBROOT . $path);
    
    if (false === $file) {
        $connection->send(new Response(404, array(), '<h3>404 Not Found</h3>'));
        return;
    }
    
    // Security check to prevent directory traversal
    if (strpos($file, STATIC_WEBROOT) !== 0) {
        $connection->send(new Response(400));
        return;
    }

    $if_modified_since = $request->header('if-modified-since');
    if (!empty($if_modified_since)) {
        // Check 304.
        $info = \stat($file);
        $modified_time = $info ? \date('D, d M Y H:i:s', $info['mtime']) . ' ' . \date_default_timezone_get() : '';
        if ($modified_time === $if_modified_since) {
            $connection->send(new Response(304));
            return;
        }
    }

    $extension = pathinfo($file, PATHINFO_EXTENSION);
    if ($extension === 'php') {
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            echo $e;
        }
        $content = ob_get_clean();
        $connection->send(new Response(200, ['Content-Type' => 'text/html'], $content));
        return;
    }
    
    $response = (new Response())->withFile($file);
    
    // Explicitly set MIME type for WASM
    if (pathinfo($file, PATHINFO_EXTENSION) === 'wasm') {
        $response->withHeader('Content-Type', 'application/wasm');
    }
    
    $connection->send($response);
};

// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
