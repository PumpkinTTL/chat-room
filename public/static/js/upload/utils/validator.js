/**
 * 文件验证器工具类
 * 负责验证文件的类型、大小等
 */

class FileValidator {
    /**
     * 验证文件
     * @param {File} file - 文件对象
     * @param {string} fileType - 文件类型 (image/video/file)
     * @param {Object} config - 文件类型配置
     * @returns {Object} { valid: boolean, error: string }
     */
    static validate(file, fileType, config) {
        if (!file) {
            return { valid: false, error: '请选择文件' };
        }

        // 验证文件大小
        const sizeError = this.validateSize(file, config.maxSize);
        if (sizeError) {
            return { valid: false, error: sizeError };
        }

        // 验证文件类型
        const typeError = this.validateType(file, config.allowedTypes);
        if (typeError) {
            return { valid: false, error: typeError };
        }

        return { valid: true, error: null };
    }

    /**
     * 验证文件大小
     * @param {File} file - 文件对象
     * @param {number} maxSize - 最大文件大小(字节)
     * @returns {string|null} 错误信息或null
     */
    static validateSize(file, maxSize) {
        if (!file.size) {
            return '文件大小无效';
        }

        if (file.size > maxSize) {
            const maxSizeMB = (maxSize / 1024 / 1024).toFixed(0);
            return `文件大小不能超过${maxSizeMB}MB`;
        }

        return null;
    }

    /**
     * 验证文件类型
     * @param {File} file - 文件对象
     * @param {Array<string>} allowedTypes - 允许的MIME类型列表
     * @returns {string|null} 错误信息或null
     */
    static validateType(file, allowedTypes) {
        // 如果allowedTypes为null，表示允许所有类型
        if (!allowedTypes || allowedTypes.length === 0) {
            return null;
        }

        const mimeType = file.type.toLowerCase();

        // 检查MIME类型是否匹配
        if (!allowedTypes.includes(mimeType)) {
            return `不支持的文件类型: ${mimeType}`;
        }

        return null;
    }

    /**
     * 检测文件类型
     * @param {File} file - 文件对象
     * @returns {string} 文件类型 (image/video/file)
     */
    static detectFileType(file) {
        if (!file || !file.type) {
            return 'file';
        }

        const mimeType = file.type.toLowerCase();

        if (mimeType.startsWith('image/')) {
            return 'image';
        } else if (mimeType.startsWith('video/')) {
            return 'video';
        } else {
            return 'file';
        }
    }

    /**
     * 格式化文件大小
     * @param {number} bytes - 字节数
     * @returns {string} 格式化后的大小
     */
    static formatFileSize(bytes) {
        if (bytes === 0) return '0 B';

        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const bytesSafe = Math.max(bytes, 0);
        const pow = Math.min(
            Math.floor((bytesSafe ? Math.log(bytesSafe) : 0) / Math.log(1024)),
            units.length - 1
        );

        const size = bytesSafe / Math.pow(1024, pow);
        return round(size, 2) + ' ' + units[pow];
    }

    /**
     * 获取文件扩展名
     * @param {File} file - 文件对象
     * @returns {string} 文件扩展名
     */
    static getFileExtension(file) {
        if (!file || !file.name) {
            return '';
        }

        const parts = file.name.split('.');
        if (parts.length > 1) {
            return parts[parts.length - 1].toLowerCase();
        }

        return '';
    }

    /**
     * 获取文件类型图标
     * @param {string} mimeType - MIME类型
     * @returns {string} FontAwesome图标类名
     */
    static getFileIcon(mimeType) {
        if (!mimeType) return 'fa-file';

        if (mimeType.startsWith('image/')) return 'fa-file-image';
        if (mimeType.startsWith('video/')) return 'fa-file-video';
        if (mimeType.startsWith('audio/')) return 'fa-file-audio';
        if (mimeType.includes('pdf')) return 'fa-file-pdf';
        if (mimeType.includes('word') || mimeType.includes('document')) return 'fa-file-word';
        if (mimeType.includes('excel') || mimeType.includes('sheet')) return 'fa-file-excel';
        if (mimeType.includes('powerpoint') || mimeType.includes('presentation')) return 'fa-file-powerpoint';
        if (mimeType.includes('zip') || mimeType.includes('rar') || mimeType.includes('compressed')) return 'fa-file-archive';
        if (mimeType.includes('text')) return 'fa-file-alt';

        return 'fa-file';
    }
}

// 辅助函数：四舍五入
function round(num, decimals) {
    const factor = Math.pow(10, decimals);
    return Math.round(num * factor) / factor;
}

// 导出（兼容不同模块系统）
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FileValidator;
}
