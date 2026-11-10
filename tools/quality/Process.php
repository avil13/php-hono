<?php

declare(strict_types=1);

namespace PhpHono\Tooling;

use RuntimeException;

final class Process
{
    /**
     * @param list<string> $command
     * @param list<int> $accepted
     */
    public static function run(string $root, array $command, string $input = '', array $accepted = [0]): string
    {
        $outputFile = Files::temporary('php-hono-quality-out-');
        $inputFile = null;
        $outputHandle = null;
        try {
            $inputFile = Files::temporary('php-hono-quality-in-');
            Files::write($inputFile, $input);
            $environment = getenv();
            foreach (array_keys($environment) as $key) {
                if (str_starts_with($key, 'MAGO_')) {
                    unset($environment[$key]);
                }
            }
            $outputHandle = fopen($outputFile, mode: 'wb');
            if ($outputHandle === false) {
                throw new RuntimeException('Cannot open command output file.');
            }
            $process = proc_open(
                $command,
                [
                    0 => ['file', $inputFile, 'r'],
                    1 => $outputHandle,
                    2 => $outputHandle,
                ],
                $pipes,
                $root,
                $environment,
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start ' . $command[0]);
            }
            $code = proc_close($process);
            $output = Files::read($outputFile);
            if (!in_array($code, $accepted, strict: true)) {
                throw new RuntimeException($command[0] . " failed:\n" . $output);
            }
            return $output;
        } finally {
            if (is_resource($outputHandle)) {
                fclose($outputHandle);
            }
            unlink($outputFile);
            if ($inputFile !== null) {
                unlink($inputFile);
            }
        }
    }
}
