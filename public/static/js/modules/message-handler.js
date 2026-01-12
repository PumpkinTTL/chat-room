/**
 * 消息处理模块
 * 负责消息格式化、URL检测、链接处理、消息类型转换
 */
(function(global) {
    'use strict';
    
    global.ChatApp = global.ChatApp || {};
    
    /**
     * 消息处理管理器
     */
    global.ChatApp.MessageHandler = {
        /**
         * 检测并解析文本中的URL
         * @param {string} text - 要检测的文本
         * @returns {Array} URL数组
         */
        detectUrls: function(text) {
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
        },

        /**
         * 格式化文本中的链接为HTML（支持链接卡片）
         * @param {string} text - 原始文本
         * @param {Object} message - 消息对象（用于保存URL卡片信息）
         * @returns {string} 格式化后的文本
         */
        formatTextWithLinks: function(text, message) {
            if (!text) return '';

            const urls = this.detectUrls(text);
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
        },

        /**
         * 处理单条消息数据（提取公共逻辑，避免重复）
         * @param {Object} msg - 原始消息对象
         * @param {boolean} isNewMessage - 是否是新消息
         * @param {Set} oldMessageIds - 旧消息ID集合
         * @param {Object} currentUser - 当前用户信息
         * @param {Object} messageSendStatus - 消息发送状态映射
         * @returns {Object} 处理后的消息对象
         */
        processMessage: function(msg, isNewMessage, oldMessageIds, currentUser, messageSendStatus) {
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
            
            // 重要：reply 类型的消息应该被当作 text 类型渲染（只是额外显示引用信息）
            if (messageType === 'reply') {
                messageType = 'text';
            }
            
            // 重要：normal 类型的消息也应该被当作 text 类型渲染
            if (messageType === 'normal') {
                messageType = 'text';
            }
            
            // 判断是否是时间分隔消息（system类型 + 内容格式为 MM-DD HH:mm）
            let isTimeSeparator = false;
            if (messageType === 'system') {
                const content = msg.content || msg.text || '';
                // 匹配格式：MM-DD HH:mm（如 01-09 14:30）
                isTimeSeparator = /^\d{2}-\d{2}\s+\d{2}:\d{2}$/.test(content.trim());
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
                readUsers: msg.read_users || [],
                isTimeSeparator: isTimeSeparator
            };
            
            // 时间分隔消息需要设置 text 字段用于显示
            if (isTimeSeparator) {
                processedMsg.text = msg.content || msg.text || '';
            }
            
            // 普通 system 消息也需要设置 text 字段（模板使用 message.text 显示）
            if (messageType === 'system' && !processedMsg.text) {
                processedMsg.text = msg.content || msg.text || '';
            }

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

            // 复制其他所有属性（包括 text、reply_to 等）
            for (const key in msg) {
                if (Object.prototype.hasOwnProperty.call(msg, key) && !processedMsg.hasOwnProperty(key)) {
                    processedMsg[key] = msg[key];
                }
            }

            // 确保 reply_to 字段被正确保留（重要！）
            if (msg.reply_to && !processedMsg.reply_to) {
                processedMsg.reply_to = msg.reply_to;
            }

            // 确保 text 字段被正确保留
            if (msg.text && !processedMsg.text) {
                processedMsg.text = msg.text;
            }
            
            // 文本消息需要设置 text 字段（从 content 或 text 获取）
            if (messageType === 'text' && !processedMsg.text) {
                processedMsg.text = msg.content || msg.text || '';
            }

            // 处理文本消息中的链接（使用转换后的 messageType 判断）
            if (messageType === 'text') {
                if (processedMsg.text) {
                    const urls = this.detectUrls(processedMsg.text);
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
            if (isOwnMessage && msg.id && messageSendStatus) {
                messageSendStatus.value[msg.id] = 'success';
            }

            return processedMsg;
        },

        /**
         * 批量处理消息
         * @param {Array} messagesArray - 消息数组
         * @param {boolean} isNewMessage - 是否是新消息
         * @param {Set} oldMessageIds - 旧消息ID集合
         * @param {Object} currentUser - 当前用户信息
         * @param {Object} messageSendStatus - 消息发送状态映射
         * @returns {Array} 处理后的消息数组
         */
        processMessages: function(messagesArray, isNewMessage, oldMessageIds, currentUser, messageSendStatus) {
            const self = this;
            const result = [];
            for (let i = 0; i < messagesArray.length; i++) {
                result.push(self.processMessage(messagesArray[i], isNewMessage, oldMessageIds, currentUser, messageSendStatus));
            }
            return result;
        },

        /**
         * 检查是否需要插入时间分隔
         * @param {Array} messages - 消息列表
         * @param {number} interval - 时间间隔（毫秒）
         * @returns {boolean} 是否需要插入
         */
        shouldInsertTimeSeparator: function(messages, interval) {
            if (messages.value.length === 0) return false;
            
            // 找最后一条非时间分隔的消息
            for (let i = messages.value.length - 1; i >= 0; i--) {
                const msg = messages.value[i];
                if (!msg.isTimeSeparator) {
                    if (!msg.time) return false;
                    const lastTime = msg.time instanceof Date ? msg.time : new Date(msg.time);
                    return (Date.now() - lastTime.getTime()) >= interval;
                }
            }
            return false;
        },

        /**
         * 插入时间分隔消息（如果需要）
         * @param {Array} messages - 消息列表
         * @param {string} roomId - 房间ID
         * @param {Function} apiRequest - API请求函数
         * @param {number} interval - 时间间隔（毫秒）
         * @returns {Promise<void>}
         */
        insertTimeSeparatorIfNeeded: async function(messages, roomId, apiRequest, interval) {
            if (!this.shouldInsertTimeSeparator(messages, interval)) return;
            
            const now = new Date();
            const text = String(now.getMonth() + 1).padStart(2, '0') + '-' +
                         String(now.getDate()).padStart(2, '0') + ' ' +
                         String(now.getHours()).padStart(2, '0') + ':' +
                         String(now.getMinutes()).padStart(2, '0');
            
            try {
                const response = await apiRequest('/api/message/sendSystem', {
                    method: 'POST',
                    body: JSON.stringify({ room_id: roomId, content: text })
                });
                const result = await response.json();
                if (result.code === 0 && result.data) {
                    messages.value.push({
                        id: result.data.id,
                        type: 'system',
                        text: text,
                        time: now,
                        isTimeSeparator: true,
                        isOwn: false
                    });
                }
            } catch (error) {
                console.error('[TimeSeparator] 写入失败:', error);
            }
        }
    };
    
})(window);
