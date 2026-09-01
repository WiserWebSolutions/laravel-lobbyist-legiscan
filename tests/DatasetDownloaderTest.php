<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Tests;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\Lobbyist\Legiscan\Exceptions\LegiscanException;
use WiserWebSolutions\Lobbyist\Legiscan\Support\DatasetDownloader;
use ZipArchive;

/**
 * The downloader decodes a base64 archive out of a JSON envelope without ever
 * holding the whole thing in memory. That incremental decoding is where the
 * bugs live: chunk boundaries falling mid-quad, and JSON escaping polluting the
 * base64 alphabet.
 */
class DatasetDownloaderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    private function downloader(): DatasetDownloader
    {
        return new DatasetDownloader(
            Http::baseUrl('https://api.legiscan.test/')->timeout(30),
            sys_get_temp_dir(),
        );
    }

    private function track(string $path): string
    {
        $this->paths[] = $path;

        return $path;
    }

    /**
     * Build a real ZIP so the assertion is that a working archive comes out the
     * other end, not merely that some bytes matched.
     */
    private function makeZip(int $entries = 1, int $entryBytes = 32): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dl-src-').'.zip';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        for ($i = 0; $i < $entries; $i++) {
            // Low-compressibility content, so the encoded payload is large
            // enough to actually cross the reader's chunk boundaries.
            $zip->addFromString(
                "PA/2025-2026_Regular_Session/bill/HB{$i}.json",
                base64_encode(random_bytes($entryBytes))
            );
        }

        $zip->close();

        return $this->track($path);
    }

    /**
     * LegiScan returns the archive base64-encoded inside a JSON envelope. PHP's
     * json_encode escapes forward slashes, so a real envelope arrives with
     * backslashes scattered through the base64 — which is exactly the case
     * $escapeSlashes reproduces.
     */
    private function fakeEnvelope(string $zipPath, bool $escapeSlashes = true): void
    {
        $encoded = base64_encode((string) file_get_contents($zipPath));
        $payload = $escapeSlashes ? str_replace('/', '\\/', $encoded) : $encoded;

        $body = '{"status":"OK","dataset":{"session_id":2192,"session_name":"2025-2026",'
            .'"dataset_hash":"abc123","mime_type":"application\\/zip","zip":"'.$payload.'"}}';

        Http::fake(['api.legiscan.test/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
    }

    public function test_decodes_the_archive_into_a_readable_zip(): void
    {
        $source = $this->makeZip(entries: 3);
        $this->fakeEnvelope($source);

        $path = $this->track($this->downloader()->download(['op' => 'getDataset'], 'PA-2192'));

        $this->assertFileExists($path);
        $this->assertSame(
            file_get_contents($source),
            file_get_contents($path),
            'The decoded archive should be byte-identical to the original.'
        );

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame(3, $zip->numFiles);
        $zip->close();
    }

    public function test_decodes_a_payload_spanning_many_read_chunks(): void
    {
        // Comfortably larger than the 64 KB read size, so the base64 stream is
        // reassembled across boundaries that rarely land on a quad.
        $source = $this->makeZip(entries: 60, entryBytes: 8192);
        $this->assertGreaterThan(65536 * 4, filesize($source));

        $this->fakeEnvelope($source);

        $path = $this->track($this->downloader()->download(['op' => 'getDataset'], 'PA-big'));

        $this->assertSame(file_get_contents($source), file_get_contents($path));
    }

    public function test_handles_an_unescaped_payload_too(): void
    {
        $source = $this->makeZip(entries: 2);
        $this->fakeEnvelope($source, escapeSlashes: false);

        $path = $this->track($this->downloader()->download(['op' => 'getDataset'], 'PA-plain'));

        $this->assertSame(file_get_contents($source), file_get_contents($path));
    }

    public function test_surfaces_an_api_error_envelope(): void
    {
        // A rejected dataset request still arrives as HTTP 200.
        Http::fake(['api.legiscan.test/*' => Http::response(
            '{"status":"ERROR","alert":{"message":"Subscription required for bulk access"}}'
        )]);

        $this->expectException(LegiscanException::class);
        $this->expectExceptionMessage('Subscription required for bulk access');

        $this->downloader()->download(['op' => 'getDataset'], 'PA-2192');
    }

    public function test_fails_clearly_when_the_response_has_no_archive_field(): void
    {
        Http::fake(['api.legiscan.test/*' => Http::response(
            '{"status":"OK","dataset":{"session_id":2192,"session_name":"2025-2026"}}'
        )]);

        $this->expectException(LegiscanException::class);
        $this->expectExceptionMessage('did not contain an archive field');

        $this->downloader()->download(['op' => 'getDataset'], 'PA-2192');
    }

    public function test_leaves_no_partial_archive_behind_when_the_payload_is_empty(): void
    {
        Http::fake(['api.legiscan.test/*' => Http::response(
            '{"status":"OK","dataset":{"session_id":2192,"zip":""}}'
        )]);

        try {
            $this->downloader()->download(['op' => 'getDataset'], 'PA-empty');
            $this->fail('Expected an exception for an empty archive payload.');
        } catch (LegiscanException $e) {
            $this->assertStringContainsString('no archive payload', $e->getMessage());
        }

        // An empty file left on disk is worse than none: a later run could take
        // it for a finished download.
        $this->assertFileDoesNotExist(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'legiscan-PA-empty.zip'
        );
    }

    public function test_does_not_leave_the_scratch_envelope_behind(): void
    {
        $source = $this->makeZip();
        $this->fakeEnvelope($source);

        $path = $this->track($this->downloader()->download(['op' => 'getDataset'], 'PA-cleanup'));

        // The envelope is several times the archive size; leaving one per import
        // would quietly fill the disk.
        $this->assertFileDoesNotExist(
            dirname($path).DIRECTORY_SEPARATOR.'legiscan-PA-cleanup.json.part'
        );
    }
}
