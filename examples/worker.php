<?php

declare(strict_types=1);

use PhpHono\Adapter\FrankenPhpWorker;
use PhpHono\Hono;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var Hono $app */
$app = require __DIR__ . '/app.php';
FrankenPhpWorker::run($app);
