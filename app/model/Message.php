<?php

namespace app\model;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * 聊天消息模型 - 继承think\Model
 */
class Message extends Model
{
    use SoftDelete;
    // 设置表名
    protected $table = 'ch_messages';

    // 设置json类型字段
    protected $json = ['file_info', 'extra_data'];
    protected $jsonAssoc = true;

    // 自动写入时间戳
    protected $autoWriteTimestamp = 'datetime';

    // 软删除
    protected $deleteTime = 'delete_time';
    protected $defaultSoftDelete = null;

    // 消息类型常量
    public const TYPE_TEXT = 1;     // 文本消息
    public const TYPE_IMAGE = 2;    // 图片消息
    public const TYPE_FILE = 3;     // 文件消息
    public const TYPE_SYSTEM = 4;   // 系统消息
    public const TYPE_VIDEO = 5;    // 视频消息

    /**
     * 关联用户模型
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id')
            ->field('id,nick_name,avatar');
    }

    /**
     * 关联房间模型
     */
    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id', 'id')
            ->field('id,name,description');
    }

    /**
     * 获取消息类型文本
     */
    public function getMessageTypeTextAttr($value, $data)
    {
        $types = [
            self::TYPE_TEXT   => '文本',
            self::TYPE_IMAGE  => '图片',
            self::TYPE_FILE   => '文件',
            self::TYPE_SYSTEM => '系统',
        ];
        return $types[$data['message_type']] ?? '未知';
    }

    /**
     * 查询指定房间的消息
     */
    public function scopeByRoom($query, $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    /**
     * 查询指定时间之后的消息（用于增量拉取）
     */
    public function scopeAfterTime($query, $time)
    {
        return $query->where('create_time', '>', $time);
    }

    /**
     * 查询指定时间之前的消息（用于加载更多历史）
     */
    public function scopeBeforeTime($query, $time)
    {
        return $query->where('create_time', '<', $time);
    }

    /**
     * 检查消息是否属于指定用户
     */
    public function isOwnMessage($userId)
    {
        return $this->user_id == $userId;
    }

    /**
     * 判断是否为系统消息
     */
    public function isSystemMessage()
    {
        return $this->message_type == self::TYPE_SYSTEM;
    }

    /**
     * 判断是否为文件类型消息
     */
    public function isFileMessage()
    {
        return in_array($this->message_type, [self::TYPE_IMAGE, self::TYPE_FILE]);
    }
}