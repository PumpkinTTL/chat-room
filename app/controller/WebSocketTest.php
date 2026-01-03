<?php

namespace app\controller;

use app\BaseController;
use think\Response;

/**
 * WebSocket 测试控制器
 */
class WebSocketTest extends BaseController
{
    /**
     * WebSocket 测试页面
     * @return Response
     */
    public function index()
    {
        return view('index/websocket');
    }

    /**
     * 获取 WebSocket 配置信息
     * @return Response
     */
    public function config()
    {
        // 获取当前请求的协议和主机
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'wss' : 'ws';
        $host = $_SERVER['HTTP_HOST'];

        // WebSocket 服务地址
        $wsUrl = $protocol . '://' . $host . '/ws';

        return json([
            'code' => 0,
            'data' => [
                'ws_url' => $wsUrl,
                'http_protocol' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http',
                'host' => $host,
                'supports_websocket' => true,
            ]
        ]);
    }
}
