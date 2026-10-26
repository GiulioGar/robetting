<?php

namespace Tests\Feature\Analytics;

use App\Models\Competition;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\Player;
use App\Models\PlayerAbsence;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\Analytics\TeamAvailabilityCalculator;
use App\Services\DataSources\ApiFootball\ApiFootballInjurySyncService;
use Carbon\Carbon;
use Database\Seeders\ApiFootballDataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Availability anti-leakage: only a snapshot certified PRE-MATCH
 * (injuries_fetched_at strictly before kickoff_at) counts as coverage.
 * Post-kickoff / historical snapshots are fail-closed for Availability.
 */
class AvailabilityPreMatchSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private DataSource $ds;
    private Competition $competition;
    private Season $season;
    private Team $home;
    private Team $away;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiFootballDataSourceSeeder::class);
        config(['api-football.api_key' => 'test-key', 'api-football.base_url' => 'https://v3.football.api-sports.io']);
        config(['api-football.core_leagues' => [135 => 'serie-a']]);
        $this->ds = DataSource::where('slug', 'api-football')->firstOrFail();

        $country = Country::create(['name' => 'Italy', 'football_code' => 'IT']);
        $this->competition = Competition::create(['country_id' => $country->id, 'name' => 'Serie A', 'slug' => 'serie-a', 'format' => 'league', 'is_active' => true]);
        $this->season = Season::create(['competition_id' => $this->competition->id, 'name' => '2026/27', 'year_start' => 2026, 'year_end' => 2027, 'is_current' => true]);
        $this->home = Team::create(['name' => 'Inter', 'type' => 'club', 'is_active' => true]);
        $this->away = Team::create(['name' => 'Milan', 'type' => 'club', 'is_active' => true]);
        foreach ([[$this->home, 505], [$this->away, 489]] as [$team, $apiId]) {
            TeamExternalId::create(['team_id' => $team->id, 'data_source_id' => $this->ds->id, 'external_id' => (string) $apiId, 'external_name' => $team->name]);
        }
    }

    private function match(string $status, Carbon $kickoff, ?Carbon $fetchedAt, ?string $ext = null): FootballMatch
    {
        $m = FootballMatch::create([
            'competition_id' => $this->competition->id, 'season_id' => $this->season->id,
            'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
            'kickoff_at' => $kickoff, 'status' => $status,
            'home_score_ft' => $status === 'finished' ? 1 : null, 'away_score_ft' => $status === 'finished' ? 0 : null,
        ]);
        $m->forceFill(['injuries_fetched_at' => $fetchedAt])->save();
        if ($ext !== null) {
            MatchExternalId::create(['match_id' => $m->id, 'data_source_id' => $this->ds->id, 'external_id' => $ext]);
        }

        return $m;
    }

    private function addForcedAbsence(FootballMatch $m): void
    {
        $p = Player::create(['name' => 'Injured ' . uniqid()]);
        PlayerAbsence::create([
            'match_id' => $m->id, 'player_id' => $p->id, 'team_id' => $this->home->id, 'data_source_id' => $this->ds->id,
            'absence_type' => 'Missing Fixture', 'reason' => 'Knee Injury',
        ]);
    }

    private function injuriesResponse(): array
    {
        return [
            'errors' => [], 'results' => 1, 'paging' => ['current' => 1, 'total' => 1],
            'response' => [[
                'player' => ['id' => 777, 'name' => 'L. Martinez', 'photo' => '', 'type' => 'Missing Fixture', 'reason' => 'Knee Injury'],
                'team'   => ['id' => 505, 'name' => 'Inter', 'logo' => ''],
                'fixture' => ['id' => 1, 'timezone' => 'UTC', 'date' => '', 'timestamp' => 0],
                'league' => ['id' => 135, 'name' => 'Serie A', 'country' => 'Italy', 'logo' => '', 'flag' => '', 'season' => 2026],
            ]],
        ];
    }

    public function test_pre_kickoff_snapshot_is_valid_coverage(): void
    {
        $kickoff = Carbon::parse('2026-10-20 18:00:00');
        $m = $this->match('scheduled', $kickoff, $kickoff->copy()->subHours(3));

        $this->assertTrue($m->hasPreMatchInjurySnapshot());
        $result = TeamAvailabilityCalculator::calculateForMatch($m, $this->home->id);
        $this->assertTrue($result['injuries_covered']);
        $this->assertSame(0.0, $result['weighted_absence']);
    }

    public function test_post_kickoff_or_at_kickoff_snapshot_is_not_valid_even_with_absence_rows(): void
    {
        $kickoff = Carbon::parse('2026-10-20 18:00:00');

        foreach ([$kickoff->copy(), $kickoff->copy()->addHours(2)] as $fetchedAt) {
            $m = $this->match('finished', $kickoff, $fetchedAt);
            $this->addForcedAbsence($m);

            $this->assertFalse($m->hasPreMatchInjurySnapshot());
            $result = TeamAvailabilityCalculator::calculateForMatch($m, $this->home->id);
            $this->assertFalse($result['injuries_covered']);
            $this->assertNull($result['weighted_absence']);
            $this->assertNull($result['forced_absent_count']);
        }
    }

    public function test_historical_sync_snapshot_never_enters_availability(): void
    {
        Http::fake(['*injuries*' => Http::response($this->injuriesResponse(), 200)]);
        $finished = $this->match('finished', now()->subHours(3), null, '222222');

        $result = app(ApiFootballInjurySyncService::class)->syncMissingHistorical(2026);

        $this->assertSame(1, $result['synced']);
        $finished->refresh();
        $this->assertNotNull($finished->injuries_fetched_at);
        $this->assertTrue($finished->injuries_fetched_at->gte($finished->kickoff_at)); // acquired after kickoff
        $this->assertFalse($finished->hasPreMatchInjurySnapshot());
        $this->assertFalse(TeamAvailabilityCalculator::calculateForMatch($finished, $this->home->id)['injuries_covered']);
    }

    public function test_pending_prospective_fetch_stays_valid_pre_match(): void
    {
        Http::fake(['*injuries*' => Http::response($this->injuriesResponse(), 200)]);
        $future = $this->match('scheduled', now()->addHours(72), null, '111111');

        $result = app(ApiFootballInjurySyncService::class)->syncPending();

        $this->assertSame(1, $result['synced']);
        $future->refresh();
        $this->assertTrue($future->injuries_fetched_at->lt($future->kickoff_at));
        $this->assertTrue($future->hasPreMatchInjurySnapshot());
        $calc = TeamAvailabilityCalculator::calculateForMatch($future, $this->home->id);
        $this->assertTrue($calc['injuries_covered']);
        $this->assertSame(1, $calc['forced_absent_count']);
    }

    public function test_status_command_separates_valid_from_historical_and_excludes_past_abandoned_from_future_block(): void
    {
        // Valid prospective finished match.
        $k = Carbon::now()->subDays(3);
        $this->match('finished', $k, $k->copy()->subHours(5));
        // Historical/post-kickoff finished match: reported apart, not counted as covered.
        $k2 = Carbon::now()->subDays(2);
        $this->match('finished', $k2, $k2->copy()->addHours(4));
        // Genuinely future covered fixture.
        $this->match('scheduled', Carbon::now()->addDays(2), Carbon::now()->subHour());
        // Abandoned with a past kickoff and a (pre-kickoff) snapshot: NOT a future fixture.
        $k3 = Carbon::now()->subDays(1);
        $this->match('abandoned', $k3, $k3->copy()->subHours(2));

        $this->artisan('robetting:availability-status')
            ->expectsOutputToContain('finished injury-covered:         1')
            ->expectsOutputToContain('finished historical/post-kickoff (not valid): 1')
            ->expectsOutputToContain('historical/post-kickoff snapshots (NOT valid for prediction/evaluation): 1')
            ->expectsOutputToContain('fixtures checked (home+away team slots): 2') // only the scheduled one (2 team slots)
            ->assertExitCode(0);
    }
}
