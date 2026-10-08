<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests\Support;

use League\Flysystem\Ftp\ConnectivityChecker;
use League\Flysystem\Ftp\NoopCommandConnectivityChecker;

final class FailingConnectivityChecker implements ConnectivityChecker
{
    private bool $failNext = false;

    public function __construct(private ConnectivityChecker $inner = new NoopCommandConnectivityChecker())
    {
    }

    public function failNextCall(): void
    {
        $this->failNext = true;
    }

    public function isConnected($connection): bool
    {
        if ($this->failNext) {
            $this->failNext = false;

            return false;
        }

        return $this->inner->isConnected($connection);
    }
}
