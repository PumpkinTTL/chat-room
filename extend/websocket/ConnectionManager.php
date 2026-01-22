<?php

namespace extend\websocket;

/**
 * WebSocket 连接管理器
 * 负责连接的存储、查询和清理
 */
class ConnectionManager
{
    /**
     * 本地连接映射
     * 格式: [connection_id => ['user_id' => xx, 'room_id' => xx, 'nickname' => xx, ...]]
     */
    private $connections = [];
    
    /**
     * 添加连接
     * 
     * @param int $connectionId 连接ID
     * @param array $data 连接数据
     */
    public function add($connectionId, array $data)
    {
        $this->connections[$connectionId] = $data;
    }
    
    /**
     * 移除连接
     * 
     * @param int $connectionId 连接ID
     * @return array|null 被移除的连接数据
     */
    public function remove($connectionId)
    {
        if (!isset($this->connections[$connectionId])) {
            return null;
        }
        
        $data = $this->connections[$connectionId];
        unset($this->connections[$connectionId]);
        return $data;
    }
    
    /**
     * 获取连接数据
     * 
     * @param int $connectionId 连接ID
     * @return array|null
     */
    public function get($connectionId)
    {
        return $this->connections[$connectionId] ?? null;
    }
    
    /**
     * 检查连接是否存在
     * 
     * @param int $connectionId 连接ID
     * @return bool
     */
    public function has($connectionId)
    {
        return isset($this->connections[$connectionId]);
    }
    
    /**
     * 更新连接数据
     * 
     * @param int $connectionId 连接ID
     * @param array $data 要更新的数据
     */
    public function update($connectionId, array $data)
    {
        if (isset($this->connections[$connectionId])) {
            $this->connections[$connectionId] = array_merge(
                $this->connections[$connectionId],
                $data
            );
        }
    }
    
    /**
     * 获取房间内的所有连接
     * 
     * @param int $roomId 房间ID
     * @return array [connection_id => connection_data]
     */
    public function getByRoom($roomId)
    {
        return array_filter($this->connections, function($conn) use ($roomId) {
            return isset($conn['room_id']) && $conn['room_id'] == $roomId;
        });
    }
    
    /**
     * 获取用户的所有连接
     * 
     * @param int $userId 用户ID
     * @return array [connection_id => connection_data]
     */
    public function getByUser($userId)
    {
        return array_filter($this->connections, function($conn) use ($userId) {
            return isset($conn['user_id']) && $conn['user_id'] == $userId;
        });
    }
    
    /**
     * 获取房间内的用户ID列表（去重）
     * 
     * @param int $roomId 房间ID
     * @return array [user_id, ...]
     */
    public function getRoomUserIds($roomId)
    {
        $userIds = [];
        foreach ($this->connections as $conn) {
            if (isset($conn['room_id']) && $conn['room_id'] == $roomId && isset($conn['user_id'])) {
                $userIds[$conn['user_id']] = true;
            }
        }
        return array_keys($userIds);
    }
    
    /**
     * 获取房间内的在线人数
     * 
     * @param int $roomId 房间ID
     * @return int
     */
    public function getRoomOnlineCount($roomId)
    {
        return count($this->getRoomUserIds($roomId));
    }
    
    /**
     * 获取所有连接
     * 
     * @return array
     */
    public function getAll()
    {
        return $this->connections;
    }
    
    /**
     * 清空所有连接
     */
    public function clear()
    {
        $this->connections = [];
    }
    
    /**
     * 获取连接总数
     * 
     * @return int
     */
    public function count()
    {
        return count($this->connections);
    }
}
