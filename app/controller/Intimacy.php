<?php

namespace app\controller;

use app\BaseController;
use app\service\IntimacyService;
use app\service\RoomUserService;
use think\Request;
use think\Response;

class Intimacy extends BaseController
{
    /**
     * 获取用户在指定房间的好感度信息
     * @param Request $request
     * @return Response
     */
    public function getIntimacyInfo(Request $request)
    {
        try {
            $roomId = $request->param('room_id');
            $userId = $request->userId; // 从中间件获取
            
            // 参数验证
            if (empty($roomId) || !is_numeric($roomId)) {
                return json(['code' => 1, 'msg' => '房间ID参数错误'], 400);
            }
            
            // 检查用户是否在房间内
            if (!RoomUserService::isUserInRoom($roomId, $userId)) {
                return json(['code' => 1, 'msg' => '您不在此房间内'], 403);
            }
            
            // 检查是否为私密房间
            $room = \app\model\Room::find($roomId);
            if (!$room) {
                return json(['code' => 1, 'msg' => '房间不存在'], 404);
            }
            
            if ($room->private != 1) {
                return json(['code' => 1, 'msg' => '只有私密房间才有好感度系统'], 400);
            }
            
            // 获取房间内的伴侣
            $roomUsers = \think\facade\Db::name('ch_room_users')
                ->where('room_id', $roomId)
                ->where('status', 1)
                ->column('user_id');
                
            if (count($roomUsers) != 2) {
                return json(['code' => 1, 'msg' => '私密房间必须恰好有2个用户'], 400);
            }
            
            $partnerId = null;
            foreach ($roomUsers as $uid) {
                if ($uid != $userId) {
                    $partnerId = $uid;
                    break;
                }
            }
            
            if (!$partnerId) {
                return json(['code' => 1, 'msg' => '未找到伴侣'], 400);
            }
            
            // 获取好感度信息
            $intimacyInfo = IntimacyService::getIntimacyInfo($roomId, $userId, $partnerId);
            
            return json([
                'code' => 0,
                'msg' => '获取成功',
                'data' => $intimacyInfo
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 1, 
                'msg' => '获取失败：' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
    
    /**
     * 获取所有等级配置
     * @param Request $request
     * @return Response
     */
    public function getLevels(Request $request)
    {
        $levels = IntimacyService::getAllLevels();
        
        return json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $levels
        ]);
    }
}