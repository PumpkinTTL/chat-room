/**
 * 用户资料弹窗组件 - 独立逻辑
 * 完全独立的组件，不依赖主页面逻辑
 */

(function() {
    'use strict';

    // ==================== 组件状态 ====================
    const state = {
        visible: false,
        loading: false,
        userId: null,
        profile: {
            id: 0,
            nickname: '',
            avatar: '',
            sign: '',
            created_at: ''
        },
        stats: {
            message_count: 0,
            room_count: 0,
            join_date: ''
        }
    };

    // ==================== DOM 元素 ====================
    let container = null;
    let template = null;

    // ==================== 初始化组件 ====================
    async function init() {
        // 加载样式
        await loadStyles();

        // 加载模板
        await loadTemplate();

        // 渲染到页面
        render();

        // 绑定全局方法
        exposeGlobalMethods();

        console.log('[UserProfile] 用户资料组件初始化完成');
    }

    // ==================== 加载样式 ====================
    function loadStyles() {
        return new Promise(function(resolve, reject) {
            // 检查是否已加载
            const existingLink = document.getElementById('user-profile-styles');
            if (existingLink) {
                resolve();
                return;
            }

            const link = document.createElement('link');
            link.id = 'user-profile-styles';
            link.rel = 'stylesheet';
            link.href = '/static/css/components/user-profile.css?v=3';

            link.onload = resolve;
            link.onerror = reject;

            document.head.appendChild(link);
        });
    }

    // ==================== 加载模板 ====================
    async function loadTemplate() {
        try {
            const response = await fetch('/static/templates/user-profile.html?v=3');
            if (!response.ok) {
                throw new Error('Failed to load template');
            }
            template = await response.text();
        } catch (error) {
            console.error('[UserProfile] 模板加载失败:', error);
            // 使用内联模板作为备用
            template = getFallbackTemplate();
        }
    }

    // ==================== 暆用模板 ====================
    function getFallbackTemplate() {
        return `
        <div class="user-profile-overlay" id="user-profile-overlay" style="display: none;">
            <div class="user-profile-modal">
                <button class="user-profile-close" onclick="UserProfile.close()">
                    <i class="fas fa-times"></i>
                </button>

                <div class="user-profile-header">
                    <div class="user-profile-avatar-large" id="profile-avatar-container">
                        <span id="profile-avatar-text">?</span>
                        <button class="user-profile-avatar-edit" onclick="UserProfile.selectAvatar()" title="更换头像">
                            <i class="fas fa-camera"></i>
                        </button>
                    </div>
                    <h2 class="user-profile-name" id="profile-name">加载中...</h2>
                    <p class="user-profile-id" id="profile-id">ID: -</p>
                </div>

                <div class="user-profile-content" id="profile-content">
                    <!-- 动态内容 -->
                </div>

                <!-- 隐藏的文件输入 -->
                <input type="file" id="avatar-input" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" style="display: none;" />
            </div>
        </div>
        `;
    }

    // ==================== 渲染组件 ====================
    function render() {
        // 创建容器
        container = document.createElement('div');
        container.id = 'user-profile-container';
        container.innerHTML = template;
        document.body.appendChild(container);
    }

    // ==================== 暴露全局方法 ====================
    function exposeGlobalMethods() {
        window.UserProfile = {
            open: open,
            close: close,
            update: updateProfile,
            selectAvatar: selectAvatar
        };
    }

    // ==================== 打开弹窗 ====================
    async function open(userId) {
        if (!userId) {
            console.error('[UserProfile] 用户ID不能为空');
            return;
        }

        state.visible = true;
        state.userId = userId;
        state.loading = true;

        // 显示弹窗
        const overlay = document.getElementById('user-profile-overlay');
        if (overlay) {
            overlay.style.display = 'flex';
            renderLoading();
        }

        // 绑定文件输入事件
        bindAvatarUpload();

        // 加载用户数据
        await loadUserProfile(userId);
    }

    // ==================== 绑定头像上传事件 ====================
    function bindAvatarUpload() {
        const input = document.getElementById('avatar-input');
        if (!input) return;

        // 移除旧的事件监听器
        input.onchange = null;

        // 绑定新的事件监听器
        input.onchange = async function(e) {
            const file = e.target.files[0];
            if (!file) return;

            await uploadAvatar(file);

            // 清空输入，允许再次选择同一文件
            e.target.value = '';
        };
    }

    // ==================== 关闭弹窗 ====================
    function close() {
        state.visible = false;
        state.userId = null;

        const overlay = document.getElementById('user-profile-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    }

    // ==================== 加载用户资料 ====================
    async function loadUserProfile(userId) {
        try {
            const response = await apiRequest('/api/user/profile?user_id=' + userId);
            const result = await response.json();

            if (result.code === 0) {
                state.profile = result.data.profile || {};
                state.stats = result.data.stats || {};
                state.loading = false;
                renderProfile();
            } else {
                throw new Error(result.msg || '加载失败');
            }
        } catch (error) {
            console.error('[UserProfile] 加载用户资料失败:', error);
            state.loading = false;
            renderError(error.message);
        }
    }

    // ==================== 渲染加载状态 ====================
    function renderLoading() {
        const content = document.getElementById('profile-content');
        if (!content) return;

        content.innerHTML = `
            <div class="user-profile-loading">
                <div class="user-profile-spinner"></div>
                <p class="user-profile-loading-text">加载中...</p>
            </div>
        `;
    }

    // ==================== 渲染用户资料 ====================
    function renderProfile() {
        const content = document.getElementById('profile-content');
        const nameEl = document.getElementById('profile-name');
        const idEl = document.getElementById('profile-id');
        const avatarText = document.getElementById('profile-avatar-text');
        const avatarContainer = document.getElementById('profile-avatar-container');

        if (!content) return;

        // 更新头部信息
        if (nameEl) nameEl.textContent = state.profile.nickname || '未知用户';
        if (idEl) idEl.textContent = 'ID: ' + state.profile.id;

        // 更新头像显示（检查空值、null、none、空字符串）
        const avatar = state.profile.avatar;
        const hasAvatar = avatar && avatar !== 'null' && avatar !== 'none' && avatar !== '';

        if (hasAvatar) {
            if (avatarContainer) {
                avatarContainer.style.backgroundImage = `url(${avatar})`;
                avatarContainer.style.backgroundSize = 'cover';
                avatarContainer.style.backgroundPosition = 'center';
            }
            if (avatarText) avatarText.style.display = 'none';
        } else {
            // 清除背景图，显示昵称首字母
            if (avatarContainer) {
                avatarContainer.style.backgroundImage = '';
            }
            if (avatarText) {
                avatarText.style.display = 'flex';
                avatarText.textContent = (state.profile.nickname || '?').charAt(0).toUpperCase();
            }
        }

        // 渲染内容
        content.innerHTML = `
            <div class="user-profile-stats">
                <div class="user-profile-stat-item">
                    <div class="user-profile-stat-value">${state.stats.message_count || 0}</div>
                    <div class="user-profile-stat-label">消息数</div>
                </div>
                <div class="user-profile-stat-item">
                    <div class="user-profile-stat-value">${state.stats.room_count || 0}</div>
                    <div class="user-profile-stat-label">房间数</div>
                </div>
                <div class="user-profile-stat-item">
                    <div class="user-profile-stat-value">${formatJoinDate(state.stats.join_date)}</div>
                    <div class="user-profile-stat-label">加入时间</div>
                </div>
            </div>

            <div class="user-profile-section">
                <h3 class="user-profile-section-title">基本信息</h3>

                <div class="user-profile-form-group">
                    <label class="user-profile-label">昵称</label>
                    <input type="text" class="user-profile-input" id="edit-nickname" value="${state.profile.nickname || ''}" placeholder="请输入昵称" />
                </div>

                <div class="user-profile-form-group">
                    <label class="user-profile-label">个性签名</label>
                    <textarea class="user-profile-input user-profile-textarea" id="edit-sign" placeholder="介绍一下自己...">${state.profile.sign || ''}</textarea>
                </div>
            </div>

            <div class="user-profile-actions">
                <button class="user-profile-btn user-profile-btn-secondary" onclick="UserProfile.close()">
                    <i class="fas fa-times"></i>
                    <span>取消</span>
                </button>
                <button class="user-profile-btn user-profile-btn-primary" onclick="UserProfile.update()">
                    <i class="fas fa-save"></i>
                    <span>保存修改</span>
                </button>
            </div>
        `;
    }

    // ==================== 渲染错误状态 ====================
    function renderError(message) {
        const content = document.getElementById('profile-content');
        if (!content) return;

        content.innerHTML = `
            <div class="user-profile-empty">
                <div class="user-profile-empty-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <p class="user-profile-empty-text">${message || '加载失败，请重试'}</p>
            </div>
        `;
    }

    // ==================== 更新用户资料 ====================
    async function updateProfile() {
        const nickname = document.getElementById('edit-nickname').value.trim();
        const sign = document.getElementById('edit-sign').value.trim();

        if (!nickname) {
            window.Toast?.error('请输入昵称');
            return;
        }

        try {
            const response = await apiRequest('/api/user/update', {
                method: 'POST',
                body: JSON.stringify({
                    user_id: state.userId,
                    nickname: nickname,
                    sign: sign
                })
            });

            const result = await response.json();

            if (result.code === 0) {
                window.Toast?.success('保存成功');

                // 更新本地状态
                state.profile.nickname = nickname;
                state.profile.sign = sign;

                // 刷新显示
                renderProfile();

                // 延迟1秒后刷新页面
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                throw new Error(result.msg || '保存失败');
            }
        } catch (error) {
            console.error('[UserProfile] 保存失败:', error);
            window.Toast?.error(error.message || '保存失败，请重试');
        }
    }

    // ==================== 选择头像 ====================
    function selectAvatar() {
        const input = document.getElementById('avatar-input');
        if (input) {
            input.click();
        }
    }

    // ==================== 上传头像 ====================
    async function uploadAvatar(file) {
        // 验证文件类型
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            window.Toast?.error('只支持 JPG、PNG、GIF、WebP 格式的图片');
            return;
        }

        // 验证文件大小（5MB）
        const maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) {
            window.Toast?.error('图片大小不能超过5MB');
            return;
        }

        // 显示上传提示
        window.Toast?.info('正在上传...');

        try {
            const formData = new FormData();
            formData.append('avatar', file);

            const response = await apiRequest('/api/user/uploadAvatar', {
                method: 'POST',
                body: formData,
                headers: {} // 让浏览器自动设置 Content-Type
            });

            const result = await response.json();

            if (result.code === 0) {
                window.Toast?.success('头像上传成功');

                // 更新本地状态
                state.profile.avatar = result.data.avatar;

                // 更新显示
                renderProfile();

                // 延迟1秒后刷新页面
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                throw new Error(result.msg || '上传失败');
            }
        } catch (error) {
            console.error('[UserProfile] 上传头像失败:', error);
            window.Toast?.error(error.message || '上传失败，请重试');
        }
    }

    // ==================== 工具函数 ====================

    // 格式化加入日期
    function formatJoinDate(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        const now = new Date();
        const diff = now - date;
        const days = Math.floor(diff / (1000 * 60 * 60 * 24));

        if (days < 30) {
            return days + '天';
        } else if (days < 365) {
            return Math.floor(days / 30) + '月';
        } else {
            return Math.floor(days / 365) + '年';
        }
    }

    // API 请求辅助函数
    async function apiRequest(url, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include'
        };

        const finalOptions = Object.assign({}, defaultOptions, options);

        // 如果有 body 且不是 FormData，添加 CSRF token
        if (finalOptions.body && typeof finalOptions.body === 'string') {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (csrfToken) {
                finalOptions.headers['X-CSRF-TOKEN'] = csrfToken;
            }
        }

        return fetch(url, finalOptions);
    }

    // ==================== 自动初始化 ====================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
