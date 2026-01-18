<?php

namespace app\service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use app\model\RefreshToken;
use app\model\User;
use think\facade\Cache;

/**
 * JWT服务层 - v2认证系统
 * 功能：JWT令牌生成、验证、刷新、撤销
 */
class JwtService
{
    /**
     * 生成访问令牌（JWT）
     * @param int $userId 用户ID
     * @param array $extraPayload 额外的载荷数据
     * @return string JWT令牌字符串
     */
    public static function generateAccessToken(int $userId, array $extraPayload = []): string
    {
        $config = config('jwt');

        // 生成唯一的jti（令牌ID）
        $tokenId = self::generateTokenId();

        // 基础载荷
        $now = time();
        $payload = [
            'iss' => $config['issuer'],              // 签发者
            'aud' => $config['audience'],           // 受众
            'iat' => $now,                         // 签发时间
            'exp' => $now + $config['access_token_ttl'], // 过期时间
            'jti' => $tokenId,                     // 唯一标识符
            'sub' => (string)$userId,              // 主题
            'uid' => $userId,                      // 用户ID（便捷字段）
            'type' => $config['token_type_access'], // 令牌类型
        ];

        // 添加额外载荷
        if (!empty($extraPayload)) {
            $payload = array_merge($payload, $extraPayload);
        }

        // 如果配置了包含用户详细信息
        if ($config['include_user_details']) {
            $user = User::field('id, nick_name, avatar, sign, status, is_ban')->find($userId);
            if ($user) {
                $payload['username'] = $user->nick_name;
                $payload['avatar'] = $user->avatar;
                $payload['sign'] = $user->sign;
            }
        }

        // 根据算法选择签名密钥
        $algorithm = $config['algorithm'];
        if ($algorithm === 'RS256') {
            // 非对称加密（RSA）
            $keyMaterial = self::getPrivateKey();
        } else {
            // 对称加密（HMAC）
            $keyMaterial = $config['secret'];
        }

        // 生成JWT
        return JWT::encode($payload, $keyMaterial, $algorithm);
    }

    /**
     * 生成刷新令牌
     * @param int $userId 用户ID
     * @param string $accessTokenId 访问令牌的jti
     * @param string $ip IP地址
     * @param string $userAgent User-Agent
     * @param bool $remember 是否记住我
     * @return string 刷新令牌（hash值）
     */
    public static function generateRefreshToken(
        int $userId,
        string $accessTokenId,
        string $ip = '',
        string $userAgent = '',
        bool $remember = false
    ): string {
        $config = config('jwt');

        // 检查是否启用刷新令牌
        if (!$config['enable_refresh_token']) {
            throw new \Exception('刷新令牌功能未启用');
        }

        // 生成随机刷新令牌
        $refreshToken = bin2hex(random_bytes(32));

        // 计算过期时间
        $ttl = $remember ? $config['refresh_token_remember_ttl'] : $config['refresh_token_ttl'];
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        // 检查最大并发数限制
        self::enforceMaxRefreshTokens($userId);

        // 存储刷新令牌到数据库
        RefreshToken::create([
            'user_id' => $userId,
            'refresh_token' => self::hashRefreshToken($refreshToken),
            'access_token_id' => $accessTokenId,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'status' => RefreshToken::STATUS_ACTIVE,
            'expires_at' => $expiresAt,
        ]);

        return $refreshToken;
    }

    /**
     * 验证访问令牌
     * @param string $token JWT令牌字符串
     * @return array|false 解码后的载荷数组，验证失败返回false
     */
    public static function verifyAccessToken(string $token): array|false
    {
        $config = config('jwt');

        try {
            // 根据算法选择验证密钥
            $algorithm = $config['algorithm'];
            if ($algorithm === 'RS256') {
                // 非对称加密（RSA）
                $key = new Key(self::getPublicKey(), $algorithm);
            } else {
                // 对称加密（HMAC）
                $key = new Key($config['secret'], $algorithm);
            }

            // 解码JWT
            $payload = JWT::decode($token, $key);

            // 检查令牌类型
            if (!isset($payload->type) || $payload->type !== $config['token_type_access']) {
                return false;
            }

            // 检查是否在黑名单中
            if ($config['enable_blacklist'] && self::isBlacklisted($payload->jti)) {
                return false;
            }

            // 转换为数组
            return (array)$payload;

        } catch (\Firebase\JWT\ExpiredException $e) {
            // 令牌过期
            return false;
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            // 签名无效
            return false;
        } catch (\Exception $e) {
            // 其他错误
            return false;
        }
    }

    /**
     * 刷新访问令牌
     * @param string $refreshToken 刷新令牌
     * @param string $ip 当前IP地址
     * @param string $userAgent 当前User-Agent
     * @return array 新的令牌对
     * @throws \Exception
     */
    public static function refreshAccessToken(string $refreshToken, string $ip = '', string $userAgent = ''): array
    {
        $config = config('jwt');

        // 查找刷新令牌
        $refreshTokenModel = RefreshToken::scopeByRefreshToken($refreshToken)
            ->scopeActive()
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->find();

        if (!$refreshTokenModel) {
            throw new \Exception($config['error_messages']['refresh_token_invalid'] ?? '刷新令牌无效');
        }

        // 检查令牌是否已过期
        if (strtotime($refreshTokenModel->expires_at) < time()) {
            throw new \Exception($config['error_messages']['refresh_token_expired'] ?? '刷新令牌已过期');
        }

        // 验证IP一致性（如果启用）
        if ($config['verify_ip'] && !empty($refreshTokenModel->ip) && $refreshTokenModel->ip !== $ip) {
            throw new \Exception('IP地址不一致');
        }

        // 验证User-Agent一致性（如果启用）
        if ($config['verify_user_agent'] && !empty($refreshTokenModel->user_agent) && $refreshTokenModel->user_agent !== $userAgent) {
            throw new \Exception('设备信息不一致');
        }

        // 撤销旧的访问令牌（加入黑名单）
        if ($config['enable_blacklist'] && !empty($refreshTokenModel->access_token_id)) {
            self::revokeToken($refreshTokenModel->access_token_id);
        }

        // 生成新的访问令牌
        $newAccessToken = self::generateAccessToken($refreshTokenModel->user_id);
        $newPayload = self::decodePayload($newAccessToken);

        // 生成新的刷新令牌
        $newRefreshToken = self::generateRefreshToken(
            $refreshTokenModel->user_id,
            $newPayload['jti'],
            $ip,
            $userAgent
        );

        // 撤销旧的刷新令牌
        $refreshTokenModel->status = RefreshToken::STATUS_REVOKED;
        $refreshTokenModel->save();

        return [
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $config['access_token_ttl'],
        ];
    }

    /**
     * 撤销令牌（加入黑名单）
     * @param string $tokenId 令牌ID（jti）
     * @return bool
     */
    public static function revokeToken(string $tokenId): bool
    {
        $config = config('jwt');

        if (!$config['enable_blacklist']) {
            return false;
        }

        try {
            // 获取缓存实例
            $cache = self::getBlacklistCache();

            // 将令牌ID加入黑名单
            $cacheKey = $config['blacklist_prefix'] . $tokenId;
            $cache->set($cacheKey, 1, $config['blacklist_ttl']);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 撤销用户所有令牌
     * @param int $userId 用户ID
     * @return int 撤销的令牌数量
     */
    public static function revokeAllUserTokens(int $userId): int
    {
        $config = config('jwt');

        // 查找用户所有有效的刷新令牌
        $refreshTokens = RefreshToken::scopeByUserId($userId)
            ->scopeActive()
            ->select();

        $revokedCount = 0;

        foreach ($refreshTokens as $refreshToken) {
            // 撤销关联的访问令牌
            if ($config['enable_blacklist'] && !empty($refreshToken->access_token_id)) {
                self::revokeToken($refreshToken->access_token_id);
            }

            // 撤销刷新令牌
            $refreshToken->status = RefreshToken::STATUS_REVOKED;
            $refreshToken->save();

            $revokedCount++;
        }

        return $revokedCount;
    }

    /**
     * 检查令牌是否在黑名单中
     * @param string $tokenId 令牌ID（jti）
     * @return bool
     */
    public static function isBlacklisted(string $tokenId): bool
    {
        $config = config('jwt');

        if (!$config['enable_blacklist']) {
            return false;
        }

        try {
            // 获取缓存实例
            $cache = self::getBlacklistCache();

            // 检查黑名单
            $cacheKey = $config['blacklist_prefix'] . $tokenId;
            return $cache->has($cacheKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 清理过期的刷新令牌
     * @return int 清理的数量
     */
    public static function cleanExpiredRefreshTokens(): int
    {
        return RefreshToken::scopeExpired()
            ->where('status', RefreshToken::STATUS_ACTIVE)
            ->update(['status' => RefreshToken::STATUS_REVOKED]);
    }

    /**
     * 解码JWT载荷（不验证签名，仅用于提取jti等）
     * @param string $token JWT令牌
     * @return array 载荷数组
     */
    public static function decodePayload(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }

        // 解码payload（Base64URL解码）
        $payload = base64_decode(str_replace(['-', '_', ''], ['+', '/', '='], $parts[1]));
        return json_decode($payload, true) ?? [];
    }

    /**
     * 从访问令牌中提取用户ID
     * @param string $token JWT令牌
     * @return int|false
     */
    public static function getUserIdFromToken(string $token): int|false
    {
        $payload = self::decodePayload($token);
        return $payload['uid'] ?? false;
    }

    /**
     * 从访问令牌中提取jti
     * @param string $token JWT令牌
     * @return string|false
     */
    public static function getTokenIdFromToken(string $token): string|false
    {
        $payload = self::decodePayload($token);
        return $payload['jti'] ?? false;
    }

    /**
     * 生成唯一的令牌ID（jti）
     * @return string
     */
    private static function generateTokenId(): string
    {
        return md5(uniqid('jwt_', true) . random_bytes(16) . microtime(true));
    }

    /**
     * 对刷新令牌进行hash
     * @param string $token 原始令牌
     * @return string hash值
     */
    private static function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 强制执行最大并发刷新令牌数限制
     * @param int $userId 用户ID
     */
    private static function enforceMaxRefreshTokens(int $userId): void
    {
        $config = config('jwt');
        $maxTokens = $config['max_refresh_tokens'];

        // 查询用户当前有效的刷新令牌数量
        $count = RefreshToken::scopeByUserId($userId)
            ->scopeActive()
            ->count();

        if ($count >= $maxTokens) {
            // 撤销最早的令牌
            $oldestToken = RefreshToken::scopeByUserId($userId)
                ->scopeActive()
                ->order('created_at', 'asc')
                ->find();

            if ($oldestToken) {
                if ($config['enable_blacklist'] && !empty($oldestToken->access_token_id)) {
                    self::revokeToken($oldestToken->access_token_id);
                }
                $oldestToken->status = RefreshToken::STATUS_REVOKED;
                $oldestToken->save();
            }
        }
    }

    /**
     * 获取RSA私钥（用于RS256算法）
     * @return string
     */
    private static function getPrivateKey(): string
    {
        $config = config('jwt');
        $key = $config['keys']['private'];

        // 如果是文件路径，读取文件内容
        if (file_exists($key)) {
            return file_get_contents($key);
        }

        return $key;
    }

    /**
     * 获取RSA公钥（用于RS256算法）
     * @return string
     */
    private static function getPublicKey(): string
    {
        $config = config('jwt');
        $key = $config['keys']['public'];

        // 如果是文件路径，读取文件内容
        if (file_exists($key)) {
            return file_get_contents($key);
        }

        return $key;
    }

    /**
     * 获取黑名单缓存实例
     * @return \think\Cache
     */
    private static function getBlacklistCache()
    {
        $config = config('jwt');

        // 如果配置了特定的缓存驱动
        if (!empty($config['blacklist_cache'])) {
            return Cache::store($config['blacklist_cache']);
        }

        // 使用默认缓存驱动
        return Cache::store();
    }
}
