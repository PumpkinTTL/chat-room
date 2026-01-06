/**
 * 图片处理器
 * 处理图片文件的上传逻辑
 */

class ImageHandler extends BaseHandler {
    constructor(config) {
        super(config);
    }

    /**
     * 验证图片文件
     * @param {File} file - 文件对象
     * @returns {Object} { valid: boolean, error: string }
     */
    validate(file) {
        return FileValidator.validate(file, 'image', this.config);
    }

    /**
     * 生成图片预览
     * @param {File} file - 文件对象
     * @returns {Promise<Object>} 预览信息
     */
    async generatePreview(file) {
        this.preview = await PreviewGenerator.generate(file, 'image');
        return this.preview;
    }

    /**
     * 上传图片
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
        formData.append('image', file);
        formData.append('room_id', roomId);

        // 发送HTTP请求
        const response = await fetch(this.config.api, {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + token
            },
            body: formData
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const result = await response.json();

        if (result.code !== 0) {
            throw new Error(result.msg || '上传失败');
        }

        return result.data;
    }

    /**
     * 获取消息类型
     * @returns {string} 消息类型
     */
    getMessageType() {
        return UPLOAD_CONFIG.messageTypes.IMAGE;
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
            type: 'image',
            imageUrl: preview.url,
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
    module.exports = ImageHandler;
}
