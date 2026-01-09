<?php

namespace app\model;

use think\Model;

/**
 * 房间模型 - 继承think\Model
 */
class Room extends Model
{
    // 设置表名
    protected $table = 'ch_rooms';

    // 设置字段信息
    protected $schema = [
        'id'          => 'int',
        'name'        => 'string',
        'description' => 'string',
        'owner_id'    => 'int',
        'private'     => 'int',
        'status'      => 'int',
        'create_time' => 'datetime',
    ];

    // 类型常量
    public const STATUS_NORMAL = 1;    // 正常
    public const STATUS_DISABLED = 0;  // 禁用
}
