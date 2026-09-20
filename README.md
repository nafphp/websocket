# naf/websocket

A WebSocket server for NAF hosts, written in plain PHP: no extensions beyond the
core, no dependencies.

It carries **that** something changed, never **what**. A message says which
channel and which revision; whoever receives it fetches the new state through
the application's ordinary, already-authorised HTTP path. So the server holds no
authority: there is nothing in it to leak, and an installation that turns it off
loses live updates and nothing else.

## Why it has its own port

Behind a reverse proxy, TLS and origin checks are somebody else's job. On its own
port they are nobody's unless they are here, so the server does both. An
installation that would rather proxy it still can.

## Authorisation

The server has no session and no database. It cannot ask whether somebody may
listen to a channel, so it does not: the application answers that while it still
has a request and writes the answer into a short-lived signed token. A
connection may join what its token names, and nothing else.

Short-lived means a reconnect needs a new one, so the client asks the host for it
-- over HTTP, where a session still exists. The host decides what that costs it:
somebody who has been signed out or removed from a board is simply not given
another token, and the client stops asking. `Naf\Websocket\token()` issues them;
where to ask is in the page, because only the host knows its own routes.

## Running it

    php vendor/bin/naf websocket:serve

Meant to be a supervised program beside a queue worker. It keeps no state, so a
restart costs its clients a reconnect.

## Publishing

    publisher()->publish('project:4', ['revision' => '187']);

One line of JSON into a Unix socket. Not a port, so it needs no secret; not a
queue, so there is nothing to drain. It never throws and never waits: a request
must not fail because a socket server is restarting.
