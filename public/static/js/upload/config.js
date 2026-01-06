/**
 * 文件上传配置
 * 统一管理所有文件类型的上传配置
 */

const UPLOAD_CONFIG = {
    // 文件类型配置
    types: {
        image: {
            name: '图片',
            maxSize: 20 * 1024 * 1024,      // 20MB
            allowedTypes: ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'],
            api: '/api/message/sendImage',
            // 进度模拟配置：快速阶段到fastEnd，慢速阶段到slowEnd，HTTP回调后到100%
            progress: {
                fastEnd: 60,      // 快速阶段结束百分比
                slowEnd: 85,      // 慢速阶段结束百分比
                interval: 100     // 更新间隔(ms)
            },
            accept: 'image/*',
            fieldName: 'image'
        },
        video: {
            name: '视频',
            maxSize: 200 * 1024 * 1024,     // 200MB
            allowedTypes: ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'],
            api: '/api/message/sendVideo',
            progress: {
                fastEnd: 40,      // 视频上传慢，快速阶段更低
                slowEnd: 70,      // 慢速阶段更低
                interval: 200     // 更新间隔更长
            },
            accept: 'video/*',
            fieldName: 'video'
        },
        file: {
            name: '文件',
            maxSize: 100 * 1024 * 1024,     // 100MB
            allowedTypes: null,              // 允许所有类型
            api: '/api/message/sendFile',
            progress: {
                fastEnd: 30,      // 文件上传最慢，快速阶段最低
                slowEnd: 60,      // 慢速阶段最低
                interval: 300     // 更新间隔最长
            },
            accept: '*/*',
            fieldName: 'file'
        }
    },

    // 上传状态枚举
    status: {
        PENDING: 'pending',    // 等待上传
        SENDING: 'sending',    // 上传中
        SUCCESS: 'success',    // 上传成功
        FAILED: 'failed'       // 上传失败
    },

    // 消息类型枚举
    messageTypes: {
        TEXT: 'normal',
        IMAGE: 'image',
        VIDEO: 'video',
        FILE: 'file',
        SYSTEM: 'system'
    },

    // 超时配置（毫秒）
    timeout: {
        image: 60000,      // 图片上传超时：1分钟
        video: 600000,     // 视频上传超时：10分钟
        file: 300000       // 文件上传超时：5分钟
    },

    // 重试配置
    retry: {
        maxAttempts: 3,    // 最大重试次数
        delay: 2000        // 重试延迟(ms)
    }
};

// 导出配置（兼容不同模块系统）
if (typeof module !== 'undefined' && module.exports) {
    module.exports = UPLOAD_CONFIG;
}
