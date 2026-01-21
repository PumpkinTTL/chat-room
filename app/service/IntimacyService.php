<?php

namespace app\service;

use think\facade\Db;
use app\model\IntimacyLevel;
use app\model\IntimacyExp;

class IntimacyService
{
    /**
     * 发送消息时增加经验值
     * @param int $roomId 房间ID
     * @param int $userId 用户ID
     * @param int $partnerId 伴侣ID
     * @param string $messageType 消息类型
     * @return array
     */
    public static function addExpForMessage($roomId, $userId, $partnerId, $messageType = 'text')
    {
        // 根据消息类型计算经验值
        $expGain = self::getExpByMessageType($messageType);
        
        // 防止刷经验：同一用户1分钟内最多获得一次经验（暂时关闭）
        /*
        $lastMessageTime = Db::name('ch_intimacy_exp')
            ->where('room_id', $roomId)
            ->where('user_id', $userId)
            ->where('partner_id', $partnerId)
            ->value('last_message_time');
            
        if ($lastMessageTime && (time() - strtotime($lastMessageTime)) < 60) {
            return ['code' => 0, 'msg' => '经验获取冷却中'];
        }
        */
        
        try {
            Db::startTrans();
            
            // 查找或创建经验记录
            $expRecord = Db::name('ch_intimacy_exp')
                ->where('room_id', $roomId)
                ->where('user_id', $userId)
                ->where('partner_id', $partnerId)
                ->find();
                
            if (!$expRecord) {
                // 创建新记录
                $expRecord = [
                    'room_id' => $roomId,
                    'user_id' => $userId,
                    'partner_id' => $partnerId,
                    'current_exp' => 0,
                    'current_level' => 1,
                    'total_messages' => 0
                ];
                $expId = Db::name('ch_intimacy_exp')->insertGetId($expRecord);
                $expRecord['id'] = $expId;
            }
            
            // 增加经验值和消息数
            $newExp = $expRecord['current_exp'] + $expGain;
            $newMessageCount = $expRecord['total_messages'] + 1;
            
            // 检查是否升级
            $levelInfo = self::checkLevelUp($expRecord['current_level'], $newExp);
            
            // 更新记录
            Db::name('ch_intimacy_exp')
                ->where('id', $expRecord['id'])
                ->update([
                    'current_exp' => $newExp,
                    'current_level' => $levelInfo['new_level'],
                    'total_messages' => $newMessageCount,
                    'last_message_time' => date('Y-m-d H:i:s')
                ]);
            
            Db::commit();
            
            // 获取完整的亲密度信息（包含两人总经验值）
            $intimacyInfo = self::getIntimacyInfo($roomId, $userId, $partnerId);
            
            return [
                'code' => 0,
                'msg' => '经验增加成功',
                'data' => [
                    'exp_gain' => $expGain,
                    'current_exp' => $intimacyInfo['current_exp'], // 使用两人总经验值
                    'current_level' => $intimacyInfo['current_level'], // 使用基于总经验值的等级
                    'level_up' => $levelInfo['level_up'],
                    'level_name' => $intimacyInfo['level_name'], // 使用基于总经验值的等级名
                    'total_messages' => $intimacyInfo['total_messages'] // 使用实际消息数
                ]
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            return ['code' => 1, 'msg' => '经验增加失败：' . $e->getMessage()];
        }
    }
    
    /**
     * 根据消息类型获取经验值（随机1-10）
     * @param string $messageType
     * @return int
     */
    private static function getExpByMessageType($messageType)
    {
        // 随机获得1-10经验值
        return rand(1, 10);
    }
    
    /**
     * 检查是否升级
     * @param int $currentLevel
     * @param int $currentExp
     * @return array
     */
    private static function checkLevelUp($currentLevel, $currentExp)
    {
        // 获取下一级所需经验
        $nextLevel = Db::name('ch_intimacy_levels')
            ->where('level', '>', $currentLevel)
            ->order('level', 'asc')
            ->find();
            
        if (!$nextLevel) {
            // 已达到最高级
            $currentLevelInfo = Db::name('ch_intimacy_levels')
                ->where('level', $currentLevel)
                ->find();
                
            return [
                'new_level' => $currentLevel,
                'level_up' => false,
                'level_name' => $currentLevelInfo['name'] ?? '未知'
            ];
        }
        
        if ($currentExp >= $nextLevel['required_exp']) {
            // 升级了，递归检查是否能继续升级
            $result = self::checkLevelUp($nextLevel['level'], $currentExp);
            // 标记为升级
            $result['level_up'] = true;
            return $result;
        }
        
        // 没有升级
        $currentLevelInfo = Db::name('ch_intimacy_levels')
            ->where('level', $currentLevel)
            ->find();
            
        return [
            'new_level' => $currentLevel,
            'level_up' => false,
            'level_name' => $currentLevelInfo['name'] ?? '未知'
        ];
    }
    
    /**
     * 获取用户在指定房间的好感度信息
     * @param int $roomId
     * @param int $userId
     * @param int $partnerId
     * @return array
     */
    public static function getIntimacyInfo($roomId, $userId, $partnerId)
    {
        // 获取两个用户的经验记录
        $userExpRecord = Db::name('ch_intimacy_exp')
            ->where('room_id', $roomId)
            ->where('user_id', $userId)
            ->where('partner_id', $partnerId)
            ->find();
            
        $partnerExpRecord = Db::name('ch_intimacy_exp')
            ->where('room_id', $roomId)
            ->where('user_id', $partnerId)
            ->where('partner_id', $userId)
            ->find();
        
        // 计算共同经验值（两人经验之和）
        $totalExp = 0;
        
        if ($userExpRecord) {
            $totalExp += $userExpRecord['current_exp'];
        }
        
        if ($partnerExpRecord) {
            $totalExp += $partnerExpRecord['current_exp'];
        }
        
        // 实时统计房间内的实际消息数（不包括已删除的）
        $totalMessages = Db::name('ch_messages')
            ->where('room_id', $roomId)
            ->whereNull('delete_time')
            ->count();
        
        if (!$userExpRecord && !$partnerExpRecord) {
            // 返回默认信息
            $defaultLevel = Db::name('ch_intimacy_levels')->where('level', 1)->find();
            return [
                'current_exp' => 0,
                'current_level' => 1,
                'level_name' => $defaultLevel['name'] ?? '恋人',
                'level_color' => $defaultLevel['color'] ?? '#ec4899',
                'level_icon' => $defaultLevel['icon'] ?? 'fa-heart',
                'total_messages' => $totalMessages,
                'next_level_exp' => 500,
                'progress_percent' => 0
            ];
        }
        
        // 根据总经验值计算等级
        $currentLevel = 1;
        $levels = Db::name('ch_intimacy_levels')->order('level', 'desc')->select()->toArray();
        
        foreach ($levels as $level) {
            if ($totalExp >= $level['required_exp']) {
                $currentLevel = $level['level'];
                break;
            }
        }
        
        // 获取当前等级信息
        $currentLevelInfo = Db::name('ch_intimacy_levels')
            ->where('level', $currentLevel)
            ->find();
            
        // 获取下一级信息
        $nextLevelInfo = Db::name('ch_intimacy_levels')
            ->where('level', '>', $currentLevel)
            ->order('level', 'asc')
            ->find();
            
        $nextLevelExp = $nextLevelInfo ? $nextLevelInfo['required_exp'] : $currentLevelInfo['required_exp'];
        $currentLevelExp = $currentLevelInfo['required_exp'];
        
        // 计算进度百分比
        $progressPercent = 0;
        if ($nextLevelInfo) {
            $expInCurrentLevel = $totalExp - $currentLevelExp;
            $expNeededForNext = $nextLevelExp - $currentLevelExp;
            $progressPercent = min(100, max(0, ($expInCurrentLevel / $expNeededForNext) * 100));
        } else {
            $progressPercent = 100; // 已达到最高级
        }
        
        return [
            'current_exp' => $totalExp,
            'current_level' => $currentLevel,
            'level_name' => $currentLevelInfo['name'] ?? '未知',
            'level_color' => $currentLevelInfo['color'] ?? '#94a3b8',
            'level_icon' => $currentLevelInfo['icon'] ?? 'fa-heart',
            'total_messages' => $totalMessages,
            'next_level_exp' => $nextLevelExp,
            'progress_percent' => round($progressPercent, 1)
        ];
    }
    
    /**
     * 获取所有等级配置
     * @return array
     */
    public static function getAllLevels()
    {
        return Db::name('ch_intimacy_levels')
            ->order('level', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 领取亲密互动好感度（60秒在线奖励）
     * @param int $roomId 房间ID
     * @param int $userId 用户ID
     * @param int $partnerId 伴侣ID
     * @return array
     */
    public static function collectInteractionExp($roomId, $userId, $partnerId)
    {
        try {
            Db::startTrans();

            // 随机获得5-10好感度
            $expGain = rand(5, 10);

            // 查找或创建经验记录
            $expRecord = Db::name('ch_intimacy_exp')
                ->where('room_id', $roomId)
                ->where('user_id', $userId)
                ->where('partner_id', $partnerId)
                ->find();

            if (!$expRecord) {
                // 创建新记录
                $expRecord = [
                    'room_id' => $roomId,
                    'user_id' => $userId,
                    'partner_id' => $partnerId,
                    'current_exp' => 0,
                    'current_level' => 1,
                    'total_messages' => 0
                ];
                $expId = Db::name('ch_intimacy_exp')->insertGetId($expRecord);
                $expRecord['id'] = $expId;
            }

            // 增加经验值
            $newExp = $expRecord['current_exp'] + $expGain;

            // 检查是否升级
            $levelInfo = self::checkLevelUp($expRecord['current_level'], $newExp);

            // 更新记录
            Db::name('ch_intimacy_exp')
                ->where('id', $expRecord['id'])
                ->update([
                    'current_exp' => $newExp,
                    'current_level' => $levelInfo['new_level'],
                    'last_interaction_collect' => date('Y-m-d H:i:s')
                ]);

            Db::commit();

            // 获取好感度信息
            $intimacyInfo = self::getIntimacyInfo($roomId, $userId, $partnerId);

            return [
                'code' => 0,
                'msg' => '领取成功',
                'data' => [
                    'exp_gain' => $expGain,
                    'current_exp' => $newExp,
                    'current_level' => $levelInfo['new_level'],
                    'level_up' => $levelInfo['level_up'],
                    'level_name' => $levelInfo['level_name'],
                    'intimacy' => [
                        'code' => 0,
                        'data' => $intimacyInfo
                    ]
                ]
            ];

        } catch (\Exception $e) {
            Db::rollback();
            return ['code' => 1, 'msg' => '领取失败：' . $e->getMessage()];
        }
    }
}