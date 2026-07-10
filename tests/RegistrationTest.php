<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Tests;

use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanDriver;
use WiserWebSolutions\Lobbyist\Testing\AssertsDriverContract;

class RegistrationTest extends TestCase
{
    use AssertsDriverContract;

    public function test_driver_is_registered_as_legiscan(): void
    {
        $driver = Lobbyist::driver('legiscan');

        $this->assertInstanceOf(LegiscanDriver::class, $driver);
    }

    public function test_state_falls_back_to_legiscan_default(): void
    {
        $driver = Lobbyist::state('CA');

        $this->assertInstanceOf(LegiscanDriver::class, $driver);
        $this->assertSame('CA', $driver->stateContext());
    }

    public function test_driver_honours_contract(): void
    {
        $driver = new LegiscanDriver(config('lobbyist-legiscan'));

        $this->assertDriverContract($driver);

        // LegiScan supports lookups but not cheap vote listing.
        $this->assertTrue($driver->supports(Capability::GetBill));
        $this->assertTrue($driver->supports(Capability::ListBills));
        $this->assertTrue($driver->supports(Capability::GetVote));
        $this->assertTrue($driver->supports(Capability::ListRepresentatives));
        $this->assertTrue($driver->supports(Capability::GetBillText));
        $this->assertTrue($driver->supports(Capability::ListBillTextHistory));
        $this->assertFalse($driver->supports(Capability::ListVotes));
    }
}
