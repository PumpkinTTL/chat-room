/**
 * Toast 轻提示组件
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
        toast.className = `toast toast-${type}`;
        
        // 图标映射
        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };

        // 图标颜色映射（使用对应的颜色）
        const iconColors = {
            success: '#10b981',
            error: '#ef4444',
            warning: '#f59e0b',
            info: '#3b82f6'
        };

        toast.innerHTML = `
            <div class="toast-icon">
                ${icons[type]}
            </div>
            <div class="toast-message">${message}</div>
        `;

        // 样式
        toast.style.cssText = `
            display: flex;
            align-items: center;
            gap: 12px;
            background: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            margin-bottom: 10px;
            min-width: 200px;
            max-width: 400px;
            pointer-events: auto;
            animation: toastSlideIn 0.3s ease;
            font-size: 14px;
            color: #1f2937;
        `;

        // 图标样式
        const iconEl = toast.querySelector('.toast-icon');
        iconEl.style.cssText = `
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            flex-shrink: 0;
            background: rgba(255, 255, 255, 0.2);
            color: ${iconColors[type]} !important;
        `;

        // 消息样式
        const messageEl = toast.querySelector('.toast-message');
        messageEl.style.cssText = `
            flex: 1;
            line-height: 1.5;
        `;

        this.container.appendChild(toast);

        // 自动移除
        setTimeout(() => {
            toast.style.animation = 'toastSlideOut 0.3s ease';
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

// 添加动画样式
const style = document.createElement('style');
style.textContent = `
    @keyframes toastSlideIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes toastSlideOut {
        from {
            opacity: 1;
            transform: translateY(0);
        }
        to {
            opacity: 0;
            transform: translateY(-20px);
        }
    }

    /* 暗黑模式支持 */
    .dark-mode .toast {
        background: #1f2937 !important;
        color: #f3f4f6 !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3) !important;
    }

    .dark-mode .toast-message {
        color: #f3f4f6 !important;
    }
`;
document.head.appendChild(style);

// 创建全局实例
window.Toast = new Toast();

// 兼容旧的alert方式（可选）
window.showToast = (message, type = 'info') => {
    window.Toast[type](message);
};
