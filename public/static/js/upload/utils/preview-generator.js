/**
 * 预览生成器工具类
 * 负责为不同类型的文件生成预览
 */

class PreviewGenerator {
    /**
     * 生成文件预览
     * @param {File} file - 文件对象
     * @param {string} fileType - 文件类型 (image/video/file)
     * @returns {Promise<Object>} 预览信息对象
     */
    static async generate(file, fileType) {
        switch (fileType) {
            case 'image':
                return await this.generateImagePreview(file);
            case 'video':
                return await this.generateVideoPreview(file);
            case 'file':
                return this.generateFilePreview(file);
            default:
                return this.generateFilePreview(file);
        }
    }

    /**
     * 生成图片预览
     * @param {File} file - 图片文件
     * @returns {Promise<Object>} 预览信息
     */
    static async generateImagePreview(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();

            reader.onload = (e) => {
                const img = new Image();

                img.onload = () => {
                    resolve({
                        type: 'image',
                        url: e.target.result,
                        width: img.width,
                        height: img.height,
                        size: file.size,
                        name: file.name,
                        mimeType: file.type
                    });
                };

                img.onerror = () => {
                    resolve({
                        type: 'image',
                        url: e.target.result,
                        size: file.size,
                        name: file.name,
                        mimeType: file.type
                    });
                };

                img.src = e.target.result;
            };

            reader.onerror = () => {
                reject(new Error('读取图片文件失败'));
            };

            reader.readAsDataURL(file);
        });
    }

    /**
     * 生成视频预览
     * @param {File} file - 视频文件
     * @returns {Promise<Object>} 预览信息
     */
    static async generateVideoPreview(file) {
        return new Promise((resolve, reject) => {
            const video = document.createElement('video');
            video.preload = 'metadata';

            const url = URL.createObjectURL(file);

            video.onloadedmetadata = () => {
                const duration = video.duration;
                const width = video.videoWidth;
                const height = video.videoHeight;

                // 尝试生成缩略图
                video.currentTime = 1; // 跳到第1秒

                video.onseeked = () => {
                    try {
                        const canvas = document.createElement('canvas');
                        canvas.width = width;
                        canvas.height = height;

                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(video, 0, 0, width, height);

                        const thumbnailUrl = canvas.toDataURL('image/jpeg', 0.7);

                        URL.revokeObjectURL(url);

                        resolve({
                            type: 'video',
                            thumbnail: thumbnailUrl,
                            url: url, // 保留URL用于预览播放
                            duration: duration,
                            width: width,
                            height: height,
                            size: file.size,
                            name: file.name,
                            mimeType: file.type
                        });
                    } catch (e) {
                        // 生成缩略图失败，返回基本信息
                        URL.revokeObjectURL(url);
                        resolve({
                            type: 'video',
                            url: null,
                            duration: duration,
                            size: file.size,
                            name: file.name,
                            mimeType: file.type
                        });
                    }
                };

                video.onerror = () => {
                    URL.revokeObjectURL(url);
                    resolve({
                        type: 'video',
                        url: null,
                        duration: null,
                        size: file.size,
                        name: file.name,
                        mimeType: file.type
                    });
                };
            };

            video.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error('读取视频文件失败'));
            };

            video.src = url;
        });
    }

    /**
     * 生成文件预览
     * @param {File} file - 普通文件
     * @returns {Object} 预览信息
     */
    static generateFilePreview(file) {
        const extension = this.getFileExtension(file);
        const icon = this.getFileIcon(file.type);

        return {
            type: 'file',
            icon: icon,
            extension: extension,
            size: file.size,
            name: file.name,
            mimeType: file.type
        };
    }

    /**
     * 获取文件扩展名
     * @param {File} file - 文件对象
     * @returns {string} 扩展名
     */
    static getFileExtension(file) {
        if (!file || !file.name) return '';

        const parts = file.name.split('.');
        return parts.length > 1 ? parts[parts.length - 1].toUpperCase() : '';
    }

    /**
     * 获取文件图标
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
        if (mimeType.includes('javascript')) return 'fa-file-code';
        if (mimeType.includes('json')) return 'fa-file-code';
        if (mimeType.includes('html')) return 'fa-file-code';
        if (mimeType.includes('css')) return 'fa-file-code';

        return 'fa-file';
    }

    /**
     * 格式化时长
     * @param {number} seconds - 秒数
     * @returns {string} 格式化后的时长 (MM:SS 或 HH:MM:SS)
     */
    static formatDuration(seconds) {
        if (!seconds || isNaN(seconds)) return '00:00';

        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = Math.floor(seconds % 60);

        if (hours > 0) {
            return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        }

        return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }

    /**
     * 清理预览URL
     * @param {string} url - Blob URL
     */
    static revokeURL(url) {
        if (url && url.startsWith('blob:')) {
            URL.revokeObjectURL(url);
        }
    }
}

// 导出（兼容不同模块系统）
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PreviewGenerator;
}
