<?php

namespace app\model;

use think\Model;

class IntimacyLevel extends Model
{
    protected $name = 'intimacy_levels';
    
    // 设置字段信息
    protected $schema = [
        'id'           => 'int',
        'level'        => 'int',
        'name'         => 'string',
        'required_exp' => 'int',
        'color'        => 'string',
        'icon'         => 'string',
        'description'  => 'string',
        'create_time'  => 'datetime',
    ];
}