<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * HttpAbruf over curl, or PHP streams where curl is missing. Short timeouts
 * (a page or a cron run waits for it), HTTPS only, no redirects to follow:
 * both services answer directly. The body is capped, so a broken answer
 * cannot fill the memory.
 *
 * The test suite defines MV_OHNE_NETZ (tests/bootstrap.php): then nothing
 * is sent at all - a test that forgot its fake gets "no answer" instead of
 * reaching OpenStreetMap.
 *
 * No curl_close(): deprecated since PHP 8.5 and without effect since 8.0
 * (same as App\Service\Update\ReleaseDownloader).
 */
final readonly class CurlHttpAbruf implements HttpAbruf
{
    private const int TIMEOUT_S = 8;

    /** A tile is ~20 KB, a geocoder answer ~1 KB. */
    private const int MAX_BYTES = 2 * 1024 * 1024;

    public function holen(string $url, string $userAgent): HttpAntwort
    {
        if (defined('MV_OHNE_NETZ') || preg_match('#^https://#i', $url) !== 1) {
            return new HttpAntwort(0);
        }

        try {
            return function_exists('curl_init') ? $this->viaCurl($url, $userAgent) : $this->viaStream($url, $userAgent);
        } catch (\Throwable) {
            return new HttpAntwort(0);
        }
    }

    private function viaCurl(string $url, string $userAgent): HttpAntwort
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return new HttpAntwort(0);
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_S,
            CURLOPT_TIMEOUT => self::TIMEOUT_S,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $typ = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);

        if (!is_string($body)) {
            return new HttpAntwort(0);
        }

        return new HttpAntwort($status, $body, is_string($typ) ? $typ : '');
    }

    private function viaStream(string $url, string $userAgent): HttpAntwort
    {
        $kontext = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_S,
                'follow_location' => 0,
                'ignore_errors' => true,
                'header' => 'User-Agent: ' . $userAgent . "\r\n",
            ],
        ]);
        $strom = @fopen($url, 'rb', false, $kontext);
        if ($strom === false) {
            return new HttpAntwort(0);
        }
        $body = stream_get_contents($strom, self::MAX_BYTES);
        $meta = stream_get_meta_data($strom);
        fclose($strom);

        $status = 0;
        $typ = '';
        foreach ($meta['wrapper_data'] ?? [] as $zeile) {
            if (!is_string($zeile)) {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $zeile, $treffer) === 1) {
                $status = (int) $treffer[1];
            } elseif (stripos($zeile, 'content-type:') === 0) {
                $typ = trim(substr($zeile, 13));
            }
        }

        return new HttpAntwort($status, is_string($body) ? $body : '', $typ);
    }
}
