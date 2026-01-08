# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Real-time chat application built with ThinkPHP 8 + Workerman + Vue 3, featuring WebSocket communication with HTTP polling fallback.

## Commands

```bash
# Start WebSocket server (required for real-time features)
php server.php start

# Start Web server
php think run

# Start server (Windows batch)
start_server.bat

# Start server (Linux)
./start_server.sh
```

## Architecture

### Backend (ThinkPHP 8)
- **Controllers** in `app/controller/` handle HTTP API requests
- **Services** in `app/service/` contain business logic (MessageService, MessageReadService, UploadService)
- **Models** in `app/model/` define database entities (Message, User, MessageRead, Room, RoomUser)
- WebSocket server in `server.php` handles real-time communication

### Frontend (Vue 3 CDN)
- Main app: `public/static/js/index.js`
- WebSocket client: `public/static/js/websocket.js`
- Upload system: `public/static/js/upload/` (modular handler pattern)

### Database Tables
- `ch_messages` - Message records with type (text/image/video/file/audio)
- `ch_users` - User profiles
- `ch_rooms` - Chat rooms
- `ch_room_users` - Room membership
- `ch_message_reads` - Read receipts

### Key Patterns

**Service Layer**: Business logic isolated in service classes (e.g., `MessageService::sendTextMessage()`, `MessageReadService::batchMarkAsRead()`)

**WebSocket Flow**: Client connects → authenticates → joins room → sends/receives messages. Fallback to HTTP polling if WebSocket fails.

**Upload System**: Modular handlers (`ImageHandler`, `VideoHandler`, `FileHandler`) via `UploadManager`

## WebSocket Protocol

Client sends: `auth`, `join_room`, `message`, `typing`, `mark_read`, `ping`
Server broadcasts: `message`, `user_joined`, `user_left`, `typing`, `message_read`

## Important Notes

- Frontend JS version updated in `view/index/index.html` when modifying `index.js`
- Use `INSERT IGNORE` for read receipts to prevent duplicates
- WebSocket `mark_read` broadcasts to room excluding sender; HTTP API `notifyReadStatus()` is unimplemented
