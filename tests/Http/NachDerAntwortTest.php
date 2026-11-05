<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\NachDerAntwort;
use PHPUnit\Framework\TestCase;

final class NachDerAntwortTest extends TestCase
{
    /**
     * The CLI (like mod_php) cannot end a response early: the work runs at
     * once - which is what lets the feature tests see the reset mail.
     */
    public function testWithoutFastcgiFinishRequestTheWorkRunsAtOnce(): void
    {
        self::assertFalse(\function_exists('fastcgi_finish_request'));

        $gelaufen = false;
        NachDerAntwort::ausfuehren(static function () use (&$gelaufen): void {
            $gelaufen = true;
        });

        self::assertTrue($gelaufen);
    }
}
