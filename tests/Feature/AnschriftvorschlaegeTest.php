<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Session;
use App\Service\Karte\HttpAbruf;
use App\Service\Karte\HttpAntwort;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FakeHttpAbruf;

/**
 * Address suggestions in the member form (issue #127) through the whole
 * application, with a fake Photon: the answer, rights, CSRF, the rate
 * limit and the field in the form.
 */
final class AnschriftvorschlaegeTest extends AppTestCase
{
    private FakeHttpAbruf $http;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttpAbruf(static fn(): HttpAntwort => new HttpAntwort(200, (string) json_encode(['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'properties' => ['type' => 'house', 'street' => 'Hauptstraße', 'housenumber' => '5', 'postcode' => '12345', 'city' => 'Musterstadt', 'countrycode' => 'DE']],
        ]]), 'application/json'));
        $http = $this->http;
        $this->dienste->dienst(HttpAbruf::class, static fn(): HttpAbruf => $http);
    }

    /** @return array<string, mixed> */
    private static function json(string $body): array
    {
        $daten = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($daten);

        return $daten;
    }

    public function testTheBoardGetsSuggestionsAsJson(): void
    {
        $this->als(Rolle::Vorstand);

        $antwort = $this->post('/app/anschrift/vorschlaege', ['strasse' => 'Hauptstr 5', 'plz' => '12345', 'ort' => 'Musterstadt']);

        self::assertSame(200, $antwort->status);
        self::assertStringStartsWith('application/json', $antwort->headers['Content-Type'] ?? '');
        self::assertSame(['vorschlaege' => [
            ['strasse' => 'Hauptstraße 5', 'plz' => '12345', 'ort' => 'Musterstadt', 'land' => 'DE', 'text' => 'Hauptstraße 5, 12345 Musterstadt'],
        ]], self::json($antwort->body));
        self::assertCount(1, $this->http->anfragen);
        self::assertStringStartsWith('https://photon.komoot.io/api/?q=Hauptstr%205%2C%2012345%20Musterstadt&', $this->http->anfragen[0]['url']);
    }

    public function testAShortTextAsksNobody(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->post('/app/anschrift/vorschlaege', ['strasse' => 'Ha']);

        self::assertSame(200, $antwort->status);
        self::assertSame(['vorschlaege' => []], self::json($antwort->body));
        self::assertSame([], $this->http->anfragen);
    }

    public function testReadOnlyAndAnonymousGetNothing(): void
    {
        $antwort = $this->post('/app/anschrift/vorschlaege', ['strasse' => 'Hauptstraße']);
        self::assertSame(401, $antwort->status);

        $this->als(Rolle::Lesend);
        $antwort = $this->post('/app/anschrift/vorschlaege', ['strasse' => 'Hauptstraße']);
        self::assertSame(403, $antwort->status);

        self::assertSame([], $this->http->anfragen);
    }

    public function testWithoutTheTokenNothingIsAsked(): void
    {
        $this->als(Rolle::Vorstand);

        $antwort = $this->post('/app/anschrift/vorschlaege', ['strasse' => 'Hauptstraße'], mitToken: false);

        self::assertSame(403, $antwort->status);
        self::assertSame([], $this->http->anfragen);
    }

    public function testTheTokenAsHeaderIsEnough(): void
    {
        $this->als(Rolle::Vorstand);
        $session = new Session();
        $session->start();

        $antwort = $this->aufrufen(new Request(HttpMethod::Post, '/app/anschrift/vorschlaege', post: ['strasse' => 'Hauptstraße'], headers: ['x-csrf-token' => $session->csrfToken()]));

        self::assertSame(200, $antwort->status);
    }

    public function testSixtyRequestsAMinutePerAccount(): void
    {
        $this->als(Rolle::Vorstand);
        // The window is the clock's minute: not start right before it turns.
        $sekunde = (int) date('s');
        if ($sekunde >= 55) {
            sleep(61 - $sekunde);
        }

        for ($i = 0; $i < 60; ++$i) {
            self::assertSame(200, $this->post('/app/anschrift/vorschlaege', ['strasse' => 'Hauptstraße'])->status);
        }
        $antwort = $this->post('/app/anschrift/vorschlaege', ['strasse' => 'Hauptstraße']);

        self::assertSame(429, $antwort->status);
        self::assertSame([], self::json($antwort->body)['vorschlaege']);
        self::assertCount(60, $this->http->anfragen);

        // Another account has its own counter.
        $this->als(Rolle::Admin);
        self::assertSame(200, $this->post('/app/anschrift/vorschlaege', ['strasse' => 'Hauptstraße'])->status);
    }

    public function testTheNewMemberFormOffersSuggestions(): void
    {
        $this->als(Rolle::Vorstand);

        $html = $this->get('/app/mitglieder/neu')->body;

        self::assertStringContainsString('data-anschrift-vorschlaege="/app/anschrift/vorschlaege"', $html);
        self::assertStringContainsString('src="/js/anschrift-vorschlaege.js', $html);
        self::assertStringNotContainsString('photon', $html);
    }
}
