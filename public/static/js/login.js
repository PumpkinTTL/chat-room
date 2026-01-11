const { createApp, reactive, ref } = Vue;

// 确保Toast已加载
const waitForToast = () => {
    return new Promise((resolve) => {
        if (window.Toast) {
            resolve();
        } else {
            const checkToast = () => {
                if (window.Toast) {
                    resolve();
                } else {
                    setTimeout(checkToast, 50);
                }
            };
            checkToast();
        }
    });
};

// 第三方IP获取接口配置
const IP_SERVICES = [
    { url: 'https://api.ipify.org?format=json', type: 'json' },
    { url: 'https://api.ipify.org', type: 'text' },  // 备用纯文本
    { url: 'https://ifconfig.me/ip', type: 'text' }, // 纯文本
];

// 从第三方接口获取真实IP（带超时控制）
const getRealIpFromThirdParty = async function () {
    for (let i = 0; i < IP_SERVICES.length; i++) {
        try {
            console.log('尝试IP服务 ' + (i + 1) + ':', IP_SERVICES[i].url);

            // 创建超时控制器（3秒超时）
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 3000);

            const response = await fetch(IP_SERVICES[i].url, {
                method: 'GET',
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                console.warn('IP服务 ' + (i + 1) + ' 响应失败:', response.status);
                continue;
            }

            let ip = null;

            if (IP_SERVICES[i].type === 'json') {
                const data = await response.json();
                ip = data.ip;
            } else {
                // 纯文本
                ip = await response.text();
                ip = ip.trim();
            }

            // 验证IP格式（支持IPv4和IPv6）
            if (ip && (/^[\d.]+$/.test(ip) || /^[0-9a-fA-F:]+$/.test(ip))) {
                console.log('成功获取IP:', ip);
                return ip;
            }

        } catch (error) {
            if (error.name === 'AbortError') {
                console.warn('IP服务 ' + (i + 1) + ' 超时');
            } else {
                console.warn('IP服务 ' + (i + 1) + ' 失败:', error.message);
            }
            continue;
        }
    }

    // 所有服务都失败，返回null
    console.warn('所有IP服务都失败，将使用后端获取的IP');
    return null;
};

// 记录页面访问
const logPageAccess = async (pageName) => {
    try {
        const clientIp = await getRealIpFromThirdParty();
        await fetch('/api/logAccess', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                client_ip: clientIp || '',
                page: pageName
            })
        });
    } catch (e) {
        console.warn('记录访问失败:', e.message);
    }
};

// 页面加载时记录访问
logPageAccess('访问登录页');

createApp({
    setup() {
        const form = reactive({
            username: '',
            password: '',
            rememberMe: false
        });

        // Load saved credentials
        const savedCreds = localStorage.getItem('login_credentials');
        if (savedCreds) {
            try {
                const { username, password } = JSON.parse(savedCreds);
                form.username = username;
                form.password = password;
                form.rememberMe = true;
            } catch (e) {
                localStorage.removeItem('login_credentials');
            }
        }

        const errors = reactive({
            username: '',
            password: ''
        });

        const loading = ref(false);

        const clearError = (field) => {
            errors[field] = '';
        };

        const validateForm = () => {
            let isValid = true;

            if (!form.username.trim()) {
                errors.username = '请输入用户名';
                isValid = false;
            }

            if (!form.password) {
                errors.password = '请输入密码';
                isValid = false;
            }

            return isValid;
        };

        const handleLogin = async () => {
            errors.username = '';
            errors.password = '';

            if (!validateForm()) {
                return;
            }

            loading.value = true;

            // 等待Toast加载完成
            await waitForToast();

            try {
                // 先获取真实IP
                const clientIp = await getRealIpFromThirdParty();
                console.log('获取到的客户端IP:', clientIp || '使用后端获取');

                // 准备请求数据
                const requestData = {
                    username: form.username,
                    password: form.password
                };

                // 如果获取到IP，添加到请求中
                if (clientIp) {
                    requestData.client_ip = clientIp;
                }

                const response = await fetch('/api/user/login', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(requestData)
                });

                const result = await response.json();

                console.log('🔍 后端返回的完整响应:', result);
                console.log('🔍 响应状态码:', response.status);
                console.log('🔍 result.code:', result.code);

                if (result.code === 0) {
                    console.log('登录成功，前端接收到的数据:', result);
                    console.log('Token:', result.token);

                    // 检查token是否存在
                    if (!result.token) {
                        console.error('❌ 后端未返回token');
                        window.Toast.error('登录失败：未获取到token');
                        return;
                    }

                    // 保存用户信息到本地存储
                    const userInfo = {
                        id: result.data.id,
                        nick_name: result.data.nick_name,
                        avatar: result.data.avatar,
                        token: result.token
                    };

                    localStorage.setItem('userInfo', JSON.stringify(userInfo));
                    console.log('✅ 用户信息已保存到本地存储:', userInfo);

                    // 验证保存是否成功
                    const saved = localStorage.getItem('userInfo');
                    console.log('✅ 验证保存结果:', saved);

                    // 检查cookie
                    console.log('🍪 检查Cookie:', document.cookie);
                    const tokenCookie = document.cookie.split('; ').find(row => row.startsWith('token='));
                    if (tokenCookie) {
                        console.log('✅ Token已保存到Cookie:', tokenCookie);
                    } else {
                        console.warn('⚠️ Cookie中未找到token');
                    }

                    // Handle Remember Me
                    if (form.rememberMe) {
                        localStorage.setItem('login_credentials', JSON.stringify({
                            username: form.username,
                            password: form.password
                        }));
                    } else {
                        localStorage.removeItem('login_credentials');
                    }

                    // 登录成功后跳转到聊天页面
                    setTimeout(() => {
                        window.location.href = '/chat';
                    }, 100); // 延迟100ms确保cookie写入完成
                } else if (result.code === 403) {
                    // 账号已封禁，跳转到道别页面

                    window.location.href = '/farewell.html';
                    return; // 确保不继续执行
                } else {
                    console.log('登录失败:', result.msg);
                    window.Toast.error(result.msg || '登录失败');
                }
            } catch (error) {
                console.error('登录失败:', error);
                window.Toast.error('请求失败：' + error.message);
            } finally {
                loading.value = false;
            }
        };

        return {
            form,
            errors,
            loading,
            clearError,
            handleLogin
        };
    }
}).mount('#app');
