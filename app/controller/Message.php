<?php

namespace app\controller;

use app\service\MessageService;
use app\service\UploadService;
use think\Request;
use think\Response;
use think\facade\Validate;

/**
 * 聊天消息控制器
 */
class Message
{
    /**
     * 获取房间消息列表
     * @param Request $request
     * @return Response
     */
    public function list(Request $request)
    {
        $roomId = $request->param('room_id');
        $page = $request->param('page', 1, 'intval');
        $limit = $request->param('limit', 20, 'intval');
        $lastTime = $request->param('last_time', '');

        $userId = $request->userId; // 从中间件获取

        // 验证参数
        $validate = Validate::rule([
            'room_id' => 'require|integer|min:1',
            'page'    => 'integer|min:1',
            'limit'   => 'integer|between:1,100',
        ]);

        if (!$validate->check(['room_id' => $roomId, 'page' => $page, 'limit' => $limit])) {
            return json(['code' => 1, 'msg' => $validate->getError()], 400);
        }

        $result = MessageService::getRoomMessages($roomId, $userId, $page, $limit, $lastTime ?: null);
        $code = $result['code'] === 0 ? 200 : 400;

        return json($result, $code);
    }

    /**
     * 发送文本消息
     * @param Request $request
     * @return Response
     */
    public function sendText(Request $request)
    {
        $roomId = $request->param('room_id');
        $content = $request->param('content');

        $userId = $request->userId; // 从中间件获取

        // 验证参数
        $validate = Validate::rule([
            'room_id' => 'require|integer|min:1',
            'content' => 'require|max:10000',
        ]);

        if (!$validate->check(['room_id' => $roomId, 'content' => $content])) {
            return json(['code' => 1, 'msg' => $validate->getError()], 400);
        }

        $result = MessageService::sendTextMessage($roomId, $userId, $content);
        $code = $result['code'] === 0 ? 200 : 400;

        return json($result, $code);
    }

    /**
     * 发送图片消息
     * @param Request $request
     * @return Response
     */
    public function sendImage(Request $request)
    {
        $roomId = $request->param('room_id');
        $file = $request->file('image');

        $userId = $request->userId; // 从中间件获取

        // 验证参数
        $validate = Validate::rule([
            'room_id' => 'require|integer|min:1',
        ]);

        if (!$validate->check(['room_id' => $roomId])) {
            return json(['code' => 1, 'msg' => $validate->getError()], 400);
        }

        // 检查文件
        if (!$file) {
            return json(['code' => 1, 'msg' => '请选择图片文件'], 400);
        }

        try {
            // 上传图片
            $uploadResult = UploadService::uploadImage($file);
            if ($uploadResult['code'] !== 0) {
                return json($uploadResult, 400);
            }

            // 发送消息
            $result = MessageService::sendImageMessage($roomId, $userId, $uploadResult['data']);
            $code = $result['code'] === 0 ? 200 : 400;

            return json($result, $code);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '上传失败：' . $e->getMessage()], 500);
        }
    }

    /**
     * 发送视频消息
     * @param Request $request
     * @return Response
     */
    public function sendVideo(Request $request)
    {
        $roomId = $request->param('room_id');
        $file = $request->file('video');

        $userId = $request->userId; // 从中间件获取

        // 验证参数
        $validate = Validate::rule([
            'room_id' => 'require|integer|min:1',
        ]);

        if (!$validate->check(['room_id' => $roomId])) {
            return json(['code' => 1, 'msg' => $validate->getError()], 400);
        }

        // 检查文件
        if (!$file) {
            return json(['code' => 1, 'msg' => '请选择视频文件'], 400);
        }

        try {
            // 上传视频
            $uploadResult = UploadService::uploadVideo($file);
            if ($uploadResult['code'] !== 0) {
                return json($uploadResult, 400);
            }

            // 发送消息
            $result = MessageService::sendVideoMessage($roomId, $userId, $uploadResult['data']);
            $code = $result['code'] === 0 ? 200 : 400;

            return json($result, $code);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '上传失败：' . $e->getMessage()], 500);
        }
    }

    /**
     * 发送文件消息
     * @param Request $request
     * @return Response
     */
    public function sendFile(Request $request)
    {
        $roomId = $request->param('room_id');
        $file = $request->file('file');

        $userId = $request->userId; // 从中间件获取

        // 验证参数
        $validate = Validate::rule([
            'room_id' => 'require|integer|min:1',
        ]);

        if (!$validate->check(['room_id' => $roomId])) {
            return json(['code' => 1, 'msg' => $validate->getError()], 400);
        }

        // 检查文件
        if (!$file) {
            return json(['code' => 1, 'msg' => '请选择文件'], 400);
        }

        try {
            // 上传文件
            $uploadResult = UploadService::uploadDocument($file);
            if ($uploadResult['code'] !== 0) {
                return json($uploadResult, 400);
            }

            // 发送消息
            $result = MessageService::sendFileMessage($roomId, $userId, $uploadResult['data']);
            $code = $result['code'] === 0 ? 200 : 400;

            return json($result, $code);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '上传失败：' . $e->getMessage()], 500);
        }
    }

    /**
     * 撤回消息
     * @param Request $request
     * @return Response
     */
    public function recall(Request $request)
    {
        $messageId = $request->param('message_id');

        $userId = $request->userId; // 从中间件获取

        // 验证参数
        $validate = Validate::rule([
            'message_id' => 'require|integer|min:1',
        ]);

        if (!$validate->check(['message_id' => $messageId])) {
            return json(['code' => 1, 'msg' => $validate->getError()], 400);
        }

        $result = MessageService::recallMessage($messageId, $userId);
        $code = $result['code'] === 0 ? 200 : 400;

        return json($result, $code);
    }

    /**
     * 焚毁消息（删除）
     * @param Request $request
     * @return Response
     */
    public function burn(Request $request)
    {
        $messageId = $request->param('message_id');

        $userId = $request->userId; // 从中间件获取

        // 验证参数
        $validate = Validate::rule([
            'message_id' => 'require|integer|min:1',
        ]);

        if (!$validate->check(['message_id' => $messageId])) {
            return json(['code' => 1, 'msg' => $validate->getError()], 400);
        }

        $result = MessageService::burnMessage($messageId, $userId);
        $code = $result['code'] === 0 ? 200 : 400;

        return json($result, $code);
    }

    /**
     * 获取最新消息时间
     * @param Request $request
     * @return Response
     */
    public function latestTime(Request $request)
    {
        $roomId = $request->param('room_id');

        // 验证参数
        $validate = Validate::rule([
            'room_id' => 'require|integer|min:1',
        ]);

        if (!$validate->check(['room_id' => $roomId])) {
            return json(['code' => 1, 'msg' => $validate->getError()], 400);
        }

        try {
            $latestTime = MessageService::getLatestMessageTime($roomId);
            return json([
                'code' => 0,
                'msg'  => '获取成功',
                'data' => [
                    'latest_time' => $latestTime,
                    'timestamp'   => $latestTime ? strtotime($latestTime) : 0,
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取失败：' . $e->getMessage()], 500);
        }
    }

    /**
     * 获取未读消息数量（预留接口）
     * @param Request $request
     * @return Response
     */
    public function unreadCount(Request $request)
    {
        $roomId = $request->param('room_id');
        $userId = $request->userId;

        // 验证参数
        $validate = Validate::rule([
            'room_id' => 'require|integer|min:1',
        ]);

        if (!$validate->check(['room_id' => $roomId])) {
            return json(['code' => 1, 'msg' => $validate->getError()], 400);
        }

        // 目前返回0，后续可以根据业务需求实现
        return json([
            'code' => 0,
            'msg'  => '获取成功',
            'data' => [
                'unread_count' => 0,
            ]
        ]);
    }

    /**
     * 标记消息为已读
     * @param Request $request
     * @return Response
     */
    public function markRead(Request $request)
    {
        $messageIds = $request->param('message_ids');
        $roomId = $request->param('room_id');
        $userId = $request->userId;

        // 支持单个消息ID或数组
        if (!empty($messageIds)) {
            if (!is_array($messageIds)) {
                $messageIds = [$messageIds];
            }
            $count = \app\service\MessageReadService::batchMarkAsRead($messageIds, $userId);
            
            // 通过WebSocket通知发送者（如果有的话）
            self::notifyReadStatus($messageIds, $userId);
        } elseif (!empty($roomId)) {
            // 标记整个房间的消息为已读
            $count = \app\service\MessageReadService::markRoomMessagesAsRead($roomId, $userId);
        } else {
            return json(['code' => 1, 'msg' => '请提供消息ID或房间ID'], 400);
        }

        return json([
            'code' => 0,
            'msg'  => '标记成功',
            'data' => ['count' => $count]
        ]);
    }
    
    /**
     * 通知消息发送者已读状态（预留，后续通过WebSocket实现）
     */
    private static function notifyReadStatus($messageIds, $readerId)
    {
        // TODO: 通过WebSocket广播已读状态给消息发送者
        // 这里可以通过Redis发布订阅或直接调用WebSocket服务
    }

    /**
     * 清理房间所有消息（房主或管理员可用）
     * @param Request $request
     * @return Response
     */
    public function clearRoom(Request $request)
    {
        $roomId = $request->param('room_id');
        $userId = $request->userId; // 从中间件获取

        // 验证参数
        $validate = Validate::rule([
            'room_id' => 'require|integer|min:1',
        ]);

        if (!$validate->check(['room_id' => $roomId])) {
            return json(['code' => 1, 'msg' => $validate->getError()], 400);
        }

        $result = MessageService::clearRoomMessages($roomId, $userId);
        $code = $result['code'] === 0 ? 200 : 400;

        return json($result, $code);
    }

    /**
     * 发送系统消息（时间分隔等）
     * @param Request $request
     * @return Response
     */
    public function sendSystem(Request $request)
    {
        $roomId = $request->param('room_id');
        $content = $request->param('content');

        // 验证参数
        $validate = Validate::rule([
            'room_id' => 'require|integer|min:1',
            'content' => 'require|max:500',
        ]);

        if (!$validate->check(['room_id' => $roomId, 'content' => $content])) {
            return json(['code' => 1, 'msg' => $validate->getError()], 400);
        }

        $result = MessageService::sendSystemMessage($roomId, $content);
        $code = $result['code'] === 0 ? 200 : 400;

        return json($result, $code);
    }
}
