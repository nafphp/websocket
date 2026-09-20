<?php

declare(strict_types=1);

namespace Naf\Websocket\Protocol;

/**
 * The HTTP request that turns a socket into a WebSocket.
 *
 * RFC 6455 section 4.2. The only interesting part is the accept value: the
 * client's key, a fixed string appended, SHA-1, base64. It proves the server
 * understood the upgrade rather than being an ordinary HTTP server that
 * answered 101 by accident.
 *
 * What comes back from `read()` is a request or a refusal; a refusal carries the
 * status to answer with, because a browser that is told 400 stops retrying while
 * one that is told nothing does not.
 */
final readonly class Handshake
{
    /** Fixed by the specification; it is not a secret and not configurable. */
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @param array<string, string> $headers lower-cased names */
    private function __construct(
        public string $path,
        public array $headers,
        public string $key,
    ) {
    }

    /**
     * Parse a complete request head, or explain why it is not one.
     *
     * Returns null while the head is still arriving -- the caller waits for the
     * blank line that ends it.
     */
    public static function read(string $head, ?string &$refusal = null): ?self
    {
        $refusal = null;
        if (!str_contains($head, "\r\n\r\n")) {
            return null;
        }

        $lines   = explode("\r\n", substr($head, 0, strpos($head, "\r\n\r\n")));
        $request = array_shift($lines) ?? '';
        if (!preg_match('#^GET (\S+) HTTP/1\.1$#', $request, $found)) {
            $refusal = '400 Bad Request';

            return null;
        }

        $headers = [];
        foreach ($lines as $line) {
            $at = strpos($line, ':');
            if ($at === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $at)))] = trim(substr($line, $at + 1));
        }

        if (
            strtolower($headers['upgrade'] ?? '') !== 'websocket'
            || !str_contains(strtolower($headers['connection'] ?? ''), 'upgrade')
        ) {
            $refusal = '400 Bad Request';

            return null;
        }
        // Version 13 is the only one this speaks. Saying so lets a client that
        // speaks an older draft fail quickly instead of hanging.
        if (($headers['sec-websocket-version'] ?? '') !== '13') {
            $refusal = '426 Upgrade Required';

            return null;
        }

        $key = $headers['sec-websocket-key'] ?? '';
        if (strlen(base64_decode($key, true) ?: '') !== 16) {
            $refusal = '400 Bad Request';

            return null;
        }

        return new self($found[1], $headers, $key);
    }

    public function response(): string
    {
        return "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . self::accept($this->key) . "\r\n\r\n";
    }

    public static function refuse(string $status): string
    {
        return "HTTP/1.1 $status\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
    }

    public static function accept(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    /** The query string of the upgrade request, which is where the token rides. */
    public function query(string $name): ?string
    {
        $at = strpos($this->path, '?');
        if ($at === false) {
            return null;
        }
        parse_str(substr($this->path, $at + 1), $values);

        return is_string($values[$name] ?? null) ? $values[$name] : null;
    }
}
