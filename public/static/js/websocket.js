/**
 * WebSocket 客户端封装
 * 功能：连接管理、消息收发、自动重连、降级到短轮询
 */

(function(window) {
    'use strict';

    /**
     * WebSocket 客户端类
     */
    function WebSocketClient(options) {
        this.options = options || {};
        this.wsUrl = this.options.wsUrl || 'ws://localhost/ws';
        this.token = this.options.token || '';
        this.roomId = this.options.roomId || null;
        this.autoReconnect = this.options.autoReconnect !== false;
        this.reconnectDelay = this.options.reconnectDelay || 3000;
        this.pingInterval = this.options.pingInterval || 30000;

        // 回调函数
        this.onConnected = this.options.onConnected || null;
        this.onAuthSuccess = this.options.onAuthSuccess || null;
        this.onRoomJoined = this.options.onRoomJoined || null;
        this.onMessage = this.options.onMessage || null;
        this.onUserJoined = this.options.onUserJoined || null;
        this.onUserLeft = this.options.onUserLeft || null;
        this.onTyping = this.options.onTyping || null;
        this.onMessageRead = this.options.onMessageRead || null;  // 已读回执
        this.onMessageBurned = this.options.onMessageBurned || null;  // 消息焚毁
        this.onRoomCleared = this.options.onRoomCleared || null;  // 房间清理
        this.onRoomLockChanged = this.options.onRoomLockChanged || null;  // 房间锁定状态变化
        this.onError = this.options.onError || null;           // 连接错误
        this.onServerError = this.options.onServerError || null; // 服务器业务错误
        this.onClose = this.options.onClose || null;

        // 内部状态
        this.ws = null;
        this.isConnected = false;
        this.isAuthed = false;
        this.currentRoomId = null;
        this.reconnectTimer = null;
        this.pingTimer = null;
        this.manualClose = false;
    }

    /**
     * 连接 WebSocket
     */
    WebSocketClient.prototype.connect = function() {
        var self = this;

        if (this.ws && (this.ws.readyState === WebSocket.CONNECTING || this.ws.readyState === WebSocket.OPEN)) {
            console.log('[WebSocket] 已在连接或已连接');
            return;
        }

        console.log('[WebSocket] 正在连接: ' + this.wsUrl);

        try {
            this.ws = new WebSocket(this.wsUrl);

            this.ws.onopen = function() {
                console.log('[WebSocket] 连接成功');
                self.isConnected = true;
                self.manualClose = false;

                if (self.onConnected) {
                    self.onConnected();
                }

                // 连接成功后进行认证
                if (self.token) {
                    self.auth(self.token);
                }

                // 启动心跳
                self.startPing();
            };

            this.ws.onmessage = function(event) {
                try {
                    var data = JSON.parse(event.data);
                    console.log('[WebSocket] 收到消息:', data);
                    self.handleMessage(data);
                } catch (e) {
                    console.error('[WebSocket] 解析消息失败:', e, event.data);
                }
            };

            this.ws.onerror = function(error) {
                console.error('[WebSocket] 连接错误:', error);
                if (self.onError) {
                    self.onError(error);
                }
            };

            this.ws.onclose = function(event) {
                console.log('[WebSocket] 连接关闭: code=' + event.code + ', reason=' + event.reason);
                self.isConnected = false;
                self.isAuthed = false;
                self.stopPing();

                if (self.onClose) {
                    self.onClose(event);
                }

                // 自动重连
                if (!self.manualClose && self.autoReconnect) {
                    self.scheduleReconnect();
                }
            };

        } catch (error) {
            console.error('[WebSocket] 连接失败:', error);
            if (self.onError) {
                self.onError(error);
            }
        }
    };

    /**
     * 断开连接
     */
    WebSocketClient.prototype.disconnect = function() {
        this.manualClose = true;
        this.stopReconnect();
        this.stopPing();

        if (this.ws) {
            this.ws.close();
            this.ws = null;
        }

        this.isConnected = false;
        this.isAuthed = false;
        this.currentRoomId = null;
    };

    /**
     * 发送认证消息
     */
    WebSocketClient.prototype.auth = function(token) {
        this.token = token;

        if (!this.isConnected) {
            console.warn('[WebSocket] 未连接，无法认证');
            return false;
        }

        try {
            this.send({
                type: 'auth',
                token: token
            });
            return true;
        } catch (e) {
            console.error('[WebSocket] 认证失败:', e);
            return false;
        }
    };

    /**
     * 加入房间
     */
    WebSocketClient.prototype.joinRoom = function(roomId) {
        this.roomId = roomId;

        if (!this.isAuthed) {
            console.warn('[WebSocket] 未认证，无法加入房间');
            return false;
        }

        try {
            this.send({
                type: 'join_room',
                room_id: roomId
            });
            return true;
        } catch (e) {
            console.error('[WebSocket] 加入房间失败:', e);
            return false;
        }
    };

    /**
     * 发送消息
     */
    WebSocketClient.prototype.sendMessage = function(content) {
        if (!this.isAuthed) {
            console.warn('[WebSocket] 未认证，无法发送消息');
            return false;
        }

        if (!this.currentRoomId) {
            console.warn('[WebSocket] 未加入房间，无法发送消息');
            return false;
        }

        try {
            this.send({
                type: 'message',
                content: content
            });
            return true;
        } catch (e) {
            console.error('[WebSocket] 发送消息失败:', e);
            return false;
        }
    };

    /**
     * 发送心跳
     */
    WebSocketClient.prototype.ping = function() {
        try {
            this.send({
                type: 'ping'
            });
        } catch (e) {
            console.error('[WebSocket] 心跳失败:', e);
        }
    };

    /**
     * 底层发送方法
     */
    WebSocketClient.prototype.send = function(data) {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            throw new Error('WebSocket 未连接');
        }
        this.ws.send(JSON.stringify(data));
    };

    /**
     * 处理收到的消息
     */
    WebSocketClient.prototype.handleMessage = function(data) {
        switch (data.type) {
            case 'connected':
                // 连接成功确认
                break;

            case 'auth_success':
                this.isAuthed = true;
                if (this.onAuthSuccess) {
                    this.onAuthSuccess(data);
                }
                break;

            case 'room_joined':
                this.currentRoomId = data.room_id;
                if (this.onRoomJoined) {
                    this.onRoomJoined(data);
                }
                break;

            case 'message':
                if (this.onMessage) {
                    this.onMessage(data);
                }
                break;

            case 'user_joined':
                if (this.onUserJoined) {
                    this.onUserJoined(data);
                }
                break;

            case 'user_left':
                if (this.onUserLeft) {
                    this.onUserLeft(data);
                }
                break;

            case 'typing':
                if (this.onTyping) {
                    this.onTyping(data);
                }
                break;

            case 'message_read':
                if (this.onMessageRead) {
                    this.onMessageRead(data);
                }
                break;

            case 'message_burned':
                console.log('[WebSocketClient] 收到 message_burned, 回调存在:', !!this.onMessageBurned);
                if (this.onMessageBurned) {
                    this.onMessageBurned(data);
                }
                break;

            case 'room_cleared':
                if (this.onRoomCleared) {
                    this.onRoomCleared(data);
                }
                break;

            case 'room_lock_changed':
                if (this.onRoomLockChanged) {
                    this.onRoomLockChanged(data);
                }
                break;

            case 'mark_read_success':
                // 标记已读成功确认，忽略
                break;

            case 'pong':
                // 心跳响应，忽略
                break;

            case 'error':
                console.error('[WebSocket] 服务器错误:', data.msg);
                // 服务器业务错误，使用专门的回调
                if (this.onServerError) {
                    this.onServerError(data);
                } else if (this.onError) {
                    // 兼容旧代码，但标记为业务错误
                    this.onError(data);
                }
                break;

            default:
                console.log('[WebSocket] 未知消息类型:', data);
        }
    };

    /**
     * 安排重连
     */
    WebSocketClient.prototype.scheduleReconnect = function() {
        var self = this;

        if (this.reconnectTimer) {
            return;
        }

        console.log('[WebSocket] ' + this.reconnectDelay / 1000 + '秒后重连...');
        this.reconnectTimer = setTimeout(function() {
            self.reconnectTimer = null;
            self.connect();
        }, this.reconnectDelay);
    };

    /**
     * 停止重连
     */
    WebSocketClient.prototype.stopReconnect = function() {
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
    };

    /**
     * 启动心跳
     */
    WebSocketClient.prototype.startPing = function() {
        var self = this;

        this.stopPing();

        this.pingTimer = setInterval(function() {
            if (self.isConnected) {
                self.ping();
            }
        }, this.pingInterval);
    };

    /**
     * 停止心跳
     */
    WebSocketClient.prototype.stopPing = function() {
        if (this.pingTimer) {
            clearInterval(this.pingTimer);
            this.pingTimer = null;
        }
    };

    /**
     * 检查连接状态
     */
    WebSocketClient.prototype.getState = function() {
        return {
            isConnected: this.isConnected,
            isAuthed: this.isAuthed,
            currentRoomId: this.currentRoomId,
            readyState: this.ws ? this.ws.readyState : WebSocket.CLOSED
        };
    };

    // 导出到全局
    window.WebSocketClient = WebSocketClient;

})(window);
