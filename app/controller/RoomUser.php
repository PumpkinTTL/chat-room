<?php

namespace app\controller;

use app\service\RoomUserService;
use think\Request;
use think\Response;

/**
 * 房间成员控制器
 */
class RoomUser
{
    /**
     * 用户加入房间
     * @param Request $request
     * @return Response
     */
    public function join(Request $request)
    {
        $roomId = $request->param('room_id');
        $userId = $request->userId; // 从中间件获取

        if (!$roomId) {
            return json(['code' => 1, 'msg' => '房间ID不能为空'], 400);
        }

        $result = RoomUserService::joinRoom($roomId, $userId);
        $code = $result['code'] === 0 ? 200 : 400;

        return json($result, $code);
    }

    /**
     * 用户离开房间
     * @param Request $request
     * @return Response
     */
    public function leave(Request $request)
    {
        $roomId = $request->param('room_id');
        $userId = $request->userId; // 从中间件获取

        if (!$roomId) {
            return json(['code' => 1, 'msg' => '房间ID不能为空'], 400);
        }

        $result = RoomUserService::leaveRoom($roomId, $userId);
        $code = $result['code'] === 0 ? 200 : 400;

        return json($result, $code);
    }

    /**
     * 获取房间在线用户数
     * @param int $roomId 房间ID
     * @return Response
     */
    public function count($roomId)
    {
        if (!$roomId) {
            return json(['code' => 1, 'msg' => '房间ID不能为空'], 400);
        }

        $count = RoomUserService::getOnlineUserCount($roomId);

        return json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => [
                'room_id' => $roomId,
                'online_count' => $count
            ]
        ]);
    }

    /**
     * 获取房间在线用户列表
     * @param int $roomId 房间ID
     * @return Response
     */
    public function list($roomId)
    {
        if (!$roomId) {
            return json(['code' => 1, 'msg' => '房间ID不能为空'], 400);
        }

        $users = RoomUserService::getOnlineUserList($roomId);

        return json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => [
                'room_id' => $roomId,
                'users' => $users
            ]
        ]);
    }

    /**
     * 获取用户加入的房间列表
     * @param Request $request
     * @return Response
     */
    public function userRooms(Request $request)
    {
        $userId = $request->userId; // 从中间件获取
        $rooms = RoomUserService::getUserRooms($userId);

        return json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $rooms
        ]);
    }

    /**
     * 检查用户是否在房间内
     * @param Request $request
     * @return Response
     */
    public function check(Request $request)
    {
        $roomId = $request->param('room_id');
        $userId = $request->userId; // 从中间件获取

        if (!$roomId) {
            return json(['code' => 1, 'msg' => '房间ID不能为空'], 400);
        }

        $isInRoom = RoomUserService::isUserInRoom($roomId, $userId);

        return json([
            'code' => 0,
            'msg' => '检查完成',
            'data' => [
                'room_id' => $roomId,
                'user_id' => $userId,
                'is_in_room' => $isInRoom
            ]
        ]);
    }
}