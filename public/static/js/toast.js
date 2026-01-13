/**
 * Toast 轻提示组件 - 灵动岛风格
 * 使用方法：
 * Toast.success('操作成功')
 * Toast.error('操作失败')
 * Toast.info('提示信息')
 * Toast.warning('警告信息')
 */

class Toast {
    constructor() {
        this.container = null;
        this.queue = [];
        this.init();
    }

    init() {
        // 创建容器
        this.container = document.createElement('div');
        this.container.className = 'toast-container';
        this.container.style.cssText = `
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 10000;
            pointer-events: none;
        `;
        document.body.appendChild(this.container);
    }

    show(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        toast.className = `toast animate__animated animate__fadeInDown animate__faster`;
        
        // 图标映射
        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };

        // 图标颜色映射
        const iconColors = {
            success: '#10b981',
            error: '#ef4444',
            warning: '#f59e0b',
            info: '#3b82f6'
        };

        toast.innerHTML = `
            <div class="toast-icon" style="color: ${iconColors[type]}">${icons[type]}</div>
            <div class="toast-message">${message}</div>
        `;

        // 统一底色的灵动岛样式
        toast.style.cssText = `
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(30, 30, 30, 0.95);
            padding: 8px 16px;
            border-radius: 50px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            margin-bottom: 8px;
            pointer-events: auto;
            font-size: 13px;
            color: white;
            max-width: 90vw;
            white-space: nowrap;
        `;

        // 图标样式
        const iconEl = toast.querySelector('.toast-icon');
        iconEl.style.cssText = `
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
            flex-shrink: 0;
            color: ${iconColors[type]};
        `;

        // 消息样式
        const messageEl = toast.querySelector('.toast-message');
        messageEl.style.cssText = `
            line-height: 1.4;
            font-weight: 500;
        `;

        this.container.appendChild(toast);

        // 自动移除
        setTimeout(() => {
            toast.classList.remove('animate__fadeInDown');
            toast.classList.add('animate__fadeOut');
            setTimeout(() => {
                if (toast.parentNode) {
                    this.container.removeChild(toast);
                }
            }, 300);
        }, duration);
    }

    success(message, duration) {
        this.show(message, 'success', duration);
    }

    error(message, duration) {
        this.show(message, 'error', duration);
    }

    warning(message, duration) {
        this.show(message, 'warning', duration);
    }

    info(message, duration) {
        this.show(message, 'info', duration);
    }
}

// 添加简洁样式
const style = document.createElement('style');
style.textContent = `
    /* Toast 容器居中 */
    .toast-container {
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    /* 暗黑模式支持 */
    .dark-mode .toast {
        background: rgba(40, 40, 40, 0.95) !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3) !important;
    }

    /* 亮色模式 */
    body:not(.dark-mode) .toast {
        background: rgba(255, 255, 255, 0.95) !important;
        color: #1f2937 !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1) !important;
    }

    body:not(.dark-mode) .toast-message {
        color: #1f2937 !important;
    }

    /* 移动端适配 */
    @media (max-width: 768px) {
        .toast {
            font-size: 12px !important;
            padding: 7px 14px !important;
            max-width: 85vw !important;
        }
        .toast-icon {
            width: 16px !important;
            height: 16px !important;
            font-size: 11px !important;
        }
    }
`;
document.head.appendChild(style);

// 创建全局实例
window.Toast = new Toast();

// 兼容旧的alert方式（可选）
window.showToast = (message, type = 'info') => {
    window.Toast[type](message);
};
