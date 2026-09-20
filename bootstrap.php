<?php

declare(strict_types=1);

use Naf\CLI\Support\CommandRegistry;
use Naf\Websocket\Commands\ServeCommand;
use Naf\Websocket\Publisher;

use function Naf\app;
use function Naf\config;

$container = app()->container();

$container->set(
    Publisher::class,
    static fn() => new Publisher((string) config('websocket:control', '/tmp/naf-websocket.sock')),
);

/*
 * Only when naf/cli is installed. A host that runs no CLI still gets the
 * publisher; it just has no way to start a server, which is a decision an
 * installation is allowed to make.
 */
if (class_exists(CommandRegistry::class) && $container->has(CommandRegistry::class)) {
    $container->get(CommandRegistry::class)->add(ServeCommand::class);
}
