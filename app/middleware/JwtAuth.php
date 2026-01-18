<?php

namespace app\middleware;

use app\service\JwtService;
use think\Request;
use think\Response;

/**
 * JWT认证中间件 - v2认证系统
 * 功能：验证JWT访问令牌，提取用户信息附加到请求
 */
class JwtAuth
{
    /**
     * 公开路由（无需认证的路由列表）
     */
    private static $skipPaths = [
        'api/v2/auth/login',
    ];

    /**
     * 处理请求
     * @param Request $request
     * @param \Closure $next
     * @return Response
     */
    public function handle(Request $request, \Closure $next)
    {
        // 获取请求路径
        $path = $request->pathinfo();

        // 检查是否跳过认证
        if ($this->shouldSkipAuth($path)) {
            return $next($request);
        }

        // 提取访问令牌
        $token = $this->extractToken($request);

        if (empty($token)) {
            return $this->errorResponse('缺少认证令牌', 'token_missing');
        }

        // 验证访问令牌
        $payload = JwtService::verifyAccessToken($token);

        if ($payload === false) {
            return $this->errorResponse('令牌无效或已过期', 'token_invalid');
        }

        // 附加用户信息到请求对象
        $request->userId = $payload['uid'] ?? null;
        $request->tokenId = $payload['jti'] ?? null;
        $request->jwtPayload = $payload;

        // 继续处理请求
        return $next($request);
    }

    /**
     * 检查是否跳过认证
     * @param string $path 请求路径
     * @return bool
     */
    private function shouldSkipAuth(string $path): bool
    {
        // 检查是否在跳过列表中
        if (in_array($path, self::$skipPaths)) {
            return true;
        }

        // 检查是否以跳过路径开头
        foreach (self::$skipPaths as $skipPath) {
            if (strpos($path, $skipPath) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 从请求中提取访问令牌
     * @param Request $request
     * @return string|null
     */
    private function extractToken(Request $request): ?string
    {
        // 优先从Authorization header获取
        $authorization = $request->header('Authorization');
        if (!empty($authorization)) {
            // 检查是否为Bearer token
            if (strpos($authorization, 'Bearer ') === 0) {
                return substr($authorization, 7);
            }
            // 兼容不带Bearer前缀的情况
            return $authorization;
        }

        // 尝试从其他header获取
        $token = $request->header('X-Access-Token');
        if (!empty($token)) {
            return $token;
        }

        // 尝试从query参数获取（不推荐，但兼容某些场景）
        $token = $request->param('access_token');
        if (!empty($token)) {
            return $token;
        }

        return null;
    }

    /**
     * 返回错误响应
     * @param string $message 错误消息
     * @param string $code 错误代码
     * @return Response
     */
    private function errorResponse(string $message, string $code = 'auth_error'): Response
    {
        $config = config('jwt');

        // 检查是否在调试模式
        if ($config['debug_errors'] ?? false) {
            return json([
                'code' => 401,
                'msg' => $message,
                'error_code' => $code,
                'data' => null,
            ], 401);
        }

        return json([
            'code' => 401,
            'msg' => $message,
            'data' => null,
        ], 401);
    }

    /**
     * 添加公开路由（允许在运行时动态添加）
     * @param string|array $paths 路径或路径数组
     */
    public static function addSkipPath($paths): void
    {
        if (is_array($paths)) {
            self::$skipPaths = array_merge(self::$skipPaths, $paths);
        } else {
            self::$skipPaths[] = $paths;
        }
    }

    /**
     * 获取所有公开路由
     * @return array
     */
    public static function getSkipPaths(): array
    {
        return self::$skipPaths;
    }
}
