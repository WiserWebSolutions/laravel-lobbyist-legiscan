<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Support;

use Illuminate\Support\Facades\Cache;
use WiserWebSolutions\Lobbyist\Legiscan\Data\Quota;

/**
 * Tracks LegiScan's monthly query quota across requests.
 *
 * LegiScan doesn't expose remaining-quota information in its responses, so
 * this package has to count queries itself. The counter is stored alongside
 * the UTC calendar month it was accumulated for; a request made in a new
 * month is compared against the stored month and, finding it stale, starts
 * over at zero. That comparison is what gives the quota its "resets at
 * midnight UTC on the 1st" behavior, rather than a cache TTL, which a given
 * store may not expire at exactly the right instant.
 *
 * Counting happens once per driver-level API call, not once per underlying
 * HTTP request — a request retried by the HTTP client after a transient
 * failure is still counted only once here, even though LegiScan itself would
 * have received more than one query for it.
 */
class QuotaTracker
{
    public function __construct(
        private readonly bool $enabled,
        private readonly ?string $store,
        private readonly string $cacheKey,
        private readonly int $limit,
    ) {}

    public function increment(int $by = 1): Quota
    {
        if (! $this->enabled) {
            return $this->toQuota($this->freshState());
        }

        $state = $this->currentState();
        $state['count'] += $by;
        $this->cache()->put($this->cacheKey, $state, now('UTC')->addDays(35));

        return $this->toQuota($state);
    }

    public function current(): Quota
    {
        return $this->toQuota($this->enabled ? $this->currentState() : $this->freshState());
    }

    /**
     * @return array{period: string, count: int}
     */
    private function currentState(): array
    {
        $stored = $this->cache()->get($this->cacheKey);
        $period = $this->period();

        if (! is_array($stored) || ($stored['period'] ?? null) !== $period) {
            return ['period' => $period, 'count' => 0];
        }

        return ['period' => $period, 'count' => (int) ($stored['count'] ?? 0)];
    }

    /**
     * @return array{period: string, count: int}
     */
    private function freshState(): array
    {
        return ['period' => $this->period(), 'count' => 0];
    }

    /**
     * @param  array{period: string, count: int}  $state
     */
    private function toQuota(array $state): Quota
    {
        return new Quota(used: $state['count'], limit: $this->limit, resetsAt: $this->periodEnd());
    }

    /**
     * The UTC calendar month the counter belongs to, e.g. "2026-09".
     */
    private function period(): string
    {
        return now('UTC')->format('Y-m');
    }

    private function periodEnd(): \DateTimeImmutable
    {
        return now('UTC')->startOfMonth()->addMonthNoOverflow()->toDateTimeImmutable();
    }

    private function cache()
    {
        return Cache::store($this->store);
    }
}
