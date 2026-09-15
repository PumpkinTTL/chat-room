<?php

namespace app\service;

use think\facade\Env;
use think\facade\Log;

/**
 * ImgbedService —— 自建图床（CloudFlare-ImgBed）接入客户端
 *
 * 独立类，职责单一：只负责与图床服务的传输和 URL 组装，不掺业务逻辑。
 * 集成点在 UploadService::uploadImage / UserService::uploadAvatar，
 * 上传出错或未启用时由调用方降级走本地存储，本类不抛异常。
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
        $token = trim((string) Env::get('IMGBED_TOKEN', ''));
        if ($token === '') {
            return ['ok' => false, 'url' => null, 'error' => '图床未配置 IMGBED_TOKEN'];
        }
        if ($content === '') {
            return ['ok' => false, 'url' => null, 'error' => '文件内容为空'];
        }
        $extension = strtolower(preg_replace(self::UNSAFE_NAME_RE, '', $extension) ?? '');
        if ($extension === '' || strlen($extension) > 16) {
            return ['ok' => false, 'url' => null, 'error' => '非法扩展名'];
        }

        // 文件名服务端生成：毫秒时间戳_随机8位.扩展名，不使用任何用户输入
        $filename = (int) (microtime(true) * 1000) . '_' . bin2hex(random_bytes(4)) . '.' . $extension;

        // CURLFile 只能读真实文件路径，内容先落临时文件，请求后即删
        $tmpFile = tempnam(sys_get_temp_dir(), 'imgbed_');
        if ($tmpFile === false || file_put_contents($tmpFile, $content) === false) {
            return ['ok' => false, 'url' => null, 'error' => '创建临时文件失败'];
        }

        $uploadUrl = self::getBase() . '/upload?serverCompress=false';
        $curlFile  = new \CURLFile($tmpFile, $contentType, $filename);

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
            CURLOPT_TIMEOUT        => 60,
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
        @unlink($tmpFile);

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
        Log::info('[Imgbed] 上传成功 ' . $filename . ' (' . strlen($content) . ' 字节) → ' . $url);
        return ['ok' => true, 'url' => $url, 'error' => null];
    }
}
