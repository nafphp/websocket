<?php

declare(strict_types=1);

return ['websocket' => [
    /*
     * Where the server listens. Its own port rather than a path behind the web
     * server: an installation that wants it proxied can still do that, but one
     * that does not should not have to touch an nginx configuration to get live
     * updates.
     */
    'address' => '0.0.0.0:8091',

    /*
     * What a browser is told to connect to. It differs from the address above
     * whenever anything sits in between -- a published port, a proxy, a
     * hostname that is not the one the server binds.
     */
    'url' => '',

    /*
     * Exactly the origins a browser may connect from. Empty means every origin,
     * which is only ever right in development: without a proxy in front, this
     * list is the whole of what stops any page on the internet opening a socket
     * here.
     */
    'origins' => [],

    /*
     * The certificate the server presents. A page served over https cannot open
     * a plain ws:// socket -- browsers refuse it -- so this is not optional for
     * an installation that uses TLS, it is the same requirement as the site's.
     */
    'certificate' => '',
    'key_file'    => '',

    /*
     * Where the application hands messages in. A Unix socket, so the exchange
     * needs no port and no shared secret.
     */
    'control' => '/tmp/naf-websocket.sock',

    /*
     * What tickets are signed with. Both the web process and the server read it,
     * and it is the only thing between a forged ticket and a channel.
     */
    'key' => '',
]];
