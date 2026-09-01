<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Support;

use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\BillText;
use WiserWebSolutions\Lobbyist\Data\Dataset;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\Session;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Data\VoteCast;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\Party;
use WiserWebSolutions\Lobbyist\Enums\SponsorType;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;
use WiserWebSolutions\Lobbyist\Enums\VotePosition;

/**
 * Translates raw LegiScan API payloads into normalized core DTOs.
 *
 * This is the only place that knows LegiScan's field names, so core stays
 * unaware of any specific data source.
 *
 * Beware one genuine ambiguity in the LegiScan schema: the key `votes` means
 * two different things depending on which payload it appears in. On a
 * `getBill` bill it is a list of roll call summaries; on a `getRollCall` roll
 * call it is the list of individual member votes. {@see self::bill()} reads it
 * as the former and {@see self::vote()} as the latter, which is correct because
 * each only ever receives its own payload type.
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

    /**
     * Maps one `getDatasetList` entry.
     *
     * `access_key` only appears in the listing, never in the archive response,
     * so a dataset must be discovered here before it can be fetched.
     */
    public static function dataset(array $payload): Dataset
    {
        return new Dataset(meta: [
            'session_id' => $payload['session_id'] ?? 0,
            'session_name' => $payload['session_name'] ?? $payload['session_title'] ?? '',
            'state' => self::state($payload),
            'hash' => $payload['dataset_hash'] ?? null,
            'date' => $payload['dataset_date'] ?? null,
            'size' => $payload['dataset_size'] ?? null,
            'year_start' => $payload['year_start'] ?? null,
            'year_end' => $payload['year_end'] ?? null,
            'access_key' => $payload['access_key'] ?? null,
            'raw' => $payload,
        ]);
    }

    public static function bill(array $payload): Bill
    {
        $billId = $payload['bill_id'] ?? null;

        return new Bill(meta: [
            'id' => $billId ?? 0,
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
            'change_hash' => $payload['change_hash'] ?? null,
            'texts' => array_map(
                fn (array $text) => self::billText($text, $billId),
                $payload['texts'] ?? []
            ),
            'votes' => array_map(
                fn (array $vote) => self::vote($vote, $billId),
                $payload['votes'] ?? []
            ),
            'sponsors' => array_map(
                fn (array $sponsor) => self::sponsor($sponsor),
                $payload['sponsors'] ?? []
            ),
            'raw' => $payload,
        ]);
    }

    /**
     * Maps either a `getRollCall` roll_call payload, which carries the
     * per-member `votes` breakdown, or one of the roll call summaries embedded
     * in a bill, which does not and has no `bill_id` of its own — so callers
     * pass that in from the enclosing bill.
     */
    public static function vote(array $payload, int|string|null $billId = null): Vote
    {
        return new Vote(meta: [
            'id' => $payload['roll_call_id'] ?? $payload['id'] ?? 0,
            'bill_id' => $payload['bill_id'] ?? $billId,
            'chamber' => Chamber::fromString($payload['chamber'] ?? null),
            'date' => $payload['date'] ?? null,
            'description' => $payload['desc'] ?? $payload['description'] ?? '',
            'yea' => $payload['yea'] ?? null,
            'nay' => $payload['nay'] ?? null,
            'nv' => $payload['nv'] ?? null,
            'absent' => $payload['absent'] ?? null,
            'passed' => $payload['passed'] ?? null,
            'url' => $payload['url'] ?? $payload['state_link'] ?? '',
            'positions' => array_map(
                fn (array $cast) => self::voteCast($cast),
                $payload['votes'] ?? []
            ),
            'raw' => $payload,
        ]);
    }

    /**
     * Maps one entry of a roll call `votes` array, being a single member vote.
     *
     * LegiScan supplies both a numeric `vote_id` (1 = Yea, 2 = Nay, ...) and a
     * textual `vote_text`; either resolves through {@see VotePosition}.
     */
    public static function voteCast(array $payload): VoteCast
    {
        return new VoteCast(meta: [
            'legislator_id' => $payload['people_id'] ?? $payload['id'] ?? 0,
            'position' => VotePosition::fromString(
                $payload['vote_id'] ?? $payload['vote_text'] ?? null
            ),
            'name' => $payload['name'] ?? '',
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
        return new Legislator(meta: self::legislatorMeta($payload));
    }

    /**
     * Maps one entry of a bill `sponsors` array.
     *
     * A sponsor payload is a person record plus that member relationship to
     * one specific bill, so the sponsorship details are normalized onto the
     * legislator `meta` rather than pretending to be attributes of the member.
     */
    public static function sponsor(array $payload): Legislator
    {
        return new Legislator(meta: self::legislatorMeta($payload) + [
            'sponsor_type' => SponsorType::fromString($payload['sponsor_type_id'] ?? null),
            'sponsor_order' => isset($payload['sponsor_order'])
                ? (int) $payload['sponsor_order']
                : null,
        ]);
    }

    /**
     * The shared person-field mapping behind {@see self::legislator()} and
     * {@see self::sponsor()}, so the two can never drift apart.
     *
     * @return array<string, mixed>
     */
    private static function legislatorMeta(array $payload): array
    {
        return [
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
        ];
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
