<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Websocket\Ticket;
use PHPUnit\Framework\TestCase;

/**
 * The whole authorisation model, which is small enough to test exhaustively.
 *
 * A connection may join what its ticket names and nothing else. Everything these
 * tests do is try to get a ticket to say more than it was issued for.
 */
final class TicketTest extends TestCase
{
    private const string KEY = 'a-key-that-is-not-in-a-repository';

    public function testAnIssuedTicketVerifiesAndCarriesItsChannels(): void
    {
        $ticket = Ticket::verify(self::KEY, Ticket::issue(self::KEY, '7', ['project:4', 'project:9']));

        $this->assertNotNull($ticket);
        $this->assertSame('7', $ticket->subject);
        $this->assertTrue($ticket->mayJoin('project:4'));
        $this->assertTrue($ticket->mayJoin('project:9'));
    }

    public function testItGrantsNothingBeyondWhatItNames(): void
    {
        $ticket = Ticket::verify(self::KEY, Ticket::issue(self::KEY, '7', ['project:4']));

        $this->assertFalse($ticket?->mayJoin('project:5'), 'a ticket opened a channel it does not name');
        $this->assertFalse($ticket?->mayJoin('project:*'));
        $this->assertFalse($ticket?->mayJoin(''));
    }

    public function testAnotherKeyDoesNotOpenIt(): void
    {
        $this->assertNull(Ticket::verify('ein anderer Schlüssel', Ticket::issue(self::KEY, '7', ['project:4'])));
    }

    public function testAChangedPayloadIsRefused(): void
    {
        $token                 = Ticket::issue(self::KEY, '7', ['project:4']);
        [$payload, $signature] = explode('.', $token);

        $forged = rtrim(strtr(base64_encode(
            json_encode(['s' => '7', 'c' => ['project:5'], 'e' => time() + 60]),
        ), '+/', '-_'), '=');

        $this->assertNotSame($payload, $forged);
        $this->assertNull(Ticket::verify(self::KEY, $forged . '.' . $signature));
    }

    public function testAnExpiredTicketIsRefused(): void
    {
        $token = Ticket::issue(self::KEY, '7', ['project:4'], 60);

        $this->assertNotNull(Ticket::verify(self::KEY, $token, time() + 59));
        $this->assertNull(Ticket::verify(self::KEY, $token, time() + 61));
    }

    /** Nothing shaped wrong may reach the JSON decoder as if it were a ticket. */
    public function testRubbishIsRefusedRatherThanParsed(): void
    {
        foreach (['', '.', 'a.b.c', 'keinpunkt', '.abc', 'abc.'] as $token) {
            $this->assertNull(Ticket::verify(self::KEY, $token), "angenommen: $token");
        }
    }

    public function testATicketTravelsThroughAQueryStringUnharmed(): void
    {
        $token = Ticket::issue(self::KEY, '7', ['project:4']);

        $this->assertSame($token, urldecode(urlencode($token)));
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $token, 'the token needs escaping to travel');
    }
}
