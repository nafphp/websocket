<?php

declare(strict_types=1);

/*
 * The package's own classes, without a vendor directory of its own.
 *
 * The protocol has no dependencies -- that is the point of writing it by hand --
 * so the tests need nothing but an autoloader and whichever PHPUnit is at hand.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Naf\\Websocket\\')) {
        return;
    }

    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('Naf\\Websocket\\'))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
