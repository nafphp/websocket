<?php

declare(strict_types=1);

namespace Naf\Websocket\Protocol;

/**
 * The close codes this server uses, from RFC 6455 section 7.4.1.
 *
 * Named rather than written as numbers at the call site, because a close code
 * is the only explanation a browser gets for why its connection ended.
 */
final class Close
{
    public const int NORMAL         = 1000;
    public const int GOING_AWAY     = 1001;
    public const int PROTOCOL_ERROR = 1002;
    public const int UNSUPPORTED    = 1003;
    public const int POLICY         = 1008;
    public const int TOO_BIG        = 1009;
}
