<?php

declare(strict_types=1);

use PhpHono\Adapter\Sapi;
use PhpHono\Hono;

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

/** @var Hono $app */
$app = require dirname(__DIR__) . '/app.php';
Sapi::run($app);
