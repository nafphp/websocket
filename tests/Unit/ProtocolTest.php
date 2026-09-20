<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Websocket\Protocol\Close;
use Naf\Websocket\Protocol\Frame;
use Naf\Websocket\Protocol\Handshake;
use PHPUnit\Framework\TestCase;

/**
 * The protocol, which is the part of a hand-written server worth testing.
 *
 * Everything else here is a select loop; this is where a wrong byte means a
 * browser silently drops the connection and nobody can say why. The examples
 * come from RFC 6455 itself where it gives them.
 */
final class ProtocolTest extends TestCase
{
    /** The example key and accept value printed in section 1.3 of the RFC. */
    public function testTheAcceptValueIsTheOneTheSpecificationPrints(): void
    {
        $this->assertSame(
            's3pPLMBiTxaQ9kYGzzhZRbK+xOo=',
            Handshake::accept('dGhlIHNhbXBsZSBub25jZQ=='),
        );
    }

    public function testAnUpgradeRequestIsRead(): void
    {
        $request = Handshake::read($this->upgrade('/socket?token=abc'), $refusal);

        $this->assertNull($refusal);
        $this->assertNotNull($request);
        $this->assertSame('abc', $request->query('token'));
        $this->assertStringContainsString('101 Switching Protocols', $request->response());
    }

    public function testAHeadThatHasNotArrivedYetIsNotARefusal(): void
    {
        $this->assertNull(Handshake::read("GET /socket HTTP/1.1\r\nUpgrade: websocket", $refusal));
        $this->assertNull($refusal, 'a half-read request was treated as a broken one');
    }

    public function testAnOlderDraftIsToldToUpgrade(): void
    {
        Handshake::read(str_replace('13', '8', $this->upgrade('/socket')), $refusal);

        $this->assertSame('426 Upgrade Required', $refusal);
    }

    public function testAKeyThatIsNotSixteenBytesIsRefused(): void
    {
        Handshake::read(str_replace('dGhlIHNhbXBsZSBub25jZQ==', 'c2hvcnQ=', $this->upgrade('/s')), $refusal);

        $this->assertSame('400 Bad Request', $refusal);
    }

    public function testAMaskedTextFrameComesBackAsItsText(): void
    {
        $frame = Frame::decode($this->masked(Frame::TEXT, 'Hallo'), $consumed, $error);

        $this->assertNotNull($frame);
        $this->assertNull($error);
        $this->assertSame(Frame::TEXT, $frame->opcode);
        $this->assertSame('Hallo', $frame->payload);
        $this->assertSame(11, $consumed, 'the frame did not report its own length');
    }

    public function testWhatFollowsAFrameIsLeftForTheNextRead(): void
    {
        $two = $this->masked(Frame::TEXT, 'eins') . $this->masked(Frame::TEXT, 'zwei');

        $first = Frame::decode($two, $consumed);
        $this->assertSame('eins', $first?->payload);

        $second = Frame::decode(substr($two, $consumed));
        $this->assertSame('zwei', $second?->payload);
    }

    public function testAFrameStillArrivingIsNotAnError(): void
    {
        $whole = $this->masked(Frame::TEXT, 'unvollständig');

        $this->assertNull(Frame::decode(substr($whole, 0, 6), $consumed, $error));
        $this->assertNull($error);
        $this->assertSame(0, $consumed);
    }

    public function testAnUnmaskedClientFrameFailsTheConnection(): void
    {
        Frame::decode(Frame::text('roh')->encode(), $consumed, $error);

        $this->assertSame(Close::PROTOCOL_ERROR, $error);
    }

    public function testAFragmentIsRefusedRatherThanAssembled(): void
    {
        $fragment    = $this->masked(Frame::TEXT, 'teil');
        $fragment[0] = chr(Frame::TEXT); // FIN gelöscht

        Frame::decode($fragment, $consumed, $error);

        $this->assertSame(Close::UNSUPPORTED, $error);
    }

    /** An announced size is checked before anything is reserved for it. */
    public function testAnEnormousAnnouncedSizeIsRefusedBeforeItIsRead(): void
    {
        $header = chr(0x80 | Frame::TEXT) . chr(0x80 | 127) . pack('J', 2 ** 40) . 'MASK';

        Frame::decode($header, $consumed, $error);

        $this->assertSame(Close::TOO_BIG, $error);
    }

    public function testATwoByteLengthIsRead(): void
    {
        $payload = str_repeat('x', 200);

        $this->assertSame($payload, Frame::decode($this->masked(Frame::TEXT, $payload))?->payload);
    }

    public function testWhatTheServerSendsIsNotMasked(): void
    {
        $bytes = Frame::text('an dich')->encode();

        $this->assertSame(0x80 | Frame::TEXT, ord($bytes[0]));
        $this->assertSame(strlen('an dich'), ord($bytes[1]), 'the mask bit was set on a server frame');
    }

    public function testACloseFrameCarriesItsCode(): void
    {
        $bytes = Frame::close(Close::POLICY, 'kein Token')->encode();
        $frame = new Frame(Frame::CLOSE, substr($bytes, 2));

        $this->assertSame(Close::POLICY, unpack('n', substr($frame->payload, 0, 2))[1]);
    }

    private function upgrade(string $path): string
    {
        return "GET $path HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
    }

    /** A client frame, masked the way a browser masks one. */
    private function masked(int $opcode, string $payload): string
    {
        $mask   = 'M4sK';
        $size   = strlen($payload);
        $header = chr(0x80 | $opcode);

        if ($size < 126) {
            $header .= chr(0x80 | $size);
        } else {
            $header .= chr(0x80 | 126) . pack('n', $size);
        }

        $body = '';
        for ($i = 0; $i < $size; $i++) {
            $body .= $payload[$i] ^ $mask[$i % 4];
        }

        return $header . $mask . $body;
    }
}
