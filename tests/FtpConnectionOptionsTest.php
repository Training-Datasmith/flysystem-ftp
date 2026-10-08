<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests;

use League\Flysystem\Ftp\FtpConnectionOptions;
use PHPUnit\Framework\TestCase;

final class FtpConnectionOptionsTest extends TestCase
{
    public function test_constructor_round_trips_every_field(): void
    {
        $options = new FtpConnectionOptions(
            'host.example',
            '/root',
            'user',
            'secret',
            2121,
            true,
            30,
            true,
            false,
            FTP_ASCII,
            'windows',
            true,
            true,
            true,
            false,
        );

        $this->assertSame('host.example', $options->host());
        $this->assertSame('/root', $options->root());
        $this->assertSame('user', $options->username());
        $this->assertSame('secret', $options->password());
        $this->assertSame(2121, $options->port());
        $this->assertTrue($options->ssl());
        $this->assertSame(30, $options->timeout());
        $this->assertTrue($options->utf8());
        $this->assertFalse($options->passive());
        $this->assertSame(FTP_ASCII, $options->transferMode());
        $this->assertSame('windows', $options->systemType());
        $this->assertTrue($options->ignorePassiveAddress());
        $this->assertTrue($options->timestampsOnUnixListingsEnabled());
        $this->assertTrue($options->recurseManually());
        $this->assertFalse($options->useRawListOptions());
    }

    public function test_from_array_defaults(): void
    {
        $options = FtpConnectionOptions::fromArray([]);

        $this->assertSame('invalid://host-not-set', $options->host());
        $this->assertSame('', $options->root());
        $this->assertSame('invalid://username-not-set', $options->username());
        $this->assertSame('invalid://password-not-set', $options->password());
        $this->assertSame(21, $options->port());
        $this->assertFalse($options->ssl());
        $this->assertSame(90, $options->timeout());
        $this->assertFalse($options->utf8());
        $this->assertTrue($options->passive());
        $this->assertSame(FTP_BINARY, $options->transferMode());
        $this->assertNull($options->systemType());
        $this->assertNull($options->ignorePassiveAddress());
        $this->assertFalse($options->timestampsOnUnixListingsEnabled());
        $this->assertTrue($options->recurseManually());
        $this->assertNull($options->useRawListOptions());
    }

    public function test_from_array_passes_through_explicit_values(): void
    {
        $options = FtpConnectionOptions::fromArray([
            'host' => 'h',
            'root' => '/r',
            'username' => 'u',
            'password' => 'p',
            'recurseManually' => false,
            'ignorePassiveAddress' => false,
        ]);

        $this->assertSame('h', $options->host());
        $this->assertSame('/r', $options->root());
        $this->assertFalse($options->recurseManually());
        $this->assertFalse($options->ignorePassiveAddress());
    }
}
