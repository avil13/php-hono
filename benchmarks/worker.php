<?php

declare(strict_types=1);

use PhpHono\Adapter\FrankenPhpWorker;
use PhpHono\Hono;

/** @var Hono $app */
$app = require __DIR__ . '/app.php';
FrankenPhpWorker::run($app, maxRequests: 100_000);
