<?php

declare(strict_types=1);

namespace App\Logging;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class TechnicalLogArchive
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_ARCHIVES = 30;
    private const MAX_ARCHIVE_BYTES = 128 * 1024 * 1024;

    /**
     * Only files belonging to the dedicated sanitized pms_daily channel are
     * eligible. Never traverse subdirectories or accept arbitrary paths.
     * The caller supplies the fixed application storage directories.
     */
    public function candidates(string $directory, string $today): array
    {
        if (! is_dir($directory) || is_link($directory)) {
            return [];
        }

        $result = [];
        foreach (scandir($directory) ?: [] as $name) {
            if (! preg_match('/\\Apms-technical-([0-9]{4}-[0-9]{2}-[0-9]{2})\\.log\\z/D', $name, $matches)) {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1], new DateTimeZone('UTC'));
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            if ($date === false || $date->format('Y-m-d') !== $matches[1] || $matches[1] >= $today
                || is_link($path) || ! is_file($path)) {
                continue;
            }
            $size = filesize($path);
            if ($size !== false && $size <= self::MAX_BYTES) {
                $result[] = $name;
            }
        }
        sort($result);

        return array_slice($result, -10);
    }

    public function archive(string $directory, string $destination, string $name, string $today): bool
    {
        if (! in_array($name, $this->candidates($directory, $today), true)) {
            throw new RuntimeException('Log is not an eligible completed daily file.');
        }
        if (! is_dir($destination) && ! mkdir($destination, 0700, true) && ! is_dir($destination)) {
            throw new RuntimeException('Cannot create private archive directory.');
        }
        if (is_link($destination)) {
            throw new RuntimeException('Archive directory must not be a symlink.');
        }
        $target = $destination.DIRECTORY_SEPARATOR.$name.'.gz';
        if (file_exists($target) || is_link($target)) {
            return false;
        }
        $total = 0;
        $count = 0;
        foreach (scandir($destination) ?: [] as $entry) {
            $path = $destination.DIRECTORY_SEPARATOR.$entry;
            if (preg_match('/\\Apms-technical-[0-9]{4}-[0-9]{2}-[0-9]{2}\\.log\\.gz\\z/D', $entry)
                && is_file($path) && ! is_link($path)) {
                $total += (int) filesize($path);
                $count++;
            }
        }
        if ($count >= self::MAX_ARCHIVES || $total >= self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Archive capacity reached; export existing archives before continuing.');
        }

        $source = $directory.DIRECTORY_SEPARATOR.$name;
        // Bounded memory, and a second check prevents accepting a grown file.
        $input = fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot read completed log.');
        }
        $output = null;
        $created = false;
        $complete = false;
        try {
            $before = fstat($input);
            if ($before === false || $before['size'] > self::MAX_BYTES || is_link($source)) {
                throw new RuntimeException('Completed log is not eligible.');
            }
            $plain = stream_get_contents($input, self::MAX_BYTES + 1);
            $after = fstat($input);
            if ($plain === false || strlen($plain) > self::MAX_BYTES || $after === false
                || $before['size'] !== strlen($plain) || $after['size'] !== $before['size']
                || $after['mtime'] !== $before['mtime']) {
                throw new RuntimeException('Log changed during archive; try again later.');
            }
            $compressed = gzencode($plain, 6);
            if ($compressed === false || $total + strlen($compressed) > self::MAX_ARCHIVE_BYTES) {
                throw new RuntimeException('Archive exceeds capacity.');
            }
            // Exclusive creation prevents overwriting an existing archive.
            $oldMask = umask(0077);
            try {
                $output = @fopen($target, 'xb');
            } finally {
                umask($oldMask);
            }
            if ($output === false) {
                throw new RuntimeException('Archive could not be created exclusively.');
            }
            $created = true;
            if (fwrite($output, $compressed) !== strlen($compressed) || ! fflush($output)) {
                throw new RuntimeException('Incomplete archive write.');
            }
            $complete = true;

            return true;
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
            if ($created && ! $complete) {
                @unlink($target);
            }
            // Source files are NEVER deleted by archival.
        }
    }
}
