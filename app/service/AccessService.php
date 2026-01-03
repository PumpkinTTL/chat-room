<?php

namespace app\service;

use app\model\Access;

/**
 * 访问记录服务层
 */
class AccessService
{
    /**
     * 记录访问
     * @param string $ip IP地址
     * @param string $remark 备注（如：登录成功、访问首页等）
     * @param string $userAgent User-Agent
     * @return bool
     */
    public static function logAccess($ip, $remark = '', $userAgent = '')
    {
        try {
            // 识别平台
            $platform = Access::identifyPlatform($userAgent);

            // 插入记录
            Access::create([
                'ip'       => $ip,
                'remark'   => $remark ?: '访问',
                'platform' => $platform,
            ]);

            return true;
        } catch (\Exception $e) {
            // 记录失败不影响业务流程
            trace('访问记录失败：' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * 获取访问统计
     * @param int $days 统计天数
     * @return array
     */
    public static function getAccessStats($days = 7)
    {
        try {
            $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            $stats = Access::where('time', '>=', $startDate)
                ->field([
                    'DATE(time) as date',
                    'COUNT(*) as count',
                    'COUNT(DISTINCT ip) as unique_ips',
                ])
                ->group('DATE(time)')
                ->order('date', 'desc')
                ->select()
                ->toArray();

            return [
                'code' => 0,
                'data' => $stats,
            ];
        } catch (\Exception $e) {
            return [
                'code' => 1,
                'msg' => '获取统计失败',
            ];
        }
    }
}
