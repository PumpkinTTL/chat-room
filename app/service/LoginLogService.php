<?php

namespace app\service;

use app\model\LoginLog;

/**
 * 登录日志服务层
 */
class LoginLogService
{
    /**
     * 记录登录日志
     * @param string $ip IP地址
     * @param int $status 状态 1成功 0失败
     * @param string $remark 备注
     * @param int|null $userId 用户ID
     * @return array
     */
    public static function log($ip, $status, $remark = '', $userId = null)
    {
        try {
            LoginLog::create([
                'ip'      => $ip,
                'status'  => $status,
                'remark'  => $remark,
                'user_id' => $userId,
            ]);

            return ['code' => 0, 'msg' => '记录成功'];
        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '记录失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取登录日志列表
     * @param array $params 查询参数
     * @return array
     */
    public static function getList($params = [])
    {
        $page = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 20;
        $ip = $params['ip'] ?? '';
        $status = $params['status'] ?? '';
        $userId = $params['user_id'] ?? '';

        $query = LoginLog::order('create_time', 'desc');

        if (!empty($ip)) {
            $query->where('ip', 'like', "%{$ip}%");
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($userId !== '') {
            $query->where('user_id', $userId);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return [
            'code' => 0,
            'msg'  => '获取成功',
            'data' => [
                'list'  => $list,
                'total' => $total,
                'page'  => $page,
                'limit' => $limit,
            ]
        ];
    }
}
