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
            // 检查文件是否有效（是否上传成功）
            if (!$file->isValid()) {
                $error = $file->getError();
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => '上传文件大小超过了 PHP 配置的最大值',
                    UPLOAD_ERR_FORM_SIZE => '上传文件大小超过了表单指定的最大值',
                    UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                    UPLOAD_ERR_NO_FILE => '没有文件被上传',
                    UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                    UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                    UPLOAD_ERR_EXTENSION => 'PHP 扩展阻止了文件上传',
                ];
                $errorMsg = $errorMessages[$error] ?? '文件上传失败';
                return ['code' => 1, 'msg' => $errorMsg];
            }
            
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
            // 检查文件是否有效（是否上传成功）
            if (!$file->isValid()) {
                $error = $file->getError();
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => '上传文件大小超过了 PHP 配置的最大值',
                    UPLOAD_ERR_FORM_SIZE => '上传文件大小超过了表单指定的最大值',
                    UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                    UPLOAD_ERR_NO_FILE => '没有文件被上传',
                    UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                    UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                    UPLOAD_ERR_EXTENSION => 'PHP 扩展阻止了文件上传',
                ];
                $errorMsg = $errorMessages[$error] ?? '文件上传失败';
                return ['code' => 1, 'msg' => $errorMsg];
            }
            
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
     * 上传视频文件
     * @param \think\file\UploadedFile $file 上传的文件
     * @return array
     */
    public static function uploadVideo($file)
    {
        if (!$file) {
            return ['code' => 1, 'msg' => '请选择文件'];
        }

        try {
            // 检查文件是否有效（是否上传成功）
            if (!$file->isValid()) {
                $error = $file->getError();
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => '上传文件大小超过了 PHP 配置的最大值',
                    UPLOAD_ERR_FORM_SIZE => '上传文件大小超过了表单指定的最大值',
                    UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                    UPLOAD_ERR_NO_FILE => '没有文件被上传',
                    UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                    UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                    UPLOAD_ERR_EXTENSION => 'PHP 扩展阻止了文件上传',
                ];
                $errorMsg = $errorMessages[$error] ?? '文件上传失败';
                return ['code' => 1, 'msg' => $errorMsg];
            }
            
            // 验证文件类型
            $allowedTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
            $mimeType = $file->getMime();
            if (!in_array($mimeType, $allowedTypes)) {
                return ['code' => 1, 'msg' => '只支持 MP4、WebM、OGG、MOV 格式的视频'];
            }

            // 验证文件大小（200MB）
            $maxSize = 200 * 1024 * 1024;
            if ($file->getSize() > $maxSize) {
                return ['code' => 1, 'msg' => '视频大小不能超过200MB'];
            }

            // 生成文件名
            $extension = strtolower($file->getOriginalExtension());
            if (!$extension) {
                // 根据MIME类型推断扩展名
                $mimeToExt = [
                    'video/mp4' => 'mp4',
                    'video/webm' => 'webm',
                    'video/ogg' => 'ogv',
                    'video/quicktime' => 'mov',
                ];
                $extension = $mimeToExt[$mimeType] ?? 'mp4';
            }

            $fileName = date('Y/m/d') . '/' . md5(uniqid(mt_rand(), true)) . '.' . $extension;

            // 保存文件
            $disk = Filesystem::disk('public');
            $path = $disk->putFileAs('videos', $file, $fileName);

            if (!$path) {
                return ['code' => 1, 'msg' => '文件保存失败'];
            }

            // 获取访问URL
            $url = '/storage/' . $path;

            // 获取视频信息（可选：使用ffmpeg获取时长、分辨率等）
            $videoInfo = self::getVideoInfo($file->getPathname());

            // 返回文件信息
            $fileInfo = [
                'original_name' => $file->getOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $mimeType,
                'extension' => $extension,
                'url' => $url,
                'path' => $path,
                'duration' => $videoInfo['duration'] ?? null,
                'width' => $videoInfo['width'] ?? null,
                'height' => $videoInfo['height'] ?? null,
            ];

            return ['code' => 0, 'msg' => '上传成功', 'data' => $fileInfo];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '上传失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取视频信息（需要ffmpeg扩展）
     * @param string $filePath 文件路径
     * @return array
     */
    private static function getVideoInfo($filePath)
    {
        $info = [
            'duration' => null,
            'width' => null,
            'height' => null,
        ];

        // 如果有ffmpeg扩展，可以使用getid3或其他库
        // 这里只是预留接口，避免强制依赖

        try {
            // 尝试使用ffprobe（如果系统安装了）
            if (function_exists('exec')) {
                $cmd = 'ffprobe -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($filePath) . ' 2>&1';
                $output = [];
                exec($cmd, $output, $returnCode);

                if ($returnCode === 0 && !empty($output)) {
                    $json = implode('', $output);
                    $data = json_decode($json, true);

                    if (isset($data['format']['duration'])) {
                        $info['duration'] = (int)$data['format']['duration'];
                    }

                    if (isset($data['streams'][0]['width'])) {
                        $info['width'] = $data['streams'][0]['width'];
                        $info['height'] = $data['streams'][0]['height'];
                    }
                }
            }
        } catch (\Exception $e) {
            // 静默失败，返回默认值
        }

        return $info;
    }

    /**
     * 上传文件（文档等）
     * @param \think\file\UploadedFile $file 上传的文件
     * @return array
     */
    public static function uploadDocument($file)
    {
        if (!$file) {
            return ['code' => 1, 'msg' => '请选择文件'];
        }

        try {
            // 验证文件大小（100MB）
            $maxSize = 100 * 1024 * 1024;
            if ($file->getSize() > $maxSize) {
                return ['code' => 1, 'msg' => '文件大小不能超过100MB'];
            }

            // 生成文件名
            $extension = strtolower($file->getOriginalExtension());
            if (!$extension) {
                return ['code' => 1, 'msg' => '无法获取文件扩展名'];
            }

            $fileName = date('Y/m/d') . '/' . md5(uniqid(mt_rand(), true)) . '.' . $extension;

            // 保存文件
            $disk = Filesystem::disk('public');
            $path = $disk->putFileAs('documents', $file, $fileName);

            if (!$path) {
                return ['code' => 1, 'msg' => '文件保存失败'];
            }

            // 获取访问URL
            $url = '/storage/' . $path;

            // 返回文件信息
            $fileInfo = [
                'original_name' => $file->getOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMime(),
                'extension' => $extension,
                'url' => $url,
                'path' => $path,
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

            // 检查文件是否存在（使用 file_exists 而不是 $disk->exists()）
            if (!file_exists($fullPath)) {
                return ['code' => 0, 'msg' => '文件不存在'];
            }

            // 删除文件（直接使用 unlink 而不是 $disk->delete()）
            if (unlink($fullPath)) {
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