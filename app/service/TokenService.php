<?php

namespace app\service;

use app\model\Token;

/**
 * Token服务层 - 静态方法
 */
class TokenService
{
    /**
     * 生成并保存token
     * @param int $userId 用户ID
     * @param int $expireHours 过期小时数，默认24小时
     * @param string $ip 用户IP地址
     * @return string token字符串
     */
    public static function generateToken($userId, $expireHours = 24, $ip = '')
    {
        // 生成随机token
        $token = bin2hex(random_bytes(32));

        // 计算过期时间
        $expireTime = date('Y-m-d H:i:s', time() + $expireHours * 3600);

        // 使用模型插入，把token作为id
        Token::create([
            'id' => $token,
            'user_id' => $userId,
            'status' => Token::STATUS_ACTIVE,
            'ip' => $ip,
            'expire_time' => $expireTime,
        ]);

        return $token;
    }

    /**
     * 验证token
     * @param string $token token字符串
     * @return array|false 返回用户信息或false
     */
    public static function verifyToken($token)
    {
        // 使用模型查询token（id就是token）
        $tokenInfo = Token::find($token);

        if (!$tokenInfo || $tokenInfo['status'] != Token::STATUS_ACTIVE) {
            return false;
        }

        // 检查是否过期
        if (strtotime($tokenInfo['expire_time']) < time()) {
            // 标记为过期
            $tokenInfo->status = Token::STATUS_EXPIRED;
            $tokenInfo->save();
            return false;
        }

        return [
            'user_id' => $tokenInfo['user_id'],
            'token' => $token,
        ];
    }

    /**
     * 注销token
     * @param string $token token字符串
     * @return bool
     */
    public static function logout($token)
    {
        // 使用模型注销token
        $tokenInfo = Token::find($token);
        if ($tokenInfo) {
            $tokenInfo->status = Token::STATUS_EXPIRED;
            return $tokenInfo->save();
        }
        return false;
    }

    /**
     * 清理过期token
     * @return int 清理数量
     */
    public static function cleanExpiredTokens()
    {
        return Token::where('expire_time', '<', date('Y-m-d H:i:s'))
            ->where('status', Token::STATUS_ACTIVE)
            ->update(['status' => Token::STATUS_EXPIRED]);
    }
}
