<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests\Support;

use RuntimeException;

final class ImmediateCloseServer
{
    /** @var resource|null */
    private $process = null;

    private int $port = 0;

    public function start(): int
    {
        $script = __DIR__ . '/immediate-close-server-bin.php';
        $pipes = [];
        $this->process = proc_open(
            [PHP_BINARY, $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'r'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if ( ! is_resource($this->process)) {
            throw new RuntimeException('Could not start immediate-close server.');
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $readyPattern = sys_get_temp_dir() . '/immediate-close-ready-*.port';
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $files = glob($readyPattern) ?: [];
            foreach ($files as $file) {
                $port = (int) trim((string) file_get_contents($file));
                if ($port > 0) {
                    $this->port = $port;
                    return $this->port;
                }
            }
            usleep(10000);
        }

        throw new RuntimeException('Immediate-close server did not become ready.');

        return $this->port;
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }
}
