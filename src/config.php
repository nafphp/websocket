<?php

declare(strict_types=1);

return ['websocket' => [
    /*
     * Whether any of this happens at all.
     *
     * Off, the command refuses to start and the application stops telling
     * browsers where to connect. Nothing else changes: live updates are an
     * addition to pages that work without them, so switching this off costs
     * immediacy and no function.
     */
    'enabled' => false,

    /*
     * Where the server binds. Its own port rather than a path behind the web
     * server: an installation that wants it proxied can still do that, but one
     * that does not should not have to touch an nginx configuration to get live
     * updates.
     */
    'address' => '0.0.0.0:8091',

    /*
     * What a browser is told to connect to.
     *
     * It differs from the address above whenever anything sits in between -- a
     * published container port, a proxy, a hostname that is not the one the
     * server binds to. Empty means the application works it out from its own
     * address, which is right for development and rarely right beyond it.
     */
    'url' => '',

    /*
     * Exactly the origins a browser may connect from.
     *
     * With nothing in front of the server, this list is the whole of what stops
     * any page on the internet opening a socket here. Empty allows every origin,
     * which is a development convenience and never more than that.
     */
    'origins' => [],

    /*
     * The certificate the server presents.
     *
     * A page served over https cannot open a plain ws:// socket -- browsers
     * refuse it -- so for a site on TLS this is not optional, it is the same
     * requirement as the site's own. The defaults are where this image keeps
     * the certificates it already serves the application with.
     */
    'certificate' => '/etc/nginx/ssl/fullchain.pem',
    'key_file'    => '/etc/nginx/ssl/privkey.pem',

    /*
     * Where the application hands messages in: a Unix socket, so the exchange
     * needs no port and no shared secret, and cannot be reached from another
     * machine.
     */
    'control' => '/tmp/naf-websocket.sock',

    /*
     * What tickets are signed with.
     *
     * The web process issues them and the server verifies them, so both read
     * this, and it is the only thing between a forged ticket and a channel.
     * There is deliberately no default: a key that ships with the package is a
     * key everybody has.
     */
    'key' => '',

    /*
     * How long a ticket is worth something.
     *
     * It rides in a query string, which is where browsers allow anything on an
     * upgrade request -- and query strings end up in logs. Long enough to
     * connect, short enough that a leaked one is worthless.
     */
    'ticket_lifetime' => 60,

    /*
     * How many connections the server will hold.
     *
     * A limit is not a guess about demand; it is the difference between a
     * process that refuses politely and one the kernel kills. Each connection
     * costs a file descriptor and its buffers.
     */
    'max_connections' => 2000,
]];
