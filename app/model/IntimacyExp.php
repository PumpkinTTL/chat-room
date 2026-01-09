<?php

namespace app\model;

use think\Model;

class IntimacyExp extends Model
{
    protected $name = 'intimacy_exp';
    
    // 设置字段信息
    protected $schema = [
        'id'                => 'int',
        'room_id'           => 'int',
        'user_id'           => 'int',
        'partner_id'        => 'int',
        'current_exp'       => 'int',
        'current_level'     => 'int',
        'total_messages'    => 'int',
        'last_message_time' => 'datetime',
        'create_time'       => 'datetime',
        'update_time'       => 'datetime',
    ];
    
    // 关联用户
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    // 关联伴侣
    public function partner()
    {
        return $this->belongsTo(User::class, 'partner_id');
    }
    
    // 关联房间
    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
    
    // 关联等级
    public function level()
    {
        return $this->belongsTo(IntimacyLevel::class, 'current_level', 'level');
    }
}