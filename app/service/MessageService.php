<?php

namespace app\service;

use app\model\Message;
use app\model\User;
use app\model\Room;
use think\facade\Db;
use think\facade\Filesystem;
use think\Exception;

/**
 * 聊天消息服务层 - 静态方法
 */
class MessageService
{
    /**
     * 发送文本消息
     * @param int $roomId 房间ID
     * @param int $userId 用户ID
     * @param string $content 消息内容
     * @return array
     */
    public static function sendTextMessage($roomId, $userId, $content)
    {
        if (empty($roomId) || empty($userId) || empty($content)) {
            return ['code' => 1, 'msg' => '参数错误'];
        }

        // 验证用户是否在房间内
        if (!RoomUserService::isUserInRoom($roomId, $userId)) {
            return ['code' => 1, 'msg' => '您不在此房间内'];
        }

        // 内容长度检查
        if (mb_strlen($content) > 10000) {
            return ['code' => 1, 'msg' => '消息内容过长，最多10000字符'];
        }

        try {
            Db::startTrans();

            $messageId = Message::insertGetId([
                'room_id'      => $roomId,
                'user_id'      => $userId,
                'message_type' => Message::TYPE_TEXT,
                'content'      => trim($content),
                'create_time'  => date('Y-m-d H:i:s'),
                'update_time'  => date('Y-m-d H:i:s'),
            ]);

            Db::commit();

            // 返回消息信息
            return [
                'code' => 0,
                'msg' => '发送成功',
                'data' => [
                    'id' => $messageId,
                    'type' => 'normal',
                    'text' => trim($content),
                    'time' => date('Y-m-d H:i:s'),
                ]
            ];

        } catch (\Exception $e) {
            Db::rollback();
            return ['code' => 1, 'msg' => '发送失败：' . $e->getMessage()];
        }
    }

    /**
     * 发送图片消息
     * @param int $roomId 房间ID
     * @param int $userId 用户ID
     * @param array $fileInfo 文件信息
     * @return array
     */
    public static function sendImageMessage($roomId, $userId, $fileInfo)
    {
        if (empty($roomId) || empty($userId) || empty($fileInfo)) {
            return ['code' => 1, 'msg' => '参数错误'];
        }

        // 验证用户是否在房间内
        if (!RoomUserService::isUserInRoom($roomId, $userId)) {
            return ['code' => 1, 'msg' => '您不在此房间内'];
        }

        try {
            Db::startTrans();

            $messageId = Message::insertGetId([
                'room_id'      => $roomId,
                'user_id'      => $userId,
                'message_type' => Message::TYPE_IMAGE,
                'content'      => $fileInfo['url'] ?? '',
                'file_info'    => json_encode($fileInfo),
                'create_time'  => date('Y-m-d H:i:s'),
                'update_time'  => date('Y-m-d H:i:s'),
            ]);

            Db::commit();

            // 返回消息信息
            return [
                'code' => 0,
                'msg' => '发送成功',
                'data' => [
                    'id' => $messageId,
                    'type' => 'image',
                    'imageUrl' => $fileInfo['url'] ?? '',
                    'time' => date('Y-m-d H:i:s'),
                ]
            ];

        } catch (\Exception $e) {
            Db::rollback();
            return ['code' => 1, 'msg' => '发送失败：' . $e->getMessage()];
        }
    }

    /**
     * 发送系统消息
     * @param int $roomId 房间ID
     * @param string $content 消息内容
     * @param array $extraData 扩展数据
     * @return array
     */
    public static function sendSystemMessage($roomId, $content, $extraData = [])
    {
        if (empty($roomId) || empty($content)) {
            return ['code' => 1, 'msg' => '参数错误'];
        }

        try {
            $message = Message::create([
                'room_id'      => $roomId,
                'user_id'      => null, // 系统消息没有用户ID
                'message_type' => Message::TYPE_SYSTEM,
                'content'      => $content,
                'extra_data'   => $extraData,
            ]);

            return ['code' => 0, 'msg' => '发送成功', 'data' => ['id' => $message->id]];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '发送失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取房间消息列表
     * @param int $roomId 房间ID
     * @param int $userId 当前用户ID
     * @param int $page 页码
     * @param int $limit 每页数量
     * @param string|null $lastTime 上一次拉取的时间（用于增量）
     * @return array
     */
    public static function getRoomMessages($roomId, $userId, $page = 1, $limit = 20, $lastTime = null)
    {
        if (empty($roomId) || empty($userId)) {
            return ['code' => 1, 'msg' => '参数错误'];
        }

        // 验证用户是否在房间内
        if (!RoomUserService::isUserInRoom($roomId, $userId)) {
            return ['code' => 1, 'msg' => '您不在此房间内'];
        }

        try {
            $query = Message::with(['user'])
                ->byRoom($roomId)
                ->order('create_time', 'desc');

            // 如果有lastTime，获取增量消息
            if ($lastTime) {
                $query->afterTime($lastTime);
                $messages = $query->select()->toArray();
                // 增量消息需要按时间正序
                $messages = array_reverse($messages);
                $hasMore = false; // 增量消息不需要分页
            } else {
                // 分页获取历史消息，多查一条用于判断是否还有更多
                $messages = $query->page($page, $limit + 1)->select()->toArray();
                
                // 判断是否还有更多
                $hasMore = count($messages) > $limit;
                
                // 如果超过limit条，去掉最后一条
                if ($hasMore) {
                    array_pop($messages);
                }
                
                // 历史消息需要按时间正序
                $messages = array_reverse($messages);
            }

            // 获取消息ID列表
            $messageIds = array_column($messages, 'id');
            
            // 批量查询已读状态和已读人数
            $readStatusMap = [];
            $readCountMap = [];
            $readUsersMap = [];
            
            if (!empty($messageIds)) {
                // 查询每条消息的已读人数（排除发送者自己）
                $readCounts = \think\facade\Db::table('ch_message_reads')
                    ->alias('r')
                    ->join('ch_messages m', 'r.message_id = m.id')
                    ->whereIn('r.message_id', $messageIds)
                    ->whereRaw('r.user_id != m.user_id') // 排除发送者自己
                    ->group('r.message_id')
                    ->column('COUNT(*) as cnt', 'r.message_id');
                
                foreach ($readCounts as $msgId => $count) {
                    $readCountMap[$msgId] = (int)$count;
                    $readStatusMap[$msgId] = $count > 0;
                }
                
                // 查询已读用户列表（最多显示5个）
                $readUsers = \think\facade\Db::table('ch_message_reads')
                    ->alias('r')
                    ->join('ch_users u', 'r.user_id = u.id')
                    ->join('ch_messages m', 'r.message_id = m.id')
                    ->whereIn('r.message_id', $messageIds)
                    ->whereRaw('r.user_id != m.user_id') // 排除发送者自己
                    ->field('r.message_id, r.user_id, u.nick_name, r.read_at')
                    ->order('r.read_at', 'desc')
                    ->select()
                    ->toArray();
                
                foreach ($readUsers as $record) {
                    $msgId = $record['message_id'];
                    if (!isset($readUsersMap[$msgId])) {
                        $readUsersMap[$msgId] = [];
                    }
                    // 每条消息最多保留5个已读用户
                    if (count($readUsersMap[$msgId]) < 5) {
                        $readUsersMap[$msgId][] = [
                            'user_id' => $record['user_id'],
                            'nickname' => $record['nick_name'],
                            'read_at' => $record['read_at']
                        ];
                    }
                }
            }

            // 格式化消息数据
            $formattedMessages = [];
            foreach ($messages as $message) {
                $formatted = self::formatMessage($message, $userId);
                $msgId = $message['id'];
                
                // 添加已读信息
                if ($message['user_id'] == $userId) {
                    // 自己发的消息：显示被多少人已读
                    $formatted['is_read'] = isset($readStatusMap[$msgId]) && $readStatusMap[$msgId];
                    $formatted['read_count'] = $readCountMap[$msgId] ?? 0;
                    $formatted['read_users'] = $readUsersMap[$msgId] ?? [];
                } else {
                    // 别人的消息：对自己来说不需要显示已读状态
                    $formatted['is_read'] = true;
                    $formatted['read_count'] = 0;
                    $formatted['read_users'] = [];
                }
                $formattedMessages[] = $formatted;
            }

            return [
                'code' => 0,
                'msg'  => '获取成功',
                'data' => [
                    'messages' => $formattedMessages,
                    'has_more' => $hasMore,
                ]
            ];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '获取失败：' . $e->getMessage()];
        }
    }

    /**
     * 撤回消息（软删除）
     * @param int $messageId 消息ID
     * @param int $userId 用户ID
     * @return array
     */
    public static function recallMessage($messageId, $userId)
    {
        if (empty($messageId) || empty($userId)) {
            return ['code' => 1, 'msg' => '参数错误'];
        }

        try {
            $message = Message::find($messageId);
            if (!$message) {
                return ['code' => 1, 'msg' => '消息不存在'];
            }

            // 检查是否为消息发送者
            if ($message->user_id != $userId) {
                return ['code' => 1, 'msg' => '只能撤回自己的消息'];
            }

            // 检查撤回时间限制（2分钟内）
            if (time() - strtotime($message->create_time) > 120) {
                return ['code' => 1, 'msg' => '超过2分钟，无法撤回'];
            }

            // 软删除
            Message::destroy($messageId);

            return ['code' => 0, 'msg' => '撤回成功'];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '撤回失败：' . $e->getMessage()];
        }
    }

    /**
     * 焚毁消息（软删除）
     * @param int $messageId 消息ID
     * @param int $userId 用户ID
     * @return array
     */
    public static function burnMessage($messageId, $userId)
    {
        if (empty($messageId) || empty($userId)) {
            return ['code' => 1, 'msg' => '参数错误'];
        }

        try {
            $message = Message::find($messageId);
            if (!$message) {
                return ['code' => 1, 'msg' => '消息不存在'];
            }

            // 检查是否为消息发送者
            if ($message->user_id != $userId) {
                return ['code' => 1, 'msg' => '只能焚毁自己的消息'];
            }

            // 软删除
            Message::destroy($messageId);

            return ['code' => 0, 'msg' => '焚毁成功'];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '焚毁失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取单条消息详情
     * @param int $messageId 消息ID
     * @param int $userId 当前用户ID
     * @return array|null
     */
    public static function getMessageById($messageId, $userId)
    {
        $message = Message::with(['user'])
            ->where('id', $messageId)
            ->find();

        if (!$message) {
            return null;
        }

        return self::formatMessage($message->toArray(), $userId);
    }

    /**
     * 格式化消息数据
     * @param array $message 原始消息数据
     * @param int $currentUserId 当前用户ID
     * @return array
     */
    private static function formatMessage($message, $currentUserId)
    {
        // 根据消息类型设置type字段
        $type = 'normal';
        if ($message['message_type'] == Message::TYPE_SYSTEM) {
            $type = 'system';
        } elseif ($message['message_type'] == Message::TYPE_IMAGE) {
            $type = 'image';
        }

        return [
            'id'           => $message['id'],
            'room_id'      => $message['room_id'],
            'type'         => $type,
            'text'         => $message['content'],
            'imageUrl'     => $message['message_type'] == Message::TYPE_IMAGE ? $message['content'] : null,
            'fileInfo'     => $message['file_info'] ?? null,
            'time'         => $message['create_time'],
            'isOwn'        => $message['user_id'] == $currentUserId,
            'sender'       => [
                'id'         => $message['user']['id'] ?? null,
                'nickname'   => $message['user']['nick_name'] ?? '系统',
                'avatar'     => $message['user']['avatar'] ?? null,
            ],
        ];
    }

    /**
     * 获取房间最新消息时间
     * @param int $roomId 房间ID
     * @return string|null
     */
    public static function getLatestMessageTime($roomId)
    {
        $message = Message::byRoom($roomId)
            ->order('create_time', 'desc')
            ->field('create_time')
            ->find();

        return $message ? $message->create_time : null;
    }

    /**
     * 清理房间所有消息（物理删除，包括文件）
     * @param int $roomId 房间ID
     * @param int $userId 操作用户ID
     * @return array
     */
    public static function clearRoomMessages($roomId, $userId)
    {
        if (empty($roomId) || empty($userId)) {
            return ['code' => 1, 'msg' => '参数错误'];
        }

        // 管理员ID
        $ADMIN_ID = 3306;
        
        // 获取房间信息
        $room = \app\model\Room::find($roomId);
        if (!$room) {
            return ['code' => 1, 'msg' => '房间不存在'];
        }
        
        // 权限检查：只有管理员(3306)或房主可以清理
        $isAdmin = ($userId == $ADMIN_ID);
        $isOwner = ($room->owner_id && $userId == $room->owner_id);
        
        if (!$isAdmin && !$isOwner) {
            return ['code' => 1, 'msg' => '权限不足，只有管理员或房主可以清理房间'];
        }

        // 验证用户是否在房间内
        if (!RoomUserService::isUserInRoom($roomId, $userId)) {
            return ['code' => 1, 'msg' => '您不在此房间内'];
        }

        try {
            Db::startTrans();

            // 获取所有消息的总数（用于统计）
            $totalMessages = Db::table('ch_messages')
                ->where('room_id', $roomId)
                ->count();

            // 获取所有图片消息的文件路径
            $imageMessages = Db::table('ch_messages')
                ->where('room_id', $roomId)
                ->where('message_type', Message::TYPE_IMAGE)
                ->whereNotNull('file_info')
                ->select()
                ->toArray();

            // 删除关联的已读记录
            Db::table('ch_message_reads')
                ->whereIn('message_id', function($query) use ($roomId) {
                    $query->table('ch_messages')
                        ->where('room_id', $roomId)
                        ->field('id');
                })
                ->delete();

            // 物理删除所有消息（包括软删除的记录）
            Db::table('ch_messages')
                ->where('room_id', $roomId)
                ->delete();

            Db::commit();

            // 删除文件（在事务提交后）
            $deletedFiles = 0;
            $error_log_path = runtime_path() . 'log/file_deletion.log';

            foreach ($imageMessages as $message) {
                $filePath = null;

                // 方法1: 优先使用 file_info 中的 path 字段
                if (!empty($message['file_info'])) {
                    if (is_string($message['file_info'])) {
                        $fileInfo = json_decode($message['file_info'], true);
                    } else {
                        $fileInfo = $message['file_info'];
                    }

                    if (!empty($fileInfo['path'])) {
                        $filePath = $fileInfo['path'];
                    }
                }

                // 方法2: 如果 file_info 没有 path，从 content 字段提取
                if (empty($filePath) && !empty($message['content'])) {
                    $content = $message['content'];
                    // content 格式可能是: /storage/images/xxx.jpg 或 images/xxx.jpg
                    if (strpos($content, '/storage/') === 0) {
                        $filePath = substr($content, 9); // 去掉 /storage/
                    } elseif (strpos($content, '/') === 0) {
                        $filePath = substr($content, 1); // 去掉开头的 /
                    } else {
                        $filePath = $content;
                    }
                }

                // 删除文件
                if (!empty($filePath)) {
                    // 使用 error_log 记录调试信息
                    $debugMsg = date('Y-m-d H:i:s') . " - 尝试删除文件: " . $filePath . "\n";
                    $debugMsg .= "  file_info原始值: " . ($message['file_info'] ?? 'null') . "\n";
                    $debugMsg .= "  content原始值: " . ($message['content'] ?? 'null') . "\n";
                    error_log($debugMsg, 3, $error_log_path);

                    $result = UploadService::deleteFile($filePath);

                    $resultMsg = date('Y-m-d H:i:s') . " - 删除结果: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
                    error_log($resultMsg, 3, $error_log_path);

                    if ($result['code'] === 0 && strpos($result['msg'], '删除成功') !== false) {
                        $deletedFiles++;
                    }
                }
            }

            // 记录总体统计
            $summaryMsg = date('Y-m-d H:i:s') . " - 清理完成: 找到 " . count($imageMessages) . " 条图片消息，实际删除 " . $deletedFiles . " 个文件\n";
            error_log($summaryMsg, 3, $error_log_path);

            return [
                'code' => 0,
                'msg' => '清理成功',
                'data' => [
                    'deleted_messages' => $totalMessages,
                    'deleted_files' => $deletedFiles
                ]
            ];

        } catch (\Exception $e) {
            Db::rollback();
            return ['code' => 1, 'msg' => '清理失败：' . $e->getMessage()];
        }
    }
}