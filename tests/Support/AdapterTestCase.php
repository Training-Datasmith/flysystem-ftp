<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests\Support;

use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use PHPUnit\Framework\TestCase;

abstract class AdapterTestCase extends TestCase
{
    protected ?FakeFtpServerProcess $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        FtpFunctionControl::$failSetOption = false;
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
        FtpFunctionControl::$failSetOption = false;
        parent::tearDown();
    }

    protected function startServer(array $config = []): FakeFtpServerProcess
    {
        $defaults = [
            'cwd_always_ok' => false,
            'missing_root' => '/invalid/root',
        ];
        $this->server = new FakeFtpServerProcess(array_merge($defaults, $config));
        $this->server->start();

        return $this->server;
    }

    protected function adapterOptions(array $overrides = []): FtpConnectionOptions
    {
        $port = $this->server?->port() ?? 2121;

        return FtpConnectionOptions::fromArray(array_merge([
            'host' => '127.0.0.1',
            'port' => $port,
            'root' => '/',
            'username' => 'foo',
            'password' => 'pass',
            'timeout' => 3,
            'passive' => true,
            'utf8' => false,
        ], $overrides));
    }

    protected function adapter(array $optionOverrides = [], ?FtpAdapter $adapter = null): FtpAdapter
    {
        if ($adapter !== null) {
            return $adapter;
        }

        return new FtpAdapter($this->adapterOptions($optionOverrides));
    }

    /**
     * @return list<string>
     */
    protected function listCommands(): array
    {
        return $this->server?->commandLog() ?? [];
    }

    protected function commandTokens(string $commandPrefix): array
    {
        foreach ($this->listCommands() as $line) {
            if (stripos($line, $commandPrefix) === 0) {
                return preg_split('/\s+/', $line) ?: [];
            }
        }

        return [];
    }

    protected function assertListCommandHasOption(string $option): void
    {
        $found = false;
        foreach ($this->listCommands() as $line) {
            if (stripos($line, 'LIST') !== 0) {
                continue;
            }
            if (str_contains($line, $option)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Expected LIST command to contain option {$option}");
    }

    protected function assertListCommandLacksOption(string $option): void
    {
        $foundList = false;
        foreach ($this->listCommands() as $line) {
            if (stripos($line, 'LIST') !== 0) {
                continue;
            }
            $foundList = true;
            $this->assertStringNotContainsString(
                $option,
                $line,
                'LIST command should not contain option ' . $option
            );
        }
        $this->assertTrue($foundList, 'Expected at least one LIST command in the command log');
    }
}
