<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Tests;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\BillText;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;
use WiserWebSolutions\Lobbyist\Legiscan\Exceptions\LegiscanException;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanDriver;

class LegiscanDriverTest extends TestCase
{
    private function driver(): LegiscanDriver
    {
        return new LegiscanDriver(config('lobbyist-legiscan'));
    }

    public function test_list_sessions_maps_to_dtos(): void
    {
        $this->fakeLegiscan([
            'getSessionList' => $this->okResponse([
                'sessions' => [
                    ['session_id' => 1791, 'state_id' => 38, 'session_title' => '2023-2024 Regular Session'],
                ],
            ]),
        ]);

        $sessions = $this->driver()->setStateContext('PA')->sessions();

        $this->assertCount(1, $sessions);
        $this->assertSame(1791, $sessions->first()->id);
        $this->assertSame(StateEnum::PA, $sessions->first()->state);
    }

    public function test_session_returns_the_most_recent_active_session(): void
    {
        $this->fakeLegiscan([
            'getSessionList' => $this->okResponse([
                'sessions' => [
                    ['session_id' => 1791, 'state_id' => 38, 'prior' => 1, 'session_title' => 'Old Session'],
                    ['session_id' => 2000, 'state_id' => 38, 'prior' => 0, 'sine_die' => 0, 'session_title' => 'Current Session'],
                ],
            ]),
        ]);

        $session = $this->driver()->setStateContext('PA')->session();

        $this->assertSame(2000, $session->id);
        $this->assertSame('Current Session', $session->title);
    }

    public function test_session_throws_when_no_sessions_are_available(): void
    {
        $this->fakeLegiscan([
            'getSessionList' => $this->okResponse(['sessions' => []]),
        ]);

        $this->expectExceptionMessageMatches('/No sessions available/');

        $this->driver()->setStateContext('PA')->session();
    }

    public function test_list_bills_maps_and_ignores_non_bill_rows(): void
    {
        $this->fakeLegiscan([
            'getMasterList' => $this->okResponse([
                'masterlist' => [
                    'session' => ['session_id' => 1791, 'session_name' => 'x'], // must be ignored
                    '0' => ['bill_id' => 1, 'number' => 'HB1', 'title' => 'A'],
                    '1' => ['bill_id' => 2, 'number' => 'SB2', 'title' => 'B'],
                ],
            ]),
        ]);

        $bills = $this->driver()->setStateContext('PA')->bills();

        $this->assertCount(2, $bills);
        $this->assertContainsOnlyInstancesOf(Bill::class, $bills);
        $this->assertSame(Chamber::House, $bills->first()->chamber);
    }

    public function test_state_is_a_fluent_alias_for_set_state_context(): void
    {
        $unscoped = $this->driver();

        $scoped = $unscoped->state('pa');

        $this->assertSame($unscoped, $scoped);
        $this->assertSame('PA', $scoped->stateContext());
    }

    public function test_get_bill_by_id(): void
    {
        $this->fakeLegiscan([
            'getBill' => $this->okResponse([
                'bill' => ['bill_id' => 1132030, 'number' => 'AB1', 'title' => 'Youth athletics', 'state_id' => 5],
            ]),
        ]);

        $bill = $this->driver()->bill(1132030);

        $this->assertInstanceOf(Bill::class, $bill);
        $this->assertSame('AB1', $bill->number);
    }

    public function test_get_bill_by_number_requires_state_context(): void
    {
        $this->fakeLegiscan([]);

        $this->expectException(LegiscanException::class);
        $this->expectExceptionMessageMatches('/State context is required/');

        $this->driver()->bill('HB1');
    }

    public function test_bill_text_history_maps_every_version_without_content(): void
    {
        $this->fakeLegiscan([
            'getBill' => $this->okResponse([
                'bill' => [
                    'bill_id' => 1132030,
                    'number' => 'AB1',
                    'texts' => [
                        ['doc_id' => 2029, 'type' => 'Introduced', 'date' => '2018-01-05'],
                        ['doc_id' => 2030, 'type' => 'Enrolled', 'date' => '2018-06-01'],
                    ],
                ],
            ]),
        ]);

        $history = $this->driver()->billTextHistory(1132030);

        $this->assertCount(2, $history);
        $this->assertContainsOnlyInstancesOf(BillText::class, $history);
        $this->assertSame(1132030, $history->first()->billId);
        $this->assertNull($history->first()->content);
    }

    public function test_bill_text_fetches_content_for_the_latest_version(): void
    {
        $this->fakeLegiscan([
            'getBill' => $this->okResponse([
                'bill' => [
                    'bill_id' => 1132030,
                    'texts' => [
                        ['doc_id' => 2029, 'type' => 'Introduced', 'date' => '2018-01-05'],
                        ['doc_id' => 2030, 'type' => 'Enrolled', 'date' => '2018-06-01'],
                    ],
                ],
            ]),
            'getBillText' => $this->okResponse([
                'text' => [
                    'doc_id' => 2030,
                    'bill_id' => 1132030,
                    'type' => 'Enrolled',
                    'doc' => base64_encode('Enrolled bill text'),
                ],
            ]),
        ]);

        $text = $this->driver()->billText(1132030);

        $this->assertSame(2030, $text->id);
        $this->assertSame('Enrolled bill text', $text->content);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['op'] ?? null) === 'getBillText' && ($query['id'] ?? null) === '2030';
        });
    }

    public function test_bill_text_throws_when_no_versions_are_available(): void
    {
        $this->fakeLegiscan([
            'getBill' => $this->okResponse([
                'bill' => ['bill_id' => 1132030, 'texts' => []],
            ]),
        ]);

        $this->expectException(LegiscanException::class);
        $this->expectExceptionMessageMatches('/No text versions available/');

        $this->driver()->billText(1132030);
    }

    public function test_get_vote_maps_roll_call(): void
    {
        $this->fakeLegiscan([
            'getRollCall' => $this->okResponse([
                'roll_call' => ['roll_call_id' => 55, 'bill_id' => 10, 'chamber' => 'H', 'yea' => 60, 'nay' => 15, 'passed' => 1],
            ]),
        ]);

        $vote = $this->driver()->vote(55);

        $this->assertInstanceOf(Vote::class, $vote);
        $this->assertSame(60, $vote->yea);
        $this->assertTrue($vote->passed);
    }

    public function test_get_vote_rejects_non_numeric(): void
    {
        $this->fakeLegiscan([]);

        $this->expectException(LegiscanException::class);

        $this->driver()->vote('abc');
    }

    public function test_list_representatives_resolves_session_then_people(): void
    {
        $this->fakeLegiscan([
            'getSessionList' => $this->okResponse([
                'sessions' => [
                    ['session_id' => 2000, 'state_id' => 38, 'prior' => 0, 'sine_die' => 0],
                ],
            ]),
            'getSessionPeople' => $this->okResponse([
                'sessionpeople' => [
                    'people' => [
                        ['people_id' => 1, 'name' => 'Jane Doe', 'party' => 'D', 'role' => 'Rep'],
                        ['people_id' => 2, 'name' => 'John Roe', 'party' => 'R', 'role' => 'Sen'],
                    ],
                ],
            ]),
        ]);

        $reps = $this->driver()->setStateContext('PA')->representatives();

        $this->assertCount(2, $reps);
        $this->assertContainsOnlyInstancesOf(Legislator::class, $reps);
        $this->assertSame('Jane Doe', $reps->first()->name);
    }

    public function test_get_representative_maps_person(): void
    {
        $this->fakeLegiscan([
            'getPerson' => $this->okResponse([
                'person' => ['people_id' => 9, 'name' => 'Jane Doe', 'party' => 'D', 'role' => 'Rep', 'state_id' => 38],
            ]),
        ]);

        $rep = $this->driver()->representative(9);

        $this->assertSame('Jane Doe', $rep->name);
        $this->assertSame(StateEnum::PA, $rep->state);
    }

    public function test_non_ok_status_throws(): void
    {
        $this->fakeLegiscan([
            'getSessionList' => ['status' => 'ERROR', 'alert' => ['message' => 'Invalid API Key']],
        ]);

        $this->expectException(LegiscanException::class);
        $this->expectExceptionMessageMatches('/Invalid API Key/');

        $this->driver()->sessions();
    }

    public function test_http_failure_throws_with_redacted_url(): void
    {
        Http::fake([
            'api.legiscan.test/*' => Http::response('server error', 500),
        ]);

        try {
            $this->driver()->sessions();
            $this->fail('Expected a LegiscanException.');
        } catch (LegiscanException $e) {
            $this->assertStringContainsString('api.legiscan.test', $e->getMessage());
            $this->assertStringContainsString('op=getSessionList', $e->getMessage());
            $this->assertStringContainsString('key=REDACTED', $e->getMessage());
            $this->assertStringNotContainsString('test-key', $e->getMessage());
        }
    }

    public function test_missing_api_key_throws_on_construct(): void
    {
        config()->set('lobbyist-legiscan.endpoint.api_key', null);

        $this->expectException(LegiscanException::class);
        $this->expectExceptionMessageMatches('/API key is missing/');

        $this->driver();
    }

    public function test_caching_avoids_duplicate_http_calls(): void
    {
        config()->set('lobbyist-legiscan.cache.enabled', true);

        $this->fakeLegiscan([
            'getSessionList' => $this->okResponse([
                'sessions' => [['session_id' => 1, 'state_id' => 38]],
            ]),
        ]);

        $driver = $this->driver()->setStateContext('PA');
        $driver->sessions();
        $driver->sessions();

        Http::assertSentCount(1);
    }

    public function test_query_includes_key_and_op(): void
    {
        $this->fakeLegiscan([
            'getSessionList' => $this->okResponse(['sessions' => []]),
        ]);

        $this->driver()->setStateContext('PA')->sessions();

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['op'] ?? null) === 'getSessionList'
                && ($query['key'] ?? null) === 'test-key'
                && ($query['state'] ?? null) === 'PA';
        });
    }
}
