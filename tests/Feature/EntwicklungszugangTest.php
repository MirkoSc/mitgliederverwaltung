<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Anwendung;
use App\Dienste;
use App\Http\Entwicklungskonten;
use App\Http\Session;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TestKonten;

/**
 * The development accounts on the placeholder login (until issue #6): only
 * where switched on, and then exactly the two roles.
 */
final class EntwicklungszugangTest extends AppTestCase
{
    private function mitEntwicklungskonten(bool $aktiv): void
    {
        $dienste = self::baueDienste(new TestKonten());
        $this->dienste = new Dienste(
            $dienste->paths,
            $dienste->config,
            $dienste->version,
            $dienste->view,
            $dienste->session,
            $dienste->logger,
            kontoquelle: new Entwicklungskonten($aktiv),
        );
        $this->kernel = Anwendung::kernel($this->dienste);
    }

    public function testSwitchedOffTheLoginSaysItIsNotThereYet(): void
    {
        $this->mitEntwicklungskonten(false);

        $seite = $this->get('/anmelden');

        self::assertStringContainsString('Die Anmeldung ist noch nicht eingerichtet.', $seite->body);
        self::assertStringNotContainsString('Als Admin ansehen', $seite->body);
        self::assertSame(404, $this->post('/anmelden/entwicklung', ['konto' => 'admin'])->status);
        self::assertNull(new Session()->userId());
    }

    public function testSwitchedOnBothRolesCanBeTried(): void
    {
        $this->mitEntwicklungskonten(true);

        self::assertStringContainsString('Als Admin ansehen', $this->get('/anmelden')->body);

        self::assertWeiterleitungNach('/app', $this->post('/anmelden/entwicklung', ['konto' => 'vorstand']));
        self::assertSame(Entwicklungskonten::VORSTAND, new Session()->userId());
        self::assertSame(403, $this->get('/app/beitraege')->status);

        self::assertWeiterleitungNach('/app', $this->post('/anmelden/entwicklung', ['konto' => 'admin']));
        self::assertSame(200, $this->get('/app/beitraege')->status);
    }

    public function testAnUnknownAccountIsRefused(): void
    {
        $this->mitEntwicklungskonten(true);

        self::assertSame(404, $this->post('/anmelden/entwicklung', ['konto' => 'root'])->status);
    }

    /** Never on a real installation: the installer writes no `debug`, a release carries no "-dev". */
    public function testOnlyDebugAndADevelopmentVersionSwitchItOn(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/app/src/Dienste.php');

        self::assertStringContainsString('new Entwicklungskonten($this->config->debug && $this->version->isDev())', $quelle);
        self::assertNull(new Entwicklungskonten(false)->konto(Entwicklungskonten::ADMIN));
    }
}
