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
$driver->representatives();   // LegislatorCollection (current session people)
$driver->representative(9001); // Legislator (by people_id)
```

### Supported capabilities

LegiScan supports every capability **except** `ListVotes` — the API has no cheap
"all votes for a state" operation (roll calls are reached per bill or by id), so
this driver implements `VoteLookup` (`vote($id)`) but not `VoteProvider`.

## Testing

Tests use `Http::fake()` and never hit the network:

```bash
composer install
vendor/bin/phpunit
```

## License

MIT © Daniel Wiser
