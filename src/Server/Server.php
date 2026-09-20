<?php

declare(strict_types=1);

namespace Naf\Websocket\Server;

use Naf\Websocket\Protocol\Close;
use Naf\Websocket\Protocol\Frame;
use Naf\Websocket\Protocol\Handshake;
use Naf\Websocket\Token;
use RuntimeException;

/**
 * The server: one loop, no threads, nothing that blocks.
 *
 * It listens on a port of its own rather than behind a reverse proxy, so it
 * terminates TLS itself and checks the origin itself -- with a proxy those are
 * somebody else's job, and without one they are nobody's unless they are here.
 *
 * The application talks to it through a Unix socket. That needs no port, no
 * shared secret and cannot be reached from outside the machine, which is a
 * better answer than any secret would have been.
 *
 * Nothing that arrives is trusted and nothing is buffered without a limit: a
 * head that never ends, a frame that announces a gigabyte, a client that stops
 * reading -- each is a close with a code that says which.
 */
final class Server
{
    /** How long between pings, and how long silence may last before a drop. */
    /**
     * The one prefix this server reads a meaning into.
     *
     * Everywhere else a channel is an opaque string and the server is proud of
     * it. Here it has to know, because presence is the one thing the
     * application cannot tell it: who is connected is a fact only the server
     * holds.
     */
    public const string PRESENCE = 'presence:';

    /** As much as a client may say at once, and as long as a location may be. */
    private const int SPOKEN   = 512;
    private const int LOCATION = 64;

    private const float HEARTBEAT = 25.0;
    private const float SILENCE   = 70.0;

    private Hub $hub;
    private mixed $listener = null;
    private mixed $control  = null;

    /** @var array<string, Connection> */
    private array $publishers = [];
    private int $sequence     = 0;
    private bool $running     = false;

    /**
     * @param list<string> $origins exactly the origins a browser may connect from
     */
    public function __construct(
        private readonly string $address,
        private readonly string $controlPath,
        private readonly string $key,
        private readonly array $origins,
        private readonly ?array $tls = null,
        private $log = null,
        private readonly int $limit = 2000,
    ) {
        $this->hub = new Hub();
        $this->log ??= static fn(string $line) => fwrite(STDOUT, $line . "\n");
    }

    public function listen(): void
    {
        $context = stream_context_create($this->tls === null ? [] : ['ssl' => $this->tls]);

        $this->listener = @stream_socket_server(
            'tcp://' . $this->address,
            $code,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        if ($this->listener === false) {
            throw new RuntimeException("Port belegt oder nicht erlaubt: {$this->address} ($error)");
        }
        stream_set_blocking($this->listener, false);

        @unlink($this->controlPath);
        $this->control = @stream_socket_server('unix://' . $this->controlPath, $code, $error);
        if ($this->control === false) {
            throw new RuntimeException("Steuersocket nicht möglich: {$this->controlPath} ($error)");
        }
        stream_set_blocking($this->control, false);
        @chmod($this->controlPath, 0660);

        ($this->log)(sprintf(
            'Bereit auf %s%s, Steuersocket %s',
            $this->address,
            $this->tls === null ? '' : ' (TLS)',
            $this->controlPath,
        ));
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /** @param float|null $seconds run for this long; null runs until stopped */
    public function run(?float $seconds = null): void
    {
        $this->running = true;
        $until         = $seconds === null ? null : microtime(true) + $seconds;
        $beat          = microtime(true);

        while ($this->running && ($until === null || microtime(true) < $until)) {
            $this->turn(0.5);

            if (microtime(true) - $beat >= self::HEARTBEAT) {
                $beat = microtime(true);
                $this->heartbeat();
            }
        }

        foreach ($this->hub->all() as $connection) {
            $connection->shutdown();
        }
        if (is_resource($this->control)) {
            fclose($this->control);
        }
        @unlink($this->controlPath);
    }

    /** One pass: whatever is readable, then whatever is writable. */
    public function turn(float $timeout): void
    {
        $read   = ['l' => $this->listener, 'c' => $this->control];
        $write  = [];
        $except = null;

        foreach ($this->hub->all() as $connection) {
            $read['w' . $connection->id] = $connection->stream;
            if ($connection->pending()) {
                $write['w' . $connection->id] = $connection->stream;
            }
        }
        foreach ($this->publishers as $id => $publisher) {
            $read['p' . $id] = $publisher->stream;
        }

        $seconds      = (int) $timeout;
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        if (@stream_select($read, $write, $except, $seconds, $microseconds) === false) {
            return;
        }

        foreach ($read as $handle) {
            if ($handle === $this->listener) {
                $this->admit();
            } elseif ($handle === $this->control) {
                $this->admitPublisher();
            }
        }
        foreach ($this->hub->all() as $connection) {
            if (in_array($connection->stream, $read, true)) {
                $this->serve($connection);
            }
        }
        foreach ($this->publishers as $publisher) {
            if (in_array($publisher->stream, $read, true)) {
                $this->deliver($publisher);
            }
        }
        foreach ($this->hub->all() as $connection) {
            if (!$connection->pending()) {
                if ($connection->state === Connection::CLOSING) {
                    $this->part($connection);
                }

                continue;
            }
            if (!$connection->flush() || $connection->overloaded()) {
                $this->part($connection);
            }
        }
    }

    private function admit(): void
    {
        $stream = @stream_socket_accept($this->listener, 0);
        if ($stream === false) {
            return;
        }

        /*
         * Refused rather than accepted and then dropped: a connection this
         * process cannot afford should learn that from the server, not from the
         * kernel running it out of descriptors.
         */
        if ($this->hub->count() >= $this->limit) {
            @fwrite($stream, Handshake::refuse('503 Service Unavailable'));
            @fclose($stream);

            return;
        }

        stream_set_blocking($stream, false);

        $connection = new Connection($stream, (string) ++$this->sequence, $this->tls !== null);
        $this->hub->add($connection);
    }

    private function admitPublisher(): void
    {
        $stream = @stream_socket_accept($this->control, 0);
        if ($stream === false) {
            return;
        }
        stream_set_blocking($stream, false);

        $id                    = (string) ++$this->sequence;
        $this->publishers[$id] = new Connection($stream, $id, false);
    }

    private function serve(Connection $connection): void
    {
        if ($connection->state === Connection::SECURING) {
            if (!$connection->secure()) {
                $this->part($connection);
            }

            return;
        }

        if ($connection->receive() === false) {
            $this->part($connection);

            return;
        }

        if ($connection->state === Connection::HANDSHAKING) {
            $this->upgrade($connection);

            return;
        }

        foreach ($connection->frames($error) as $frame) {
            $this->handle($connection, $frame);
        }
        if ($error !== null) {
            $connection->closeWith($error);
        }
    }

    private function upgrade(Connection $connection): void
    {
        $request = $connection->handshake($refusal);
        if ($refusal !== null) {
            $connection->refuse($refusal);

            return;
        }
        if ($request === null) {
            return;
        }

        // Without a proxy in front, this is the only thing standing between the
        // server and any page on the internet opening a socket to it.
        $origin = $request->headers['origin'] ?? '';
        if ($this->origins !== [] && !in_array($origin, $this->origins, true)) {
            $connection->refuse('403 Forbidden');

            return;
        }

        $token = Token::verify($this->key, $request->query('token') ?? '');
        if ($token === null) {
            $connection->refuse('401 Unauthorized');

            return;
        }

        $connection->accept($request, $token);
        foreach ($token->channels as $channel) {
            $this->hub->join($connection, $channel);
        }
        $connection->say(['type' => 'ready', 'channels' => $token->channels]);
        $this->announce($connection);
    }

    /**
     * Who is here, on the channels that are about that.
     *
     * A channel whose name begins `presence:` is one the server knows something
     * about, which is otherwise never true -- everywhere else a channel is an
     * opaque string the application chose. The exception buys a great deal: the
     * server already knows who is connected and to what, so presence needs no
     * heartbeat from the page, no endpoint to poll, and nothing stored anywhere.
     *
     * The newcomer is told who is already there; the others are told about the
     * newcomer. The subject comes from the token, so nobody can announce
     * themselves as somebody else.
     */
    private function announce(Connection $connection): void
    {
        $subject = $connection->token?->subject;
        if ($subject === null || $subject === '') {
            return;
        }

        foreach ($connection->token?->channels ?? [] as $channel) {
            if (!str_starts_with($channel, self::PRESENCE)) {
                continue;
            }

            // The roster first, to the newcomer alone: everybody else already
            // knows who is there.
            $connection->say([
                'channel' => $channel,
                'type'    => 'presence.here',
                'present' => $this->hub->subjects($channel),
            ]);

            // A second tab is not a second arrival.
            if ($this->hub->holds($channel, $subject, $connection)) {
                continue;
            }

            $this->hub->publish($channel, ['type' => 'presence.joined', 'subject' => $subject]);
        }
    }

    /** The mirror of announce(), for a connection that is going away. */
    private function farewell(Connection $connection): void
    {
        $subject = $connection->token?->subject;
        if ($subject === null || $subject === '') {
            return;
        }

        foreach ($connection->token?->channels ?? [] as $channel) {
            // The last tab closing is the person leaving; an earlier one is not.
            if (!str_starts_with($channel, self::PRESENCE) || $this->hub->holds($channel, $subject, $connection)) {
                continue;
            }

            $this->hub->publish($channel, ['type' => 'presence.left', 'subject' => $subject]);
        }
    }

    private function handle(Connection $connection, Frame $frame): void
    {
        match ($frame->opcode) {
            Frame::PING  => $connection->send(Frame::pong($frame->payload)),
            Frame::PONG  => null,
            Frame::CLOSE => $connection->closeWith(Close::NORMAL),
            Frame::TEXT  => $this->relay($connection, $frame->payload),
            default      => null,
        };
    }

    /**
     * The one thing a client may say, and the one place it may say it.
     *
     * Everywhere else in this server, nothing a client sends is acted on: what
     * it may hear was decided before it connected. Presence is the exception it
     * has to be -- where somebody is looking is a fact only their browser has,
     * and no amount of server-side knowledge produces it.
     *
     * What that buys a liar is nothing worth having. The subject is taken from
     * the token and never from the message, so nobody can speak as somebody
     * else. It reaches only presence channels this connection already holds a
     * token for -- people who share that board, and who can see each other
     * anyway. It is capped, and it is not stored: the next thing said replaces
     * it, and a closed socket ends it.
     */
    private function relay(Connection $connection, string $payload): void
    {
        $subject = $connection->token?->subject;
        if ($subject === null || $subject === '' || strlen($payload) > self::SPOKEN) {
            return;
        }

        $said = json_decode($payload, true);
        $at   = is_array($said) ? ($said['at'] ?? null) : null;
        if (!is_string($at) || mb_strlen($at) > self::LOCATION) {
            return;
        }

        foreach ($connection->token?->channels ?? [] as $channel) {
            if (str_starts_with($channel, self::PRESENCE)) {
                $this->hub->publish($channel, [
                    'type'    => 'presence.at',
                    'subject' => $subject,
                    'at'      => $at,
                ]);
            }
        }
    }

    /** One JSON object per line from the application, then the socket closes. */
    private function deliver(Connection $publisher): void
    {
        $chunk = $publisher->receive();
        if ($chunk === false) {
            unset($this->publishers[$publisher->id]);
            $publisher->shutdown();

            return;
        }

        foreach ($publisher->lines() as $line) {
            $message = json_decode($line, true);
            if (!is_array($message) || !is_string($message['channel'] ?? null)) {
                continue;
            }
            $this->hub->publish($message['channel'], $message['message'] ?? []);
        }
    }

    private function heartbeat(): void
    {
        foreach ($this->hub->all() as $connection) {
            if ($connection->stale(self::SILENCE)) {
                $this->part($connection);

                continue;
            }
            if ($connection->state === Connection::OPEN) {
                $connection->send(Frame::ping());
            }
        }
    }

    private function part(Connection $connection): void
    {
        // Said while it is still on the channel, so "is anybody else this
        // person" has the connection that is leaving to exclude.
        $this->farewell($connection);
        $this->hub->drop($connection);
        $connection->shutdown();
    }

    public function hub(): Hub
    {
        return $this->hub;
    }
}
