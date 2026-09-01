<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Tests;

use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanDriver;

/**
 * LegiScan meters access by monthly query count, and bill detail costs one
 * query per bill. Change detection is therefore the difference between a sync
 * that fits the budget and one that exhausts the key, so these tests pin the
 * behaviour that keeps it cheap.
 */
class LegiscanChangeDetectionTest extends TestCase
{
    private function driver(): LegiscanDriver
    {
        return new LegiscanDriver(config('lobbyist-legiscan'));
    }

    public function test_bill_changes_uses_the_raw_master_list(): void
    {
        $this->fakeLegiscan([
            'getMasterListRaw' => $this->okResponse([
                'masterlist' => [
                    'session' => ['session_id' => 2000],
                    '0' => ['bill_id' => 1, 'number' => 'HB1', 'change_hash' => 'aaa'],
                    '1' => ['bill_id' => 2, 'number' => 'SB2', 'change_hash' => 'bbb'],
                ],
            ]),
        ]);

        $bills = $this->driver()->setStateContext('PA')->billChanges();

        // getMasterList would also have produced bills here; the point is that
        // the cheap operation is the one that gets called.
        $this->assertSame(['getMasterListRaw'], $this->requestedOps());
        $this->assertCount(2, $bills);
        $this->assertSame('aaa', $bills->first()->changeHash);
        $this->assertSame('HB1', $bills->first()->number);
    }

    public function test_bill_changes_skips_the_session_envelope_row(): void
    {
        $this->fakeLegiscan([
            'getMasterListRaw' => $this->okResponse([
                'masterlist' => [
                    // LegiScan mixes a session descriptor in with the bills; it
                    // has no bill_id and must not become a phantom bill.
                    'session' => ['session_id' => 2000, 'session_name' => '2023-2024'],
                    '0' => ['bill_id' => 1, 'number' => 'HB1', 'change_hash' => 'aaa'],
                ],
            ]),
        ]);

        $bills = $this->driver()->setStateContext('PA')->billChanges();

        $this->assertCount(1, $bills);
        $this->assertSame(1, $bills->first()->id);
    }

    public function test_a_bill_carries_its_change_hash(): void
    {
        $this->fakeLegiscan([
            'getBill' => $this->okResponse([
                'bill' => [
                    'bill_id' => 1234,
                    'number' => 'HB1234',
                    'state_id' => 38,
                    'change_hash' => 'f00ba7',
                ],
            ]),
        ]);

        $bill = $this->driver()->setStateContext('PA')->bill(1234);

        $this->assertSame('f00ba7', $bill->changeHash);
    }

    public function test_driver_advertises_the_new_capabilities(): void
    {
        $driver = $this->driver();

        $this->assertTrue($driver->supports(Capability::ListBillChanges));
        $this->assertTrue($driver->supports(Capability::ListBillVotes));
        $this->assertTrue($driver->supports(Capability::ListSponsoredBills));

        // Still no state-wide roll call listing, which is why the bill-scoped
        // vote provider exists in the first place.
        $this->assertFalse($driver->supports(Capability::ListVotes));
    }
}
