<?php

namespace app\service;

use think\facade\Env;
use think\facade\Log;

/**
 * ImgbedService —— 自建图床（CloudFlare-ImgBed）接入客户端
 *
 * 独立类，职责单一：只负责与图床服务的传输和 URL 组装，不掺业务逻辑。
 * 集成点在 UploadService 各上传方法 / UserService::uploadAvatar；
 * 上传失败由调用方直接返回错误（是否降级由调用方按 UPLOAD_PRIORITY 决定），本类不抛异常。
 *
 * 配置（.env，扁平键，与项目风格一致）：
 *   IMGBED_ENABLED = true            总开关（缺省 false）
 *   IMGBED_BASE    = https://img.bitlesu.com
 *   IMGBED_TOKEN   = imgbed_xxx      图床后台生成的 API Token（仅 upload 权限）
 *                                    未配置 token 时自动视为未启用
 *   IMGBED_PROXY   = http://127.0.0.1:7890   可选出站代理（仅本机直连被墙重置时用，生产留空直连）
 *   IMGBED_TLS     = 1.3                     可选强制 TLS 版本（1.2/1.3；本机 phpstudy 老 OpenSSL
 *                                            默认协商会被重置，强制 1.3 可通；生产留空自动协商）
 *   IMGBED_CAINFO  = D:\Git\mingw64\etc\ssl\certs\ca-bundle.crt   可选 CA 证书包（本机 php 未配
 *                                            curl.cainfo 时证书校验失败；生产留空用系统默认）
 *   IMGBED_TIMEOUT = 300                     可选上传超时（秒，缺省 300；视频等大文件可调大）
 *
 * 上传优先级（.env，决定图床与本地谁先走）：
 *   UPLOAD_PRIORITY              = imgbed | local   全局优先级（缺省 imgbed=图床优先）
 *   UPLOAD_PRIORITY_<TYPE>       = imgbed | local   按类型覆盖，
 *                                TYPE ∈ IMAGE / VIDEO / DOCUMENT / FILE / AVATAR
 *   含义：imgbed = 只传图床（失败即报错，不降级）；local = 只存本地（失败即报错，不降级）
 *
 * API 规格（站点接入文档 imgbed-api.md，2026-09-08 全链路实测）：
 *   POST {base}/upload?serverCompress=false
 *   鉴权：请求头 Authorization: Bearer <token>
 *   表单：multipart/form-data，字段名 file
 *   成功 200：[{"src":"/file/<文件名>"}]，外链 = {base}/file/<文件名>
 *   单文件上限约 20MB（TG Bot API 硬限）
 *   serverCompress=false 必带：不传会被 TG 压缩重编码（内容变 JPEG）
 */
class ImgbedService
{
    /** 图床返回 src 的合法形状（文件名只允许字母数字._-） */
    private const SRC_SHAPE_RE = '/^\/file\/[A-Za-z0-9._\-]+$/';

    /** 文件名中不允许出现的字符（统一替换为 _） */
    private const UNSAFE_NAME_RE = '/[^A-Za-z0-9._\-]/';

    /**
     * 图床上传是否启用
     * 开关打开且配置了 token 才算启用
     */
    public static function isEnabled(): bool
    {
        if (!Env::get('IMGBED_ENABLED', false)) {
            return false;
        }
        $token = trim((string) Env::get('IMGBED_TOKEN', ''));
        return $token !== '';
    }

    /** 存储类型标识（用于按类型读取上传优先级） */
    public const TYPE_IMAGE    = 'image';
    public const TYPE_VIDEO    = 'video';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_FILE     = 'file';
    public const TYPE_AVATAR   = 'avatar';

    /**
     * 读取某类型的上传优先级
     *
     * 先查 UPLOAD_PRIORITY_<TYPE>（如 UPLOAD_PRIORITY_VIDEO），
     * 未配置或值非法时回落到全局 UPLOAD_PRIORITY，最终默认图床优先。
     *
     * @param string $type 见 self::TYPE_* 常量
     * @return string 'imgbed'（图床优先）或 'local'（本地优先）
     */
    public static function getPriority(string $type): string
    {
        $value = strtolower(trim((string) Env::get('UPLOAD_PRIORITY_' . strtoupper($type), '')));
        if ($value !== 'imgbed' && $value !== 'local') {
            $value = strtolower(trim((string) Env::get('UPLOAD_PRIORITY', 'imgbed')));
        }
        return $value === 'local' ? 'local' : 'imgbed';
    }

    /**
     * 某类型当前是否「图床优先」
     */
    public static function isImgbedFirst(string $type): bool
    {
        return self::getPriority($type) === 'imgbed';
    }

    /**
     * 图床基地址
     */
    public static function getBase(): string
    {
        return rtrim((string) Env::get('IMGBED_BASE', 'https://img.bitlesu.com'), '/');
    }

    /**
     * 判断 URL 是否属于本图床（供删除/清理逻辑甄别外链文件）
     */
    public static function isManagedUrl(string $url): bool
    {
        return str_starts_with($url, self::getBase() . '/file/');
    }

    /**
     * 上传一段二进制内容到图床
     *
     * @param string $content      文件二进制内容
     * @param string $extension    扩展名（不含点，如 jpg；由调用方完成类型校验后传入）
     * @param string $contentType  MIME 类型（仅作 multipart 声明，不作为安全依据）
     * @return array{ok: bool, url: ?string, error: ?string}
     */
    public static function upload(string $content, string $extension, string $contentType = 'application/octet-stream'): array
    {
        if ($content === '') {
            return ['ok' => false, 'url' => null, 'error' => '文件内容为空'];
        }

        // CURLFile 只能读真实文件路径，内容先落临时文件，请求后即删
        $tmpFile = tempnam(sys_get_temp_dir(), 'imgbed_');
        if ($tmpFile === false || file_put_contents($tmpFile, $content) === false) {
            return ['ok' => false, 'url' => null, 'error' => '创建临时文件失败'];
        }

        try {
            return self::uploadFile($tmpFile, $extension, $contentType);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * 上传一个磁盘文件到图床（流式，不把内容读进内存，适合大视频/文档）
     *
     * @param string $filePath     本地文件绝对路径
     * @param string $extension    扩展名（不含点；由调用方完成类型校验后传入）
     * @param string $contentType  MIME 类型（仅作 multipart 声明，不作为安全依据）
     * @return array{ok: bool, url: ?string, error: ?string}
     */
    public static function uploadFile(string $filePath, string $extension, string $contentType = 'application/octet-stream'): array
    {
        $token = trim((string) Env::get('IMGBED_TOKEN', ''));
        if ($token === '') {
            return ['ok' => false, 'url' => null, 'error' => '图床未配置 IMGBED_TOKEN'];
        }
        if (!is_file($filePath)) {
            return ['ok' => false, 'url' => null, 'error' => '文件不存在'];
        }
        $extension = strtolower(preg_replace(self::UNSAFE_NAME_RE, '', $extension) ?? '');
        if ($extension === '' || strlen($extension) > 16) {
            return ['ok' => false, 'url' => null, 'error' => '非法扩展名'];
        }

        // 文件名服务端生成：毫秒时间戳_随机8位.扩展名，不使用任何用户输入
        $filename = (int) (microtime(true) * 1000) . '_' . bin2hex(random_bytes(4)) . '.' . $extension;

        $uploadUrl = self::getBase() . '/upload?serverCompress=false';
        $curlFile  = new \CURLFile($filePath, $contentType, $filename);

        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['file' => $curlFile],
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(1, (int) Env::get('IMGBED_TIMEOUT', 300)),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        // 可选出站代理：不配置则直连（生产环境）；本机开发直连被重置时才需要
        $proxy = trim((string) Env::get('IMGBED_PROXY', ''));
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        // 可选强制 TLS 版本 / 指定 CA 包（同为本机老 OpenSSL/php.ini 缺证书的场景，生产留空）
        $tls = (string) Env::get('IMGBED_TLS', '');
        if ($tls === '1.3') {
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_3);
        } elseif ($tls === '1.2') {
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        }
        $cainfo = trim((string) Env::get('IMGBED_CAINFO', ''));
        if ($cainfo !== '' && is_file($cainfo)) {
            curl_setopt($ch, CURLOPT_CAINFO, $cainfo);
        }
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Log::warning('[Imgbed] 请求失败: ' . $err);
            return ['ok' => false, 'url' => null, 'error' => '图床连接失败'];
        }
        if ($status !== 200) {
            Log::warning('[Imgbed] HTTP ' . $status . ': ' . substr((string) $body, 0, 200));
            return ['ok' => false, 'url' => null, 'error' => '图床上传失败(' . $status . ')'];
        }

        // 响应校验：必须是 [{"src":"/file/<文件名>"}] 形状，防止异常响应拼出恶意 URL
        $payload = json_decode((string) $body, true);
        $src = $payload[0]['src'] ?? null;
        if (!is_string($src) || !preg_match(self::SRC_SHAPE_RE, $src)) {
            Log::error('[Imgbed] 响应形状异常: ' . substr((string) $body, 0, 200));
            return ['ok' => false, 'url' => null, 'error' => '图床返回异常'];
        }

        $url = self::getBase() . $src;
        Log::info('[Imgbed] 上传成功 ' . $filename . ' (' . (int) @filesize($filePath) . ' 字节) → ' . $url);
        return ['ok' => true, 'url' => $url, 'error' => null];
    }
}
