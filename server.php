<?php

/**
 * WebSocket 聊天服务器
 * 启动命令: php server.php start
 */

use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

define('APP_PATH', __DIR__ . '/app/');
define('RUNTIME_PATH', __DIR__ . '/runtime/');

$app = new think\App();
$app->initialize();

// 全局 Redis 连接
$redis = null;
$redisLastCheck = 0;

function getRedis()
{
    global $redis, $redisLastCheck;
    
    $now = time();
    
    try {
        // 如果 Redis 连接存在且最近检查过，直接返回
        if ($redis !== null && ($now - $redisLastCheck) < 5) {
            return $redis;
        }
        
        // 检查连接是否有效
        if ($redis !== null) {
            try {
                $redis->ping();
                $redisLastCheck = $now;
                return $redis;
            } catch (\Exception $e) {
                // 连接失效，重新连接
                $redis = null;
            }
        }
        
        // 创建新连接
        $config = config('cache.stores.redis');
        $redis = new \Redis();
        $redis->connect($config['host'] ?? '127.0.0.1', $config['port'] ?? 6379, 3); // 3秒超时
        if (!empty($config['password'])) {
            $redis->auth($config['password']);
        }
        $redis->select($config['select'] ?? 0);
        $redisLastCheck = $now;
        return $redis;
    } catch (\Exception $e) {
        echo "[" . date('H:i:s') . "] Redis 连接失败: " . $e->getMessage() . "\n";
        $redis = null;
        return null;
    }
}

// 本地连接映射
$localConnections = [];

// 创建 Worker
$wsWorker = new Worker('websocket://0.0.0.0:2346');
$wsWorker->count = 1;
$wsWorker->name = 'ChatWebSocket';

// 启动时清理旧数据
$wsWorker->onWorkerStart = function ($worker) {
    echo "[" . date('H:i:s') . "] WebSocket 服务器启动\n";
    
    try {
        $redis = getRedis();
        if ($redis) {
            $keys = $redis->keys('ws:*');
            if (!empty($keys)) {
                $redis->del($keys);
                echo "[" . date('H:i:s') . "] 已清理 " . count($keys) . " 个旧的 Redis 键\n";
            }
        }
    } catch (\Exception $e) {
        echo "[" . date('H:i:s') . "] 清理旧数据失败: " . $e->getMessage() . "\n";
    }
};

// 连接建立
$wsWorker->onConnect = function ($connection) use (&$localConnections) {
    echo "[" . date('H:i:s') . "] 新连接 #{$connection->id}\n";
    $localConnections[$connection->id] = [
        'user_id' => null,
        'room_id' => null,
        'nickname' => null,
        'authed' => false
    ];
    $connection->send(json_encode([
        'type' => 'connected',
        'conn_id' => $connection->id,
        'msg' => '连接成功，请先认证'
    ]));
};

// 收到消息
$wsWorker->onMessage = function ($connection, $data) use (&$localConnections) {
    $msg = json_decode($data, true);
    if (!$msg || !isset($msg['type'])) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '消息格式错误']));
        return;
    }

    echo "[" . date('H:i:s') . "] #{$connection->id} -> {$msg['type']}\n";

    try {
        switch ($msg['type']) {
            case 'auth':
                handleAuth($connection, $msg, $localConnections);
                break;
            case 'join_room':
                handleJoinRoom($connection, $msg, $localConnections);
                break;
            case 'message':
                handleMessage($connection, $msg, $localConnections);
                break;
            case 'typing':
                handleTyping($connection, $msg, $localConnections);
                break;
            case 'mark_read':
                handleMarkRead($connection, $msg, $localConnections);
                break;
            case 'message_burned':
                handleMessageBurned($connection, $msg, $localConnections);
                break;
            case 'ping':
                handlePing($connection, $localConnections);
                break;
        }
    } catch (\Exception $e) {
        echo "[" . date('H:i:s') . "] 错误: " . $e->getMessage() . "\n";
        $connection->send(json_encode(['type' => 'error', 'msg' => '服务器错误']));
    }
};

// 连接关闭
$wsWorker->onClose = function ($connection) use (&$localConnections) {
    if (!isset($localConnections[$connection->id])) {
        echo "[" . date('H:i:s') . "] 断开 #{$connection->id} (未绑定用户)\n";
        return;
    }

    $connData = $localConnections[$connection->id];
    $userId = $connData['user_id'];
    $roomId = $connData['room_id'];
    $nickname = $connData['nickname'];

    // 输出详细的断开日志
    if ($userId && $nickname) {
        echo "[" . date('H:i:s') . "] 断开 #{$connection->id} 用户:{$nickname}(ID:{$userId}) 房间:{$roomId}\n";
    } elseif ($userId) {
        echo "[" . date('H:i:s') . "] 断开 #{$connection->id} 用户ID:{$userId} 房间:{$roomId}\n";
    } else {
        echo "[" . date('H:i:s') . "] 断开 #{$connection->id} (已认证但未绑定用户)\n";
    }

    $connData = $localConnections[$connection->id];
    $userId = $connData['user_id'];
    $roomId = $connData['room_id'];
    $nickname = $connData['nickname'];

    unset($localConnections[$connection->id]);

    if ($userId && $roomId) {
        // 检查该用户是否还有其他连接在同一房间
        $userStillInRoom = false;
        foreach ($localConnections as $conn) {
            if ($conn['user_id'] == $userId && $conn['room_id'] == $roomId) {
                $userStillInRoom = true;
                break;
            }
        }

        // 只有当用户没有其他连接在房间时，才清理Redis和广播离开
        if (!$userStillInRoom) {
            // 清理 Redis
            try {
                $redis = getRedis();
                if ($redis) {
                    $redis->sRem("ws:room:{$roomId}:users", $userId);
                    $redis->del("ws:room:{$roomId}:user:{$userId}");
                    $redis->del("ws:user:{$userId}:room");
                }
            } catch (\Exception $e) {
                echo "[" . date('H:i:s') . "] 断开连接时 Redis 清理失败: " . $e->getMessage() . "\n";
            }

            // 计算在线人数（按用户ID去重）
            $onlineUserIds = [];
            foreach ($localConnections as $conn) {
                if ($conn['room_id'] == $roomId && $conn['user_id']) {
                    $onlineUserIds[$conn['user_id']] = true;
                }
            }
            $onlineCount = count($onlineUserIds);

            // 广播用户离开
            broadcastToRoom($roomId, [
                'type' => 'user_left',
                'room_id' => $roomId,
                'user_id' => $userId,
                'nickname' => $nickname,
                'online_count' => $onlineCount
            ], $localConnections);
        }
    }
};

// 认证
function handleAuth($connection, $msg, &$localConnections)
{
    $token = $msg['token'] ?? '';
    if (empty($token)) {
        $connection->send(json_encode(['type' => 'error', 'msg' => 'token不能为空']));
        return;
    }

    $tokenData = \app\service\TokenService::verifyToken($token);
    if (!$tokenData || !isset($tokenData['user_id'])) {
        $connection->send(json_encode(['type' => 'error', 'msg' => 'token无效']));
        return;
    }

    $userId = $tokenData['user_id'];
    $user = \app\model\User::find($userId);
    if (!$user) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '用户不存在']));
        return;
    }

    // 允许多端登录，不再踢掉旧连接
    // 只记录当前连接的用户信息
    $localConnections[$connection->id]['user_id'] = $userId;
    $localConnections[$connection->id]['nickname'] = $user->nick_name;
    $localConnections[$connection->id]['avatar'] = $user->avatar;
    $localConnections[$connection->id]['authed'] = true;

    $connection->send(json_encode([
        'type' => 'auth_success',
        'user_id' => $userId,
        'nickname' => $user->nick_name,
        'avatar' => $user->avatar
    ]));

    echo "[" . date('H:i:s') . "] 认证: {$user->nick_name} (ID:{$userId}) 连接#{$connection->id}\n";
}

// 加入房间
function handleJoinRoom($connection, $msg, &$localConnections)
{
    $connData = &$localConnections[$connection->id];

    if (!$connData['authed']) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '请先认证']));
        return;
    }

    $roomId = (int)($msg['room_id'] ?? 0);
    if ($roomId <= 0) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '房间ID无效']));
        return;
    }

    $userId = $connData['user_id'];
    $nickname = $connData['nickname'];

    // 验证用户是否已加入该房间（数据库层面）
    try {
        $isInRoom = \app\service\RoomUserService::isUserInRoom($roomId, $userId);
        if (!$isInRoom) {
            $connection->send(json_encode(['type' => 'error', 'msg' => '您未加入此房间']));
            echo "[" . date('H:i:s') . "] 用户 {$userId} 未加入房间 {$roomId}\n";
            return;
        }
    } catch (\Exception $e) {
        echo "[" . date('H:i:s') . "] 检查房间成员失败: " . $e->getMessage() . "\n";
        $connection->send(json_encode(['type' => 'error', 'msg' => '检查房间成员失败']));
        return;
    }

    // 离开旧房间
    $oldRoomId = $connData['room_id'];
    if ($oldRoomId && $oldRoomId != $roomId) {
        // 检查该用户是否还有其他连接在旧房间
        $userStillInOldRoom = false;
        foreach ($localConnections as $connId => $conn) {
            if ($connId != $connection->id && $conn['user_id'] == $userId && $conn['room_id'] == $oldRoomId) {
                $userStillInOldRoom = true;
                break;
            }
        }

        if (!$userStillInOldRoom) {
            try {
                $redis = getRedis();
                if ($redis) {
                    $redis->sRem("ws:room:{$oldRoomId}:users", $userId);
                    $redis->del("ws:room:{$oldRoomId}:user:{$userId}");
                }
            } catch (\Exception $e) {
                echo "[" . date('H:i:s') . "] 离开旧房间 Redis 操作失败: " . $e->getMessage() . "\n";
            }

            // 计算旧房间在线人数（按用户ID去重）
            $oldRoomUserIds = [];
            foreach ($localConnections as $connId => $conn) {
                if ($connId != $connection->id && $conn['room_id'] == $oldRoomId && $conn['user_id']) {
                    $oldRoomUserIds[$conn['user_id']] = true;
                }
            }

            broadcastToRoom($oldRoomId, [
                'type' => 'user_left',
                'room_id' => $oldRoomId,
                'user_id' => $userId,
                'nickname' => $nickname,
                'online_count' => count($oldRoomUserIds)
            ], $localConnections, $connection->id);
        }
    }

    // 检查该用户是否已有其他连接在新房间（用于判断是否需要广播加入）
    $userAlreadyInNewRoom = false;
    foreach ($localConnections as $connId => $conn) {
        if ($connId != $connection->id && $conn['user_id'] == $userId && $conn['room_id'] == $roomId) {
            $userAlreadyInNewRoom = true;
            break;
        }
    }

    // 更新房间
    $connData['room_id'] = $roomId;

    // 写入 Redis
    try {
        $redis = getRedis();
        if ($redis) {
            $redis->sAdd("ws:room:{$roomId}:users", $userId);
            $redis->hMSet("ws:room:{$roomId}:user:{$userId}", ['nick_name' => $nickname, 'join_time' => time()]);
            $redis->set("ws:user:{$userId}:room", $roomId);
        }
    } catch (\Exception $e) {
        echo "[" . date('H:i:s') . "] 加入房间 Redis 操作失败: " . $e->getMessage() . "\n";
    }

    // 获取在线用户（按用户ID去重）
    $onlineUserMap = [];
    foreach ($localConnections as $conn) {
        if ($conn['room_id'] == $roomId && $conn['user_id']) {
            $onlineUserMap[$conn['user_id']] = ['user_id' => $conn['user_id'], 'nick_name' => $conn['nickname']];
        }
    }
    $onlineUsers = array_values($onlineUserMap);

    $connection->send(json_encode([
        'type' => 'room_joined',
        'room_id' => $roomId,
        'users' => $onlineUsers,
        'online_count' => count($onlineUsers)
    ]));

    // 只有当用户之前不在房间时才广播加入消息
    if (!$userAlreadyInNewRoom) {
        broadcastToRoom($roomId, [
            'type' => 'user_joined',
            'room_id' => $roomId,
            'user_id' => $userId,
            'nickname' => $nickname,
            'online_count' => count($onlineUsers)
        ], $localConnections, $connection->id);
    }

    echo "[" . date('H:i:s') . "] {$nickname} 加入房间 {$roomId} (连接#{$connection->id})\n";
}

// 消息通知（纯广播，不写数据库）
function handleMessage($connection, $msg, &$localConnections)
{
    $connData = $localConnections[$connection->id];

    if (!$connData['authed']) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '请先认证']));
        return;
    }
    if (!$connData['room_id']) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '请先加入房间']));
        return;
    }

    // 必须有 message_id，说明消息已通过 HTTP 保存
    $messageId = $msg['message_id'] ?? null;
    if (!$messageId) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '请通过HTTP发送消息']));
        return;
    }

    $messageType = $msg['message_type'] ?? 'text';
    $content = $msg['content'] ?? '';

    echo "[" . date('H:i:s') . "] 广播消息: {$connData['nickname']} 发送了 {$messageType} (ID:{$messageId})\n";
    
    // 构建广播数据
    $broadcastData = [
        'type' => 'message',
        'room_id' => $connData['room_id'],
        'message_id' => $messageId,
        'message_type' => $messageType,
        'from_user_id' => $connData['user_id'],
        'from_nickname' => $connData['nickname'],
        'from_avatar' => $connData['avatar'],
        'content' => $content,
        'time' => date('H:i:s')
    ];

    // 视频消息：附加视频元数据
    if ($messageType === 'video') {
        $broadcastData['video_url'] = $msg['video_url'] ?? $content;
        $broadcastData['video_thumbnail'] = $msg['video_thumbnail'] ?? null;
        $broadcastData['video_duration'] = $msg['video_duration'] ?? null;
    }

    // 文件消息：附加文件元数据
    if ($messageType === 'file') {
        $broadcastData['file_name'] = $msg['file_name'] ?? $content;
        $broadcastData['file_size'] = $msg['file_size'] ?? 0;
        $broadcastData['file_extension'] = $msg['file_extension'] ?? '';
        $broadcastData['file_url'] = $msg['file_url'] ?? '';
    }

    // 广播给房间内所有用户（排除当前连接，但包括发送者的其他设备）
    broadcastToRoom($connData['room_id'], $broadcastData, $localConnections, $connection->id);
}

// 正在输入
function handleTyping($connection, $msg, &$localConnections)
{
    $connData = $localConnections[$connection->id];
    if (!$connData['authed'] || !$connData['room_id']) {
        return;
    }

    $typing = $msg['typing'] ?? false;
    $status = $typing ? '正在输入' : '停止输入';
    echo "[" . date('H:i:s') . "] #{$connection->id} 用户{$connData['user_id']} {$connData['nickname']} {$status}\n";

    // 广播给房间内其他用户（排除自己的所有连接）
    broadcastToRoomExcludeUser($connData['room_id'], [
        'type' => 'typing',
        'user_id' => $connData['user_id'],
        'nickname' => $connData['nickname'],
        'typing' => $typing
    ], $localConnections, $connData['user_id']);
}

// 广播（排除指定连接）
function broadcastToRoom($roomId, $data, &$localConnections, $excludeConnId = null)
{
    global $wsWorker;
    $message = json_encode($data);

    foreach ($wsWorker->connections as $conn) {
        if (!isset($localConnections[$conn->id])) continue;
        if ($localConnections[$conn->id]['room_id'] != $roomId) continue;
        if ($excludeConnId !== null && $conn->id == $excludeConnId) continue;

        try {
            $conn->send($message);
        } catch (\Exception $e) {}
    }
}

// 广播（排除指定用户的所有连接）
function broadcastToRoomExcludeUser($roomId, $data, &$localConnections, $excludeUserId)
{
    global $wsWorker;
    $message = json_encode($data);

    foreach ($wsWorker->connections as $conn) {
        if (!isset($localConnections[$conn->id])) continue;
        if ($localConnections[$conn->id]['room_id'] != $roomId) continue;
        if ($localConnections[$conn->id]['user_id'] == $excludeUserId) continue;

        try {
            $conn->send($message);
        } catch (\Exception $e) {}
    }
}

// 处理标记已读
function handleMarkRead($connection, $msg, &$localConnections)
{
    $connData = $localConnections[$connection->id];
    if (!$connData['authed'] || !$connData['room_id']) {
        return;
    }

    $messageIds = $msg['message_ids'] ?? [];
    $userId = $connData['user_id'];
    $nickname = $connData['nickname'];
    $roomId = $connData['room_id'];

    if (empty($messageIds)) {
        return;
    }

    // 调用服务标记已读
    $count = \app\service\MessageReadService::batchMarkAsRead($messageIds, $userId);
    
    if ($count > 0) {
        echo "[" . date('H:i:s') . "] 用户{$userId} {$nickname} 已读 {$count} 条消息\n";
        
        // 广播已读回执给房间内其他用户（主要是消息发送者）
        broadcastToRoomExcludeUser($roomId, [
            'type' => 'message_read',
            'message_ids' => $messageIds,
            'reader_id' => $userId,
            'reader_nickname' => $nickname,
            'read_at' => date('Y-m-d H:i:s')
        ], $localConnections, $userId);
    }
    
    // 回复确认
    $connection->send(json_encode([
        'type' => 'mark_read_success',
        'count' => $count
    ]));
}

// 处理消息焚毁广播
function handleMessageBurned($connection, $msg, &$localConnections)
{
    $connData = $localConnections[$connection->id];
    if (!$connData['authed'] || !$connData['room_id']) {
        return;
    }

    $messageId = $msg['message_id'] ?? null;
    $userId = $connData['user_id'];
    $nickname = $connData['nickname'];
    $roomId = $connData['room_id'];

    if (empty($messageId)) {
        return;
    }

    echo "[" . date('H:i:s') . "] 用户{$userId} {$nickname} 焚毁消息 {$messageId}\n";

    // 广播给房间内其他用户（不包括自己，因为自己已经处理了）
    broadcastToRoomExcludeUser($roomId, [
        'type' => 'message_burned',
        'message_id' => $messageId,
        'burned_by' => $userId,
        'burned_by_nickname' => $nickname
    ], $localConnections, $userId);
}

// 处理心跳检测检测
function handlePing($connection, &$localConnections)
{
    $connection->send(json_encode(['type' => 'pong']));
    
    if (!isset($localConnections[$connection->id])) {
        echo "[" . date('H:i:s') . "] 心跳检测检测 #{$connection->id} (未绑定用户)\n";
        return;
    }
    
    $connData = $localConnections[$connection->id];
    $userId = $connData['user_id'];
    $nickname = $connData['nickname'];
    $roomId = $connData['room_id'];
    
    if ($userId && $nickname) {
        echo "[" . date('H:i:s') . "] 心跳检测 #{$connection->id} 用户:{$nickname}(ID:{$userId}) 房间:{$roomId}\n";
    } elseif ($userId) {
        echo "[" . date('H:i:s') . "] 心跳检测 #{$connection->id} 用户ID:{$userId} 房间:{$roomId}\n";
    } else {
        echo "[" . date('H:i:s') . "] 心跳检测 #{$connection->id} (未认证)\n";
    }
}

Worker::runAll();
