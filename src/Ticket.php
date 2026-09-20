<?php

declare(strict_types=1);

namespace Naf\Websocket;

/**
 * What a browser shows at the door.
 *
 * The server has no session, no database and no idea what a project is. It
 * cannot ask whether somebody may listen to a channel, so it does not: the
 * application answers that question while it still has a request, and writes
 * the answer into a ticket that the server only has to verify.
 *
 * So the channels are *in* the ticket. A connection can join what its ticket
 * names and nothing else, and forging one means forging an HMAC. That is the
 * whole authorisation model, and it fits in a sentence -- which is the point,
 * because an authorisation model nobody can hold in their head is one nobody
 * can check.
 *
 * Tickets are short-lived. They ride in a query string, which is the one place
 * a browser lets you put anything on an upgrade request, and query strings end
 * up in logs and in `Referer` headers. A minute is long enough to connect and
 * short enough that a leaked one is worth nothing.
 */
final readonly class Ticket
{
    /** @param list<string> $channels */
    public function __construct(
        public string $subject,
        public array $channels,
        public int $expires,
    ) {
    }

    /** @param list<string> $channels */
    public static function issue(
        string $key,
        string $subject,
        array $channels,
        int $lifetime = 60,
    ): string {
        $payload = self::encode(json_encode([
            's' => $subject,
            'c' => array_values($channels),
            'e' => time() + $lifetime,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . self::encode(hash_hmac('sha256', $payload, $key, true));
    }

    /**
     * The ticket a token stands for, or null when it stands for nothing.
     *
     * Every reason to say no -- a wrong shape, a bad signature, an expired one
     * -- returns the same null. The client is told "1008 policy" and not which
     * of those it was, because the difference is only useful to somebody
     * guessing.
     */
    public static function verify(string $key, string $token, ?int $now = null): ?self
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;
        $expected              = hash_hmac('sha256', $payload, $key, true);

        // Constant time, so the comparison does not leak how much of a forged
        // signature was right.
        if (!hash_equals($expected, self::decode($signature))) {
            return null;
        }

        $claims = json_decode(self::decode($payload), true);
        if (
            !is_array($claims)
            || !is_string($claims['s'] ?? null)
            || !is_array($claims['c'] ?? null)
            || !is_int($claims['e'] ?? null)
        ) {
            return null;
        }
        if ($claims['e'] < ($now ?? time())) {
            return null;
        }

        $channels = array_values(array_filter($claims['c'], is_string(...)));

        return new self($claims['s'], $channels, $claims['e']);
    }

    public function mayJoin(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    /** base64url: a ticket travels in a query string, where +, / and = do not. */
    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): string
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true) ?: '';
    }
}
