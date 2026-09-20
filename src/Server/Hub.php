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

    /**
     * Who is on a channel, once each.
     *
     * By subject and not by connection: one person with the board open in three
     * tabs is one person, and a list that said otherwise would be a list of
     * browser windows rather than of colleagues.
     *
     * @return list<string>
     */
    public function subjects(string $channel): array
    {
        $present = [];
        foreach ($this->channels[$channel] ?? [] as $connection) {
            $subject = $connection->token?->subject;
            if ($subject !== null && $subject !== '') {
                $present[$subject] = true;
            }
        }

        // Back to strings: a subject like "7" becomes the integer 7 the moment it
        // is used as an array key, and a client comparing it with its own id
        // would then find nobody.
        return array_map(strval(...), array_keys($present));
    }

    /**
     * Whether anybody else on this channel is that person.
     *
     * Asked before announcing a departure: the last tab closing is the person
     * leaving, an earlier one closing is not.
     */
    public function holds(string $channel, string $subject, ?Connection $except = null): bool
    {
        foreach ($this->channels[$channel] ?? [] as $connection) {
            if ($connection !== $except && $connection->token?->subject === $subject) {
                return true;
            }
        }

        return false;
    }
}
