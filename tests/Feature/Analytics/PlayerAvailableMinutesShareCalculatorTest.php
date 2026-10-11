<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchPlayerStatistic;
use App\Models\Player;
use App\Models\PlayerAbsence;
use App\Models\PlayerExternalId;
use App\Models\Season;
use App\Models\Team;
use App\Services\Analytics\PlayerAvailableMinutesShareCalculator;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for PlayerAvailableMinutesShareCalculator (Availability V1,
 * Step 5G). Rules under test:
 *  [A] starter with real minutes counted normally
 *  [B] unused bench, NULL minutes, games_substitute=1, no sub event → 0
 *  [C] NULL minutes + sub-in event evidence → invalid, excluded entirely
 *  [D] Missing Fixture + injury reason → match excluded (forced unavailable)
 *  [E] Missing Fixture + medical reason → match excluded
 *  [F] Missing Fixture + suspension reason → match excluded
 *  [G] Questionable absence does NOT force unavailability (still counted)
 *  [H] EXCLUDE-listed reason does NOT force unavailability (still counted)
 *  [I] match without injuries_fetched_at is not part of the denominator at all
 *  [J] cutoff is strict: match at/after cutoff never counted
 *  [K] no valid match at all → available_minutes_share is null
 */
class PlayerAvailableMinutesShareCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Team $teamA;
    private Team $teamB;
    private Team $teamC;
    private Competition $comp;
    private Season $season;
    private Carbon $cutoff;

    private const CUTOFF = '2026-09-10 20:45:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country      = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->comp   = Competition::create([
            'country_id' => $country->id,
            'name'       => 'Serie A',
            'slug'       => 'serie-a',
            'format'     => 'league',
            'is_active'  => true,
        ]);
        $this->season = Season::create([
            'competition_id' => $this->comp->id,
            'name'           => '2026/27',
            'year_start'     => 2026,
            'year_end'       => 2027,
            'is_current'     => true,
        ]);
        $this->teamA  = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->teamB  = Team::create(['name' => 'Milan', 'type' => 'club', 'is_active' => true]);
        // Neutral third team: used as the default opponent for coveredMatchForTeam()
        // so that a teamA fixture and a teamB fixture never accidentally share the
        // same match row (which would make it eligible for both teams at once).
        $this->teamC  = Team::create(['name' => 'Napoli', 'type' => 'club', 'is_active' => true]);
        $this->cutoff = Carbon::parse(self::CUTOFF);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [A] Starter with real minutes
    // ─────────────────────────────────────────────────────────────────────────

    public function test_starter_with_minutes_counted(): void
    {
        $player = Player::create(['name' => 'Lautaro']);
        $m      = $this->coveredMatch($this->cutoff->copy()->subDays(5));
        $this->addStat($m, $player, minutes: 90, isSub: false);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(1, $result['covered_matches']);
        $this->assertSame(1, $result['available_matches']);
        $this->assertSame(90, $result['played_minutes']);
        $this->assertSame(90, $result['available_minutes']);
        $this->assertSame(1.0, $result['available_minutes_share']);
        $this->assertSame(0, $result['excluded_absence_matches']);
        $this->assertSame(0, $result['invalid_minutes_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [B] Unused bench: NULL minutes, substitute, no sub-in event → 0
    // ─────────────────────────────────────────────────────────────────────────

    public function test_unused_bench_null_minutes_treated_as_zero(): void
    {
        $player = Player::create(['name' => 'BenchGuy']);
        PlayerExternalId::create([
            'player_id'      => $player->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '999002',
        ]);
        $m = $this->coveredMatch($this->cutoff->copy()->subDays(5));
        $this->addStat($m, $player, minutes: null, isSub: true);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(1, $result['available_matches']);
        $this->assertSame(0, $result['played_minutes']);
        $this->assertSame(0.0, $result['available_minutes_share']);
        $this->assertSame(0, $result['invalid_minutes_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C] NULL minutes + sub-in event evidence → invalid, excluded entirely
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sub_used_null_minutes_excluded_as_invalid(): void
    {
        $player = Player::create(['name' => 'SubUsed']);
        PlayerExternalId::create([
            'player_id'      => $player->id,
            'data_source_id' => $this->ds->id,
            'external_id'    => '999001',
        ]);

        $m = $this->coveredMatch($this->cutoff->copy()->subDays(5));
        $this->addStat($m, $player, minutes: null, isSub: true);
        MatchEvent::create([
            'match_id'                    => $m->id,
            'data_source_id'              => $this->ds->id,
            'event_type'                  => 'substitution',
            'minute'                      => 70,
            'team_id'                     => $this->teamA->id,
            'player_external_id'          => '999999',
            'related_player_external_id'  => '999001',
            'source_event_key'            => 'sub-1',
        ]);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(1, $result['covered_matches']);
        $this->assertSame(0, $result['available_matches']);
        $this->assertSame(1, $result['invalid_minutes_matches']);
        $this->assertSame(0, $result['played_minutes']);
        $this->assertNull($result['available_minutes_share']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [C2] NULL minutes + substitute, but no external-id mapping at all →
    // cannot verify evidence → fail-closed, treated as invalid (not 0).
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_minutes_without_external_id_mapping_treated_as_invalid(): void
    {
        $player = Player::create(['name' => 'Unmapped']);
        $m      = $this->coveredMatch($this->cutoff->copy()->subDays(5));
        $this->addStat($m, $player, minutes: null, isSub: true);
        // No PlayerExternalId row at all — cannot check substitution evidence.

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(0, $result['available_matches']);
        $this->assertSame(1, $result['invalid_minutes_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [D] Missing Fixture + injury reason → excluded (forced unavailable)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_missing_fixture_injury_excludes_match(): void
    {
        $player = Player::create(['name' => 'Injured']);
        $m      = $this->coveredMatch($this->cutoff->copy()->subDays(5));
        $this->addAbsence($m, $player, 'Missing Fixture', 'Knee Injury');
        // No stat row — player genuinely didn't play due to the injury.

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(1, $result['covered_matches']);
        $this->assertSame(1, $result['excluded_absence_matches']);
        $this->assertSame(0, $result['available_matches']);
        $this->assertNull($result['available_minutes_share']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [E] Missing Fixture + medical reason → excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_missing_fixture_medical_excludes_match(): void
    {
        $player = Player::create(['name' => 'Sick']);
        $m      = $this->coveredMatch($this->cutoff->copy()->subDays(5));
        $this->addAbsence($m, $player, 'Missing Fixture', 'Illness');

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(1, $result['excluded_absence_matches']);
        $this->assertSame(0, $result['available_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [F] Missing Fixture + suspension reason → excluded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_missing_fixture_suspension_excludes_match(): void
    {
        $player = Player::create(['name' => 'Suspended']);
        $m      = $this->coveredMatch($this->cutoff->copy()->subDays(5));
        $this->addAbsence($m, $player, 'Missing Fixture', 'Suspended');

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(1, $result['excluded_absence_matches']);
        $this->assertSame(0, $result['available_matches']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [G] Questionable does NOT force unavailability
    // ─────────────────────────────────────────────────────────────────────────

    public function test_questionable_absence_does_not_exclude_match(): void
    {
        $player = Player::create(['name' => 'Doubtful']);
        $m      = $this->coveredMatch($this->cutoff->copy()->subDays(5));
        $this->addAbsence($m, $player, 'Questionable', 'Knee Injury');
        $this->addStat($m, $player, minutes: 90, isSub: false);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(0, $result['excluded_absence_matches']);
        $this->assertSame(1, $result['available_matches']);
        $this->assertSame(90, $result['played_minutes']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [H] EXCLUDE-listed reason does NOT force unavailability
    // ─────────────────────────────────────────────────────────────────────────

    public function test_exclude_reason_does_not_force_unavailability(): void
    {
        $player = Player::create(['name' => 'Inactive']);
        $m      = $this->coveredMatch($this->cutoff->copy()->subDays(5));
        $this->addAbsence($m, $player, 'Missing Fixture', 'Inactive');
        // No stat row: not in squad, counted as 0 played but still "available".

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(0, $result['excluded_absence_matches']);
        $this->assertSame(1, $result['available_matches']);
        $this->assertSame(0, $result['played_minutes']);
        $this->assertSame(0.0, $result['available_minutes_share']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [I] Match without injuries_fetched_at is not part of the denominator
    // ─────────────────────────────────────────────────────────────────────────

    public function test_match_without_injuries_fetched_at_ignored(): void
    {
        $player = Player::create(['name' => 'NoSnapshot']);
        $m = FootballMatch::create($this->matchAttrs($this->cutoff->copy()->subDays(5)));
        // injuries_fetched_at intentionally left null.
        $this->addStat($m, $player, minutes: 90, isSub: false);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(0, $result['covered_matches']);
        $this->assertNull($result['available_minutes_share']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [J] Strict cutoff: match at/after cutoff never counted
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cutoff_is_strict_and_prevents_leakage(): void
    {
        $player = Player::create(['name' => 'Future']);
        $future = $this->coveredMatch($this->cutoff->copy()->addDays(1));
        $this->addStat($future, $player, minutes: 90, isSub: false);

        $onCutoff = $this->coveredMatch($this->cutoff->copy());
        $this->addStat($onCutoff, $player, minutes: 90, isSub: false);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(0, $result['covered_matches']);
        $this->assertNull($result['available_minutes_share']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [K] No valid match at all → share is null
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_matches_at_all_returns_null_share(): void
    {
        $player = Player::create(['name' => 'Nobody']);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(0, $result['covered_matches']);
        $this->assertSame(0, $result['available_matches']);
        $this->assertSame(0, $result['played_minutes']);
        $this->assertSame(0, $result['available_minutes']);
        $this->assertNull($result['available_minutes_share']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [L] Player present since the start of the covered window: all matches count
    // ─────────────────────────────────────────────────────────────────────────

    public function test_player_present_from_start_counts_all_matches(): void
    {
        $player = Player::create(['name' => 'Resident']);
        $m1 = $this->coveredMatch($this->cutoff->copy()->subDays(20));
        $this->addStat($m1, $player, minutes: 90, isSub: false);
        $m2 = $this->coveredMatch($this->cutoff->copy()->subDays(5));
        $this->addStat($m2, $player, minutes: 60, isSub: false);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(2, $result['covered_matches']);
        $this->assertSame(2, $result['available_matches']);
        $this->assertSame(150, $result['played_minutes']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [M] Mid-season transfer: matches of the new team before the transfer
    // must never be counted for that player.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_transferred_player_excludes_new_team_matches_before_arrival(): void
    {
        $player = Player::create(['name' => 'Transferee']);

        // Old team (teamB) match, well before everything — irrelevant, different team_id anyway.
        $oldTeamMatch = $this->coveredMatchForTeam($this->teamB, $this->cutoff->copy()->subDays(60));
        $this->addStatForTeam($oldTeamMatch, $player, $this->teamB, minutes: 90, isSub: false);

        // New team (teamA) matches BEFORE arrival — must never be counted for this player.
        $beforeArrival1 = $this->coveredMatchForTeam($this->teamA, $this->cutoff->copy()->subDays(40));
        $this->addStatForTeam($beforeArrival1, Player::create(['name' => 'OtherGuy1']), $this->teamA, minutes: 90, isSub: false);
        $beforeArrival2 = $this->coveredMatchForTeam($this->teamA, $this->cutoff->copy()->subDays(30));
        $this->addStatForTeam($beforeArrival2, Player::create(['name' => 'OtherGuy2']), $this->teamA, minutes: 90, isSub: false);

        // First certain evidence of this player at teamA.
        $arrivalMatch = $this->coveredMatchForTeam($this->teamA, $this->cutoff->copy()->subDays(10));
        $this->addStatForTeam($arrivalMatch, $player, $this->teamA, minutes: 70, isSub: false);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        // Only the arrival match is covered for teamA — the two pre-arrival
        // teamA matches (with other players) must not leak in.
        $this->assertSame(1, $result['covered_matches']);
        $this->assertSame(1, $result['available_matches']);
        $this->assertSame(70, $result['played_minutes']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [N] After membership starts, a match without a stat row → 0 minutes
    // (same Step 5G rule, now membership-gated).
    // ─────────────────────────────────────────────────────────────────────────

    public function test_match_after_membership_without_stats_counts_as_zero(): void
    {
        $player = Player::create(['name' => 'JustArrived']);

        $arrival = $this->coveredMatch($this->cutoff->copy()->subDays(10));
        $this->addStat($arrival, $player, minutes: 70, isSub: false);

        // Later match, player not selected (no stat row) — still after membership started.
        $laterNotSelected = $this->coveredMatch($this->cutoff->copy()->subDays(3));

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(2, $result['covered_matches']);
        $this->assertSame(2, $result['available_matches']);
        $this->assertSame(70, $result['played_minutes']); // 70 + 0
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [O] Cutoff before the first membership evidence → share null
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cutoff_before_first_membership_evidence_returns_null(): void
    {
        $player = Player::create(['name' => 'FutureSigning']);

        // Only evidence of membership is AFTER the cutoff we query with.
        $arrival = $this->coveredMatch($this->cutoff->copy()->addDays(5));
        $this->addStat($arrival, $player, minutes: 90, isSub: false);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(0, $result['covered_matches']);
        $this->assertNull($result['available_minutes_share']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [P] No membership evidence at all → fail-closed, not "since season start"
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_membership_evidence_at_all_fails_closed(): void
    {
        $player = Player::create(['name' => 'NeverSeen']);

        // A covered match exists for the team, but this player never appears
        // in stats/absences/lineups for it — no evidence of membership at all.
        $this->coveredMatch($this->cutoff->copy()->subDays(5));

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(0, $result['covered_matches']);
        $this->assertNull($result['available_minutes_share']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [Q] Step 5I — full A→B transfer window on BOTH sides
    // ─────────────────────────────────────────────────────────────────────────

    public function test_transfer_window_includes_old_team_before_new_evidence_and_excludes_after(): void
    {
        $player = Player::create(['name' => 'Mover']);

        // teamA matches: one before the B-arrival evidence (must be INCLUDED),
        // one after it (must be EXCLUDED — player had certainly already left).
        $aBefore = $this->coveredMatchForTeam($this->teamA, $this->cutoff->copy()->subDays(50));
        $this->addStatForTeam($aBefore, $player, $this->teamA, minutes: 90, isSub: false);

        $aAfter = $this->coveredMatchForTeam($this->teamA, $this->cutoff->copy()->subDays(5));
        $this->addStatForTeam($aAfter, $player, $this->teamA, minutes: 90, isSub: false);

        // teamB arrival evidence (defines END for teamA, START for teamB).
        $bArrival = $this->coveredMatchForTeam($this->teamB, $this->cutoff->copy()->subDays(20));
        $this->addStatForTeam($bArrival, $player, $this->teamB, minutes: 80, isSub: false);

        // teamB match before the arrival evidence — must be EXCLUDED for teamB.
        $bBefore = $this->coveredMatchForTeam($this->teamB, $this->cutoff->copy()->subDays(30));
        $this->addStatForTeam($bBefore, Player::create(['name' => 'OtherGuy']), $this->teamB, minutes: 90, isSub: false);

        // teamB match after arrival — must be INCLUDED for teamB.
        $bAfter = $this->coveredMatchForTeam($this->teamB, $this->cutoff->copy()->subDays(10));
        $this->addStatForTeam($bAfter, $player, $this->teamB, minutes: 45, isSub: false);

        $resultA = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);
        $resultB = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamB->id, $this->cutoff);

        // teamA: only the pre-transfer match counts.
        $this->assertSame(1, $resultA['covered_matches']);
        $this->assertSame(90, $resultA['played_minutes']);

        // teamB: only the arrival + after match count (bBefore excluded).
        $this->assertSame(2, $resultB['covered_matches']);
        $this->assertSame(125, $resultB['played_minutes']); // 80 + 45
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [R] Cutoff before the transfer: no use of future teamB evidence, A stays
    // valid through cutoff.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cutoff_before_transfer_never_uses_future_team_b_evidence(): void
    {
        $player = Player::create(['name' => 'NotYetMoved']);

        $aMatch = $this->coveredMatchForTeam($this->teamA, $this->cutoff->copy()->subDays(10));
        $this->addStatForTeam($aMatch, $player, $this->teamA, minutes: 90, isSub: false);

        // teamB evidence AFTER the cutoff — must be invisible to this call.
        $bFuture = $this->coveredMatchForTeam($this->teamB, $this->cutoff->copy()->addDays(10));
        $this->addStatForTeam($bFuture, $player, $this->teamB, minutes: 90, isSub: false);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(1, $result['covered_matches']);
        $this->assertSame(90, $result['played_minutes']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [S] Single-team player: behaviour unchanged (no ceiling ever found)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_single_team_player_behaviour_unchanged(): void
    {
        $player = Player::create(['name' => 'Loyal']);
        $m1 = $this->coveredMatch($this->cutoff->copy()->subDays(30));
        $this->addStat($m1, $player, minutes: 90, isSub: false);
        $m2 = $this->coveredMatch($this->cutoff->copy()->subDays(5));
        $this->addStat($m2, $player, minutes: 90, isSub: false);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(2, $result['covered_matches']);
        $this->assertSame(2, $result['available_matches']);
        $this->assertSame(180, $result['played_minutes']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [T] Incoherent/simultaneous evidence (same timestamp as START on another
    // team) → fail-closed, never treated as a ceiling.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_simultaneous_other_team_evidence_is_not_treated_as_ceiling(): void
    {
        $player = Player::create(['name' => 'Ambiguous']);

        // Same kickoff timestamp used as START evidence for teamA...
        $startKickoff = $this->cutoff->copy()->subDays(20);
        $startMatch   = $this->coveredMatchForTeam($this->teamA, $startKickoff);
        $this->addStatForTeam($startMatch, $player, $this->teamA, minutes: 90, isSub: false);

        // ...and a DIFFERENT match, for teamB, at the exact SAME kickoff
        // timestamp (a data inconsistency / simultaneous evidence) must not
        // be treated as proof the player had already moved to teamB — the
        // ">" (strict) comparison in firstOtherTeamKickoffAfter() excludes
        // anything tied to the same instant as START.
        $conflictingMatch = $this->coveredMatchForTeam($this->teamB, $startKickoff->copy());
        $this->addAbsence($conflictingMatch, $player, 'Missing Fixture', 'Inactive');
        // Patch the team on that absence row to teamB (addAbsence() hardcodes teamA).
        \App\Models\PlayerAbsence::where('match_id', $conflictingMatch->id)
            ->where('player_id', $player->id)
            ->update(['team_id' => $this->teamB->id]);

        // A later, unambiguous teamA match must still be included.
        $later = $this->coveredMatchForTeam($this->teamA, $this->cutoff->copy()->subDays(5));
        $this->addStatForTeam($later, $player, $this->teamA, minutes: 60, isSub: false);

        $result = PlayerAvailableMinutesShareCalculator::calculate($player->id, $this->teamA->id, $this->cutoff);

        $this->assertSame(2, $result['covered_matches']);
        $this->assertSame(150, $result['played_minutes']); // 90 + 60, no false ceiling
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function matchAttrs(Carbon $kickoff, string $status = 'finished'): array
    {
        return [
            'competition_id'     => $this->comp->id,
            'season_id'          => $this->season->id,
            'home_team_id'       => $this->teamA->id,
            'away_team_id'       => $this->teamB->id,
            'kickoff_at'         => $kickoff,
            'status'             => $status,
            'home_score_ft'      => $status === 'finished' ? 1 : null,
            'away_score_ft'      => $status === 'finished' ? 0 : null,
        ];
    }

    /** A match with an injuries_fetched_at snapshot already taken (denominator-eligible). */
    private function coveredMatch(Carbon $kickoff, string $status = 'finished'): FootballMatch
    {
        return $this->coveredMatchForTeam($this->teamA, $kickoff, $status);
    }

    /**
     * Same as coveredMatch(), but lets the home/away team be chosen explicitly.
     * Opponent defaults to the neutral teamC so that two fixtures created for
     * teamA and teamB respectively never collide into the SAME match row
     * (a real match always has two distinct, generally unrelated, opponents
     * across different fixtures in these tests).
     */
    private function coveredMatchForTeam(Team $team, Carbon $kickoff, string $status = 'finished', ?Team $opponent = null): FootballMatch
    {
        $other = $opponent ?? $this->teamC;
        $m = FootballMatch::create([
            'competition_id' => $this->comp->id,
            'season_id'      => $this->season->id,
            'home_team_id'   => $team->id,
            'away_team_id'   => $other->id,
            'kickoff_at'     => $kickoff,
            'status'         => $status,
            'home_score_ft'  => $status === 'finished' ? 1 : null,
            'away_score_ft'  => $status === 'finished' ? 0 : null,
        ]);
        $m->forceFill(['injuries_fetched_at' => $kickoff->copy()->subDay()])->save();

        return $m;
    }

    private function addStat(
        FootballMatch $match,
        Player $player,
        ?int $minutes,
        bool $isSub,
    ): MatchPlayerStatistic {
        return $this->addStatForTeam($match, $player, $this->teamA, $minutes, $isSub);
    }

    private function addStatForTeam(
        FootballMatch $match,
        Player $player,
        Team $team,
        ?int $minutes,
        bool $isSub,
    ): MatchPlayerStatistic {
        return MatchPlayerStatistic::create([
            'match_id'         => $match->id,
            'player_id'        => $player->id,
            'team_id'          => $team->id,
            'data_source_id'   => $this->ds->id,
            'games_minutes'    => $minutes,
            'games_substitute' => $isSub,
        ]);
    }

    private function addAbsence(
        FootballMatch $match,
        Player $player,
        string $absenceType,
        string $reason,
    ): PlayerAbsence {
        return PlayerAbsence::create([
            'match_id'        => $match->id,
            'player_id'       => $player->id,
            'team_id'         => $this->teamA->id,
            'data_source_id'  => $this->ds->id,
            'absence_type'    => $absenceType,
            'reason'          => $reason,
        ]);
    }
}
