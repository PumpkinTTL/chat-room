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

        // 业务逻辑成功，生成token并记录登录IP
        $ip = isset($data['ip']) ? $data['ip'] : '';
        $token = TokenService::generateToken($userInfo['id'], 24, $ip);

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

    /**
     * 获取用户资料（用于个人中心）
     * @param int $userId 用户ID
     * @param int $currentUserId 当前登录用户ID
     * @return array
     */
    public static function getProfile($userId, $currentUserId)
    {
        if (empty($userId)) {
            return ['code' => 1, 'msg' => '用户ID不能为空'];
        }

        try {
            // 获取用户基本信息（包含 sign 字段）
            $user = User::field('id, nick_name, avatar, sign, create_time')
                ->find($userId);

            if (!$user) {
                return ['code' => 1, 'msg' => '用户不存在'];
            }

            // 获取用户统计信息
            $messageCount = \app\model\Message::where('user_id', $userId)->count();
            $roomCount = \app\model\RoomUser::where('user_id', $userId)->count();

            // 返回数据
            return [
                'code' => 0,
                'msg' => '获取成功',
                'data' => [
                    'profile' => [
                        'id' => $user->id,
                        'nickname' => $user->nick_name,
                        'avatar' => $user->avatar,
                        'sign' => $user->sign ?: '',
                        'created_at' => $user->create_time,
                    ],
                    'stats' => [
                        'message_count' => $messageCount,
                        'room_count' => $roomCount,
                        'join_date' => $user->create_time,
                    ]
                ]
            ];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '获取失败：' . $e->getMessage()];
        }
    }

    /**
     * 更新用户资料
     * @param int $userId 用户ID
     * @param int $currentUserId 当前登录用户ID
     * @param array $data 更新数据
     * @return array
     */
    public static function updateProfile($userId, $currentUserId, $data)
    {
        if (empty($userId)) {
            return ['code' => 1, 'msg' => '用户ID不能为空'];
        }

        // 只能修改自己的资料
        if ($userId != $currentUserId) {
            return ['code' => 1, 'msg' => '只能修改自己的资料'];
        }

        try {
            $user = User::find($userId);
            if (!$user) {
                return ['code' => 1, 'msg' => '用户不存在'];
            }

            // 验证昵称
            if (!empty($data['nickname'])) {
                if (mb_strlen($data['nickname']) > 20) {
                    return ['code' => 1, 'msg' => '昵称不能超过20个字符'];
                }
                $user->nick_name = trim($data['nickname']);
            }

            // 更新个性签名
            if (isset($data['sign'])) {
                if (mb_strlen($data['sign']) > 200) {
                    return ['code' => 1, 'msg' => '个性签名不能超过200个字符'];
                }
                $user->sign = trim($data['sign']);
            }

            // 更新头像（如果有）
            if (!empty($data['avatar'])) {
                $user->avatar = $data['avatar'];
            }

            $user->save();

            // 返回更新后的用户信息
            return [
                'code' => 0,
                'msg' => '更新成功',
                'data' => [
                    'id' => $user->id,
                    'nickname' => $user->nick_name,
                    'avatar' => $user->avatar,
                    'sign' => $user->sign,
                ]
            ];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '更新失败：' . $e->getMessage()];
        }
    }

    /**
     * 上传用户头像
     * @param int $userId 用户ID
     * @param \think\file\UploadedFile $file 上传的文件
     * @return array
     */
    public static function uploadAvatar($userId, $file)
    {
        if (empty($userId)) {
            return ['code' => 1, 'msg' => '用户ID不能为空'];
        }

        if (!$file) {
            return ['code' => 1, 'msg' => '请选择要上传的文件'];
        }

        try {
            // 获取用户信息
            $user = User::find($userId);
            if (!$user) {
                return ['code' => 1, 'msg' => '用户不存在'];
            }

            // 验证文件类型（只允许图片）
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $mimeType = $file->getMime();
            if (!in_array($mimeType, $allowedTypes)) {
                return ['code' => 1, 'msg' => '只支持 JPG、PNG、GIF、WebP 格式的图片'];
            }

            // 验证文件大小（5MB）
            $maxSize = 5 * 1024 * 1024;
            if ($file->getSize() > $maxSize) {
                return ['code' => 1, 'msg' => '图片大小不能超过5MB'];
            }

            // 验证图片内容
            $imageInfo = getimagesize($file->getPathname());
            if (!$imageInfo) {
                return ['code' => 1, 'msg' => '图片文件无效'];
            }

            // 获取文件扩展名
            $extension = strtolower($file->getOriginalExtension());
            if (!$extension) {
                $mimeToExt = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                ];
                $extension = $mimeToExt[$mimeType] ?? 'jpg';
            }

            // 使用固定文件名: avatars/{用户ID}.{扩展名}
            // 这样同一用户的头像URL永远不变,浏览器可以缓存
            $fileName = 'avatars/' . $userId . '.' . $extension;

            // 保存文件(会自动覆盖旧文件)
            $disk = \think\facade\Filesystem::disk('public');
            $path = $disk->putFileAs('images', $file, $fileName);

            if (!$path) {
                return ['code' => 1, 'msg' => '文件保存失败'];
            }

            // 获取访问URL(固定URL,包含用户ID)
            $url = '/storage/' . $path;

            // 注意: 不需要删除旧文件,因为新文件会覆盖旧文件
            // 如果扩展名改变了(例如从jpg改为png),旧的jpg文件会保留,但不影响使用

            // 更新用户头像
            $user->avatar = $url;
            $user->save();

            // 返回结果
            return [
                'code' => 0,
                'msg' => '上传成功',
                'data' => [
                    'url' => $url,
                    'avatar' => $url,
                ]
            ];

        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '上传失败：' . $e->getMessage()];
        }
    }
}
