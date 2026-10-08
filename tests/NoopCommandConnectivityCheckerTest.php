<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests;

use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\Ftp\FtpConnectionProvider;
use League\Flysystem\Ftp\NoopCommandConnectivityChecker;
use League\Flysystem\Ftp\Tests\Support\FakeFtpServerProcess;
use PHPUnit\Framework\TestCase;

final class NoopCommandConnectivityCheckerTest extends TestCase
{
    private ?FakeFtpServerProcess $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        parent::tearDown();
    }

    public function test_open_connection_is_connected(): void
    {
        $this->server = new FakeFtpServerProcess();
        $port = $this->server->start();
        $connection = (new FtpConnectionProvider())->createConnection(FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 3,
        ]));

        $this->assertTrue((new NoopCommandConnectivityChecker())->isConnected($connection));
        ftp_close($connection);
    }

    public function test_closed_connection_is_not_connected(): void
    {
        $this->server = new FakeFtpServerProcess();
        $port = $this->server->start();
        $connection = (new FtpConnectionProvider())->createConnection(FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 3,
        ]));
        ftp_close($connection);

        $this->assertFalse((new NoopCommandConnectivityChecker())->isConnected($connection));
    }

    public function test_non_200_noop_is_not_connected(): void
    {
        $this->server = new FakeFtpServerProcess(['noop_response' => '421 Service not available']);
        $port = $this->server->start();
        $connection = (new FtpConnectionProvider())->createConnection(FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 3,
        ]));

        $this->assertFalse((new NoopCommandConnectivityChecker())->isConnected($connection));
        ftp_close($connection);
    }
}
