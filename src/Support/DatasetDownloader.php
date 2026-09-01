<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use WiserWebSolutions\Lobbyist\Legiscan\Exceptions\LegiscanException;

/**
 * Fetches a LegiScan dataset archive to local disk.
 *
 * LegiScan returns the archive base64-encoded inside a JSON envelope, which is
 * awkward at this size: a single session runs to tens of megabytes, and the
 * encoding inflates it by roughly a third on the wire. Decoding it the obvious
 * way would hold the JSON, the extracted base64 string and the decoded binary
 * in memory at once — several times the archive size, for every import.
 *
 * So the response is streamed to a scratch file and the base64 payload is
 * decoded incrementally out of it. Peak memory stays flat regardless of how
 * large the session is.
 */
class DatasetDownloader
{
    private const CHUNK_BYTES = 65536;

    /**
     * Read far enough into the response to catch an API-level error, which
     * LegiScan reports in the opening bytes of the envelope.
     */
    private const ERROR_PROBE_BYTES = 8192;

    public function __construct(
        private readonly PendingRequest $http,
        private readonly ?string $directory = null,
    ) {}

    /**
     * Download the archive selected by $query and return the local ZIP path.
     *
     * @param  array<string, mixed>  $query
     */
    public function download(array $query, string $filenameHint): string
    {
        $directory = $this->directory ?: sys_get_temp_dir();

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw LegiscanException::apiError("Dataset directory [{$directory}] is not writable.");
        }

        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $filenameHint) ?: 'dataset';
        $envelopePath = $directory.DIRECTORY_SEPARATOR.'legiscan-'.$slug.'.json.part';
        $zipPath = $directory.DIRECTORY_SEPARATOR.'legiscan-'.$slug.'.zip';

        try {
            $this->fetchEnvelope($query, $envelopePath);
            $this->assertNotAnApiError($envelopePath);
            $this->decodeZipField($envelopePath, $zipPath);

            if (! is_file($zipPath) || filesize($zipPath) === 0) {
                throw LegiscanException::apiError('The dataset response contained no archive payload.');
            }
        } catch (\Throwable $e) {
            // Leave nothing half-written behind: a partial or empty archive on
            // disk is worse than none, since a later run could mistake it for a
            // completed download.
            $this->discard($zipPath);

            throw $e;
        } finally {
            // The envelope is pure scratch; only the ZIP outlives this call.
            $this->discard($envelopePath);
        }

        return $zipPath;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function fetchEnvelope(array $query, string $envelopePath): void
    {
        try {
            $response = $this->http->sink($envelopePath)->get('/', $query);
        } catch (RequestException $e) {
            throw LegiscanException::requestFailed(
                'getDataset', $e->response?->status(), 'The LegiScan API returned an error response.', $e
            );
        } catch (ConnectionException $e) {
            throw LegiscanException::requestFailed(
                'getDataset', null, 'Could not connect to the LegiScan API.', $e
            );
        }

        if ($response->failed()) {
            throw LegiscanException::requestFailed('getDataset', $response->status());
        }
    }

    /**
     * A failed dataset request still arrives as a 200 with an error envelope, so
     * the body has to be inspected rather than the status code trusted.
     */
    private function assertNotAnApiError(string $envelopePath): void
    {
        $handle = $this->openForRead($envelopePath);
        $head = (string) fread($handle, self::ERROR_PROBE_BYTES);
        fclose($handle);

        if ($head === '') {
            throw LegiscanException::apiError('The LegiScan API returned an empty dataset response.');
        }

        if (! preg_match('/"status"\s*:\s*"([^"]+)"/', $head, $matches)) {
            throw LegiscanException::apiError('Unrecognized dataset response from the LegiScan API.');
        }

        if (strtoupper($matches[1]) === 'OK') {
            return;
        }

        // Error envelopes are small, so reading the whole thing is safe here.
        $decoded = json_decode((string) file_get_contents($envelopePath), true);
        $message = $decoded['alert']['message'] ?? 'Unknown error';

        throw LegiscanException::apiError("{$message} (operation: [getDataset]).");
    }

    /**
     * Stream-decode the envelope's `zip` field into a binary ZIP file.
     */
    private function decodeZipField(string $envelopePath, string $zipPath): void
    {
        $in = $this->openForRead($envelopePath);
        $out = fopen($zipPath, 'wb');

        if ($out === false) {
            fclose($in);
            throw LegiscanException::apiError("Could not write dataset archive to [{$zipPath}].");
        }

        try {
            $carry = $this->seekToZipPayload($in);
            $terminated = false;

            do {
                $end = strpos($carry, '"');

                if ($end !== false) {
                    $carry = substr($carry, 0, $end);
                    $terminated = true;
                }

                $carry = $this->writeBase64($out, $carry, flush: $terminated);

                if ($terminated) {
                    break;
                }

                $chunk = fread($in, self::CHUNK_BYTES);

                if ($chunk === false || $chunk === '') {
                    // Ran out of response before the closing quote.
                    $this->writeBase64($out, $carry, flush: true);
                    break;
                }

                $carry .= $chunk;
            } while (true);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Advance the handle past the `zip` field's opening quote, returning
     * whatever payload was already buffered in the same read.
     *
     * @param  resource  $in
     */
    private function seekToZipPayload($in): string
    {
        $marker = '/"zip"\s*:\s*"/';
        $buffer = '';

        while (true) {
            $chunk = fread($in, self::CHUNK_BYTES);

            if ($chunk === false || $chunk === '') {
                throw LegiscanException::apiError(
                    'The dataset response did not contain an archive field.'
                );
            }

            $buffer .= $chunk;

            if (preg_match($marker, $buffer, $matches, PREG_OFFSET_CAPTURE)) {
                $offset = $matches[0][1] + strlen($matches[0][0]);

                return substr($buffer, $offset);
            }

            // Retain a short tail so a marker split across two reads is still
            // found on the next pass.
            $buffer = substr($buffer, -32);
        }
    }

    /**
     * Decode as much of $payload as forms whole base64 quads, write it out, and
     * return the unaligned remainder to be carried into the next chunk.
     *
     * Everything outside the base64 alphabet is dropped first: PHP's JSON
     * encoder escapes forward slashes, so the stream arrives peppered with
     * backslashes that are not part of the data.
     *
     * @param  resource  $out
     */
    private function writeBase64($out, string $payload, bool $flush): string
    {
        $clean = preg_replace('#[^A-Za-z0-9+/=]#', '', $payload) ?? '';

        if ($clean === '') {
            return '';
        }

        $aligned = $flush ? strlen($clean) : intdiv(strlen($clean), 4) * 4;

        if ($aligned === 0) {
            return $clean;
        }

        $decoded = base64_decode(substr($clean, 0, $aligned), true);

        if ($decoded === false) {
            throw LegiscanException::apiError('The dataset archive payload was not valid base64.');
        }

        fwrite($out, $decoded);

        return substr($clean, $aligned);
    }

    private function discard(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return resource
     */
    private function openForRead(string $path)
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw LegiscanException::apiError("Could not read dataset response at [{$path}].");
        }

        return $handle;
    }
}
