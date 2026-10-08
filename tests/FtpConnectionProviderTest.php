<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests;

use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\Ftp\FtpConnectionProvider;
use League\Flysystem\Ftp\Tests\Support\FakeFtpServerProcess;
use League\Flysystem\Ftp\Tests\Support\FtpFunctionControl;
use League\Flysystem\Ftp\Tests\Support\ImmediateCloseServer;
use League\Flysystem\Ftp\UnableToAuthenticate;
use League\Flysystem\Ftp\UnableToConnectToFtpHost;
use League\Flysystem\Ftp\UnableToEnableUtf8Mode;
use League\Flysystem\Ftp\UnableToMakeConnectionPassive;
use League\Flysystem\Ftp\UnableToSetFtpOption;
use PHPUnit\Framework\TestCase;

final class FtpConnectionProviderTest extends TestCase
{
    private ?FakeFtpServerProcess $server = null;

    private ?ImmediateCloseServer $immediateClose = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->immediateClose?->stop();
        FtpFunctionControl::$failSetOption = false;
        parent::tearDown();
    }

    public function test_connects_with_utf8_passive_and_ignore_passive_address(): void
    {
        $this->server = new FakeFtpServerProcess();
        $port = $this->server->start();
        $options = FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'utf8' => true,
            'passive' => true,
            'ignorePassiveAddress' => true,
            'timeout' => 3,
        ]);

        $connection = (new FtpConnectionProvider())->createConnection($options);
        $this->assertIsOpenFtpConnection($connection);
        $this->assertTrue(ftp_close($connection));
        $this->assertGreaterThan(0, count(array_filter($this->server->commandLog(), static fn (string $line): bool => str_starts_with($line, 'USER '))));
        $log = implode("\n", $this->server->commandLog());
        $this->assertStringContainsString('OPTS UTF8 ON', $log);
        $this->assertStringContainsString('PASV', $log);
    }

    public function test_utf8_202_is_success(): void
    {
        $this->server = new FakeFtpServerProcess(['opts_utf8_response' => '202 UTF8 mode is always enabled.']);
        $port = $this->server->start();
        $options = FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'utf8' => true,
            'timeout' => 3,
        ]);

        $connection = (new FtpConnectionProvider())->createConnection($options);
        $this->assertIsOpenFtpConnection($connection);
        ftp_close($connection);
    }

    public function test_utf8_500_throws_with_host_and_port_in_message(): void
    {
        $this->server = new FakeFtpServerProcess(['opts_utf8_response' => '500 Error']);
        $port = $this->server->start();
        $options = FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'utf8' => true,
            'timeout' => 3,
        ]);

        $this->expectException(UnableToEnableUtf8Mode::class);
        $this->expectExceptionMessageMatches('/127\.0\.0\.1::' . $port . '/');
        (new FtpConnectionProvider())->createConnection($options);
    }

    public function test_utf8_disconnect_throws_without_a_warning(): void
    {
        $this->server = new FakeFtpServerProcess(['close_on_opts' => true]);
        $port = $this->server->start();
        $options = FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'utf8' => true,
            'timeout' => 3,
        ]);

        try {
            (new FtpConnectionProvider())->createConnection($options);
            $this->fail('Expected UnableToEnableUtf8Mode');
        } catch (UnableToEnableUtf8Mode $exception) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_rejected_pasv_throws(): void
    {
        $this->server = new FakeFtpServerProcess(['reject_commands' => ['PASV']]);
        $port = $this->server->start();
        $options = FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 3,
        ]);

        $this->expectException(UnableToMakeConnectionPassive::class);
        (new FtpConnectionProvider())->createConnection($options);
    }

    public function test_closed_before_greeting_throws(): void
    {
        $this->immediateClose = new ImmediateCloseServer();
        $port = $this->immediateClose->start();
        $options = FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 2,
        ]);

        try {
            (new FtpConnectionProvider())->createConnection($options);
            $this->fail('Expected UnableToConnectToFtpHost');
        } catch (UnableToConnectToFtpHost $e) {
            $this->assertStringContainsString('127.0.0.1', $e->getMessage());
            $this->assertStringContainsString((string) $port, $e->getMessage());
            $this->assertStringNotContainsString('using ssl', $e->getMessage());
        }
    }

    public function test_ssl_closed_before_greeting_throws(): void
    {
        $this->immediateClose = new ImmediateCloseServer();
        $port = $this->immediateClose->start();
        $options = FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'ssl' => true,
            'timeout' => 2,
        ]);

        $this->expectException(UnableToConnectToFtpHost::class);
        $this->expectExceptionMessageMatches('/using ssl/i');
        (new FtpConnectionProvider())->createConnection($options);
    }

    public function test_bad_password_throws(): void
    {
        $this->server = new FakeFtpServerProcess();
        $port = $this->server->start();
        $options = FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'password' => 'bad',
            'timeout' => 3,
        ]);

        $this->expectException(UnableToAuthenticate::class);
        (new FtpConnectionProvider())->createConnection($options);
    }

    public function test_failed_use_pasv_address_option_throws(): void
    {
        $this->server = new FakeFtpServerProcess();
        $port = $this->server->start();
        FtpFunctionControl::$failSetOption = true;
        $options = FtpConnectionOptions::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'ignorePassiveAddress' => true,
            'timeout' => 3,
        ]);

        try {
            (new FtpConnectionProvider())->createConnection($options);
            $this->fail('Expected UnableToSetFtpOption');
        } catch (UnableToSetFtpOption $exception) {
            $this->assertStringContainsString('FTP_USEPASVADDRESS', $exception->getMessage());
        }

        FtpFunctionControl::$failSetOption = false;
        $connection = (new FtpConnectionProvider())->createConnection($options);
        $this->assertIsOpenFtpConnection($connection);
        ftp_close($connection);
    }

    /**
     * @param resource|\FTP\Connection $connection
     */
    private function assertIsOpenFtpConnection($connection): void
    {
        $this->assertTrue(
            is_resource($connection)
            || (class_exists(\FTP\Connection::class) && $connection instanceof \FTP\Connection)
        );
    }
}
