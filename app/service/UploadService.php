<?php

namespace app\service;

use think\facade\Filesystem;
use think\Exception;

/**
 * 文件上传服务层 - 静态方法
 *
 * 存储优先级由 ImgbedService::getPriority() 读取（.env 的 UPLOAD_PRIORITY / UPLOAD_PRIORITY_<TYPE>）：
 *   imgbed 优先：只传图床，上传失败直接返回错误（不降级本地）；
 *   local  优先：只存本地，保存失败直接返回错误（不降级图床）。
 * 两侧二选一，不做自动降级；失败时把具体原因返回给调用方。
 */
class UploadService
{
    /**
     * 上传文件到图床（流式，不把内容读进内存）
     *
     * @return array{ok: bool, url: ?string, error: ?string}
     */
    private static function imgbedPut($file, $extension, $mimeType)
    {
        return ImgbedService::uploadFile($file->getPathname(), $extension, $mimeType);
    }

    /**
     * 上传文件到本地 public 磁盘
     *
     * @param string $dir  一级子目录（images/videos/documents/files）
     * @return array{ok: bool, path: ?string, url: ?string}
     */
    private static function localPut($file, $dir, $extension)
    {
        try {
            $fileName = date('Y/m/d') . '/' . md5(uniqid(mt_rand(), true)) . '.' . $extension;
            $disk = Filesystem::disk('public');
            $path = $disk->putFileAs($dir, $file, $fileName);
            if (!$path) {
                return ['ok' => false, 'path' => null, 'url' => null];
            }
            return ['ok' => true, 'path' => $path, 'url' => '/storage/' . $path];
        } catch (\Exception $e) {
            return ['ok' => false, 'path' => null, 'url' => null];
        }
    }

    /**
     * 上传文件（按优先级在 图床 / 本地 之间二选一，不做跨侧降级）
     *
     * imgbed 优先：只传图床，失败即返回错误；
     * local  优先：只存本地，失败即返回错误。
     *
     * @param string $type  ImgbedService::TYPE_* 之一，决定读取哪个优先级开关
     * @param string $dir   本地一级子目录
     * @return array{ok: bool, url: ?string, storage: ?string, disk_path: ?string, msg: ?string}
     */
    private static function store($file, $type, $dir, $extension, $mimeType)
    {
        // 图床优先：只试图床
        if (ImgbedService::isImgbedFirst($type)) {
            if (!ImgbedService::isEnabled()) {
                return ['ok' => false, 'url' => null, 'storage' => null, 'disk_path' => null, 'msg' => '图床未启用，请开启后重试（或将上传优先级改为本地）'];
            }
            $imgbed = self::imgbedPut($file, $extension, $mimeType);
            if ($imgbed['ok']) {
                return ['ok' => true, 'url' => $imgbed['url'], 'storage' => 'imgbed', 'disk_path' => '', 'msg' => null];
            }
            return ['ok' => false, 'url' => null, 'storage' => null, 'disk_path' => null, 'msg' => '图床上传失败：' . ($imgbed['error'] ?: '未知错误')];
        }

        // 本地优先：只存本地
        $local = self::localPut($file, $dir, $extension);
        if ($local['ok']) {
            return ['ok' => true, 'url' => $local['url'], 'storage' => 'local', 'disk_path' => $local['path'], 'msg' => null];
        }
        return ['ok' => false, 'url' => null, 'storage' => null, 'disk_path' => null, 'msg' => '文件保存失败'];
    }

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

            // 图床 / 本地 按 UPLOAD_PRIORITY_IMAGE 二选一，失败即报错
            $stored = self::store($file, ImgbedService::TYPE_IMAGE, 'images', $extension, $mimeType);
            if (!$stored['ok']) {
                return ['code' => 1, 'msg' => $stored['msg']];
            }

            // 返回文件信息
            $fileInfo = [
                'original_name' => $file->getOriginalName(),
                'file_size'     => $file->getSize(),
                'mime_type'     => $mimeType,
                'extension'     => $extension,
                'width'         => $imageInfo[0],
                'height'        => $imageInfo[1],
                'url'           => $stored['url'],
                'path'          => $stored['disk_path'],
                'storage'       => $stored['storage'],
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

            // 图床 / 本地 按 UPLOAD_PRIORITY_FILE 二选一，失败即报错
            $stored = self::store($file, ImgbedService::TYPE_FILE, 'files', $extension, $file->getMime());
            if (!$stored['ok']) {
                return ['code' => 1, 'msg' => $stored['msg']];
            }

            // 返回文件信息
            $fileInfo = [
                'original_name' => $file->getOriginalName(),
                'file_size'     => $file->getSize(),
                'mime_type'     => $file->getMime(),
                'extension'     => $extension,
                'url'           => $stored['url'],
                'path'          => $stored['disk_path'],
                'storage'       => $stored['storage'],
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

            // 图床 / 本地 按 UPLOAD_PRIORITY_VIDEO 二选一，失败即报错
            $stored = self::store($file, ImgbedService::TYPE_VIDEO, 'videos', $extension, $mimeType);
            if (!$stored['ok']) {
                return ['code' => 1, 'msg' => $stored['msg']];
            }

            // 获取视频信息（可选：使用ffmpeg获取时长、分辨率等）
            $videoInfo = self::getVideoInfo($file->getPathname());

            // 返回文件信息
            $fileInfo = [
                'original_name' => $file->getOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $mimeType,
                'extension' => $extension,
                'url' => $stored['url'],
                'path' => $stored['disk_path'],
                'storage' => $stored['storage'],
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

            // 图床 / 本地 按 UPLOAD_PRIORITY_DOCUMENT 二选一，失败即报错
            $stored = self::store($file, ImgbedService::TYPE_DOCUMENT, 'documents', $extension, $file->getMime());
            if (!$stored['ok']) {
                return ['code' => 1, 'msg' => $stored['msg']];
            }

            // 返回文件信息
            $fileInfo = [
                'original_name' => $file->getOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMime(),
                'extension' => $extension,
                'url' => $stored['url'],
                'path' => $stored['disk_path'],
                'storage' => $stored['storage'],
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
            // 图床外链文件不在本地磁盘，直接跳过
            if (preg_match('#^https?://#i', $path)) {
                return ['code' => 0, 'msg' => '外链文件，无需本地删除'];
            }

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