<?php

namespace app\controller;

use app\BaseController;
use app\service\UserService;
use app\service\AccessService;
use app\service\LoginLogService;
use think\Request;
use think\Response;

/**
 * 用户控制器
 */
class User extends BaseController
{
    /**
     * 获取用户列表
     * @param Request $request
     * @return Response
     */
    public function list(Request $request)
    {
        $params = $request->param();
        $result = UserService::getUserList($params);

        return json([
            'code' => 0,
            'msg'  => '获取成功',
            'data' => $result,
        ]);
    }

    /**
     * 获取用户详情
     * @param int $id 用户ID
     * @return Response
     */
    public function info($id)
    {
        $result = UserService::getUserInfo($id);

        if ($result['code'] === 0) {
            return json($result);
        }

        return json($result, 400);
    }

    /**
     * 创建用户
     * @param Request $request
     * @return Response
     */
    public function create(Request $request)
    {
        $data = $request->param();
        $result = UserService::createUser($data);

        if ($result['code'] === 0) {
            return json($result);
        }

        return json($result, 400);
    }

    /**
     * 更新用户
     * @param int $id 用户ID
     * @param Request $request
     * @return Response
     */
    public function update($id, Request $request)
    {
        $data = $request->param();
        $result = UserService::updateUser($id, $data);

        if ($result['code'] === 0) {
            return json($result);
        }

        return json($result, 400);
    }

    /**
     * 删除用户
     * @param int $id 用户ID
     * @return Response
     */
    public function delete($id)
    {
        $result = UserService::deleteUser($id);

        if ($result['code'] === 0) {
            return json($result);
        }

        return json($result, 400);
    }

    /**
     * 用户登录
     * @param Request $request
     * @return Response
     */
    public function login(Request $request)
    {
        $data = $request->param();

        // IP获取策略：优先使用前端传递的IP，否则使用后端获取
        if (!empty($data['client_ip'])) {
            $data['ip'] = $data['client_ip'];
        } else {
            // 后端备用方案
            $data['ip'] = $this->getRealIp();
        }

        // 获取User-Agent用于识别平台
        $userAgent = $request->header('user-agent', '');

        $result = UserService::login($data);

        // 记录访问（无论登录成功与否）
        $remark = $result['code'] === 0 ? '登录成功' : '登录失败';
        AccessService::logAccess($data['ip'], $remark, $userAgent);

        // 记录登录日志
        $loginStatus = $result['code'] === 0 ? 1 : 0;
        $loginRemark = $result['code'] === 0 ? '登录成功' : ($result['msg'] ?? '登录失败');
        $userId = $result['code'] === 0 ? $result['data']['id'] : null;
        LoginLogService::log($data['ip'], $loginStatus, $loginRemark, $userId);

        // 如果登录成功，设置cookie
        if ($result['code'] === 0 && isset($result['token'])) {
            // 设置cookie，24小时过期
            $response = json($result);
            $response->cookie('token', $result['token'], [
                'expire' => 24 * 3600,
                'path' => '/',
                'httponly' => true, // 防止XSS攻击
                'secure' => false, // HTTPS环境设置为true
                'samesite' => 'Lax', // 防止CSRF攻击
            ]);

            // 记录日志
            trace('登录成功，设置cookie: ' . $result['token'], 'info');

            return $response;
        }

        return json($result);
    }
}
