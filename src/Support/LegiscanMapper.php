<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Support;

use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\BillText;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\Session;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\Party;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;

/**
 * Translates raw LegiScan API payloads into normalized core DTOs.
 *
 * This is the only place that knows LegiScan's field names, so core stays
 * unaware of any specific data source.
 */
class LegiscanMapper
{
    public static function session(array $payload): Session
    {
        return new Session(meta: [
            'id' => (int) ($payload['session_id'] ?? 0),
            'title' => (string) ($payload['session_title'] ?? ''),
            'name' => (string) ($payload['session_name'] ?? ''),
            'state' => self::state($payload),
            'prior' => (bool) ($payload['prior'] ?? false),
            'sine_die' => (bool) ($payload['sine_die'] ?? false),
            'special' => (bool) ($payload['special'] ?? false),
            'raw' => $payload,
        ]);
    }

    public static function bill(array $payload): Bill
    {
        return new Bill(meta: [
            'id' => $payload['bill_id'] ?? 0,
            'number' => $payload['number'] ?? $payload['bill_number'] ?? '',
            'title' => $payload['title'] ?? '',
            'description' => $payload['description'] ?? '',
            'state' => self::state($payload),
            'chamber' => Chamber::fromString($payload['body'] ?? $payload['current_body'] ?? null),
            'status' => (string) ($payload['status'] ?? ''),
            'status_date' => $payload['status_date'] ?? null,
            'last_action' => $payload['last_action'] ?? '',
            'last_action_date' => $payload['last_action_date'] ?? null,
            'url' => $payload['url'] ?? $payload['state_link'] ?? '',
            'session_id' => $payload['session_id'] ?? ($payload['session']['session_id'] ?? null),
            'raw' => $payload,
        ]);
    }

    public static function vote(array $payload): Vote
    {
        return new Vote(meta: [
            'id' => $payload['roll_call_id'] ?? $payload['id'] ?? 0,
            'bill_id' => $payload['bill_id'] ?? null,
            'chamber' => Chamber::fromString($payload['chamber'] ?? null),
            'date' => $payload['date'] ?? null,
            'description' => $payload['desc'] ?? $payload['description'] ?? '',
            'yea' => $payload['yea'] ?? null,
            'nay' => $payload['nay'] ?? null,
            'nv' => $payload['nv'] ?? null,
            'absent' => $payload['absent'] ?? null,
            'passed' => $payload['passed'] ?? null,
            'url' => $payload['url'] ?? $payload['state_link'] ?? '',
            'raw' => $payload,
        ]);
    }

    /**
     * Maps either a `getBill`'s `texts[]` entry (no content) or a full
     * `getBillText` response (`doc`, base64-encoded) into a normalized
     * {@see BillText}. The latter carries `bill_id` directly; the former
     * doesn't, so callers pass it in explicitly from the enclosing bill.
     */
    public static function billText(array $payload, int|string|null $billId = null): BillText
    {
        return new BillText(meta: [
            'id' => $payload['doc_id'] ?? 0,
            'bill_id' => $payload['bill_id'] ?? $billId,
            'type' => $payload['type'] ?? '',
            'mime' => $payload['mime'] ?? '',
            'date' => $payload['date'] ?? null,
            'url' => $payload['state_link'] ?? $payload['url'] ?? '',
            'content' => isset($payload['doc']) ? base64_decode($payload['doc']) : null,
            'raw' => $payload,
        ]);
    }

    public static function legislator(array $payload): Legislator
    {
        return new Legislator(meta: [
            'id' => $payload['people_id'] ?? $payload['id'] ?? 0,
            'name' => $payload['name'] ?? trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? '')),
            'first_name' => $payload['first_name'] ?? '',
            'last_name' => $payload['last_name'] ?? '',
            'party' => Party::fromString($payload['party'] ?? null),
            'chamber' => Chamber::fromString($payload['role'] ?? $payload['chamber'] ?? null),
            'district' => $payload['district'] ?? null,
            'role' => $payload['role'] ?? null,
            'state' => self::state($payload),
            'active' => $payload['active'] ?? null,
            'url' => $payload['ballotpedia'] ?? $payload['url'] ?? '',
            'raw' => $payload,
        ]);
    }

    /**
     * Resolve a StateEnum from a LegiScan payload's `state_id` (its numeric
     * state id) or `state` abbreviation, defaulting to US.
     */
    private static function state(array $payload): StateEnum
    {
        return StateEnum::tryFrom((int) ($payload['state_id'] ?? 0))
            ?? StateEnum::fromAbbr((string) ($payload['state'] ?? ''))
            ?? StateEnum::US;
    }
}
