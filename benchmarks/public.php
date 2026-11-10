<?php

declare(strict_types=1);

use PhpHono\Adapter\Sapi;
use PhpHono\Hono;

/** @var Hono $app */
$app = require __DIR__ . '/app.php';
Sapi::run($app);
