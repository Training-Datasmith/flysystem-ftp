<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php fake-ftp-server-bin.php <workdir>\n");
    exit(1);
}

$workDir = $argv[1];
$logFile = $workDir . '/commands.log';
$stateFile = $workDir . '/state.json';
$configFile = $workDir . '/config.json';

function log_command(string $workDir, string $command): void
{
    file_put_contents($workDir . '/commands.log', $command . "\n", FILE_APPEND | LOCK_EX);
}

function load_config(string $configFile): array
{
    if (! is_file($configFile)) {
        return [];
    }

    return json_decode((string) file_get_contents($configFile), true) ?: [];
}

function load_state(string $stateFile): array
{
    return json_decode((string) file_get_contents($stateFile), true) ?: ['nodes' => [], 'root' => '/'];
}

function save_state(string $stateFile, array $state): void
{
    file_put_contents($stateFile, json_encode($state));
}

function normalize_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || $path === '/') {
        return '/';
    }

    return '/' . trim($path, '/');
}

function perm_for_mode(int $mode): string
{
    $mode &= 0777;
    if ($mode === 0600) {
        return '-rw-------';
    }
    if ($mode === 0644) {
        return '-rw-r--r--';
    }

    return '-rw-r--r--';
}

function list_unix_line(string $name, array $node): string
{
    if (($node['type'] ?? '') === 'dir') {
        $mode = sprintf('%o', $node['mode'] ?? 0755);
        return sprintf('drwxr-xr-x   1 ftp      ftp          4096 Jan  1 00:00 %s', $name);
    }

    $content = (string) ($node['content'] ?? '');
    $size = strlen($content);
    $mode = $node['mode'] ?? 0644;
    $perm = perm_for_mode($mode);

    return sprintf('%s   1 ftp      ftp        %7d Jan  1 00:00 %s', $perm, $size, $name);
}

function generate_listing(array $state, string $listPath): array
{
    $listPath = normalize_path($listPath);
    $lines = [];
    $prefix = $listPath === '/' ? '' : $listPath;

    foreach ($state['nodes'] as $path => $node) {
        $parent = dirname($path);
        if ($parent === '.') {
            $parent = '/';
        }
        if (normalize_path($parent) !== $listPath) {
            continue;
        }
        $name = basename($path);
        if ($name === '' || $name === '.') {
            continue;
        }
        $lines[] = list_unix_line($name, $node);
    }

    return $lines;
}

function ensure_parent_dirs(array &$state, string $path): void
{
    $parts = explode('/', trim(normalize_path($path), '/'));
    array_pop($parts);
    $built = '';
    foreach ($parts as $part) {
        $built .= '/' . $part;
        if (! isset($state['nodes'][$built])) {
            $state['nodes'][$built] = ['type' => 'dir', 'mode' => 0755];
        }
    }
}

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($server, '127.0.0.1', 0);
socket_listen($server, 5);
$port = socket_getsockname($server, $addr, $boundPort);
file_put_contents($workDir . '/ready.port', (string) $boundPort);

socket_set_nonblock($server);

$running = true;
while ($running) {
    $client = @socket_accept($server);
    if ($client === false) {
        usleep(10000);
        continue;
    }

    socket_set_block($client);
    $authenticated = false;
    $renameFrom = null;
    $pendingDataMode = null;
    $dataListener = null;
    $currentDirectory = '/';

    $send = static function ($client, string $message): void {
        socket_write($client, $message . "\r\n");
    };

    $sessionOpen = true;
    $closeSession = static function () use (&$client, $workDir, &$sessionOpen): void {
        if (! $sessionOpen) {
            return;
        }
        $sessionOpen = false;
        try {
            @socket_close($client);
        } catch (Throwable) {
        }
        touch($workDir . '/session_closed.marker');
    };

    $openPassiveListener = static function (array $config) {
        $listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($listener, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($listener, '127.0.0.1', 0);
        socket_listen($listener, 1);
        socket_getsockname($listener, $addr, $port);
        $pasvHost = $config['pasv_address'] ?? '127.0.0.1';
        $parts = explode('.', $pasvHost);
        if (count($parts) !== 4) {
            $parts = [127, 0, 0, 1];
        }
        $p1 = intdiv($port, 256);
        $p2 = $port % 256;
        return [$listener, sprintf(
            '227 Entering Passive Mode (%d,%d,%d,%d,%d,%d)',
            (int) $parts[0],
            (int) $parts[1],
            (int) $parts[2],
            (int) $parts[3],
            $p1,
            $p2
        )];
    };

    $handleData = static function (
        $dataListener,
        string $mode,
        array &$state,
        string $workDir,
        string $targetPath,
        ?string $uploadContent
    ) use ($stateFile, $configFile): ?string {
        if ($dataListener === null) {
            return null;
        }
        $data = @socket_accept($dataListener);
        if ($data === false) {
            return null;
        }

        if ($mode === 'list') {
            $config = load_config($configFile);
            $override = $config['list_override'] ?? null;
            if (is_array($override)) {
                $payload = implode("\r\n", $override) . "\r\n";
            } else {
                $lines = generate_listing($state, $targetPath);
                $payload = implode("\r\n", $lines) . (count($lines) ? "\r\n" : '');
            }
            socket_write($data, $payload);
            @socket_shutdown($data, 1);
            @socket_close($data);
            return null;
        }

        if ($mode === 'retr') {
            $path = normalize_path($targetPath);
            $content = (string) ($state['nodes'][$path]['content'] ?? '');
            socket_write($data, $content);
            @socket_shutdown($data, 1);
            @socket_close($data);
            return null;
        }

        if ($mode === 'stor') {
            $blob = '';
            while (true) {
                $chunk = @socket_read($data, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $blob .= $chunk;
            }
            @socket_close($data);
            $path = normalize_path($targetPath);
            ensure_parent_dirs($state, $path);
            $state['nodes'][$path] = ['type' => 'file', 'content' => $blob, 'mode' => 0644];
            save_state($stateFile, $state);
            return $blob;
        }

        @socket_close($data);
        return null;
    };

    $send($client, '220 Fake FTP ready');

    $buffer = '';
    while (true) {
        try {
            $chunk = @socket_read($client, 4096);
        } catch (Throwable) {
            break;
        }
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\r\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);
            $config = load_config($configFile);
            $state = load_state($stateFile);
            log_command($workDir, $line);
            $upper = strtoupper($line);

            if (isset($config['close_on_command']) && stripos($line, (string) $config['close_on_command']) === 0) {
                $closeSession();
                continue 2;
            }

            $commandName = strtoupper(strtok($line, ' ') ?: '');
            if (in_array($commandName, $config['reject_commands'] ?? [], true)) {
                $send($client, '550 rejected');
                continue;
            }

            if (str_starts_with($upper, 'USER ')) {
                $send($client, '331 Password required');
                continue;
            }
            if (str_starts_with($upper, 'PASS ')) {
                $pass = substr($line, 5);
                if ($pass === 'bad') {
                    $send($client, '530 Login incorrect');
                    $closeSession();
                    continue 2;
                }
                $authenticated = true;
                $send($client, '230 User logged in');
                continue;
            }
            if (! $authenticated) {
                $send($client, '530 Not logged in');
                continue;
            }
            if (str_starts_with($upper, 'OPTS UTF8 ON')) {
                if (($config['close_on_opts'] ?? false) === true) {
                    $closeSession();
                    continue 2;
                }
                $response = $config['opts_utf8_response'] ?? '200 UTF8 mode enabled';
                $send($client, $response);
                continue;
            }
            if (str_starts_with($upper, 'SYST')) {
                $send($client, $config['syst_response'] ?? '215 UNIX Type: L8');
                if (($config['close_on_syst'] ?? false) === true) {
                    $closeSession();
                    continue 2;
                }
                continue;
            }
            if (str_starts_with($upper, 'TYPE ')) {
                $send($client, '200 Type set');
                continue;
            }
            if (str_starts_with($upper, 'PWD')) {
                $send($client, '257 "' . $currentDirectory . '" is the current directory');
                continue;
            }
            if (str_starts_with($upper, 'CWD ')) {
                $path = trim(substr($line, 4));
                $norm = normalize_path($path);
                if (($config['missing_root'] ?? null) === $norm) {
                    $send($client, '550 No such directory');
                    continue;
                }
                if ($norm !== '/') {
                    $node = $state['nodes'][$norm] ?? null;
                    if ($node === null && ! ($config['cwd_always_ok'] ?? false)) {
                        $send($client, '550 No such directory');
                        continue;
                    }
                    if (($node['type'] ?? '') === 'file') {
                        $send($client, '550 Not a directory');
                        continue;
                    }
                }
                $currentDirectory = $norm;
                $send($client, '250 Directory changed');
                continue;
            }
            if (str_starts_with($upper, 'PASV')) {
                [$dataListener, $reply] = $openPassiveListener($config);
                $send($client, $reply);
                continue;
            }
            if (str_starts_with($upper, 'NOOP')) {
                $send($client, $config['noop_response'] ?? '200 OK');
                continue;
            }
            if (str_starts_with($upper, 'HELP')) {
                $help = $config['help_response'] ?? "214-Help\r\n214 End";
                if (str_contains($help, 'Pure-FTPd') || ($config['pureftpd'] ?? false)) {
                    $help = '214 Pure-FTPd help listing';
                }
                $send($client, $help);
                if (($config['close_on_help'] ?? false) === true) {
                    $closeSession();
                    continue 2;
                }
                continue;
            }
            if (str_starts_with($upper, 'LIST') || str_starts_with($upper, 'NLST')) {
                $listArg = trim(preg_replace('/^(LIST|NLST)\s*/i', '', $line) ?? '');
                $listArg = preg_replace('/^(-\S+\s+)+/', '', $listArg) ?? $listArg;
                $listArg = trim($listArg);
                if ($listArg === '' || $listArg === '.' || $listArg === './') {
                    $listArg = $currentDirectory;
                }
                $pendingDataMode = 'list';
                $send($client, '150 Opening data connection');
                $handleData($dataListener, 'list', $state, $workDir, $listArg, null);
                $dataListener = null;
                $send($client, '226 Transfer complete');
                continue;
            }
            if (str_starts_with($upper, 'STOR ')) {
                $target = trim(substr($line, 5));
                $pendingDataMode = 'stor';
                $send($client, '150 Opening data connection');
                $handleData($dataListener, 'stor', $state, $workDir, $target, null);
                $dataListener = null;
                $send($client, '226 Transfer complete');
                continue;
            }
            if (str_starts_with($upper, 'RETR ')) {
                $target = trim(substr($line, 5));
                $retrPath = normalize_path($target);
                if ( ! isset($state['nodes'][$retrPath]) || ($state['nodes'][$retrPath]['type'] ?? '') !== 'file') {
                    $send($client, '550 Not found');
                    continue;
                }
                $send($client, '150 Opening data connection');
                $handleData($dataListener, 'retr', $state, $workDir, $target, null);
                $dataListener = null;
                $send($client, '226 Transfer complete');
                continue;
            }
            if (str_starts_with($upper, 'SIZE ')) {
                $path = normalize_path(trim(substr($line, 5)));
                $node = $state['nodes'][$path] ?? null;
                if ($node === null || ($node['type'] ?? '') !== 'file') {
                    $send($client, '550 Not found');
                    continue;
                }
                $size = strlen((string) ($node['content'] ?? ''));
                $send($client, '213 ' . $size);
                continue;
            }
            if (str_starts_with($upper, 'MDTM ')) {
                $send($client, $config['mdtm_response'] ?? '213 20261007120000');
                continue;
            }
            if (str_starts_with($upper, 'MKD ')) {
                $path = normalize_path(trim(substr($line, 4)));
                ensure_parent_dirs($state, $path);
                $state['nodes'][$path] = ['type' => 'dir', 'mode' => 0755];
                save_state($stateFile, $state);
                $send($client, '257 "' . $path . '" created');
                continue;
            }
            if (str_starts_with($upper, 'SITE CHMOD')) {
                if (preg_match('/^SITE CHMOD (\d+) (.+)$/i', $line, $matches)) {
                    $mode = octdec($matches[1]);
                    $path = normalize_path($matches[2]);
                    if (isset($state['nodes'][$path])) {
                        $state['nodes'][$path]['mode'] = $mode;
                        save_state($stateFile, $state);
                    }
                }
                $send($client, '200 SITE command successful');
                continue;
            }
            if (str_starts_with($upper, 'RNFR ')) {
                $renameFrom = trim(substr($line, 5));
                $send($client, '350 Ready for RNTO');
                continue;
            }
            if (str_starts_with($upper, 'RNTO ')) {
                $to = normalize_path(trim(substr($line, 5)));
                $from = normalize_path((string) $renameFrom);
                if (isset($state['nodes'][$from])) {
                    ensure_parent_dirs($state, $to);
                    $state['nodes'][$to] = $state['nodes'][$from];
                    unset($state['nodes'][$from]);
                    save_state($stateFile, $state);
                }
                $send($client, '250 Rename successful');
                $renameFrom = null;
                continue;
            }
            if (str_starts_with($upper, 'DELE ')) {
                $path = normalize_path(trim(substr($line, 5)));
                unset($state['nodes'][$path]);
                save_state($stateFile, $state);
                $send($client, '250 Deleted');
                continue;
            }
            if (str_starts_with($upper, 'RMD ')) {
                $path = normalize_path(trim(substr($line, 4)));
                unset($state['nodes'][$path]);
                save_state($stateFile, $state);
                $send($client, '250 Removed');
                continue;
            }
            if (str_starts_with($upper, 'STAT ')) {
                if (isset($config['stat_response'])) {
                    $stat = $config['stat_response'];
                } else {
                    $path = normalize_path(trim(substr($line, 5)));
                    $node = $state['nodes'][$path] ?? null;
                    if (($node['type'] ?? '') === 'dir') {
                        $stat = [
                            '213-Status follows:',
                            'drwxr-xr-x   1 ftp      ftp          4096 Jan  1 00:00 ' . basename($path),
                            '213 End',
                        ];
                    } else {
                        $mode = $node['mode'] ?? 0644;
                        $perm = perm_for_mode($mode);
                        $size = strlen((string) ($node['content'] ?? ''));
                        $stat = [
                            '213-Status follows:',
                            sprintf('%s   1 ftp      ftp        %7d Jan  1 00:00 %s', $perm, $size, basename($path)),
                            '213 End',
                        ];
                    }
                }
                foreach ($stat as $statLine) {
                    $send($client, $statLine);
                }
                continue;
            }
            if (str_starts_with($upper, 'QUIT')) {
                touch($workDir . '/session_closed.marker');
                $send($client, '221 Goodbye');
                $closeSession();
                continue 2;
            }

            $send($client, '200 OK');
        }
    }

    if ($sessionOpen) {
        try {
            @socket_close($client);
        } catch (Throwable) {
        }
    }
}

socket_close($server);
