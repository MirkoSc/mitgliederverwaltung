<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Konto;
use App\Http\Kontoquelle;
use App\Http\Session;

/** The accounts the login guard sees in a feature test (App\Tests\Support\AppTestCase::als()). */
final class TestKonten implements Kontoquelle
{
    /** @var array<int, Konto> */
    private array $konten = [];

    public function hinzufuegen(Konto $konto): void
    {
        $this->konten[$konto->id] = $konto;
    }

    /**
     * Knows the account and logs it into a fresh session - the login
     * without the login (until issue #6, and afterwards for every test
     * that is not about the login).
     */
    public function anmelden(Konto $konto): void
    {
        $this->hinzufuegen($konto);
        $session = new Session();
        $session->start();
        $_SESSION = [];
        $session->login($konto->id);
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
