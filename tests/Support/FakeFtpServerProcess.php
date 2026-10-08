<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests\Support;

use RuntimeException;

final class FakeFtpServerProcess
{
    /** @var resource|null */
    private $process = null;

    private string $workDir;

    private int $port = 0;

    public function __construct(array $config = [])
    {
        $this->workDir = sys_get_temp_dir() . '/flysystem-ftp-' . bin2hex(random_bytes(8));
        if ( ! mkdir($this->workDir, 0700, true) && ! is_dir($this->workDir)) {
            throw new RuntimeException('Could not create work directory for fake FTP server.');
        }

        file_put_contents($this->workDir . '/state.json', json_encode(['nodes' => [], 'root' => '/'], JSON_THROW_ON_ERROR));
        $this->writeConfig($config);
    }

    public function workDir(): string
    {
        return $this->workDir;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function writeConfig(array $config): void
    {
        $existing = [];
        $path = $this->workDir . '/config.json';
        if (is_file($path)) {
            $existing = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        file_put_contents($path, json_encode(array_merge($existing, $config), JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $node
     */
    public function seedNode(string $path, array $node): void
    {
        $state = $this->readState();
        $normalized = $this->normalizePath($path);
        $state['nodes'][$normalized] = $node;
        $this->writeState($state);
    }

    public function seedFile(string $path, string $content, int $mode = 0644): void
    {
        $this->seedNode($path, ['type' => 'file', 'content' => $content, 'mode' => $mode]);
        $this->ensureParentDirectories($path);
    }

    public function seedDirectory(string $path, int $mode = 0755): void
    {
        $this->seedNode($path, ['type' => 'dir', 'mode' => $mode]);
        $this->ensureParentDirectories($path);
    }

    public function start(): int
    {
        if ($this->process !== null) {
            return $this->port;
        }

        $bin = __DIR__ . '/fake-ftp-server-bin.php';
        $cmd = [PHP_BINARY, $bin, $this->workDir];
        $pipes = [];
        $this->process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'r'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null
        );

        if ( ! is_resource($this->process)) {
            throw new RuntimeException('Could not start fake FTP server process.');
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $readyFile = $this->workDir . '/ready.port';
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if (is_file($readyFile)) {
                $this->port = (int) trim((string) file_get_contents($readyFile));
                if ($this->port > 0) {
                    return $this->port;
                }
            }
            usleep(10000);
        }

        throw new RuntimeException('Fake FTP server did not become ready.');
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        proc_terminate($this->process);
        proc_close($this->process);
        $this->process = null;
        $this->port = 0;

        $this->removeDirectory($this->workDir);
    }

    /**
     * @return list<string>
     */
    public function commandLog(): array
    {
        $logFile = $this->workDir . '/commands.log';
        if ( ! is_file($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? [] : array_values($lines);
    }

    public function sessionClosedAfterLastCommand(): bool
    {
        $marker = $this->workDir . '/session_closed.marker';
        if ( ! is_file($marker)) {
            return false;
        }

        $mtime = filemtime($marker);
        $logFile = $this->workDir . '/commands.log';
        if ($mtime === false || ! is_file($logFile)) {
            return false;
        }

        return $mtime >= filemtime($logFile);
    }

    private function ensureParentDirectories(string $path): void
    {
        $parts = explode('/', trim($this->normalizePath($path), '/'));
        array_pop($parts);
        $built = '';
        foreach ($parts as $part) {
            $built .= '/' . $part;
            if ( ! isset($this->readState()['nodes'][$built])) {
                $this->seedNode($built, ['type' => 'dir', 'mode' => 0755]);
            }
        }
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim(str_replace('\\', '/', $path), '/');
        if ($path === '/') {
            return '/';
        }

        return rtrim($path, '/') ?: '/';
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        return json_decode((string) file_get_contents($this->workDir . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        file_put_contents($this->workDir . '/state.json', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $dir): void
    {
        if ( ! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
