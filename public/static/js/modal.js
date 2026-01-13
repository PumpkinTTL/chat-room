/**
 * Modal 模态框组件 - 紧凑精致风格
 *
 * 使用方法：
 * await Modal.alert('操作成功')
 * const confirmed = await Modal.confirm('确定要删除吗？')
 * const input = await Modal.prompt('请输入房间名称：', '创建房间', '房间名称')
 */

class Modal {
    constructor() {
        this.overlay = null;
        this.currentModal = null;
        this.init();
    }

    init() {
        this.overlay = document.createElement('div');
        this.overlay.className = 'modal-overlay';
        this.overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.15s ease;
        `;
        document.body.appendChild(this.overlay);
        this.overlay.addEventListener('click', () => {
            if (this.currentModal?.closeOnOverlay) {
                this.close();
            }
        });
    }

    createModal(options = {}) {
        const {
            title = '',
            content = '',
            icon = '',
            iconColor = '',
            buttons = [],
            closeOnOverlay = false
        } = options;

        const modal = document.createElement('div');
        modal.className = 'modal-container';

        // 小图标
        let iconHTML = '';
        if (icon) {
            iconHTML = `<div class="modal-icon" style="color: ${iconColor}"><i class="fas fa-${icon}"></i></div>`;
        }

        let titleHTML = title ? `<div class="modal-title">${title}</div>` : '';
        let contentHTML = content ? `<div class="modal-content">${content}</div>` : '';

        let buttonsHTML = '';
        if (buttons.length > 0) {
            buttonsHTML = '<div class="modal-footer">' +
                buttons.map(btn => {
                    const icon = btn.icon || '';
                    const iconHtml = icon ? `<i class="fas fa-${icon}" style="margin-right: 4px;"></i>` : '';
                    return `<button class="modal-btn modal-btn-${btn.class || 'primary'}" data-action="${btn.action || 'close'}">${iconHtml}${btn.text}</button>`;
                }).join('') +
                '</div>';
        }

        modal.innerHTML = `
            <div class="modal-box">
                ${iconHTML}
                ${titleHTML}
                ${contentHTML}
                ${buttonsHTML}
            </div>
        `;

        if (buttons.length > 0) {
            buttons.forEach((btn, index) => {
                const buttonEl = modal.querySelectorAll('.modal-btn')[index];
                if (buttonEl && btn.onClick) {
                    buttonEl.addEventListener('click', (e) => {
                        e.preventDefault();
                        btn.onClick(btn.action);
                    });
                }
            });
        }

        const escapeHandler = (e) => {
            if (e.key === 'Escape' && closeOnOverlay) {
                this.close();
                document.removeEventListener('keydown', escapeHandler);
            }
        };
        document.addEventListener('keydown', escapeHandler);

        return modal;
    }

    show(options) {
        return new Promise((resolve) => {
            if (this.currentModal) this.close(false);

            this.currentModal = this.createModal(options);
            this.currentModal.resolve = resolve;

            this.overlay.appendChild(this.currentModal);
            this.overlay.style.display = 'flex';

            requestAnimationFrame(() => {
                this.overlay.style.opacity = '1';
                const box = this.currentModal.querySelector('.modal-box');
                if (box) {
                    box.style.transform = 'scale(1) translate(-50%, -50%)';
                    box.style.opacity = '1';
                }
            });

            document.body.style.overflow = 'hidden';
        });
    }

    close(result = null) {
        if (!this.currentModal) return;

        const box = this.currentModal.querySelector('.modal-box');
        if (box) {
            box.style.transform = 'scale(0.97) translate(-50%, -50%)';
            box.style.opacity = '0';
        }
        this.overlay.style.opacity = '0';

        setTimeout(() => {
            if (this.currentModal) {
                this.overlay.removeChild(this.currentModal);
                if (this.currentModal.resolve) {
                    this.currentModal.resolve(result);
                }
                this.currentModal = null;
            }
            if (!this.currentModal) {
                this.overlay.style.display = 'none';
                document.body.style.overflow = '';
            }
        }, 150);
    }

    async alert(message, title = '提示') {
        await this.show({
            icon: 'check-circle',
            iconColor: '#2563eb',
            title,
            content: `<div class="modal-message">${message}</div>`,
            buttons: [
                { text: '确定', icon: 'check', class: 'primary', onClick: () => this.close(true) }
            ],
            closeOnOverlay: true
        });
        return true;
    }

    async confirm(message, title = '确认') {
        return await this.show({
            icon: 'question-circle',
            iconColor: '#f59e0b',
            title,
            content: `<div class="modal-message">${message}</div>`,
            buttons: [
                { text: '取消', icon: 'times', class: 'secondary', onClick: () => this.close(false) },
                { text: '确定', icon: 'check', class: 'primary', onClick: () => this.close(true) }
            ]
        });
    }

    async prompt(message, title = '输入', placeholder = '') {
        const inputId = 'modal-prompt-input-' + Date.now();
        const result = await this.show({
            icon: 'edit',
            iconColor: '#2563eb',
            title,
            content: `<div class="modal-message">${message}</div><input type="text" id="${inputId}" class="modal-input" placeholder="${placeholder}" />`,
            buttons: [
                { text: '取消', icon: 'times', class: 'secondary', onClick: () => this.close(null) },
                { text: '确定', icon: 'check', class: 'primary', onClick: () => {
                    const input = document.getElementById(inputId);
                    this.close(input ? input.value : null);
                }}
            ]
        });

        setTimeout(() => {
            const input = document.getElementById(inputId);
            if (input) {
                input.focus();
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') this.close(input.value);
                });
            }
        }, 50);

        return result;
    }

    custom(options) {
        return this.show(options);
    }
}

// 紧凑精致样式
const modalStyle = document.createElement('style');
modalStyle.textContent = `
    .modal-container {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
    }

    .modal-box {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: scale(0.97) translate(-50%, -50%);
        background: var(--modal-bg, rgba(30, 30, 30, 0.96));
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border-radius: 12px;
        box-shadow:
            0 0 0 1px rgba(255, 255, 255, 0.08),
            0 4px 20px rgba(0, 0, 0, 0.3);
        padding: 18px 18px 16px;
        min-width: 260px;
        max-width: 340px;
        opacity: 0;
        transition: transform 0.15s ease, opacity 0.15s ease;
        pointer-events: auto;
    }

    .modal-icon {
        text-align: center;
        font-size: 28px;
        margin-bottom: 10px;
    }

    .modal-title {
        font-size: 15px;
        font-weight: 600;
        color: var(--modal-text, #ffffff);
        margin-bottom: 6px;
        text-align: center;
    }

    .modal-content {
        margin-bottom: 14px;
    }

    .modal-message {
        font-size: 13px;
        line-height: 1.5;
        color: var(--modal-text-muted, rgba(255, 255, 255, 0.7));
        text-align: center;
        white-space: pre-wrap;
        margin-bottom: 10px;
    }

    .modal-input {
        width: 100%;
        padding: 8px 10px;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 7px;
        color: #ffffff;
        font-size: 13px;
        outline: none;
        transition: all 0.15s ease;
        box-sizing: border-box;
    }

    .modal-input:focus {
        background: rgba(255, 255, 255, 0.1);
        border-color: #2563eb;
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
    }

    .modal-input::placeholder {
        color: rgba(255, 255, 255, 0.35);
    }

    .modal-footer {
        display: flex;
        gap: 8px;
        justify-content: center;
    }

    .modal-btn {
        flex: 1;
        padding: 8px 12px;
        border: none;
        border-radius: 7px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.1s ease;
        min-width: 70px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .modal-btn-primary {
        background: #2563eb;
        color: white;
    }

    .modal-btn-primary:hover {
        background: #1d4ed8;
    }

    .modal-btn-primary:active {
        transform: scale(0.97);
    }

    .modal-btn-secondary {
        background: rgba(255, 255, 255, 0.06);
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .modal-btn-secondary:hover {
        background: rgba(255, 255, 255, 0.1);
    }

    .modal-btn-secondary:active {
        transform: scale(0.97);
    }

    .modal-btn i {
        font-size: 11px;
    }

    /* 亮色模式 */
    body:not(.dark-mode) .modal-box {
        background: rgba(255, 255, 255, 0.96);
        box-shadow:
            0 0 0 1px rgba(0, 0, 0, 0.06),
            0 4px 20px rgba(0, 0, 0, 0.1);
    }

    body:not(.dark-mode) .modal-title {
        color: #1e293b;
    }

    body:not(.dark-mode) .modal-message {
        color: #64748b;
    }

    body:not(.dark-mode) .modal-input {
        background: #f8fafc;
        border-color: #e2e8f0;
        color: #1e293b;
    }

    body:not(.dark-mode) .modal-input:focus {
        background: #ffffff;
        border-color: #2563eb;
    }

    body:not(.dark-mode) .modal-input::placeholder {
        color: #94a3b8;
    }

    body:not(.dark-mode) .modal-btn-secondary {
        background: #f1f5f9;
        color: #475569;
        border-color: #e2e8f0;
    }

    body:not(.dark-mode) .modal-btn-secondary:hover {
        background: #e2e8f0;
    }

    /* 移动端 */
    @media (max-width: 768px) {
        .modal-box {
            min-width: 240px;
            max-width: 80vw;
            padding: 16px;
            border-radius: 14px;
        }

        .modal-icon {
            font-size: 26px;
        }

        .modal-title {
            font-size: 14px;
        }

        .modal-message {
            font-size: 13px;
        }

        .modal-input {
            padding: 8px 10px;
            font-size: 16px;
        }

        .modal-btn {
            padding: 9px 12px;
            font-size: 14px;
            min-width: 65px;
        }

        .modal-footer {
            gap: 7px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .modal-box, .modal-btn, .modal-input {
            transition: none !important;
        }
    }
`;
document.head.appendChild(modalStyle);

window.Modal = new Modal();
