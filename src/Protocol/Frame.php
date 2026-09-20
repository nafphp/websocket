<?php

declare(strict_types=1);

namespace Naf\Websocket\Protocol;

/**
 * One WebSocket frame, read off a buffer or written to one.
 *
 * RFC 6455 section 5. This server talks to browsers about one thing -- that
 * something changed -- so it reads far less than the protocol allows and says
 * so rather than guessing: a fragmented frame, a frame a client did not mask,
 * anything larger than a short message, and the connection is closed with the
 * code that names the reason.
 *
 * Being strict is what makes this safe to hand-write. The frames that arrive
 * here are pongs, closes, and the occasional small text; nothing else has a
 * reason to exist on this socket.
 */
final readonly class Frame
{
    public const int CONTINUATION = 0x0;
    public const int TEXT         = 0x1;
    public const int BINARY       = 0x2;
    public const int CLOSE        = 0x8;
    public const int PING         = 0x9;
    public const int PONG         = 0xA;

    /** Anything a client sends beyond this is refused rather than buffered. */
    public const int LIMIT = 8192;

    public function __construct(
        public int $opcode,
        public string $payload,
        public bool $final = true,
    ) {
    }

    /**
     * The next frame in $buffer, or null while it is still arriving.
     *
     * `$consumed` reports how much of the buffer the frame took, so the caller
     * keeps whatever came after it. A null frame with a non-zero close code is a
     * protocol error: the caller closes with it.
     */
    public static function decode(string $buffer, ?int &$consumed = null, ?int &$error = null): ?self
    {
        $consumed = 0;
        $error    = null;
        $length   = strlen($buffer);
        if ($length < 2) {
            return null;
        }

        $first  = ord($buffer[0]);
        $second = ord($buffer[1]);
        $final  = (bool) ($first & 0x80);
        $opcode = $first & 0x0F;
        $masked = (bool) ($second & 0x80);
        $size   = $second & 0x7F;
        $offset = 2;

        // A client that does not mask is not a browser, and the specification
        // says to fail the connection rather than to read it anyway.
        if (!$masked) {
            $error = Close::PROTOCOL_ERROR;

            return null;
        }
        if (!$final || $opcode === self::CONTINUATION) {
            $error = Close::UNSUPPORTED;

            return null;
        }

        if ($size === 126) {
            if ($length < $offset + 2) {
                return null;
            }
            $size = unpack('n', substr($buffer, $offset, 2))[1];
            $offset += 2;
        } elseif ($size === 127) {
            if ($length < $offset + 8) {
                return null;
            }
            $size = unpack('J', substr($buffer, $offset, 8))[1];
            $offset += 8;
        }

        // Checked before anything is reserved for it: the point of a limit is
        // that an announced size cannot make this process allocate.
        if ($size < 0 || $size > self::LIMIT) {
            $error = Close::TOO_BIG;

            return null;
        }
        if ($length < $offset + 4 + $size) {
            return null;
        }

        $mask = substr($buffer, $offset, 4);
        $offset += 4;
        $body = substr($buffer, $offset, $size);
        $offset += $size;

        $payload = '';
        for ($i = 0; $i < $size; $i++) {
            $payload .= $body[$i] ^ $mask[$i % 4];
        }

        $consumed = $offset;

        return new self($opcode, $payload, $final);
    }

    /** The bytes to put on the wire. A server never masks what it sends. */
    public function encode(): string
    {
        $size   = strlen($this->payload);
        $header = chr(($this->final ? 0x80 : 0x00) | $this->opcode);

        if ($size < 126) {
            $header .= chr($size);
        } elseif ($size < 65536) {
            $header .= chr(126) . pack('n', $size);
        } else {
            $header .= chr(127) . pack('J', $size);
        }

        return $header . $this->payload;
    }

    public static function text(string $payload): self
    {
        return new self(self::TEXT, $payload);
    }

    public static function pong(string $payload = ''): self
    {
        return new self(self::PONG, $payload);
    }

    public static function ping(string $payload = ''): self
    {
        return new self(self::PING, $payload);
    }

    /** A close frame carries its reason as a two-byte code, then optional text. */
    public static function close(int $code = Close::NORMAL, string $reason = ''): self
    {
        return new self(self::CLOSE, pack('n', $code) . $reason);
    }
}
