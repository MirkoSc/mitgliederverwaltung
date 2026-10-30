<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Tests\Support\AppTestCase;

/** The table of contents of the settings page (issue #89). */
final class EinstellungenVerzeichnisTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    public function testEverySectionHasAnAnchorAndAnEntryInTheContents(): void
    {
        $this->als(Rolle::Admin);
        $seite = $this->get('/admin');
        self::assertSame(200, $seite->status);

        preg_match_all('/<section class="abschnitt" id="([a-z0-9-]+)"/', $seite->body, $abschnitte);
        preg_match('#<nav class="verzeichnis".*?</nav>#s', $seite->body, $nav);
        self::assertNotEmpty($abschnitte[1], 'the page has sections');
        self::assertNotEmpty($nav, 'the contents exist');
        foreach ($abschnitte[1] as $id) {
            self::assertStringContainsString('href="#' . $id . '"', $nav[0], $id . ' is listed');
            self::assertStringContainsString('<h2 id="titel-' . $id . '" tabindex="-1">', $seite->body, $id . ' heading takes focus');
        }
        preg_match_all('/href="#([a-z0-9-]+)"/', $nav[0], $eintraege);
        self::assertEqualsCanonicalizing($abschnitte[1], $eintraege[1], 'nothing listed that is not on the page');
        self::assertStringContainsString('/js/inhaltsverzeichnis.js', $seite->body);
    }
}
