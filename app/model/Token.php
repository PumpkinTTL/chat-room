<?php

namespace app\model;

use think\Model;

/**
 * Token模型 - 继承think\Model
 */
class Token extends Model
{
    // 设置表名
    protected $table = 'ch_tokens';

    // 设置字段信息
    protected $schema = [
        'id'          => 'varchar',
        'user_id'     => 'int',
        'status'      => 'int',
        'expire_time' => 'datetime',
        'create_time' => 'datetime',
    ];

    // 类型常量
    public const STATUS_ACTIVE = 1;    // 有效
    public const STATUS_EXPIRED = 0;   // 过期
}
