<?php

declare(strict_types=1);

namespace App\Tests\App\Mitglied;

use App\App\Mitglied\Einbettung;
use App\Http\HttpMethod;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Where an opened member is shown (docs/spec/06-oberflaeche.md section 5,
 * issue #86): the addresses of the embedding page and the guard against an
 * open redirect through the field `zurueck`.
 */
final class EinbettungTest extends TestCase
{
    private function liste(): Einbettung
    {
        return new Einbettung('/app/listen/geburtstage', ['zeitraum' => 'monat']);
    }

    public function testAddressesKeepThePageAndItsState(): void
    {
        $e = $this->liste();

        self::assertSame('/app/listen/geburtstage?zeitraum=monat', $e->rueckweg());
        self::assertSame('/app/listen/geburtstage?zeitraum=monat&mitglied=12', $e->adresse(12));
        self::assertSame('/app/listen/geburtstage?zeitraum=monat&mitglied=12&reiter=familie', $e->adresse(12, 'familie'));
        self::assertSame('/app/listen/geburtstage?mitglied=12', $e->adresse(12, null, ['zeitraum' => null]));
        self::assertSame('/app/listen/geburtstage?zeitraum=monat', $e->adresse(null, 'familie'), 'a tab needs a member');
    }

    public function testTheMembersPageIsTheDefaultAndNeedsNoField(): void
    {
        $standard = Einbettung::mitgliederSeite();

        self::assertTrue($standard->istStandard());
        self::assertSame('', $standard->feld());
        self::assertSame('/app/mitglieder/3/loeschen', $standard->mitRueckweg('/app/mitglieder/3/loeschen'));
        self::assertSame('/app/mitglieder?mitglied=3', $standard->adresse(3));

        self::assertFalse(new Einbettung('/app/mitglieder', ['abteilung' => '2'])->istStandard(), 'filters are state worth keeping');
    }

    public function testFormsAndOwnPagesCarryThePage(): void
    {
        $e = $this->liste();

        self::assertSame('<input type="hidden" name="zurueck" value="/app/listen/geburtstage?zeitraum=monat">', $e->feld());
        self::assertSame('/app/mitglieder/3/familie?zurueck=%2Fapp%2Flisten%2Fgeburtstage%3Fzeitraum%3Dmonat', $e->mitRueckweg('/app/mitglieder/3/familie'));
    }

    public function testTheFieldIsReadBackFromBodyOrQuery(): void
    {
        $ausBody = Einbettung::ausAnfrage(new Request(HttpMethod::Post, '/app/mitglieder/3', post: ['zurueck' => '/app/listen/geburtstage?zeitraum=monat']));
        self::assertSame('/app/listen/geburtstage?zeitraum=monat&mitglied=3&reiter=familie', $ausBody->adresse(3, 'familie'));

        $ausQuery = Einbettung::ausAnfrage(new Request(HttpMethod::Get, '/app/mitglieder/3/familie', query: ['zurueck' => '/app/familien']));
        self::assertSame('/app/familien', $ausQuery->rueckweg());

        $ohne = Einbettung::ausAnfrage(new Request(HttpMethod::Post, '/app/mitglieder/3'));
        self::assertSame('/app/mitglieder?mitglied=3', $ohne->adresse(3));
    }

    public function testTheMembersOwnParametersAreDroppedFromTheField(): void
    {
        $e = Einbettung::ausAdresse('/app/listen/geburtstage?zeitraum=monat&mitglied=4&reiter=verlauf&zurueck=x&fokus=mitglied');

        self::assertNotNull($e);
        self::assertSame(['zeitraum' => 'monat'], $e->parameter);
        self::assertNull($e->ziel, 'a redirect target has no htmx');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsichereAdressen(): iterable
    {
        yield 'other host' => ['https://boese.example/app/mitglieder'];
        yield 'protocol-relative' => ['//boese.example/app'];
        yield 'backslash' => ['/app\\\\boese.example'];
        yield 'up a level' => ['/app/../admin'];
        yield 'outside /app' => ['/admin/benutzer'];
        yield 'login' => ['/anmelden'];
        yield 'empty' => [''];
        yield 'fragment' => ['/app/mitglieder#x'];
        yield 'markup in the query' => ['/app/mitglieder?a="><script>'];
        yield 'newline' => ["/app/mitglieder\r\nX: y"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsichereAdressen')]
    public function testUnsafeAddressesFallBackToTheMembersPage(string $adresse): void
    {
        self::assertFalse(Einbettung::istSicher($adresse));
        self::assertNull(Einbettung::ausAdresse($adresse));
        $e = Einbettung::ausAnfrage(new Request(HttpMethod::Post, '/app/mitglieder/3', post: ['zurueck' => $adresse]));
        self::assertTrue($e->istStandard());
    }

    public function testAPageOutsideTheAppCannotBeBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Einbettung('https://boese.example');
    }

    public function testLinksWithoutHtmxArePlainLinks(): void
    {
        self::assertSame('href="/app/listen/geburtstage?zeitraum=monat&amp;mitglied=2"', $this->liste()->link($this->liste()->adresse(2), 'mitglied'));
    }

    public function testLinksWithHtmxGetOrPostTheEmbeddingArea(): void
    {
        $get = $this->liste()->mitHtmx('listen-bereich');
        $link = $get->link($get->adresse(2, 'verlauf'), 'reiter');

        self::assertStringContainsString('hx-get="/app/listen/geburtstage?zeitraum=monat&amp;mitglied=2&amp;reiter=verlauf"', $link);
        self::assertStringContainsString('hx-target="#listen-bereich"', $link);
        self::assertStringContainsString('hx-push-url="/app/listen/geburtstage?zeitraum=monat&amp;mitglied=2&amp;reiter=verlauf"', $link);
        self::assertStringContainsString("hx-vals='{&quot;fokus&quot;:&quot;reiter&quot;}'", $link);
        self::assertStringNotContainsString('hx-include', $link);

        $post = Einbettung::mitgliederSeite()->mitHtmx('mitglieder-bereich', '#mitglieder-form');
        $link = $post->link('/app/mitglieder', swap: 'outerHTML');
        self::assertSame('href="/app/mitglieder" hx-post="/app/mitglieder" hx-include="#mitglieder-form" hx-target="#mitglieder-bereich" hx-swap="outerHTML" hx-push-url="/app/mitglieder"', $link);
    }

    public function testAWayBackOfItsOwnSurvivesHtmx(): void
    {
        self::assertNull($this->liste()->zurueckText);

        $familie = new Einbettung('/app/familien', ['familie' => '5'])->mitZurueck('Zurück zur Familie')->mitHtmx('familien-bereich', '#familien-form');

        self::assertSame('Zurück zur Familie', $familie->zurueckText);
        self::assertSame('/app/familien?familie=5', $familie->rueckweg());
        self::assertSame('/app/familien?familie=5&mitglied=7', $familie->adresse(7));
    }
}
