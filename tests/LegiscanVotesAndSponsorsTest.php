<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Tests;

use WiserWebSolutions\Lobbyist\Enums\SponsorType;
use WiserWebSolutions\Lobbyist\Enums\VotePosition;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanDriver;
use WiserWebSolutions\Lobbyist\Legiscan\Support\LegiscanMapper;

/**
 * The getBill payload already contains roll call summaries and sponsors, so
 * exposing them costs nothing beyond the request that fetched the bill. These
 * tests hold that line, and cover the per-member roll call detail that makes a
 * legislator voting record reconstructable.
 */
class LegiscanVotesAndSponsorsTest extends TestCase
{
    private function driver(): LegiscanDriver
    {
        return new LegiscanDriver(config('lobbyist-legiscan'));
    }

    private function billPayload(): array
    {
        return [
            'bill_id' => 1234,
            'number' => 'HB1234',
            'state_id' => 38,
            'change_hash' => 'f00ba7',
            'votes' => [
                [
                    'roll_call_id' => 55,
                    'date' => '2025-03-04',
                    'desc' => 'House Final Passage',
                    'yea' => 120,
                    'nay' => 80,
                    'nv' => 2,
                    'absent' => 1,
                    'passed' => 1,
                    'chamber' => 'H',
                ],
            ],
            'sponsors' => [
                [
                    'people_id' => 9001,
                    'name' => 'Rep. Alpha',
                    'first_name' => 'Ada',
                    'last_name' => 'Alpha',
                    'party' => 'D',
                    'role' => 'Rep',
                    'district' => 'HD-001',
                    'sponsor_type_id' => 1,
                    'sponsor_order' => 1,
                ],
                [
                    'people_id' => 9002,
                    'name' => 'Rep. Beta',
                    'party' => 'R',
                    'role' => 'Rep',
                    'sponsor_type_id' => 2,
                    'sponsor_order' => 2,
                ],
            ],
        ];
    }

    public function test_maps_roll_call_summaries_embedded_in_a_bill(): void
    {
        $bill = LegiscanMapper::bill($this->billPayload());

        $this->assertCount(1, $bill->votes());

        $vote = $bill->votes()->first();
        $this->assertSame(55, $vote->id);
        $this->assertSame(120, $vote->yea);
        $this->assertSame(80, $vote->nay);
        $this->assertTrue($vote->passed);
        $this->assertSame('House Final Passage', $vote->description);

        // The embedded summary has no bill_id of its own, so it inherits the
        // enclosing bill; without this the vote could not be attributed.
        $this->assertSame(1234, $vote->billId);
    }

    public function test_embedded_roll_calls_have_no_member_breakdown(): void
    {
        $bill = LegiscanMapper::bill($this->billPayload());

        // Tallies only. Per-member detail needs a separate roll call lookup,
        // and pretending otherwise would silently lose votes.
        $this->assertTrue($bill->votes()->first()->positions()->isEmpty());
    }

    public function test_maps_sponsors_with_their_sponsorship_details(): void
    {
        $bill = LegiscanMapper::bill($this->billPayload());

        $this->assertCount(2, $bill->sponsors());

        $primary = $bill->sponsors()->first();
        $this->assertSame(9001, $primary->id);
        $this->assertSame('Rep. Alpha', $primary->name);
        $this->assertSame('HD-001', $primary->district);
        $this->assertSame(SponsorType::Primary, $primary->meta['sponsor_type']);
        $this->assertSame(1, $primary->meta['sponsor_order']);

        $this->assertSame(SponsorType::CoSponsor, $bill->sponsors()->last()->meta['sponsor_type']);
    }

    public function test_votes_for_bill_costs_a_single_request(): void
    {
        $this->fakeLegiscan([
            'getBill' => $this->okResponse(['bill' => $this->billPayload()]),
        ]);

        $votes = $this->driver()->setStateContext('PA')->votesForBill(1234);

        $this->assertCount(1, $votes);
        $this->assertSame(55, $votes->first()->id);

        // The whole point: roll calls ride along with the bill.
        $this->assertSame(['getBill'], $this->requestedOps());
    }

    public function test_a_roll_call_lookup_maps_every_member_vote(): void
    {
        $this->fakeLegiscan([
            'getRollCall' => $this->okResponse([
                'roll_call' => [
                    'roll_call_id' => 55,
                    'bill_id' => 1234,
                    'date' => '2025-03-04',
                    'desc' => 'House Final Passage',
                    'yea' => 2,
                    'nay' => 1,
                    'nv' => 1,
                    'passed' => 1,
                    'chamber' => 'H',
                    'votes' => [
                        ['people_id' => 9001, 'vote_id' => 1, 'vote_text' => 'Yea'],
                        ['people_id' => 9002, 'vote_id' => 1, 'vote_text' => 'Yea'],
                        ['people_id' => 9003, 'vote_id' => 2, 'vote_text' => 'Nay'],
                        ['people_id' => 9004, 'vote_id' => 3, 'vote_text' => 'NV'],
                    ],
                ],
            ]),
        ]);

        $vote = $this->driver()->setStateContext('PA')->vote(55);

        $this->assertCount(4, $vote->positions());
        $this->assertCount(2, $vote->positions()->yeas());
        $this->assertCount(1, $vote->positions()->nays());

        $cast = $vote->positions()->forLegislator(9003);
        $this->assertSame(VotePosition::Nay, $cast?->position);
        $this->assertSame(9003, $cast?->legislatorId);

        $this->assertSame(
            VotePosition::NotVoting,
            $vote->positions()->forLegislator(9004)?->position
        );
    }

    public function test_sponsored_bills_inverts_the_sponsor_index(): void
    {
        $this->fakeLegiscan([
            'getSponsoredList' => $this->okResponse([
                'sponsoredbills' => [
                    'sponsor' => ['people_id' => 9001, 'name' => 'Rep. Alpha'],
                    'bills' => [
                        ['bill_id' => 1234, 'number' => 'HB1234', 'state_id' => 38],
                        ['bill_id' => 5678, 'number' => 'HB5678', 'state_id' => 38],
                    ],
                ],
            ]),
        ]);

        $bills = $this->driver()->setStateContext('PA')->sponsoredBills(9001);

        $this->assertCount(2, $bills);
        $this->assertSame('HB1234', $bills->first()->number);
        $this->assertSame(['getSponsoredList'], $this->requestedOps());
    }

    public function test_sponsored_bills_rejects_a_non_numeric_identifier(): void
    {
        $this->expectExceptionMessage('Sponsor identifier must be numeric.');

        $this->driver()->setStateContext('PA')->sponsoredBills('rep-alpha');
    }
}
