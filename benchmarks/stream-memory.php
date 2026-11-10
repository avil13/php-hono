<?php

declare(strict_types=1);

use PhpHono\Http\StreamingBody;

require dirname(__DIR__) . '/vendor/autoload.php';
$baseline = memory_get_usage(true);
$stream = new StreamingBody(static function (): Generator {
    $chunk = str_repeat('x', times: 65_536);
    for ($index = 0; $index < 16_384; ++$index) {
        yield $chunk;
    }
});
$bytes = 0;
while (!$stream->eof()) {
    $bytes += strlen($stream->read(8192));
}
$stream->close();
$growth = memory_get_peak_usage(true) - $baseline;
if ($bytes !== 1_073_741_824 || $growth > 8_388_608) {
    throw new RuntimeException('Streaming memory bound exceeded');
}
echo json_encode(['php' => PHP_VERSION, 'bytes' => $bytes, 'peak_growth_bytes' => $growth], JSON_THROW_ON_ERROR), "\n";
