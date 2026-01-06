<?php

namespace app\model;

use think\Model;

/**
 * 用户模型 - 继承think\Model
 */
class User extends Model
{
    // 设置表名
    protected $table = 'ch_users';

    // 设置字段信息
    protected $schema = [
        'id'          => 'int',
        'nick_name'   => 'string',
        'password'    => 'string',
        'avatar'      => 'string',
        'sign'        => 'string',
        'status'      => 'int',
        'is_ban'      => 'int',
        'create_time' => 'datetime',
    ];

    // 类型常量
    public const STATUS_NORMAL = 1;  // 正常
    public const STATUS_DISABLED = 0; // 禁用
}
