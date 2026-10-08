<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests;

use League\Flysystem\Config;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\Tests\Support\AdapterTestCase;
use League\MimeTypeDetection\MimeTypeDetector;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;

final class FtpAdapterMetadataTest extends AdapterTestCase
{
    public function test_file_size_and_last_modified(): void
    {
        $this->startServer(['mdtm_response' => '213 20261007120000']);
        $adapter = $this->adapter();
        $adapter->write('note.txt', 'hello', new Config());
        $this->assertSame(5, $adapter->fileSize('note.txt')->fileSize());
        $this->assertSame(1791374400, $adapter->lastModified('note.txt')->lastModified());
    }

    public function test_mime_type_from_contents(): void
    {
        $this->startServer();
        $adapter = $this->adapter();
        $adapter->write('note.txt', 'hello', new Config());
        $this->assertSame('text/plain', $adapter->mimeType('note.txt')->mimeType());
    }

    public function test_mime_type_from_path_does_not_read(): void
    {
        $this->startServer();
        $adapter = new FtpAdapter($this->adapterOptions(), null, null, null, null, true);
        $this->assertSame('text/plain', $adapter->mimeType('note.txt')->mimeType());
    }

    public function test_null_mime_throws(): void
    {
        $this->startServer();
        $detector = new class implements MimeTypeDetector {
            public function detectMimeType(string $path, $contents): ?string
            {
                return null;
            }

            public function detectMimeTypeFromPath(string $path): ?string
            {
                return null;
            }

            public function detectMimeTypeFromBuffer(string $contents): ?string
            {
                return null;
            }

            public function detectMimeTypeFromFile(string $path): ?string
            {
                return null;
            }
        };
        $adapter = new FtpAdapter($this->adapterOptions(), null, null, null, $detector);
        $adapter->write('x.bin', 'data', new Config());
        $this->expectException(UnableToRetrieveMetadata::class);
        $adapter->mimeType('x.bin');
    }

    public function test_visibility_stat_uses_the_listing_line(): void
    {
        $this->startServer([
            'stat_response' => [
                '213-Status follows:',
                '-rw-------   1 ftp      ftp            10 Jan  1 00:00 secret.txt',
                '213 End',
            ],
        ]);
        $adapter = $this->adapter();
        $this->server->seedFile('/secret.txt', '0123456789');
        $this->assertSame(Visibility::PRIVATE, $adapter->visibility('secret.txt')->visibility());
        $this->assertSame(10, $adapter->fileSize('secret.txt')->fileSize());
    }

    public function test_stat_of_a_directory_throws(): void
    {
        $this->startServer([
            'stat_response' => [
                '213-Status follows:',
                'drwxr-xr-x   1 ftp      ftp          4096 Jan  1 00:00 folder',
                '213 End',
            ],
        ]);
        $adapter = $this->adapter();
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->expectExceptionMessage('directory found');
        $adapter->visibility('folder');
    }

    public function test_pureftpd_stat_escapes_brackets_on_a_fresh_adapter(): void
    {
        $this->startServer(['pureftpd' => true]);
        $adapter = $this->adapter();
        $this->server->seedFile('/a[b].txt', 'x');
        $adapter->visibility('a[b].txt');
        $log = implode("\n", $this->listCommands());
        $this->assertStringContainsString('STAT', $log);
        $this->assertStringContainsString('\\[', $log);
        $this->assertStringContainsString('\\]', $log);
    }

    public function test_pureftpd_stat_escapes_brackets_after_write_disconnect_and_visibility(): void
    {
        $this->startServer(['pureftpd' => true]);
        $adapter = $this->adapter();
        $adapter->write('a[b].txt', 'x', new Config());
        $adapter->disconnect();
        $adapter->visibility('a[b].txt');
        $log = implode("\n", $this->listCommands());
        $this->assertStringContainsString('STAT', $log);
        $this->assertStringContainsString('\\[', $log);
        $this->assertStringContainsString('\\]', $log);
    }
}
