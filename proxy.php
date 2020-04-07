<?php

// 定义路径分隔符
define('DS', DIRECTORY_SEPARATOR);

// 定义程序根路径
define('ROOT_PATH', __DIR__ . DS);

require ROOT_PATH . "lib/ProxyCenter.php";

Co::set(['hook_flags'=> SWOOLE_HOOK_ALL]);
$serv = new ProxyCenter("0.0.0.0", 8000, [
    'task_worker_num' => 4,
    'task_enable_coroutine' => 1
]);
