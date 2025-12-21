<?php

namespace app\controller;

use think\Response;

/**
 * 静态文件访问控制器
 * 用于直接通过ThinkPHP提供静态文件访问，无需nginx代理
 */
class StaticFile
{
    /**
     * 访问storage目录下的文件
     * @param string $path 文件路径
     * @return Response
     */
    public function storage(string $path = '')
    {
        // 解码URL路径
        $path = urldecode($path);
        
        // 安全检查：防止目录遍历攻击
        if (strpos($path, '..') !== false) {
            return response('非法访问', 403);
        }

        // 构建完整文件路径
        $filePath = app()->getRootPath() . 'public/storage/' . $path;

        // 检查文件是否存在
        if (!file_exists($filePath) || !is_file($filePath)) {
            return response('文件不存在', 404);
        }

        // 获取文件MIME类型
        $mimeType = $this->getMimeType($filePath);

        // 读取文件内容
        $content = file_get_contents($filePath);

        // 返回文件响应
        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=31536000', // 缓存1年
            'Expires' => gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT',
        ]);
    }

    /**
     * 获取文件MIME类型
     * @param string $filePath 文件路径
     * @return string
     */
    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $mimeTypes = [
            // 图片
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            
            // 文档
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            
            // 压缩文件
            'zip'  => 'application/zip',
            'rar'  => 'application/x-rar-compressed',
            '7z'   => 'application/x-7z-compressed',
            
            // 文本
            'txt'  => 'text/plain',
            'csv'  => 'text/csv',
            'json' => 'application/json',
            'xml'  => 'application/xml',
            
            // 音视频
            'mp3'  => 'audio/mpeg',
            'mp4'  => 'video/mp4',
            'avi'  => 'video/x-msvideo',
            'mov'  => 'video/quicktime',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
