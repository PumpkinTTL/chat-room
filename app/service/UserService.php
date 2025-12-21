<?php

namespace app\service;

use app\model\User;

/**
 * 用户服务层 - 静态方法
 */
class UserService
{
    /**
     * 获取用户列表
     * @param array $params 查询参数
     * @return array
     */
    public static function getUserList($params = [])
    {
        $query = User::where([]);

        // 状态筛选
        if (isset($params['status']) && in_array($params['status'], [0, 1])) {
            $query->where('status', $params['status']);
        }

        // 昵称搜索
        if (!empty($params['nick_name'])) {
            $query->where('nick_name', 'like', '%' . $params['nick_name'] . '%');
        }

        $page = isset($params['page']) ? (int)$params['page'] : 1;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 20;

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return [
            'total' => $total,
            'list' => $list,
        ];
    }

    /**
     * 创建用户
     * @param array $data 用户数据
     * @return array
     */
    public static function createUser($data)
    {
        // 数据验证
        if (empty($data['nick_name'])) {
            return ['code' => 1, 'msg' => '昵称不能为空'];
        }

        if (empty($data['password'])) {
            return ['code' => 1, 'msg' => '密码不能为空'];
        }

        // 验证昵称长度
        if (strlen($data['nick_name']) > 50) {
            return ['code' => 1, 'msg' => '昵称不能超过50个字符'];
        }

        // 验证密码长度
        if (strlen($data['password']) < 6) {
            return ['code' => 1, 'msg' => '密码不能少于6个字符'];
        }

        // 验证状态值
        if (isset($data['status']) && !in_array($data['status'], [0, 1])) {
            return ['code' => 1, 'msg' => '状态值无效'];
        }

        // 设置默认值
        if (!isset($data['status'])) {
            $data['status'] = User::STATUS_NORMAL;
        }

        try {
            $user = User::create($data);
            return [
                'code' => 0,
                'msg'  => '创建成功',
                'data' => $user,
            ];
        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '创建失败：' . $e->getMessage()];
        }
    }

    /**
     * 更新用户
     * @param int $id 用户ID
     * @param array $data 用户数据
     * @return array
     */
    public static function updateUser($id, $data)
    {
        // 验证用户是否存在
        $userInfo = User::find($id);
        if (!$userInfo) {
            return ['code' => 1, 'msg' => '用户不存在'];
        }

        // 数据验证
        if (!empty($data['nick_name'])) {
            if (strlen($data['nick_name']) > 50) {
                return ['code' => 1, 'msg' => '昵称不能超过50个字符'];
            }
        }

        // 验证状态值
        if (isset($data['status']) && !in_array($data['status'], [0, 1])) {
            return ['code' => 1, 'msg' => '状态值无效'];
        }

        try {
            $result = $userInfo->save($data);
            if ($result) {
                return ['code' => 0, 'msg' => '更新成功'];
            }
            return ['code' => 1, 'msg' => '更新失败'];
        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '更新失败：' . $e->getMessage()];
        }
    }

    /**
     * 删除用户
     * @param int $id 用户ID
     * @return array
     */
    public static function deleteUser($id)
    {
        // 验证用户是否存在
        $userInfo = User::find($id);
        if (!$userInfo) {
            return ['code' => 1, 'msg' => '用户不存在'];
        }

        try {
            $result = $userInfo->delete();
            if ($result) {
                return ['code' => 0, 'msg' => '删除成功'];
            }
            return ['code' => 1, 'msg' => '删除失败'];
        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '删除失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取用户详情
     * @param int $id 用户ID
     * @return array
     */
    public static function getUserInfo($id)
    {
        $userInfo = User::find($id);
        if ($userInfo) {
            return ['code' => 0, 'msg' => '获取成功', 'data' => $userInfo];
        }
        return ['code' => 1, 'msg' => '用户不存在'];
    }

    /**
     * 用户登录
     * @param array $data 登录数据
     * @return array
     */
    public static function login($data)
    {
        // 业务逻辑验证
        if (empty($data['username'])) {
            return ['code' => 1, 'msg' => '用户ID不能为空'];
        }

        if (empty($data['password'])) {
            return ['code' => 1, 'msg' => '密码不能为空'];
        }

        // 根据ID和密码查询用户
        $userInfo = User::where('id', $data['username'])
            ->where('password', $data['password'])
            ->find();

        if (!$userInfo) {
            return ['code' => 1, 'msg' => 'ID或密码错误'];
        }

        // 验证用户状态
        if ($userInfo['status'] != User::STATUS_NORMAL) {
            return ['code' => 1, 'msg' => '用户已被禁用'];
        }

        // 业务逻辑成功，生成token
        $token = TokenService::generateToken($userInfo['id']);

        // 返回用户信息和token
        return [
            'code' => 0,
            'msg' => '登录成功',
            'data' => [
                'id' => $userInfo['id'],
                'nick_name' => $userInfo['nick_name'],
                'avatar' => $userInfo['avatar'],

            ],
            'token' => $token,
        ];
    }
}
