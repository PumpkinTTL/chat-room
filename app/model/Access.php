<?php

namespace app\model;

use think\Model;

/**
 * 访问记录模型
 */
class Access extends Model
{
    // 设置表名
    protected $table = 'ch_access';

    // 自动写入时间戳
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'time';
    protected $updateTime = false;

    // 平台常量
    public const PLATFORM_PC = 'PC';
    public const PLATFORM_ANDROID = 'Android';
    public const PLATFORM_IOS = 'iOS';
    public const PLATFORM_UNKNOWN = 'Unknown';

    /**
     * 根据 User-Agent 识别平台
     * @param string $userAgent
     * @return string
     */
    public static function identifyPlatform($userAgent)
    {
        if (empty($userAgent)) {
            return self::PLATFORM_UNKNOWN;
        }

        // iOS 检测
        if (preg_match('/iPhone|iPad|iPod/i', $userAgent)) {
            return self::PLATFORM_IOS;
        }

        // Android 检测
        if (preg_match('/Android/i', $userAgent)) {
            return self::PLATFORM_ANDROID;
        }

        // PC 检测 (Windows, Mac, Linux)
        if (preg_match('/Windows|Macintosh|Mac OS|Linux/i', $userAgent)) {
            return self::PLATFORM_PC;
        }

        return self::PLATFORM_UNKNOWN;
    }
}
