<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Tests\Support\AppTestCase;

/**
 * Regressions found by the walkthrough of a fresh installation (issue #36):
 * things that each issue tested on its own but that only broke where the
 * parts meet on one page.
 */
final class GesamtdurchlaufTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    /**
     * The settings page puts every section on one page. A section's element
     * with the id of the section itself (the update chain had id="update",
     * like its <section>) made the script read the wrong element.
     */
    public function testTheSettingsPageHasNoDuplicateIds(): void
    {
        $this->als(Rolle::Admin);

        self::assertSame([], self::doppelteIds($this->get('/admin')->body));
    }

    public function testTheMembersPageHasNoDuplicateIds(): void
    {
        $this->als(Rolle::Admin);

        self::assertSame([], self::doppelteIds($this->get('/app/mitglieder')->body));
    }

    /** 06 section 2: "Neues Mitglied" opens the empty form - from the members page. */
    public function testTheMembersPageLinksToTheNewMemberForm(): void
    {
        foreach ([Rolle::Admin, Rolle::Vorstand] as $rolle) {
            $this->als($rolle);
            $seite = $this->get('/app/mitglieder')->body;

            self::assertStringContainsString('href="/app/mitglieder/neu">Neues Mitglied</a>', $seite, $rolle->value);
            self::assertSame(200, $this->get('/app/mitglieder/neu')->status, $rolle->value);
        }
    }

    /** @return list<string> */
    private static function doppelteIds(string $html): array
    {
        preg_match_all('/\sid="([^"]+)"/', $html, $treffer);
        $anzahl = array_count_values($treffer[1]);

        return array_keys(array_filter($anzahl, static fn(int $n): bool => $n > 1));
    }
}
