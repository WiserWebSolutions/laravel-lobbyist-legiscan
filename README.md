# Laravel Lobbyist — LegiScan Driver

The default nationwide driver for [`wiserwebsolutions/laravel-lobbyist`](https://github.com/wiserwebsolutions/laravel-lobbyist),
backed by the [LegiScan API](https://legiscan.com/legiscan). It provides
legislative data for all 50 states and the federal government and registers
itself with the Lobbyist manager under the `legiscan` name (the default driver).

## Installation

```bash
composer require wiserwebsolutions/laravel-lobbyist-legiscan
```

The package auto-registers. Publish its config if you want to tune requests or
caching:

```bash
php artisan vendor:publish --tag=lobbyist-legiscan-config
```

## Configuration

Set your [LegiScan API key](https://legiscan.com/user/register) in `.env`:

```dotenv
LEGISCAN_API_KEY=your-key-here

# optional
LEGISCAN_BASE_URI=https://api.legiscan.com/
LEGISCAN_TIMEOUT=30
LEGISCAN_RETRY_TIMES=2
LEGISCAN_CACHE_ENABLED=true
LEGISCAN_CACHE_STORE=
LEGISCAN_CACHE_TTL=3600
```

Responses are cached per operation (sessions/people for a day, bills/roll calls
for an hour) via the configured cache store.

## Usage

```php
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

$driver = Lobbyist::state('CA'); // LegiscanDriver, scoped to California

$driver->sessions();          // SessionCollection
$driver->bills();             // BillCollection (current CA session master list)
$driver->bill(1132030);       // Bill (by LegiScan bill_id)
$driver->bill('AB1');         // Bill (by number — requires state context)
$driver->vote(55);            // Vote (by roll_call_id)
$driver->legislators();       // LegislatorCollection (current session people, both chambers)
$driver->representatives();   // LegislatorCollection (House only)
$driver->senators();           // LegislatorCollection (Senate only)
$driver->representative(9001); // Legislator (by people_id, either chamber)
```

### Supported capabilities

LegiScan supports every capability **except** `ListVotes` — the API has no cheap
"all votes for a state" operation (roll calls are reached per bill or by id), so
this driver implements `VoteLookup` (`vote($id)`) but not `VoteProvider`.

## Bulk Datasets

Beyond per-record calls, LegiScan publishes a full session archive (every bill,
roll call with per-member positions, and legislator) as a single download —
dramatically cheaper against a metered key than importing a session bill by
bill:

```php
$datasets = $driver->datasets();               // DatasetCollection for the state
$latest = $datasets->latest();                 // most recently rebuilt archive
$changed = $datasets->changedSince($lastHashes); // only sessions whose hash moved

$archive = $driver->dataset($latest);          // downloads and opens the archive
$archive->bills()->each(fn ($bill) => ...);    // LazyCollection, one session at a time
$archive->votes();
$archive->people();
$archive->delete();                            // discard the local copy when done
```

### Reusing downloaded archives

Archives run to tens of megabytes and are streamed to disk rather than
buffered or response-cached. During local development — where the importing
database gets wiped and rebuilt far more often than LegiScan actually
republishes a session — it's often wasteful to re-download the same archive on
every run. Enable `reuse_existing` to skip the download when a file from a
previous run is already on disk for that session and dataset hash:

```dotenv
LEGISCAN_DATASET_DIR=
LEGISCAN_DATASET_TIMEOUT=600
LEGISCAN_DATASET_REUSE_EXISTING=true
```

A cached archive is only ever reused for the exact hash it was downloaded
for — once LegiScan republishes the session under a new hash, it downloads
fresh automatically.

To clear the local cache entirely (e.g. after wiping your database), call:

```php
$driver->clearDatasetCache(); // int — files removed
```

## Testing

Tests use `Http::fake()` and never hit the network:

```bash
composer install
vendor/bin/phpunit
```

## License

MIT © Daniel Wiser
