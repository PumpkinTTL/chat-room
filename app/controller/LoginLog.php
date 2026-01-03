<?php

namespace app\controller;

use app\BaseController;
use app\service\LoginLogService;
use think\Request;

/**
 * 登录日志控制器
 */
class LoginLog extends BaseController
{
    /**
     * 获取登录日志列表
     * @param Request $request
     * @return \think\Response
     */
    public function list(Request $request)
    {
        $params = $request->param();
        $result = LoginLogService::getList($params);

        return json($result);
    }
}
