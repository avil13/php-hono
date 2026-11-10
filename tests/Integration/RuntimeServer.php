<?php

declare(strict_types=1);

namespace PhpHono\Tests\Integration;

use RuntimeException;

final class RuntimeServer
{
    public readonly string $directory;
    public readonly string $address;
    /** @var resource */
    private mixed $process;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/php-hono-http-' . bin2hex(random_bytes(8));
        mkdir($this->directory, permissions: 0o700);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        if ($listener === false) {
            throw new RuntimeException('Cannot reserve HTTP test port.');
        }
        $address = stream_socket_get_name($listener, remote: false);
        fclose($listener);
        if ($address === false) {
            throw new RuntimeException('Cannot determine HTTP test port.');
        }
        $this->address = $address;
        $environment = getenv();
        $environment['PHP_HONO_TEST_DIRECTORY'] = $this->directory;
        $log = fopen($this->directory . '/server.log', mode: 'ab');
        if ($log === false) {
            throw new RuntimeException('Cannot open HTTP test server log.');
        }
        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'output_buffering=0',
                '-d',
                'display_errors=0',
                '-S',
                $address,
                dirname(__DIR__) . '/Fixtures/runtime.php',
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => $log,
                2 => $log,
            ],
            $pipes,
            dirname(__DIR__, levels: 2),
            $environment,
        );
        fclose($log);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start HTTP test server.');
        }
        $this->process = $process;
        $deadline = microtime(true) + 5;
        do {
            set_error_handler(static fn(): bool => true);
            try {
                $socket = stream_socket_client('tcp://' . $address, timeout: 0.1);
            } finally {
                restore_error_handler();
            }
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->close();
        throw new RuntimeException('HTTP test server did not start.');
    }

    /** @return resource */
    public function connect(string $path, string $method = 'GET'): mixed
    {
        $socket = stream_socket_client('tcp://' . $this->address, timeout: 5);
        if ($socket === false) {
            throw new RuntimeException('Cannot connect to HTTP test server.');
        }
        stream_set_timeout($socket, seconds: 5);
        fwrite($socket, $method . ' ' . $path . " HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        return $socket;
    }

    public function request(string $path, string $method = 'GET'): string
    {
        $socket = $this->connect($path, $method);
        try {
            $response = stream_get_contents($socket);
            if ($response === false) {
                throw new RuntimeException('Cannot read HTTP response.');
            }
            return $response;
        } finally {
            fclose($socket);
        }
    }

    public function close(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        $files = glob($this->directory . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
}
