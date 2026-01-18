<?php

namespace app\model;

use think\Model;

/**
 * 刷新令牌模型 - v2认证系统
 * 表：ch_refresh_tokens
 */
class RefreshToken extends Model
{
    // 设置表名
    protected $table = 'ch_refresh_tokens';

    // 设置字段信息
    protected $schema = [
        'id' => 'int',
        'user_id' => 'int',
        'refresh_token' => 'string',
        'access_token_id' => 'string',
        'ip' => 'string',
        'user_agent' => 'string',
        'status' => 'int',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    // 自动时间戳
    protected $autoWriteTimestamp = false; // 数据库已有自动设置created_at

    // 状态常量
    const STATUS_ACTIVE = 1;    // 有效
    const STATUS_REVOKED = 0;   // 已撤销

    /**
     * 类型转换
     */
    protected $type = [
        'user_id' => 'integer',
        'status' => 'integer',
    ];

    /**
     * 查询作用域：仅查询有效的刷新令牌
     * @param $query
     * @return mixed
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * 查询作用域：按用户ID查询
     * @param $query
     * @param int $userId
     * @return mixed
     */
    public function scopeByUserId($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * 查询作用域：按刷新令牌查询
     * @param $query
     * @param string $token
     * @return mixed
     */
    public function scopeByRefreshToken($query, string $token)
    {
        return $query->where('refresh_token', hash('sha256', $token));
    }

    /**
     * 查询作用域：按关联的访问令牌ID查询
     * @param $query
     * @param string $tokenId
     * @return mixed
     */
    public function scopeByAccessTokenId($query, string $tokenId)
    {
        return $query->where('access_token_id', $tokenId);
    }

    /**
     * 查询作用域：查询已过期的令牌
     * @param $query
     * @return mixed
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', date('Y-m-d H:i:s'));
    }

    /**
     * 查询作用域：按IP地址查询
     * @param $query
     * @param string $ip
     * @return mixed
     */
    public function scopeByIp($query, string $ip)
    {
        return $query->where('ip', $ip);
    }

    /**
     * 关联用户模型
     * @return \think\model\relation\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 检查令牌是否有效
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && strtotime($this->expires_at) > time();
    }

    /**
     * 检查令牌是否已过期
     * @return bool
     */
    public function isExpired(): bool
    {
        return strtotime($this->expires_at) < time();
    }

    /**
     * 撤销令牌
     * @return bool
     */
    public function revoke(): bool
    {
        $this->status = self::STATUS_REVOKED;
        return $this->save();
    }

    /**
     * 格式化创建时间
     * @param $value
     * @return string
     */
    public function getCreatedAtAttr($value): string
    {
        return $value ? date('Y-m-d H:i:s', strtotime($value)) : '';
    }

    /**
     * 格式化过期时间
     * @param $value
     * @return string
     */
    public function getExpiresAtAttr($value): string
    {
        return $value ? date('Y-m-d H:i:s', strtotime($value)) : '';
    }

    /**
     * 获取状态文本
     * @param $value
     * @return string
     */
    public function getStatusTextAttr($value): string
    {
        $statusMap = [
            self::STATUS_ACTIVE => '有效',
            self::STATUS_REVOKED => '已撤销',
        ];
        return $statusMap[$value] ?? '未知';
    }
}
