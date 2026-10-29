<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Service\Account\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * docs/spec/02-sicherheit.md section 3: at least 12 characters plus a check
 * against common passwords, no composition rules. Taken over from
 * Vereinsbelege.
 */
final class PasswordPolicyTest extends TestCase
{
    private function policy(): PasswordPolicy
    {
        return new PasswordPolicy(dirname(__DIR__, 3) . '/app/data/haeufige-passwoerter.txt');
    }

    public function testAGoodPasswordHasNoViolations(): void
    {
        self::assertSame([], $this->policy()->verstoesse('ein-ausreichend-langes-testpasswort'));
    }

    public function testNoCompositionRules(): void
    {
        self::assertSame([], $this->policy()->verstoesse('nurkleinebuchstaben'));
    }

    public function testAPasswordBelowTheMinimumLengthIsRejected(): void
    {
        $verstoesse = $this->policy()->verstoesse('kurz1234567');

        self::assertCount(1, $verstoesse);
        self::assertStringContainsString('mindestens 12 Zeichen', $verstoesse[0]);
    }

    public function testExactlyTheMinimumLengthPasses(): void
    {
        self::assertSame([], $this->policy()->verstoesse(str_repeat('x', PasswordPolicy::MIN_LAENGE)));
    }

    /** mb_strlen, not strlen: umlauts do not count double. */
    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        self::assertNotSame([], $this->policy()->verstoesse(str_repeat('ä', PasswordPolicy::MIN_LAENGE - 1)));
        self::assertSame([], $this->policy()->verstoesse(str_repeat('ä', PasswordPolicy::MIN_LAENGE)));
    }

    public function testACommonPasswordIsRejectedEvenWhenLongEnough(): void
    {
        $verstoesse = $this->policy()->verstoesse('passwort1234');

        self::assertCount(1, $verstoesse);
        self::assertStringContainsString('zu bekannt', $verstoesse[0]);
    }

    public function testTheCommonPasswordCheckIsCaseInsensitive(): void
    {
        self::assertNotSame([], $this->policy()->verstoesse('PASSWORT1234'));
    }

    public function testTheRepetitionMustMatch(): void
    {
        self::assertSame(['Die beiden Passwörter stimmen nicht überein.'], $this->policy()->pruefeNeu('ein-langes-passwort', 'ein-langes-Passwort'));
        self::assertSame([], $this->policy()->pruefeNeu('ein-langes-passwort', 'ein-langes-passwort'));
    }

    public function testAMissingListDoesNotBreakTheLengthCheck(): void
    {
        $policy = new PasswordPolicy('/nicht/vorhanden.txt');

        self::assertSame([], $policy->verstoesse('ein-ausreichend-langes-testpasswort'));
        self::assertNotSame([], $policy->verstoesse('zu-kurz'));
    }

    public function testTheShippedListIsLowercaseAndNotEmpty(): void
    {
        $zeilen = file(dirname(__DIR__, 3) . '/app/data/haeufige-passwoerter.txt', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        self::assertIsArray($zeilen);
        self::assertGreaterThan(1000, count($zeilen));
        foreach ($zeilen as $zeile) {
            self::assertSame(mb_strtolower(trim($zeile)), $zeile, 'the list is compared lowercase: ' . $zeile);
        }
    }
}
