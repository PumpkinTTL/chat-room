<?php

namespace app\service;

use app\model\MessageRead;
use app\model\Message;
use think\facade\Db;

/**
 * 消息已读服务
 */
class MessageReadService
{
    /**
     * 标记消息为已读
     * @param int $messageId 消息ID
     * @param int $userId 用户ID
     * @return bool
     */
    public static function markAsRead($messageId, $userId)
    {
        if (empty($messageId) || empty($userId)) {
            return false;
        }
        
        try {
            // 使用 REPLACE INTO 避免重复插入
            Db::execute(
                "REPLACE INTO ch_message_reads (message_id, user_id, read_at) VALUES (?, ?, ?)",
                [$messageId, $userId, date('Y-m-d H:i:s')]
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 批量标记消息为已读
     * @param array $messageIds 消息ID数组
     * @param int $userId 用户ID
     * @return int 成功标记的数量
     */
    public static function batchMarkAsRead($messageIds, $userId)
    {
        if (empty($messageIds) || empty($userId)) {
            return 0;
        }
        
        $messageIds = array_unique(array_filter($messageIds));
        if (empty($messageIds)) {
            return 0;
        }
        
        try {
            $now = date('Y-m-d H:i:s');
            $values = [];
            foreach ($messageIds as $msgId) {
                $values[] = "({$msgId}, {$userId}, '{$now}')";
            }
            
            // 使用 INSERT IGNORE 批量插入，忽略已存在的记录
            $sql = "INSERT IGNORE INTO ch_message_reads (message_id, user_id, read_at) VALUES " . implode(',', $values);
            return Db::execute($sql);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * 标记房间内所有消息为已读（排除自己发的）
     * @param int $roomId 房间ID
     * @param int $userId 用户ID
     * @return int 标记数量
     */
    public static function markRoomMessagesAsRead($roomId, $userId)
    {
        if (empty($roomId) || empty($userId)) {
            return 0;
        }
        
        try {
            // 获取房间内所有未读消息ID（排除自己发的）
            $messageIds = Message::where('room_id', $roomId)
                ->where('user_id', '<>', $userId)
                ->whereNotExists(function ($query) use ($userId) {
                    $query->table('ch_message_reads')
                        ->where('ch_message_reads.message_id', '=', Db::raw('ch_messages.id'))
                        ->where('ch_message_reads.user_id', '=', $userId);
                })
                ->column('id');
            
            if (empty($messageIds)) {
                return 0;
            }
            
            return self::batchMarkAsRead($messageIds, $userId);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * 检查消息是否被指定用户已读
     * @param int $messageId 消息ID
     * @param int $userId 用户ID
     * @return bool
     */
    public static function isRead($messageId, $userId)
    {
        return MessageRead::where('message_id', $messageId)
            ->where('user_id', $userId)
            ->count() > 0;
    }
    
    /**
     * 获取消息的已读用户数量
     * @param int $messageId 消息ID
     * @return int
     */
    public static function getReadCount($messageId)
    {
        return MessageRead::where('message_id', $messageId)->count();
    }
    
    /**
     * 获取消息的已读用户列表
     * @param int $messageId 消息ID
     * @return array
     */
    public static function getReadUsers($messageId)
    {
        return MessageRead::with(['user'])
            ->where('message_id', $messageId)
            ->select()
            ->toArray();
    }
    
    /**
     * 检查消息是否被房间内任意其他用户已读（用于发送者查看）
     * @param int $messageId 消息ID
     * @param int $senderId 发送者ID
     * @return bool
     */
    public static function isReadByOthers($messageId, $senderId)
    {
        return MessageRead::where('message_id', $messageId)
            ->where('user_id', '<>', $senderId)
            ->count() > 0;
    }
}
