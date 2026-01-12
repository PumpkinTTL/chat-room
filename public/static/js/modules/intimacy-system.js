/**
 * 好感度系统模块（私密房间专用）
 * 负责好感度加载、更新、进度计算、升级提示、特效动画
 */
(function(global) {
    'use strict';
    
    global.ChatApp = global.ChatApp || {};
    
    /**
     * 好感度系统管理器
     */
    global.ChatApp.IntimacySystem = {
        // 等级经验映射表
        levelExpMap: {
            1: 0,
            2: 500,
            3: 1500,
            4: 3000,
            5: 5000,
            6: 8000,
            7: 12000,
            8: 18000
        },

        /**
         * 加载好感度信息
         * @param {string} roomId - 房间ID
         * @param {Function} apiRequest - API请求函数
         * @param {Object} intimacyInfo - 好感度信息响应式对象
         * @returns {Promise<void>}
         */
        loadIntimacyInfo: async function(roomId, apiRequest, intimacyInfo) {
            try {
                const response = await apiRequest(`/api/intimacy/info/${roomId}`);
                const result = await response.json();
                
                if (result.code === 0) {
                    intimacyInfo.value = result.data;
                }
            } catch (error) {
                console.error('[好感度] 加载失败:', error);
            }
        },

        /**
         * 处理好感度更新
         * @param {Object} intimacyData - 好感度更新数据
         * @param {Object} intimacyInfo - 好感度信息响应式对象
         * @param {Object} roomId - 房间ID响应式对象
         * @param {Function} apiRequest - API请求函数
         * @param {Object} refs - 其他需要的响应式引用
         */
        handleIntimacyUpdate: function(intimacyData, intimacyInfo, roomId, apiRequest, refs) {
            if (!intimacyData || intimacyData.code !== 0) return;
            
            const data = intimacyData.data;
            if (!data) return;
            
            const self = this;
            
            // 如果intimacyInfo还未初始化，先加载
            if (!intimacyInfo.value) {
                this.loadIntimacyInfo(roomId.value, apiRequest, intimacyInfo);
                return;
            }
            
            // 增量更新：只增加本次获得的经验
            if (data.exp_gain) {
                intimacyInfo.value.current_exp = (intimacyInfo.value.current_exp || 0) + data.exp_gain;
                
                // 显示经验获得提示
                this.showExpGainToast(data.exp_gain, refs.showExpToast);
            }
            
            // 消息数+1（实时统计）
            intimacyInfo.value.total_messages = (intimacyInfo.value.total_messages || 0) + 1;
            
            // 记录当前等级
            const oldLevel = intimacyInfo.value.current_level;
            
            // 重新加载完整信息（会自动计算新等级）
            this.loadIntimacyInfo(roomId.value, apiRequest, intimacyInfo).then(function() {
                // 检查是否升级
                if (intimacyInfo.value.current_level > oldLevel) {
                    self.showLevelUpToast(intimacyInfo.value.level_name, intimacyInfo.value.current_level, intimacyInfo);
                }
                self.updateIntimacyProgress(intimacyInfo);
            });
        },

        /**
         * 更新好感度进度条
         * @param {Object} intimacyInfo - 好感度信息响应式对象
         */
        updateIntimacyProgress: function(intimacyInfo) {
            if (!intimacyInfo.value || !intimacyInfo.value.next_level_exp) return;
            
            const currentExp = intimacyInfo.value.current_exp || 0;
            const currentLevel = intimacyInfo.value.current_level || 1;
            const nextLevelExp = intimacyInfo.value.next_level_exp;
            
            const currentLevelStartExp = this.levelExpMap[currentLevel] || 0;
            const expInCurrentLevel = currentExp - currentLevelStartExp;
            const expNeededForNext = nextLevelExp - currentLevelStartExp;
            
            const progressPercent = Math.min(100, Math.max(0, (expInCurrentLevel / expNeededForNext) * 100));
            intimacyInfo.value.progress_percent = progressPercent.toFixed(1);
        },

        /**
         * 显示升级提示 - 高级灵动版
         * @param {string} levelName - 等级名称
         * @param {number} level - 等级数字
         * @param {Object} intimacyInfo - 好感度信息响应式对象
         */
        showLevelUpToast: function(levelName, level, intimacyInfo) {
            const modal = document.createElement('div');
            modal.className = 'level-up-modal';
            
            // 获取当前等级颜色
            const levelColor = intimacyInfo.value?.level_color || '#ec4899';
            
            modal.innerHTML = `
                <div class="level-up-overlay"></div>
                <div class="level-up-card" style="--intimacy-color: ${levelColor}">
                    <button class="level-up-close-btn">
                        <i class="fas fa-times"></i>
                    </button>
                    
                    <!-- 装饰元素 -->
                    <div class="level-up-decorations">
                        <div class="decoration-circle decoration-1" style="background: ${levelColor}"></div>
                        <div class="decoration-circle decoration-2" style="background: ${levelColor}"></div>
                        <div class="decoration-circle decoration-3" style="background: ${levelColor}"></div>
                        <div class="decoration-star decoration-star-1">✨</div>
                        <div class="decoration-star decoration-star-2">✨</div>
                        <div class="decoration-star decoration-star-3">⭐</div>
                        <div class="decoration-heart decoration-heart-1" style="color: ${levelColor}">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div class="decoration-heart decoration-heart-2" style="color: ${levelColor}">
                            <i class="fas fa-heart"></i>
                        </div>
                    </div>
                    
                    <!-- 主内容 -->
                    <div class="level-up-content">
                        <div class="level-up-icon-wrapper">
                            <div class="icon-ring icon-ring-1" style="border-color: ${levelColor}40"></div>
                            <div class="icon-ring icon-ring-2" style="border-color: ${levelColor}60"></div>
                            <div class="icon-bg" style="background: ${levelColor}15"></div>
                            <i class="fas fa-heart level-up-icon" style="color: ${levelColor}"></i>
                        </div>
                        
                        <div class="level-up-badge-wrapper">
                            <div class="badge-glow" style="background: ${levelColor}"></div>
                            <div class="level-up-badge" style="background: ${levelColor}">
                                <span class="badge-text">Lv.${level}</span>
                            </div>
                        </div>
                        
                        <h2 class="level-up-title">
                            <span class="title-icon">🎉</span>
                            亲密等级提升
                            <span class="title-icon">🎉</span>
                        </h2>
                        
                        <div class="level-up-name" style="color: ${levelColor}">${levelName}</div>
                        
                        <p class="level-up-desc">
                            <i class="fas fa-heart-circle"></i>
                            我们的关系更进一步啦
                        </p>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // 点击关闭按钮
            const closeBtn = modal.querySelector('.level-up-close-btn');
            closeBtn.addEventListener('click', function() {
                modal.classList.add('level-up-hiding');
                setTimeout(function() {
                    if (modal.parentNode) {
                        document.body.removeChild(modal);
                    }
                }, 400);
            });
            
            // 点击遮罩关闭
            const overlay = modal.querySelector('.level-up-overlay');
            overlay.addEventListener('click', function() {
                closeBtn.click();
            });
        },

        /**
         * 显示经验获得提示
         * @param {number} expGain - 获得的经验值
         * @param {Object} showExpToast - 是否显示经验提示的响应式对象
         */
        showExpGainToast: function(expGain, showExpToast) {
            // 检查是否开启经验提示
            if (!showExpToast.value) return;
            
            const toast = document.createElement('div');
            toast.className = 'exp-gain-toast';
            toast.innerHTML = `<i class="fas fa-heart"></i> +${expGain} 经验`;
            document.body.appendChild(toast);
            
            setTimeout(function () {
                if (toast.parentNode) {
                    document.body.removeChild(toast);
                }
            }, 2000);
        },

        /**
         * 触发爱心飘动动画（私密房间发送消息时）
         * @param {Object} refs - 需要的响应式引用
         */
        triggerFloatingHearts: function(refs) {
            // 通过改变key强制重新渲染动画元素
            refs.heartsAnimationKey.value++;
            refs.showFloatingHearts.value = true;
            setTimeout(function () {
                refs.showFloatingHearts.value = false;
            }, 1600);
        },

        /**
         * 触发羁绊上线特效
         * @param {Object} user1 - 用户1信息 {nick_name, avatar}
         * @param {Object} user2 - 用户2信息 {nick_name, avatar}
         * @param {Object} showBondOnlineEffect - 是否显示特效的响应式对象
         */
        triggerBondOnlineEffect: function(user1, user2, showBondOnlineEffect) {
            if (!showBondOnlineEffect.value) return;
            
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || window.innerWidth < 768;
            
            const container = document.createElement('div');
            container.className = 'bond-notification-container';
            
            const notification = document.createElement('div');
            notification.className = 'bond-online-notification';
            
            // 构建头像HTML
            const avatarHTML = function(user) {
                return user.avatar 
                    ? `<img src="${user.avatar}" alt="${user.nick_name}" class="bond-avatar">` 
                    : `<div class="bond-avatar bond-avatar-placeholder">${user.nick_name.charAt(0)}</div>`;
            };
            
            notification.innerHTML = `
                <div class="bond-card">
                    <div class="bond-particles">
                        <div class="bond-ring"></div>
                        <div class="bond-ring"></div>
                        <div class="bond-ring"></div>
                    </div>
                    <div class="bond-title-wrapper">
                        <div class="bond-heart-icon"><i class="fas fa-heart"></i></div>
                        <div class="bond-title">羁绊上线</div>
                        <div class="bond-heart-icon"><i class="fas fa-heart"></i></div>
                    </div>
                    <div class="bond-users">
                        <div class="bond-user">
                            ${avatarHTML(user1)}
                            <div class="bond-username">${user1.nick_name}</div>
                        </div>
                        <div class="bond-connector"><i class="fas fa-heart"></i></div>
                        <div class="bond-user">
                            ${avatarHTML(user2)}
                            <div class="bond-username">${user2.nick_name}</div>
                        </div>
                    </div>
                    <div class="bond-message">双向奔赴的爱最美好</div>
                </div>
            `;
            
            container.appendChild(notification);
            document.body.appendChild(container);
            
            // 创建粒子效果（移动端不创建）
            if (!isMobile) {
                setTimeout(function() {
                    const particlesContainer = notification.querySelector('.bond-particles');
                    const particles = ['<i class="fas fa-heart"></i>', '<i class="fas fa-star"></i>', '<i class="fas fa-sparkles"></i>'];
                    
                    for (let i = 0; i < 20; i++) {
                        const particle = document.createElement('div');
                        particle.className = 'bond-particle';
                        particle.innerHTML = particles[Math.floor(Math.random() * particles.length)];
                        
                        const angle = (Math.random() * 360) * (Math.PI / 180);
                        const distance = 150 + Math.random() * 200;
                        
                        particle.style.setProperty('--tx', Math.cos(angle) * distance + 'px');
                        particle.style.setProperty('--ty', Math.sin(angle) * distance + 'px');
                        particle.style.left = '50%';
                        particle.style.top = '50%';
                        particle.style.animationDelay = (Math.random() * 0.5) + 's';
                        
                        particlesContainer.appendChild(particle);
                    }
                }, 200);
            }
            
            // 3秒后移除
            setTimeout(function() {
                container.remove();
            }, 3000);
        },

        /**
         * 切换好感度卡片展开/收缩
         * @param {Object} showIntimacyCard - 是否展开卡片的响应式对象
         */
        toggleIntimacyCard: function(showIntimacyCard) {
            showIntimacyCard.value = !showIntimacyCard.value;
        },

        /**
         * 保存经验提示设置
         * @param {Object} showExpToast - 是否显示经验提示的响应式对象
         */
        saveExpToastSetting: function(showExpToast) {
            localStorage.setItem('showExpToast', showExpToast.value);
        },

        /**
         * 保存羁绊上线特效设置
         * @param {Object} showBondOnlineEffect - 是否显示特效的响应式对象
         */
        saveBondOnlineEffectSetting: function(showBondOnlineEffect) {
            localStorage.setItem('showBondOnlineEffect', showBondOnlineEffect.value);
        }
    };
    
})(window);
