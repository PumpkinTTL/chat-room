/**
 * 图片模态框模块
 * 负责图片预览、缩放、拖拽功能
 */
(function(global) {
    'use strict';
    
    global.ChatApp = global.ChatApp || {};
    
    /**
     * 图片模态框管理器
     */
    global.ChatApp.ImageModalManager = {
        /**
         * 打开图片模态框
         */
        open: function(imageUrl, refs) {
            if (imageUrl) {
                refs.currentImageUrl.value = imageUrl;
                refs.isImageModalOpen.value = true;
                // 重置缩放和位置
                refs.imageScale.value = 1;
                refs.imagePosition.value = { x: 0, y: 0 };
            }
        },

        /**
         * 关闭图片模态框
         */
        close: function(refs) {
            refs.isImageModalOpen.value = false;
        },

        /**
         * 重置图片状态
         */
        reset: function(refs) {
            refs.imageScale.value = 1;
            refs.imagePosition.value = { x: 0, y: 0 };
        },

        /**
         * 获取两点间距离
         */
        getDistance: function(touch1, touch2) {
            const dx = touch1.clientX - touch2.clientX;
            const dy = touch1.clientY - touch2.clientY;
            return Math.sqrt(dx * dx + dy * dy);
        },

        /**
         * 获取两点中心
         */
        getCenter: function(touch1, touch2) {
            return {
                x: (touch1.clientX + touch2.clientX) / 2,
                y: (touch1.clientY + touch2.clientY) / 2
            };
        },

        /**
         * 处理触摸开始
         */
        handleTouchStart: function(e, refs) {
            e.preventDefault();
            refs.touchStartTime.value = Date.now();
            refs.hasMoved.value = false;

            if (e.touches.length === 1) {
                // 单指开始：记录拖拽起点
                const touch = e.touches[0];
                refs.dragStartPos.value = {
                    x: touch.clientX,
                    y: touch.clientY
                };
                refs.imageStartPos.value = {
                    x: refs.imagePosition.value.x,
                    y: refs.imagePosition.value.y
                };
                refs.isDragging.value = true;
                refs.isZooming.value = false;
            } else if (e.touches.length === 2) {
                // 双指开始：切换到缩放模式
                refs.isDragging.value = false;
                refs.isZooming.value = true;

                const distance = this.getDistance(e.touches[0], e.touches[1]);
                refs.lastTouchDistance.value = distance;
                refs.initialScale.value = refs.imageScale.value;
            }
        },

        /**
         * 处理触摸移动
         */
        handleTouchMove: function(e, refs) {
            e.preventDefault();

            if (e.touches.length === 1 && refs.isDragging.value) {
                // 单指拖拽：计算移动距离并应用
                const touch = e.touches[0];
                const deltaX = touch.clientX - refs.dragStartPos.value.x;
                const deltaY = touch.clientY - refs.dragStartPos.value.y;

                // 检测是否有移动
                if (Math.abs(deltaX) > 5 || Math.abs(deltaY) > 5) {
                    refs.hasMoved.value = true;
                }

                refs.imagePosition.value = {
                    x: refs.imageStartPos.value.x + deltaX,
                    y: refs.imageStartPos.value.y + deltaY
                };
            } else if (e.touches.length === 2) {
                // 切换到双指模式
                if (!refs.isZooming.value) {
                    refs.isDragging.value = false;
                    refs.isZooming.value = true;
                    const distance = this.getDistance(e.touches[0], e.touches[1]);
                    refs.lastTouchDistance.value = distance;
                    refs.initialScale.value = refs.imageScale.value;
                } else {
                    // 双指缩放
                    const distance = this.getDistance(e.touches[0], e.touches[1]);

                    if (refs.lastTouchDistance.value > 0) {
                        const scaleRatio = distance / refs.lastTouchDistance.value;
                        const newScale = Math.max(0.1, Math.min(5, refs.initialScale.value * scaleRatio));
                        refs.imageScale.value = newScale;
                    }
                }
            }
        },

        /**
         * 处理触摸结束
         */
        handleTouchEnd: function(e, refs) {
            e.preventDefault();
            refs.isDragging.value = false;
            refs.isZooming.value = false;
            refs.lastTouchDistance.value = 0;
        },

        /**
         * 鼠标拖拽开始
         */
        handleMouseDown: function(e, refs) {
            e.preventDefault();

            refs.dragStartPos.value = {
                x: e.clientX,
                y: e.clientY
            };
            refs.imageStartPos.value = {
                x: refs.imagePosition.value.x,
                y: refs.imagePosition.value.y
            };
            refs.isDragging.value = true;
            refs.hasMoved.value = false;
        },

        /**
         * 鼠标拖拽移动
         */
        handleMouseMove: function(e, refs) {
            if (!refs.isDragging.value) return;

            const deltaX = e.clientX - refs.dragStartPos.value.x;
            const deltaY = e.clientY - refs.dragStartPos.value.y;

            // 检测是否有移动
            if (Math.abs(deltaX) > 5 || Math.abs(deltaY) > 5) {
                refs.hasMoved.value = true;
            }

            refs.imagePosition.value = {
                x: refs.imageStartPos.value.x + deltaX,
                y: refs.imageStartPos.value.y + deltaY
            };
        },

        /**
         * 鼠标拖拽结束
         */
        handleMouseUp: function(e, refs) {
            refs.isDragging.value = false;
        },

        /**
         * 鼠标滚轮缩放
         */
        handleWheel: function(e, refs) {
            e.preventDefault();

            const delta = e.deltaY > 0 ? -0.1 : 0.1;
            const newScale = Math.max(0.1, Math.min(5, refs.imageScale.value + delta));
            refs.imageScale.value = newScale;
        }
    };
    
})(window);
