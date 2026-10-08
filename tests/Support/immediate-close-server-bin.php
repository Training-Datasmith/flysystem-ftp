<?php

declare(strict_types=1);

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($server, '127.0.0.1', 0);
socket_listen($server, 1);
socket_getsockname($server, $addr, $port);
$readyFile = sys_get_temp_dir() . '/immediate-close-ready-' . getmypid() . '.port';
file_put_contents($readyFile, (string) $port);

$client = socket_accept($server);
socket_close($client);
socket_close($server);

while (true) {
    sleep(3600);
}
