<?php

declare(strict_types=1);

namespace App\Service\SystemCheck;

/**
 * Reads the response headers of one URL of the installation itself: the
 * system check uses it to see whether the security headers of web/.htaccess
 * arrive.
 *
 * Built like the self-test of the updater (App\Service\Update\
 * ReleaseDownloader::defaultHttpGet): curl when it is there, the stream
 * wrapper otherwise, a plain GET with redirects followed (http -> https),
 * the body discarded unread. Only status and headers leave this class.
 *
 * No curl_close(): deprecated since PHP 8.5 and without effect since 8.0.
 */
final readonly class CurlHeaderProbe implements HeaderProbe
{
    private const int TIMEOUT_S = 5;
    private const int MAX_REDIRECTS = 5;
    private const string USER_AGENT = 'Mitgliederverwaltung-Systemcheck';

    public function __invoke(string $url): ?array
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        return function_exists('curl_init') ? $this->viaCurl($url) : $this->viaStream($url);
    }

    /**
     * Turns raw header lines - possibly of several hops, each starting with
     * its status line - into the answer of the LAST hop.
     *
     * @param iterable<mixed> $lines
     *
     * @return array{status: int, headers: array<string, string>}|null
     */
    public static function auswerten(iterable $lines): ?array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
            } elseif ($status > 0 && preg_match('/^([A-Za-z0-9!#$%&\'*+.^_`|~-]+):\s*(.*)$/', $line, $m) === 1) {
                $name = strtolower($m[1]);
                $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $m[2] : $m[2];
            }
        }

        return $status > 0 ? ['status' => $status, 'headers' => $headers] : null;
    }

    /**
     * @return array{status: int, headers: array<string, string>}|null
     */
    private function viaCurl(string $url): ?array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }

        $lines = [];
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_S,
            CURLOPT_TIMEOUT => self::TIMEOUT_S,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$lines): int {
                $lines[] = $line;

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        unset($body);

        return self::auswerten($lines);
    }

    /**
     * @return array{status: int, headers: array<string, string>}|null
     */
    private function viaStream(string $url): ?array
    {
        $kontext = stream_context_create([
            'http' => [
                'timeout' => self::TIMEOUT_S,
                'follow_location' => 1,
                'max_redirects' => self::MAX_REDIRECTS,
                'user_agent' => self::USER_AGENT,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $kontext);
        $lines = http_get_last_response_headers() ?? [];
        unset($body);

        return self::auswerten($lines);
    }
}
