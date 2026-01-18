<?php

namespace app\controller;

use app\BaseController;
use app\model\User;
use app\service\JwtService;
use app\service\AccessService;
use app\service\LoginLogService;
use think\Request;
use think\Response;

/**
 * 认证控制器 - v2认证系统
 * 功能：JWT版本的登录、刷新、注销接口
 */
class Auth extends BaseController
{
    /**
     * 用户登录
     * @param Request $request
     * @return Response
     */
    public function login(Request $request)
    {
        $data = $request->param();

        // 业务逻辑验证
        if (empty($data['username'])) {
            return json(['code' => 1, 'msg' => '用户ID不能为空'], 400);
        }

        if (empty($data['password'])) {
            return json(['code' => 1, 'msg' => '密码不能为空'], 400);
        }

        // 根据ID和密码查询用户
        $userInfo = User::where('id', $data['username'])
            ->where('password', $data['password'])
            ->find();

        if (!$userInfo) {
            // 记录访问
            $this->logAccess($request, '登录失败：用户名或密码错误');
            return json(['code' => 1, 'msg' => '用户名或密码错误'], 401);
        }

        // 验证用户状态
        if ($userInfo['status'] != User::STATUS_NORMAL) {
            $this->logAccess($request, '登录失败：用户已被禁用');
            return json(['code' => 403, 'msg' => '用户已被禁用'], 403);
        }

        // 检查账号是否被封禁
        if (isset($userInfo['is_ban']) && $userInfo['is_ban'] == 1) {
            $this->logAccess($request, '登录失败：账号已被停用');
            return json(['code' => 403, 'msg' => '账号无限期停用'], 403);
        }

        // 获取IP地址
        $ip = $this->getRealIpForAuth($request);

        // 生成访问令牌（JWT）
        $accessToken = JwtService::generateAccessToken($userInfo['id'], [
            'username' => $userInfo->nick_name,
            'avatar' => $userInfo->avatar,
        ]);

        // 从访问令牌中提取jti
        $payload = JwtService::decodePayload($accessToken);
        $tokenId = $payload['jti'];

        // 生成刷新令牌
        $remember = !empty($data['remember']) && $data['remember'] == true;
        $userAgent = $request->header('user-agent', '');
        $refreshToken = JwtService::generateRefreshToken(
            $userInfo['id'],
            $tokenId,
            $ip,
            $userAgent,
            $remember
        );

        // 获取配置
        $config = config('jwt');

        // 记录访问日志
        $this->logAccess($request, '登录成功', $userInfo['id']);

        // 记录登录日志
        LoginLogService::log($ip, 1, '登录成功', $userInfo['id']);

        // 返回响应
        $response = json([
            'code' => 0,
            'msg' => '登录成功',
            'data' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $config['access_token_ttl'],
                'user' => [
                    'id' => $userInfo['id'],
                    'nick_name' => $userInfo['nick_name'],
                    'avatar' => $userInfo['avatar'],
                ],
            ],
        ]);

        // 设置刷新令牌到cookie（可选，安全起见推荐使用HttpOnly）
        $response->cookie('refresh_token_v2', $refreshToken, [
            'expire' => $remember ? $config['refresh_token_remember_ttl'] : $config['refresh_token_ttl'],
            'path' => '/',
            'httponly' => true,
            'secure' => false, // 生产环境HTTPS应设置为true
            'samesite' => 'Lax',
        ]);

        return $response;
    }

    /**
     * 刷新访问令牌
     * @param Request $request
     * @return Response
     */
    public function refresh(Request $request)
    {
        $config = config('jwt');

        // 获取刷新令牌
        $refreshToken = $request->param('refresh_token');

        // 如果没有传递，尝试从cookie获取
        if (empty($refreshToken)) {
            $refreshToken = $request->cookie('refresh_token_v2');
        }

        if (empty($refreshToken)) {
            return json(['code' => 400, 'msg' => '缺少刷新令牌'], 400);
        }

        // 获取IP地址和User-Agent
        $ip = $this->getRealIpForAuth($request);
        $userAgent = $request->header('user-agent', '');

        try {
            // 刷新访问令牌
            $newTokens = JwtService::refreshAccessToken($refreshToken, $ip, $userAgent);

            // 记录访问日志
            $this->logAccess($request, '刷新令牌成功');

            return json([
                'code' => 0,
                'msg' => '刷新成功',
                'data' => $newTokens,
            ]);

        } catch (\Exception $e) {
            // 记录访问日志
            $this->logAccess($request, '刷新令牌失败：' . $e->getMessage());

            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'IP地址不一致') !== false) {
                return json(['code' => 403, 'msg' => 'IP地址不一致，请重新登录'], 403);
            } elseif (strpos($errorMessage, '设备信息不一致') !== false) {
                return json(['code' => 403, 'msg' => '设备信息不一致，请重新登录'], 403);
            } elseif (strpos($errorMessage, '刷新令牌') !== false) {
                return json(['code' => 401, 'msg' => $errorMessage], 401);
            } else {
                return json(['code' => 500, 'msg' => '刷新失败：' . $errorMessage], 500);
            }
        }
    }

    /**
     * 注销（撤销当前令牌）
     * @param Request $request
     * @return Response
     */
    public function logout(Request $request)
    {
        $tokenId = $request->tokenId;

        if (empty($tokenId)) {
            return json(['code' => 400, 'msg' => '无效的令牌'], 400);
        }

        // 撤销访问令牌（加入黑名单）
        $revoked = JwtService::revokeToken($tokenId);

        // 清除cookie
        $response = json([
            'code' => 0,
            'msg' => '注销成功',
        ]);

        $response->cookie('refresh_token_v2', '', time() - 3600, '/');

        // 记录访问日志
        $userId = $request->userId;
        $this->logAccess($request, '注销成功', $userId);

        return $response;
    }

    /**
     * 注销所有设备
     * @param Request $request
     * @return Response
     */
    public function logoutAll(Request $request)
    {
        $userId = $request->userId;

        if (empty($userId)) {
            return json(['code' => 400, 'msg' => '无效的用户信息'], 400);
        }

        // 撤销用户所有令牌
        $revokedCount = JwtService::revokeAllUserTokens($userId);

        // 清除cookie
        $response = json([
            'code' => 0,
            'msg' => '注销成功',
            'data' => [
                'revoked_count' => $revokedCount,
            ],
        ]);

        $response->cookie('refresh_token_v2', '', time() - 3600, '/');

        // 记录访问日志
        $this->logAccess($request, '注销所有设备', $userId);

        return $response;
    }

    /**
     * 获取当前用户信息
     * @param Request $request
     * @return Response
     */
    public function me(Request $request)
    {
        $userId = $request->userId;

        if (empty($userId)) {
            return json(['code' => 401, 'msg' => '未授权'], 401);
        }

        // 查询用户信息
        $user = User::field('id, nick_name, avatar, sign, create_time')->find($userId);

        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在'], 404);
        }

        return json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => [
                'id' => $user->id,
                'nick_name' => $user->nick_name,
                'avatar' => $user->avatar,
                'sign' => $user->sign,
                'created_at' => $user->create_time,
            ],
        ]);
    }

    /**
     * 获取客户端真实IP（用于认证）
     * @param Request $request
     * @return string
     */
    private function getRealIpForAuth(Request $request): string
    {
        // 优先使用前端传递的IP
        $ip = $request->param('client_ip', '');

        if (empty($ip)) {
            $ip = parent::getRealIp();
        }

        return $ip ?: '0.0.0.0';
    }

    /**
     * 记录访问日志
     * @param Request $request
     * @param string $remark 备注
     * @param int|null $userId 用户ID
     */
    private function logAccess(Request $request, string $remark, ?int $userId = null): void
    {
        try {
            $ip = $this->getRealIp($request);
            $userAgent = $request->header('user-agent', '');

            AccessService::logAccess($ip, $remark, $userAgent, $userAgent);
        } catch (\Exception $e) {
            // 记录日志失败不影响主流程
        }
    }
}
