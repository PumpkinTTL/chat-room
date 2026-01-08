<?php

namespace app\controller;

use app\BaseController;
use app\service\AccessService;
use think\facade\View;

class Index extends BaseController
{
    public function index()
    {
        return View::fetch('index');
    }
    public function hello()
    {
        return 'hello';
    }
    public function login()
    {
        return View::fetch('login/index');
    }

    /**
     * 记录页面访问
     */
    public function logAccess()
    {
        // 获取请求体数据
        $body = request()->getContent();
        $data = json_decode($body, true) ?? [];

        $ip = $data['client_ip'] ?? '';
        $page = $data['page'] ?? '未知页面';

        // 如果前端没传IP，从header获取真实IP
        if (empty($ip)) {
            $ip = $this->getRealIp();
        }

        $userAgent = request()->header('user-agent', '');

        AccessService::logAccess($ip, $page, $userAgent);

        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 静态文件服务
     */
    public function storage($path)
    {
        $filePath = app()->getRootPath() . 'public/storage/' . $path;
        
        // 安全检查
        if (strpos($path, '..') !== false) {
            abort(404);
        }
        
        // 检查文件是否存在
        if (!file_exists($filePath) || !is_file($filePath)) {
            abort(404);
        }
        
        // 获取文件MIME类型
        $mimeType = mime_content_type($filePath);
        if (!$mimeType) {
            $mimeType = 'application/octet-stream';
        }
        
        // 设置响应头
        return response(file_get_contents($filePath))
            ->header([
                'Content-Type' => $mimeType,
                'Cache-Control' => 'public, max-age=31536000',
                'Expires' => gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT'
            ]);
    }
}
