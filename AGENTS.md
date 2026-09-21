# Working on naf/websocket

NAF is a small PHP framework with optional Composer plugins. Its core owns boot,
configuration, the service container, routing, events and PSR-7 responses. Prefer existing
NAF helpers, services and extension interfaces; keep application business rules in the host.
This package declares `type: naf-plugin` and is discovered after installation in a NAF host.
The plugin repository itself is not the application's web root.

Before changing code, read the [shared contribution workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md)
and [release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md).
In the multi-repository workspace, the same documents are in the sibling `docs/` checkout;
use the linked copies when working from a standalone clone. Preserve other contributors' work.
Review and update user documentation with every behavior change. Source fixes use an RC branch;
verified documentation-only changes can be merged and published by the agent.

## What this plugin does

A WebSocket server in plain PHP — `stream_socket_server` and `stream_select`, no extension
beyond the core and no dependency. It runs as a supervised program beside a queue worker and
keeps no state, so a restart costs its clients a reconnect and nothing else.

## The property everything else rests on

**It carries that something changed, never what.** A message names a channel and a revision;
whoever receives it fetches the new state through the host's ordinary, already-authorised HTTP
path.

That is not a style preference. It is why the server holds no authority, why a bug in here
cannot become a disclosure, and why a host can switch it off and lose live updates and nothing
else. A change that starts putting record contents into a published message has taken that
away, and no amount of care elsewhere gives it back.

## Authorisation lives outside

The server has no session and no database, so it cannot ask whether somebody may listen to a
channel. The host answers that while it still has a request and signs the answer:

```php
use function Naf\Websocket\token;

$token = token((string) $userId, ['project:4']);
```

A connection may join what its token names and nothing else. Tokens are short-lived on
purpose: a reconnect needs a new one, the client asks the host over HTTP where a session
exists, and somebody who has been signed out or removed is simply not given another.

`Token::issue()` is a pure function of subject, channels and the current second — two calls in
the same second return the same string, which is a property to rely on rather than a bug to
fix.

## Presence is the one deliberate exception

A channel named `presence:…` is the only one whose name the server reads. Everywhere else a
channel is an opaque string the host chose, and that opacity is worth keeping.

The exception buys a roster that needs no heartbeat, no endpoint to poll and nothing stored.
It counts people, not connections: `Hub::holds()` is what makes a second tab not a second
arrival, and `Hub::subjects()` returns strings because a subject is a string — it once
returned integers, and a page comparing them to its own found nobody.

`Server::relay()` is the only place a client's words are acted on. Everything it accepts is
bounded: the subject comes from the token and never the message, it reaches only presence
channels the connection already holds, the payload is capped, and nothing is stored. Do not
widen any of those without a test that says why.

## Change it here

[`Server`](src/Server/Server.php) is the loop and the protocol decisions;
[`Hub`](src/Server/Hub.php) is the bookkeeping; [`Token`](src/Token.php) is the trust;
[`Publisher`](src/Publisher.php) is how a host says something happened. The browser end is
[`websocket.js`](src/Resources/public/assets/websocket.js) and
[`token.js`](src/Resources/public/assets/token.js), published like any other package's assets.

The handshake and frame reader are written against what a hostile client sends rather than
what a friendly one does — a head that never ends, a frame announcing a gigabyte, a masked
frame from a server. Each has a test and a close code. Relaxing one of those needs a reason
better than a client that is easier to write.

A page speaks to this through a document event, never an import:

```js
document.dispatchEvent(new CustomEvent('naf:websocket-say', { detail: { at: 'board' } }));
```

Keep it that way. A package served under `/plugins/` should not be a path any host has to know.

## Verify

```sh
composer test          # 34 tests, the protocol and the server loop
composer style:check   # composer style:fix applies it
composer validate --strict
node --test tests/js/*.test.js
```

The JavaScript tests are not optional politeness: which answer to a token request means *retry*
and which means *give up* is the kind of rule that is easy to get subtly wrong and impossible
to notice, because the only symptom is a tab that quietly stopped updating.

CI runs all of this on every push.

User docs: [Live updates](https://nafphp.github.io/docs/websocket/).

Follow the shared [PHP code style](https://github.com/nafphp/docs/blob/main/CODE_STYLE.md)
and `.php-cs-fixer.dist.php`. Keep logical steps and local names readable, preserving public
signatures and template output.
