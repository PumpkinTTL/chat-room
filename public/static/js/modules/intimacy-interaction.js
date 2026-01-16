/**
 * ==================== 亲密互动条模块 ====================
 *
 * 功能：私密房间双人同时在线时，60秒内头像靠拢获得好感度
 *
 * 使用方式：
 * ChatApp.IntimacyInteraction.init(options)
 * ChatApp.IntimacyInteraction.start()
 * ChatApp.IntimacyInteraction.stop()
 * ChatApp.IntimacyInteraction.collectExp()
 */

(function(global) {
    'use strict';

    global.ChatApp = global.ChatApp || {};

    /**
     * 亲密互动条管理器
     */
    global.ChatApp.IntimacyInteraction = {
        // 配置
        config: {
            duration: 60, // 动画时长（秒）
            updateInterval: 100, // 更新间隔（毫秒）
            collectCooldown: 300000, // 领取冷却时间（5分钟）
        },

        // 状态
        state: {
            isActive: false,
            isRunning: false,
            isCompleted: false,
            currentTime: 0,
            startTime: null,  // 互动开始时间戳（用于计算进度）
            timer: null,
            lastCollectTime: 0,
        },

        // Vue响应式引用（由外部传入）
        refs: {
            showInteractionBar: null,
            interactionProgress: null,
            interactionCountdown: null,
            interactionCompleted: null,
            currentUser: null,
            onlineUsers: null,
            roomId: null,
            wsClient: null,  // WebSocket客户端，用于发送消息
        },

        // DOM元素缓存
        elements: {
            bar: null,
            leftUser: null,
            rightUser: null,
            progressFill: null,
            countdown: null,
            centerHeart: null,
        },

        // 用户数据
        userData: {
            local: null,
            partner: null,
        },

        /**
         * 初始化互动条
         * @param {Object} options - 配置选项
         * @param {Object} options.refs - Vue响应式引用
         * @param {Function} options.apiRequest - API请求函数
         */
        init: function(options) {
            console.log('[亲密互动] init 接收到的 options:', options);

            // 保存配置
            if (options.refs) {
                this.refs = options.refs;
            }
            this.apiRequest = options.apiRequest;

            console.log('[亲密互动] roomId ref 对象已设置:', this.refs.roomId);

            // 监听在线用户变化
            if (this.refs.onlineUsers) {
                // 使用Vue的watch监听（在index.js中设置）
            }

            console.log('[亲密互动] 初始化完成');
        },

        /**
         * 检查是否应该显示互动条
         * @returns {boolean}
         */
        shouldShow: function() {
            // 检查是否两人同时在线
            const onlineCount = this.refs.onlineUsers?.value || 0;
            return onlineCount >= 2;
        },

        /**
         * 检查是否在冷却中（已移除冷却限制）
         * @returns {boolean}
         */
        isInCooldown: function() {
            // 不再使用冷却时间
            return false;
        },

        /**
         * 获取剩余冷却时间（已移除冷却限制）
         * @returns {number}
         */
        getCooldownRemaining: function() {
            // 不再使用冷却时间
            return 0;
        },

        /**
         * 开始互动（由WebSocket服务器端控制时间）
         */
        start: function() {
            if (this.state.isRunning) {
                console.warn('[亲密互动] 已经在运行中，跳过启动');
                return;
            }

            if (this.state.isCompleted) {
                console.warn('[亲密互动] 已完成，跳过启动');
                return;
            }

            console.log('[亲密互动] 开始互动');
            console.log('[亲密互动] 当前状态:', this.state);

            // 先清除之前的定时器（如果有）
            if (this.state.timer) {
                clearInterval(this.state.timer);
                this.state.timer = null;
            }

            this.state.isActive = true;
            this.state.isRunning = true;
            this.state.currentTime = 0;
            this.state.startTime = Date.now();  // 记录开始时间戳

            // 更新Vue状态
            if (this.refs.showInteractionBar) {
                this.refs.showInteractionBar.value = true;
                console.log('[亲密互动] showInteractionBar 设置为 true');
            }

            // 缓存DOM元素
            this.cacheElements();

            // 设置用户信息
            this.setupUsers();

            // 启动本地定时器来计算进度
            this.startTimer();
            console.log('[亲密互动] 定时器已启动');

            // 触发头像移动动画
            this.triggerAvatarAnimation();
        },

        /**
         * 停止互动
         */
        stop: function() {
            console.log('[亲密互动] 停止互动');

            this.state.isRunning = false;
            this.state.isActive = false;

            // 清除定时器
            if (this.state.timer) {
                clearInterval(this.state.timer);
                this.state.timer = null;
                console.log('[亲密互动] 定时器已清除');
            }

            // 移除动画类
            this.removeAnimationClasses();
        },

        /**
         * 完成互动（由WebSocket回调触发）
         */
        complete: function() {
            console.log('[亲密互动] 完成互动');

            this.state.isRunning = false;
            this.state.isCompleted = true;

            // 清除定时器
            if (this.state.timer) {
                clearInterval(this.state.timer);
                this.state.timer = null;
            }

            // 更新进度为100%
            if (this.refs.interactionProgress) {
                this.refs.interactionProgress.value = 100;
            }

            // 确保 DOM 元素已缓存（关键修复：防止波纹效果不触发）
            if (!this.elements.bar) {
                this.cacheElements();
            }

            // 触发完成特效
            this.triggerCompleteEffect();

            // 自动延迟领取好感度（2秒后）- 由WebSocket回调处理
            // setTimeout(() => {
            //     this.collectExp();
            // }, 2000);
        },

        /**
         * 重置互动状态
         */
        reset: function() {
            console.log('[亲密互动] 重置状态，当前状态:', this.state);

            this.state.isActive = false;
            this.state.isRunning = false;
            this.state.isCompleted = false;
            this.state.currentTime = 0;

            // 清除定时器（重要！确保停止计时）
            if (this.state.timer) {
                clearInterval(this.state.timer);
                this.state.timer = null;
                console.log('[亲密互动] reset: 定时器已清除');
            }

            // 重置Vue状态
            if (this.refs.interactionProgress) {
                this.refs.interactionProgress.value = 0;
                console.log('[亲密互动] reset: interactionProgress 已重置为 0');
            }
            if (this.refs.interactionCountdown) {
                this.refs.interactionCountdown.value = this.config.duration;
                console.log('[亲密互动] reset: interactionCountdown 已重置为', this.config.duration);
            }
            if (this.refs.interactionCompleted) {
                this.refs.interactionCompleted.value = false;
                console.log('[亲密互动] reset: interactionCompleted 已重置为 false');
            }

            // 清除DOM特效
            this.clearEffects();
            this.removeAnimationClasses();

            console.log('[亲密互动] reset 完成，新状态:', this.state);
        },

        /**
         * 缓存DOM元素
         */
        cacheElements: function() {
            const bar = document.querySelector('.intimacy-interaction-bar');
            if (!bar) return;

            this.elements = {
                bar: bar,
                leftUser: bar.querySelector('.intimacy-user-section.left'),
                rightUser: bar.querySelector('.intimacy-user-section.right'),
                progressFill: bar.querySelector('.intimacy-progress-fill'),
                countdown: bar.querySelector('.intimacy-countdown-value'),
                centerHeart: bar.querySelector('.intimacy-center-heart'),
            };
        },

        /**
         * 设置用户信息
         */
        setupUsers: function() {
            if (!this.refs.currentUser) return;

            const currentUser = this.refs.currentUser.value;

            // 本地用户
            this.userData.local = {
                id: currentUser.id,
                name: currentUser.nick_name,
                avatar: currentUser.avatar,
            };

            // 伴侣用户（从在线用户列表中获取）
            // 这里需要外部传入完整的在线用户列表数据
        },

        /**
         * 开始倒计时（基于本地时间戳计算，减少误差）
         */
        startTimer: function() {
            const self = this;

            this.state.timer = setInterval(function() {
                // 检查是否还在运行（如果已停止，清除定时器）
                if (!self.state.isRunning || !self.state.isActive) {
                    if (self.state.timer) {
                        clearInterval(self.state.timer);
                        self.state.timer = null;
                        console.log('[亲密互动] 定时器检测到非运行状态，已自动清除');
                    }
                    return;
                }

                // 基于开始时间戳计算已过时间（毫秒）
                const elapsedMs = Date.now() - self.state.startTime;
                const elapsedSec = elapsedMs / 1000;

                // 计算进度
                const progress = (elapsedSec / self.config.duration) * 100;

                // 更新Vue状态
                if (self.refs.interactionProgress) {
                    self.refs.interactionProgress.value = Math.min(100, progress);
                }
                if (self.refs.interactionCountdown) {
                    const remaining = Math.max(0, self.config.duration - elapsedSec);
                    self.refs.interactionCountdown.value = Math.ceil(remaining);
                }

                // 更新DOM
                self.updateProgressDOM(progress);
                self.updateCountdownDOM();

                // 检查是否完成（给服务器一点时间延迟，避免提前完成）
                if (elapsedSec >= self.config.duration) {
                    // 等待服务器发送 complete 消息
                    // 如果服务器消息延迟，这里也会等待
                }
            }, this.config.updateInterval);
        },

        /**
         * 更新进度条DOM
         * @param {number} progress - 进度百分比
         */
        updateProgressDOM: function(progress) {
            if (this.elements.progressFill) {
                this.elements.progressFill.style.width = progress + '%';
            }
        },

        /**
         * 更新倒计时DOM
         */
        updateCountdownDOM: function() {
            if (!this.elements.countdown || !this.refs.interactionCountdown) return;

            const remaining = this.refs.interactionCountdown.value;
            const countdownEl = this.elements.countdown;
            const wrapperEl = countdownEl.closest('.intimacy-countdown');

            countdownEl.textContent = Math.ceil(remaining);

            // 添加紧急状态样式
            if (remaining <= 10 && wrapperEl) {
                wrapperEl.classList.add('urgent');
            } else if (wrapperEl) {
                wrapperEl.classList.remove('urgent');
            }
        },

        /**
         * 触发头像移动动画
         * 注：头像移动现在由WebSocket进度回调动态控制
         */
        triggerAvatarAnimation: function() {
            // PC端才启用移动动画
            const isMobile = window.innerWidth < 768;
            if (isMobile) return;

            // 添加脉冲动画类
            if (this.elements.leftUser) {
                this.elements.leftUser.classList.add('is-moving');
            }
            if (this.elements.rightUser) {
                this.elements.rightUser.classList.add('is-moving');
            }

            // 激活中心爱心
            const progressSection = document.querySelector('.intimacy-progress-section');
            if (progressSection) {
                progressSection.classList.add('active');
            }
        },

        /**
         * 移除动画类
         */
        removeAnimationClasses: function() {
            if (this.elements.leftUser) {
                this.elements.leftUser.classList.remove('is-moving');
                this.elements.leftUser.style.transform = '';
            }
            if (this.elements.rightUser) {
                this.elements.rightUser.classList.remove('is-moving');
                this.elements.rightUser.style.transform = '';
            }

            const progressSection = document.querySelector('.intimacy-progress-section');
            if (progressSection) {
                progressSection.classList.remove('active');
            }
        },

        /**
         * 触发完成特效
         */
        triggerCompleteEffect: function() {
            // 确保 DOM 元素已缓存，如果没有则重新缓存
            if (!this.elements.bar) {
                this.cacheElements();
            }
            
            const bar = this.elements.bar;
            if (!bar) {
                console.warn('[亲密互动] triggerCompleteEffect: 无法找到互动条元素');
                return;
            }

            // 标记完成状态
            const progressSection = bar.querySelector('.intimacy-progress-section');
            if (progressSection) {
                progressSection.classList.add('completed');
            }

            // 创建碰撞特效
            this.createCollisionEffect();
        },

        /**
         * 创建碰撞特效
         */
        createCollisionEffect: function() {
            // 确保 DOM 元素已缓存，如果没有则重新缓存
            if (!this.elements.bar) {
                this.cacheElements();
            }
            
            const bar = this.elements.bar;
            if (!bar) {
                console.warn('[亲密互动] createCollisionEffect: 无法找到互动条元素');
                return;
            }

            const effectContainer = document.createElement('div');
            effectContainer.className = 'intimacy-collision-effect';

            // 添加光环
            const ripple1 = document.createElement('div');
            ripple1.className = 'intimacy-ripple';
            effectContainer.appendChild(ripple1);

            const ripple2 = document.createElement('div');
            ripple2.className = 'intimacy-ripple';
            ripple2.style.animationDelay = '0.2s';
            effectContainer.appendChild(ripple2);

            // 添加爱心粒子
            for (let i = 0; i < 8; i++) {
                const particle = document.createElement('div');
                particle.className = 'intimacy-heart-particle';
                particle.innerHTML = '<i class="fas fa-heart"></i>';

                const angle = (i / 8) * 360 * (Math.PI / 180);
                const distance = 60 + Math.random() * 40;
                particle.style.setProperty('--tx', Math.cos(angle) * distance + 'px');
                particle.style.setProperty('--ty', Math.sin(angle) * distance + 'px');
                particle.style.animationDelay = (Math.random() * 0.3) + 's';

                effectContainer.appendChild(particle);
            }

            bar.appendChild(effectContainer);

            // 清理特效元素
            setTimeout(() => {
                effectContainer.remove();
            }, 2000);
        },

        /**
         * 清除DOM特效
         */
        clearEffects: function() {
            const effects = document.querySelectorAll('.intimacy-collision-effect');
            effects.forEach(el => el.remove());

            const toasts = document.querySelectorAll('.intimacy-exp-gain-toast');
            toasts.forEach(el => el.remove());

            const progressSection = document.querySelector('.intimacy-progress-section');
            if (progressSection) {
                progressSection.classList.remove('completed');
            }
        },

        /**
         * 领取好感度
         */
        collectExp: async function() {
            if (!this.state.isCompleted) {
                console.warn('[亲密互动] 互动未完成，无法领取');
                return;
            }

            // 从 ref 对象获取当前房间ID
            const roomIdValue = this.refs.roomId ? this.refs.roomId.value : null;

            console.log('[亲密互动] 领取好感度, roomId.value:', roomIdValue, '类型:', typeof roomIdValue);

            if (!roomIdValue) {
                console.error('[亲密互动] 房间ID为空，无法领取');
                this.showErrorToast('房间ID为空，请刷新页面重试');
                return;
            }

            try {
                const roomIdInt = parseInt(roomIdValue);
                console.log('[亲密互动] 解析后 roomId:', roomIdInt, '类型:', typeof roomIdInt);

                // 调用后端API领取好感度（使用 cookie 认证）
                const response = await fetch('/api/intimacy/collect', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        room_id: roomIdInt,
                    }),
                });

                // 处理401错误
                if (response.status === 401) {
                    console.error('[亲密互动] 登录已过期');
                    if (global.Toast && global.Toast.error) {
                        global.Toast.error('登录已过期，请重新登录');
                    }
                    setTimeout(function() {
                        window.location.href = '/login';
                    }, 1500);
                    return;
                }

                const result = await response.json();

                if (result.code === 0) {
                    // 领取成功
                    this.showExpGainToast(result.data.exp_gain || 10);

                    // 通知好感度系统更新
                    if (result.data.intimacy && this.onIntimacyUpdate) {
                        this.onIntimacyUpdate(result.data.intimacy);
                    }

                    // 标记为已领取
                    if (this.refs.interactionCollected) {
                        this.refs.interactionCollected.value = true;
                    }

                    // 发送消息给服务器，请求重新开始互动
                    const ws = this.refs.wsClient ? this.refs.wsClient.value : null;
                    if (ws && ws.ws && ws.ws.readyState === WebSocket.OPEN) {
                        try {
                            ws.send({
                                type: 'intimacy_restart',
                                room_id: roomIdInt
                            });
                            console.log('[亲密互动] 已发送重新开始请求');
                        } catch (e) {
                            console.error('[亲密互动] 发送重新开始请求失败:', e);
                        }
                    } else {
                        console.warn('[亲密互动] WebSocket未连接，无法发送重新开始请求');
                    }

                    // 不在这里重置，等待服务器发送 intimacy_reset 和 intimacy_start 消息
                } else {
                    console.error('[亲密互动] 领取失败:', result.msg);
                    this.showErrorToast(result.msg || '领取失败');
                }
            } catch (error) {
                console.error('[亲密互动] 领取异常:', error);
                this.showErrorToast('网络异常，请重试');
            }
        },

        /**
         * 显示好感度获得提示
         * @param {number} expGain - 获得的经验值
         */
        showExpGainToast: function(expGain) {
            const bar = this.elements.bar;
            if (!bar) return;

            const toast = document.createElement('div');
            toast.className = 'intimacy-exp-gain-toast';
            toast.innerHTML = `<i class="fas fa-heart"></i> +${expGain} 好感度`;

            bar.appendChild(toast);

            setTimeout(() => {
                toast.remove();
            }, 3000);
        },

        /**
         * 显示错误提示
         * @param {string} message - 错误消息
         */
        showErrorToast: function(message) {
            // 使用全局Toast
            if (global.toast && global.toast.error) {
                global.toast.error(message);
            }
        },

        /**
         * 处理在线用户变化
         * @param {number} onlineCount - 在线用户数量
         */
        handleOnlineUsersChange: function(onlineCount) {
            console.log('[亲密互动] 在线用户变化:', onlineCount);

            // 如果互动正在进行中，但用户下线了，停止互动
            if (this.state.isRunning && onlineCount < 2) {
                this.stop();
                this.reset();
            }

            // 如果两人同时在线，且不在冷却中，自动开始
            if (onlineCount >= 2 && !this.state.isRunning && !this.isInCooldown()) {
                this.start();
            }
        },

        /**
         * 设置好感度更新回调
         * @param {Function} callback - 回调函数
         */
        setIntimacyUpdateCallback: function(callback) {
            this.onIntimacyUpdate = callback;
        },

        /**
         * 销毁互动条
         */
        destroy: function() {
            this.stop();
            this.clearEffects();
            this.state = {
                isActive: false,
                isRunning: false,
                isCompleted: false,
                currentTime: 0,
                timer: null,
                lastCollectTime: this.state.lastCollectTime,
            };
        },
    };

    console.log('[亲密互动] 模块加载完成');

})(window);
