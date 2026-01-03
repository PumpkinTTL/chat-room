<?php

namespace app\model;

use think\Model;

/**
 * 消息已读记录模型
 */
class MessageRead extends Model
{
    protected $table = 'ch_message_reads';
    
    protected $autoWriteTimestamp = false;
    
    /**
     * 关联消息
     */
    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id', 'id');
    }
    
    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
