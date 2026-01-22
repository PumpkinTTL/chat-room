<?php

namespace extend\websocket;

use app\service\MessageReadService;

/**
 * 消息处理器
 * 负责消息的广播、已读、焚毁、编辑等功能
 */
class MessageHandler
{
    /**
     * 处理消息广播
     * 
     * @param object $connection Workerman 连接对象
     * @param array $msg 消息数据
     * @param ConnectionManager $connManager 连接管理器
     * @param object $worker Workerman Worker 对象
     */
    public static function handleMessage($connection, array $msg, ConnectionManager $connManager, $worker)
    {
        $connData = $connManager->get($connection->id);

        if (!$connData || !($connData['authed'] ?? false)) {
            $connection->send(json_encode(['type' => 'error', 'msg' => '请先认证']));
            return;
        }
        if (!($connData['room_id'] ?? null)) {
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
        RoomHandler::broadcastToRoom($connData['room_id'], $broadcastData, $connManager, $worker, $connection->id);
    }

    /**
     * 处理正在输入状态
     */
    public static function handleTyping($connection, array $msg, ConnectionManager $connManager, $worker)
    {
        $connData = $connManager->get($connection->id);
        if (!$connData || !($connData['authed'] ?? false) || !($connData['room_id'] ?? null)) {
            return;
        }

        $typing = $msg['typing'] ?? false;
        $status = $typing ? '正在输入' : '停止输入';
        echo "[" . date('H:i:s') . "] #{$connection->id} 用户{$connData['user_id']} {$connData['nickname']} {$status}\n";

        // 广播给房间内其他用户（排除自己的所有连接）
        RoomHandler::broadcastToRoomExcludeUser($connData['room_id'], [
            'type' => 'typing',
            'user_id' => $connData['user_id'],
            'nickname' => $connData['nickname'],
            'typing' => $typing
        ], $connManager, $worker, $connData['user_id']);
    }

    /**
     * 处理标记已读
     */
    public static function handleMarkRead($connection, array $msg, ConnectionManager $connManager, $worker)
    {
        $connData = $connManager->get($connection->id);
        if (!$connData || !($connData['authed'] ?? false) || !($connData['room_id'] ?? null)) {
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
        $count = MessageReadService::batchMarkAsRead($messageIds, $userId);
        
        if ($count > 0) {
            echo "[" . date('H:i:s') . "] 用户{$userId} {$nickname} 已读 {$count} 条消息\n";
            
            // 广播已读回执给房间内其他用户（主要是消息发送者）
            RoomHandler::broadcastToRoomExcludeUser($roomId, [
                'type' => 'message_read',
                'message_ids' => $messageIds,
                'reader_id' => $userId,
                'reader_nickname' => $nickname,
                'read_at' => date('Y-m-d H:i:s')
            ], $connManager, $worker, $userId);
        }
        
        // 回复确认
        $connection->send(json_encode([
            'type' => 'mark_read_success',
            'count' => $count
        ]));
    }

    /**
     * 处理消息焚毁广播
     */
    public static function handleMessageBurned($connection, array $msg, ConnectionManager $connManager, $worker)
    {
        $connData = $connManager->get($connection->id);
        if (!$connData || !($connData['authed'] ?? false) || !($connData['room_id'] ?? null)) {
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
        RoomHandler::broadcastToRoomExcludeUser($roomId, [
            'type' => 'message_burned',
            'message_id' => $messageId,
            'burned_by' => $userId,
            'burned_by_nickname' => $nickname
        ], $connManager, $worker, $userId);
    }

    /**
     * 处理消息编辑广播
     */
    public static function handleMessageEdited($connection, array $msg, ConnectionManager $connManager, $worker)
    {
        $connData = $connManager->get($connection->id);
        if (!$connData || !($connData['authed'] ?? false) || !($connData['room_id'] ?? null)) {
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
        RoomHandler::broadcastToRoom($roomId, [
            'type' => 'message_edited',
            'message_id' => $messageId,
            'content' => $content,
            'edited_at' => $editedAt,
            'edited_by' => $userId,
            'edited_by_nickname' => $nickname
        ], $connManager, $worker, $connection->id);
    }

    /**
     * 处理房间清理广播
     */
    public static function handleRoomCleared($connection, array $msg, ConnectionManager $connManager, $worker)
    {
        $connData = $connManager->get($connection->id);
        if (!$connData || !($connData['authed'] ?? false) || !($connData['room_id'] ?? null)) {
            return;
        }

        $roomId = $msg['room_id'] ?? null;
        $hardDelete = $msg['hard_delete'] ?? false;
        $userId = $connData['user_id'];
        $nickname = $connData['nickname'];

        if (empty($roomId)) {
            return;
        }

        echo "[" . date('H:i:s') . "] 用户{$userId} {$nickname} 清理房间 {$roomId} (硬删除:{$hardDelete})\n";

        // 广播给房间内所有用户
        RoomHandler::broadcastToRoom($roomId, [
            'type' => 'room_cleared',
            'room_id' => $roomId,
            'hard_delete' => $hardDelete,
            'cleared_by' => $userId,
            'cleared_by_nickname' => $nickname
        ], $connManager, $worker);
    }

    /**
     * 处理房间锁定状态变化广播
     */
    public static function handleRoomLockChanged($connection, array $msg, ConnectionManager $connManager, $worker)
    {
        $connData = $connManager->get($connection->id);
        if (!$connData || !($connData['authed'] ?? false) || !($connData['room_id'] ?? null)) {
            return;
        }

        $roomId = $msg['room_id'] ?? null;
        $locked = $msg['locked'] ?? false;
        $userId = $connData['user_id'];
        $nickname = $connData['nickname'];

        if (empty($roomId)) {
            return;
        }

        $action = $locked ? '锁定' : '解锁';
        echo "[" . date('H:i:s') . "] 用户{$userId} {$nickname} {$action}房间 {$roomId}\n";

        // 广播给房间内所有用户
        RoomHandler::broadcastToRoom($roomId, [
            'type' => 'room_lock_changed',
            'room_id' => $roomId,
            'locked' => $locked,
            'changed_by' => $userId,
            'changed_by_nickname' => $nickname
        ], $connManager, $worker);
    }

    /**
     * 处理心跳检测
     */
    public static function handlePing($connection)
    {
        $connection->send(json_encode(['type' => 'pong']));
    }
}
