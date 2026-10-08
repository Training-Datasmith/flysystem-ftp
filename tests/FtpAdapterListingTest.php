<?php

declare(strict_types=1);

namespace League\Flysystem\Ftp\Tests;

use DateTime;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\InvalidListResponseReceived;
use League\Flysystem\Ftp\Tests\Support\AdapterTestCase;
use League\Flysystem\StorageAttributes;

final class FtpAdapterListingTest extends AdapterTestCase
{
    public function test_shallow_list_skips_total_dot_and_dotdot(): void
    {
        $this->startServer([
            'list_override' => [
                'total 1',
                '-rw-r--r--   1 ftp      ftp           409 Aug 19 09:01 file1.txt',
            ],
        ]);
        $adapter = $this->adapter();
        $contents = iterator_to_array($adapter->listContents('/', false));
        $this->assertCount(1, $contents);
        $this->assertInstanceOf(FileAttributes::class, $contents[0]);
        $this->assertSame('file1.txt', $contents[0]->path());
        $paths = array_map(static fn (StorageAttributes $item) => $item->path(), $contents);
        $this->assertNotContains('.', $paths);
        $this->assertNotContains('..', $paths);
    }

    public function test_timestamps_disabled_leave_last_modified_null(): void
    {
        $this->startServer([
            'list_override' => ['-rw-r--r--   1 ftp      ftp           409 Oct 13  2012 index.html'],
        ]);
        $adapter = $this->adapter(['timestampsOnUnixListingsEnabled' => false]);
        $item = iterator_to_array($adapter->listContents('', false))[0];
        $this->assertNull($item->lastModified());
    }

    public function test_explicit_year_timestamp(): void
    {
        $this->startServer([
            'list_override' => ['-rw-r--r--   1 ftp      ftp           409 Oct 13  2012 index.html'],
        ]);
        $adapter = $this->adapter(['timestampsOnUnixListingsEnabled' => true]);
        $item = iterator_to_array($adapter->listContents('', false))[0];
        $this->assertSame(strtotime('2012-10-13 00:00:00 UTC'), $item->lastModified());
    }

    public function test_recent_unix_timestamp_uses_previous_year_when_more_than_one_day_ahead(): void
    {
        $now = new DateTime('now', new \DateTimeZone('UTC'));
        $year = (int) $now->format('Y');
        $timezone = $now->getTimezone();
        $rollbackThreshold = (clone $now)->modify('+1 day');
        $minimumFuture = (clone $rollbackThreshold)->modify('+1 hour');
        $future = null;

        if ((int) $minimumFuture->format('Y') === $year && $minimumFuture > $rollbackThreshold) {
            $future = $minimumFuture;
        }

        if ($future === null) {
            $yearEnd = DateTime::createFromFormat('Y-m-d H:i:s', $year . '-12-31 23:59:59', $timezone);
            if ($yearEnd !== false) {
                for ($timestamp = $minimumFuture->getTimestamp(); $timestamp <= $yearEnd->getTimestamp(); $timestamp += 60) {
                    $candidate = (new DateTime('@' . $timestamp))->setTimezone($timezone);
                    if ((int) $candidate->format('Y') === $year && $candidate > $rollbackThreshold) {
                        $future = $candidate;
                        break;
                    }
                }
            }
        }

        if ($future === null || $future <= $rollbackThreshold) {
            $this->markTestSkipped('Cannot build a same-calendar-year unix listing timestamp more than one day ahead of now.');
        }

        $assumed = DateTime::createFromFormat(
            'Y-M-j-G:i:s',
            sprintf(
                '%s-%s-%d-%02d:%02d:00',
                $now->format('Y'),
                $future->format('M'),
                (int) $future->format('j'),
                (int) $future->format('G'),
                (int) $future->format('i')
            ),
            new \DateTimeZone('UTC')
        );
        $this->assertInstanceOf(DateTime::class, $assumed);
        $this->assertGreaterThan($now, $assumed);

        $line = sprintf(
            '-rw-r--r--   1 ftp      ftp           409 %s %2d %02d:%02d file.txt',
            $future->format('M'),
            (int) $future->format('j'),
            (int) $future->format('G'),
            (int) $future->format('i')
        );
        $this->startServer(['list_override' => [$line]]);
        $adapter = new FtpAdapter($this->adapterOptions(['timestampsOnUnixListingsEnabled' => true]));
        $item = iterator_to_array($adapter->listContents('', false))[0];
        $expected = (clone $assumed)->modify('-1 year')->getTimestamp();
        $this->assertSame($expected, $item->lastModified());
    }

    public function test_recent_unix_timestamp_keeps_current_year_when_about_one_hour_ahead(): void
    {
        $now = new DateTime('now', new \DateTimeZone('UTC'));
        $year = (int) $now->format('Y');
        $future = (clone $now)->modify('+1 hour');

        if ((int) $future->format('Y') !== $year || $future <= $now) {
            $this->markTestSkipped('An hour ahead crosses the calendar year or is not in the future.');
        }

        $assumed = DateTime::createFromFormat(
            'Y-M-j-G:i:s',
            sprintf(
                '%s-%s-%d-%02d:%02d:00',
                $now->format('Y'),
                $future->format('M'),
                (int) $future->format('j'),
                (int) $future->format('G'),
                (int) $future->format('i')
            ),
            new \DateTimeZone('UTC')
        );
        $this->assertInstanceOf(DateTime::class, $assumed);

        $line = sprintf(
            '-rw-r--r--   1 ftp      ftp           409 %s %2d %02d:%02d file.txt',
            $future->format('M'),
            (int) $future->format('j'),
            (int) $future->format('G'),
            (int) $future->format('i')
        );
        $this->startServer(['list_override' => [$line]]);
        $adapter = new FtpAdapter($this->adapterOptions(['timestampsOnUnixListingsEnabled' => true]));
        $item = iterator_to_array($adapter->listContents('', false))[0];
        $this->assertSame($assumed->getTimestamp(), $item->lastModified());
    }

    public function test_colonless_unix_time_throws(): void
    {
        $this->startServer([
            'list_override' => ['-rw-r--r--   1 ftp      ftp           409 Oct 19 09h01 file1.txt'],
        ]);
        $adapter = $this->adapter(['timestampsOnUnixListingsEnabled' => true]);
        $this->expectException(InvalidListResponseReceived::class);
        iterator_to_array($adapter->listContents('/', false));
    }

    public function test_unix_timestamp_with_seconds_parses(): void
    {
        $this->startServer([
            'list_override' => ['-rw-r--r--   1 ftp      ftp           409 Aug 19 09:01:30 file1.txt'],
        ]);
        $adapter = $this->adapter(['timestampsOnUnixListingsEnabled' => true]);
        $item = iterator_to_array($adapter->listContents('', false))[0];
        $year = (int) (new DateTime('now', new \DateTimeZone('UTC')))->format('Y');
        $expected = DateTime::createFromFormat('Y-M-j-G:i:s', "$year-Aug-19-9:01:00", new \DateTimeZone('UTC'))->getTimestamp();
        $this->assertSame($expected, $item->lastModified());
    }

    public function test_unparseable_unix_timestamp_throws(): void
    {
        $this->startServer([
            'list_override' => ['-rw-r--r--   1 ftp      ftp           409 NotAMonth 19 09:01 file1.txt'],
        ]);
        $adapter = $this->adapter(['timestampsOnUnixListingsEnabled' => true]);
        $this->expectException(InvalidListResponseReceived::class);
        iterator_to_array($adapter->listContents('', false));
    }

    public function test_windows_listing_with_auto_detection(): void
    {
        $this->startServer([
            'list_override' => [
                '2015-05-23  12:09       <DIR>          dir1',
                '05-23-15  12:09PM                  684 file2.txt',
            ],
        ]);
        $adapter = $this->adapter(['systemType' => null]);
        $contents = iterator_to_array($adapter->listContents('/', false));
        $this->assertCount(2, $contents);
        $this->assertInstanceOf(DirectoryAttributes::class, $contents[0]);
        $this->assertSame('dir1', $contents[0]->path());
        $this->assertInstanceOf(FileAttributes::class, $contents[1]);
        $expected = DateTime::createFromFormat('m-d-yH:iA', '05-23-1512:09PM', new \DateTimeZone('UTC'))->getTimestamp();
        $this->assertSame($expected, $contents[1]->lastModified());
    }

    public function test_invalid_windows_line_throws(): void
    {
        $this->startServer(['list_override' => ['05-23-15  12:09PM    file2.txt']]);
        $adapter = $this->adapter(['systemType' => 'windows']);
        $this->expectException(InvalidListResponseReceived::class);
        iterator_to_array($adapter->listContents('/', false));
    }

    public function test_filezilla_omits_list_options(): void
    {
        $this->startServer([
            'syst_response' => '215 UNIX Type: L8 FileZilla',
            'list_override' => ['-rw-r--r--   1 ftp      ftp             4 Jan  1 00:00 a.txt'],
        ]);
        $adapter = $this->adapter();
        $contents = iterator_to_array($adapter->listContents('', false));
        $this->assertListCommandLacksOption('-aln');
        $paths = array_map(static fn (StorageAttributes $item) => $item->path(), $contents);
        $this->assertContains('a.txt', $paths);
    }

    public function test_use_raw_list_options_forces_aln(): void
    {
        $this->startServer([
            'syst_response' => '215 UNIX Type: L8 FileZilla',
            'list_override' => ['-rw-r--r--   1 ftp      ftp             4 Jan  1 00:00 a.txt'],
        ]);
        $adapter = $this->adapter(['useRawListOptions' => true]);
        iterator_to_array($adapter->listContents('', false));
        $this->assertListCommandHasOption('-aln');
    }

    public function test_manual_recursion_walks_children(): void
    {
        $this->startServer();
        $adapter = $this->adapter(['recurseManually' => true]);
        $adapter->write('somewhere/index.html', 'i', new Config());
        $adapter->write('somewhere/cgi-bin/.keep', 'k', new Config());
        $adapter->write('somewhere/folder/dummy.txt', 'd', new Config());
        $paths = array_map(
            static fn (StorageAttributes $item) => $item->path(),
            iterator_to_array($adapter->listContents('somewhere', true))
        );
        $this->assertContains('somewhere/index.html', $paths);
        $this->assertContains('somewhere/folder/dummy.txt', $paths);
    }
}
