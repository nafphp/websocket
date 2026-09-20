<?php

declare(strict_types=1);

namespace Naf\Websocket\Server;

/**
 * Who is listening to what.
 *
 * A channel is a string the application chose and wrote into a token; this
 * knows nothing about what one means. Fan-out is the only thing that happens
 * here, and it happens into a write buffer rather than onto a socket, so one
 * slow client cannot hold up the others.
 */
final class Hub
{
    /** @var array<string, array<string, Connection>> channel => id => connection */
    private array $channels = [];

    /** @var array<string, Connection> */
    private array $connections = [];

    public function add(Connection $connection): void
    {
        $this->connections[$connection->id] = $connection;
    }

    public function join(Connection $connection, string $channel): void
    {
        $this->channels[$channel][$connection->id] = $connection;
    }

    public function drop(Connection $connection): void
    {
        unset($this->connections[$connection->id]);
        foreach ($this->channels as $name => $members) {
            unset($this->channels[$name][$connection->id]);
            if ($this->channels[$name] === []) {
                unset($this->channels[$name]);
            }
        }
    }

    /**
     * @param  array<string, mixed> $message
     * @return int                  how many connections it went to
     */
    public function publish(string $channel, array $message): int
    {
        $sent = 0;
        foreach ($this->channels[$channel] ?? [] as $connection) {
            if ($connection->state !== Connection::OPEN) {
                continue;
            }
            $connection->say(['channel' => $channel] + $message);
            $sent++;
        }

        return $sent;
    }

    /** @return list<Connection> */
    public function all(): array
    {
        return array_values($this->connections);
    }

    public function count(): int
    {
        return count($this->connections);
    }

    /** @return array<string, int> channel => listeners, for the status line */
    public function channels(): array
    {
        return array_map(count(...), $this->channels);
    }
}
