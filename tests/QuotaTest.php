<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Tests;

use Illuminate\Support\Carbon;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanDriver;

/**
 * LegiScan doesn't report quota usage back in its responses, so this package
 * counts its own requests. The behavior worth pinning down is what actually
 * counts as a query (a real HTTP call, not a cache hit) and that the counter
 * rolls over at the UTC month boundary rather than accumulating forever.
 */
class QuotaTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function driver(): LegiscanDriver
    {
        return new LegiscanDriver(config('lobbyist-legiscan'));
    }

    public function test_quota_starts_at_zero(): void
    {
        $quota = $this->driver()->quota();

        $this->assertSame(0, $quota->used);
        $this->assertSame(30000, $quota->limit);
        $this->assertSame(30000, $quota->remaining());
        $this->assertFalse($quota->exceeded());
    }

    public function test_a_live_request_increments_the_quota(): void
    {
        $this->fakeLegiscan([
            'getSessionList' => $this->okResponse(['sessions' => []]),
        ]);

        $driver = $this->driver();
        $driver->setStateContext('PA')->sessions();

        $this->assertSame(1, $driver->quota()->used);

        $driver->setStateContext('PA')->sessions();

        $this->assertSame(2, $this->driver()->quota()->used);
    }

    public function test_a_cache_hit_does_not_increment_the_quota(): void
    {
        $config = array_replace_recursive(config('lobbyist-legiscan'), [
            'cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 3600],
        ]);

        $this->fakeLegiscan([
            'getSessionList' => $this->okResponse(['sessions' => []]),
        ]);

        $driver = new LegiscanDriver($config);
        $driver->setStateContext('PA')->sessions();
        $driver->setStateContext('PA')->sessions();

        $this->assertSame(1, $driver->quota()->used);
    }

    public function test_quota_counter_persists_across_driver_instances(): void
    {
        $this->fakeLegiscan([
            'getSessionList' => $this->okResponse(['sessions' => []]),
        ]);

        $this->driver()->setStateContext('PA')->sessions();
        $this->driver()->setStateContext('PA')->sessions();

        $this->assertSame(2, $this->driver()->quota()->used);
    }

    public function test_quota_resets_at_the_start_of_a_new_utc_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 23:59:00', 'UTC'));

        $this->fakeLegiscan([
            'getSessionList' => $this->okResponse(['sessions' => []]),
        ]);

        $this->driver()->setStateContext('PA')->sessions();
        $this->assertSame(1, $this->driver()->quota()->used);

        Carbon::setTestNow(Carbon::parse('2026-10-01 00:00:01', 'UTC'));

        $this->assertSame(0, $this->driver()->quota()->used);

        $this->driver()->setStateContext('PA')->sessions();
        $this->assertSame(1, $this->driver()->quota()->used);
    }

    public function test_quota_resets_at_field_reflects_the_first_of_next_month_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00', 'UTC'));

        $resetsAt = $this->driver()->quota()->resetsAt;

        $this->assertSame('2026-10-01 00:00:00', $resetsAt->format('Y-m-d H:i:s'));
    }

    public function test_quota_tracking_can_be_disabled(): void
    {
        $config = array_replace_recursive(config('lobbyist-legiscan'), [
            'quota' => ['enabled' => false],
        ]);

        $this->fakeLegiscan([
            'getSessionList' => $this->okResponse(['sessions' => []]),
        ]);

        $driver = new LegiscanDriver($config);
        $driver->setStateContext('PA')->sessions();
        $driver->setStateContext('PA')->sessions();

        $this->assertSame(0, $driver->quota()->used);
    }
}
