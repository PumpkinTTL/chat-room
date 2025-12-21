<?php

namespace app\service;

use think\facade\Filesystem;
use think\Exception;

/**
 * 文件上传服务层 - 静态方法
 */
class UploadService
{
    /**
     * 上传图片文件
     * @param \think\file\UploadedFile $file 上传的文件
     * @return array
     */
    public static function uploadImage($file)
    {
        if (!$file) {
            return ['code' => 1, 'msg' => '请选择文件'];
        }

        try {
            // 验证文件类型
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $mimeType = $file->getMime();
            if (!in_array($mimeType, $allowedTypes)) {
                return ['code' => 1, 'msg' => '只支持 JPG、PNG、GIF、WebP 格式的图片'];
            }

            // 验证文件大小（20MB）
            $maxSize = 20 * 1024 * 1024; // 20MB
            if ($file->getSize() > $maxSize) {
                return ['code' => 1, 'msg' => '图片大小不能超过20MB'];
            }

            // 验证图片内容
            $imageInfo = getimagesize($file->getPathname());
            if (!$imageInfo) {
                return ['code' => 1, 'msg' => '图片文件无效'];
            }

            // 生成文件名
            $extension = strtolower($file->getOriginalExtension());
            if (!$extension) {
                // 根据MIME类型推断扩展名
                $mimeToExt = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                ];
                $extension = $mimeToExt[$mimeType] ?? 'jpg';
            }

            $fileName = date('Y/m/d') . '/' . md5(uniqid(mt_rand(), true)) . '.' . $extension;

            // 保存文件
            $disk = Filesystem::disk('public');
            $path = $disk->putFileAs('images', $file, $fileName);

            if (!$path) {
                return ['code' => 1, 'msg' => '文件保存失败'];
            }

            // 获取访问URL
            $url = '/storage/' . $path;

            // 返回文件信息
            $fileInfo = [
                'original_name' => $file->getOriginalName(),
                'file_size'     => $file->getSize(),
                'mime_type'     => $mimeType,
                'extension'     => $extension,
                'width'         => $imageInfo[0],
                'height'        => $imageInfo[1],
                'url'           => $url,
                'path'          => $path,
            ];

            return ['code' => 0, 'msg' => '上传成功', 'data' => $fileInfo];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '上传失败：' . $e->getMessage()];
        }
    }

    /**
     * 上传普通文件
     * @param \think\file\UploadedFile $file 上传的文件
     * @param array $allowedTypes 允许的文件类型
     * @param int $maxSize 最大文件大小（字节）
     * @return array
     */
    public static function uploadFile($file, $allowedTypes = [], $maxSize = 10 * 1024 * 1024)
    {
        if (!$file) {
            return ['code' => 1, 'msg' => '请选择文件'];
        }

        try {
            // 验证文件大小
            if ($file->getSize() > $maxSize) {
                return ['code' => 1, 'msg' => '文件大小不能超过' . ($maxSize / 1024 / 1024) . 'MB'];
            }

            // 验证文件类型
            if (!empty($allowedTypes)) {
                $mimeType = $file->getMime();
                if (!in_array($mimeType, $allowedTypes)) {
                    return ['code' => 1, 'msg' => '不支持的文件类型'];
                }
            }

            // 生成文件名
            $extension = strtolower($file->getOriginalExtension());
            if (!$extension) {
                return ['code' => 1, 'msg' => '无法获取文件扩展名'];
            }

            $fileName = date('Y/m/d') . '/' . md5(uniqid(mt_rand(), true)) . '.' . $extension;

            // 保存文件
            $disk = Filesystem::disk('public');
            $path = $disk->putFileAs('files', $file, $fileName);

            if (!$path) {
                return ['code' => 1, 'msg' => '文件保存失败'];
            }

            // 获取访问URL
            $url = '/storage/' . $path;

            // 返回文件信息
            $fileInfo = [
                'original_name' => $file->getOriginalName(),
                'file_size'     => $file->getSize(),
                'mime_type'     => $file->getMime(),
                'extension'     => $extension,
                'url'           => $url,
                'path'          => $path,
            ];

            return ['code' => 0, 'msg' => '上传成功', 'data' => $fileInfo];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '上传失败：' . $e->getMessage()];
        }
    }

    /**
     * 删除文件
     * @param string $path 文件路径（相对于uploads目录）
     * @return array
     */
    public static function deleteFile($path)
    {
        if (empty($path)) {
            return ['code' => 1, 'msg' => '文件路径不能为空'];
        }

        try {
            // 安全检查，防止目录遍历攻击
            if (strpos($path, '..') !== false || strpos($path, '/') === 0) {
                return ['code' => 1, 'msg' => '非法的文件路径'];
            }

            $disk = Filesystem::disk('public');
            $fullPath = $disk->path($path);

            // 检查文件是否存在
            if (!$disk->exists($path)) {
                return ['code' => 0, 'msg' => '文件不存在'];
            }

            // 删除文件
            if ($disk->delete($path)) {
                return ['code' => 0, 'msg' => '删除成功'];
            } else {
                return ['code' => 1, 'msg' => '删除失败'];
            }

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '删除失败：' . $e->getMessage()];
        }
    }

    /**
     * 格式化文件大小
     * @param int $bytes 字节数
     * @return string
     */
    public static function formatFileSize($bytes)
    {
        if ($bytes === 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}