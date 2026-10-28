<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Konto;
use App\Http\Kontoquelle;

/** The accounts the login guard sees in a feature test (App\Tests\Support\AppTestCase::als()). */
final class TestKonten implements Kontoquelle
{
    /** @var array<int, Konto> */
    private array $konten = [];

    public function hinzufuegen(Konto $konto): void
    {
        $this->konten[$konto->id] = $konto;
    }

    public function entfernen(int $id): void
    {
        unset($this->konten[$id]);
    }

    public function konto(int $id): ?Konto
    {
        return $this->konten[$id] ?? null;
    }
}
