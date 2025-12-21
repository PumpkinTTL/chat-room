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
        $result = RoomService::createRoom($data);

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
}
