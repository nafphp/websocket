<?php

declare(strict_types=1);

namespace Naf\Websocket;

/**
 * How the application tells the server that something changed.
 *
 * One line of JSON into a Unix socket, then done. Not a port, so it needs no
 * secret and cannot be reached from another machine; not a queue, so there is
 * nothing to drain or to lose.
 *
 * It never throws and never waits. A live update is a convenience on top of a
 * page that works without it -- a request must not fail, or even slow down,
 * because a socket server is restarting.
 */
final readonly class Publisher
{
    public function __construct(
        private string $path,
        private float $timeout = 0.05,
    ) {
    }

    /** @param array<string, mixed> $message */
    public function publish(string $channel, array $message): bool
    {
        if (!file_exists($this->path)) {
            return false;
        }

        $socket = @stream_socket_client(
            'unix://' . $this->path,
            $code,
            $error,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            return false;
        }

        stream_set_timeout($socket, 0, (int) ($this->timeout * 1_000_000));
        $line    = json_encode(['channel' => $channel, 'message' => $message]) . "\n";
        $written = @fwrite($socket, $line);
        @fclose($socket);

        return $written === strlen($line);
    }
}
