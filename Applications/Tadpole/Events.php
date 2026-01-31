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

/**
 * 主逻辑
 * 主要是处理 onMessage onClose 三个方法
 */

use \GatewayWorker\Lib\Gateway;

class Events
{
    /**
     * 当客户端连上时触发
     * @param int $client_id
     */
    public static function onConnect($client_id)
    {
        $_SESSION['id'] = time();

        // 加入世界群组
        Gateway::joinGroup($client_id, 'world');
    
        Gateway::sendToCurrentClient('{"type":"welcome","id":'.$_SESSION['id'].'}');
    }
    
    /**
     * 有消息时
     * @param int $client_id
     * @param string $message
     */
    public static function onMessage($client_id, $message)
    {
        // 获取客户端请求
        $message_data = json_decode($message, true);
        if(!$message_data) {
            return ;
        }
        
        switch($message_data['type']) {
            case 'login':
                break;
                // 更新用户
            case 'update':
                $now = microtime(true);

                // 服务器端限频：10Hz
                if (isset($_SESSION['last_send_time']) &&
                    ($now - $_SESSION['last_send_time']) < 0.1) {
                    return;
                }

                $_SESSION['last_send_time'] = $now;
                $update_data = array(
                        'type'     => 'update',
                        'id'       => $_SESSION['id'],
                        'angle'    => $message_data["angle"],
                        'momentum' => $message_data["momentum"],
                        'x'        => $message_data["x"],
                        'y'        => $message_data["y"],
                        'life'     => 1,
                        'authorized'  => false,
                        );
                if(isset($message_data["name"])) {
                    $update_data['name'] = $message_data["name"];
                }
                if(isset($message_data["icon"])) {
                    $update_data['icon'] = $message_data["icon"];
                }
                
                // 转播给所有用户
                Gateway::sendToGroup('world', json_encode(
                    $update_data
                ));
                return;
                // 聊天
            case 'message':
                // 向大家说
                $new_message = array(
                    'type'=>'message',
                    'id'  =>$_SESSION['id'],
                    'message'=>$message_data['message'],
                );
                return Gateway::sendToGroup('world', json_encode($new_message));
        }
    }
   
    /**
     * 当用户断开连接时
     * @param integer $client_id 用户id
     */
    public static function onClose($client_id)
    {
        if (isset($_SESSION['id'])) {
            // 广播 xxx 退出了
            Gateway::sendToGroup('world', json_encode(array('type'=>'closed', 'id'=>$_SESSION['id'])));
            Gateway::leaveGroup($client_id, 'world');
        }
    }

    public static function onWebSocketConnect($client_id, $data)
    {
        // 获取 HTTP Origin 头部
        $origin = isset($data['server']['HTTP_ORIGIN']) ? $data['server']['HTTP_ORIGIN'] : null;

        // Initialize
        $allowed_origins = [];

        // 从环境变量获取额外的允许来源
        $env_origins = getenv('ALLOWED_ORIGINS');
        if($env_origins){
            $allowed_origins = array_merge($allowed_origins, explode(',', $env_origins));
        }

        // 检查 Origin 头部
        if (!in_array($origin, $allowed_origins)) {
            // 记录日志
            // $logMessage = date("Y-m-d H:i:s") . " - Client ID: $client_id - Origin: $origin\n";
            // file_put_contents("connections.log", $logMessage, FILE_APPEND | LOCK_EX);
            // 如果来源不被允许，关闭连接
            Gateway::closeClient($client_id);
            return;
        }
    }
}
