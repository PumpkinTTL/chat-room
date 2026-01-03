const { createApp } = Vue;

createApp({
    data() {
        return {
            message: '欢迎使用Vue3开发聊天室',
            inputText: ''
        }
    },
    computed: {
        displayText() {
            return this.inputText || '请输入内容...'
        }
    },
    methods: {
        handleClick() {
            if (window.Toast) {
                window.Toast.success('Vue3 正常运行！输入内容：' + this.inputText);
            } else {
                alert('Vue3 正常运行！输入内容：' + this.inputText);
            }
        }
    }
}).mount('#app');
