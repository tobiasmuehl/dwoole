<?php declare(strict_types=1);

const WATCH_DIR = '/app';
const PHP_BIN = '/usr/local/bin/php';
const RESTART_CMD = '@restart';

define('ENTRY_POINT_FILE', $_ENV['ENTRY_POINT_FILE'] ?? '/app/index.php');

if (!file_exists(ENTRY_POINT_FILE)) {
    echo "Entry-point file (".ENTRY_POINT_FILE.") not found. It should be on the root directory. Is it there?\n";
    exit(1);
}

use Swoole\Process;
use Swoole\Timer;
use Swoole\Event;

echo "🚀 Start\n";
start();

function start()
{
    $watch = new Process('watch', true);
    $watch->start();

    if (false === $watch->pid) {
        echo swoole_strerror(swoole_errno())."\n";
        exit(1);
    }

    Event::add($watch->pipe, function ($pipe) use (&$watch) {
        $message = $watch->read();

        if (RESTART_CMD === $message) {
            echo "🔄 Restart\n";
            start();
        } else {
            if (!empty($message)) {
                echo $message."\n";
            }
        }
    });
}

function watch(Process $watch)
{
    $serve = new Process('serve', true);
    $serve->start();

    if (false === $serve->pid) {
        echo swoole_strerror(swoole_errno())."\n";
        exit(1);
    }

    Event::add($serve->pipe, function ($pipe) use (&$serve) {
        $message = $serve->read();

        if (!empty($message)) {
            echo $message;
        }
    });

    $files = php_files(WATCH_DIR);
    $hashes = array_combine($files, array_map('file_hash', $files));
    $count = count($hashes);

    echo "📡 Watching $count file(s)...";

    Timer::tick(2000, function () use (&$hashes, &$watch, &$serve) {
        foreach ($hashes as $pathname => $current_hash) {
            if (!file_exists($pathname)) {
                unset($hashes[$pathname]);
                continue;
            }

            $new_hash = file_hash($pathname);

            if ($new_hash != $current_hash) {
                Process::kill($serve->pid);
                echo RESTART_CMD;
                $watch->exit();
            }
        }
    });
}

function serve(Process $serve)
{
    $serve->exec(PHP_BIN, [ENTRY_POINT_FILE]);
}

function file_hash(string $pathname): string
{
    $contents = file_get_contents($pathname);

    // File may be deleted on the fly
    if (false === $contents) {
        return 'deleted';
    }

    return md5($contents);
}

function php_files(string $dirname): array
{
    $directory = new RecursiveDirectoryIterator($dirname);
    $filter = new Filter($directory);
    $iterator = new RecursiveIteratorIterator($filter);

    return array_map(function ($fileInfo) {
        return $fileInfo->getPathname();
    }, iterator_to_array($iterator));
}

class Filter extends RecursiveFilterIterator
{
    public function accept()
    {
        if ($this->current()->isDir()) {
            if (preg_match('/^\./', $this->current()->getFilename())) {
                return false;
            }

            return !in_array($this->current()->getFilename(), ['vendor']);
        }

        return preg_match('/\.php$/', $this->current()->getFilename());
    }
}
