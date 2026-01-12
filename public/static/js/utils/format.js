// ┌──────────────────────────────────────────────────────────────┐
// │  格式化工具函数                                                │
// │  包含：时间、文件大小、时长、URL等格式化函数                     │
// └──────────────────────────────────────────────────────────────┘

function formatTime(date) {
    if (!date) return '';

    const messageDate = date instanceof Date ? date : new Date(date);

    if (isNaN(messageDate.getTime())) {
        return '';
    }

    const now = new Date();
    const diffInMinutes = Math.floor((now - messageDate) / 60000);

    if (diffInMinutes < 1) return '刚刚';
    if (diffInMinutes < 60) return diffInMinutes + '分钟前';

    if (diffInMinutes < 1440) {
        const hour = messageDate.getHours().toString().padStart(2, '0');
        const minute = messageDate.getMinutes().toString().padStart(2, '0');
        return hour + ':' + minute;
    }

    const month = (messageDate.getMonth() + 1).toString().padStart(2, '0');
    const day = messageDate.getDate().toString().padStart(2, '0');
    const hour = messageDate.getHours().toString().padStart(2, '0');
    const minute = messageDate.getMinutes().toString().padStart(2, '0');
    return month + '-' + day + ' ' + hour + ':' + minute;
}

function formatUrl(url) {
    if (!url) return '';
    return url;
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function formatDuration(seconds) {
    if (!seconds || seconds < 0) return '0:00';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);

    if (hours > 0) {
        return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
}

function getFileExtension(fileNameOrUrl) {
    if (!fileNameOrUrl) return '';
    let fileName = fileNameOrUrl;
    if (fileNameOrUrl.includes('/')) {
        fileName = fileNameOrUrl.split('/').pop();
    }
    const lastDot = fileName.lastIndexOf('.');
    if (lastDot > -1 && lastDot < fileName.length - 1) {
        return fileName.substring(lastDot + 1).toUpperCase();
    }
    return '';
}

function getFileNameFromUrl(url) {
    if (!url) return '';
    return url.split('/').pop() || '';
}

function isAudioFile(fileNameOrUrl) {
    if (!fileNameOrUrl) return false;
    let fileName = fileNameOrUrl;
    if (fileNameOrUrl.includes('/')) {
        fileName = fileNameOrUrl.split('/').pop();
    }
    const ext = fileName.toString().toLowerCase().split('.').pop();
    return ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'opus', 'webm'].includes(ext);
}

export {
    formatTime,
    formatUrl,
    formatFileSize,
    formatDuration,
    getFileExtension,
    getFileNameFromUrl,
    isAudioFile
};
