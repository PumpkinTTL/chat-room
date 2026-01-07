/**
 * 精致聊天界面 - 智能聊天室
 * 紧凑美观设计 - Vue3版本
 */

// 检查Vue是否已加载
if (typeof Vue === 'undefined') {
    alert('Vue 加载失败，请检查网络连接');
    throw new Error('Vue 未定义');
}

// iOS Safari 兼容性：避免解构语法
const createApp = Vue.createApp;
const ref = Vue.ref;
const computed = Vue.computed;
const onMounted = Vue.onMounted;
const nextTick = Vue.nextTick;

try {
    // 自定义点击外部指令
    const clickOutside = {
        mounted(el, binding) {
            el._clickOutside = (event) => {
                if (!(el === event.target || el.contains(event.target))) {
                    binding.value(event);
                }
            };
            document.addEventListener('click', el._clickOutside);
        },
        unmounted(el) {
            document.removeEventListener('click', el._clickOutside);
        }
    };

    const app = createApp({
        setup() {
            // 响应式数据
            const roomName = ref('');
            const roomId = ref('');
            const username = ref('');

            // 当前用户信息
            const currentUser = ref({
                id: '',
                nick_name: '',
                avatar: '',
                token: ''
            });

            // UI状态
            const sidebarOpen = ref(false);
            // 从 localStorage 读取深色模式偏好，默认 false
            const savedTheme = localStorage.getItem('isDarkMode');
            const isDarkMode = ref(savedTheme === 'true');
            const showEmojiPicker = ref(false);
            const showMorePanel = ref(false);
            const activeEmojiCategory = ref(0);
            const autoRefresh = ref(true);
            const autoRefreshTimer = ref(null);

            // WebSocket 相关
            const wsClient = ref(null);           // WebSocket 客户端实例
            const wsConnected = ref(false);       // WebSocket 连接状态
            const wsEnabled = ref(true);          // 是否启用 WebSocket（降级开关）
            const usePolling = ref(false);        // 是否使用轮询（降级时）

            // 图片模态框
            const isImageModalOpen = ref(false);
            const currentImageUrl = ref('');

            // 图片缩放和拖拽状态
            const imageScale = ref(1);
            const imagePosition = ref({ x: 0, y: 0 });
            const isDragging = ref(false);
            const isZooming = ref(false);
            const touchStartTime = ref(0);
            const lastTouchDistance = ref(0);
            const initialScale = ref(1);
            const dragStartPos = ref({ x: 0, y: 0 });
            const imageStartPos = ref({ x: 0, y: 0 });
            const hasMoved = ref(false);

            // 图片相关
            const imagePreview = ref(null);
            const selectedImageFile = ref(null);

            // 右键菜单
            const contextMenu = ref({
                show: false,
                x: 0,
                y: 0,
                message: null
            });

            // Loading状态
            const globalLoading = ref(false);
            const loadingText = ref('加载中...');
            const messagesLoading = ref(false); // 消息列表loading

            // 新消息提示
            const showNewMessageTip = ref(false);
            const newMessageCount = ref(0);

            // 分页相关
            const currentPage = ref(1);
            const hasMoreMessages = ref(true);
            const loadingMore = ref(false);

            // 表情面板触摸滑动检测
            const emojiTouchStartPos = ref({ x: 0, y: 0 });
            const emojiHasMoved = ref(false);

            // 发送状态
            const isSending = ref(false);

            // 设置uploadManager的回调
            if (window.uploadManager) {
                window.uploadManager.setCallbacks({
                    onProgress: (tempId, progress) => {
                        uploadProgress.value[tempId] = progress;
                    },
                    onSuccess: (data) => {
                        const { tempId, messageId, message, tempMessage } = data;

                        console.log('[Upload] onSuccess - message:', JSON.stringify(message));
                        console.log('[Upload] onSuccess - tempMessage:', JSON.stringify(tempMessage));

                        // 更新消息ID：将临时ID替换为真实ID
                        const msgIndex = messages.value.findIndex(m => m.id === tempId);
                        console.log('[Upload] Looking for tempId:', tempId, 'found at index:', msgIndex);
                        console.log('[Upload] Current messages ids:', messages.value.map(m => m.id));
                        
                        if (msgIndex !== -1) {
                            // 构建完整的消息对象
                            const updatedMessage = {
                                id: messageId,
                                type: message.type,
                                content: message.content || '',
                                isOwn: true,
                                time: message.time,
                                sender: {
                                    id: currentUser.value.id,
                                    nickname: currentUser.value.nick_name,
                                    avatar: currentUser.value.avatar
                                }
                            };

                            // 视频消息：确保 videoUrl 正确设置
                            if (message.type === 'video') {
                                // 优先使用服务器返回的 URL，其次使用临时消息的预览 URL
                                updatedMessage.videoUrl = message.videoUrl || tempMessage.videoUrl || message.content;
                                updatedMessage.videoThumbnail = null; // 不使用缩略图，让 video 标签自动显示封面
                                updatedMessage.videoDuration = message.videoDuration || tempMessage.videoDuration || null;
                                console.log('[Upload] Video message updated - videoUrl:', updatedMessage.videoUrl);
                            }

                            // 文件消息：确保文件信息正确设置
                            if (message.type === 'file') {
                                updatedMessage.fileName = message.fileName || tempMessage.fileName || '';
                                updatedMessage.fileSize = message.fileSize || tempMessage.fileSize || 0;
                                updatedMessage.fileExtension = message.fileExtension || tempMessage.fileExtension || '';
                                updatedMessage.fileUrl = message.fileUrl || '';
                            }

                            // 图片消息
                            if (message.type === 'image') {
                                updatedMessage.imageUrl = message.imageUrl || message.content;
                            }

                            // 使用 splice 确保 Vue 响应式更新
                            messages.value.splice(msgIndex, 1, updatedMessage);
                            console.log('[Upload] Message updated at index:', msgIndex, 'new message:', JSON.stringify(updatedMessage));

                            // 更新发送状态
                            delete messageSendStatus.value[tempId];
                            messageSendStatus.value[messageId] = 'success';
                        } else {
                            console.warn('[Upload] Could not find message with tempId:', tempId);
                        }

                        // 构建文件信息用于 WebSocket 广播
                        let fileInfo = null;
                        if (message.type === 'video') {
                            fileInfo = {
                                videoUrl: message.videoUrl || message.content,
                                videoThumbnail: message.videoThumbnail || null,
                                videoDuration: message.videoDuration || null
                            };
                        } else if (message.type === 'file') {
                            fileInfo = {
                                fileName: message.fileName || message.content,
                                fileSize: message.fileSize || 0,
                                fileExtension: message.fileExtension || '',
                                fileUrl: message.fileUrl || ''
                            };
                        }

                        // 通过 WebSocket 广播通知其他用户（携带完整文件信息）
                        broadcastMessageViaWebSocket(messageId, message.type, message.content, fileInfo);
                    },
                    onFailed: (data) => {
                        const { tempId, error, cancelled } = data;

                        // 更新发送状态
                        messageSendStatus.value[tempId] = 'failed';

                        // 移除临时消息或标记为失败
                        if (!cancelled) {
                            const msgIndex = messages.value.findIndex(m => m.id === tempId);
                            if (msgIndex !== -1) {
                                // 标记为失败，可以重试
                                messages.value[msgIndex].sendError = error;
                            }
                        }
                    },
                    onStatusChange: (tempId, status, tempMessage) => {
                        messageSendStatus.value[tempId] = status;

                        // 当状态变为sending时，添加临时消息到列表
                        if (status === 'sending' && tempMessage) {
                            console.log('[Upload] Adding temp message:', JSON.stringify(tempMessage));
                            // 检查是否已经添加过
                            const exists = messages.value.some(m => m.id === tempMessage.id);
                            if (!exists) {
                                // 补充模板需要的字段
                                const completeMessage = {
                                    ...tempMessage,
                                    username: tempMessage.sender?.nickname || currentUser.value.nick_name,
                                    content: '',
                                    text: ''
                                };
                                messages.value.push(completeMessage);
                                uploadProgress.value[tempMessage.id] = 0;

                                // 滚动到底部
                                nextTick(function () {
                                    scrollToBottom();
                                });
                            }
                        }
                    }
                });
            }

            // 语音录制相关
            const isRecording = ref(false);
            const mediaRecorder = ref(null);
            const audioChunks = ref([]);
            const recordingStartTime = ref(0);
            const recordingTimer = ref(null);
            const recordingDuration = ref(0);
            const hasVoicePermission = ref(false);

            // 计算录制时间显示文本
            const recordingTimeText = computed(() => {
                const seconds = Math.floor(recordingDuration.value / 1000);
                const mins = Math.floor(seconds / 60);
                const secs = seconds % 60;
                return mins + ':' + (secs < 10 ? '0' : '') + secs;
            });

            // 正在输入状态 - 支持多人
            const typingUsers = ref({});  // { oderId: { nickname, timer } }
            const typingTimer = ref(null);
            const isTyping = ref(false);

            // 计算正在输入的显示文本
            const typingText = computed(() => {
                const users = Object.values(typingUsers.value);
                if (users.length === 0) return '';
                if (users.length === 1) return users[0].nickname + ' 正在输入...';
                return users[0].nickname + ' 等' + users.length + '人正在输入...';
            });

            // 消息发送状态映射 { messageId: 'sending' | 'success' | 'failed' }
            const messageSendStatus = ref({});

            // 上传进度映射 { messageId: progress(0-100) }
            const uploadProgress = ref({});

            // 模拟上传进度的定时器映射
            const uploadProgressTimers = ref({});

            // 计算图片样式
            const imageStyle = computed(() => ({
                transform: `translate(${imagePosition.value.x}px, ${imagePosition.value.y}px) scale(${imageScale.value})`,
                transition: (isDragging.value || isZooming.value) ? 'none' : 'transform 0.2s ease-out',
                transformOrigin: 'center center',
                willChange: 'transform',
                userSelect: 'none',
                pointerEvents: 'none' // 防止图片本身的事件干扰
            }));

            // 工具函数：检查头像URL是否有效
            const isValidAvatar = function (avatar) {
                return avatar && avatar !== 'null' && avatar !== 'none' && avatar !== '';
            };

            const imageContainerStyle = computed(() => ({
                overflow: 'hidden',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: '100%',
                height: '100%',
                position: 'relative',
                cursor: isDragging.value ? 'grabbing' : 'grab'
            }));

            // 表情数据 - 从外部JSON加载
            const emojiCategories = ref([]);

            // 表情相关方法
            const toggleEmojiPicker = () => {
                showEmojiPicker.value = !showEmojiPicker.value;
                // 关闭附件面板
                if (showEmojiPicker.value) {
                    showAttachPanel.value = false;
                }
            };

            const hideEmojiPicker = () => {
                showEmojiPicker.value = false;
            };

            // 隐藏所有弹出面板
            const hideAllPanels = () => {
                showEmojiPicker.value = false;
                showAttachPanel.value = false;
            };

            const insertEmoji = (emoji) => {
                newMessage.value += emoji.char;
            };

            // 更多面板相关方法
            const toggleMorePanel = () => {
                showMorePanel.value = !showMorePanel.value;
            };

            const hideMorePanel = () => {
                showMorePanel.value = false;
            };

            // 表情触摸处理 - 防止滑动误触
            const handleEmojiTouchStart = (event, emoji) => {
                const touch = event.touches[0];
                emojiTouchStartPos.value = { x: touch.clientX, y: touch.clientY };
                emojiHasMoved.value = false;
            };

            const handleEmojiTouchMove = (event) => {
                const touch = event.touches[0];
                const deltaX = Math.abs(touch.clientX - emojiTouchStartPos.value.x);
                const deltaY = Math.abs(touch.clientY - emojiTouchStartPos.value.y);
                // 移动超过5px视为滑动
                if (deltaX > 5 || deltaY > 5) {
                    emojiHasMoved.value = true;
                }
            };

            const handleEmojiTouchEnd = (event, emoji) => {
                // 只有在没有滑动的情况下才插入表情
                if (!emojiHasMoved.value) {
                    // 阻止后续的 click 事件触发，避免重复插入
                    event.preventDefault();
                    insertEmoji(emoji);
                }
            };

            // 显示loading
            const showLoading = (text = '加载中...') => {
                loadingText.value = text;
                globalLoading.value = true;
            };

            // 隐藏loading
            const hideLoading = () => {
                globalLoading.value = false;
            };

            // 通用API请求函数
            // Token通过Cookie自动发送，不需要手动添加到header
            const apiRequest = async (url, options = {}) => {
                const headers = {
                    'Content-Type': 'application/json'
                };

                // iOS Safari 兼容性：手动合并headers
                if (options.headers) {
                    Object.assign(headers, options.headers);
                }

                const fetchOptions = {
                    headers: headers,
                    credentials: 'same-origin' // 确保发送cookie
                };

                // iOS Safari 兼容性：手动合并options
                Object.assign(fetchOptions, options);
                fetchOptions.headers = headers; // 确保headers不被覆盖

                const response = await fetch(url, fetchOptions);

                // 统一处理401状态码（token无效或过期）
                if (response.status === 401) {
                    // 清除本地存储的用户信息
                    localStorage.removeItem('userInfo');
                    localStorage.removeItem('login_credentials');

                    // 显示提示
                    window.Toast.error('登录已过期，请重新登录');

                    // 2秒后跳转到登录页
                    setTimeout(function () {
                        window.location.href = '/login';
                    }, 2000);

                    // 抛出错误，停止后续处理
                    throw new Error('Token已过期');
                }

                return response;
            };

            // 模板引用（提前定义，供已读检测函数使用）
            const messagesContainer = ref(null);

            // 消息列表（提前定义，供已读检测函数使用）
            const messages = ref([]);

            // 已读消息ID集合（避免重复标记）- 统一使用数字类型
            const markedAsReadIds = ref(new Set());
            // 正在标记中的消息ID（防止并发重复请求）
            const pendingMarkIds = ref(new Set());

            // 从localStorage加载已读状态
            const loadReadStatusFromStorage = () => {
                try {
                    const userId = currentUser.value?.id;
                    if (!userId) return;

                    const storageKey = 'message_read_status_' + userId;
                    const storedData = localStorage.getItem(storageKey);

                    if (storedData) {
                        const parsedData = JSON.parse(storedData);
                        // 恢复已读状态（只加载最近的1000条，避免内存占用过大）
                        if (Array.isArray(parsedData.readIds)) {
                            parsedData.readIds.slice(-1000).forEach(function (id) {
                                markedAsReadIds.value.add(Number(id));
                            });
                            console.log('[已读] 从localStorage加载了', markedAsReadIds.value.size, '条已读记录');
                        }
                    }
                } catch (e) {
                    console.warn('[已读] 加载已读状态失败:', e);
                }
            };

            // 保存已读状态到localStorage（节流）
            let saveReadStatusTimer = null;
            const saveReadStatusToStorage = () => {
                if (saveReadStatusTimer) return;

                saveReadStatusTimer = setTimeout(function () {
                    saveReadStatusTimer = null;

                    try {
                        const userId = currentUser.value?.id;
                        if (!userId) return;

                        const storageKey = 'message_read_status_' + userId;
                        const readIds = Array.from(markedAsReadIds.value);

                        localStorage.setItem(storageKey, JSON.stringify({
                            userId: userId,
                            readIds: readIds,
                            updateTime: Date.now()
                        }));
                    } catch (e) {
                        console.warn('[已读] 保存已读状态失败:', e);
                    }
                }, 1000); // 1秒节流
            };

            // 标记指定消息为已读（通过WebSocket）
            const markMessagesAsRead = (messageIds) => {
                if (!messageIds || messageIds.length === 0) return;

                // 统一转换为数字类型，避免类型混用
                const normalizedIds = messageIds.map(function (id) {
                    return id ? Number(id) : null;
                }).filter(function (id) {
                    return id && !isNaN(id) && !id.toString().startsWith('temp_');
                });

                if (normalizedIds.length === 0) return;

                // 过滤掉已经标记过的消息和正在标记中的消息
                const validIds = normalizedIds.filter(function (id) {
                    if (markedAsReadIds.value.has(id)) return false;
                    if (pendingMarkIds.value.has(id)) return false;
                    return true;
                });

                if (validIds.length === 0) return;

                // 记录到正在标记集合
                validIds.forEach(function (id) {
                    pendingMarkIds.value.add(id);
                });

                console.log('[已读] 标记消息已读:', validIds);

                // 通过WebSocket发送已读标记
                if (wsClient.value && wsConnected.value) {
                    wsClient.value.send({
                        type: 'mark_read',
                        message_ids: validIds
                    });

                    // WebSocket发送成功后立即标记为已读
                    validIds.forEach(function (id) {
                        markedAsReadIds.value.add(id);
                        pendingMarkIds.value.delete(id);
                    });

                    // 触发保存到localStorage
                    saveReadStatusToStorage();
                } else {
                    // 降级到HTTP API
                    apiRequest('/api/message/markRead', {
                        method: 'POST',
                        body: JSON.stringify({ message_ids: validIds })
                    }).then(function () {
                        // HTTP请求成功后标记为已读
                        validIds.forEach(function (id) {
                            markedAsReadIds.value.add(id);
                            pendingMarkIds.value.delete(id);
                        });
                        saveReadStatusToStorage();
                    }).catch(function (error) {
                        console.error('[已读] HTTP标记失败:', error);
                        // 失败后从待发送集合移除，允许重试
                        validIds.forEach(function (id) {
                            pendingMarkIds.value.delete(id);
                        });
                    });
                }
            };

            // 检测并标记可见区域内的未读消息（优化版：减少 DOM 查询）
            const checkAndMarkVisibleMessages = () => {
                if (!roomId.value || document.visibilityState !== 'visible') return;

                const container = messagesContainer.value;
                if (!container) return;

                // 一次性获取容器位置，避免重复调用
                const containerRect = container.getBoundingClientRect();
                const containerTop = containerRect.top;
                const containerBottom = containerRect.bottom;

                // 获取所有消息元素
                const msgElements = container.querySelectorAll('.msg-row[data-msg-id]');
                const visibleUnreadIds = [];

                // 使用普通 for 循环代替 forEach，性能更好
                for (let i = 0; i < msgElements.length; i++) {
                    const el = msgElements[i];
                    const msgId = el.getAttribute('data-msg-id');
                    if (!msgId || msgId.startsWith('temp_')) continue;

                    // 统一转换为数字类型
                    const numericMsgId = Number(msgId);
                    if (isNaN(numericMsgId)) continue;

                    // 检查是否已经标记过（统一使用数字类型）
                    if (markedAsReadIds.value.has(numericMsgId)) continue;

                    // 查找对应的消息数据
                    const msg = messages.value.find(function (m) {
                        return m.id == numericMsgId;
                    });

                    // 只处理别人发的消息
                    if (!msg || msg.isOwn) continue;

                    // 检查是否在可见区域（内联计算，减少函数调用）
                    const msgRect = el.getBoundingClientRect();
                    const msgCenter = msgRect.top + msgRect.height / 2;
                    if (msgCenter >= containerTop && msgCenter <= containerBottom) {
                        visibleUnreadIds.push(numericMsgId);
                    }
                }

                if (visibleUnreadIds.length > 0) {
                    markMessagesAsRead(visibleUnreadIds);
                }
            };

            // 滚动事件处理（节流）
            let scrollCheckTimer = null;
            const handleMessagesScroll = () => {
                if (scrollCheckTimer) return;

                scrollCheckTimer = setTimeout(function () {
                    scrollCheckTimer = null;
                    checkAndMarkVisibleMessages();

                    // 如果用户滚动到底部，隐藏新消息提示
                    if (isUserAtBottom() && showNewMessageTip.value) {
                        showNewMessageTip.value = false;
                        newMessageCount.value = 0;
                    }
                }, 200); // 200ms节流
            };

            // ========== WebSocket 相关函数 ==========

            // 初始化 WebSocket
            const initWebSocket = () => {
                if (!window.WebSocketClient) {
                    console.warn('WebSocketClient 未加载，将使用轮询模式');
                    usePolling.value = true;
                    return;
                }

                if (!currentUser.value.token) {
                    console.warn('未登录，无法初始化 WebSocket');
                    return;
                }

                // 确定 WebSocket URL
                const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
                const host = window.location.host;
                const wsUrl = protocol + '//' + host + '/ws';

                console.log('[WebSocket] 初始化: ' + wsUrl);

                wsClient.value = new WebSocketClient({
                    wsUrl: wsUrl,
                    token: currentUser.value.token,
                    autoReconnect: true,
                    reconnectDelay: 5000,
                    pingInterval: 30000,

                    onConnected: () => {
                        console.log('[WebSocket] 已连接');
                        wsConnected.value = true;
                        usePolling.value = false;  // 重连成功，关闭轮询模式标记

                        // 停止轮询（如果有）
                        if (autoRefreshTimer.value) {
                            stopAutoRefresh();
                        }
                    },

                    onAuthSuccess: (data) => {
                        console.log('[WebSocket] 认证成功: ' + data.nickname);

                        // 认证成功后加入当前房间
                        if (roomId.value) {
                            wsClient.value.joinRoom(roomId.value);
                        }
                    },

                    onRoomJoined: (data) => {
                        console.log('[WebSocket] 加入房间: ' + data.room_id);

                        // 更新在线人数
                        onlineUsers.value = data.online_count;

                        // 更新在线用户列表
                        if (data.users && data.users.length > 0) {
                            onlineUsersList.value = data.users.map(function (u) {
                                return {
                                    id: u.user_id,
                                    name: u.nick_name,
                                    online: true
                                };
                            });
                        }

                        // 加入房间成功后停止轮询
                        if (autoRefreshTimer.value) {
                            stopAutoRefresh();
                        }
                    },

                    onMessage: (data) => {
                        console.log('[WebSocket] 收到消息:', data);

                        // 添加消息到列表
                        if (data.type === 'message') {
                            // 使用 == 进行宽松比较，避免类型不一致问题
                            const isOwn = data.from_user_id == currentUser.value.id;

                            // 如果是自己发送的消息，查找并更新临时消息
                            if (isOwn) {
                                // 查找临时消息（以 temp_ 开头的 ID）
                                const tempMsgIndex = messages.value.findIndex(function (m) {
                                    return m.id && m.id.toString().indexOf('temp_') === 0;
                                });

                                if (tempMsgIndex > -1) {
                                    // 更新临时消息为真实消息
                                    const tempId = messages.value[tempMsgIndex].id;
                                    messages.value[tempMsgIndex].id = data.message_id;

                                    // 更新发送状态
                                    delete messageSendStatus.value[tempId];
                                    messageSendStatus.value[data.message_id] = 'success';

                                    console.log('[WebSocket] 已更新临时消息: ' + tempId + ' -> ' + data.message_id);
                                    return; // 不添加新消息，只更新现有消息
                                }

                                // 如果没找到临时消息，可能是刷新后的历史消息，直接忽略
                                // 检查是否已存在相同 message_id 的消息
                                const existingMsg = messages.value.find(function (m) {
                                    return m.id == data.message_id;
                                });
                                if (existingMsg) {
                                    console.log('[WebSocket] 消息已存在，忽略: ' + data.message_id);
                                    return;
                                }
                            }

                            // 别人发送的消息，添加新消息
                            const msgType = data.message_type || 'normal';

                            // 确定消息类型（支持字符串和数字）
                            let normalizedType = 'text';
                            if (msgType === 'image' || msgType === 2) normalizedType = 'image';
                            else if (msgType === 'video' || msgType === 5) normalizedType = 'video';
                            else if (msgType === 'file' || msgType === 3) normalizedType = 'file';
                            else if (msgType === 'text' || msgType === 1 || msgType === 'normal') normalizedType = 'text';

                            const rawMsg = {
                                id: data.message_id,
                                type: normalizedType,
                                text: (normalizedType === 'image' || normalizedType === 'video' || normalizedType === 'file') ? '' : data.content,
                                content: data.content,
                                imageUrl: normalizedType === 'image' ? data.content : '',
                                // 视频消息：优先使用 WebSocket 传递的元数据
                                videoUrl: normalizedType === 'video' ? (data.video_url || data.content) : '',
                                videoThumbnail: normalizedType === 'video' ? (data.video_thumbnail || null) : null,
                                videoDuration: normalizedType === 'video' ? (data.video_duration || null) : null,
                                // 文件消息：优先使用 WebSocket 传递的元数据
                                fileName: normalizedType === 'file' ? (data.file_name || data.content) : '',
                                fileSize: normalizedType === 'file' ? (data.file_size || 0) : 0,
                                fileExtension: normalizedType === 'file' ? (data.file_extension || '') : '',
                                fileUrl: normalizedType === 'file' ? (data.file_url || '') : '',
                                username: data.from_nickname,
                                timestamp: new Date(),
                                isOwn: isOwn,
                                is_read: false, // 新消息默认未读
                                read_count: 0,
                                read_users: [],
                                sender: {
                                    id: data.from_user_id,
                                    nickname: data.from_nickname,
                                    avatar: data.from_avatar
                                }
                            };

                            // 使用 processMessage 处理消息（包括链接检测）
                            const newMsg = processMessage(rawMsg, true, null);

                            // 先检查用户是否在底部
                            const wasAtBottom = isUserAtBottom();

                            messages.value.push(newMsg);

                            nextTick(function () {
                                if (isOwn || wasAtBottom) {
                                    // 自己发的消息或用户在底部，自动滚动
                                    scrollToBottom();
                                } else {
                                    // 别人发的消息且用户不在底部，显示新消息提示
                                    showNewMessageTip.value = true;
                                    newMessageCount.value = (newMessageCount.value || 0) + 1;
                                }

                                // 如果是别人的消息，延迟检测可见性并标记已读
                                if (!isOwn && document.visibilityState === 'visible') {
                                    setTimeout(function () {
                                        checkAndMarkVisibleMessages();
                                    }, 100);
                                }
                            });
                        }
                    },

                    onUserJoined: (data) => {
                        console.log('[WebSocket] 用户加入: ' + data.nickname);
                        // 只更新在线人数，总人数不变
                        onlineUsers.value = data.online_count;

                        // 刷新在线用户列表
                        if (roomId.value) {
                            getRoomInfo(roomId.value);
                        }
                    },

                    onUserLeft: (data) => {
                        console.log('[WebSocket] 用户离开: ' + data.nickname);
                        // 只更新在线人数，总人数不变
                        onlineUsers.value = data.online_count;

                        // 刷新在线用户列表
                        if (roomId.value) {
                            getRoomInfo(roomId.value);
                        }
                    },

                    onTyping: (data) => {
                        console.log('[WebSocket] 收到输入状态:', data);

                        // 不显示自己的输入状态
                        if (data.user_id == currentUser.value.id) {
                            console.log('[WebSocket] 忽略自己的输入状态');
                            return;
                        }

                        const oderId = String(data.user_id);
                        console.log('[WebSocket] 处理用户输入:', oderId, data.nickname, data.typing);

                        if (data.typing) {
                            // 清除该用户之前的定时器
                            if (typingUsers.value[oderId] && typingUsers.value[oderId].timer) {
                                clearTimeout(typingUsers.value[oderId].timer);
                            }

                            // 添加/更新正在输入的用户 - 使用新对象触发响应式
                            const timer = setTimeout(function () {
                                const newUsers = Object.assign({}, typingUsers.value);
                                delete newUsers[oderId];
                                typingUsers.value = newUsers;
                                console.log('[WebSocket] 输入超时清除:', oderId);
                            }, 3000);

                            const newUsers = Object.assign({}, typingUsers.value);
                            newUsers[oderId] = {
                                nickname: data.nickname,
                                timer: timer
                            };
                            typingUsers.value = newUsers;
                            console.log('[WebSocket] 更新 typingUsers:', JSON.stringify(Object.keys(typingUsers.value)));
                        } else {
                            // 用户停止输入，清除
                            if (typingUsers.value[oderId]) {
                                if (typingUsers.value[oderId].timer) {
                                    clearTimeout(typingUsers.value[oderId].timer);
                                }
                                const newUsers = Object.assign({}, typingUsers.value);
                                delete newUsers[oderId];
                                typingUsers.value = newUsers;
                                console.log('[WebSocket] 用户停止输入:', oderId);
                            }
                        }
                    },

                    // 收到已读回执
                    onMessageRead: (data) => {
                        console.log('[WebSocket] 收到已读回执:', data);

                        // 更新消息的已读状态
                        const messageIds = data.message_ids || [];
                        const readerInfo = {
                            user_id: data.reader_id,
                            nickname: data.reader_nickname,
                            read_at: data.read_at
                        };

                        messages.value.forEach(function (msg) {
                            // 使用宽松比较，避免类型不匹配
                            const msgIdMatches = messageIds.some(function (id) {
                                return id == msg.id;
                            });

                            if (msg.isOwn && msgIdMatches) {
                                // 更新已读状态
                                msg.isRead = true;
                                msg.readCount = (msg.readCount || 0) + 1;

                                // 添加到已读用户列表（最多5个）
                                if (!msg.readUsers) {
                                    msg.readUsers = [];
                                }
                                // 检查是否已存在
                                const exists = msg.readUsers.some(function (u) {
                                    return u.user_id == readerInfo.user_id;
                                });
                                if (!exists && msg.readUsers.length < 5) {
                                    msg.readUsers.unshift(readerInfo);
                                }

                                console.log('[WebSocket] 更新消息已读状态:', msg.id, '已读人数:', msg.readCount);
                            }
                        });
                    },

                    // 服务器业务错误（如"您未加入此房间"）
                    onServerError: (error) => {
                        console.warn('[WebSocket] 服务器业务错误:', error.msg);
                        // 显示错误提示，但不降级
                        if (window.Toast) {
                            window.Toast.error(error.msg);
                        }
                    },

                    // WebSocket 连接错误
                    onError: (error) => {
                        console.error('[WebSocket] 连接错误:', error);

                        // WebSocket 连接错误，降级到轮询
                        wsConnected.value = false;

                        if (!usePolling.value) {
                            console.warn('[WebSocket] 降级到轮询模式');
                            usePolling.value = true;

                            // 启动轮询
                            if (autoRefresh.value && roomId.value) {
                                startAutoRefresh();
                            }
                        }
                    },

                    onClose: () => {
                        console.log('[WebSocket] 连接关闭');
                        wsConnected.value = false;
                    }
                });

                // 连接 WebSocket
                wsClient.value.connect();
            };

            // 通过 WebSocket 发送消息
            const sendWebSocketMessage = (content) => {
                if (!wsClient.value || !wsConnected.value) {
                    return false;
                }

                try {
                    return wsClient.value.sendMessage(content);
                } catch (e) {
                    console.error('[WebSocket] 发送失败:', e);
                    return false;
                }
            };

            // 发送正在输入状态
            const sendTypingStatus = (typing) => {
                if (!wsClient.value || !wsConnected.value) {
                    return;
                }
                try {
                    wsClient.value.send({
                        type: 'typing',
                        typing: typing
                    });
                } catch (e) {
                    // 忽略错误
                }
            };

            // 输入框获取焦点
            const onInputFocus = () => {
                if (newMessage.value.trim()) {
                    sendTypingStatus(true);
                    isTyping.value = true;
                }
            };

            // 输入框失去焦点
            const onInputBlur = () => {
                sendTypingStatus(false);
                isTyping.value = false;
            };

            // 输入内容变化
            const onInputChange = () => {
                // 清除之前的定时器
                if (typingTimer.value) {
                    clearTimeout(typingTimer.value);
                }

                if (newMessage.value.trim()) {
                    // 每次输入都发送正在输入状态（续命）
                    sendTypingStatus(true);
                    isTyping.value = true;

                    // 3秒后自动停止输入状态
                    typingTimer.value = setTimeout(function () {
                        sendTypingStatus(false);
                        isTyping.value = false;
                    }, 3000);
                } else {
                    // 内容为空，停止输入状态
                    sendTypingStatus(false);
                    isTyping.value = false;
                }
            };

            // 切换房间时重新加入 WebSocket 房间
            const joinWebSocketRoom = (roomIdValue) => {
                if (wsClient.value && wsConnected.value) {
                    wsClient.value.joinRoom(roomIdValue);
                }
            };

            // ========== 消息处理工具函数 ==========

            // 检测并解析文本中的URL
            const detectUrls = function (text) {
                if (!text) return [];

                const urlRegex = /(https?:\/\/[^\s]+)|(www\.[^\s]+)/gi;
                const urls = [];
                let match;

                while ((match = urlRegex.exec(text)) !== null) {
                    let url = match[0];
                    // 补全 www 开头的链接
                    if (url.startsWith('www.')) {
                        url = 'http://' + url;
                    }
                    urls.push({
                        url: url,
                        original: match[0]
                    });
                }

                return urls;
            };

            // 格式化文本中的链接为HTML（支持链接卡片）
            const formatTextWithLinks = function (text, message) {
                if (!text) return '';

                const urls = detectUrls(text);
                if (urls.length === 0) return text;

                let result = text;
                const urlCards = [];

                // 为每个URL生成卡片HTML
                urls.forEach(function (item, index) {
                    const placeholder = '__URL_' + index + '__';
                    urlCards.push({
                        placeholder: placeholder,
                        url: item.url
                    });

                    // 替换原文本中的URL
                    result = result.replace(item.url, placeholder);
                });

                // 保存URL卡片信息到消息对象
                if (message) {
                    message.urlCards = urlCards;
                }

                return result;
            };

            // 处理单条消息数据（提取公共逻辑，避免重复）
            const processMessage = function (msg, isNewMessage, oldMessageIds) {
                const isOwnMessage = msg.sender && msg.sender.id == currentUser.value.id;
                const shouldShowNewAnimation = isNewMessage && !isOwnMessage && (!oldMessageIds || !oldMessageIds.has(msg.id));

                // 消息类型映射：数字 -> 字符串
                // 1=文本(text), 2=图片(image), 3=文件(file), 4=系统(system), 5=视频(video)
                let messageType = msg.type;
                if (typeof messageType === 'number') {
                    const typeMap = {
                        1: 'text',
                        2: 'image',
                        3: 'file',
                        4: 'system',
                        5: 'video'
                    };
                    messageType = typeMap[messageType] || 'text';
                }

                // iOS Safari 兼容性：避免扩展运算符
                const processedMsg = {
                    id: msg.id,
                    content: msg.content,
                    type: messageType, // 使用转换后的字符串类型
                    timestamp: msg.timestamp,
                    sender: msg.sender,
                    isOwn: isOwnMessage,
                    username: (msg.sender && msg.sender.nickname) || '未知用户',
                    isNewMessage: shouldShowNewAnimation,
                    isRead: msg.is_read || false,
                    readCount: msg.read_count || 0,
                    readUsers: msg.read_users || []
                };

                // 时间戳处理（只在有值时处理）
                if (msg.timestamp) {
                    processedMsg.time = new Date(
                        typeof msg.timestamp === 'string' ? msg.timestamp.replace(/-/g, '/') : msg.timestamp
                    );
                }

                // 图片消息需要 imageUrl（从 content 字段获取）
                if (messageType === 'image' && msg.content) {
                    processedMsg.imageUrl = msg.content;
                }

                // 视频消息需要videoUrl和videoThumbnail（从file_info或content获取）
                if (messageType === 'video') {
                    if (msg.videoUrl) {
                        processedMsg.videoUrl = msg.videoUrl;
                    } else if (msg.content) {
                        processedMsg.videoUrl = msg.content;
                    }
                    if (msg.videoThumbnail) {
                        processedMsg.videoThumbnail = msg.videoThumbnail;
                    }
                    if (msg.videoDuration !== undefined) {
                        processedMsg.videoDuration = msg.videoDuration;
                    }
                }

                // 文件消息需要fileName、fileSize、fileExtension（从file_info或content获取）
                if (messageType === 'file') {
                    if (msg.fileName) {
                        processedMsg.fileName = msg.fileName;
                    } else if (msg.content) {
                        processedMsg.fileName = msg.content;
                    }
                    if (msg.fileSize !== undefined) {
                        processedMsg.fileSize = msg.fileSize;
                    }
                    if (msg.fileUrl) {
                        processedMsg.fileUrl = msg.fileUrl;
                    }
                    // 提取扩展名：优先用已有的，否则从 fileName 或 fileUrl 提取
                    if (msg.fileExtension && msg.fileExtension.length > 0) {
                        processedMsg.fileExtension = msg.fileExtension;
                    } else {
                        // 从 fileName 或 fileUrl 提取
                        const source = processedMsg.fileName || msg.fileUrl || '';
                        const lastDot = source.lastIndexOf('.');
                        if (lastDot > -1 && lastDot < source.length - 1) {
                            processedMsg.fileExtension = source.substring(lastDot + 1).toLowerCase();
                        }
                    }
                }

                // 复制其他所有属性（包括 text 等）
                for (const key in msg) {
                    if (msg.hasOwnProperty && msg.hasOwnProperty(key) && !processedMsg.hasOwnProperty(key)) {
                        processedMsg[key] = msg[key];
                    }
                }

                // 处理文本消息中的链接（支持 'text', 'normal', 1 等多种类型）
                if (msg.type === 'text' || msg.type === 'normal' || msg.type === 1) {
                    if (processedMsg.text) {
                        const urls = detectUrls(processedMsg.text);
                        if (urls.length > 0) {
                            processedMsg.urlCards = urls;
                            // 处理文本，移除链接（将在模板中单独显示）
                            processedMsg.displayText = processedMsg.text;
                            urls.forEach(function (item) {
                                processedMsg.displayText = processedMsg.displayText.replace(item.original, '');
                            });
                            // 清理多余的空格和换行
                            processedMsg.displayText = processedMsg.displayText.trim().replace(/\s+/g, ' ');
                        }
                    }
                }

                // 设置发送状态
                if (isOwnMessage && msg.id) {
                    messageSendStatus.value[msg.id] = 'success';
                }

                return processedMsg;
            };

            // 批量处理消息
            const processMessages = function (messagesArray, isNewMessage = false, oldMessageIds = null) {
                const result = [];
                for (let i = 0; i < messagesArray.length; i++) {
                    result.push(processMessage(messagesArray[i], isNewMessage, oldMessageIds));
                }
                return result;
            };

            // ========== 原有函数 ==========

            // 获取用户信息和已加入的房间
            const loadUserInfo = async () => {
                try {
                    showLoading('加载房间列表...');
                    // 获取用户已加入的房间列表
                    const response = await apiRequest('/api/roomUser/userRooms');
                    const result = await response.json();

                    if (result.code === 0 && result.data && result.data.length > 0) {
                        // 用户有已加入的房间，显示房间列表
                        roomList.value = result.data;

                        // 尝试恢复上次所在的房间
                        const lastRoomId = localStorage.getItem('lastRoomId');
                        let targetRoom = null;

                        if (lastRoomId) {
                            // 查找上次的房间是否还在列表中
                            targetRoom = result.data.find(function (r) {
                                return String(r.id) === String(lastRoomId);
                            });
                        }

                        // 如果没有上次的房间或已不在列表中，使用第一个房间
                        if (!targetRoom) {
                            targetRoom = result.data[0];
                        }

                        roomName.value = targetRoom.name;
                        roomId.value = targetRoom.id;

                        // 保存当前房间ID
                        localStorage.setItem('lastRoomId', targetRoom.id);

                        // 获取房间信息（在线人数）
                        await getRoomInfo(targetRoom.id);

                        // 加载历史消息
                        await loadRoomMessages(targetRoom.id);
                    } else {
                        // 显示未加入房间状态
                        roomList.value = [];
                        roomName.value = '';
                        roomId.value = '';
                    }
                } catch (error) {
                    // 获取用户房间信息失败，显示未加入状态
                    roomList.value = [];
                    roomName.value = '';
                    roomId.value = '';
                } finally {
                    hideLoading(); // 确保关闭全局loading
                }
            };

            // 加入房间对话框
            const showJoinRoomDialog = () => {
                const inputRoomId = prompt('请输入房间ID：');
                if (inputRoomId) {
                    joinRoom(inputRoomId);
                }
            };

            // 创建房间对话框
            const showCreateRoomDialog = () => {
                const roomNameInput = prompt('请输入房间名称：');
                if (roomNameInput && roomNameInput.trim()) {
                    createRoom(roomNameInput.trim());
                }
            };

            // 创建房间API调用
            const createRoom = async (newRoomName) => {
                try {
                    showLoading('正在创建房间...');

                    const response = await apiRequest('/api/room/create', {
                        method: 'POST',
                        body: JSON.stringify({
                            name: newRoomName
                        })
                    });

                    const result = await response.json();
                    console.log('[创建房间] 返回数据:', result);

                    if (result.code === 0) {
                        const roomData = result.data;
                        const newRoomId = roomData.id;

                        console.log('[创建房间] 房间ID:', newRoomId);

                        if (!newRoomId) {
                            window.Toast.error('创建成功但无法获取房间ID');
                            // 刷新房间列表
                            await loadUserInfo();
                            return;
                        }

                        // 后端已自动加入房间，前端直接切换
                        roomName.value = newRoomName;
                        roomId.value = newRoomId;

                        // 获取房间信息
                        await getRoomInfo(newRoomId);

                        // 加载历史消息（新房间应该是空的）
                        await loadRoomMessages(newRoomId);

                        // 重新加载房间列表
                        await loadUserInfo();

                        // 加入 WebSocket 房间
                        if (wsClient.value && wsConnected.value) {
                            wsClient.value.joinRoom(newRoomId);
                        }

                        window.Toast.success('房间创建成功：' + newRoomName);
                    } else {
                        window.Toast.error('创建房间失败：' + result.msg);
                    }
                } catch (error) {
                    console.error('[创建房间] 失败:', error);
                    window.Toast.error('创建房间失败：' + error.message);
                } finally {
                    hideLoading();
                }
            };

            // 加入房间API调用
            const joinRoom = async (roomIdValue) => {
                try {
                    // 先检查房间是否存在
                    const checkResponse = await apiRequest(`/api/room/${roomIdValue}`);
                    const checkResult = await checkResponse.json();

                    if (checkResult.code !== 0) {
                        window.Toast.error('房间不存在，请检查房间ID是否正确');
                        return;
                    }

                    // 房间存在，尝试加入
                    const response = await apiRequest(`/api/roomUser/join?room_id=${roomIdValue}`, {
                        method: 'POST'
                    });

                    const result = await response.json();

                    if (result.code === 0) {
                        // 加入成功，获取房间真实名称
                        const joinedRoomName = checkResult.data.name || `房间 ${roomIdValue}`;
                        roomName.value = joinedRoomName;
                        roomId.value = roomIdValue;

                        // 获取房间信息
                        await getRoomInfo(roomIdValue);

                        // 加载历史消息
                        await loadRoomMessages(roomIdValue);

                        // 重新加载房间列表
                        await loadUserInfo();

                        window.Toast.success('成功加入房间：' + joinedRoomName);
                    } else {
                        // 加入失败
                        window.Toast.error('加入房间失败：' + result.msg);
                    }
                } catch (error) {
                    window.Toast.error('加入房间失败：' + error.message);
                }
            };

            // 获取房间信息
            const getRoomInfo = async (roomIdValue) => {
                try {
                    // 获取房间详情（包含owner_id）
                    const roomResponse = await apiRequest(`/api/room/${roomIdValue}`);
                    const roomResult = await roomResponse.json();

                    if (roomResult.code === 0 && roomResult.data) {
                        currentRoomOwnerId.value = roomResult.data.owner_id;
                    }

                    // 获取在线人数和群成员总数
                    const countResponse = await apiRequest(`/api/roomUser/count/${roomIdValue}`);
                    const countResult = await countResponse.json();

                    if (countResult.code === 0) {
                        onlineUsers.value = countResult.data.online_count;   // 实时在线
                        totalUsers.value = countResult.data.total_count;     // 群成员总数
                    }

                    // 获取在线用户列表
                    const listResponse = await apiRequest(`/api/roomUser/list/${roomIdValue}`);
                    const listResult = await listResponse.json();

                    if (listResult.code === 0) {
                        // iOS Safari 兼容性：避免箭头函数
                        onlineUsersList.value = listResult.data.users.map(function (user) {
                            return {
                                id: user.id,
                                name: user.nick_name || ('用户' + user.id),
                                online: true
                            };
                        });
                    }
                } catch (error) {
                    // 获取房间信息失败，静默处理
                }
            };

            // 聊天数据
            const onlineUsers = ref(0);      // 实时在线人数（Redis）
            const totalUsers = ref(0);       // 群成员总数（SQL）
            const currentRoomOwnerId = ref(null); // 当前房间的房主ID
            const onlineUsersList = ref([]);
            const roomList = ref([]);
            const contactList = ref([]);     // 联系人列表
            const activeTab = ref('rooms');  // 当前激活的标签: 'contacts' 或 'rooms'，默认选中群聊
            // messages 已在前面定义

            const newMessage = ref('');

            // 模板引用（imageInput，messagesContainer 已在前面定义）
            const imageInput = ref(null);

            // 加载房间历史消息
            const loadRoomMessages = async (roomIdValue, shouldScroll = true, showLoading = true) => {
                if (!roomIdValue) return;

                // 重置分页
                currentPage.value = 1;
                hasMoreMessages.value = true;

                // 记录刷新前用户是否在底部
                const wasAtBottom = isUserAtBottom();

                try {
                    if (showLoading) {
                        messagesLoading.value = true;
                    }

                    const response = await apiRequest(`/api/message/list?room_id=${roomIdValue}&page=1&limit=50`);
                    const result = await response.json();

                    if (result.code === 0) {
                        const oldMessageCount = messages.value.length;
                        const oldMessageIds = new Set(messages.value.map(function (m) { return m.id; }));

                        const roomMessages = result.data.messages || [];
                        hasMoreMessages.value = result.data.has_more === true;

                        // 快速检查是否有变化
                        const currentIds = messages.value.map(function (m) { return m.id; }).join(',');
                        const newIds = roomMessages.map(function (m) { return m.id; }).join(',');
                        const hasChanges = currentIds !== newIds;

                        // 如果没有变化且不是首次加载，跳过渲染
                        if (!hasChanges && messages.value.length > 0 && !shouldScroll && !showLoading) {
                            if (roomMessages.length > oldMessageCount && !wasAtBottom) {
                                showNewMessageTip.value = true;
                                newMessageCount.value = roomMessages.length - oldMessageCount;
                            }
                            return;
                        }

                        // 保存正在发送的消息（避免自动刷新时消失）
                        const pendingMessages = messages.value.filter(function (msg) {
                            return msg.id && msg.id.indexOf('temp_') === 0 && messageSendStatus.value[msg.id] === 'sending';
                        });

                        // 使用优化的消息处理函数
                        const isAutoRefresh = !shouldScroll && !showLoading;
                        messages.value = processMessages(roomMessages, isAutoRefresh, oldMessageIds);

                        // 追加正在发送的消息
                        if (pendingMessages.length > 0) {
                            pendingMessages.forEach(function (pendingMsg) {
                                if (!messages.value.some(function (m) { return m.id === pendingMsg.id; })) {
                                    messages.value.push(pendingMsg);
                                }
                            });
                        }

                        // 移除新消息标记（延迟一次执行）
                        if (isAutoRefresh) {
                            setTimeout(function () {
                                messages.value.forEach(function (msg) {
                                    if (msg.isNewMessage) {
                                        msg.isNewMessage = false;
                                    }
                                });
                            }, 300);
                        }

                        // 检测新消息提示（只有当有滚动条且用户不在底部时才显示提示）
                        // 如果没有滚动条（消息少），直接显示新消息不提示
                        const shouldShowTip = hasScrollbar() && !wasAtBottom;
                        if (isAutoRefresh && roomMessages.length > oldMessageCount && shouldShowTip) {
                            showNewMessageTip.value = true;
                            newMessageCount.value = roomMessages.length - oldMessageCount;
                        }

                        // 检测可见消息（合并到一个 setTimeout）
                        setTimeout(function () {
                            checkAndMarkVisibleMessages();
                        }, 200);
                    }
                } catch (error) {
                    console.error('加载消息失败:', error);
                } finally {
                    if (showLoading) {
                        messagesLoading.value = false;
                    }

                    // 智能滚动：减少 setTimeout 嵌套
                    if (shouldScroll || wasAtBottom) {
                        showNewMessageTip.value = false;
                        nextTick(function () {
                            scrollToBottom();
                            // 滚动后检测可见消息
                            setTimeout(checkAndMarkVisibleMessages, 150);
                        });
                    }
                }
            };

            // 加载更多历史消息
            const loadMoreMessages = async () => {
                if (!roomId.value || loadingMore.value || !hasMoreMessages.value) return;

                const container = messagesContainer.value;
                if (!container) return;

                // 记录滚动位置
                const scrollTopBefore = container.scrollTop;
                const scrollHeightBefore = container.scrollHeight;

                loadingMore.value = true;

                try {
                    const nextPage = currentPage.value + 1;
                    const response = await apiRequest(`/api/message/list?room_id=${roomId.value}&page=${nextPage}&limit=50`);
                    const result = await response.json();

                    if (result.code === 0) {
                        const olderMessages = result.data.messages || [];
                        hasMoreMessages.value = result.data.has_more === true;

                        if (olderMessages.length > 0) {
                            currentPage.value = nextPage;

                            // 使用优化的消息处理函数
                            const processedMessages = processMessages(olderMessages, false, null);

                            // 插入到开头
                            messages.value = processedMessages.concat(messages.value);

                            // DOM 更新后恢复滚动位置
                            nextTick(function () {
                                requestAnimationFrame(function () {
                                    const scrollHeightAfter = container.scrollHeight;
                                    const heightDiff = scrollHeightAfter - scrollHeightBefore;
                                    container.scrollTop = scrollTopBefore + heightDiff;
                                });
                            });
                        } else {
                            hasMoreMessages.value = false;
                        }
                    }
                } catch (error) {
                    console.error('加载更多失败:', error);
                } finally {
                    loadingMore.value = false;
                }
            };

            // 方法
            const sendMessage = async () => {
                const messageText = newMessage.value.trim();

                if (selectedImageFile.value) {
                    await sendImageMessage();
                    return;
                }

                if (!messageText) return;

                if (!roomId.value) {
                    window.Toast.error('请先加入房间');
                    return;
                }

                if (isSending.value) return; // 防止重复发送

                // 生成临时消息ID
                const tempId = 'temp_' + Date.now();

                // 构造消息对象
                const rawMsg = {
                    id: tempId,
                    type: 'normal',
                    text: messageText,
                    content: messageText,
                    time: new Date(),
                    sender: {
                        id: currentUser.value.id,
                        nickname: username.value
                    }
                };

                // 使用 processMessage 处理消息（包括链接检测）
                const newMsg = processMessage(rawMsg, true, null);

                messages.value.push(newMsg);
                messageSendStatus.value[tempId] = 'sending';
                newMessage.value = '';

                // 动画播放后立即移除标记
                setTimeout(function () {
                    const msgIndex = messages.value.findIndex(function (m) { return m.id === tempId; });
                    if (msgIndex > -1) {
                        messages.value[msgIndex].isNewMessage = false;
                    }
                }, 300);

                nextTick(function () {
                    scrollToBottom();
                });

                try {
                    isSending.value = true;

                    // 统一通过 HTTP API 发送消息
                    const response = await apiRequest('/api/message/sendText', {
                        method: 'POST',
                        body: JSON.stringify({
                            room_id: roomId.value,
                            content: messageText
                        })
                    });

                    const result = await response.json();

                    if (result.code === 0 && result.data) {
                        // 发送成功，更新消息ID和状态
                        const msgIndex = messages.value.findIndex(m => m.id === tempId);
                        if (msgIndex > -1) {
                            messages.value[msgIndex].id = result.data.id;
                            let serverTime = result.data.time;
                            if (typeof serverTime === 'string') {
                                serverTime = serverTime.replace(/-/g, '/');
                            }
                            messages.value[msgIndex].time = new Date(serverTime);

                            delete messageSendStatus.value[tempId];
                            messageSendStatus.value[result.data.id] = 'success';

                            // 通过 WebSocket 广播通知其他用户（使用统一函数）
                            broadcastMessageViaWebSocket(result.data.id, 'text', messageText);
                        }
                    } else {
                        messageSendStatus.value[tempId] = 'failed';
                        window.Toast.error('发送失败：' + (result.msg || '未知错误'));
                    }

                } catch (error) {
                    messageSendStatus.value[tempId] = 'failed';
                    window.Toast.error('发送失败：' + error.message);
                } finally {
                    isSending.value = false;
                }
            };

            // ========== 附件面板相关 ==========
            const showAttachPanel = ref(false);
            const fileInput = ref(null);

            const toggleAttachPanel = () => {
                showAttachPanel.value = !showAttachPanel.value;
                // 关闭其他面板
                if (showAttachPanel.value) {
                    showEmojiPicker.value = false;
                }
            };

            const hideAttachPanel = () => {
                showAttachPanel.value = false;
            };

            const selectImage = () => {
                hideAttachPanel();
                imageInput.value.click();
            };

            const selectVideo = () => {
                hideAttachPanel();
                fileInput.value.accept = 'video/*';
                fileInput.value.click();
            };

            const selectFile = () => {
                hideAttachPanel();
                fileInput.value.accept = '*/*';
                fileInput.value.click();
            };

            const handleFileSelect = async (event) => {
                const file = event.target.files[0];
                if (!file) return;

                if (!roomId.value) {
                    window.Toast.error('请先加入房间');
                    event.target.value = '';
                    return;
                }

                if (isSending.value) return; // 防止重复发送

                // 检测文件类型
                const fileType = FileValidator.detectFileType(file);

                // 创建临时消息（根据文件类型）
                let tempMessage = {
                    isOwn: true,
                    time: new Date().toISOString(),
                    isNew: true,
                    sender: {
                        id: currentUser.value.id,
                        nickname: currentUser.value.nick_name,
                        avatar: currentUser.value.avatar
                    }
                };

                // 根据文件类型创建不同的临时消息
                if (fileType === 'image') {
                    // 图片预览
                    const previewUrl = URL.createObjectURL(file);
                    tempMessage.type = 'image';
                    tempMessage.imageUrl = previewUrl;
                } else if (fileType === 'video') {
                    // 视频预览
                    const previewUrl = URL.createObjectURL(file);
                    tempMessage.type = 'video';
                    tempMessage.videoUrl = previewUrl;
                    tempMessage.videoThumbnail = previewUrl;
                    tempMessage.videoDuration = 0;
                } else {
                    // 文件消息
                    tempMessage.type = 'file';
                    tempMessage.fileName = file.name;
                    tempMessage.fileSize = file.size;
                    tempMessage.fileExtension = file.name.split('.').pop();
                }

                try {
                    // 清空文件输入
                    event.target.value = '';

                    // 使用UploadManager上传（不在这里添加消息，让onSuccess回调处理）
                    const result = await uploadManager.upload(file, fileType, {
                        roomId: roomId.value,
                        token: currentUser.value.token,
                        userInfo: {
                            id: currentUser.value.id,
                            nick_name: currentUser.value.nick_name,
                            avatar: currentUser.value.avatar
                        }
                    });

                    // 上传成功后，onSuccess回调已经处理了消息更新

                } catch (error) {
                    console.error('[上传] 失败:', error);
                    window.Toast.error(error.message || '上传失败，请重试');
                }
            };

            const clearRoomMessagesFromPanel = () => {
                hideAttachPanel();
                clearRoomMessages();
            };

            const triggerImageUpload = () => {
                imageInput.value.click();
            };

            // ========== 语音录制相关函数 ==========

            // 请求麦克风权限
            const requestVoicePermission = async () => {
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    // 获取权限后立即停止流
                    stream.getTracks().forEach(track => track.stop());
                    hasVoicePermission.value = true;
                    return true;
                } catch (error) {
                    console.error('[语音] 权限请求失败:', error);
                    if (error.name === 'NotAllowedError') {
                        window.Toast.error('麦克风权限被拒绝，请在浏览器设置中允许');
                    } else if (error.name === 'NotFoundError') {
                        window.Toast.error('未检测到麦克风设备');
                    } else {
                        window.Toast.error('无法访问麦克风: ' + error.message);
                    }
                    hasVoicePermission.value = false;
                    return false;
                }
            };

            // 开始录音
            const startVoiceRecord = async () => {
                if (!roomId.value) {
                    window.Toast.error('请先加入房间');
                    return;
                }

                // 检查是否为安全上下文（HTTPS 或 localhost）
                if (!window.isSecureContext) {
                    window.Toast.error('语音功能需要 HTTPS 安全连接');
                    console.error('[语音] 当前不是安全上下文，需要 HTTPS 或 localhost');
                    return;
                }

                // 检查浏览器支持
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    window.Toast.error('您的浏览器不支持语音录制，请使用最新版 Chrome/Firefox/Edge');
                    console.error('[语音] navigator.mediaDevices 不可用');
                    return;
                }

                // 如果没有权限，先请求权限
                if (!hasVoicePermission.value) {
                    window.Toast.info('正在请求麦克风权限...');
                    const granted = await requestVoicePermission();
                    if (!granted) return;
                }

                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });

                    // 检查MediaRecorder支持
                    if (!window.MediaRecorder) {
                        window.Toast.error('您的浏览器不支持录音功能');
                        stream.getTracks().forEach(track => track.stop());
                        return;
                    }

                    // 选择支持的音频格式
                    let mimeType = 'audio/webm';
                    if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                        mimeType = 'audio/webm;codecs=opus';
                    } else if (MediaRecorder.isTypeSupported('audio/mp4')) {
                        mimeType = 'audio/mp4';
                    } else if (MediaRecorder.isTypeSupported('audio/ogg')) {
                        mimeType = 'audio/ogg';
                    }

                    mediaRecorder.value = new MediaRecorder(stream, { mimeType });
                    audioChunks.value = [];

                    mediaRecorder.value.ondataavailable = (event) => {
                        if (event.data.size > 0) {
                            audioChunks.value.push(event.data);
                        }
                    };

                    mediaRecorder.value.onstop = () => {
                        // 停止所有音轨
                        stream.getTracks().forEach(track => track.stop());
                    };

                    mediaRecorder.value.start();
                    isRecording.value = true;
                    recordingStartTime.value = Date.now();
                    recordingDuration.value = 0;

                    // 更新录制时间
                    recordingTimer.value = setInterval(() => {
                        recordingDuration.value = Date.now() - recordingStartTime.value;
                        // 最长录制60秒
                        if (recordingDuration.value >= 60000) {
                            stopVoiceRecord();
                        }
                    }, 100);

                    window.Toast.info('开始录音，松开发送');
                } catch (error) {
                    console.error('[语音] 录音启动失败:', error);
                    window.Toast.error('录音启动失败: ' + error.message);
                    isRecording.value = false;
                }
            };

            // 停止录音并发送
            const stopVoiceRecord = async () => {
                if (!isRecording.value || !mediaRecorder.value) return;

                // 清除计时器
                if (recordingTimer.value) {
                    clearInterval(recordingTimer.value);
                    recordingTimer.value = null;
                }

                const duration = recordingDuration.value;
                isRecording.value = false;

                // 录音时间太短
                if (duration < 1000) {
                    mediaRecorder.value.stop();
                    window.Toast.warning('录音时间太短');
                    return;
                }

                // 停止录音
                return new Promise((resolve) => {
                    mediaRecorder.value.onstop = async () => {
                        // 停止所有音轨
                        if (mediaRecorder.value.stream) {
                            mediaRecorder.value.stream.getTracks().forEach(track => track.stop());
                        }

                        // 创建音频Blob
                        const audioBlob = new Blob(audioChunks.value, { type: mediaRecorder.value.mimeType });

                        // 发送语音消息
                        await sendVoiceMessage(audioBlob, duration);
                        resolve();
                    };
                    mediaRecorder.value.stop();
                });
            };

            // 取消录音
            const cancelVoiceRecord = () => {
                if (!isRecording.value || !mediaRecorder.value) return;

                // 清除计时器
                if (recordingTimer.value) {
                    clearInterval(recordingTimer.value);
                    recordingTimer.value = null;
                }

                isRecording.value = false;
                mediaRecorder.value.stop();
                window.Toast.info('已取消录音');
            };

            // 发送语音消息
            const sendVoiceMessage = async (audioBlob, duration) => {
                if (!audioBlob || audioBlob.size === 0) {
                    window.Toast.error('录音数据为空');
                    return;
                }

                const tempId = 'temp_voice_' + Date.now();

                // 创建FormData上传
                const formData = new FormData();
                formData.append('file', audioBlob, 'voice.webm');
                formData.append('room_id', roomId.value);
                formData.append('duration', Math.floor(duration / 1000));

                try {
                    window.Toast.info('正在发送语音...');

                    const response = await fetch('/api/message/sendVoice', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });

                    if (response.status === 401) {
                        localStorage.removeItem('userInfo');
                        window.Toast.error('登录已过期，请重新登录');
                        setTimeout(() => { window.location.href = '/login'; }, 2000);
                        return;
                    }

                    const result = await response.json();

                    if (result.code === 0) {
                        window.Toast.success('语音发送成功');
                        // 刷新消息列表
                        await fetchMessages();
                        scrollToBottom();
                    } else {
                        window.Toast.error(result.msg || '语音发送失败');
                    }
                } catch (error) {
                    console.error('[语音] 发送失败:', error);
                    window.Toast.error('语音发送失败: ' + error.message);
                }
            };

            const handleImageSelect = (event) => {
                const file = event.target.files[0];
                if (!file) {
                    return;
                }

                const maxSize = 20 * 1024 * 1024;
                if (file.size > maxSize) {
                    window.Toast.error('图片大小不能超过20M！');
                    event.target.value = '';
                    return;
                }

                if (!file.type.startsWith('image/')) {
                    window.Toast.error('请选择图片文件！');
                    event.target.value = '';
                    return;
                }

                selectedImageFile.value = file;

                const reader = new FileReader();
                reader.onload = (e) => {
                    imagePreview.value = e.target.result;
                };
                reader.onerror = () => {
                    window.Toast.error('图片读取失败！');
                };
                reader.readAsDataURL(file);
            };

            const clearImagePreview = () => {
                imagePreview.value = null;
                selectedImageFile.value = null;
                imageInput.value.value = '';
            };

            const sendImageMessage = async () => {
                if (!selectedImageFile.value) return;

                if (!roomId.value) {
                    window.Toast.error('请先加入房间');
                    clearImagePreview();
                    return;
                }

                if (isSending.value) return; // 防止重复发送

                // 生成临时消息ID
                const tempId = 'temp_' + Date.now();
                const previewUrl = imagePreview.value;
                const fileToUpload = selectedImageFile.value; // 保存文件引用，避免被clearImagePreview清空

                // 立即添加消息到列表，状态为发送中
                const newMsg = {
                    id: tempId,
                    type: 'image',
                    username: username.value,
                    imageUrl: previewUrl, // 使用当前预览URL
                    time: new Date(),
                    isOwn: true,
                    isRead: false, // 新发送的消息默认未读
                    isNewMessage: true // 自己发送的消息也需要动画
                };

                messages.value.push(newMsg);
                messageSendStatus.value[tempId] = 'sending';

                // 初始化上传进度
                uploadProgress.value[tempId] = 0;

                // 启动模拟进度（快速到60%，然后慢慢增加到85%等待HTTP回调）
                let progress = 0;
                uploadProgressTimers.value[tempId] = setInterval(function () {
                    if (progress < 60) {
                        // 快速阶段：每100ms增加3-6%
                        progress += Math.random() * 3 + 3;
                    } else if (progress < 85) {
                        // 慢速阶段：每100ms增加0.2-0.5%
                        progress += Math.random() * 0.3 + 0.2;
                    }
                    // 最多到85%，剩下15%等HTTP回调
                    progress = Math.min(progress, 85);
                    uploadProgress.value[tempId] = Math.round(progress);
                }, 100);

                // 动画播放后立即移除标记，避免后续更新时重复播放
                setTimeout(function () {
                    const msgIndex = messages.value.findIndex(function (m) { return m.id === tempId; });
                    if (msgIndex > -1) {
                        messages.value[msgIndex].isNewMessage = false;
                    }
                }, 300);

                nextTick(function () {
                    scrollToBottom();
                });

                // 清理进度的辅助函数 - 平滑过渡到100%
                const cleanupProgress = function (msgId, newMsgId) {
                    // 停止模拟进度定时器
                    if (uploadProgressTimers.value[msgId]) {
                        clearInterval(uploadProgressTimers.value[msgId]);
                        delete uploadProgressTimers.value[msgId];
                    }

                    // 平滑过渡到100%
                    const currentProgress = uploadProgress.value[msgId] || 0;
                    const remaining = 100 - currentProgress;
                    const steps = 8; // 分8步完成
                    const stepValue = remaining / steps;
                    let step = 0;

                    const smoothTimer = setInterval(function () {
                        step++;
                        uploadProgress.value[msgId] = Math.min(Math.round(currentProgress + stepValue * step), 100);

                        if (step >= steps) {
                            clearInterval(smoothTimer);
                            uploadProgress.value[msgId] = 100;

                            // 100%停留500ms后再清理
                            setTimeout(function () {
                                delete uploadProgress.value[msgId];
                                if (newMsgId && newMsgId !== msgId) {
                                    delete uploadProgress.value[newMsgId];
                                }
                            }, 500);
                        }
                    }, 25);
                };

                try {
                    isSending.value = true;

                    // 创建FormData对象上传文件
                    const formData = new FormData();
                    formData.append('image', fileToUpload); // 使用保存的文件引用
                    formData.append('room_id', roomId.value);

                    // 调用后端API发送图片消息
                    const headers = {};
                    if (currentUser.value.token) {
                        headers['Authorization'] = `Bearer ${currentUser.value.token}`;
                    }

                    const response = await fetch('/api/message/sendImage', {
                        method: 'POST',
                        headers: headers,
                        body: formData,
                        credentials: 'same-origin'
                    });

                    const result = await response.json();

                    if (result.code === 0 && result.data) {
                        // 发送成功，清理进度
                        cleanupProgress(tempId, result.data.id);

                        // 更新消息ID和真实图片URL
                        const msgIndex = messages.value.findIndex(function (m) { return m.id === tempId; });
                        if (msgIndex > -1) {
                            messages.value[msgIndex].id = result.data.id;

                            // 预加载服务器返回的图片URL，避免切换时闪动
                            const serverImageUrl = result.data.imageUrl;
                            const preloadImg = new Image();
                            preloadImg.onload = function () {
                                // 图片加载完成后再更新URL，避免闪动
                                const idx = messages.value.findIndex(function (m) { return m.id === result.data.id; });
                                if (idx > -1) {
                                    messages.value[idx].imageUrl = serverImageUrl;
                                }
                            };
                            preloadImg.onerror = function () {
                                // 加载失败也更新，使用服务器URL
                                const idx = messages.value.findIndex(function (m) { return m.id === result.data.id; });
                                if (idx > -1) {
                                    messages.value[idx].imageUrl = serverImageUrl;
                                }
                            };
                            preloadImg.src = serverImageUrl;

                            // iOS Safari 兼容性：确保服务器时间格式正确
                            let serverTime = result.data.time;
                            if (typeof serverTime === 'string') {
                                serverTime = serverTime.replace(/-/g, '/');
                            }
                            messages.value[msgIndex].time = new Date(serverTime);

                            // 更新状态为成功（常驻显示）
                            delete messageSendStatus.value[tempId];
                            messageSendStatus.value[result.data.id] = 'success';

                            // 通过 WebSocket 通知其他用户有新图片消息（使用统一函数）
                            broadcastMessageViaWebSocket(result.data.id, 'image', result.data.imageUrl);
                        }
                    } else {
                        // 发送失败，清理进度
                        cleanupProgress(tempId);
                        messageSendStatus.value[tempId] = 'failed';
                        window.Toast.error(result.msg || '图片发送失败');
                    }

                } catch (error) {
                    // 发送失败，清理进度
                    cleanupProgress(tempId);
                    messageSendStatus.value[tempId] = 'failed';
                    window.Toast.error('图片发送失败：' + error.message);
                } finally {
                    isSending.value = false;
                    // 无论成功还是失败，都清空图片预览
                    clearImagePreview();
                }
            };

            const scrollToBottom = function () {
                const container = messagesContainer.value;
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            };

            // 检查容器是否有滚动条（内容是否超出可视区域）
            const hasScrollbar = function () {
                const container = messagesContainer.value;
                if (!container) return false;
                return container.scrollHeight > container.clientHeight;
            };

            // 检查用户是否在底部（距离底部50px以内算在底部）
            const isUserAtBottom = function () {
                const container = messagesContainer.value;
                if (!container) return false;

                const threshold = 50; // 50px的容差
                const scrollBottom = container.scrollHeight - container.scrollTop - container.clientHeight;
                return scrollBottom <= threshold;
            };

            // 滚动到底部并隐藏提示
            const scrollToBottomAndHideTip = function () {
                scrollToBottom();
                showNewMessageTip.value = false;
                newMessageCount.value = 0;
            };

            // 处理粘贴事件
            const handlePaste = function (e) {
                // 检查是否有图片数据 - iOS Safari 兼容性
                const items = e.clipboardData && e.clipboardData.items;
                if (!items) return;

                for (let i = 0; i < items.length; i++) {
                    const item = items[i];

                    // 检查是否为图片类型
                    if (item.type.indexOf('image') !== -1) {
                        e.preventDefault(); // 阻止默认粘贴行为

                        const file = item.getAsFile();
                        if (file) {
                            // 使用现有的图片选择逻辑
                            selectedImageFile.value = file;

                            // 生成预览
                            const reader = new FileReader();
                            reader.onload = function (evt) {
                                imagePreview.value = evt.target.result;
                            };
                            reader.onerror = function () {
                                window.Toast.error('读取图片失败！');
                            };
                            reader.readAsDataURL(file);
                        }
                        break;
                    }
                }
            };

            // 切换房间
            const switchRoom = async function (room) {
                messagesLoading.value = true; // 立即显示loading

                roomName.value = room.name;
                roomId.value = room.id;

                // 清空已标记已读的消息ID集合（切换房间时重置）
                markedAsReadIds.value = new Set();

                // 保存当前房间ID到本地
                localStorage.setItem('lastRoomId', room.id);

                try {
                    // 获取房间信息
                    await getRoomInfo(room.id);

                    // 加载房间历史消息（内部有loading，但我们已经显示了）
                    await loadRoomMessages(room.id);

                    // WebSocket 加入房间
                    joinWebSocketRoom(room.id);

                    // 如果自动刷新已开启且 WebSocket 未连接，重启定时器
                    if (autoRefresh.value && !wsConnected.value) {
                        startAutoRefresh();
                    }
                } catch (error) {
                    messagesLoading.value = false;
                }
            };

            // 处理图片点击 - 使用Vue响应式
            const handleImageClick = (imageUrl) => {
                if (imageUrl) {
                    currentImageUrl.value = imageUrl;
                    isImageModalOpen.value = true;
                    // 重置缩放和位置
                    imageScale.value = 1;
                    imagePosition.value = { x: 0, y: 0 };
                }
            };

            // 获取两点间距离
            const getDistance = (touch1, touch2) => {
                const dx = touch1.clientX - touch2.clientX;
                const dy = touch1.clientY - touch2.clientY;
                return Math.sqrt(dx * dx + dy * dy);
            };

            // 获取两点中心
            const getCenter = (touch1, touch2) => {
                return {
                    x: (touch1.clientX + touch2.clientX) / 2,
                    y: (touch1.clientY + touch2.clientY) / 2
                };
            };

            // 重置图片状态
            const resetImageState = () => {
                imageScale.value = 1;
                imagePosition.value = { x: 0, y: 0 };
            };

            // 处理触摸开始
            const handleTouchStart = (e) => {
                e.preventDefault();
                touchStartTime.value = Date.now();
                hasMoved.value = false;

                if (e.touches.length === 1) {
                    // 单指开始：记录拖拽起点
                    const touch = e.touches[0];
                    dragStartPos.value = {
                        x: touch.clientX,
                        y: touch.clientY
                    };
                    imageStartPos.value = {
                        x: imagePosition.value.x,
                        y: imagePosition.value.y
                    };
                    isDragging.value = true;
                    isZooming.value = false;
                } else if (e.touches.length === 2) {
                    // 双指开始：切换到缩放模式
                    isDragging.value = false;
                    isZooming.value = true;

                    const distance = getDistance(e.touches[0], e.touches[1]);
                    lastTouchDistance.value = distance;
                    initialScale.value = imageScale.value;
                }
            };

            // 处理触摸移动
            const handleTouchMove = (e) => {
                e.preventDefault();

                if (e.touches.length === 1 && isDragging.value) {
                    // 单指拖拽：计算移动距离并应用
                    const touch = e.touches[0];
                    const deltaX = touch.clientX - dragStartPos.value.x;
                    const deltaY = touch.clientY - dragStartPos.value.y;

                    // 检测是否有移动
                    if (Math.abs(deltaX) > 5 || Math.abs(deltaY) > 5) {
                        hasMoved.value = true;
                    }

                    imagePosition.value = {
                        x: imageStartPos.value.x + deltaX,
                        y: imageStartPos.value.y + deltaY
                    };
                } else if (e.touches.length === 2) {
                    // 切换到双指模式
                    if (!isZooming.value) {
                        isDragging.value = false;
                        isZooming.value = true;
                        const distance = getDistance(e.touches[0], e.touches[1]);
                        lastTouchDistance.value = distance;
                        initialScale.value = imageScale.value;
                    } else {
                        // 双指缩放
                        const distance = getDistance(e.touches[0], e.touches[1]);

                        if (lastTouchDistance.value > 0) {
                            const scaleRatio = distance / lastTouchDistance.value;
                            const newScale = Math.max(0.1, Math.min(5, initialScale.value * scaleRatio));
                            imageScale.value = newScale;
                        }
                    }
                }
            };

            // 处理触摸结束
            const handleTouchEnd = (e) => {
                e.preventDefault();

                isDragging.value = false;
                isZooming.value = false;
                lastTouchDistance.value = 0;

                // 简化：不要自动放大，让用户自己控制
            };

            // 鼠标拖拽支持
            const handleMouseDown = (e) => {
                e.preventDefault();

                dragStartPos.value = {
                    x: e.clientX,
                    y: e.clientY
                };
                imageStartPos.value = {
                    x: imagePosition.value.x,
                    y: imagePosition.value.y
                };
                isDragging.value = true;
                hasMoved.value = false;
            };

            const handleMouseMove = (e) => {
                if (!isDragging.value) return;

                e.preventDefault();
                const deltaX = e.clientX - dragStartPos.value.x;
                const deltaY = e.clientY - dragStartPos.value.y;

                // 检测是否有移动
                if (Math.abs(deltaX) > 5 || Math.abs(deltaY) > 5) {
                    hasMoved.value = true;
                }

                imagePosition.value = {
                    x: imageStartPos.value.x + deltaX,
                    y: imageStartPos.value.y + deltaY
                };
            };

            const handleMouseUp = (e) => {
                e.preventDefault();
                isDragging.value = false;
            };

            // 处理鼠标滚轮缩放
            const handleWheel = (e) => {
                e.preventDefault();

                const delta = e.deltaY;
                const scaleFactor = delta > 0 ? 0.9 : 1.1;
                const newScale = Math.max(0.1, Math.min(5, imageScale.value * scaleFactor));

                imageScale.value = newScale;
            };

            // 处理模态框点击（防止拖拽时意外关闭）
            const handleModalClick = (e) => {
                // 只有在没有移动且没有缩放时才关闭
                if (!hasMoved.value && !isZooming.value) {
                    closeImageModal();
                }
            };

            // 关闭图片模态框 - 使用Vue响应式
            const closeImageModal = () => {
                isImageModalOpen.value = false;
                currentImageUrl.value = '';
            };

            const toggleSidebar = () => {
                sidebarOpen.value = !sidebarOpen.value;
            };

            // 切换标签（联系人/群聊）
            const switchTab = (tab) => {
                activeTab.value = tab;
                if (tab === 'contacts') {
                    loadContactList();
                }
            };

            // 加载联系人列表
            const loadContactList = async () => {
                try {
                    const response = await apiRequest('/api/contact/list');
                    const result = await response.json();
                    if (result.code === 0) {
                        contactList.value = result.data.contacts || [];
                    }
                } catch (error) {
                    console.error('[loadContactList] 加载联系人列表失败:', error);
                }
            };

            // 显示添加联系人对话框
            const showAddContactDialog = () => {
                // TODO: 实现添加联系人对话框
                window.Toast?.info('添加联系人功能开发中...');
            };

            const toggleTheme = () => {
                isDarkMode.value = !isDarkMode.value;
                // 手动操作DOM class（因为Vue的:class绑定不生效）
                const appEl = document.getElementById('app');
                if (isDarkMode.value) {
                    appEl.classList.add('dark-mode');
                } else {
                    appEl.classList.remove('dark-mode');
                }
                // 保存到 localStorage
                localStorage.setItem('isDarkMode', isDarkMode.value.toString());
            };

            const toggleAutoRefresh = () => {
                autoRefresh.value = !autoRefresh.value;

                if (autoRefresh.value) {
                    // 开启自动刷新，每3秒刷新一次
                    startAutoRefresh();
                } else {
                    // 关闭自动刷新
                    stopAutoRefresh();
                }
            };

            const startAutoRefresh = function () {
                if (autoRefreshTimer.value) {
                    clearInterval(autoRefreshTimer.value);
                }

                autoRefreshTimer.value = setInterval(async function () {
                    if (roomId.value) {
                        try {
                            // 自动刷新：不滚动，不显示loading
                            await loadRoomMessages(roomId.value, false, false);
                        } catch (error) {
                            // 自动刷新失败，静默处理
                        }
                    }
                }, 3000); // 3秒刷新一次
            };

            const stopAutoRefresh = () => {
                if (autoRefreshTimer.value) {
                    clearInterval(autoRefreshTimer.value);
                    autoRefreshTimer.value = null;
                }
            };

            // 处理页面可见性变化（解决失焦后轮询失效问题）
            const handleVisibilityChange = () => {
                if (document.hidden) {
                    // 页面隐藏时，停止轮询节省资源
                    if (autoRefreshTimer.value) {
                        stopAutoRefresh();
                    }
                } else {
                    // 页面重新可见时
                    if (roomId.value) {
                        // 如果开启了自动刷新且 WebSocket 未连接，则重启轮询
                        if (autoRefresh.value && !wsConnected.value) {
                            startAutoRefresh();
                            // 立即刷新一次获取最新消息
                            loadRoomMessages(roomId.value, false, false);
                        }

                        // 页面重新可见时，检测并标记可见区域内的未读消息
                        setTimeout(function () {
                            checkAndMarkVisibleMessages();
                        }, 200);
                    }
                }
            };

            const manualRefresh = async () => {
                if (!roomId.value) {
                    return;
                }

                try {
                    // 手动刷新：不滚动，但显示loading
                    await loadRoomMessages(roomId.value, false, true);
                } catch (error) {
                    // 刷新失败，静默处理
                }
            };

            // 显示右键菜单
            const showContextMenu = (event, message) => {
                event.preventDefault();

                const menuWidth = 180;
                const menuHeight = 200;
                const windowWidth = window.innerWidth;
                const windowHeight = window.innerHeight;

                let x = event.clientX;
                let y = event.clientY;

                // 防止菜单超出屏幕
                if (x + menuWidth > windowWidth) {
                    x = windowWidth - menuWidth - 10;
                }
                if (y + menuHeight > windowHeight) {
                    y = windowHeight - menuHeight - 10;
                }

                contextMenu.value = {
                    show: true,
                    x: x,
                    y: y,
                    message: message
                };
            };

            // 隐藏右键菜单
            const hideContextMenu = () => {
                contextMenu.value.show = false;
            };

            // 焚毁消息
            const burnMessage = async () => {
                const message = contextMenu.value.message;
                if (!message) {
                    hideContextMenu();
                    return;
                }

                // 检查是否是自己的消息
                if (!message.isOwn) {
                    window.Toast.error('只能焚毁自己的消息');
                    hideContextMenu();
                    return;
                }

                try {
                    // 添加焚毁动画
                    const msgElement = document.querySelector(`[data-msg-id="${message.id}"]`);
                    if (msgElement) {
                        msgElement.classList.add('msg-burning');
                    }

                    // 调用后端API焚毁消息
                    const response = await apiRequest('/api/message/burn', {
                        method: 'POST',
                        body: JSON.stringify({
                            message_id: message.id
                        })
                    });

                    const result = await response.json();

                    if (result.code === 0) {
                        // 延迟删除消息（等待动画完成）
                        setTimeout(() => {
                            const index = messages.value.findIndex(m => m.id === message.id);
                            if (index > -1) {
                                messages.value.splice(index, 1);
                            }
                        }, 600); // 0.6秒，匹配动画时长
                    } else {
                        // 焚毁失败，移除动画
                        if (msgElement) {
                            msgElement.classList.remove('msg-burning');
                        }
                        Toast.error(result.msg || '焚毁失败');
                    }
                } catch (error) {
                    Toast.error('焚毁消息失败，请重试');
                    // 移除动画
                    const msgElement = document.querySelector(`[data-msg-id="${message.id}"]`);
                    if (msgElement) {
                        msgElement.classList.remove('msg-burning');
                    }
                }
                hideContextMenu();
            };

            const formatTime = (date) => {
                if (!date) return '';
                // iOS Safari 兼容性：将 YYYY-MM-DD 格式转换为 YYYY/MM/DD
                const safeDate = typeof date === 'string' ? date.replace(/-/g, '/') : date;

                const now = new Date();
                const messageDate = new Date(safeDate);

                // 如果日期无效，尝试直接解析或返回原字符串
                if (isNaN(messageDate.getTime())) {
                    return date;
                }

                const diffInMinutes = Math.floor((now - messageDate) / 60000);

                if (diffInMinutes < 1) return '刚刚';
                if (diffInMinutes < 60) return `${diffInMinutes}分钟前`;

                if (diffInMinutes < 1440) {
                    const hour = messageDate.getHours().toString().padStart(2, '0');
                    const minute = messageDate.getMinutes().toString().padStart(2, '0');
                    return `${hour}:${minute}`;
                }

                const month = (messageDate.getMonth() + 1).toString().padStart(2, '0');
                const day = messageDate.getDate().toString().padStart(2, '0');
                const hour = messageDate.getHours().toString().padStart(2, '0');
                const minute = messageDate.getMinutes().toString().padStart(2, '0');
                return `${month}-${day} ${hour}:${minute}`;
            };

            // 格式化URL显示（保留完整链接）
            const formatUrl = function (url) {
                if (!url) return '';
                // 直接返回完整URL
                return url;
            };

            const formatFileSize = (bytes) => {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            };

            // 格式化视频时长（秒 -> MM:SS 或 HH:MM:SS）
            const formatDuration = (seconds) => {
                if (!seconds || seconds < 0) return '0:00';
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                const secs = Math.floor(seconds % 60);

                if (hours > 0) {
                    return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
                }
                return `${minutes}:${secs.toString().padStart(2, '0')}`;
            };

            // 获取文件扩展名（从文件名提取）
            const getFileExtension = (fileName) => {
                if (!fileName) return '';
                const lastDot = fileName.lastIndexOf('.');
                if (lastDot > -1 && lastDot < fileName.length - 1) {
                    return fileName.substring(lastDot + 1).toUpperCase();
                }
                return '';
            };

            // 获取文件图标（根据文件扩展名）
            const getFileIcon = (fileNameOrExtension) => {
                const ext = (fileNameOrExtension || '').toString().toLowerCase().replace('.', '').split('.').pop();
                const iconMap = {
                    // 图片
                    'jpg': 'fas fa-file-image', 'jpeg': 'fas fa-file-image', 'png': 'fas fa-file-image',
                    'gif': 'fas fa-file-image', 'webp': 'fas fa-file-image', 'svg': 'fas fa-file-image',
                    'bmp': 'fas fa-file-image', 'ico': 'fas fa-file-image', 'tiff': 'fas fa-file-image',
                    'psd': 'fas fa-file-image', 'ai': 'fas fa-file-image', 'eps': 'fas fa-file-image',
                    // 视频
                    'mp4': 'fas fa-file-video', 'webm': 'fas fa-file-video', 'ogg': 'fas fa-file-video',
                    'mov': 'fas fa-file-video', 'avi': 'fas fa-file-video', 'mkv': 'fas fa-file-video',
                    'flv': 'fas fa-file-video', 'wmv': 'fas fa-file-video', 'm4v': 'fas fa-file-video',
                    '3gp': 'fas fa-file-video', 'ts': 'fas fa-file-video', 'mts': 'fas fa-file-video',
                    // 音频
                    'mp3': 'fas fa-file-audio', 'wav': 'fas fa-file-audio', 'ogg': 'fas fa-file-audio',
                    'flac': 'fas fa-file-audio', 'aac': 'fas fa-file-audio', 'm4a': 'fas fa-file-audio',
                    'wma': 'fas fa-file-audio', 'opus': 'fas fa-file-audio',
                    // 文档
                    'pdf': 'fas fa-file-pdf', 'doc': 'fas fa-file-word', 'docx': 'fas fa-file-word',
                    'xls': 'fas fa-file-excel', 'xlsx': 'fas fa-file-excel', 'ppt': 'fas fa-file-powerpoint',
                    'pptx': 'fas fa-file-powerpoint', 'txt': 'fas fa-file-alt', 'md': 'fas fa-file-alt',
                    'rtf': 'fas fa-file-alt', 'odt': 'fas fa-file-word', 'ods': 'fas fa-file-excel',
                    'odp': 'fas fa-file-powerpoint', 'csv': 'fas fa-file-csv',
                    // 压缩包
                    'zip': 'fas fa-file-archive', 'rar': 'fas fa-file-archive', '7z': 'fas fa-file-archive',
                    'tar': 'fas fa-file-archive', 'gz': 'fas fa-file-archive', 'bz2': 'fas fa-file-archive',
                    'xz': 'fas fa-file-archive', 'cab': 'fas fa-file-archive', 'iso': 'fas fa-file-archive',
                    // 代码
                    'js': 'fas fa-file-code', 'ts': 'fas fa-file-code', 'html': 'fas fa-file-code',
                    'css': 'fas fa-file-code', 'json': 'fas fa-file-code', 'xml': 'fas fa-file-code',
                    'py': 'fas fa-file-code', 'java': 'fas fa-file-code', 'php': 'fas fa-file-code',
                    'cpp': 'fas fa-file-code', 'c': 'fas fa-file-code', 'h': 'fas fa-file-code',
                    'go': 'fas fa-file-code', 'rs': 'fas fa-file-code', 'swift': 'fas fa-file-code',
                    'kt': 'fas fa-file-code', 'dart': 'fas fa-file-code', 'vue': 'fas fa-file-code',
                    'jsx': 'fas fa-file-code', 'tsx': 'fas fa-file-code', 'sql': 'fas fa-file-code',
                    'sh': 'fas fa-file-code', 'bash': 'fas fa-file-code', 'yml': 'fas fa-file-code',
                    'yaml': 'fas fa-file-code', 'toml': 'fas fa-file-code', 'ini': 'fas fa-file-code',
                    'conf': 'fas fa-file-code', 'cfg': 'fas fa-file-code', 'rb': 'fas fa-file-code',
                    'pl': 'fas fa-file-code', 'scala': 'fas fa-file-code', 'r': 'fas fa-file-code',
                    'm': 'fas fa-file-code', 'mm': 'fas fa-file-code', 'swift': 'fas fa-file-code',
                    // 可执行文件
                    'exe': 'fas fa-file', 'msi': 'fas fa-file', 'app': 'fas fa-file',
                    'dmg': 'fas fa-file', 'deb': 'fas fa-file', 'rpm': 'fas fa-file',
                    'apk': 'fas fa-file', 'ipa': 'fas fa-file', 'bin': 'fas fa-file',
                    'elf': 'fas fa-file', 'so': 'fas fa-file', 'dll': 'fas fa-file',
                    'sys': 'fas fa-file', 'drv': 'fas fa-file',
                    // 数据库
                    'db': 'fas fa-database', 'sqlite': 'fas fa-database', 'mdb': 'fas fa-database',
                    'accdb': 'fas fa-database',
                    // 其他常见格式
                    'key': 'fas fa-key', 'pem': 'fas fa-key', 'cert': 'fas fa-certificate',
                    'torrent': 'fas fa-download', 'jar': 'fas fa-file-archive'
                };
                return iconMap[ext] || 'fas fa-file';
            };

            // 获取文件图标CSS类（用于着色）
            const getFileIconClass = (fileNameOrExtension) => {
                const ext = (fileNameOrExtension || '').toString().toLowerCase().replace('.', '').split('.').pop();

                if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tiff', 'psd', 'ai', 'eps'].includes(ext)) {
                    return 'image';
                }
                if (['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v', '3gp', 'ts', 'mts'].includes(ext)) {
                    return 'video';
                }
                if (['mp3', 'wav', 'flac', 'aac', 'm4a', 'wma', 'opus'].includes(ext)) {
                    return 'audio';
                }
                if (['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'cab', 'iso', 'jar'].includes(ext)) {
                    return 'archive';
                }
                if (['js', 'ts', 'html', 'css', 'json', 'py', 'java', 'php', 'cpp', 'go', 'vue', 'jsx', 'tsx',
                    'sh', 'bash', 'yml', 'yaml', 'toml', 'ini', 'conf', 'cfg', 'rb', 'pl', 'scala', 'r'].includes(ext)) {
                    return 'code';
                }
                if (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'rtf', 'odt', 'ods', 'odp', 'csv'].includes(ext)) {
                    return 'document';
                }
                if (['exe', 'msi', 'app', 'dmg', 'deb', 'rpm', 'apk', 'ipa', 'bin', 'elf', 'so', 'dll', 'sys', 'drv'].includes(ext)) {
                    return 'default';
                }
                if (['db', 'sqlite', 'mdb', 'accdb'].includes(ext)) {
                    return 'document';
                }
                return 'default';
            };

            // 处理视频点击（播放视频）
            const handleVideoClick = (videoUrl) => {
                if (!videoUrl) return;
                // 可以扩展为打开视频播放器模态框
                window.open(videoUrl, '_blank');
            };

            // 统一的WebSocket消息广播函数
            // fileInfo 参数用于视频/文件消息，包含完整的元数据
            const broadcastMessageViaWebSocket = (messageId, messageType, content, fileInfo = null) => {
                if (!wsClient.value || !wsClient.value.isConnected || !messageId) {
                    return;
                }

                // 确定message_type（字符串格式）
                let wsMessageType = 'text';
                if (messageType === 'image' || messageType === 2) wsMessageType = 'image';
                else if (messageType === 'video' || messageType === 5) wsMessageType = 'video';
                else if (messageType === 'file' || messageType === 3) wsMessageType = 'file';
                else if (messageType === 'text' || messageType === 1 || messageType === 'normal') wsMessageType = 'text';

                const messageData = {
                    type: 'message',
                    message_id: messageId,
                    message_type: wsMessageType,
                    content: content || ''
                };

                // 视频消息：附加视频元数据
                if (wsMessageType === 'video' && fileInfo) {
                    messageData.video_url = fileInfo.videoUrl || content;
                    messageData.video_thumbnail = fileInfo.videoThumbnail || null;
                    messageData.video_duration = fileInfo.videoDuration || null;
                }

                // 文件消息：附加文件元数据
                if (wsMessageType === 'file' && fileInfo) {
                    messageData.file_name = fileInfo.fileName || content;
                    messageData.file_size = fileInfo.fileSize || 0;
                    messageData.file_extension = fileInfo.fileExtension || '';
                    messageData.file_url = fileInfo.fileUrl || '';
                }

                wsClient.value.send(messageData);
            };

            // 显示功能开发中提示
            const showComingSoon = (featureName) => {
                window.Toast.info(`${featureName}功能正在开发中，敬请期待！`);
            };

            // 清空消息确认对话框
            const confirmClearMessages = () => {
                if (!roomId.value) {
                    window.Toast.error('请先加入房间');
                    return;
                }

                if (messages.value.length === 0) {
                    window.Toast.info('当前房间没有消息');
                    return;
                }

                // 使用 confirm 对话框确认
                const confirmed = confirm('确定要清空当前房间的所有消息吗？\n\n此操作仅清除本地显示的消息，不会影响其他用户。');
                if (confirmed) {
                    clearMessages();
                }
            };

            // 清空当前房间的消息（仅前端）
            const clearMessages = () => {
                try {
                    // 清空消息列表
                    messages.value = [];

                    // 清空消息发送状态
                    messageSendStatus.value = {};

                    // 显示成功提示
                    window.Toast.success('已清空当前房间消息');
                } catch (error) {
                    window.Toast.error('清空消息失败：' + error.message);
                }
            };

            // 清理房间所有消息（房主或管理员可用）
            const clearRoomMessages = async () => {
                if (!roomId.value) {
                    window.Toast.error('请先加入房间');
                    return;
                }

                // 使用 confirm 对话框确认
                const confirmed = confirm('警告：此操作将物理删除房间的所有消息和上传的图片文件！\n\n此操作不可恢复，确定要继续吗？');
                if (!confirmed) {
                    return;
                }

                try {
                    globalLoading.value = true;
                    loadingText.value = '正在清理...';

                    // 调用后端API清理房间消息（后端会验证权限）
                    const response = await apiRequest('/api/message/clearRoom', {
                        method: 'POST',
                        body: JSON.stringify({
                            room_id: roomId.value
                        })
                    });

                    const result = await response.json();

                    if (result.code === 0) {
                        // 清空前端消息列表
                        messages.value = [];
                        messageSendStatus.value = {};

                        const deletedMsgs = result.data?.deleted_messages || 0;
                        const deletedFiles = result.data?.deleted_files || 0;

                        window.Toast.success(`清理成功！删除了${deletedMsgs}条消息和${deletedFiles}个文件`);
                    } else {
                        window.Toast.error(result.msg || '清理失败');
                    }
                } catch (error) {
                    window.Toast.error('清理失败：' + error.message);
                } finally {
                    globalLoading.value = false;
                }
            };

            // 计算属性：是否显示清理按钮（房主或管理员3306）
            const canClearRoom = computed(() => {
                const ADMIN_ID = 3306;
                const userId = currentUser.value.id;
                const ownerId = currentRoomOwnerId.value;

                // 管理员或房主可以清理
                return userId == ADMIN_ID || (ownerId && userId == ownerId);
            });

            // 退出登录
            const handleLogout = () => {
                if (!confirm('确定要退出登录吗？')) {
                    return;
                }

                // 断开 WebSocket
                if (wsClient.value) {
                    wsClient.value.disconnect();
                }

                // 清除本地存储
                localStorage.removeItem('userInfo');
                localStorage.removeItem('lastRoomId');

                // 跳转到登录页
                window.location.href = '/login';
            };

            // 打开用户资料弹窗
            const openUserProfile = () => {
                if (window.UserProfile && currentUser.value && currentUser.value.id) {
                    window.UserProfile.open(currentUser.value.id);
                }
            };

            // 生命周期
            // 从本地存储加载用户信息
            const loadCurrentUser = () => {
                try {
                    const userInfoStr = localStorage.getItem('userInfo');

                    if (userInfoStr) {
                        const userInfo = JSON.parse(userInfoStr);

                        if (!userInfo.token) {
                            window.location.href = '/login';
                            return false;
                        }

                        currentUser.value = {
                            id: userInfo.id,
                            nick_name: userInfo.nick_name,
                            avatar: userInfo.avatar,
                            token: userInfo.token
                        };
                        username.value = userInfo.nick_name; // 设置用户名
                    } else {
                        window.location.href = '/login';
                        return false;
                    }
                    return true;
                } catch (error) {
                    window.location.href = '/login';
                    return false;
                }
            };

            onMounted(() => {
                // 初始化深色模式class（因为Vue的:class绑定不生效）
                const appEl = document.getElementById('app');
                if (isDarkMode.value) {
                    appEl.classList.add('dark-mode');
                } else {
                    appEl.classList.remove('dark-mode');
                }

                // 从本地存储加载用户信息
                if (!loadCurrentUser()) {
                    return; // 如果没有用户信息，直接返回
                }

                // 从服务器获取最新用户信息并覆盖本地数据
                fetch('/api/user/profile', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include'
                })
                    .then(response => response.json())
                    .then(result => {
                        if (result.code === 0 && result.data.profile) {
                            const profile = result.data.profile;
                            // 更新全局用户状态
                            Object.assign(currentUser.value, {
                                id: profile.id,
                                nick_name: profile.nickname,
                                avatar: profile.avatar
                            });
                            username.value = profile.nickname;
                            console.log('[init] 从服务器获取最新用户信息成功');
                        }
                    })
                    .catch(error => {
                        console.error('[init] 获取用户信息失败:', error);
                    });

                // 加载表情数据
                fetch('/static/js/emoji-data.json')
                    .then(response => response.json())
                    .then(data => {
                        emojiCategories.value = data;
                    })
                    .catch(() => {
                        // 加载失败时使用空数组
                    });

                // 使用Vue响应式初始化模态框状态
                isImageModalOpen.value = false;
                currentImageUrl.value = '';

                // 获取用户信息和已加入的房间
                loadUserInfo().then(function () {
                    // 从localStorage加载已读状态
                    loadReadStatusFromStorage();

                    // 初始化 WebSocket
                    initWebSocket();

                    // WebSocket 连接需要时间，延迟检查是否需要启动轮询
                    // 如果3秒后 WebSocket 还没连接成功，才启动轮询
                    setTimeout(function () {
                        if (autoRefresh.value && roomId.value && !wsConnected.value) {
                            console.log('[轮询] WebSocket未连接，启动轮询');
                            startAutoRefresh();
                        }
                    }, 3000);
                });

                nextTick(() => {
                    scrollToBottom();
                });

                // 键盘事件
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        if (contextMenu.value.show) {
                            hideContextMenu();
                        } else if (sidebarOpen.value) {
                            sidebarOpen.value = false;
                        } else if (isImageModalOpen.value) {
                            closeImageModal();
                        }
                    }
                });

                // 页面卸载时清理定时器
                window.addEventListener('beforeunload', () => {
                    stopAutoRefresh();
                });

                // 点击其他地方关闭右键菜单
                document.addEventListener('click', () => {
                    if (contextMenu.value.show) {
                        hideContextMenu();
                    }
                });

                // 监听粘贴事件
                document.addEventListener('paste', handlePaste);

                // 监听系统主题变化
                if (window.matchMedia) {
                    const darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
                    const handleThemeChange = (e) => {
                        // 只在用户没有手动设置过主题时才跟随系统
                        if (localStorage.getItem('isDarkMode') === null) {
                            isDarkMode.value = e.matches;
                        }
                    };
                    // 检查初始系统主题
                    if (localStorage.getItem('isDarkMode') === null) {
                        isDarkMode.value = darkModeQuery.matches;
                    }
                    // 监听系统主题变化
                    darkModeQuery.addEventListener('change', handleThemeChange);
                }

                // 监听页面可见性变化（解决移动端失焦后轮询失效问题）
                document.addEventListener('visibilitychange', handleVisibilityChange);

                // 监听消息容器滚动事件，检测可见消息并标记已读
                nextTick(function () {
                    const container = messagesContainer.value;
                    if (container) {
                        container.addEventListener('scroll', handleMessagesScroll);
                    }
                });

            });

            // 返回响应式数据和方法
            return {
                roomName,
                roomId,
                username,
                currentUser,
                sidebarOpen,
                isDarkMode,
                showEmojiPicker,
                activeEmojiCategory,
                emojiCategories,
                autoRefresh,
                isImageModalOpen,
                currentImageUrl,
                imagePreview,
                selectedImageFile,
                onlineUsers,
                totalUsers,
                currentRoomOwnerId,
                onlineUsersList,
                roomList,
                contactList,
                activeTab,
                messages,
                newMessage,
                messagesContainer,
                imageInput,
                fileInput,
                sendMessage,
                triggerImageUpload,
                handleImageSelect,
                handleFileSelect,
                clearImagePreview,
                sendImageMessage,
                // 附件面板
                showAttachPanel,
                toggleAttachPanel,
                hideAttachPanel,
                selectImage,
                selectVideo,
                selectFile,
                clearRoomMessagesFromPanel,
                scrollToBottom,
                switchRoom,
                handleImageClick,
                closeImageModal,
                handleModalClick,
                // 图片缩放和拖拽
                imageStyle,
                imageContainerStyle,
                resetImageState,
                handleTouchStart,
                handleTouchMove,
                handleTouchEnd,
                handleMouseDown,
                handleMouseMove,
                handleMouseUp,
                handleWheel,
                toggleSidebar,
                switchTab,
                showAddContactDialog,
                loadContactList,
                toggleTheme,
                toggleAutoRefresh,
                startAutoRefresh,
                stopAutoRefresh,
                manualRefresh,
                formatTime,
                formatUrl,
                formatFileSize,
                formatDuration,
                getFileIcon,
                getFileExtension,
                getFileIconClass,
                handleVideoClick,
                broadcastMessageViaWebSocket,
                // 加入房间功能
                loadUserInfo,
                showJoinRoomDialog,
                showCreateRoomDialog,
                createRoom,
                joinRoom,
                getRoomInfo,
                loadRoomMessages,
                loadMoreMessages,
                hasMoreMessages,
                loadingMore,
                // 右键菜单相关
                contextMenu,
                showContextMenu,
                hideContextMenu,
                burnMessage,
                // Loading状态
                globalLoading,
                loadingText,
                messagesLoading,
                // 新消息提示
                showNewMessageTip,
                newMessageCount,
                scrollToBottomAndHideTip,
                // 粘贴处理
                handlePaste,
                // 表情相关
                toggleEmojiPicker,
                hideEmojiPicker,
                hideAllPanels,
                insertEmoji,
                // 更多面板
                showMorePanel,
                toggleMorePanel,
                hideMorePanel,
                handleEmojiTouchStart,
                handleEmojiTouchMove,
                handleEmojiTouchEnd,
                // 发送状态
                isSending,
                messageSendStatus,
                uploadProgress,
                // 语音录制
                isRecording,
                recordingTimeText,
                startVoiceRecord,
                stopVoiceRecord,
                cancelVoiceRecord,
                // 功能提示
                showComingSoon,
                // 清空消息
                confirmClearMessages,
                clearMessages,
                // 清理房间消息
                canClearRoom,
                clearRoomMessages,
                // 退出登录
                handleLogout,
                // 用户资料
                openUserProfile,
                // 工具函数
                isValidAvatar,
                // WebSocket 相关
                wsConnected,
                usePolling,
                initWebSocket,
                // 正在输入
                typingUsers,
                typingText,
                onInputFocus,
                onInputBlur,
                onInputChange
            };
        }
    });

    // 注册自定义指令
    app.directive('click-outside', clickOutside);

    app.mount('#app');

} catch (error) {
    alert('Vue 应用启动失败: ' + error.message);
}