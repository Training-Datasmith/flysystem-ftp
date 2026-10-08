<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests;

use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\Ftp\FtpConnectionProvider;
use League\Flysystem\Ftp\RawListFtpConnectivityChecker;
use League\Flysystem\Ftp\Tests\Support\FakeFtpServerProcess;
use PHPUnit\Framework\TestCase;

final class RawListFtpConnectivityCheckerTest extends TestCase
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
        $checker = new RawListFtpConnectivityChecker();

        $this->assertTrue($checker->isConnected($connection));
        ftp_close($connection);
        $this->assertFalse($checker->isConnected($connection));
    }

    public function test_false_connection_is_not_connected(): void
    {
        $this->assertFalse((new RawListFtpConnectivityChecker())->isConnected(false));
    }

    public function test_rejected_list_is_not_connected(): void
    {
        $this->server = new FakeFtpServerProcess(['reject_commands' => ['LIST']]);
        $port = $this->server->start();
        $connection = (new FtpConnectionProvider())->createConnection(FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 3,
        ]));

        $this->assertFalse((new RawListFtpConnectivityChecker())->isConnected($connection));
        ftp_close($connection);
    }
}
