<?php

namespace extend\websocket;

use app\model\Room;

/**
 * 亲密度系统处理器
 * 
 * 负责处理房间亲密度互动相关功能：
 * - 检查房间亲密互动状态
 * - 启动/停止亲密互动
 * - 处理亲密互动重新开始请求
 */
class IntimacyHandler
{
    /**
     * 房间亲密互动状态
     * 格式: [room_id => ['active' => bool, 'start_time' => timestamp, 'user_ids' => array, 'room_id' => int]]
     */
    private static $roomIntimacyStates = [];

    /**
     * 检查房间亲密互动状态
     * 只有私密房间恰好2人在线时才启动互动
     * 
     * @param int $roomId 房间ID
     * @param array $localConnections 本地连接数组（引用传递）
     */
    public static function checkRoomIntimacy($roomId, &$localConnections)
    {
        // 检查是否为私密房间
        $room = Room::find($roomId);
        if (!$room || $room->private != 1) {
            // 非私密房间，停止互动
            if (isset(self::$roomIntimacyStates[$roomId])) {
                self::stopRoomIntimacy($roomId, $localConnections);
            }
            return;
        }

        // 计算房间在线用户数（按用户ID去重）
        $onlineUserIds = [];
        foreach ($localConnections as $conn) {
            if ($conn['room_id'] == $roomId && $conn['user_id']) {
                $onlineUserIds[$conn['user_id']] = true;
            }
        }
        $onlineCount = count($onlineUserIds);

        echo "[" . date('H:i:s') . "] 房间 {$roomId} 在线人数: {$onlineCount}\n";

        if ($onlineCount == 2) {
            // 恰好2人在线，启动互动（如果还未启动）
            if (!isset(self::$roomIntimacyStates[$roomId]) || !self::$roomIntimacyStates[$roomId]['active']) {
                self::startRoomIntimacy($roomId, array_keys($onlineUserIds), $localConnections);
            }
        } else {
            // 人数不是2人，停止并重置互动
            if (isset(self::$roomIntimacyStates[$roomId]) && self::$roomIntimacyStates[$roomId]['active']) {
                self::stopRoomIntimacy($roomId, $localConnections);
            }
        }
    }

    /**
     * 启动房间亲密互动
     * 
     * @param int $roomId 房间ID
     * @param array $userIds 用户ID数组
     * @param array $localConnections 本地连接数组（引用传递）
     */
    public static function startRoomIntimacy($roomId, $userIds, &$localConnections)
    {
        $startTime = time();
        self::$roomIntimacyStates[$roomId] = [
            'active' => true,
            'start_time' => $startTime,
            'user_ids' => $userIds,
            'room_id' => $roomId
        ];

        echo "[" . date('H:i:s') . "] 房间 {$roomId} 启动亲密互动\n";

        // 广播互动开始，前端收到后各自本地计时60秒
        self::broadcastToRoom($roomId, [
            'type' => 'intimacy_start',
            'room_id' => $roomId
        ], $localConnections);
    }

    /**
     * 停止房间亲密互动（重置）
     * 
     * @param int $roomId 房间ID
     * @param array $localConnections 本地连接数组（引用传递）
     */
    public static function stopRoomIntimacy($roomId, &$localConnections)
    {
        if (isset(self::$roomIntimacyStates[$roomId])) {
            $wasActive = self::$roomIntimacyStates[$roomId]['active'];
            unset(self::$roomIntimacyStates[$roomId]);

            if ($wasActive) {
                echo "[" . date('H:i:s') . "] 房间 {$roomId} 停止亲密互动\n";

                // 广播互动停止/重置
                self::broadcastToRoom($roomId, [
                    'type' => 'intimacy_reset',
                    'room_id' => $roomId
                ], $localConnections);
                echo "[" . date('H:i:s') . "] 房间 {$roomId} 已发送 intimacy_reset 消息\n";
            }
        }
    }

    /**
     * 处理亲密互动重新开始请求
     * 
     * @param object $connection WebSocket连接对象
     * @param array $msg 消息数据
     * @param array $localConnections 本地连接数组（引用传递）
     */
    public static function handleIntimacyRestart($connection, $msg, &$localConnections)
    {
        if (!isset($msg['room_id'])) {
            echo "[" . date('H:i:s') . "] intimacy_restart 消息缺少 room_id\n";
            return;
        }

        $roomId = $msg['room_id'];

        // 验证用户是否在该房间
        $connData = $localConnections[$connection->id] ?? null;
        if (!$connData || $connData['room_id'] != $roomId) {
            echo "[" . date('H:i:s') . "] 用户不在房间 {$roomId}\n";
            return;
        }

        echo "[" . date('H:i:s') . "] 房间 {$roomId} 请求重新开始亲密互动\n";

        // 先停止当前互动
        self::stopRoomIntimacy($roomId, $localConnections);

        // 检查房间是否仍有2人在线
        $roomUsers = [];
        foreach ($localConnections as $connId => $conn) {
            if ($conn['room_id'] == $roomId && $conn['user_id']) {
                $roomUsers[$conn['user_id']] = true;
            }
        }

        echo "[" . date('H:i:s') . "] 房间 {$roomId} 在线用户数: " . count($roomUsers) . "\n";

        // 如果恰好2人在线，立即重新开始
        if (count($roomUsers) === 2) {
            echo "[" . date('H:i:s') . "] 房间 {$roomId} 重新开始亲密互动\n";
            self::startRoomIntimacy($roomId, array_keys($roomUsers), $localConnections);
        } else {
            echo "[" . date('H:i:s') . "] 房间 {$roomId} 人数不足，无法重新开始\n";
        }
    }

    /**
     * 获取房间亲密互动状态
     * 
     * @param int $roomId 房间ID
     * @return array|null 返回状态数组或null
     */
    public static function getRoomIntimacyState($roomId)
    {
        return self::$roomIntimacyStates[$roomId] ?? null;
    }

    /**
     * 获取所有房间亲密互动状态
     * 
     * @return array 所有房间状态数组
     */
    public static function getAllRoomIntimacyStates()
    {
        return self::$roomIntimacyStates;
    }

    /**
     * 广播消息到指定房间
     * 
     * @param int $roomId 房间ID
     * @param array $data 要广播的数据
     * @param array $localConnections 本地连接数组
     */
    private static function broadcastToRoom($roomId, $data, &$localConnections)
    {
        $message = json_encode($data);
        foreach ($localConnections as $connId => $conn) {
            if ($conn['room_id'] == $roomId && isset($conn['connection'])) {
                $conn['connection']->send($message);
            }
        }
    }
}
