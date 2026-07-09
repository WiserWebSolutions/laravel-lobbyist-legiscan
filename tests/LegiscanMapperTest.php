<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Tests;

use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\Party;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;
use WiserWebSolutions\Lobbyist\Legiscan\Support\LegiscanMapper;

class LegiscanMapperTest extends TestCase
{
    public function test_maps_session(): void
    {
        $session = LegiscanMapper::session([
            'session_id' => 1791,
            'state_id' => 5,
            'session_title' => '2021-2022 Regular Session',
            'session_name' => '2021-2022 Session',
        ]);

        $this->assertSame(1791, $session->id);
        $this->assertSame(StateEnum::CA, $session->state);
        $this->assertSame('2021-2022 Regular Session', $session->title);
    }

    public function test_maps_bill_and_infers_state(): void
    {
        $bill = LegiscanMapper::bill([
            'bill_id' => 1132030,
            'number' => 'AB1',
            'title' => 'Youth athletics',
            'state_id' => 5,
            'status_date' => '2018-12-03',
            'last_action_date' => '2018-12-04',
            'url' => 'https://legiscan.com/CA/bill/AB1/2019',
        ]);

        $this->assertSame(1132030, $bill->id);
        $this->assertSame('AB1', $bill->number);
        $this->assertSame(StateEnum::CA, $bill->state);
        $this->assertSame('2018-12-04', $bill->lastActionDate?->format('Y-m-d'));
    }

    public function test_maps_vote(): void
    {
        $vote = LegiscanMapper::vote([
            'roll_call_id' => 55,
            'bill_id' => 10,
            'chamber' => 'H',
            'desc' => 'Third Reading',
            'yea' => 60,
            'nay' => 15,
            'passed' => 1,
        ]);

        $this->assertSame(55, $vote->id);
        $this->assertSame(10, $vote->billId);
        $this->assertSame(Chamber::House, $vote->chamber);
        $this->assertSame(60, $vote->yea);
        $this->assertTrue($vote->passed);
    }

    public function test_maps_legislator(): void
    {
        $legislator = LegiscanMapper::legislator([
            'people_id' => 9001,
            'name' => 'Jane Doe',
            'party' => 'D',
            'role' => 'Rep',
            'district' => 'HD-042',
            'state_id' => 38,
        ]);

        $this->assertSame(9001, $legislator->id);
        $this->assertSame('Jane Doe', $legislator->name);
        $this->assertSame(Party::Democrat, $legislator->party);
        $this->assertSame(Chamber::House, $legislator->chamber);
        $this->assertSame(StateEnum::PA, $legislator->state);
    }
}
