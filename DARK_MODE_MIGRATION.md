# 深色模式优化迁移指南

## 优化内容

### 1. 架构调整
- ✅ 创建 `useDarkMode` composable 统一管理深色模式
- ✅ 将 `dark-mode` 类从 `.chat-app` 迁移到 `<html>` 元素
- ✅ 更新 `App.vue` 使用新的 composable
- ✅ 更新 `Index.vue` 移除本地状态管理

### 2. 样式选择器迁移规则

需要批量替换以下选择器：

```scss
// 旧的选择器 → 新的选择器
.chat-app.dark-mode { ... }           → html.dark-mode .chat-app { ... }
.chat-app.dark-mode .xxx { ... }      → html.dark-mode .xxx { ... }
:global(.chat-app.dark-mode) { ... }  → html.dark-mode { ... }
.dark-mode .xxx { ... }               → html.dark-mode .xxx { ... } (已经正确)
```

### 3. 已完成的文件列表

#### 核心文件 ✅
- ✅ `vue-webchat/src/composables/useDarkMode.ts` - 新建
- ✅ `vue-webchat/src/App.vue` - 使用 composable
- ✅ `vue-webchat/src/views/Index.vue` - 移除本地状态，更新样式

#### 亲密度组件 ✅
- ✅ `vue-webchat/src/components/intimacy/IntimacyPanel.vue`
- ✅ `vue-webchat/src/components/intimacy/IntimacyInteraction.vue`
- ✅ `vue-webchat/src/components/intimacy/IntimacyExpTip.vue` (已经使用 html.dark-mode)
- ✅ `vue-webchat/src/components/intimacy/IntimacyLevelUpModal.vue`
- ✅ `vue-webchat/src/components/intimacy/IntimacyBondNotification.vue`

#### 聊天组件 ✅
- ✅ `vue-webchat/src/components/index/MessageList.vue`
- ✅ `vue-webchat/src/components/index/MessageItem.vue`
- ✅ `vue-webchat/src/components/index/RoomList.vue` (使用简单 .dark-mode，自动生效)
- ✅ `vue-webchat/src/components/index/UserCard.vue` (使用简单 .dark-mode，自动生效)
- ✅ `vue-webchat/src/components/index/Sidebar.vue` (使用简单 .dark-mode，自动生效)
- ✅ `vue-webchat/src/components/index/InputBar.vue` (使用简单 .dark-mode，自动生效)

### 4. 替换示例

#### 示例 1: Index.vue
```scss
// 旧的
.chat-app.dark-mode {
  background: $bg-color-page-dark;
  color: $text-primary-dark;
}

// 新的
html.dark-mode .chat-app {
  background: $bg-color-page-dark;
  color: $text-primary-dark;
}
```

#### 示例 2: 嵌套选择器
```scss
// 旧的
.chat-app.dark-mode .menu-btn {
  color: $text-secondary-dark;
}

// 新的
html.dark-mode .menu-btn {
  color: $text-secondary-dark;
}
```

#### 示例 3: :global() 包裹
```scss
// 旧的
:global(.chat-app.dark-mode) .glass-orb {
  background: ...;
}

// 新的
html.dark-mode .glass-orb {
  background: ...;
}
```

### 5. 注意事项

1. **已经正确的选择器不需要修改**：
   - `html.dark-mode { ... }` ✅
   - `.dark-mode .xxx { ... }` (如果是独立组件内部) ✅

2. **特殊情况处理**：
   - 如果组件有自己的根元素类名，保留该类名
   - 例如：`.sidebar.dark-mode` → `html.dark-mode .sidebar`

3. **测试要点**：
   - 切换深色模式时，html 元素应该添加/移除 `dark-mode` 类
   - 所有组件的深色模式样式应该正常生效
   - 跨标签页同步应该正常工作

### 6. 迁移完成总结 ✅

**已完成的工作：**

1. ✅ 创建了 `useDarkMode` composable 统一管理深色模式状态
2. ✅ 深色模式类从 `.chat-app` 元素迁移到 `<html>` 元素
3. ✅ 所有使用 `.chat-app.dark-mode` 的组件已更新为 `html.dark-mode`
4. ✅ 使用简单 `.dark-mode` 选择器的组件自动生效，无需修改
5. ✅ 代码已分 3 个阶段提交到 git

**验证结果：**
- ✅ 搜索确认：项目中已无 `.chat-app.dark-mode` 选择器
- ✅ 功能测试：深色模式切换正常工作
- ✅ 跨标签页同步：localStorage 同步正常

**技术优势：**
1. 更符合 Web 标准：深色模式类挂载在 html 元素
2. 更好的性能：减少了不必要的类绑定
3. 更易维护：统一的状态管理，避免重复代码
4. 更好的扩展性：其他页面可直接使用 composable

## 优势

1. **更符合标准**：深色模式类挂载在 html 元素是业界标准做法
2. **更好的性能**：减少了不必要的类绑定和事件监听
3. **更易维护**：统一的状态管理，避免多处重复代码
4. **更好的扩展性**：其他页面和组件可以直接使用 `useDarkMode` composable
