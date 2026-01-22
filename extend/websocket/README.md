# WebSocket 服务器模块化文档

本目录包含从 `server.php` 拆分出来的 WebSocket 服务器功能模块。这些模块提供了更清晰的代码组织结构，便于后续功能开发和维护。

## 📁 模块列表

### 1. RedisHelper.php
**Redis 连接管理器**

负责管理 Redis 连接，提供单例模式的连接获取。

**主要方法：**
- `getConnection()` - 获取 Redis 连接实例

**使用示例：**
```php
use extend\websocket\RedisHelper;

$redis = RedisHelper::getConnection();
$redis->set('key', 'value');
```

---

### 2. ConnectionManager.php
**连接管理器**

管理 WebSocket 连接的生命周期，包括连接存储、查找和清理。

**主要方法：**
- `addConnection($connectionId, $connection, $userId, $roomId)` - 添加连接
- `removeConnection($connectionId)` - 移除连接
- `getConnection($connectionId)` - 获取连接信息
- `getUserConnections($userId)` - 获取用户的所有连接
- `getRoomConnections($roomId)` - 获取房间的所有连接
- `getAllConnections()` - 获取所有连接
- `updateConnectionRoom($connectionId, $roomId)` - 更新连接的房间ID

**使用示例：**
```php
use extend\websocket\ConnectionManager;

// 添加连接
ConnectionManager::addConnection($connection->id, $connection, $userId, $roomId);

// 获取房间所有连接
$roomConnections = ConnectionManager::getRoomConnections($roomId);

// 移除连接
ConnectionManager::removeConnection($connection->id);
```

---

### 3. AuthHandler.php
**认证处理器**

处理用户认证相关功能，包括 token 验证和用户信息获取。

**主要方法：**
- `authenticate($token)` - 验证 token 并返回用户信息
- `getUserById($userId)` - 根据用户ID获取用户信息

**使用示例：**
```php
use extend\websocket\AuthHandler;

// 验证 token
$user = AuthHandler::authenticate($token);
if ($user) {
    echo "用户 {$user->username} 认证成功";
}

// 获取用户信息
$user = AuthHandler::getUserById($userId);
```

---

### 4. RoomHandler.php
**房间处理器**

处理房间相关功能，包括加入房间、离开房间、在线用户管理等。

**主要方法：**
- `handleJoinRoom($connection, $msg, &$localConnections)` - 处理加入房间请求
- `handleLeaveRoom($connection, $msg, &$localConnections)` - 处理离开房间请求
- `getRoomOnlineUsers($roomId, &$localConnections)` - 获取房间在线用户列表
- `broadcastToRoom($roomId, $data, &$localConnections, $excludeConnectionId)` - 广播消息到房间

**使用示例：**
```php
use extend\websocket\RoomHandler;

// 处理加入房间
RoomHandler::handleJoinRoom($connection, $msg, $localConnections);

// 获取在线用户
$onlineUsers = RoomHandler::getRoomOnlineUsers($roomId, $localConnections);

// 广播消息到房间
RoomHandler::broadcastToRoom($roomId, [
    'type' => 'notification',
    'message' => '欢迎新用户'
], $localConnections);
```

---

### 5. MessageHandler.php
**消息处理器**

处理消息相关功能，包括消息广播、消息存储、消息撤回等。

**主要方法：**
- `handleMessage($connection, $msg, &$localConnections)` - 处理普通消息
- `handleRecallMessage($connection, $msg, &$localConnections)` - 处理消息撤回
- `broadcastMessage($roomId, $messageData, &$localConnections, $excludeConnectionId)` - 广播消息
- `saveMessage($messageData)` - 保存消息到数据库

**使用示例：**
```php
use extend\websocket\MessageHandler;

// 处理消息
MessageHandler::handleMessage($connection, $msg, $localConnections);

// 广播消息
MessageHandler::broadcastMessage($roomId, [
    'message_id' => 123,
    'content' => 'Hello',
    'user_id' => 1
], $localConnections);

// 撤回消息
MessageHandler::handleRecallMessage($connection, $msg, $localConnections);
```

---

### 6. IntimacyHandler.php
**亲密度系统处理器**

处理房间亲密度互动相关功能，包括互动状态检查、启动/停止互动等。

**主要方法：**
- `checkRoomIntimacy($roomId, &$localConnections)` - 检查房间亲密互动状态
- `startRoomIntimacy($roomId, $userIds, &$localConnections)` - 启动房间亲密互动
- `stopRoomIntimacy($roomId, &$localConnections)` - 停止房间亲密互动
- `handleIntimacyRestart($connection, $msg, &$localConnections)` - 处理亲密互动重新开始请求
- `getRoomIntimacyState($roomId)` - 获取房间亲密互动状态
- `getAllRoomIntimacyStates()` - 获取所有房间亲密互动状态

**使用示例：**
```php
use extend\websocket\IntimacyHandler;

// 检查房间亲密互动状态
IntimacyHandler::checkRoomIntimacy($roomId, $localConnections);

// 启动亲密互动
IntimacyHandler::startRoomIntimacy($roomId, [$userId1, $userId2], $localConnections);

// 停止亲密互动
IntimacyHandler::stopRoomIntimacy($roomId, $localConnections);

// 处理重新开始请求
IntimacyHandler::handleIntimacyRestart($connection, $msg, $localConnections);

// 获取状态
$state = IntimacyHandler::getRoomIntimacyState($roomId);
```

---

## 🚀 使用指南

### 在新功能中使用模块

当你需要开发新的 WebSocket 功能时，可以直接使用这些模块：

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use extend\websocket\RedisHelper;
use extend\websocket\ConnectionManager;
use extend\websocket\AuthHandler;
use extend\websocket\RoomHandler;
use extend\websocket\MessageHandler;
use extend\websocket\IntimacyHandler;

$ws_worker = new Worker("websocket://0.0.0.0:2346");

$ws_worker->onConnect = function($connection) {
    echo "新连接建立\n";
};

$ws_worker->onMessage = function($connection, $data) {
    $msg = json_decode($data, true);
    
    // 使用认证模块
    if ($msg['type'] === 'auth') {
        $user = AuthHandler::authenticate($msg['token']);
        if ($user) {
            ConnectionManager::addConnection($connection->id, $connection, $user->id, null);
        }
    }
    
    // 使用房间模块
    if ($msg['type'] === 'join_room') {
        RoomHandler::handleJoinRoom($connection, $msg, $localConnections);
    }
    
    // 使用消息模块
    if ($msg['type'] === 'message') {
        MessageHandler::handleMessage($connection, $msg, $localConnections);
    }
    
    // 使用亲密度模块
    if ($msg['type'] === 'intimacy_restart') {
        IntimacyHandler::handleIntimacyRestart($connection, $msg, $localConnections);
    }
};

$ws_worker->onClose = function($connection) {
    ConnectionManager::removeConnection($connection->id);
};

Worker::runAll();
```

---

## ⚠️ 重要说明

1. **现有代码不受影响**：`server.php` 保持原样运行，所有现有功能继续正常工作
2. **仅供新功能使用**：这些模块是为后续新功能开发准备的，不会影响现有系统
3. **独立测试**：在使用这些模块开发新功能时，建议先进行独立测试
4. **命名空间**：所有模块使用 `extend\websocket` 命名空间

---

## 📝 开发建议

### 添加新功能时

1. 评估功能属于哪个模块（认证、房间、消息、亲密度等）
2. 在对应模块中添加新方法
3. 保持方法的静态特性，便于调用
4. 添加详细的注释说明
5. 在本文档中更新使用示例

### 创建新模块时

如果需要添加新的功能模块（如：礼物系统、排行榜等），建议：

1. 在 `extend/websocket/` 目录下创建新的 PHP 类文件
2. 使用 `extend\websocket` 命名空间
3. 采用静态方法设计，保持与现有模块一致
4. 在本文档中添加模块说明

---

## 🔧 维护说明

- **模块更新**：修改模块代码时，确保不影响现有调用
- **向后兼容**：添加新方法时保持现有方法签名不变
- **文档同步**：更新代码时同步更新本文档

---

## 📞 技术支持

如有问题或建议，请联系开发团队。
