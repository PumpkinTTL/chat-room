/**
 * 已读状态管理模块
 * 负责消息已读标记、IntersectionObserver、批量请求聚合
 */
(function(global) {
    'use strict';
    
    global.ChatApp = global.ChatApp || {};
    
    /**
     * 已读状态管理器
     */
    global.ChatApp.ReadStatusManager = {
        // 批量已读请求聚合
        pendingReadBatch: [],
        batchReadTimer: null,
        BATCH_READ_DELAY: 300, // 300ms 聚合窗口
        
        // IntersectionObserver 实例
        messageObserver: null,
        
        /**
         * 从 localStorage 加载已读状态（按房间分开存储）
         */
        loadFromStorage: function(currentUser, roomId, markedAsReadIds) {
            try {
                const userId = currentUser.value?.id;
                const currentRoomId = roomId.value;
                if (!userId || !currentRoomId) return;

                const storageKey = 'read_status_' + userId + '_' + currentRoomId;
                const storedData = localStorage.getItem(storageKey);

                if (storedData) {
                    const parsedData = JSON.parse(storedData);
                    // 检查是否过期（7天）
                    if (parsedData.updateTime && Date.now() - parsedData.updateTime > 7 * 24 * 60 * 60 * 1000) {
                        localStorage.removeItem(storageKey);
                        return;
                    }
                    // 恢复已读状态（只加载最近的500条）
                    if (Array.isArray(parsedData.readIds)) {
                        parsedData.readIds.slice(-500).forEach(function (id) {
                            markedAsReadIds.value.add(Number(id));
                        });
                    }
                }
            } catch (e) {
                console.warn('[已读] 加载已读状态失败:', e);
            }
        },

        /**
         * 保存已读状态到 localStorage（节流，按房间分开）
         */
        saveToStorage: (function() {
            let saveReadStatusTimer = null;
            
            return function(currentUser, roomId, markedAsReadIds) {
                if (saveReadStatusTimer) return;

                saveReadStatusTimer = setTimeout(function () {
                    saveReadStatusTimer = null;

                    try {
                        const userId = currentUser.value?.id;
                        const currentRoomId = roomId.value;
                        if (!userId || !currentRoomId) return;

                        const storageKey = 'read_status_' + userId + '_' + currentRoomId;
                        // 只保留最近500条
                        const readIds = Array.from(markedAsReadIds.value).slice(-500);

                        localStorage.setItem(storageKey, JSON.stringify({
                            readIds: readIds,
                            updateTime: Date.now()
                        }));
                    } catch (e) {
                        console.warn('[已读] 保存已读状态失败:', e);
                    }
                }, 2000); // 2秒节流
            };
        })(),
        
        /**
         * 清理旧的已读存储（切换房间时调用）
         */
        clearForRoom: function(markedAsReadIds, pendingMarkIds) {
            markedAsReadIds.value.clear();
            pendingMarkIds.value.clear();
            this.pendingReadBatch = [];
        },

        /**
         * 实际发送已读标记请求
         */
        flushReadBatch: function(wsClient, wsConnected, apiRequest, markedAsReadIds, pendingMarkIds, saveCallback) {
            if (this.pendingReadBatch.length === 0) return;
            
            const idsToSend = this.pendingReadBatch.slice();
            this.pendingReadBatch = [];
            
            // 通过WebSocket发送已读标记
            if (wsClient && wsConnected) {
                wsClient.send({
                    type: 'mark_read',
                    message_ids: idsToSend
                });

                // WebSocket发送成功后立即标记为已读
                idsToSend.forEach(function (id) {
                    markedAsReadIds.value.add(id);
                    pendingMarkIds.value.delete(id);
                });

                saveCallback();
            } else {
                // 降级到HTTP API
                apiRequest('/api/message/markRead', {
                    method: 'POST',
                    body: JSON.stringify({ message_ids: idsToSend })
                }).then(function () {
                    idsToSend.forEach(function (id) {
                        markedAsReadIds.value.add(id);
                        pendingMarkIds.value.delete(id);
                    });
                    saveCallback();
                }).catch(function (error) {
                    console.error('[已读] HTTP标记失败:', error);
                    idsToSend.forEach(function (id) {
                        pendingMarkIds.value.delete(id);
                    });
                });
            }
        },

        /**
         * 标记指定消息为已读（批量聚合版）
         */
        markMessagesAsRead: function(messageIds, markedAsReadIds, pendingMarkIds, wsClient, wsConnected, apiRequest, saveCallback) {
            if (!messageIds || messageIds.length === 0) return;

            const self = this;

            // 统一转换为数字类型，避免类型混用
            const normalizedIds = messageIds.map(function (id) {
                return id ? Number(id) : null;
            }).filter(function (id) {
                return id && !isNaN(id) && !String(id).startsWith('temp_');
            });

            if (normalizedIds.length === 0) return;

            // 过滤掉已经标记过的消息和正在标记中的消息
            const validIds = normalizedIds.filter(function (id) {
                if (markedAsReadIds.value.has(id)) return false;
                if (pendingMarkIds.value.has(id)) return false;
                return true;
            });

            if (validIds.length === 0) return;

            // 记录到正在标记集合，并加入批量队列
            validIds.forEach(function (id) {
                pendingMarkIds.value.add(id);
                if (self.pendingReadBatch.indexOf(id) === -1) {
                    self.pendingReadBatch.push(id);
                }
            });

            // 重置批量发送定时器
            if (this.batchReadTimer) {
                clearTimeout(this.batchReadTimer);
            }
            this.batchReadTimer = setTimeout(function() {
                self.flushReadBatch(wsClient, wsConnected, apiRequest, markedAsReadIds, pendingMarkIds, saveCallback);
            }, this.BATCH_READ_DELAY);
        },

        /**
         * 使用 IntersectionObserver 检测消息可见性
         */
        initObserver: function(messagesContainer, messages, markedAsReadIds, markCallback) {
            if (this.messageObserver) {
                this.messageObserver.disconnect();
            }
            
            if (!('IntersectionObserver' in window)) {
                console.warn('[已读] 浏览器不支持 IntersectionObserver，降级到滚动检测');
                return;
            }
            
            const container = messagesContainer.value;
            if (!container) return;
            
            const self = this;
            
            this.messageObserver = new IntersectionObserver(function (entries) {
                if (document.visibilityState !== 'visible') return;
                
                const visibleUnreadIds = [];
                
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    
                    const el = entry.target;
                    const msgId = el.getAttribute('data-msg-id');
                    if (!msgId || msgId.startsWith('temp_')) return;
                    
                    const numericMsgId = Number(msgId);
                    if (isNaN(numericMsgId)) return;
                    if (markedAsReadIds.value.has(numericMsgId)) return;
                    
                    // 查找消息数据，只处理别人的消息
                    const msg = messages.value.find(function (m) {
                        return m.id == numericMsgId;
                    });
                    if (!msg || msg.isOwn) return;
                    
                    visibleUnreadIds.push(numericMsgId);
                    
                    // 已读后停止观察该元素
                    self.messageObserver.unobserve(el);
                });
                
                if (visibleUnreadIds.length > 0) {
                    markCallback(visibleUnreadIds);
                }
            }, {
                root: container,
                rootMargin: '0px',
                threshold: 0.5 // 50% 可见时触发
            });
        },
        
        /**
         * 观察新消息元素
         */
        observeMessageElement: function(msgId, messagesContainer, nextTick) {
            if (!this.messageObserver) return;
            
            const self = this;
            
            nextTick(function () {
                const container = messagesContainer.value;
                if (!container) return;
                
                const el = container.querySelector('.msg-row[data-msg-id="' + msgId + '"]');
                if (el) {
                    self.messageObserver.observe(el);
                }
            });
        },
        
        /**
         * 观察所有未读消息（加载消息后调用）
         */
        observeAllUnreadMessages: function(messagesContainer, messages, markedAsReadIds, nextTick) {
            if (!this.messageObserver) return;
            
            const self = this;
            
            nextTick(function () {
                const container = messagesContainer.value;
                if (!container) return;
                
                const msgElements = container.querySelectorAll('.msg-row[data-msg-id]');
                msgElements.forEach(function (el) {
                    const msgId = el.getAttribute('data-msg-id');
                    if (!msgId || msgId.startsWith('temp_')) return;
                    
                    const numericMsgId = Number(msgId);
                    if (markedAsReadIds.value.has(numericMsgId)) return;
                    
                    // 只观察别人的消息
                    const msg = messages.value.find(function (m) {
                        return m.id == numericMsgId;
                    });
                    if (!msg || msg.isOwn) return;
                    
                    self.messageObserver.observe(el);
                });
            });
        },

        /**
         * 检测并标记可见区域内的未读消息（降级方案）
         */
        checkAndMarkVisibleMessages: function(messagesContainer, messages, markedAsReadIds, markCallback) {
            // 如果有 IntersectionObserver，优先使用它
            if (this.messageObserver) {
                return;
            }
            
            const container = messagesContainer.value;
            if (!container || document.visibilityState !== 'visible') return;

            const containerRect = container.getBoundingClientRect();
            const containerTop = containerRect.top;
            const containerBottom = containerRect.bottom;

            const msgElements = container.querySelectorAll('.msg-row[data-msg-id]');
            const visibleUnreadIds = [];

            for (let i = 0; i < msgElements.length; i++) {
                const el = msgElements[i];
                const msgId = el.getAttribute('data-msg-id');
                if (!msgId || msgId.startsWith('temp_')) continue;

                const numericMsgId = Number(msgId);
                if (isNaN(numericMsgId)) continue;
                if (markedAsReadIds.value.has(numericMsgId)) continue;

                const msg = messages.value.find(function (m) {
                    return m.id == numericMsgId;
                });
                if (!msg || msg.isOwn) continue;

                const msgRect = el.getBoundingClientRect();
                const msgCenter = msgRect.top + msgRect.height / 2;
                if (msgCenter >= containerTop && msgCenter <= containerBottom) {
                    visibleUnreadIds.push(numericMsgId);
                }
            }

            if (visibleUnreadIds.length > 0) {
                markCallback(visibleUnreadIds);
            }
        }
    };
    
})(window);
