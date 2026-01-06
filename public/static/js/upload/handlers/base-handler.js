/**
 * 基础处理器抽象类
 * 所有文件处理器的基础类，定义统一的接口
 */

class BaseHandler {
    constructor(config) {
        this.config = config;
        this.file = null;
        this.preview = null;
        this.tempId = null;
        this.progressTimer = null;
    }

    /**
     * 验证文件（子类必须实现）
     * @param {File} file - 文件对象
     * @returns {Object} { valid: boolean, error: string }
     */
    validate(file) {
        throw new Error('validate() method must be implemented');
    }

    /**
     * 生成预览（子类必须实现）
     * @param {File} file - 文件对象
     * @returns {Promise<Object>} 预览信息
     */
    async generatePreview(file) {
        throw new Error('generatePreview() method must be implemented');
    }

    /**
     * 上传文件（子类必须实现）
     * @param {File} file - 文件对象
     * @param {Object} options - 上传选项
     * @returns {Promise<Object>} 上传结果
     */
    async upload(file, options) {
        throw new Error('upload() method must be implemented');
    }

    /**
     * 创建临时消息对象（子类可以覆盖）
     * @param {string} tempId - 临时ID
     * @param {Object} preview - 预览信息
     * @param {Object} userInfo - 用户信息
     * @returns {Object} 临时消息对象
     */
    createTempMessage(tempId, preview, userInfo) {
        const baseMessage = {
            id: tempId,
            type: this.getMessageType(),
            isOwn: true,
            time: new Date().toISOString(),
            sender: {
                id: userInfo.id,
                nickname: userInfo.nick_name,
                avatar: userInfo.avatar
            }
        };

        // 根据文件类型添加特定字段
        if (this.config.name === '图片') {
            baseMessage.imageUrl = preview.url;
            baseMessage.imagePreview = preview.url;
        } else if (this.config.name === '视频') {
            baseMessage.videoUrl = preview.url;
            baseMessage.videoThumbnail = preview.thumbnail;
            baseMessage.videoDuration = preview.duration;
        } else if (this.config.name === '文件') {
            baseMessage.fileName = preview.name;
            baseMessage.fileSize = preview.size;
            baseMessage.fileExtension = preview.extension;
            baseMessage.fileIcon = preview.icon;
        }

        return baseMessage;
    }

    /**
     * 获取消息类型（子类必须实现）
     * @returns {string} 消息类型
     */
    getMessageType() {
        throw new Error('getMessageType() method must be implemented');
    }

    /**
     * 启动进度模拟
     * @param {string} tempId - 临时ID
     * @param {Function} onProgress - 进度回调
     * @returns {Object} 定时器控制对象
     */
    startProgressSimulation(tempId, onProgress) {
        const config = this.config.progress;
        let progress = 0;

        const timer = setInterval(() => {
            if (progress < config.fastEnd) {
                // 快速阶段：每次增加随机值
                progress += Math.random() * (config.fastEnd / 20) + 1;
            } else if (progress < config.slowEnd) {
                // 慢速阶段：增加更小
                progress += Math.random() * 0.5 + 0.2;
            }

            // 最多到slowEnd，剩下等HTTP回调
            progress = Math.min(progress, config.slowEnd);
            onProgress(tempId, Math.round(progress));
        }, config.interval);

        return {
            timer: timer,
            stop: () => {
                clearInterval(timer);
            }
        };
    }

    /**
     * 平滑过渡到100%
     * @param {string} tempId - 临时ID
     * @param {number} currentProgress - 当前进度
     * @param {Function} onProgress - 进度回调
     * @param {Function} onComplete - 完成回调
     */
    smoothProgressToComplete(tempId, currentProgress, onProgress, onComplete) {
        const remaining = 100 - currentProgress;
        const steps = 8; // 分8步完成
        const stepValue = remaining / steps;
        let step = 0;

        const smoothTimer = setInterval(() => {
            step++;
            const newProgress = Math.min(Math.round(currentProgress + stepValue * step), 100);
            onProgress(tempId, newProgress);

            if (step >= steps) {
                clearInterval(smoothTimer);
                onProgress(tempId, 100);

                // 100%停留500ms后再清理
                setTimeout(() => {
                    onComplete(tempId);
                }, 500);
            }
        }, 25);
    }

    /**
     * 清理资源
     */
    cleanup() {
        if (this.progressTimer) {
            this.progressTimer.stop();
            this.progressTimer = null;
        }

        // 清理预览URL
        if (this.preview && this.preview.url) {
            PreviewGenerator.revokeURL(this.preview.url);
        }
    }

    /**
     * 格式化文件大小
     * @param {number} bytes - 字节数
     * @returns {string} 格式化后的大小
     */
    formatFileSize(bytes) {
        return FileValidator.formatFileSize(bytes);
    }

    /**
     * 格式化时长
     * @param {number} seconds - 秒数
     * @returns {string} 格式化后的时长
     */
    formatDuration(seconds) {
        return PreviewGenerator.formatDuration(seconds);
    }
}

// 导出（兼容不同模块系统）
if (typeof module !== 'undefined' && module.exports) {
    module.exports = BaseHandler;
}
