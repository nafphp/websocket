# naf/websocket

A WebSocket server for NAF hosts, written in plain PHP: no extension beyond the core
and no dependency. It carries **that** something changed, never **what** — whoever
receives a message fetches the new state through the application's ordinary,
already-authorised path.

## Documentation

<https://nafphp.github.io/docs/websocket/>

## Install

```sh
composer require naf/websocket
php vendor/bin/naf websocket:serve
```

## License

MIT
