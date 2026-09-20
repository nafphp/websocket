<?php

declare(strict_types=1);

namespace Tests\Integration;

use Naf\Websocket\Protocol\Frame;
use Naf\Websocket\Protocol\Handshake;
use Naf\Websocket\Publisher;
use Naf\Websocket\Server\Server;
use Naf\Websocket\Token;
use PHPUnit\Framework\TestCase;

/**
 * The server, driven the way a browser drives it.
 *
 * Real sockets, a real handshake, a real published message. The loop is stepped
 * by hand instead of being let run, so a failing test says which turn it failed
 * on rather than timing out.
 */
final class ServerTest extends TestCase
{
    private const string KEY = 'schlüssel-nur-für-den-test';

    private Server $server;
    private string $address;
    private string $control;
    /** @var list<Peer> */
    private array $clients = [];

    protected function setUp(): void
    {
        $port          = random_int(20000, 45000);
        $this->address = '127.0.0.1:' . $port;
        $this->control = sys_get_temp_dir() . '/naf-websocket-test-' . $port . '.sock';

        $this->server = new Server(
            $this->address,
            $this->control,
            self::KEY,
            [],
            null,
            static fn() => null,
        );
        $this->server->listen();
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->server->stop();
        @unlink($this->control);
    }

    public function testAnAuthorisedClientIsToldWhatItIsListeningTo(): void
    {
        $client = $this->connect(Token::issue(self::KEY, '7', ['project:4']));

        $this->assertStringContainsString('101 Switching Protocols', $this->head($client));

        $ready = $this->message($client);
        $this->assertSame('ready', $ready['type']);
        $this->assertSame(['project:4'], $ready['channels']);
    }

    public function testAPublishedMessageReachesTheChannelAndNobodyElse(): void
    {
        $listening = $this->connect(Token::issue(self::KEY, '7', ['project:4']));
        $elsewhere = $this->connect(Token::issue(self::KEY, '8', ['project:9']));
        $this->head($listening);
        $this->head($elsewhere);
        $this->message($listening);
        $this->message($elsewhere);

        $published = (new Publisher($this->control))->publish('project:4', ['revision' => '187']);
        $this->assertTrue($published, 'the application could not reach the server');
        $this->spin(6);

        $message = $this->message($listening);
        $this->assertSame('project:4', $message['channel']);
        $this->assertSame('187', $message['revision']);

        $this->assertNull($this->message($elsewhere, false), 'a channel leaked into another one');
    }

    public function testAConnectionWithoutATokenIsRefused(): void
    {
        $client = $this->connect('');

        $this->assertStringContainsString('401', $this->head($client));
    }

    public function testATokenForAnotherKeyIsRefused(): void
    {
        $client = $this->connect(Token::issue('ein anderer Schlüssel', '7', ['project:4']));

        $this->assertStringContainsString('401', $this->head($client));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $client = $this->connect(Token::issue(self::KEY, '7', ['project:4'], -1));

        $this->assertStringContainsString('401', $this->head($client));
    }

    /** The channels are in the token, so a client cannot ask for another one. */
    public function testAMessageForAChannelTheTokenDoesNotNameNeverArrives(): void
    {
        $client = $this->connect(Token::issue(self::KEY, '7', ['project:4']));
        $this->head($client);
        $this->message($client);

        (new Publisher($this->control))->publish('project:5', ['revision' => '1']);
        $this->spin(6);

        $this->assertNull($this->message($client, false));
    }

    public function testAClientThatSaysSomethingIsIgnoredRatherThanObeyed(): void
    {
        $client = $this->connect(Token::issue(self::KEY, '7', ['project:4']));
        $this->head($client);
        $this->message($client);

        $client->write($this->maskedText(json_encode(['subscribe' => 'project:5'])));
        $this->spin(4);

        (new Publisher($this->control))->publish('project:5', ['revision' => '2']);
        $this->spin(4);

        $this->assertNull($this->message($client, false), 'a client talked its way into a channel');
    }

    public function testAnOversizedFrameClosesTheConnection(): void
    {
        $client = $this->connect(Token::issue(self::KEY, '7', ['project:4']));
        $this->head($client);
        $this->message($client);

        // Announced, not sent: the limit has to be applied to the claim.
        $client->write(chr(0x80 | Frame::TEXT) . chr(0x80 | 127) . pack('J', 2 ** 30) . 'MASK');
        $this->spin(4);

        $frame = $this->frame($client);
        $this->assertSame(Frame::CLOSE, $frame?->opcode);
    }

    public function testAConnectionBeyondTheLimitIsRefusedRatherThanAccepted(): void
    {
        $port    = random_int(20000, 45000);
        $control = sys_get_temp_dir() . '/naf-websocket-limit-' . $port . '.sock';
        $server  = new Server('127.0.0.1:' . $port, $control, self::KEY, [], null, static fn() => null, 1);
        $server->listen();

        $first  = $this->dial('127.0.0.1:' . $port);
        $second = $this->dial('127.0.0.1:' . $port);
        for ($i = 0; $i < 6; $i++) {
            $server->turn(0.02);
            usleep(2000);
        }

        $this->assertStringContainsString('503', (string) fread($second, 512));

        fclose($first);
        fclose($second);
        $server->stop();
        @unlink($control);
    }

    /** @return resource */
    private function dial(string $address): mixed
    {
        $stream = stream_socket_client('tcp://' . $address, $code, $error, 2);
        $this->assertNotFalse($stream, "keine Verbindung: $error");
        stream_set_blocking($stream, false);
        fwrite($stream, "GET / HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");

        return $stream;
    }

    /**
     * Who is here, without anybody having to say so.
     *
     * The server already knows which connections it holds and to what, so
     * presence costs no heartbeat from the page and nothing stored anywhere.
     */
    public function testAPresenceChannelTellsEachArrivalWhoIsAlreadyThere(): void
    {
        $first = $this->connect(Token::issue(self::KEY, '7', ['presence:project:4']));
        $this->drain($first);

        $second = $this->connect(Token::issue(self::KEY, '8', ['presence:project:4']));

        $roster = $this->firstOfType($second, 'presence.here');
        $this->assertNotNull($roster, 'the newcomer was not told who was there');
        // Strings, not numbers: a client compares this with its own id.
        $this->assertSame(['7', '8'], $roster['present']);

        $joined = $this->firstOfType($first, 'presence.joined');
        $this->assertNotNull($joined, 'nobody was told about the newcomer');
        $this->assertSame('8', $joined['subject']);
    }

    /** A second tab is not a second person, and closing it is not leaving. */
    public function testASecondConnectionOfTheSamePersonIsNotASecondArrival(): void
    {
        $first = $this->connect(Token::issue(self::KEY, '7', ['presence:project:4']));
        $other = $this->connect(Token::issue(self::KEY, '8', ['presence:project:4']));
        $this->drain($first);
        $this->drain($other);

        $again = $this->connect(Token::issue(self::KEY, '7', ['presence:project:4']));
        $this->drain($again);

        $this->assertNull(
            $this->firstOfType($other, 'presence.joined'),
            'the same person arriving twice was announced twice',
        );
    }

    /**
     * Where somebody is looking is the one thing only their browser knows, so it
     * is the one thing a client may say. The subject comes from the token
     * regardless of what the message claims.
     */
    public function testAClientMaySayWhereItIsAndNotWhoItIs(): void
    {
        $watcher = $this->connect(Token::issue(self::KEY, '7', ['presence:project:4']));
        $other   = $this->connect(Token::issue(self::KEY, '8', ['presence:project:4']));
        $this->drain($watcher);
        $this->drain($other);

        $other->write($this->maskedText(json_encode(['at' => 'NAF-12', 'subject' => '1'], JSON_THROW_ON_ERROR)));
        $this->spin(6);

        $said = $this->firstOfType($watcher, 'presence.at');
        $this->assertNotNull($said, 'nothing was relayed');
        $this->assertSame('NAF-12', $said['at']);
        $this->assertSame('8', $said['subject'], 'a client announced itself as somebody else');
    }

    /** Anything longer than a location is not one. */
    public function testAnOversizedLocationIsIgnored(): void
    {
        $watcher = $this->connect(Token::issue(self::KEY, '7', ['presence:project:4']));
        $other   = $this->connect(Token::issue(self::KEY, '8', ['presence:project:4']));
        $this->drain($watcher);
        $this->drain($other);

        $other->write($this->maskedText(json_encode(['at' => str_repeat('x', 200)], JSON_THROW_ON_ERROR)));
        $this->spin(6);

        $this->assertNull($this->firstOfType($watcher, 'presence.at'));
    }

    /** Everything waiting for this peer, thrown away -- the handshake included. */
    private function drain(Peer $peer): void
    {
        $this->spin(4);
        $peer->head();
        while ($peer->frame() !== null) {
            // nichts; es geht nur darum, den Puffer zu leeren
        }
    }

    /** @return array<string,mixed>|null the first message of that type, if one came */
    private function firstOfType(Peer $peer, string $type): ?array
    {
        $this->spin(4);
        // The upgrade response sits in front of the frames until it is taken out.
        $peer->head();

        while (null !== $frame = $peer->frame()) {
            $message = json_decode($frame->payload, true);
            if (is_array($message) && ($message['type'] ?? null) === $type) {
                return $message;
            }
        }

        return null;
    }

    private function connect(string $token): Peer
    {
        $stream = stream_socket_client('tcp://' . $this->address, $code, $error, 2);
        $this->assertNotFalse($stream, "keine Verbindung: $error");
        stream_set_blocking($stream, false);

        $peer            = new Peer($stream);
        $this->clients[] = $peer;

        fwrite($stream, "GET /?token=" . rawurlencode($token) . " HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n");
        $this->spin(6);

        return $peer;
    }

    /** Turn the loop a few times, which is what the client is waiting for. */
    private function spin(int $turns): void
    {
        for ($i = 0; $i < $turns; $i++) {
            $this->server->turn(0.02);
            usleep(2000);
        }
    }

    private function head(Peer $client): string
    {
        $this->spin(4);

        return $client->head();
    }

    private function frame(Peer $client): ?Frame
    {
        $this->spin(2);

        return $client->frame();
    }

    /** @return array<string, mixed>|null */
    private function message(Peer $client, bool $expected = true): ?array
    {
        $frame = $this->frame($client);
        if ($frame === null) {
            $this->assertFalse($expected, 'nothing arrived');

            return null;
        }

        $decoded = json_decode($frame->payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function maskedText(string $payload): string
    {
        $mask = 'M4sK';
        $body = '';
        for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
            $body .= $payload[$i] ^ $mask[$i % 4];
        }

        return chr(0x80 | Frame::TEXT) . chr(0x80 | strlen($payload)) . $mask . $body;
    }
}

/**
 * A test client that keeps its own buffer.
 *
 * The server writes the upgrade response and the first frame in one go, so a
 * plain fread swallows both -- reading the head has to leave whatever followed
 * it for the frames.
 */
final class Peer
{
    private string $buffer = '';

    /** @param resource $stream */
    public function __construct(private mixed $stream)
    {
    }

    public function write(string $bytes): void
    {
        fwrite($this->stream, $bytes);
    }

    public function head(): string
    {
        $this->pull();
        $at = strpos($this->buffer, "\r\n\r\n");
        if ($at === false) {
            return '';
        }

        $head         = substr($this->buffer, 0, $at + 4);
        $this->buffer = substr($this->buffer, $at + 4);

        return $head;
    }

    public function frame(): ?Frame
    {
        $this->pull();
        if (strlen($this->buffer) < 2) {
            return null;
        }

        $opcode = ord($this->buffer[0]) & 0x0F;
        $size   = ord($this->buffer[1]) & 0x7F;
        $offset = 2;
        if ($size === 126) {
            $size   = unpack('n', substr($this->buffer, 2, 2))[1];
            $offset = 4;
        }
        if (strlen($this->buffer) < $offset + $size) {
            return null;
        }

        $payload      = substr($this->buffer, $offset, $size);
        $this->buffer = substr($this->buffer, $offset + $size);

        return new Frame($opcode, $payload);
    }

    public function close(): void
    {
        @fclose($this->stream);
    }

    private function pull(): void
    {
        $chunk = @fread($this->stream, 65536);
        if (is_string($chunk)) {
            $this->buffer .= $chunk;
        }
    }
}
