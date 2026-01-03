/* 
 * iOS Safari 兼容性JavaScript修复
 * 动态处理iOS设备的特殊情况
 */

(function() {
    'use strict';
    
    // iOS设备检测
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || 
                  (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    
    console.log('iOS兼容性检测:', { isIOS, isSafari });
    
    if (isIOS) {
        document.body.classList.add('ios-device');
        
        // iOS方向检测
        function updateOrientation() {
            if (window.orientation !== undefined) {
                const orientation = Math.abs(window.orientation) === 90 ? 'landscape' : 'portrait';
                document.body.className = document.body.className.replace(/\b(portrait|landscape)\b/g, '');
                document.body.classList.add(orientation);
                console.log('iOS方向变化:', orientation);
            }
        }
        
        // 初始化方向
        updateOrientation();
        
        // 监听方向变化
        window.addEventListener('orientationchange', function() {
            setTimeout(updateOrientation, 100);
        });
        
        // iOS虚拟键盘处理
        let initialViewportHeight = window.innerHeight;
        let currentViewportHeight = window.innerHeight;
        
        // 监听视口变化
        function handleViewportChange() {
            currentViewportHeight = window.innerHeight;
            const keyboardHeight = Math.max(0, initialViewportHeight - currentViewportHeight);
            
            // 设置CSS变量
            document.documentElement.style.setProperty('--keyboard-height', keyboardHeight + 'px');
            document.documentElement.style.setProperty('--viewport-height', currentViewportHeight + 'px');
            
            // 如果键盘弹出
            if (keyboardHeight > 150) {
                document.body.classList.add('keyboard-open');
                console.log('iOS键盘弹出，高度:', keyboardHeight);
            } else {
                document.body.classList.remove('keyboard-open');
            }
        }
        
        // 监听窗口大小变化
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(handleViewportChange, 150);
        });
        
        // 初始化
        handleViewportChange();
        
        // iOS滚动优化
        function improveScrolling() {
            const scrollElements = document.querySelectorAll('.messages-container, .sidebar');
            scrollElements.forEach(el => {
                if (el) {
                    el.style.webkitOverflowScrolling = 'touch';
                }
            });
        }
        
        // DOM加载完成后执行
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', improveScrolling);
        } else {
            improveScrolling();
        }
        
        // iOS输入框焦点处理
        function handleInputFocus() {
            const inputs = document.querySelectorAll('input, textarea');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    // 延迟滚动，确保键盘已弹出
                    setTimeout(() => {
                        if (this.scrollIntoView) {
                            this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }, 300);
                });
            });
        }
        
        // 延迟执行，确保DOM元素存在
        setTimeout(handleInputFocus, 1000);
        
        // iOS登录页面特殊处理
        function optimizeLoginPage() {
            if (window.location.pathname.includes('login')) {
                console.log('iOS登录页面优化启用');
                
                // 添加iOS兼容性提示
                addIOSCompatibilityNotice();
                
                // 登录页面输入框特殊处理
                const inputs = document.querySelectorAll('.login-card input');
                inputs.forEach(input => {
                    // 防止iOS自动缩放
                    input.addEventListener('focus', function() {
                        this.style.fontSize = '16px';
                    });
                    
                    // 失焦时恢复
                    input.addEventListener('blur', function() {
                        this.style.fontSize = '';
                    });
                });
                
                // 复选框点击区域优化
                const checkboxLabels = document.querySelectorAll('.checkbox-label');
                checkboxLabels.forEach(label => {
                    label.addEventListener('touchstart', function(e) {
                        // 防止双击缩放
                        e.preventDefault();
                        const checkbox = this.querySelector('input[type="checkbox"]');
                        if (checkbox) {
                            checkbox.checked = !checkbox.checked;
                            // 触发change事件以便Vue响应
                            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });
                });
                
                // iOS登录按钮优化
                const loginBtn = document.querySelector('.btn-primary');
                if (loginBtn) {
                    loginBtn.addEventListener('touchstart', function() {
                        this.style.transform = 'scale(0.98)';
                    });
                    
                    loginBtn.addEventListener('touchend', function() {
                        this.style.transform = '';
                    });
                }
            }
        }
        
        // 延迟执行登录页面优化
        setTimeout(optimizeLoginPage, 1500);
        
        // iOS兼容性提示函数
        function addIOSCompatibilityNotice() {
            const loginFooter = document.querySelector('.login-footer');
            if (loginFooter && !document.querySelector('.ios-compatibility-notice')) {
                const notice = document.createElement('div');
                notice.className = 'ios-compatibility-notice';
                notice.innerHTML = `
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>您正在使用iOS设备访问，可能会出现兼容性问题</span>
                `;
                loginFooter.appendChild(notice);
                console.log('iOS兼容性提示已添加');
            }
        }
        
        // iOS双击缩放禁用
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, false);
        
        // iOS橡皮筋效果控制
        document.addEventListener('touchmove', function(e) {
            // 如果不是在可滚动区域内，阻止默认行为
            const scrollableElement = e.target.closest('.messages-container, .sidebar');
            if (!scrollableElement) {
                e.preventDefault();
            }
        }, { passive: false });
        
        // iOS safe area检测和设置
        function setSafeAreas() {
            const safeAreaTop = getComputedStyle(document.documentElement).getPropertyValue('env(safe-area-inset-top)') || '0px';
            const safeAreaBottom = getComputedStyle(document.documentElement).getPropertyValue('env(safe-area-inset-bottom)') || '0px';
            
            console.log('iOS Safe Areas:', { top: safeAreaTop, bottom: safeAreaBottom });
        }
        
        setTimeout(setSafeAreas, 500);
    }
    
    // iOS Safari特定修复
    if (isIOS && isSafari) {
        console.log('iOS Safari特定修复已激活');
        
        // 修复iOS Safari的100vh问题
        function setRealViewportHeight() {
            const vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', vh + 'px');
        }
        
        setRealViewportHeight();
        window.addEventListener('resize', setRealViewportHeight);
        window.addEventListener('orientationchange', function() {
            setTimeout(setRealViewportHeight, 500);
        });
        
        // iOS图片加载优化
        function optimizeImages() {
            const images = document.querySelectorAll('img');
            images.forEach(img => {
                if (!img.complete) {
                    img.addEventListener('load', function() {
                        // 强制重绘
                        this.style.opacity = '0.99';
                        setTimeout(() => {
                            this.style.opacity = '';
                        }, 1);
                    });
                }
            });
        }
        
        // 定期优化新加载的图片
        setInterval(optimizeImages, 2000);
    }
    
    // 调试信息
    window.IOSCompatibility = {
        isIOS: isIOS,
        isSafari: isSafari,
        viewportHeight: window.innerHeight,
        devicePixelRatio: window.devicePixelRatio,
        userAgent: navigator.userAgent
    };
    
    console.log('iOS兼容性模块已加载:', window.IOSCompatibility);
})();