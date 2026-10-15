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

// 待离开宽限表：短暂断线（切网/刷新/自动重连）不立刻广播离开，避免「离开/加入」刷屏
// 结构： "{$roomId}:{$userId}" => ['timer' => 定时器ID, ...]
$pendingLeaves = [];

// 亲密互动房间状态（内存维护）
$roomIntimacyStates = [];

// 一起听会话状态（内存维护，按 roomId 索引）
// 结构：$listenSessions[$roomId] = [
//   'initiator' => int, 'responder' => int|null, 'active' => bool,
//   'audio_url' => string, 'audio_name' => string, 'audio_duration' => number|null,
//   'position' => float, 'playing' => bool, 'timer' => mixed(待接受自动取消定时器)
// ]
$listenSessions = [];

// 创建 Worker
$wsWorker = new Worker('websocket://0.0.0.0:2346');
$wsWorker->count = 1;
$wsWorker->name = 'ChatWebSocket';

// 启动时清理旧数据
$wsWorker->onWorkerStart = function ($worker) use (&$roomIntimacyStates, &$listenSessions) {
    global $localConnections;
    echo "[" . date('H:i:s') . "] WebSocket 服务器启动\n";

    // 重置一起听会话状态（进程重启后不保留旧会话）
    $listenSessions = [];

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

    // 僵尸连接清理：客户端在后台/挂起/断电时不会发 close，导致服务端连接池残留“幽灵在线”。
    // 超过 75s 无任何消息（含 25s 心跳）的连接视为失效，主动关闭，以触发正常的 onClose 清理与 user_left 广播。
    \Workerman\Lib\Timer::add(30, function() use (&$localConnections) {
        $now = time();
        foreach ($localConnections as $connId => $conn) {
            $last = $conn['last_active'] ?? $now;
            if ($now - $last > 75) {
                $connObj = null;
                global $wsWorker;
                foreach ($wsWorker->connections as $c) {
                    if ($c->id == $connId) { $connObj = $c; break; }
                }
                if ($connObj) {
                    $idle = $now - $last;
                    echo "[" . date('H:i:s') . "] 清理僵尸连接 #{$connId}（{$idle}s 无活动）\n";
                    $connObj->close();
                } else {
                    // 连接对象已不存在，直接清理映射
                    unset($localConnections[$connId]);
                }
            }
        }
    });

    // 亲密互动定时器：检查是否完成，完成后直接增加经验并广播
    \Workerman\Lib\Timer::add(1, function() use (&$roomIntimacyStates, &$localConnections) {
        $now = time();

        foreach ($roomIntimacyStates as $roomId => $state) {
            if (!$state['active']) continue;

            // 计算已过时间
            $elapsed = $now - $state['start_time'];

            // 如果达到60秒，标记完成、增加经验并广播
            if ($elapsed >= 60) {
                $roomIntimacyStates[$roomId]['active'] = false;
                
                // 获取房间内的两个用户ID
                $userIds = $state['user_ids'] ?? [];
                if (count($userIds) === 2) {
                    // 调用IntimacyService增加经验
                    try {
                        $userId = $userIds[0];
                        $partnerId = $userIds[1];
                        
                        // 使用ThinkPHP的服务类增加经验
                        $result = \app\service\IntimacyService::collectInteractionExp($roomId, $userId, $partnerId);
                        
                        if ($result['code'] === 0) {
                            // 广播完成事件，携带经验信息
                            broadcastToRoom($roomId, [
                                'type' => 'intimacy_complete',
                                'room_id' => $roomId,
                                'exp_gain' => $result['data']['exp_gain'],
                                'intimacy' => $result['data']['intimacy']['data']
                            ], $localConnections);
                            
                            echo "[" . date('H:i:s') . "] 房间 {$roomId} 互动完成，增加经验 {$result['data']['exp_gain']}\n";
                        } else {
                            echo "[" . date('H:i:s') . "] 房间 {$roomId} 增加经验失败: {$result['msg']}\n";
                            // 即使失败也广播完成事件
                            broadcastToRoom($roomId, [
                                'type' => 'intimacy_complete',
                                'room_id' => $roomId
                            ], $localConnections);
                        }
                    } catch (\Exception $e) {
                        echo "[" . date('H:i:s') . "] 房间 {$roomId} 增加经验异常: " . $e->getMessage() . "\n";
                        // 异常时也广播完成事件
                        broadcastToRoom($roomId, [
                            'type' => 'intimacy_complete',
                            'room_id' => $roomId
                        ], $localConnections);
                    }
                } else {
                    // 人数不对，只广播完成事件
                    broadcastToRoom($roomId, [
                        'type' => 'intimacy_complete',
                        'room_id' => $roomId
                    ], $localConnections);
                }
            }
        }
    });
};

// 连接建立
$wsWorker->onConnect = function ($connection) use (&$localConnections) {
    echo "[" . date('H:i:s') . "] 新连接 #{$connection->id}\n";
    $localConnections[$connection->id] = [
        'user_id' => null,
        'room_id' => null,
        'nickname' => null,
        'authed' => false,
        'last_active' => time()
    ];    $connection->send(json_encode([
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

    // 刷新活跃时间（用于僵尸连接清理）
    if (isset($localConnections[$connection->id])) {
        $localConnections[$connection->id]['last_active'] = time();
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
            case 'message_edited':
                handleMessageEdited($connection, $msg, $localConnections);
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
            case 'room_cleared':
                handleRoomCleared($connection, $msg, $localConnections);
                break;
            case 'room_lock_changed':
                handleRoomLockChanged($connection, $msg, $localConnections);
                break;
            case 'profile_updated':
                handleProfileUpdated($connection, $msg, $localConnections);
                break;
            case 'intimacy_restart':
                handleIntimacyRestart($connection, $msg, $localConnections);
                break;
            case 'listen_invite':
                handleListenInvite($connection, $msg, $localConnections);
                break;
            case 'listen_accept':
                handleListenAccept($connection, $msg, $localConnections);
                break;
            case 'listen_decline':
                handleListenDecline($connection, $msg, $localConnections);
                break;
            case 'listen_sync':
                handleListenSync($connection, $msg, $localConnections);
                break;
            case 'listen_end':
                handleListenEnd($connection, $msg, $localConnections);
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
$wsWorker->onClose = function ($connection) use (&$localConnections, &$pendingLeaves, &$wsWorker, &$listenSessions) {
    global $roomIntimacyStates;
    if (!isset($localConnections[$connection->id])) {
        echo "[" . date('H:i:s') . "] 断开 #{$connection->id} (未绑定用户)\n";
        return;
    }

    $connData = $localConnections[$connection->id];
    $userId = $connData['user_id'];
    $roomId = $connData['room_id'];
    $nickname = $connData['nickname'];

    if ($userId && $nickname) {
        echo "[" . date('H:i:s') . "] 断开 #{$connection->id} 用户:{$nickname}(ID:{$userId}) 房间:{$roomId}\n";
    } elseif ($userId) {
        echo "[" . date('H:i:s') . "] 断开 #{$connection->id} 用户ID:{$userId} 房间:{$roomId}\n";
    } else {
        echo "[" . date('H:i:s') . "] 断开 #{$connection->id} (已认证但未绑定用户)\n";
    }

    unset($localConnections[$connection->id]);

    if (!$userId || !$roomId) {
        return;
    }

    // 该用户是否还有其它连接在同一房间
    $userStillInRoom = false;
    foreach ($localConnections as $conn) {
        if ($conn['user_id'] == $userId && $conn['room_id'] == $roomId) {
            $userStillInRoom = true;
            break;
        }
    }
    if ($userStillInRoom) {
        return;
    }

    // 一起听：断开用户在所属房间的会话中是参与者 → 结束（reason=disconnect）。
    // 关键：仅当「该用户在本服务端已无任何活跃连接」时才结束（上面的 $userStillInRoom 已判定），
    // 避免用户重连/多标签时误杀会话。
    if (isListenParticipant($roomId, $userId)) {
        endListenSession($roomId, 'disconnect', $userId, $nickname, $localConnections);
    }

    // 宽限期内不立即广播「离开」：短暂断线（切换网络/刷新/自动重连）会很快重新 join，
    // 若立刻广播 user_left，对方会看到「离开→加入」来回刷屏。
    $leaveKey = $roomId . ':' . $userId;

    // 已有待处理离开 → 直接返回（同一次断开的重复触发）
    if (isset($pendingLeaves[$leaveKey])) {
        return;
    }

    $pendingLeaves[$leaveKey] = true;

    \Workerman\Lib\Timer::add(6, function () use (&$localConnections, &$pendingLeaves, &$wsWorker, $roomId, $userId, $nickname, $leaveKey) {
        unset($pendingLeaves[$leaveKey]);

        // 宽限期内用户重新加入 → 视为未离开，什么都不做
        foreach ($localConnections as $conn) {
            if ($conn['user_id'] == $userId && $conn['room_id'] == $roomId) {
                echo "[" . date('H:i:s') . "] 用户 {$userId} 在宽限期内重连，跳过离开广播\n";
                return;
            }
        }

        // 确认离开：清理 Redis
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

        broadcastToRoom($roomId, [
            'type' => 'user_left',
            'room_id' => $roomId,
            'user_id' => $userId,
            'nickname' => $nickname,
            'online_count' => count($onlineUserIds)
        ], $localConnections);

        echo "[" . date('H:i:s') . "] {$nickname} 离开房间 {$roomId}（宽限期结束）\n";

        // 检查房间互动状态（有人离开需要重置）
        checkRoomIntimacy($roomId, $localConnections);
    }, [], false);
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
    global $roomIntimacyStates, $pendingLeaves, $listenSessions;
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

            // 一起听：用户离开旧房间 → 若其是旧房间会话参与者，结束该会话（reason=left）
            if (isListenParticipant($oldRoomId, $userId)) {
                endListenSession($oldRoomId, 'left', $userId, $nickname, $localConnections);
            }
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

    // 用户重新加入 → 取消待处理的离开（宽限期内重连，视为未离开，不广播 user_left / user_joined）
    $leaveKey = $roomId . ':' . $userId;
    $reconnectSameRoom = false;
    if (isset($pendingLeaves[$leaveKey])) {
        unset($pendingLeaves[$leaveKey]);
        $reconnectSameRoom = true;
        echo "[" . date('H:i:s') . "] 用户 {$userId} 重连取消待处理离开\n";
    }

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

    // 获取在线用户（按用户ID去重，包含头像信息）
    $onlineUserMap = [];
    foreach ($localConnections as $conn) {
        if ($conn['room_id'] == $roomId && $conn['user_id']) {
            $onlineUserMap[$conn['user_id']] = [
                'user_id' => $conn['user_id'], 
                'nick_name' => $conn['nickname'],
                'avatar' => $conn['avatar'] ?? ''
            ];
        }
    }
    $onlineUsers = array_values($onlineUserMap);

    $connection->send(json_encode([
        'type' => 'room_joined',
        'room_id' => $roomId,
        'users' => $onlineUsers,
        'online_count' => count($onlineUsers)
    ]));

    // 一起听：join 后同步会话状态，确保客户端本地状态与服务端一致（避免任何残留/泄漏）
    if (isset($listenSessions[$roomId]) && isListenParticipant($roomId, $userId)) {
        // 参与者：下发完整会话状态
        $listenState = buildListenState($roomId, $localConnections);
        $connection->send(json_encode($listenState));
        echo "[" . date('H:i:s') . "] 向 {$nickname} 下发一起听状态 (room={$roomId}, active=" . ($listenState['active'] ? '1' : '0') . ")\n";
    } elseif (isset($listenSessions[$roomId]) && !$listenSessions[$roomId]['active']) {
        // 非参与者但会话 pending：仅告知存在待处理邀请，不泄露音频细节
        $connection->send(json_encode([
            'type' => 'listen_state',
            'room_id' => $roomId,
            'active' => false,
            'initiator_id' => $listenSessions[$roomId]['initiator']
        ]));
        echo "[" . date('H:i:s') . "] 向 {$nickname} 下发一起听 pending 状态 (room={$roomId})\n";
    } else {
        // 该房间无会话：明确告知非活跃，客户端据此清掉任何残留状态（防泄漏）
        $connection->send(json_encode([
            'type' => 'listen_state',
            'room_id' => $roomId,
            'active' => false
        ]));
    }

    // 只有当用户之前不在房间时才广播加入消息
    // 注意：同一连接重复 join 同一房间（如重连/重试）时不应重复广播 user_joined，否则会出现重复的“X 加入了房间”
    // $reconnectSameRoom：宽限期内重连（刚取消过待处理离开）同样不广播，避免“离开/加入”刷屏
    $wasAlreadyInThisRoom = ((int)$oldRoomId === (int)$roomId);
    if (!$userAlreadyInNewRoom && !$wasAlreadyInThisRoom && !$reconnectSameRoom) {
        broadcastToRoom($roomId, [
            'type' => 'user_joined',
            'room_id' => $roomId,
            'user_id' => $userId,
            'nickname' => $nickname,
            'avatar' => $connData['avatar'] ?? '',
            'online_count' => count($onlineUsers)
        ], $localConnections, $connection->id);
    }

    // 检查私密房间互动
    checkRoomIntimacy($roomId, $localConnections);

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

    // 音频消息：附加音频元数据
    if ($messageType === 'audio') {
        $broadcastData['audio_url'] = $msg['audio_url'] ?? $content;
        $broadcastData['audio_duration'] = $msg['audio_duration'] ?? null;
        $broadcastData['audio_name'] = $msg['audio_name'] ?? '';
    }

    // 文件消息：附加文件元数据
    if ($messageType === 'file') {
        $broadcastData['file_name'] = $msg['file_name'] ?? $content;
        $broadcastData['file_size'] = $msg['file_size'] ?? 0;
        $broadcastData['file_extension'] = $msg['file_extension'] ?? '';
        $broadcastData['file_url'] = $msg['file_url'] ?? '';
    }

    // 引用回复：附加引用信息
    if (isset($msg['reply_to']) && $msg['reply_to']) {
        $broadcastData['reply_to'] = $msg['reply_to'];
    }
    
    // 私密房间：附加好感度信息
    if (isset($msg['intimacy']) && $msg['intimacy']) {
        $broadcastData['intimacy'] = $msg['intimacy'];
        echo "[" . date('H:i:s') . "] 携带好感度信息: exp={$msg['intimacy']['current_exp']}, level={$msg['intimacy']['current_level']}\n";
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

// 处理消息编辑广播
function handleMessageEdited($connection, $msg, &$localConnections)
{
    $connData = $localConnections[$connection->id];
    if (!$connData['authed'] || !$connData['room_id']) {
        return;
    }

    $messageId = $msg['message_id'] ?? null;
    $content = $msg['content'] ?? '';
    $editedAt = $msg['edited_at'] ?? date('Y-m-d H:i:s');
    $userId = $connData['user_id'];
    $nickname = $connData['nickname'];
    $roomId = $connData['room_id'];

    if (empty($messageId)) {
        return;
    }

    echo "[" . date('H:i:s') . "] 用户{$userId} {$nickname} 编辑消息 {$messageId}\n";

    // 广播给房间内所有用户（包括自己的其他设备）
    broadcastToRoom($roomId, [
        'type' => 'message_edited',
        'message_id' => $messageId,
        'content' => $content,
        'edited_at' => $editedAt,
        'edited_by' => $userId,
        'edited_by_nickname' => $nickname
    ], $localConnections, $connection->id);
}

// 处理房间清理广播
function handleRoomCleared($connection, $msg, &$localConnections)
{
    $connData = $localConnections[$connection->id];
    if (!$connData['authed'] || !$connData['room_id']) {
        return;
    }

    $userId = $connData['user_id'];
    $nickname = $connData['nickname'];
    $roomId = $connData['room_id'];
    $hardDelete = $msg['hard_delete'] ?? false;
    $isRestore = $msg['is_restore'] ?? false; // 是否是恢复操作

    // 一起听：清空房间（非恢复操作）→ 结束该房间会话（reason=cleared）
    if (!$isRestore) {
        endListenSession($roomId, 'cleared', $userId, $nickname, $localConnections);
    }

    if ($isRestore) {
        echo "[" . date('H:i:s') . "] 用户{$userId} {$nickname} 恢复房间 {$roomId} 的消息\n";
        
        // 广播给房间内其他用户（恢复操作）
        broadcastToRoomExcludeUser($roomId, [
            'type' => 'room_cleared',
            'cleared_by' => $userId,
            'cleared_by_nickname' => $nickname,
            'hard_delete' => false,
            'is_restore' => true
        ], $localConnections, $userId);
    } else {
        echo "[" . date('H:i:s') . "] 用户{$userId} {$nickname} 清理房间 {$roomId} (物理删除: " . ($hardDelete ? '是' : '否') . ")\n";
        
        // 广播给房间内其他用户（清理操作）
        broadcastToRoomExcludeUser($roomId, [
            'type' => 'room_cleared',
            'cleared_by' => $userId,
            'cleared_by_nickname' => $nickname,
            'hard_delete' => $hardDelete,
            'is_restore' => false
        ], $localConnections, $userId);
    }
}

// 处理用户资料变更广播（昵称/头像），让同房间其他人刷新显示
function handleProfileUpdated($connection, $msg, &$localConnections)
{
    $connData = $localConnections[$connection->id] ?? null;
    if (!$connData || !$connData['authed'] || !$connData['room_id']) {
        return;
    }

    $userId = $connData['user_id'];
    $nickname = $msg['nickname'] ?? null;
    $avatar = $msg['avatar'] ?? null;
    $roomId = $connData['room_id'];

    // 同步更新本连接缓存的资料（供后续广播/在线列表使用）
    if ($nickname !== null) { $localConnections[$connection->id]['nickname'] = $nickname; }
    if ($avatar !== null) { $localConnections[$connection->id]['avatar'] = $avatar; }

    echo "[" . date('H:i:s') . "] 用户{$userId} 更新资料: nick=" . ($nickname ?? '-') . "\n";

    // 广播给房间内其他用户（不含自己）
    broadcastToRoomExcludeUser($roomId, [
        'type' => 'profile_updated',
        'user_id' => $userId,
        'nickname' => $nickname,
        'avatar' => $avatar
    ], $localConnections, $userId);
}

// 处理房间锁定状态变化广播
function handleRoomLockChanged($connection, $msg, &$localConnections)
{
    $connData = $localConnections[$connection->id];
    if (!$connData['authed'] || !$connData['room_id']) {
        return;
    }

    $roomId = $connData['room_id'];
    $lockStatus = $msg['lock'] ?? 0;

    echo "[" . date('H:i:s') . "] 房间 {$roomId} 锁定状态变更: " . ($lockStatus ? '锁定' : '解锁') . "\n";

    // 广播给房间内所有用户（包括操作者）
    broadcastToRoom($roomId, [
        'type' => 'room_lock_changed',
        'lock' => $lockStatus
    ], $localConnections);
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

// ==================== 亲密互动相关函数 ====================

/**
 * 检查房间亲密互动状态
 * 只有私密房间恰好2人在线时才启动互动
 */
function checkRoomIntimacy($roomId, &$localConnections)
{
    global $roomIntimacyStates;

    // 检查是否为私密房间
    $room = \app\model\Room::find($roomId);
    if (!$room || $room->private != 1) {
        // 非私密房间，停止互动
        if (isset($roomIntimacyStates[$roomId])) {
            stopRoomIntimacy($roomId, $localConnections);
        }
        return;
    }

    // 计算房间在线用户数（按用户ID去重）
    $onlineUserIds = [];
    foreach ($localConnections as $conn) {
        if ($conn['room_id'] == $roomId && $conn['user_id']) {
            $onlineUserIds[$conn['user_id']] = true;
        }
    }
    $onlineCount = count($onlineUserIds);

    echo "[" . date('H:i:s') . "] 房间 {$roomId} 在线人数: {$onlineCount}\n";

    if ($onlineCount == 2) {
        // 恰好2人在线，启动互动（如果还未启动）
        if (!isset($roomIntimacyStates[$roomId]) || !$roomIntimacyStates[$roomId]['active']) {
            startRoomIntimacy($roomId, array_keys($onlineUserIds), $localConnections);
        }
    } else {
        // 人数不是2人，停止并重置互动
        if (isset($roomIntimacyStates[$roomId]) && $roomIntimacyStates[$roomId]['active']) {
            stopRoomIntimacy($roomId, $localConnections);
        }
    }
}

/**
 * 启动房间亲密互动
 */
function startRoomIntimacy($roomId, $userIds, &$localConnections)
{
    global $roomIntimacyStates;

    $startTime = time();
    $roomIntimacyStates[$roomId] = [
        'active' => true,
        'start_time' => $startTime,
        'user_ids' => $userIds,
        'room_id' => $roomId
    ];

    echo "[" . date('H:i:s') . "] 房间 {$roomId} 启动亲密互动\n";

    // 广播互动开始，前端收到后各自本地计时60秒
    broadcastToRoom($roomId, [
        'type' => 'intimacy_start',
        'room_id' => $roomId
    ], $localConnections);
}

/**
 * 停止房间亲密互动（重置）
 */
function stopRoomIntimacy($roomId, &$localConnections)
{
    global $roomIntimacyStates;

    if (isset($roomIntimacyStates[$roomId])) {
        $wasActive = $roomIntimacyStates[$roomId]['active'];
        unset($roomIntimacyStates[$roomId]);

        if ($wasActive) {
            echo "[" . date('H:i:s') . "] 房间 {$roomId} 停止亲密互动\n";

            // 广播互动停止/重置
            broadcastToRoom($roomId, [
                'type' => 'intimacy_reset',
                'room_id' => $roomId
            ], $localConnections);
            echo "[" . date('H:i:s') . "] 房间 {$roomId} 已发送 intimacy_reset 消息\n";
        }
    }
}

/**
 * 处理亲密互动重新开始请求
 */
function handleIntimacyRestart($connection, $msg, &$localConnections)
{
    if (!isset($msg['room_id'])) {
        echo "[" . date('H:i:s') . "] intimacy_restart 消息缺少 room_id\n";
        return;
    }

    $roomId = $msg['room_id'];

    // 验证用户是否在该房间
    $connData = $localConnections[$connection->id] ?? null;
    if (!$connData || $connData['room_id'] != $roomId) {
        echo "[" . date('H:i:s') . "] 用户不在房间 {$roomId}\n";
        return;
    }

    echo "[" . date('H:i:s') . "] 房间 {$roomId} 请求重新开始亲密互动\n";

    // 先停止当前互动
    stopRoomIntimacy($roomId, $localConnections);

    // 检查房间是否仍有2人在线
    $roomUsers = [];
    foreach ($localConnections as $connId => $conn) {
        if ($conn['room_id'] == $roomId && $conn['user_id']) {
            $roomUsers[$conn['user_id']] = true;
        }
    }

    echo "[" . date('H:i:s') . "] 房间 {$roomId} 在线用户数: " . count($roomUsers) . "\n";

    // 如果恰好2人在线，立即重新开始
    if (count($roomUsers) === 2) {
        echo "[" . date('H:i:s') . "] 房间 {$roomId} 重新开始亲密互动\n";
        startRoomIntimacy($roomId, array_keys($roomUsers), $localConnections);
    } else {
        echo "[" . date('H:i:s') . "] 房间 {$roomId} 人数不足，无法重新开始\n";
    }
}

// ==================== 一起听相关函数 ====================

/**
 * 精确单发：给指定房间内的指定用户（其所有连接）发送消息
 * @return int 实际发送的连接数
 */
function sendToUser($roomId, $userId, $data, &$localConnections)
{
    global $wsWorker;
    $message = json_encode($data);
    $sent = 0;

    foreach ($localConnections as $connId => $conn) {
        if ($conn['room_id'] != $roomId) continue;
        if ($conn['user_id'] != $userId) continue;

        // 连接对象从 worker 连接池里按 id 找
        $connObj = null;
        foreach ($wsWorker->connections as $c) {
            if ($c->id == $connId) { $connObj = $c; break; }
        }
        if ($connObj) {
            try {
                $connObj->send($message);
                $sent++;
            } catch (\Exception $e) {}
        }
    }

    return $sent;
}

/**
 * 获取房间内某用户的昵称（先从本地连接取，取不到再查库）
 */
function getListenUserNickname($roomId, $userId, &$localConnections)
{
    foreach ($localConnections as $conn) {
        if ($conn['room_id'] == $roomId && $conn['user_id'] == $userId) {
            return $conn['nickname'];
        }
    }

    try {
        $user = \app\model\User::find($userId);
        if ($user) {
            return $user->nick_name;
        }
    } catch (\Exception $e) {}

    return '';
}

/**
 * 判断用户是否为该房间一起听会话的参与者
 */
function isListenParticipant($roomId, $userId)
{
    global $listenSessions;
    if (!isset($listenSessions[$roomId])) {
        return false;
    }
    $s = $listenSessions[$roomId];
    return ($s['initiator'] == $userId) || ($s['responder'] !== null && $s['responder'] == $userId);
}

/**
 * 结束一起听会话（统一释放入口：清 timer + unset 会话 + 可选广播）
 * @param string $reason ended / cancelled / disconnect / left / cleared
 * @param bool   $notify 是否广播 listen_ended
 * @return bool  是否确有会话被结束
 */
function endListenSession($roomId, $reason, $byUserId, $byNickname, &$localConnections, $notify = true)
{
    global $listenSessions;

    if (!isset($listenSessions[$roomId])) {
        return false;
    }

    $session = $listenSessions[$roomId];

    // 释放待接受的自动取消定时器
    if (!empty($session['timer'])) {
        \Workerman\Lib\Timer::del($session['timer']);
    }
    // 释放「掉线宽限」延迟结束定时器
    if (!empty($session['endTimer'])) {
        \Workerman\Lib\Timer::del($session['endTimer']);
    }

    unset($listenSessions[$roomId]);

    if ($notify) {
        $endedData = [
            'type' => 'listen_ended',
            'room_id' => $roomId,
            'reason' => $reason,
            'by_user_id' => $byUserId,
            'by_nickname' => $byNickname
        ];

        if (!empty($session['active'])) {
            // 已进入双人模式：只发双方参与者（房间可能 ≥3 人，按 user_id 精确发送）
            sendToUser($roomId, $session['initiator'], $endedData, $localConnections);
            if ($session['responder'] !== null && $session['responder'] != $session['initiator']) {
                sendToUser($roomId, $session['responder'], $endedData, $localConnections);
            }
        } else {
            // 仍在「邀请中」阶段就结束（发起者取消 / 60s 超时）：此时 responder 还是 null，
            // 被邀请方必须收到通知，否则其确认气泡会一直挂着（数据泄漏）。
            // → 广播给房间内除发起者外的所有人（即所有看到邀请的人），并单独回执给发起者。
            broadcastToRoomExcludeUser($roomId, $endedData, $localConnections, $session['initiator']);
            sendToUser($roomId, $session['initiator'], $endedData, $localConnections);
        }
    }

    echo "[" . date('H:i:s') . "] 房间 {$roomId} 一起听会话结束 (reason={$reason}, by={$byUserId})\n";
    return true;
}

/**
 * 构建一起听会话状态（用于重连恢复）
 */
function buildListenState($roomId, &$localConnections)
{
    global $listenSessions;

    if (!isset($listenSessions[$roomId])) {
        return ['type' => 'listen_state', 'room_id' => $roomId, 'active' => false];
    }

    $s = $listenSessions[$roomId];
    return [
        'type' => 'listen_state',
        'room_id' => $roomId,
        'active' => $s['active'],
        'initiator_id' => $s['initiator'],
        'responder_id' => $s['responder'],
        'initiator_name' => getListenUserNickname($roomId, $s['initiator'], $localConnections),
        'responder_name' => ($s['responder'] !== null) ? getListenUserNickname($roomId, $s['responder'], $localConnections) : '',
        'audio_url' => $s['audio_url'],
        'audio_name' => $s['audio_name'],
        'audio_duration' => $s['audio_duration'],
        'position' => $s['position'],
        'playing' => $s['playing'],
        'server_at' => round(microtime(true) * 1000)
    ];
}

/**
 * 一起听：发起邀请
 */
function handleListenInvite($connection, $msg, &$localConnections)
{
    global $listenSessions;

    $connData = $localConnections[$connection->id] ?? null;
    if (!$connData || !$connData['authed']) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '请先认证']));
        return;
    }
    if (!$connData['room_id']) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '请先加入房间']));
        return;
    }

    $roomId = $connData['room_id'];
    $userId = $connData['user_id'];
    $nickname = $connData['nickname'];

    $audioUrl = trim((string)($msg['audio_url'] ?? ''));
    if ($audioUrl === '') {
        $connection->send(json_encode(['type' => 'error', 'msg' => '音频地址不能为空']));
        return;
    }

    // 该房间已有会话（active 或 pending）→ 拒绝
    if (isset($listenSessions[$roomId])) {
        echo "[" . date('H:i:s') . "] 房间 {$roomId} 已有一起听会话，拒绝 {$nickname} 的邀请\n";
        $connection->send(json_encode(['type' => 'error', 'msg' => '当前已有一起听会话']));
        return;
    }

    $audioName = (string)($msg['audio_name'] ?? '');
    $audioDuration = $msg['audio_duration'] ?? null;
    $position = (float)($msg['position'] ?? 0);
    $playing = (bool)($msg['playing'] ?? false);

    $listenSessions[$roomId] = [
        'initiator' => $userId,
        'responder' => null,
        'active' => false,
        'audio_url' => $audioUrl,
        'audio_name' => $audioName,
        'audio_duration' => $audioDuration,
        'position' => $position,
        'playing' => $playing,
        'timer' => null,
        'endTimer' => null
    ];

    // 60 秒内未接受 → 自动取消
    $listenSessions[$roomId]['timer'] = \Workerman\Lib\Timer::add(60, function () use (&$localConnections, $roomId, $userId, $nickname) {
        global $listenSessions;

        // 会话已不存在或已进入双人模式 → 什么都不做
        if (!isset($listenSessions[$roomId]) || $listenSessions[$roomId]['active']) {
            return;
        }

        echo "[" . date('H:i:s') . "] 房间 {$roomId} 一起听邀请超时未接受，自动取消\n";
        endListenSession($roomId, 'cancelled', $userId, $nickname, $localConnections);
    }, [], false);

    echo "[" . date('H:i:s') . "] {$nickname} 在房间 {$roomId} 发起一起听: {$audioName}\n";

    // 广播给房间内除发起者外的其他人
    broadcastToRoomExcludeUser($roomId, [
        'type' => 'listen_invited',
        'from_user_id' => $userId,
        'from_nickname' => $nickname,
        'audio_url' => $audioUrl,
        'audio_name' => $audioName,
        'audio_duration' => $audioDuration,
        'position' => $position,
        'playing' => $playing
    ], $localConnections, $userId);
}

/**
 * 一起听：接受邀请
 */
function handleListenAccept($connection, $msg, &$localConnections)
{
    global $listenSessions;

    $connData = $localConnections[$connection->id] ?? null;
    if (!$connData || !$connData['authed'] || !$connData['room_id']) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '请先加入房间']));
        return;
    }

    $roomId = $connData['room_id'];
    $userId = $connData['user_id'];
    $nickname = $connData['nickname'];

    if (!isset($listenSessions[$roomId])) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '会话不存在或已结束']));
        return;
    }

    $session = $listenSessions[$roomId];

    if ($session['active']) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '会话已开始']));
        return;
    }

    if ($userId == $session['initiator']) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '不能接受自己的邀请']));
        return;
    }

    // 清掉待接受自动取消定时器
    if (!empty($session['timer'])) {
        \Workerman\Lib\Timer::del($session['timer']);
    }

    $listenSessions[$roomId]['responder'] = $userId;
    $listenSessions[$roomId]['active'] = true;
    $listenSessions[$roomId]['timer'] = null;

    $initiatorId = $session['initiator'];
    $initiatorName = getListenUserNickname($roomId, $initiatorId, $localConnections);

    echo "[" . date('H:i:s') . "] 房间 {$roomId} 一起听已开始: {$initiatorName}({$initiatorId}) + {$nickname}({$userId})\n";

    // 只发双方（房间可能 ≥3 人，按 user_id 精确发送）
    $started = [
        'type' => 'listen_started',
        'audio_url' => $session['audio_url'],
        'audio_name' => $session['audio_name'],
        'audio_duration' => $session['audio_duration'],
        'position' => $session['position'],
        'playing' => $session['playing'],
        'server_at' => round(microtime(true) * 1000),
        'initiator_id' => $initiatorId,
        'initiator_name' => $initiatorName,
        'responder_id' => $userId,
        'responder_name' => $nickname
    ];

    sendToUser($roomId, $initiatorId, $started, $localConnections);
    if ($userId != $initiatorId) {
        sendToUser($roomId, $userId, $started, $localConnections);
    }
}

/**
 * 一起听：拒绝邀请
 */
function handleListenDecline($connection, $msg, &$localConnections)
{
    global $listenSessions;

    $connData = $localConnections[$connection->id] ?? null;
    if (!$connData || !$connData['authed'] || !$connData['room_id']) {
        return;
    }

    $roomId = $connData['room_id'];
    $userId = $connData['user_id'];
    $nickname = $connData['nickname'];

    if (!isset($listenSessions[$roomId]) || $listenSessions[$roomId]['active']) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '没有待接受的邀请']));
        return;
    }

    $initiatorId = $listenSessions[$roomId]['initiator'];
    if ($userId == $initiatorId) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '不能拒绝自己的邀请']));
        return;
    }

    echo "[" . date('H:i:s') . "] {$nickname} 拒绝了房间 {$roomId} 的一起听邀请\n";

    // 先释放会话（不广播 listen_ended），再分别通知：
    // - 发起者：收到 listen_declined（明确「被拒绝」）
    // - 房间内其余看到过邀请的人（含拒绝者本人）：收到 listen_ended，消除残留气泡
    endListenSession($roomId, 'declined', $userId, $nickname, $localConnections, false);

    broadcastToRoomExcludeUser($roomId, [
        'type' => 'listen_ended',
        'room_id' => $roomId,
        'reason' => 'declined',
        'by_user_id' => $userId,
        'by_nickname' => $nickname
    ], $localConnections, $initiatorId);

    sendToUser($roomId, $initiatorId, [
        'type' => 'listen_declined',
        'by_user_id' => $userId,
        'by_nickname' => $nickname
    ], $localConnections);
}

/**
 * 一起听：同步播放进度（转发给另一方）
 */
function handleListenSync($connection, $msg, &$localConnections)
{
    global $listenSessions;

    $connData = $localConnections[$connection->id] ?? null;
    if (!$connData || !$connData['authed'] || !$connData['room_id']) {
        return;
    }

    $roomId = $connData['room_id'];
    $userId = $connData['user_id'];

    if (!isset($listenSessions[$roomId]) || !$listenSessions[$roomId]['active']) {
        return;
    }

    $session = $listenSessions[$roomId];
    if ($userId != $session['initiator'] && $userId != $session['responder']) {
        return;
    }

    $position = (float)($msg['position'] ?? 0);
    $playing = (bool)($msg['playing'] ?? false);

    $listenSessions[$roomId]['position'] = $position;
    $listenSessions[$roomId]['playing'] = $playing;

    // 转发给另一方参与者（不回给发送者）
    $other = ($userId == $session['initiator']) ? $session['responder'] : $session['initiator'];
    if ($other !== null) {
        sendToUser($roomId, $other, [
            'type' => 'listen_sync',
            'position' => $position,
            'playing' => $playing,
            'by_user_id' => $userId,
            'server_at' => round(microtime(true) * 1000)
        ], $localConnections);
    }
}

/**
 * 一起听：主动结束
 */
function handleListenEnd($connection, $msg, &$localConnections)
{
    global $listenSessions;

    $connData = $localConnections[$connection->id] ?? null;
    if (!$connData || !$connData['authed'] || !$connData['room_id']) {
        return;
    }

    $roomId = $connData['room_id'];
    $userId = $connData['user_id'];
    $nickname = $connData['nickname'];

    if (!isset($listenSessions[$roomId])) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '会话不存在或已结束']));
        return;
    }

    $session = $listenSessions[$roomId];

    // pending 阶段只能是发起者结束
    if (!$session['active'] && $userId != $session['initiator']) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '无权结束该会话']));
        return;
    }
    if ($session['active'] && $userId != $session['initiator'] && $userId != $session['responder']) {
        $connection->send(json_encode(['type' => 'error', 'msg' => '无权结束该会话']));
        return;
    }

    echo "[" . date('H:i:s') . "] {$nickname} 主动结束房间 {$roomId} 的一起听\n";

    // 邀请中就取消 → cancelled（被邀请方据此消除气泡）；已是双人模式 → ended
    $reason = $session['active'] ? 'ended' : 'cancelled';
    endListenSession($roomId, $reason, $userId, $nickname, $localConnections);
}

Worker::runAll();
