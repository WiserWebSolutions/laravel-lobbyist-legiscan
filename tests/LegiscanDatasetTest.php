<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Tests;

use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;
use WiserWebSolutions\Lobbyist\Legiscan\Exceptions\LegiscanException;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanDriver;

class LegiscanDatasetTest extends TestCase
{
    private function driver(): LegiscanDriver
    {
        return new LegiscanDriver(config('lobbyist-legiscan'));
    }

    /**
     * Shaped after a real getDatasetList response for PA.
     */
    private function datasetList(): array
    {
        return $this->okResponse([
            'datasetlist' => [
                [
                    'state_id' => 38,
                    'session_id' => 2192,
                    'year_start' => 2025,
                    'year_end' => 2026,
                    'session_name' => '2025-2026 Regular Session',
                    'session_title' => '2025-2026 Regular Session',
                    'dataset_date' => '2026-08-30',
                    'dataset_hash' => 'cd32ae927ce8',
                    'dataset_size' => 22607231,
                    'access_key' => 'ACCESS2192',
                ],
                [
                    'state_id' => 38,
                    'session_id' => 2035,
                    'year_start' => 2023,
                    'year_end' => 2024,
                    'session_name' => '2023-2024 Regular Session',
                    'dataset_date' => '2024-12-15',
                    'dataset_hash' => '10b70c948521',
                    'dataset_size' => 15383751,
                    'access_key' => 'ACCESS2035',
                ],
            ],
        ]);
    }

    public function test_lists_datasets_with_their_revision_markers(): void
    {
        $this->fakeLegiscan(['getDatasetList' => $this->datasetList()]);

        $datasets = $this->driver()->setStateContext('PA')->datasets();

        $this->assertCount(2, $datasets);

        $current = $datasets->forSession(2192);
        $this->assertSame('2025-2026 Regular Session', $current->sessionName);
        $this->assertSame('cd32ae927ce8', $current->hash);
        $this->assertSame(22607231, $current->size);
        $this->assertSame(2025, $current->yearStart);
        $this->assertSame(StateEnum::PA, $current->state);
        $this->assertSame('2026-08-30', $current->date?->format('Y-m-d'));
    }

    public function test_carries_the_access_key_only_the_listing_publishes(): void
    {
        $this->fakeLegiscan(['getDatasetList' => $this->datasetList()]);

        // The archive request needs this token, and it appears nowhere else, so
        // a dataset cannot be fetched without being listed first.
        $this->assertSame(
            'ACCESS2192',
            $this->driver()->setStateContext('PA')->datasets()->forSession(2192)->accessKey
        );
    }

    public function test_identifies_the_most_recently_rebuilt_archive(): void
    {
        $this->fakeLegiscan(['getDatasetList' => $this->datasetList()]);

        $this->assertSame(
            2192,
            $this->driver()->setStateContext('PA')->datasets()->latest()->sessionId
        );
    }

    public function test_reports_which_archives_changed_since_the_last_import(): void
    {
        $this->fakeLegiscan(['getDatasetList' => $this->datasetList()]);

        $datasets = $this->driver()->setStateContext('PA')->datasets();

        // An unchanged hash means a download would return what is already
        // stored, so skipping it saves the whole transfer.
        $changed = $datasets->changedSince([
            2192 => 'cd32ae927ce8',
            2035 => 'stale-hash',
        ]);

        $this->assertCount(1, $changed);
        $this->assertSame(2035, $changed->first()->sessionId);

        // A session never imported counts as changed.
        $this->assertCount(2, $datasets->changedSince([]));
    }

    public function test_requesting_an_unpublished_session_fails_clearly(): void
    {
        $this->fakeLegiscan(['getDatasetList' => $this->datasetList()]);

        $this->expectException(LegiscanException::class);
        $this->expectExceptionMessage('No dataset published for session [9999]');

        $this->driver()->setStateContext('PA')->dataset(9999);
    }

    public function test_clears_downloaded_dataset_archives(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legiscan-driver-clear-'.uniqid();
        $config = array_replace_recursive(config('lobbyist-legiscan'), [
            'dataset' => ['directory' => $directory],
        ]);

        mkdir($directory, 0775, true);
        file_put_contents($directory.DIRECTORY_SEPARATOR.'legiscan-PA-2192-abc.zip', 'archive');
        file_put_contents($directory.DIRECTORY_SEPARATOR.'legiscan-PA-2192-abc.json.part', 'partial');

        $removed = (new LegiscanDriver($config))->clearDatasetCache();

        $this->assertSame(2, $removed);
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'legiscan-PA-2192-abc.zip');

        rmdir($directory);
    }

    public function test_driver_advertises_the_dataset_capabilities(): void
    {
        $driver = $this->driver();

        $this->assertTrue($driver->supports(Capability::ListDatasets));
        $this->assertTrue($driver->supports(Capability::GetDataset));
    }
}
