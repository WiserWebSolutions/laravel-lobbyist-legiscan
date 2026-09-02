<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Tests;

use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanDriver;

class BillTextVersionTest extends TestCase
{
    private function driver(): LegiscanDriver
    {
        return new LegiscanDriver(config('lobbyist-legiscan'));
    }

    public function test_it_fetches_one_version_in_a_single_request(): void
    {
        $this->fakeLegiscan([
            'getBillText' => $this->okResponse([
                'text' => [
                    'doc_id' => 12345,
                    'bill_id' => 999,
                    'type' => 'Amended PN 1837',
                    'mime' => 'application/pdf',
                    'date' => '2025-06-10',
                    'doc' => base64_encode('the document bytes'),
                ],
            ]),
        ]);

        $text = $this->driver()->billTextVersion(12345);

        $this->assertSame(12345, $text->id);
        $this->assertSame('Amended PN 1837', $text->type);

        // The point of the capability: reaching the same document through
        // billText() resolves the bill first, which is a second billed request.
        $this->assertSame(['getBillText'], $this->requestedOps());
    }

    public function test_it_asks_for_the_version_it_was_given(): void
    {
        $this->fakeLegiscan([
            'getBillText' => $this->okResponse([
                'text' => ['doc_id' => 777, 'mime' => 'application/pdf'],
            ]),
        ]);

        $this->driver()->billTextVersion(777);

        $this->assertSame(777, $this->driver()->billTextVersion(777)->id);
    }

    public function test_the_driver_advertises_the_capability(): void
    {
        $this->assertTrue($this->driver()->supports(Capability::GetBillTextVersion));
    }

    public function test_the_current_text_still_costs_two_requests_by_design(): void
    {
        $this->fakeLegiscan([
            'getBill' => $this->okResponse([
                'bill' => [
                    'bill_id' => 999,
                    'bill_number' => 'HB1',
                    'state' => 'PA',
                    'texts' => [
                        ['doc_id' => 1, 'type' => 'Introduced', 'date' => '2025-01-01'],
                        ['doc_id' => 2, 'type' => 'Amended', 'date' => '2025-06-01'],
                    ],
                ],
            ]),
            'getBillText' => $this->okResponse([
                'text' => ['doc_id' => 2, 'type' => 'Amended', 'mime' => 'application/pdf'],
            ]),
        ]);

        // A bill number needs a state to resolve; a bill id does not.
        $text = $this->driver()->setStateContext('PA')->billText('HB1');

        // Asking for "the current text" genuinely requires finding out which
        // version that is, so this path is unchanged.
        $this->assertSame(2, $text->id);
        $this->assertSame(['getBill', 'getBillText'], $this->requestedOps());
    }
}
