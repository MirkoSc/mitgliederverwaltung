<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Config;
use App\Config\Paths;
use App\Dienste;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Domain\Konto;
use App\Domain\Rolle;
use App\Http\ChunkUpload;
use App\Http\CsrfSchutz;
use App\Http\HttpMethod;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Router;
use App\Http\Session;
use App\Http\Zugriff;
use App\Repository\BlobRepository;
use App\Service\Crypto\ServerCrypto;
use App\Service\Migration\Migrator;
use App\Service\Storage\BlobService;
use App\Service\Storage\DbBlobBackend;
use App\Service\Storage\FsBlobBackend;
use App\Service\Upload\OffenesUpload;
use App\Service\Upload\UploadError;
use App\Service\Upload\UploadException;
use App\Service\Upload\UploadService;
use App\Support\FileLogger;
use App\Support\Version;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\TestKonten;
use App\View\Stylesheets;
use App\View\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The whole way a file takes: open the upload, send the chunks, close it -
 * and what comes out the other end is an encrypted blob
 * (docs/spec/03-datenhaltung.md section 3, "Uploads in Chunks (2 MiB)").
 *
 * Driven through real routes with the real login guard and CSRF check,
 * against a real database and both storage backends, because the point of the
 * closing request is that it hands the chunks straight to the storage layer.
 */
final class ChunkUploadTest extends DatabaseTestCase
{
    private const string BASIS = '/app/test/upload';

    private string $wurzel;

    private ServerCrypto $crypto;

    private TestKonten $konten;

    private Dienste $dienste;

    private Router $router;

    private BlobStorage $storage = BlobStorage::Fs;

    /** The body of the next chunk request. */
    private string $body = '';

    /** What the finish closure got: [file name, content]. */
    private array $abgeschlossen = [];

    /** If set, the finish closure throws it. */
    private ?\Throwable $abschlussFehler = null;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();

        // release root = <tmp>/current, so that shared/ lies next to it.
        $this->wurzel = sys_get_temp_dir() . '/mv_chunkupload_' . uniqid('', true);
        mkdir($this->wurzel . '/current', 0775, true);
        mkdir($this->wurzel . '/shared/var/blobs', 0775, true);
        mkdir($this->wurzel . '/shared/var/tmp', 0775, true);

        $_SESSION = [];
        $this->crypto = new ServerCrypto(random_bytes(ServerCrypto::KEY_BYTES));
        $this->konten = new TestKonten();
        $this->konten->anmelden(new Konto(5, 'Vorstand', Rolle::Vorstand));

        $paths = new Paths($this->wurzel . '/current');
        $repoRoot = dirname(__DIR__, 2);
        $this->dienste = new Dienste(
            paths: $paths,
            config: Config::fromArray(self::configData()),
            version: new Version('0.0.0-test'),
            view: new View($repoRoot . '/app/views', '0.0.0-test', Stylesheets::aus($repoRoot . '/public')),
            session: new Session(),
            logger: new FileLogger($this->wurzel . '/shared/var/log/app.log'),
            pdoFabrik: fn(): \PDO => $this->pdo(),
            kontoquelle: $this->konten,
        );

        $this->router = new Router();
        $routen = new Routen(
            $this->router,
            $this->dienste,
            new LoginGuard($this->dienste->session, $this->dienste->view, $this->konten),
            new CsrfSchutz($this->dienste->session, $this->dienste->view),
        );
        ChunkUpload::registrieren(
            $routen,
            self::BASIS,
            Zugriff::angemeldet()->alsApi(),
            function (OffenesUpload $datei, string $name): array {
                if ($this->abschlussFehler !== null) {
                    throw $this->abschlussFehler;
                }
                $blob = $this->blobs()->store($datei->chunks(), new BlobMeta('application/pdf', $name), $this->storage);
                $this->abgeschlossen = [$name, $blob->id];

                return ['blob_id' => $blob->id, 'groesse' => $blob->size];
            },
            function () {
                $stream = fopen('php://temp', 'r+b');
                self::assertIsResource($stream);
                fwrite($stream, $this->body);
                rewind($stream);

                return $stream;
            },
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        self::removeDir($this->wurzel);
        parent::tearDown();
    }

    /**
     * @return array<string, array{BlobStorage}>
     */
    public static function backends(): array
    {
        return ['Dateisystem' => [BlobStorage::Fs], 'Datenbank' => [BlobStorage::Db]];
    }

    #[DataProvider('backends')]
    public function testAFileArrivesInChunksAndLeavesAsAnEncryptedBlob(BlobStorage $storage): void
    {
        $this->storage = $storage;
        // Two chunks of the real size: 2 MiB and a bit.
        $inhalt = str_repeat('%PDF-Nachweis-', (int) ((UploadService::CHUNK_BYTES + 100) / 14) + 1);
        $inhalt = substr($inhalt, 0, UploadService::CHUNK_BYTES + 100);

        $ticket = $this->json($this->post(self::BASIS, ['groesse' => strlen($inhalt)]), 201);
        self::assertSame(2, $ticket['chunks']);
        self::assertSame(UploadService::CHUNK_BYTES, $ticket['chunk_bytes']);

        // Backwards on purpose: the order the chunks arrive in must not
        // matter, only the index they carry.
        $stuecke = str_split($inhalt, UploadService::CHUNK_BYTES);
        foreach ([1, 0] as $index) {
            $this->body = $stuecke[$index];
            self::assertSame(200, $this->post(self::BASIS . '/' . $ticket['id'] . '/chunk/' . $index)->status);
        }

        $antwort = $this->json($this->post(self::BASIS . '/' . $ticket['id'] . '/finish', ['name' => 'C:\\Users\\Kasse\\Attest Max.pdf']), 201);
        self::assertSame(strlen($inhalt), $antwort['groesse']);

        $blob = $this->blobs()->find((int) $antwort['blob_id']);
        self::assertNotNull($blob);
        self::assertSame($storage, $blob->storage);
        self::assertSame($inhalt, self::collect($this->blobs()->openRead($blob)));

        // Stripped of the path the browser sent along.
        self::assertSame('Attest Max.pdf', $blob->originalName);
        self::assertSame('Attest Max.pdf', $this->abgeschlossen[0]);

        self::assertSame([], self::entries($this->wurzel . '/shared/var/tmp/upload'), 'the plaintext chunks are gone');
        $this->assertNoPlaintextOnDisk('%PDF-Nachweis-');
    }

    public function testTheChunkAnswerNamesWhatIsStillMissing(): void
    {
        $ticket = $this->json($this->post(self::BASIS, ['groesse' => UploadService::CHUNK_BYTES * 3]), 201);

        $this->body = str_repeat('x', UploadService::CHUNK_BYTES);
        $antwort = $this->json($this->post(self::BASIS . '/' . $ticket['id'] . '/chunk/0'), 200);

        self::assertSame([1, 2], $antwort['fehlend']);
        self::assertFalse($antwort['vollstaendig']);
    }

    public function testClosingAnIncompleteUploadIsRefusedAndKeepsWhatIsThere(): void
    {
        $ticket = $this->json($this->post(self::BASIS, ['groesse' => UploadService::CHUNK_BYTES + 5]), 201);
        $this->body = str_repeat('x', UploadService::CHUNK_BYTES);
        $this->post(self::BASIS . '/' . $ticket['id'] . '/chunk/0');

        $antwort = $this->json($this->post(self::BASIS . '/' . $ticket['id'] . '/finish', ['name' => 'a.pdf']), 409);

        self::assertSame([1], $antwort['fehlend']);
        self::assertSame(0, $this->countBlobs(), 'nothing half written reaches the storage');
        self::assertNotSame([], self::entries($this->wurzel . '/shared/var/tmp/upload'), 'the chunks stay so the browser can retry');
    }

    public function testAFileTheClosureRefusesIsAnswerdWithItsErrorAndThrownAway(): void
    {
        $this->abschlussFehler = new UploadException(UploadError::UnsupportedType);
        $ticket = $this->json($this->post(self::BASIS, ['groesse' => 4]), 201);
        $this->body = '1234';
        $this->post(self::BASIS . '/' . $ticket['id'] . '/chunk/0');

        $antwort = $this->json($this->post(self::BASIS . '/' . $ticket['id'] . '/finish', ['name' => 'a.exe']), 415);

        self::assertSame(UploadError::UnsupportedType->message(), $antwort['fehler']);
        self::assertSame([], self::entries($this->wurzel . '/shared/var/tmp/upload'), 'a refused file is not kept');
    }

    public function testAnUnexpectedFailureIsAJsonErrorWithoutTheMessage(): void
    {
        $this->abschlussFehler = new \RuntimeException('Datei Attest_Max_Mustermann.pdf kaputt');
        $ticket = $this->json($this->post(self::BASIS, ['groesse' => 4]), 201);
        $this->body = '1234';
        $this->post(self::BASIS . '/' . $ticket['id'] . '/chunk/0');

        $antwort = $this->post(self::BASIS . '/' . $ticket['id'] . '/finish', ['name' => 'a.pdf']);

        self::assertSame(500, $antwort->status);
        self::assertStringNotContainsString('Mustermann', $antwort->body);
        $log = (string) file_get_contents($this->wurzel . '/shared/var/log/app.log');
        self::assertStringContainsString('upload failed: RuntimeException', $log);
        self::assertStringNotContainsString('Mustermann', $log, 'the exception message never reaches the log');
    }

    public function testAbortThrowsTheChunksAway(): void
    {
        $ticket = $this->json($this->post(self::BASIS, ['groesse' => 4]), 201);
        $this->body = '1234';
        $this->post(self::BASIS . '/' . $ticket['id'] . '/chunk/0');

        $this->json($this->post(self::BASIS . '/' . $ticket['id'] . '/abort'), 200);

        self::assertSame([], self::entries($this->wurzel . '/shared/var/tmp/upload'));
    }

    public function testAnUnknownUploadIsNotFound(): void
    {
        $this->json($this->post(self::BASIS . '/' . str_repeat('a', 32) . '/finish', ['name' => 'a.pdf']), 404);
        $this->json($this->post(self::BASIS . '/../../etc/chunk/0'), 404);
    }

    public function testAnInvalidSizeIsRefused(): void
    {
        $this->json($this->post(self::BASIS, ['groesse' => 'viel']), 422);
        $this->json($this->post(self::BASIS, ['groesse' => 0]), 422);
        $this->json($this->post(self::BASIS, ['groesse' => UploadService::MAX_FILE_BYTES + 1]), 413);
    }

    /** The routes inherit the central checks of App\Http\Routen. */
    public function testTheRoutesNeedALoginAndTheCsrfToken(): void
    {
        $this->json($this->post(self::BASIS, ['groesse' => 4], mitToken: false), 403);

        $_SESSION = [];
        $this->json($this->post(self::BASIS, ['groesse' => 4], mitToken: false), 401);
    }

    /**
     * @param array<string, mixed> $felder
     */
    private function post(string $pfad, array $felder = [], bool $mitToken = true): Response
    {
        $headers = [];
        if ($mitToken) {
            $session = new Session();
            $session->start();
            $headers['x-csrf-token'] = $session->csrfToken();
        }

        $request = new Request(HttpMethod::Post, $pfad, post: $felder, headers: $headers);
        $treffer = $this->router->match($request->method, $request->path);
        if ($treffer->handler === null) {
            return Response::json(['fehler' => 'nicht gefunden'], 404);
        }
        $antwort = ($treffer->handler)($request, $treffer->params);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $antwort, int $status): array
    {
        self::assertSame($status, $antwort->status, $antwort->body);
        $daten = json_decode($antwort->body, true);
        self::assertIsArray($daten);

        return $daten;
    }

    private function blobs(): BlobService
    {
        $repository = new BlobRepository($this->pdo());

        return new BlobService(
            $repository,
            new DbBlobBackend($repository),
            new FsBlobBackend($this->wurzel . '/shared/var/blobs'),
            $this->crypto,
        );
    }

    private function countBlobs(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM file_blob')->fetchColumn();
    }

    private function assertNoPlaintextOnDisk(string $needle): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->wurzel . '/shared', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                self::assertStringNotContainsString($needle, (string) file_get_contents($file->getPathname()));
            }
        }
    }

    /**
     * @param iterable<string> $chunks
     */
    private static function collect(iterable $chunks): string
    {
        $result = '';
        foreach ($chunks as $chunk) {
            $result .= $chunk;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function entries(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $eintrag) {
            $pfad = $dir . '/' . $eintrag;
            is_dir($pfad) ? self::removeDir($pfad) : unlink($pfad);
        }

        rmdir($dir);
    }
}
