<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Data;

/**
 * A snapshot of LegiScan's metered monthly query quota, as tracked locally by
 * this package.
 *
 * LegiScan does not report usage back in its API responses, so `used` counts
 * only the requests this driver has made — not other keys, other
 * applications, or anything querying LegiScan outside of it.
 */
final class Quota
{
    public function __construct(
        public readonly int $used,
        public readonly int $limit,
        public readonly \DateTimeImmutable $resetsAt,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    public function exceeded(): bool
    {
        return $this->used >= $this->limit;
    }
}
