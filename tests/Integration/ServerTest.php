<?php

declare(strict_types=1);

namespace Tests\Integration;

use Naf\Websocket\Protocol\Frame;
use Naf\Websocket\Protocol\Handshake;
use Naf\Websocket\Publisher;
use Naf\Websocket\Server\Server;
use Naf\Websocket\Ticket;
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

    public function testATicketedClientIsToldWhatItIsListeningTo(): void
    {
        $client = $this->connect(Ticket::issue(self::KEY, '7', ['project:4']));

        $this->assertStringContainsString('101 Switching Protocols', $this->head($client));

        $ready = $this->message($client);
        $this->assertSame('ready', $ready['type']);
        $this->assertSame(['project:4'], $ready['channels']);
    }

    public function testAPublishedMessageReachesTheChannelAndNobodyElse(): void
    {
        $listening = $this->connect(Ticket::issue(self::KEY, '7', ['project:4']));
        $elsewhere = $this->connect(Ticket::issue(self::KEY, '8', ['project:9']));
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

    public function testAConnectionWithoutATicketIsRefused(): void
    {
        $client = $this->connect('');

        $this->assertStringContainsString('401', $this->head($client));
    }

    public function testATicketForAnotherKeyIsRefused(): void
    {
        $client = $this->connect(Ticket::issue('ein anderer Schlüssel', '7', ['project:4']));

        $this->assertStringContainsString('401', $this->head($client));
    }

    public function testAnExpiredTicketIsRefused(): void
    {
        $client = $this->connect(Ticket::issue(self::KEY, '7', ['project:4'], -1));

        $this->assertStringContainsString('401', $this->head($client));
    }

    /** The channels are in the ticket, so a client cannot ask for another one. */
    public function testAMessageForAChannelTheTicketDoesNotNameNeverArrives(): void
    {
        $client = $this->connect(Ticket::issue(self::KEY, '7', ['project:4']));
        $this->head($client);
        $this->message($client);

        (new Publisher($this->control))->publish('project:5', ['revision' => '1']);
        $this->spin(6);

        $this->assertNull($this->message($client, false));
    }

    public function testAClientThatSaysSomethingIsIgnoredRatherThanObeyed(): void
    {
        $client = $this->connect(Ticket::issue(self::KEY, '7', ['project:4']));
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
        $client = $this->connect(Ticket::issue(self::KEY, '7', ['project:4']));
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

    private function connect(string $ticket): Peer
    {
        $stream = stream_socket_client('tcp://' . $this->address, $code, $error, 2);
        $this->assertNotFalse($stream, "keine Verbindung: $error");
        stream_set_blocking($stream, false);

        $peer            = new Peer($stream);
        $this->clients[] = $peer;

        fwrite($stream, "GET /?ticket=" . rawurlencode($ticket) . " HTTP/1.1\r\n"
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
