<?php
// +----------------------------------------------------------------------
// | 应用路由配置
// +----------------------------------------------------------------------
use think\facade\Route;

// +----------------------------------------------------------------------
// | 前台路由
// +----------------------------------------------------------------------
// 登录页面
Route::get('/', 'Index/login');
Route::get('login', 'Index/login');

// 记录页面访问
Route::post('api/logAccess', 'Index/logAccess');

// 检查访问记录
Route::get('api/checkAccess', 'Index/checkAccess');

// 聊天室首页
Route::get('chat', 'Index/index');

// WebSocket 测试页面
Route::get('websocket', 'WebSocketTest/index');
Route::get('websocket/config', 'WebSocketTest/config');

// 静态文件访问路由（用于访问上传的文件）
Route::get('storage/<path>', 'StaticFile/storage')->pattern(['path' => '.+']);

// 静态文件服务
Route::get('storage/<path>', 'Index/storage')->pattern(['path' => '.*']);


// +----------------------------------------------------------------------
// | API路由组
// +----------------------------------------------------------------------
Route::group('api', function () {
    // 用户模块
    Route::group('user', function () {
        Route::get('list', 'User/list');
        Route::get('info/:id', 'User/info');
        Route::post('create', 'User/create');
        Route::put('update/:id', 'User/update');
        Route::delete('delete/:id', 'User/delete');
        Route::post('login', 'User/login');
        // 用户资料相关
        Route::get('profile', 'User/profile');
        Route::post('update', 'User/updateProfile');
        Route::post('uploadAvatar', 'User/uploadAvatar');
    });

    // 房间模块
    Route::group('room', function () {
        Route::get('/', 'Room/index');
        Route::get(':id', 'Room/read');
        Route::post('/', 'Room/save');
        Route::post('create', 'Room/save');
        Route::post('toggleLock', 'Room/toggleLock');
        Route::put(':id', 'Room/update');
        Route::delete(':id', 'Room/delete');
    });

    // 房间成员模块
    Route::group('roomUser', function () {
        Route::post('join', 'RoomUser/join');
        Route::post('leave', 'RoomUser/leave');
        Route::get('count/:room_id', 'RoomUser/count');
        Route::get('list/:room_id', 'RoomUser/list');
        Route::get('userRooms', 'RoomUser/userRooms');
        Route::get('check', 'RoomUser/check');
    });

    // 消息模块
    Route::group('message', function () {
        Route::get('list', 'Message/list');
        Route::post('sendText', 'Message/sendText');
        Route::post('sendImage', 'Message/sendImage');
        Route::post('sendVideo', 'Message/sendVideo');
        Route::post('sendFile', 'Message/sendFile');
        Route::post('sendSystem', 'Message/sendSystem');
        Route::put('edit', 'Message/edit');
        Route::post('recall', 'Message/recall');
        Route::post('burn', 'Message/burn');
        Route::get('latestTime', 'Message/latestTime');
        Route::get('unreadCount', 'Message/unreadCount');
        Route::post('markRead', 'Message/markRead');
        Route::post('clearRoom', 'Message/clearRoom');
        Route::post('restoreRoom', 'Message/restoreRoom');
        Route::get('deletedCount', 'Message/deletedCount');
    });
    
    // 好感度系统模块
    Route::group('intimacy', function () {
        Route::get('info/:room_id', 'Intimacy/getIntimacyInfo');
        Route::get('levels', 'Intimacy/getLevels');
        Route::post('collect', 'Intimacy/collect');
    });

});

