/**
 * 文件上传管理器（单例模式）
 * 统一管理所有文件类型的上传
 */

class UploadManager {
    constructor() {
        if (UploadManager.instance) {
            return UploadManager.instance;
        }

        // 处理器映射
        this.handlers = {
            image: null,
            video: null,
            file: null
        };

        // 上传队列
        this.uploadQueue = new Map();

        // 回调函数
        this.callbacks = {
            onProgress: null,
            onSuccess: null,
            onFailed: null,
            onStatusChange: null
        };

        // 初始化处理器
        this.initHandlers();

        UploadManager.instance = this;
    }

    /**
     * 初始化处理器
     */
    initHandlers() {
        this.handlers.image = new ImageHandler(UPLOAD_CONFIG.types.image);
        this.handlers.video = new VideoHandler(UPLOAD_CONFIG.types.video);
        this.handlers.file = new FileHandler(UPLOAD_CONFIG.types.file);
    }

    /**
     * 设置回调函数
     * @param {Object} callbacks - 回调函数对象
     */
    setCallbacks(callbacks) {
        if (callbacks.onProgress) this.callbacks.onProgress = callbacks.onProgress;
        if (callbacks.onSuccess) this.callbacks.onSuccess = callbacks.onSuccess;
        if (callbacks.onFailed) this.callbacks.onFailed = callbacks.onFailed;
        if (callbacks.onStatusChange) this.callbacks.onStatusChange = callbacks.onStatusChange;
    }

    /**
     * 上传文件（主入口）
     * @param {File} file - 文件对象
     * @param {string} fileType - 文件类型 (image/video/file)
     * @param {Object} options - 上传选项 { roomId, token, userInfo }
     * @returns {Promise<Object>} 上传结果
     */
    async upload(file, fileType, options) {
        const { roomId, token, userInfo } = options;

        if (!roomId) {
            throw new Error('房间ID不能为空');
        }

        if (!token) {
            throw new Error('Token不能为空');
        }

        // 获取对应处理器
        const handler = this.handlers[fileType];
        if (!handler) {
            throw new Error(`不支持的文件类型: ${fileType}`);
        }

        // 验证文件
        const validation = handler.validate(file);
        if (!validation.valid) {
            throw new Error(validation.error);
        }

        // 生成预览
        const preview = await handler.generatePreview(file);

        // 生成临时ID
        const tempId = 'temp_' + Date.now();

        // 创建临时消息
        const tempMessage = handler.createTempMessage(tempId, preview, userInfo);

        // 添加到上传队列
        this.uploadQueue.set(tempId, {
            file: file,
            handler: handler,
            tempId: tempId,
            status: UPLOAD_CONFIG.status.SENDING
        });

        // 触发状态变更回调（传递tempMessage）
        this.triggerStatusChange(tempId, UPLOAD_CONFIG.status.SENDING, tempMessage);

        // 启动进度模拟
        const progressControl = handler.startProgressSimulation(tempId, (msgId, progress) => {
            this.triggerProgress(msgId, progress);
        });

        // 保存进度控制器
        this.uploadQueue.get(tempId).progressControl = progressControl;

        try {
            // 执行上传
            const result = await handler.upload(file, { roomId, token });

            // 停止进度模拟
            progressControl.stop();

            // 平滑过渡到100%
            handler.smoothProgressToComplete(
                tempId,
                this.getCurrentProgress(tempId),
                (msgId, progress) => {
                    this.triggerProgress(msgId, progress);
                },
                (msgId) => {
                    // 清理进度显示
                    this.triggerProgress(msgId, 0);

                    // 更新队列状态
                    this.uploadQueue.get(tempId).status = UPLOAD_CONFIG.status.SUCCESS;

                    // 触发成功回调
                    this.triggerSuccess({
                        tempId: tempId,
                        messageId: result.id,
                        message: result,
                        tempMessage: tempMessage
                    });

                    // 触发状态变更
                    this.triggerStatusChange(tempId, UPLOAD_CONFIG.status.SUCCESS);

                    // 从队列移除
                    this.uploadQueue.delete(tempId);
                }
            );

            return {
                tempId: tempId,
                messageId: result.id,
                tempMessage: tempMessage
            };

        } catch (error) {
            // 停止进度模拟
            progressControl.stop();

            // 更新队列状态
            this.uploadQueue.get(tempId).status = UPLOAD_CONFIG.status.FAILED;

            // 触发失败回调
            this.triggerFailed({
                tempId: tempId,
                error: error.message,
                tempMessage: tempMessage
            });

            // 触发状态变更
            this.triggerStatusChange(tempId, UPLOAD_CONFIG.status.FAILED);

            // 从队列移除
            this.uploadQueue.delete(tempId);

            throw error;
        }
    }

    /**
     * 重试上传
     * @param {string} tempId - 临时ID
     * @param {Object} options - 上传选项
     * @returns {Promise<Object>} 上传结果
     */
    async retry(tempId, options) {
        const uploadTask = this.uploadQueue.get(tempId);

        if (!uploadTask) {
            throw new Error('未找到上传任务');
        }

        // 清理旧的处理器
        uploadTask.handler.cleanup();

        // 重新上传
        return await this.upload(uploadTask.file, this.getFileTypeByHandler(uploadTask.handler), options);
    }

    /**
     * 取消上传
     * @param {string} tempId - 临时ID
     */
    cancel(tempId) {
        const uploadTask = this.uploadQueue.get(tempId);

        if (!uploadTask) {
            return false;
        }

        // 停止进度
        if (uploadTask.progressControl) {
            uploadTask.progressControl.stop();
        }

        // 清理资源
        uploadTask.handler.cleanup();

        // 更新状态
        uploadTask.status = UPLOAD_CONFIG.status.FAILED;

        // 触发取消回调
        this.triggerFailed({
            tempId: tempId,
            error: '用户取消',
            cancelled: true
        });

        // 从队列移除
        this.uploadQueue.delete(tempId);

        return true;
    }

    /**
     * 获取当前进度
     * @param {string} tempId - 临时ID
     * @returns {number} 当前进度(0-100)
     */
    getCurrentProgress(tempId) {
        // 这里需要从外部状态获取，或者我们在管理器内部维护进度状态
        return 0;
    }

    /**
     * 根据处理器获取文件类型
     * @param {BaseHandler} handler - 处理器对象
     * @returns {string} 文件类型
     */
    getFileTypeByHandler(handler) {
        for (const [type, h] of Object.entries(this.handlers)) {
            if (h === handler) {
                return type;
            }
        }
        return 'file';
    }

    /**
     * 触发进度回调
     * @param {string} tempId - 临时ID
     * @param {number} progress - 进度值(0-100)
     */
    triggerProgress(tempId, progress) {
        if (this.callbacks.onProgress) {
            this.callbacks.onProgress(tempId, progress);
        }
    }

    /**
     * 触发成功回调
     * @param {Object} data - 成功数据
     */
    triggerSuccess(data) {
        console.log('[UploadManager] triggerSuccess called:', data);
        if (this.callbacks.onSuccess) {
            this.callbacks.onSuccess(data);
        } else {
            console.warn('[UploadManager] onSuccess callback not set!');
        }
    }

    /**
     * 触发失败回调
     * @param {Object} data - 失败数据
     */
    triggerFailed(data) {
        if (this.callbacks.onFailed) {
            this.callbacks.onFailed(data);
        }
    }

    /**
     * 触发状态变更回调
     * @param {string} tempId - 临时ID
     * @param {string} status - 状态
     * @param {Object} tempMessage - 临时消息（可选）
     */
    triggerStatusChange(tempId, status, tempMessage = null) {
        if (this.callbacks.onStatusChange) {
            this.callbacks.onStatusChange(tempId, status, tempMessage);
        }
    }

    /**
     * 清理所有上传任务
     */
    cleanup() {
        for (const [tempId, task] of this.uploadQueue.entries()) {
            if (task.progressControl) {
                task.progressControl.stop();
            }
            task.handler.cleanup();
        }

        this.uploadQueue.clear();
    }

    /**
     * 获取上传队列状态
     * @returns {Object} 队列状态
     */
    getQueueStatus() {
        const status = {
            total: this.uploadQueue.size,
            sending: 0,
            success: 0,
            failed: 0
        };

        for (const task of this.uploadQueue.values()) {
            switch (task.status) {
                case UPLOAD_CONFIG.status.SENDING:
                    status.sending++;
                    break;
                case UPLOAD_CONFIG.status.SUCCESS:
                    status.success++;
                    break;
                case UPLOAD_CONFIG.status.FAILED:
                    status.failed++;
                    break;
            }
        }

        return status;
    }
}

// 创建单例实例
const uploadManager = new UploadManager();

// 导出（兼容不同模块系统）
if (typeof module !== 'undefined' && module.exports) {
    module.exports = UploadManager;
}

// 全局访问
window.UploadManager = UploadManager;
window.uploadManager = uploadManager;
