<?php

namespace app\model;

use think\Model;

/**
 * 登录日志模型
 */
class LoginLog extends Model
{
    // 设置表名
    protected $table = 'ch_login_log';

    // 自动写入时间戳
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = false;

    // 允许写入的字段
    protected $field = ['id', 'user_id', 'ip', 'status', 'remark', 'create_time'];

    // 状态常量
    public const STATUS_SUCCESS = 1;  // 登录成功
    public const STATUS_FAIL = 0;     // 登录失败

    /**
     * 获取状态文本
     */
    public function getStatusTextAttr($value, $data)
    {
        $status = [
            self::STATUS_FAIL => '失败',
            self::STATUS_SUCCESS => '成功',
        ];
        return $status[$data['status']] ?? '未知';
    }

    /**
     * 查询成功的登录记录
     */
    public function scopeSuccess($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * 查询失败的登录记录
     */
    public function scopeFail($query)
    {
        return $query->where('status', self::STATUS_FAIL);
    }

    /**
     * 按用户ID查询
     */
    public function scopeByUserId($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * 按IP查询
     */
    public function scopeByIp($query, $ip)
    {
        return $query->where('ip', $ip);
    }
}
