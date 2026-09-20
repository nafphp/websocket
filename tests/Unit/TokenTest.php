<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Websocket\Token;
use PHPUnit\Framework\TestCase;

/**
 * The whole authorisation model, which is small enough to test exhaustively.
 *
 * A connection may join what its token names and nothing else. Everything these
 * tests do is try to get a token to say more than it was issued for.
 */
final class TokenTest extends TestCase
{
    private const string KEY = 'a-key-that-is-not-in-a-repository';

    public function testAnIssuedTokenVerifiesAndCarriesItsChannels(): void
    {
        $token = Token::verify(self::KEY, Token::issue(self::KEY, '7', ['project:4', 'project:9']));

        $this->assertNotNull($token);
        $this->assertSame('7', $token->subject);
        $this->assertTrue($token->mayJoin('project:4'));
        $this->assertTrue($token->mayJoin('project:9'));
    }

    public function testItGrantsNothingBeyondWhatItNames(): void
    {
        $token = Token::verify(self::KEY, Token::issue(self::KEY, '7', ['project:4']));

        $this->assertFalse($token?->mayJoin('project:5'), 'a token opened a channel it does not name');
        $this->assertFalse($token?->mayJoin('project:*'));
        $this->assertFalse($token?->mayJoin(''));
    }

    public function testAnotherKeyDoesNotOpenIt(): void
    {
        $this->assertNull(Token::verify('ein anderer Schlüssel', Token::issue(self::KEY, '7', ['project:4'])));
    }

    public function testAChangedPayloadIsRefused(): void
    {
        $token                 = Token::issue(self::KEY, '7', ['project:4']);
        [$payload, $signature] = explode('.', $token);

        $forged = rtrim(strtr(base64_encode(
            json_encode(['s' => '7', 'c' => ['project:5'], 'e' => time() + 60]),
        ), '+/', '-_'), '=');

        $this->assertNotSame($payload, $forged);
        $this->assertNull(Token::verify(self::KEY, $forged . '.' . $signature));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $token = Token::issue(self::KEY, '7', ['project:4'], 60);

        $this->assertNotNull(Token::verify(self::KEY, $token, time() + 59));
        $this->assertNull(Token::verify(self::KEY, $token, time() + 61));
    }

    /** Nothing shaped wrong may reach the JSON decoder as if it were a token. */
    public function testRubbishIsRefusedRatherThanParsed(): void
    {
        foreach (['', '.', 'a.b.c', 'keinpunkt', '.abc', 'abc.'] as $token) {
            $this->assertNull(Token::verify(self::KEY, $token), "angenommen: $token");
        }
    }

    public function testATokenTravelsThroughAQueryStringUnharmed(): void
    {
        $token = Token::issue(self::KEY, '7', ['project:4']);

        $this->assertSame($token, urldecode(urlencode($token)));
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $token, 'the token needs escaping to travel');
    }
}
