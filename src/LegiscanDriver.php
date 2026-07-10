<?php

namespace WiserWebSolutions\Lobbyist\Legiscan;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use WiserWebSolutions\Lobbyist\Contracts\Providers\BillLookup;
use WiserWebSolutions\Lobbyist\Contracts\Providers\BillProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\RepresentativeLookup;
use WiserWebSolutions\Lobbyist\Contracts\Providers\RepresentativeProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\SessionProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\VoteLookup;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\BillCollection;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\LegislatorCollection;
use WiserWebSolutions\Lobbyist\Data\SessionCollection;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Legiscan\Exceptions\LegiscanException;
use WiserWebSolutions\Lobbyist\Legiscan\Support\LegiscanMapper;
use WiserWebSolutions\Lobbyist\Support\AbstractDriver;

/**
 * Default nationwide driver backed by the LegiScan API.
 *
 * LegiScan exposes a single endpoint where the operation is selected via the
 * `op` query parameter. It supports listing/looking up bills, looking up roll
 * calls and people, and listing sessions and session people. It does not offer
 * a cheap "list all votes" operation, so this driver implements {@see VoteLookup}
 * but not VoteProvider.
 */
class LegiscanDriver extends AbstractDriver implements
    SessionProvider,
    BillProvider,
    BillLookup,
    VoteLookup,
    RepresentativeProvider,
    RepresentativeLookup
{
    /** @var array{api_key: ?string, base_uri: ?string} */
    private array $endpoint;

    /** @var array{timeout: int, retry_times: int, retry_sleep_ms: int} */
    private array $request;

    /** @var array{enabled: bool, store: ?string, ttl: int} */
    private array $cache;

    /**
     * @param  array{endpoint: array, request: array, cache: array}  $config
     */
    public function __construct(array $config)
    {
        $this->endpoint = $config['endpoint'] ?? [];
        $this->request = $config['request'] ?? [];
        $this->cache = $config['cache'] ?? [];

        if (empty($this->endpoint['api_key'])) {
            throw LegiscanException::missingKey();
        }

        if (empty($this->endpoint['base_uri'])) {
            throw LegiscanException::missingBaseUri();
        }
    }

    // ---------------------------------------------------------------------
    // Public contract
    // ---------------------------------------------------------------------

    /**
     * Fluent alias for {@see setStateContext()}.
     *
     * LegiScan is the only driver with nationwide coverage, so it's the one
     * meaningfully re-scoped after being forced by name — e.g. to query it for
     * a state that also has a dedicated (single-state) driver installed:
     * `Lobbyist::driver('legiscan')->state('PA')->bills()`.
     */
    public function state(string $state): static
    {
        return $this->setStateContext($state);
    }

    public function sessions(): SessionCollection
    {
        $response = $this->getSessionList();

        return new SessionCollection(
            array_map(
                fn (array $session) => LegiscanMapper::session($session),
                $response['sessions'] ?? []
            )
        );
    }

    public function bills(): BillCollection
    {
        $response = $this->getMasterList();

        $bills = collect($response['masterlist'] ?? [])
            ->filter(fn ($row) => is_array($row) && isset($row['bill_id']))
            ->map(fn (array $row) => LegiscanMapper::bill($row))
            ->values()
            ->all();

        return new BillCollection($bills);
    }

    public function bill(string|int $identifier): Bill
    {
        $response = is_numeric($identifier)
            ? $this->fetchBillById((int) $identifier)
            : $this->fetchBillByNumber((string) $identifier);

        return LegiscanMapper::bill($response['bill']);
    }

    public function vote(string|int $identifier): Vote
    {
        if (! is_numeric($identifier)) {
            throw LegiscanException::apiError('Roll call identifier must be numeric.');
        }

        $response = $this->getRollCall((int) $identifier);

        return LegiscanMapper::vote($response['roll_call']);
    }

    public function representatives(): LegislatorCollection
    {
        $sessionId = $this->currentSessionId();

        $response = $this->call(
            operation: 'getSessionPeople',
            params: ['id' => $sessionId],
            ttl: 60 * 60 * 24,
        );

        $this->requireResponseKey($response, 'getSessionPeople', 'sessionpeople');

        $people = $response['sessionpeople']['people'] ?? [];

        return new LegislatorCollection(
            array_map(fn (array $person) => LegiscanMapper::legislator($person), $people)
        );
    }

    public function representative(string|int $identifier): Legislator
    {
        if (! is_numeric($identifier)) {
            throw LegiscanException::apiError('Person identifier must be numeric.');
        }

        $response = $this->call(operation: 'getPerson', params: ['id' => (int) $identifier], ttl: 60 * 60 * 24);
        $this->requireResponseKey($response, 'getPerson', 'person');

        return LegiscanMapper::legislator($response['person']);
    }

    // ---------------------------------------------------------------------
    // LegiScan operations
    // ---------------------------------------------------------------------

    /**
     * @see docs/LegiScan_API_User_Manual (getSessionList, Page 8)
     */
    private function getSessionList(): array
    {
        $response = $this->call(
            operation: 'getSessionList',
            params: ['state' => $this->stateContext ?? 'US'],
            ttl: 60 * 60 * 24,
        );

        return $this->requireResponseKey($response, 'getSessionList', 'sessions');
    }

    /**
     * @see docs/LegiScan_API_User_Manual (getMasterList, Page 9)
     */
    private function getMasterList(?int $sessionId = null): array
    {
        $params = $sessionId === null
            ? ['state' => $this->stateContext ?? 'US']
            : ['id' => $sessionId];

        $response = $this->call(operation: 'getMasterList', params: $params, ttl: 60 * 60);

        return $this->requireResponseKey($response, 'getMasterList', 'masterlist');
    }

    private function fetchBillById(int $billId): array
    {
        $response = $this->call(operation: 'getBill', params: ['id' => $billId], ttl: 60 * 60);

        return $this->requireResponseKey($response, 'getBill', 'bill');
    }

    private function fetchBillByNumber(string $billNumber): array
    {
        $response = $this->call(
            operation: 'getBill',
            params: [
                'state' => $this->requireStateContext('State context is required to query by bill number.'),
                'bill' => $billNumber,
            ],
            ttl: 60 * 60,
        );

        return $this->requireResponseKey($response, 'getBill', 'bill');
    }

    private function getRollCall(int $rollCallId): array
    {
        $response = $this->call(operation: 'getRollCall', params: ['id' => $rollCallId], ttl: 60 * 60);

        return $this->requireResponseKey($response, 'getRollCall', 'roll_call');
    }

    /**
     * Resolve the session id to use for people lookups: the most recent
     * non-prior session for the active state context.
     */
    private function currentSessionId(): int
    {
        $sessions = $this->sessions();

        $current = $sessions->active()->first() ?? $sessions->first();

        if ($current === null) {
            throw LegiscanException::apiError(
                'No sessions available for state ['.($this->stateContext ?? 'US').'].'
            );
        }

        return $current->id;
    }

    // ---------------------------------------------------------------------
    // HTTP + caching
    // ---------------------------------------------------------------------

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->endpoint['base_uri'])
            ->timeout($this->request['timeout'] ?? 30)
            ->retry($this->request['retry_times'] ?? 2, $this->request['retry_sleep_ms'] ?? 200);
    }

    protected function call(string $operation, array $params = [], ?int $ttl = null): array
    {
        $query = array_filter([
            'key' => $this->endpoint['api_key'],
            'op' => $operation,
        ] + $params, fn ($v) => $v !== null && $v !== '');

        if (empty($this->cache['enabled'])) {
            return $this->send($query);
        }

        $cacheKey = 'lobbyist-legiscan:'.md5($this->endpoint['base_uri'].'|'.http_build_query($query));

        return Cache::store($this->cache['store'] ?? null)
            ->remember($cacheKey, $ttl ?? ($this->cache['ttl'] ?? 3600), fn () => $this->send($query));
    }

    protected function send(array $query): array
    {
        $url = $this->attemptedUrl($query);

        try {
            $response = $this->http()->get('/', $query);
        } catch (RequestException $e) {
            throw LegiscanException::requestFailed($url, $e->response?->status(), 'The LegiScan API returned an error response.', $e);
        } catch (ConnectionException $e) {
            throw LegiscanException::requestFailed($url, null, 'Could not connect to the LegiScan API.', $e);
        }

        if ($response->failed()) {
            throw LegiscanException::requestFailed($url, $response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw LegiscanException::apiError("Invalid (non-JSON) response from [{$url}].");
        }

        if (($json['status'] ?? null) !== 'OK') {
            $message = $json['alert']['message'] ?? 'Unknown error';
            throw LegiscanException::apiError("{$message} (request: [{$url}]).");
        }

        return $json;
    }

    /**
     * The request URL for diagnostics, with the API key redacted so it is safe
     * to surface in exceptions and logs.
     */
    private function attemptedUrl(array $query): string
    {
        if (isset($query['key'])) {
            $query['key'] = 'REDACTED';
        }

        return rtrim((string) $this->endpoint['base_uri'], '/').'/?'.http_build_query($query);
    }

    private function requireResponseKey(array $response, string $operation, string $key): array
    {
        if (! isset($response[$key])) {
            throw LegiscanException::apiError("Invalid response structure for {$operation}");
        }

        return $response;
    }

    private function requireStateContext(string $message): string
    {
        if (! $this->stateContext) {
            throw LegiscanException::apiError($message);
        }

        return $this->stateContext;
    }
}
