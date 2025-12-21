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
        if (mb_strlen($content) > 1000) {
            return ['code' => 1, 'msg' => '消息内容过长，最多1000字符'];
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
            } else {
                // 分页获取历史消息
                $messages = $query->page($page, $limit)->select()->toArray();
                // 历史消息需要按时间正序
                $messages = array_reverse($messages);
            }

            // 格式化消息数据
            $formattedMessages = [];
            foreach ($messages as $message) {
                $formattedMessages[] = self::formatMessage($message, $userId);
            }

            return [
                'code' => 0,
                'msg'  => '获取成功',
                'data' => [
                    'messages' => $formattedMessages,
                    'has_more' => count($formattedMessages) >= $limit,
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
}