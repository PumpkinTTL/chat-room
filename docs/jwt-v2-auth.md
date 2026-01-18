# JWT v2 认证接口文档

## 概述

- 基础路径：`/api/v2/auth`
- 认证方式：`Authorization: Bearer <access_token>`
- 所有接口返回格式统一

```json
{
  "code": 0,
  "msg": "提示信息",
  "data": {}
}
```

---

## 1. 用户登录

**POST** `/api/v2/auth/login`

### 请求参数
| 参数 | 必填 | 说明 |
|------|------|------|
| username | 是 | 用户ID |
| password | 是 | 密码 |
| client_ip | 否 | 客户端IP |
| remember | 否 | 记住我（延长refresh_token有效期） |

### 请求示例
```javascript
// Axios
await axios.post('/api/v2/auth/login', {
  username: '1',
  password: '123456',
  remember: true
});

// Fetch
await fetch('/api/v2/auth/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ username: '1', password: '123456' })
});
```

### 成功响应
```json
{
  "code": 0,
  "msg": "登录成功",
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "refresh_token": "a1b2c3d4e5f6...",
    "token_type": "Bearer",
    "expires_in": 7200,
    "user": {
      "id": 1,
      "nick_name": "昵称",
      "avatar": "/storage/avatars/xxx.jpg"
    }
  }
}
```

---

## 2. 刷新令牌

**POST** `/api/v2/auth/refresh`

### 请求参数
| 参数 | 必填 | 说明 |
|------|------|------|
| refresh_token | 是* | 刷新令牌（传参或cookie自动获取） |

### 请求示例
```javascript
// 自动从cookie获取
await axios.post('/api/v2/auth/refresh');

// 或手动传递
await axios.post('/api/v2/auth/refresh', {
  refresh_token: 'a1b2c3d4e5f6...'
});
```

### 成功响应
```json
{
  "code": 0,
  "msg": "刷新成功",
  "data": {
    "access_token": "新的access_token",
    "refresh_token": "新的refresh_token",
    "token_type": "Bearer",
    "expires_in": 7200
  }
}
```

---

## 3. 获取当前用户

**GET** `/api/v2/auth/me`

### 请求头
```
Authorization: Bearer <access_token>
```

### 请求示例
```javascript
const token = localStorage.getItem('access_token');

await axios.get('/api/v2/auth/me', {
  headers: { Authorization: `Bearer ${token}` }
});

// 或自动携带（推荐）
axios.interceptors.request.use(config => {
  const token = localStorage.getItem('access_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});
```

### 成功响应
```json
{
  "code": 0,
  "msg": "获取成功",
  "data": {
    "id": 1,
    "nick_name": "昵称",
    "avatar": "/storage/avatars/xxx.jpg",
    "sign": "个性签名",
    "created_at": "2024-01-01 12:00:00"
  }
}
```

### 失败响应（401）
```json
{
  "code": 401,
  "msg": "令牌无效或已过期",
  "data": null
}
```

---

## 4. 单设备注销

**POST** `/api/v2/auth/logout`

### 请求头
```
Authorization: Bearer <access_token>
```

### 请求示例
```javascript
await axios.post('/api/v2/auth/logout', {}, {
  headers: { Authorization: `Bearer ${token}` }
});
```

### 响应
```json
{
  "code": 0,
  "msg": "注销成功"
}
```

---

## 5. 注销所有设备

**POST** `/api/v2/auth/logout-all`

### 请求头
```
Authorization: Bearer <access_token>
```

### 请求示例
```javascript
await axios.post('/api/v2/auth/logout-all', {}, {
  headers: { Authorization: `Bearer ${token}` }
});
```

### 响应
```json
{
  "code": 0,
  "msg": "注销成功",
  "data": {
    "revoked_count": 3
  }
}
```

---

## 前端存储建议

```javascript
// 登录成功后
const { access_token, refresh_token, user } = response.data.data;
localStorage.setItem('access_token', access_token);
localStorage.setItem('refresh_token', refresh_token);
localStorage.setItem('user', JSON.stringify(user));

// 请求拦截器
axios.interceptors.request.use(config => {
  const token = localStorage.getItem('access_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// 响应拦截器（401自动刷新）
axios.interceptors.response.use(
  response => response,
  async error => {
    if (error.response?.status === 401) {
      const refreshToken = localStorage.getItem('refresh_token');
      if (refreshToken) {
        try {
          const res = await axios.post('/api/v2/auth/refresh', { refresh_token });
          localStorage.setItem('access_token', res.data.data.access_token);
          localStorage.setItem('refresh_token', res.data.data.refresh_token);
          error.config.headers.Authorization = `Bearer ${res.data.data.access_token}`;
          return axios(error.config);
        } catch {
          // 刷新失败，跳转登录
          localStorage.clear();
          window.location.href = '/login';
        }
      }
    }
    return Promise.reject(error);
  }
);
```

---

## 错误码说明

| code | 说明 |
|------|------|
| 0 | 成功 |
| 400 | 参数错误 |
| 401 | 未授权/令牌无效/已过期 |
| 403 | 用户被禁用/账号停用 |
| 404 | 用户不存在 |
| 500 | 服务器错误 |
