<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\BlobMeta;
use App\Service\Upload\OffenesUpload;
use App\Service\Upload\UploadError;
use App\Service\Upload\UploadException;
use App\Service\Upload\UploadService;
use App\Support\FileLogger;

/**
 * The chunk upload as a reusable building block (docs/spec/03-datenhaltung.md
 * section 3): four short requests instead of one long one, so that no request
 * runs into the hoster's time limit or its upload limit per request
 * (CLAUDE.md section 1).
 *
 *   POST {basis}                 {groesse}  -> {id, groesse, chunks, chunk_bytes}
 *   POST {basis}/{id}/chunk/{n}  raw body   -> {chunk, chunks, fehlend, empfangen, vollstaendig}
 *   POST {basis}/{id}/finish     {name}     -> whatever the finish closure answers
 *   POST {basis}/{id}/abort                 -> {status}
 *
 * An area that takes files (the proofs of a member, issue #23) registers the
 * four routes from its own route file with its own access declaration and says
 * what happens to a finished file:
 *
 *     ChunkUpload::registrieren($routen, '/app/nachweise/upload', Zugriff::recht(...)->alsApi(),
 *         static function (OffenesUpload $datei, string $name) use ($routen): array {
 *             $blob = BlobService::aus($routen->dienste())
 *                 ->store($datei->chunks(), new BlobMeta($mime, $name));
 *
 *             return ['blob_id' => $blob->id];
 *         });
 *
 * The CSRF token is checked by App\Http\Routen for every POST, as for any
 * other route; public/js/upload.js sends it. Declare the access with
 * `->alsApi()` - the requests come from fetch() and want JSON, not a login
 * page.
 *
 * The request body of a chunk is raw bytes, which Request does not expose (it
 * only decodes JSON); it is read from php://input, or from the closure a test
 * passes.
 */
final readonly class ChunkUpload
{
    /**
     * @param \Closure(OffenesUpload, string): array<string, mixed> $abschluss
     *        what to do with the finished file: gets it opened (read it once,
     *        e.g. into BlobService::store()) and the name the browser sent,
     *        cleaned of any path; returns the JSON answer. It may throw an
     *        {@see UploadException} to refuse the file.
     * @param (\Closure(): resource)|null $body the request body; defaults to php://input
     */
    public function __construct(
        private UploadService $uploads,
        private \Closure $abschluss,
        private ?\Closure $body = null,
        private ?FileLogger $logger = null,
    ) {
    }

    /**
     * Registers the four routes below $basis. The services are built when
     * the first of them is hit, so a request pays only for its own route.
     *
     * @param \Closure(OffenesUpload, string): array<string, mixed> $abschluss see the constructor
     * @param (\Closure(): resource)|null $body tests only: the request body of a chunk
     */
    public static function registrieren(
        Routen $routen,
        string $basis,
        Zugriff $zugriff,
        \Closure $abschluss,
        ?\Closure $body = null,
    ): void {
        $baue = static fn(): self => new self(
            UploadService::aus($routen->dienste()),
            $abschluss,
            $body,
            $routen->dienste()->logger,
        );

        $routen->post($basis, $zugriff, static fn(Request $r): ResponseInterface => $baue()->eroeffnen($r));
        $routen->post(
            $basis . '/{id}/chunk/{n:\d+}',
            $zugriff,
            static fn(Request $r, array $p): ResponseInterface => $baue()->chunk($r, $p),
        );
        $routen->post(
            $basis . '/{id}/finish',
            $zugriff,
            static fn(Request $r, array $p): ResponseInterface => $baue()->abschliessen($r, $p),
        );
        $routen->post(
            $basis . '/{id}/abort',
            $zugriff,
            static fn(Request $r, array $p): ResponseInterface => $baue()->abbrechen($r, $p),
        );
    }

    public function eroeffnen(Request $request): Response
    {
        return $this->geschuetzt($request, function () use ($request): Response {
            $groesse = $request->post['groesse'] ?? null;
            if (!is_int($groesse) && !(is_string($groesse) && ctype_digit($groesse))) {
                return Response::json(['fehler' => 'Ungültige Dateigröße.'], 422);
            }

            return Response::json($this->uploads->create((int) $groesse)->toArray(), 201);
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function chunk(Request $request, array $params): Response
    {
        return $this->geschuetzt($request, function () use ($params): Response {
            $id = $params['id'];
            $index = (int) $params['n'];

            $oeffnen = $this->body ?? static fn() => fopen('php://input', 'rb');
            $stream = $oeffnen();
            if (!is_resource($stream)) {
                throw new \RuntimeException('Cannot read the request body.');
            }

            try {
                $this->uploads->writeChunk($id, $index, $stream);
            } finally {
                fclose($stream);
            }

            return Response::json(['chunk' => $index] + $this->uploads->status($id)->toArray());
        });
    }

    /**
     * Hands the finished file to the closure, in one pass - the plaintext
     * never becomes a file of its own - and throws the chunks away.
     *
     * @param array<string, string> $params
     */
    public function abschliessen(Request $request, array $params): Response
    {
        return $this->geschuetzt($request, function () use ($request, $params): Response {
            $id = $params['id'];

            $status = $this->uploads->status($id);
            if (!$status->vollstaendig()) {
                return Response::json([
                    'fehler' => UploadError::Incomplete->message(),
                    'fehlend' => $status->fehlend,
                ], UploadError::Incomplete->status());
            }

            $offen = $this->uploads->oeffnen($id);
            try {
                $antwort = ($this->abschluss)($offen, BlobMeta::bereinigterName($request->feld('name')));
            } catch (\Throwable $e) {
                // Refused or failed: the chunks are not needed any more, and
                // a retry starts a new upload anyway.
                $offen->schliessen();
                $this->uploads->discard($id);

                throw $e;
            }

            $offen->schliessen();
            $this->uploads->discard($id);

            return Response::json($antwort, 201);
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function abbrechen(Request $request, array $params): Response
    {
        return $this->geschuetzt($request, function () use ($params): Response {
            $this->uploads->discard($params['id']);

            return Response::json(['status' => 'geloescht']);
        });
    }

    /**
     * The JSON error contract for everything the handler can throw. The
     * kernel would answer a plain text 500, which no fetch() can make sense
     * of - and a refused upload is an everyday case, not a bug.
     *
     * @param \Closure(): Response $handler
     */
    private function geschuetzt(Request $request, \Closure $handler): Response
    {
        try {
            return $handler();
        } catch (UploadException $e) {
            return Response::json(['fehler' => $e->error->message()], $e->error->status());
        } catch (\Throwable $e) {
            // Class and route only - never the message, which could carry a
            // path or a file name (CLAUDE.md section 4).
            $this->logger?->append(sprintf('upload failed: %s in %s', $e::class, $request->path));

            return Response::json(['fehler' => 'Der Upload ist fehlgeschlagen.'], 500);
        }
    }
}
