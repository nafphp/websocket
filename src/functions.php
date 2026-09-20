<?php

declare(strict_types=1);

namespace Naf\Websocket;

use function Naf\app;
use function Naf\config;

/**
 * How the application says that something changed.
 *
 * Named by function rather than reached through the container at every call
 * site, the same way the other packages here do it.
 */
function publisher(): Publisher
{
    return app()->container()->get(Publisher::class);
}

/**
 * A token for these channels, for the browser that is being served right now.
 *
 * Called while there is still a request, which is the only moment the answer to
 * "may this person hear this" is cheap to get. What comes back is a string to
 * put in a page; the server needs nothing else.
 *
 * @param list<string> $channels
 */
function token(string $subject, array $channels): string
{
    $key = (string) config('websocket:key', '');
    if ($key === '') {
        return '';
    }

    return Token::issue($key, $subject, $channels, (int) config('websocket:token_lifetime', 60));
}

/**
 * Whether an installation has this switched on at all.
 *
 * The flag arrives from the environment, where everything is a string -- and
 * the string "false" is true to anything that does not look. It is read the way
 * a person means it, and a server without a signing key counts as off whatever
 * the flag says, because it could not verify a single connection.
 */
function live(): bool
{
    $enabled = config('websocket:enabled', false);
    $on      = $enabled === true
        || (is_string($enabled) && in_array(strtolower($enabled), ['1', 'true', 'yes', 'on'], true));

    return $on && (string) config('websocket:key', '') !== '';
}
