<?php

namespace app\controller;

use app\service\RoomService;
use think\Request;
use think\Response;

/**
 * 房间控制器
 */
class Room
{
    /**
     * 获取房间列表
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $params = $request->param();
        $result = RoomService::getRoomList($params);

        return json($result);
    }

    /**
     * 获取房间详情
     * @param int $id 房间ID
     * @return Response
     */
    public function read($id)
    {
        $result = RoomService::getRoomInfo($id);

        if ($result['code'] === 0) {
            return json($result);
        }

        return json($result, 404);
    }

    /**
     * 创建房间
     * @param Request $request
     * @return Response
     */
    public function save(Request $request)
    {
        $data = $request->param();
        $userId = $request->userId; // 从中间件获取创建者ID
        
        $result = RoomService::createRoom($data, $userId);

        if ($result['code'] === 0) {
            return json($result);
        }

        return json($result, 400);
    }

    /**
     * 更新房间
     * @param int $id 房间ID
     * @param Request $request
     * @return Response
     */
    public function update($id, Request $request)
    {
        $data = $request->param();
        $result = RoomService::updateRoom($id, $data);

        if ($result['code'] === 0) {
            return json($result);
        }

        return json($result, 400);
    }

    /**
     * 删除房间
     * @param int $id 房间ID
     * @return Response
     */
    public function delete($id)
    {
        $result = RoomService::deleteRoom($id);

        if ($result['code'] === 0) {
            return json($result);
        }

        return json($result, 400);
    }
    
    /**
     * 锁定/解锁房间
     * @param Request $request
     * @return Response
     */
    public function toggleLock(Request $request)
    {
        $roomId = $request->param('room_id');
        $lockStatus = $request->param('lock', 0); // 0=解锁 1=锁定
        $userId = $request->userId;
        
        if (empty($roomId)) {
            return json(['code' => 1, 'msg' => '参数错误'], 400);
        }
        
        $result = RoomService::toggleRoomLock($roomId, $userId, $lockStatus);
        $code = $result['code'] === 0 ? 200 : 400;
        
        return json($result, $code);
    }
}
