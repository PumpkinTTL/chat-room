<?php

namespace app\model;

use think\Model;

/**
 * 房间成员关系模型 - 继承think\Model
 */
class RoomUser extends Model
{
    // 设置表名
    protected $table = 'ch_room_users';

    // 设置字段信息
    protected $schema = [
        'id'        => 'int',
        'room_id'   => 'int',
        'user_id'   => 'int',
        'status'    => 'int',
        'join_time' => 'datetime',
    ];

    // 类型常量
    public const STATUS_IN_ROOM = 1;   // 在房间内
    public const STATUS_LEFT = 0;      // 已离开

    /**
     * 关联用户模型
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联房间模型
     */
    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }
}