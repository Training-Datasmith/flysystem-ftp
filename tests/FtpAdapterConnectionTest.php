<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests;

use League\Flysystem\Config;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\Tests\Support\AdapterTestCase;
use League\Flysystem\Ftp\Tests\Support\FailingConnectivityChecker;
use League\Flysystem\Ftp\UnableToResolveConnectionRoot;

final class FtpAdapterConnectionTest extends AdapterTestCase
{
    public function test_empty_root_uses_pwd(): void
    {
        $this->startServer();
        $adapter = $this->adapter(['root' => '']);
        $adapter->write('dirname1/dirname2/path.txt', 'contents', new Config());
        $adapter->write('dirname1/dirname2/path.txt', 'contents', new Config());
        $this->assertTrue($adapter->fileExists('dirname1/dirname2/path.txt'));
        $this->assertSame('contents', $adapter->read('dirname1/dirname2/path.txt'));
    }

    public function test_configured_root_is_prefixed(): void
    {
        $this->startServer();
        $this->server->seedDirectory('/home/foo/upload');
        $adapter = $this->adapter(['root' => '/home/foo/upload']);
        $adapter->write('a.txt', 'hello', new Config());
        $this->assertSame('hello', $adapter->read('a.txt'));
        $log = implode("\n", $this->listCommands());
        $this->assertStringContainsString('CWD /home/foo/upload', $log);
        $this->assertStringContainsString('STOR /home/foo/upload/a.txt', $log);
    }

    public function test_missing_root_throws(): void
    {
        $this->startServer();
        $adapter = $this->adapter(['root' => '/invalid/root']);
        $this->expectException(UnableToResolveConnectionRoot::class);
        $this->expectExceptionMessage('/invalid/root');
        $adapter->delete('something');
    }

    public function test_missing_root_can_be_called_again(): void
    {
        $this->startServer();
        $adapter = $this->adapter(['root' => '/invalid/root']);
        try {
            $adapter->delete('something');
        } catch (UnableToResolveConnectionRoot) {
        }
        $this->expectException(UnableToResolveConnectionRoot::class);
        $adapter->delete('something');
    }

    public function test_pwd_failure_throws(): void
    {
        $this->startServer(['close_on_command' => 'PWD']);
        $this->server->seedDirectory('/home/foo/upload');
        $adapter = $this->adapter(['root' => '/home/foo/upload']);
        $this->expectException(UnableToResolveConnectionRoot::class);
        $this->expectExceptionMessage('Could not resolve the current directory');
        $adapter->delete('x');
    }

    public function test_disconnect_closes_and_later_call_reconnects(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('file.txt', 'data', new Config());
        $this->assertTrue($adapter->fileExists('file.txt'));
        $adapter->disconnect();
        $this->assertSame('data', $adapter->read('file.txt'));
        $log = $this->listCommands();
        $userCount = 0;
        foreach ($log as $line) {
            if (str_starts_with($line, 'USER ')) {
                ++$userCount;
            }
        }
        $this->assertGreaterThanOrEqual(2, $userCount);
    }

    public function test_destructor_closes_the_session(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('file.txt', 'data', new Config());
        unset($adapter);
        gc_collect_cycles();
        $this->assertTrue($this->server->sessionClosedAfterLastCommand());
        $adapter2 = $this->adapter();
        $this->assertSame('data', $adapter2->read('file.txt'));
    }

    public function test_reconnects_when_connectivity_check_fails_once(): void
    {
        $this->startServer();
        $checker = new FailingConnectivityChecker();
        $adapter = new FtpAdapter($this->adapterOptions(), null, $checker);
        $adapter->write('file.txt', 'payload', new Config());
        $checker->failNextCall();
        $this->assertSame('payload', $adapter->read('file.txt'));
    }
}
