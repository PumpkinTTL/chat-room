<?php

namespace app\service;

use app\model\Room;
use app\service\RoomUserService;
use think\facade\Db;

/**
 * 房间服务层 - 静态方法
 */
class RoomService
{
    /**
     * 获取房间列表
     * @param array $params 查询参数
     * @return array
     */
    public static function getRoomList($params = [])
    {
        $query = Room::where([]);

        // 状态筛选
        if (isset($params['status']) && in_array($params['status'], [0, 1])) {
            $query->where('status', $params['status']);
        }

        // 房间名称搜索
        if (!empty($params['name'])) {
            $query->where('name', 'like', '%' . $params['name'] . '%');
        }

        $page = isset($params['page']) ? (int)$params['page'] : 1;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 20;

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return [
            'total' => $total,
            'list' => $list,
        ];
    }

    /**
     * 创建房间
     * @param array $data 房间数据
     * @param int $userId 创建者ID
     * @return array
     */
    public static function createRoom($data, $userId = null)
    {
        // 数据验证
        if (empty($data['name'])) {
            return ['code' => 1, 'msg' => '房间名称不能为空'];
        }

        if (strlen($data['name']) > 100) {
            return ['code' => 1, 'msg' => '房间名称不能超过100个字符'];
        }

        // 生成随机5位数ID（10000-99999）
        $roomId = self::generateUniqueRoomId();
        
        try {
            // 使用Db直接插入
            Db::name('rooms')->insert([
                'id' => $roomId,
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'owner_id' => $userId,
                'status' => Room::STATUS_NORMAL,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            
            // 创建者自动加入房间
            if ($userId) {
                RoomUserService::joinRoom($roomId, $userId);
            }
            
            return [
                'code' => 0,
                'msg'  => '创建成功',
                'data' => [
                    'id' => $roomId,
                    'name' => $data['name'],
                ],
            ];
        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '创建失败：' . $e->getMessage()];
        }
    }
    
    /**
     * 生成唯一的房间ID（5位数）
     * @return int
     */
    private static function generateUniqueRoomId()
    {
        $maxAttempts = 10;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $roomId = rand(10000, 99999);
            // 检查是否已存在
            $exists = Db::table('ch_rooms')->where('id', $roomId)->find();
            if (!$exists) {
                return $roomId;
            }
        }
        // 如果10次都重复，用时间戳后5位
        return (int)(time() % 100000);
    }

    /**
     * 更新房间
     * @param int $id 房间ID
     * @param array $data 房间数据
     * @return array
     */
    public static function updateRoom($id, $data)
    {
        // 验证房间是否存在
        $roomInfo = Room::find($id);
        if (!$roomInfo) {
            return ['code' => 1, 'msg' => '房间不存在'];
        }

        // 数据验证
        if (!empty($data['name'])) {
            if (strlen($data['name']) > 100) {
                return ['code' => 1, 'msg' => '房间名称不能超过100个字符'];
            }
        }

        // 验证描述长度
        if (!empty($data['description']) && strlen($data['description']) > 500) {
            return ['code' => 1, 'msg' => '房间描述不能超过500个字符'];
        }

        // 验证状态值
        if (isset($data['status']) && !in_array($data['status'], [0, 1])) {
            return ['code' => 1, 'msg' => '状态值无效'];
        }

        try {
            $result = $roomInfo->save($data);
            if ($result) {
                return ['code' => 0, 'msg' => '更新成功'];
            }
            return ['code' => 1, 'msg' => '更新失败'];
        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '更新失败：' . $e->getMessage()];
        }
    }

    /**
     * 删除房间
     * @param int $id 房间ID
     * @return array
     */
    public static function deleteRoom($id)
    {
        // 验证房间是否存在
        $roomInfo = Room::find($id);
        if (!$roomInfo) {
            return ['code' => 1, 'msg' => '房间不存在'];
        }

        try {
            $result = $roomInfo->delete();
            if ($result) {
                return ['code' => 0, 'msg' => '删除成功'];
            }
            return ['code' => 1, 'msg' => '删除失败'];
        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '删除失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取房间详情
     * @param int $id 房间ID
     * @return array
     */
    public static function getRoomInfo($id)
    {
        $roomInfo = Room::find($id);
        if ($roomInfo) {
            return ['code' => 0, 'msg' => '获取成功', 'data' => $roomInfo];
        }
        return ['code' => 1, 'msg' => '房间不存在'];
    }
}
