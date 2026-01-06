/**
 * 文件处理器
 * 处理普通文件的上传逻辑
 */

class FileHandler extends BaseHandler {
    constructor(config) {
        super(config);
    }

    /**
     * 验证文件
     * @param {File} file - 文件对象
     * @returns {Object} { valid: boolean, error: string }
     */
    validate(file) {
        return FileValidator.validate(file, 'file', this.config);
    }

    /**
     * 生成文件预览
     * @param {File} file - 文件对象
     * @returns {Promise<Object>} 预览信息
     */
    async generatePreview(file) {
        this.preview = await PreviewGenerator.generate(file, 'file');
        return this.preview;
    }

    /**
     * 上传文件
     * @param {File} file - 文件对象
     * @param {Object} options - 上传选项 { roomId, token }
     * @returns {Promise<Object>} 上传结果
     */
    async upload(file, options) {
        const { roomId, token } = options;

        if (!roomId) {
            throw new Error('房间ID不能为空');
        }

        // 构建FormData
        const formData = new FormData();
        formData.append('file', file);
        formData.append('room_id', roomId);

        // 发送HTTP请求
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), UPLOAD_CONFIG.timeout.file);

        try {
            const response = await fetch(this.config.api, {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + token
                },
                body: formData,
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();

            if (result.code !== 0) {
                throw new Error(result.msg || '上传失败');
            }

            return result.data;
        } catch (error) {
            clearTimeout(timeoutId);

            if (error.name === 'AbortError') {
                throw new Error('上传超时，请检查网络连接');
            }

            throw error;
        }
    }

    /**
     * 获取消息类型
     * @returns {string} 消息类型
     */
    getMessageType() {
        return UPLOAD_CONFIG.messageTypes.FILE;
    }

    /**
     * 创建临时消息对象
     * @param {string} tempId - 临时ID
     * @param {Object} preview - 预览信息
     * @param {Object} userInfo - 用户信息
     * @returns {Object} 临时消息对象
     */
    createTempMessage(tempId, preview, userInfo) {
        return {
            id: tempId,
            type: 'file',
            fileName: preview.name,
            fileSize: preview.size,
            fileExtension: preview.extension,
            fileIcon: preview.icon,
            isOwn: true,
            time: new Date().toISOString(),
            sender: {
                id: userInfo.id,
                nickname: userInfo.nick_name,
                avatar: userInfo.avatar
            }
        };
    }
}

// 导出（兼容不同模块系统）
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FileHandler;
}
