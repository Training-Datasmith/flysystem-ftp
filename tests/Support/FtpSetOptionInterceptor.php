<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp;

use League\Flysystem\Ftp\Tests\Support\FtpFunctionControl;

if ( ! function_exists('League\Flysystem\Ftp\ftp_set_option')) {
    function ftp_set_option($ftp, int $option, $value): bool
    {
        if (FtpFunctionControl::$failSetOption) {
            return false;
        }

        return \ftp_set_option($ftp, $option, $value);
    }
}
