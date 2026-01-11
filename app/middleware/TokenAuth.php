<?php

namespace app\middleware;

use app\service\TokenService;
use think\Request;
use think\Response;

/**
 * Token验证中间件
 */
class TokenAuth
{
    /**
     * 处理请求
     * @param Request $request
     * @param \Closure $next
     * @param array $options 配置选项
     * @return Response
     */
    public function handle(Request $request, \Closure $next, $options = [])
    {
        // 跳过登录接口、登录页面、首页、聊天页面和访问记录接口
        $path = $request->pathinfo();
        $skipPaths = ['api/user/login', 'api/logAccess', 'login', '', 'index', 'chat'];
        
        // 检查是否需要跳过
        if (in_array($path, $skipPaths) || strpos($path, 'login') === 0) {
            return $next($request);
        }
        
        // 跳过静态文件路由
        if (strpos($path, 'storage/') === 0) {
            return $next($request);
        }

        // 获取token（优先从header，兼容从cookie）
        $token = $request->header('Authorization');

        // 移除Bearer前缀
        if ($token && strpos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }

        // 如果header中没有token，则从cookie获取
        if (!$token) {
            $token = $request->cookie('token');
        }

        // 验证token
        if (!$token) {
            return json([
                'code' => 401,
                'msg' => '缺少token',
                'data' => null,
            ])->code(401);
        }

        $userInfo = TokenService::verifyToken($token);
        if (!$userInfo) {
            return json([
                'code' => 401,
                'msg' => 'token无效或已过期',
                'data' => null,
            ])->code(401);
        }

        // 将用户信息附加到request
        $request->userId = $userInfo['user_id'];
        $request->token = $token;

        // 继续处理请求
        return $next($request);
    }
}
