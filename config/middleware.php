<?php
// 中间件配置
return [
    // 别名或分组
    'alias'    => [
        'token_auth' => app\middleware\TokenAuth::class,
    ],
    // 全局中间件
    'global'   => [
        app\middleware\TokenAuth::class,
    ],
    // 优先级设置，此数组中的中间件会按照数组中的顺序优先执行
    'priority' => [],
];
