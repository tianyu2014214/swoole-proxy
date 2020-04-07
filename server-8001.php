<?php

$serv = new \Swoole\Server("0.0.0.0", 8001);
$serv->on("connect", function($serv, $fd) {
    echo "PHP node connect: port 8001\n";
});

$serv->on('receive', function($serv, $fd, $fromid, $data) {
    echo "PHP node receive: {$data}" . PHP_EOL;
});

$serv->start();