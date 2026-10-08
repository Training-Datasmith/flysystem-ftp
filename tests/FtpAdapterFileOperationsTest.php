<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests;

use League\Flysystem\Config;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\Tests\Support\AdapterTestCase;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\Visibility;

final class FtpAdapterFileOperationsTest extends AdapterTestCase
{
    public function test_write_and_read_stream_round_trip(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'stream-bytes');
        rewind($stream);
        $adapter->writeStream('s.bin', $stream, new Config());
        fclose($stream);
        $read = $adapter->readStream('s.bin');
        $this->assertSame('stream-bytes', stream_get_contents($read));
        fclose($read);
    }

    public function test_binary_payload_with_null_byte(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $payload = "a\0b\0c";
        $adapter->write('bin.dat', $payload, new Config());
        $this->assertSame($payload, $adapter->read('bin.dat'));
    }

    public function test_read_missing_file_throws(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $this->expectException(UnableToReadFile::class);
        $adapter->read('missing.txt');
    }

    public function test_read_three_times_returns_the_same_bytes(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('some/nested/path.txt', 'this is it', new Config());
        $this->assertSame('this is it', $adapter->read('some/nested/path.txt'));
        $this->assertSame('this is it', $adapter->read('some/nested/path.txt'));
        $this->assertSame('this is it', $adapter->read('some/nested/path.txt'));
    }

    public function test_write_creates_parent_directories(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('a/b/c.txt', 'x', new Config());
        $this->assertTrue($adapter->directoryExists('a/b'));
        $this->assertTrue($adapter->directoryExists('a'));
        $this->assertFalse($adapter->directoryExists('a/b/c.txt'));
        $this->assertFalse($adapter->directoryExists('missing'));
    }

    public function test_file_exists_is_false_for_directories_and_missing_paths(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->createDirectory('directory_name', new Config());
        $this->assertFalse($adapter->fileExists('directory_name'));
        $this->expectException(\League\Flysystem\UnableToRetrieveMetadata::class);
        $adapter->fileSize('directory_name');
    }

    public function test_names_with_spaces_round_trip(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('some dirname/file name.txt', 'ok', new Config());
        $this->assertTrue($adapter->fileExists('some dirname/file name.txt'));
        $contents = iterator_to_array($adapter->listContents('', true));
        $paths = array_map(static fn (StorageAttributes $item) => $item->path(), $contents);
        $this->assertContains('some dirname/file name.txt', $paths);
    }

    public function test_public_and_private_visibility(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('pub.txt', 'a', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $adapter->write('priv.txt', 'b', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $log = implode("\n", $this->listCommands());
        $this->assertStringContainsString('SITE CHMOD 644', $log);
        $this->assertStringContainsString('SITE CHMOD 600', $log);
        $this->assertSame(Visibility::PUBLIC, $adapter->visibility('pub.txt')->visibility());
        $this->assertSame(Visibility::PRIVATE, $adapter->visibility('priv.txt')->visibility());
    }

    public function test_ascii_transfer_mode_is_sent(): void
    {
        $this->startServer();
        $adapter = $this->adapter(['transferMode' => FTP_ASCII]);
        $adapter->write('hello.txt', 'hello', new Config());
        $this->assertSame('hello', $adapter->read('hello.txt'));
        $log = implode("\n", $this->listCommands());
        $this->assertStringContainsString('TYPE A', $log);
    }

    public function test_delete_removes_the_file_and_missing_delete_is_idempotent(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('gone.txt', 'x', new Config());
        $adapter->delete('gone.txt');
        $this->assertFalse($adapter->fileExists('gone.txt'));
        $adapter->delete('gone.txt');
        $this->assertFalse($adapter->fileExists('gone.txt'));
    }

    public function test_delete_failure_while_file_remains_throws(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'contents', new Config());
        $this->server->writeConfig(['reject_commands' => ['DELE']]);
        $this->expectException(UnableToDeleteFile::class);
        $adapter->delete('path.txt');
    }

    public function test_delete_directory_removes_nested_entries(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('some/nested/path.txt', 'x', new Config());
        $adapter->deleteDirectory('some');
        $this->assertFalse($adapter->directoryExists('some'));
        $this->assertFalse($adapter->fileExists('some/nested/path.txt'));
    }

    public function test_move_renames_and_creates_the_parent(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'bytes', new Config());
        $adapter->move('path.txt', 'new/path.txt', new Config());
        $this->assertFalse($adapter->fileExists('path.txt'));
        $this->assertSame('bytes', $adapter->read('new/path.txt'));
    }

    public function test_copy_preserves_bytes_and_private_visibility(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'secret', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $adapter->copy('path.txt', 'copy.txt', new Config());
        $this->assertSame('secret', $adapter->read('copy.txt'));
        $this->assertSame(Visibility::PRIVATE, $adapter->visibility('copy.txt')->visibility());
    }

    public function test_copy_read_failure_throws(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'x', new Config());
        $this->server->writeConfig(['reject_commands' => ['RETR']]);
        $this->expectException(UnableToCopyFile::class);
        $adapter->copy('path.txt', 'copy.txt', new Config());
    }

    public function test_write_stor_failure_throws(): void
    {
        $this->startServer(['reject_commands' => ['STOR']]);
        $adapter = $this->adapter();
        $this->expectException(UnableToWriteFile::class);
        $adapter->write('some/path.txt', 'contents', new Config());
    }

    public function test_set_visibility_failure_throws(): void
    {
        $this->startServer(['reject_commands' => ['SITE']]);
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'x', new Config());
        $this->expectException(UnableToSetVisibility::class);
        $adapter->setVisibility('path.txt', Visibility::PUBLIC);
    }

    public function test_ignore_passive_address_uses_control_host(): void
    {
        $this->startServer(['pasv_address' => '127.0.0.2']);
        $adapter = $this->adapter(['ignorePassiveAddress' => true, 'timeout' => 2]);
        $adapter->write('ok.txt', 'ok', new Config());
        $this->assertSame('ok', $adapter->read('ok.txt'));

        $this->server->stop();
        $this->startServer(['pasv_address' => '127.0.0.2']);
        $adapterWithout = $this->adapter(['ignorePassiveAddress' => null, 'timeout' => 2]);
        $this->expectException(UnableToWriteFile::class);
        $adapterWithout->write('fail.txt', 'fail', new Config());
    }

    public function test_listing_directory_named_zero(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('0/path.txt', 'z', new Config());
        $adapter->write('1/path.txt', 'y', new Config());
        $listing = iterator_to_array($adapter->listContents('0', false));
        $this->assertCount(1, $listing);
        $this->assertSame('0/path.txt', $listing[0]->path());
    }
}
