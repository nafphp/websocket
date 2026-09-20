<?php

declare(strict_types=1);

namespace Naf\Websocket\Server;

use Naf\Websocket\Protocol\Frame;
use Naf\Websocket\Protocol\Handshake;
use Naf\Websocket\Ticket;

/**
 * One client, and everything that is true of it between two turns of the loop.
 *
 * Nothing here blocks. A socket takes what it takes and says how much; the rest
 * waits in `$out` until it is writable again. That is the part hand-written
 * servers get wrong: a slow client that is written to with a plain fwrite either
 * blocks everybody else or loses the tail of its message.
 */
final class Connection
{
    public const int HANDSHAKING = 0;
    public const int SECURING    = 1;
    public const int OPEN        = 2;
    public const int CLOSING     = 3;

    public int $state      = self::SECURING;
    public ?Ticket $ticket = null;
    public float $seen;
    private string $in  = '';
    private string $out = '';

    /** @param resource $stream */
    public function __construct(
        public readonly mixed $stream,
        public readonly string $id,
        private readonly bool $secure,
    ) {
        $this->seen  = microtime(true);
        $this->state = $secure ? self::SECURING : self::HANDSHAKING;
    }

    /**
     * Carry the TLS handshake one step further.
     *
     * It is done in pieces because a blocking one would hold the whole loop for
     * as long as a client takes -- and a client that never finishes would hold
     * it forever.
     */
    public function secure(): bool
    {
        $result = @stream_socket_enable_crypto(
            $this->stream,
            true,
            STREAM_CRYPTO_METHOD_TLS_SERVER,
        );
        if ($result === true) {
            $this->state = self::HANDSHAKING;

            return true;
        }

        // 0 means "needs more bytes"; false means it will never work.
        return $result === 0;
    }

    /** @return string|false what arrived, or false when the client went away */
    public function receive(int $bytes = 8192): string|false
    {
        $chunk = @fread($this->stream, $bytes);
        if ($chunk === false || ($chunk === '' && feof($this->stream))) {
            return false;
        }
        $this->in .= $chunk;
        $this->seen = microtime(true);

        return $chunk;
    }

    /** The upgrade request once it has fully arrived. */
    public function handshake(?string &$refusal = null): ?Handshake
    {
        // A head that never ends is a client filling memory, not a browser.
        if (strlen($this->in) > 8192) {
            $refusal = '431 Request Header Fields Too Large';

            return null;
        }

        $request = Handshake::read($this->in, $refusal);
        if ($request !== null) {
            $this->in = '';
        }

        return $request;
    }

    /**
     * The frames that have fully arrived, leaving any partial one for later.
     *
     * @return list<Frame>
     */
    public function frames(?int &$error = null): array
    {
        $frames = [];
        while (true) {
            $frame = Frame::decode($this->in, $consumed, $error);
            if ($error !== null) {
                return $frames;
            }
            if ($frame === null) {
                return $frames;
            }
            $this->in = substr($this->in, $consumed);
            $frames[] = $frame;
        }
    }

    /**
     * Complete lines from the buffer, leaving a partial one for the next read.
     *
     * The control socket speaks one JSON object per line: a line that has not
     * finished arriving is not a broken message, it is a message still on its
     * way.
     *
     * @return list<string>
     */
    public function lines(int $limit = 65536): array
    {
        if (strlen($this->in) > $limit) {
            $this->in = '';

            return [];
        }

        $lines = [];
        while (($at = strpos($this->in, "\n")) !== false) {
            $line     = trim(substr($this->in, 0, $at));
            $this->in = substr($this->in, $at + 1);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function send(Frame $frame): void
    {
        $this->out .= $frame->encode();
    }

    public function write(string $raw): void
    {
        $this->out .= $raw;
    }

    public function pending(): bool
    {
        return $this->out !== '';
    }

    /**
     * Push out what fits, keep the rest.
     *
     * @return bool false when the socket is gone
     */
    public function flush(): bool
    {
        if ($this->out === '') {
            return true;
        }

        $written = @fwrite($this->stream, $this->out);
        if ($written === false) {
            return false;
        }
        $this->out = substr($this->out, $written);

        return true;
    }

    /** More than this waiting means the client is not keeping up; drop it. */
    public function overloaded(int $limit = 1048576): bool
    {
        return strlen($this->out) > $limit;
    }

    public function closeWith(int $code, string $reason = ''): void
    {
        if ($this->state === self::CLOSING) {
            return;
        }
        $this->send(Frame::close($code, $reason));
        $this->state = self::CLOSING;
    }

    public function refuse(string $status): void
    {
        $this->write(Handshake::refuse($status));
        $this->state = self::CLOSING;
    }

    public function shutdown(): void
    {
        @fclose($this->stream);
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function accept(Handshake $request, Ticket $ticket): void
    {
        $this->ticket = $ticket;
        $this->write($request->response());
        $this->state = self::OPEN;
    }

    public function say(array $payload): void
    {
        $this->send(Frame::text(json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    public function stale(float $after): bool
    {
        return microtime(true) - $this->seen > $after;
    }
}
