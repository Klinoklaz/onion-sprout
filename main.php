<?php

use App\HttpRelay;
use Symfony\Component\Dotenv\Dotenv;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

(new Dotenv())->overload(__DIR__ . '/.env');

Worker::$logFile = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
new HttpRelay(fn(string $name, $default = null)
    => $_ENV[strtoupper($name)] ?? $default);
Worker::runAll();