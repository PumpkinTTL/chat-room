<?php

namespace app\service;

use app\model\RoomUser;
use app\model\User;
use think\facade\Db;

/**
 * 房间成员服务层 - 静态方法
 */
class RoomUserService
{
    /**
     * 用户加入房间
     * @param int $roomId 房间ID
     * @param int $userId 用户ID
     * @param string $password 房间密码（可选）
     * @param bool $skipPasswordCheck 跳过密码验证（创建者自动加入时使用）
     * @return array
     */
    public static function joinRoom($roomId, $userId, $password = null, $skipPasswordCheck = false)
    {
        // 验证参数
        if (empty($roomId) || empty($userId)) {
            return ['code' => 1, 'msg' => '参数错误'];
        }

        try {
            // 检查房间是否存在
            $room = \app\model\Room::find($roomId);
            if (!$room) {
                return ['code' => 1, 'msg' => '房间不存在'];
            }
            
            // 检查房间密码（创建者跳过验证）
            if (!$skipPasswordCheck && !empty($room['password'])) {
                if ($password === null || $password !== $room['password']) {
                    return ['code' => 1, 'msg' => '房间密码错误'];
                }
            }
            
            // 检查是否已经在房间内
            $existing = RoomUser::where('room_id', $roomId)
                ->where('user_id', $userId)
                ->find();

            if ($existing) {
                if ($existing['status'] == RoomUser::STATUS_IN_ROOM) {
                    return ['code' => 1, 'msg' => '您已经在房间内'];
                }
                // 重新加入，更新状态和时间
                $existing->status = RoomUser::STATUS_IN_ROOM;
                $existing->join_time = date('Y-m-d H:i:s');
                $existing->save();
                return ['code' => 0, 'msg' => '重新加入成功'];
            }

            // 新加入房间
            RoomUser::create([
                'room_id' => $roomId,
                'user_id' => $userId,
                'status' => RoomUser::STATUS_IN_ROOM,
                'join_time' => date('Y-m-d H:i:s'),
            ]);

            return ['code' => 0, 'msg' => '加入成功'];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '加入失败：' . $e->getMessage()];
        }
    }

    /**
     * 用户离开房间
     * @param int $roomId 房间ID
     * @param int $userId 用户ID
     * @return array
     */
    public static function leaveRoom($roomId, $userId)
    {
        if (empty($roomId) || empty($userId)) {
            return ['code' => 1, 'msg' => '参数错误'];
        }

        try {
            $result = RoomUser::where('room_id', $roomId)
                ->where('user_id', $userId)
                ->delete();

            if ($result) {
                return ['code' => 0, 'msg' => '离开成功'];
            }
            return ['code' => 1, 'msg' => '您不在此房间内'];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '离开失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取房间在线用户数
     * @param int $roomId 房间ID
     * @return int
     */
    public static function getOnlineUserCount($roomId)
    {
        return RoomUser::where('room_id', $roomId)
            ->where('status', RoomUser::STATUS_IN_ROOM)
            ->count();
    }

    /**
     * 获取房间在线用户列表
     * @param int $roomId 房间ID
     * @return array
     */
    public static function getOnlineUserList($roomId)
    {
        return RoomUser::alias('ru')
            ->join('ch_users u', 'ru.user_id = u.id')
            ->where('ru.room_id', $roomId)
            ->where('ru.status', RoomUser::STATUS_IN_ROOM)
            ->field('u.id, u.nick_name, u.avatar, ru.join_time')
            ->select()
            ->toArray();
    }

    /**
     * 检查用户是否在房间内
     * @param int $roomId 房间ID
     * @param int $userId 用户ID
     * @return bool
     */
    public static function isUserInRoom($roomId, $userId)
    {
        return RoomUser::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->where('status', RoomUser::STATUS_IN_ROOM)
            ->count() > 0;
    }

    /**
     * 获取用户加入的房间列表
     * @param int $userId 用户ID
     * @return array
     */
    public static function getUserRooms($userId)
    {
        // 获取用户加入的房间列表
        $rooms = RoomUser::alias('ru')
            ->join('ch_rooms r', 'ru.room_id=r.id')
            ->where('ru.user_id', $userId)
            ->where('ru.status', RoomUser::STATUS_IN_ROOM)
            ->where('r.status', 1)
            ->field('r.id, r.name, r.description, r.private, r.lock, r.owner_id, ru.join_time')
            ->select()
            ->toArray();

        // 为每个房间附加最后消息和未读数
        foreach ($rooms as &$room) {
            // 获取最后一条消息
            $lastMsg = \app\model\Message::where('room_id', $room['id'])
                ->whereNull('delete_time')
                ->order('id desc')
                ->find();
            
            if ($lastMsg) {
                $room['lastMessage'] = $lastMsg['content'];
                $room['lastMessageTime'] = $lastMsg['create_time'];
                $room['lastMessageType'] = $lastMsg['message_type'];
            } else {
                $room['lastMessage'] = null;
                $room['lastMessageTime'] = null;
                $room['lastMessageType'] = null;
            }

            // 获取未读消息数（需要通过 Message 表关联获取）
            $unreadCount = \app\model\Message::where('room_id', $room['id'])
                ->where('user_id', '<>', $userId)
                ->whereNotExists(function ($query) use ($userId) {
                    $query->table('ch_message_reads')
                        ->where('ch_message_reads.message_id', '=', Db::raw('ch_messages.id'))
                        ->where('ch_message_reads.user_id', '=', $userId);
                })
                ->count();
            
            $room['unreadCount'] = $unreadCount;
        }

        return $rooms;
    }

    /**
     * 批量清理已离开的记录（定时任务用）
     * @param int $days 清理多少天前的记录
     * @return int 清理数量
     */
    public static function cleanLeftRecords($days = 30)
    {
        $cutoffTime = date('Y-m-d H:i:s', time() - $days * 24 * 3600);
        return RoomUser::where('status', RoomUser::STATUS_LEFT)
            ->where('join_time', '<', $cutoffTime)
            ->delete();
    }
}