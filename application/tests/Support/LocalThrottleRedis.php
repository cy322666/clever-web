<?php

namespace Tests\Support;

use RuntimeException;

/** Minimal test-only RESP connection. Unix sockets only, never production TCP/Redis ENV. */
class LocalThrottleRedis
{
    private mixed $socket;

    public function __construct(string $socketPath)
    {
        if (! str_starts_with($socketPath, '/')) {
            throw new RuntimeException('An explicit local Redis Unix socket is required.');
        }
        $this->socket = stream_socket_client('unix://'.$socketPath, $errno, $error, 1);
        if ($this->socket === false) {
            throw new RuntimeException('Local test Redis unavailable.');
        }
        stream_set_timeout($this->socket, 2);
    }

    public function command(string $command, mixed ...$arguments): mixed
    {
        $arguments = array_map('strval', [$command, ...$arguments]);
        $packet = '*'.count($arguments)."\r\n";
        foreach ($arguments as $argument) {
            $packet .= '$'.strlen($argument)."\r\n".$argument."\r\n";
        }
        while ($packet !== '') {
            $sent = fwrite($this->socket, $packet);
            if (! $sent) {
                throw new RuntimeException('Local Redis write failed.');
            }
            $packet = substr($packet, $sent);
        }

        return $this->read();
    }

    private function read(): mixed
    {
        $line = fgets($this->socket);
        if ($line === false) {
            throw new RuntimeException('Local Redis read failed.');
        }
        $value = substr($line, 1, -2);

        return match ($line[0]) {
            '+' => $value,
            ':' => (int) $value,
            '-' => throw new RuntimeException('Local Redis error: '.$value),
            '$' => $this->bulk((int) $value),
            '*' => $this->items((int) $value),
            default => throw new RuntimeException('Invalid local Redis response.'),
        };
    }

    private function bulk(int $length): ?string
    {
        if ($length < 0) {
            return null;
        }
        $value = '';
        while (strlen($value) < $length + 2) {
            $part = fread($this->socket, $length + 2 - strlen($value));
            if ($part === false || $part === '') {
                throw new RuntimeException('Truncated Redis response.');
            }
            $value .= $part;
        }

        return substr($value, 0, -2);
    }

    private function items(int $count): ?array
    {
        if ($count < 0) {
            return null;
        }
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = $this->read();
        }

        return $result;
    }
}
