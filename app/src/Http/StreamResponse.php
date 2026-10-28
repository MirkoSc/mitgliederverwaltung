<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Sends a response piece by piece, from an iterable of strings. Taken over
 * from Vereinsbelege.
 *
 * This is what exports and decrypted files go out through: an export is
 * produced while it is downloaded and never stored on the webspace, and a
 * decrypted file never becomes a file again (CLAUDE.md section 4). Nothing
 * of it has to fit into the memory limit at once. {@see FileResponse} stays
 * the right answer for a file that already lies on disk in the clear, such
 * as a static asset.
 *
 * The one kind of request allowed to run long (CLAUDE.md section 1): the
 * time limit starts over with every piece, and the output buffers are
 * flushed after it, so the browser receives while the server still produces.
 */
final readonly class StreamResponse implements ResponseInterface
{
    /** Time each piece may take, measured from the end of the previous one. */
    public const int SEKUNDEN_JE_STUECK = 30;

    /**
     * @param iterable<string>      $chunks
     * @param array<string, string> $headers
     */
    public function __construct(
        public iterable $chunks,
        public array $headers = [],
        public int $status = 200,
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        foreach ($this->chunks as $chunk) {
            if ($chunk === '') {
                continue;
            }
            echo $chunk;
            // Not on the command line (tests): there it would put a limit on
            // the whole remaining run instead of lifting one.
            if (PHP_SAPI !== 'cli') {
                set_time_limit(self::SEKUNDEN_JE_STUECK);
            }
            // ob_get_level() > 0: a leftover output buffer would collect the
            // whole file and undo the streaming.
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
    }
}
