<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Service\Export\BlsvFormat;
use PHPUnit\Framework\TestCase;

final class BlsvFormatTest extends TestCase
{
    public function testLeadingZeroIsRemoved(): void
    {
        self::assertSame('9', BlsvFormat::ohneFuehrendeNull('09'));
        self::assertSame('34', BlsvFormat::ohneFuehrendeNull('34'));
        self::assertSame('', BlsvFormat::ohneFuehrendeNull(''));
    }

    public function testSeveralNumbersAreSortedAscendingAndUnique(): void
    {
        self::assertSame('9, 34', BlsvFormat::spartenkennzeichen(['34', '09']));
        self::assertSame('9, 34', BlsvFormat::spartenkennzeichen(['09', '34', '9']));
        self::assertSame('', BlsvFormat::spartenkennzeichen(['', '']));
    }
}
