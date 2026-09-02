<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Tests;

use WiserWebSolutions\Lobbyist\Legiscan\Support\LegiscanMapper;

class LastActionTest extends TestCase
{
    public function test_it_derives_the_last_action_from_history(): void
    {
        // getBill and the dataset archives publish a history array and no
        // last_action fields at all -- every bill in a real PA session arrived
        // that way, so without this the last action is simply lost.
        $bill = LegiscanMapper::bill([
            'bill_id' => 1,
            'bill_number' => 'HB1431',
            'state' => 'PA',
            'history' => [
                ['date' => '2025-05-08', 'action' => 'Referred to Game & Fisheries'],
                ['date' => '2025-06-11', 'action' => 'Third consideration and final passage'],
                ['date' => '2025-07-09', 'action' => 'Approved by the Governor'],
            ],
        ]);

        $this->assertSame('Approved by the Governor', $bill->lastAction);
        $this->assertSame('2025-07-09', $bill->lastActionDate?->toDateString());
    }

    public function test_an_explicit_last_action_still_wins(): void
    {
        // getMasterList publishes these outright, and the source's own answer
        // should not be second-guessed.
        $bill = LegiscanMapper::bill([
            'bill_id' => 1,
            'bill_number' => 'HB1',
            'state' => 'PA',
            'last_action' => 'Signed by the Governor',
            'last_action_date' => '2025-08-01',
            'history' => [
                ['date' => '2025-07-09', 'action' => 'Approved by the Governor'],
            ],
        ]);

        $this->assertSame('Signed by the Governor', $bill->lastAction);
        $this->assertSame('2025-08-01', $bill->lastActionDate?->toDateString());
    }

    public function test_an_out_of_order_history_still_reports_the_latest(): void
    {
        $bill = LegiscanMapper::bill([
            'bill_id' => 1,
            'bill_number' => 'HB1',
            'state' => 'PA',
            'history' => [
                ['date' => '2025-07-09', 'action' => 'Approved by the Governor'],
                ['date' => '2025-05-08', 'action' => 'Referred to committee'],
            ],
        ]);

        $this->assertSame('Approved by the Governor', $bill->lastAction);
    }

    public function test_a_tie_on_date_takes_the_later_entry(): void
    {
        // PA records several actions a day, in order, sharing one date.
        $bill = LegiscanMapper::bill([
            'bill_id' => 1,
            'bill_number' => 'HB1',
            'state' => 'PA',
            'history' => [
                ['date' => '2025-06-30', 'action' => 'Signed in House'],
                ['date' => '2025-06-30', 'action' => 'Signed in Senate'],
            ],
        ]);

        $this->assertSame('Signed in Senate', $bill->lastAction);
    }

    public function test_a_bill_with_no_history_reports_nothing(): void
    {
        $bill = LegiscanMapper::bill([
            'bill_id' => 1,
            'bill_number' => 'HB1',
            'state' => 'PA',
        ]);

        $this->assertSame('', $bill->lastAction);
        $this->assertNull($bill->lastActionDate);
    }

    public function test_a_history_entry_without_a_date_is_not_treated_as_current(): void
    {
        $bill = LegiscanMapper::bill([
            'bill_id' => 1,
            'bill_number' => 'HB1',
            'state' => 'PA',
            'history' => [
                ['date' => '2025-07-09', 'action' => 'Approved by the Governor'],
                ['action' => 'Undated note'],
            ],
        ]);

        $this->assertSame('Approved by the Governor', $bill->lastAction);
        $this->assertSame('2025-07-09', $bill->lastActionDate?->toDateString());
    }
}
