// ┌──────────────────────────────────────────────────────────────┐
// │  常量定义 - 文件图标映射                                       │
// │  包含：文件类型图标、文件分类CSS类                              │
// └──────────────────────────────────────────────────────────────┘

const FILE_ICON_MAP = {
    // 图片
    'jpg': 'fas fa-file-image', 'jpeg': 'fas fa-file-image', 'png': 'fas fa-file-image',
    'gif': 'fas fa-file-image', 'webp': 'fas fa-file-image', 'svg': 'fas fa-file-image',
    'bmp': 'fas fa-file-image', 'ico': 'fas fa-file-image', 'tiff': 'fas fa-file-image',
    'psd': 'fas fa-file-image', 'ai': 'fas fa-file-image', 'eps': 'fas fa-file-image',
    // 视频
    'mp4': 'fas fa-file-video', 'webm': 'fas fa-file-video', 'ogg': 'fas fa-file-video',
    'mov': 'fas fa-file-video', 'avi': 'fas fa-file-video', 'mkv': 'fas fa-file-video',
    'flv': 'fas fa-file-video', 'wmv': 'fas fa-file-video', 'm4v': 'fas fa-file-video',
    '3gp': 'fas fa-file-video', 'ts': 'fas fa-file-video', 'mts': 'fas fa-file-video',
    // 音频
    'mp3': 'fas fa-file-audio', 'wav': 'fas fa-file-audio', 'ogg': 'fas fa-file-audio',
    'flac': 'fas fa-file-audio', 'aac': 'fas fa-file-audio', 'm4a': 'fas fa-file-audio',
    'wma': 'fas fa-file-audio', 'opus': 'fas fa-file-audio',
    // 文档
    'pdf': 'fas fa-file-pdf', 'doc': 'fas fa-file-word', 'docx': 'fas fa-file-word',
    'xls': 'fas fa-file-excel', 'xlsx': 'fas fa-file-excel', 'ppt': 'fas fa-file-powerpoint',
    'pptx': 'fas fa-file-powerpoint', 'txt': 'fas fa-file-alt', 'md': 'fas fa-file-alt',
    'rtf': 'fas fa-file-alt', 'odt': 'fas fa-file-word', 'ods': 'fas fa-file-excel',
    'odp': 'fas fa-file-powerpoint', 'csv': 'fas fa-file-csv',
    // 压缩包
    'zip': 'fas fa-file-archive', 'rar': 'fas fa-file-archive', '7z': 'fas fa-file-archive',
    'tar': 'fas fa-file-archive', 'gz': 'fas fa-file-archive', 'bz2': 'fas fa-file-archive',
    'xz': 'fas fa-file-archive', 'cab': 'fas fa-file-archive', 'iso': 'fas fa-file-archive',
    // 代码
    'js': 'fas fa-file-code', 'ts': 'fas fa-file-code', 'html': 'fas fa-file-code',
    'css': 'fas fa-file-code', 'json': 'fas fa-file-code', 'xml': 'fas fa-file-code',
    'py': 'fas fa-file-code', 'java': 'fas fa-file-code', 'php': 'fas fa-file-code',
    'cpp': 'fas fa-file-code', 'c': 'fas fa-file-code', 'h': 'fas fa-file-code',
    'go': 'fas fa-file-code', 'rs': 'fas fa-file-code', 'swift': 'fas fa-file-code',
    'kt': 'fas fa-file-code', 'dart': 'fas fa-file-code', 'vue': 'fas fa-file-code',
    'jsx': 'fas fa-file-code', 'tsx': 'fas fa-file-code', 'sql': 'fas fa-file-code',
    'sh': 'fas fa-file-code', 'bash': 'fas fa-file-code', 'yml': 'fas fa-file-code',
    'yaml': 'fas fa-file-code', 'toml': 'fas fa-file-code', 'ini': 'fas fa-file-code',
    'conf': 'fas fa-file-code', 'cfg': 'fas fa-file-code', 'rb': 'fas fa-file-code',
    'pl': 'fas fa-file-code', 'scala': 'fas fa-file-code', 'r': 'fas fa-file-code',
    'm': 'fas fa-file-code', 'mm': 'fas fa-file-code',
    // 可执行文件
    'exe': 'fas fa-file', 'msi': 'fas fa-file', 'app': 'fas fa-file',
    'dmg': 'fas fa-file', 'deb': 'fas fa-file', 'rpm': 'fas fa-file',
    'apk': 'fas fa-file', 'ipa': 'fas fa-file', 'bin': 'fas fa-file',
    'elf': 'fas fa-file', 'so': 'fas fa-file', 'dll': 'fas fa-file',
    'sys': 'fas fa-file', 'drv': 'fas fa-file',
    // 数据库
    'db': 'fas fa-database', 'sqlite': 'fas fa-database', 'mdb': 'fas fa-database',
    'accdb': 'fas fa-database',
    // 其他常见格式
    'key': 'fas fa-key', 'pem': 'fas fa-key', 'cert': 'fas fa-certificate',
    'torrent': 'fas fa-download', 'jar': 'fas fa-file-archive'
};

const FILE_TYPE_IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tiff', 'psd', 'ai', 'eps'];
const FILE_TYPE_VIDEO = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v', '3gp', 'ts', 'mts'];
const FILE_TYPE_AUDIO = ['mp3', 'wav', 'flac', 'aac', 'm4a', 'wma', 'opus'];
const FILE_TYPE_ARCHIVE = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'cab', 'iso', 'jar'];
const FILE_TYPE_CODE = ['js', 'ts', 'html', 'css', 'json', 'py', 'java', 'php', 'cpp', 'go', 'vue', 'jsx', 'tsx', 'sh', 'bash', 'yml', 'yaml', 'toml', 'ini', 'conf', 'cfg', 'rb', 'pl', 'scala', 'r'];
const FILE_TYPE_DOCUMENT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'rtf', 'odt', 'ods', 'odp', 'csv'];
const FILE_TYPE_EXECUTABLE = ['exe', 'msi', 'app', 'dmg', 'deb', 'rpm', 'apk', 'ipa', 'bin', 'elf', 'so', 'dll', 'sys', 'drv'];
const FILE_TYPE_DATABASE = ['db', 'sqlite', 'mdb', 'accdb'];

function getFileIcon(fileNameOrExtension) {
    const ext = (fileNameOrExtension || '').toString().toLowerCase().replace('.', '').split('.').pop();
    return FILE_ICON_MAP[ext] || 'fas fa-file';
}

function getFileIconClass(fileNameOrExtension) {
    const ext = (fileNameOrExtension || '').toString().toLowerCase().replace('.', '').split('.').pop();

    if (FILE_TYPE_IMAGE.includes(ext)) return 'image';
    if (FILE_TYPE_VIDEO.includes(ext)) return 'video';
    if (FILE_TYPE_AUDIO.includes(ext)) return 'audio';
    if (FILE_TYPE_ARCHIVE.includes(ext)) return 'archive';
    if (FILE_TYPE_CODE.includes(ext)) return 'code';
    if (FILE_TYPE_DOCUMENT.includes(ext)) return 'document';
    if (FILE_TYPE_EXECUTABLE.includes(ext)) return 'default';
    if (FILE_TYPE_DATABASE.includes(ext)) return 'document';
    return 'default';
}

export {
    FILE_ICON_MAP,
    FILE_TYPE_IMAGE,
    FILE_TYPE_VIDEO,
    FILE_TYPE_AUDIO,
    FILE_TYPE_ARCHIVE,
    FILE_TYPE_CODE,
    FILE_TYPE_DOCUMENT,
    FILE_TYPE_EXECUTABLE,
    FILE_TYPE_DATABASE,
    getFileIcon,
    getFileIconClass
};
