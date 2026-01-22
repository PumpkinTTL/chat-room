<?php

namespace extend\websocket;

use app\service\TokenService;
use app\model\User;

/**
 * 认证处理器
 * 负责 WebSocket 连接的用户认证
 */
class AuthHandler
{
    /**
     * 处理认证请求
     * 
     * @param object $connection Workerman 连接对象
     * @param array $msg 消息数据
     * @param ConnectionManager $connManager 连接管理器
     * @return bool 认证是否成功
     */
    public static function handle($connection, array $msg, ConnectionManager $connManager)
    {
        $token = $msg['token'] ?? '';
        if (empty($token)) {
            $connection->send(json_encode(['type' => 'error', 'msg' => 'token不能为空']));
            return false;
        }

        $tokenData = TokenService::verifyToken($token);
        if (!$tokenData || !isset($tokenData['user_id'])) {
            $connection->send(json_encode(['type' => 'error', 'msg' => 'token无效']));
            return false;
        }

        $userId = $tokenData['user_id'];
        $user = User::find($userId);
        if (!$user) {
            $connection->send(json_encode(['type' => 'error', 'msg' => '用户不存在']));
            return false;
        }

        // 允许多端登录，只记录当前连接的用户信息
        $connManager->update($connection->id, [
            'user_id' => $userId,
            'nickname' => $user->nick_name,
            'avatar' => $user->avatar,
            'authed' => true
        ]);

        $connection->send(json_encode([
            'type' => 'auth_success',
            'user_id' => $userId,
            'nickname' => $user->nick_name,
            'avatar' => $user->avatar
        ]));

        echo "[" . date('H:i:s') . "] 认证: {$user->nick_name} (ID:{$userId}) 连接#{$connection->id}\n";
        
        return true;
    }
}
