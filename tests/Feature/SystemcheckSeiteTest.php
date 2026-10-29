<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Service\SystemCheck\StatusProbe;
use App\Tests\Support\AppTestCase;

/** /admin/systemcheck through the whole application, with a fake status probe. */
final class SystemcheckSeiteTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    public function testThePageRendersWithTheProbeOfTheController(): void
    {
        $this->dienste->dienst(StatusProbe::class, static fn(): StatusProbe => new class implements StatusProbe {
            public function __invoke(string $url): ?int
            {
                return 403;
            }
        });
        $this->als(Rolle::Admin);

        $antwort = $this->get('/admin/systemcheck', headers: ['host' => 'verein.example.org']);

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('von außen gesperrt', $antwort->body);
    }
}
