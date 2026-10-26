<?php

namespace Tests\Feature\Prediction;

use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P19A — `robetting:export-latent-fit-matches` feeds the CURRENT Latent snapshot
 * fit from the DB. Only finished, validly-scored core-league matches from
 * 2024/25 on with kickoff strictly before the cutoff may be exported.
 */
class ExportLatentFitMatchesTest extends TestCase
{
    use RefreshDatabase;

    private const CUTOFF = '2026-09-25T12:00:00Z';

    private string $out;
    private Team $home;
    private Team $away;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = tempnam(sys_get_temp_dir(), 'latent_fit_') . '.csv';

        $country    = Country::create(['name' => 'Italy', 'code' => 'IT']);
        $this->home = Team::create(['name' => 'Napoli', 'country_id' => $country->id]);
        $this->away = Team::create(['name' => 'Frosinone', 'country_id' => $country->id]);
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
        parent::tearDown();
    }

    private function season(string $slug, int $yearStart): Season
    {
        $competition = Competition::firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'country_id' => $this->home->country_id],
        );

        return Season::create([
            'competition_id' => $competition->id,
            'name'           => $yearStart . '/' . substr((string) ($yearStart + 1), 2),
            'year_start'     => $yearStart,
            'year_end'       => $yearStart + 1,
        ]);
    }

    private function match(Season $season, string $kickoff, string $status, ?int $hg, ?int $ag): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $season->competition_id,
            'season_id'      => $season->id,
            'home_team_id'   => $this->home->id,
            'away_team_id'   => $this->away->id,
            'kickoff_at'     => $kickoff,
            'status'         => $status,
            'home_score_ft'  => $hg,
            'away_score_ft'  => $ag,
        ]);
    }

    private function export(?string $latentMode = null): array
    {
        $args = ['--before' => self::CUTOFF, '--output' => $this->out];
        if ($latentMode !== null) {
            $args['--latent-mode'] = $latentMode;
        }
        $this->artisan('robetting:export-latent-fit-matches', $args)->assertSuccessful();

        $lines = array_map('str_getcsv', file($this->out, FILE_IGNORE_NEW_LINES));
        $header = array_shift($lines);

        return array_map(fn ($l) => array_combine($header, $l), $lines);
    }

    /** @test */
    public function test_exports_only_valid_finished_core_matches_before_cutoff(): void
    {
        $sa2425 = $this->season('serie-a', 2024);
        $sa2627 = $this->season('serie-a', 2026);
        $sa2324 = $this->season('serie-a', 2023);
        $serieB = $this->season('serie-b', 2026);

        $keepOld    = $this->match($sa2425, '2024-09-01 18:00:00', 'finished', 2, 1);
        $keepRecent = $this->match($sa2627, '2026-09-20 19:00:00', 'finished', 0, 0);

        $this->match($sa2627, '2026-09-21 18:00:00', 'awarded', 3, 0);      // awarded: excluded (training policy)
        $this->match($sa2627, '2026-09-22 18:00:00', 'scheduled', null, null);
        $this->match($sa2627, '2026-09-23 18:00:00', 'finished', null, 1);  // missing FT score
        $this->match($sa2324, '2024-05-01 18:00:00', 'finished', 1, 1);     // before 2024/25
        $this->match($serieB, '2026-09-20 15:00:00', 'finished', 1, 0);     // not a core league
        $this->match($sa2627, '2026-09-25 12:00:00', 'finished', 1, 0);     // kickoff == cutoff: excluded (strict)
        $this->match($sa2627, '2026-09-26 18:00:00', 'finished', 2, 2);     // after cutoff (leakage)

        $rows = $this->export();

        $this->assertSame([(string) $keepOld->id, (string) $keepRecent->id], array_column($rows, 'match_id'));
        $this->assertSame(['2', '1'], [$rows[0]['home_goals'], $rows[0]['away_goals']]);
        $this->assertSame('Napoli', $rows[0]['home_team_name']);
        $this->assertSame((string) $this->away->id, $rows[1]['away_team_id']);
        foreach ($rows as $row) {
            $this->assertLessThan(strtotime(self::CUTOFF), strtotime($row['kickoff_at']));
        }
    }

    /** @test */
    public function test_requires_cutoff_and_output(): void
    {
        $this->artisan('robetting:export-latent-fit-matches', ['--output' => $this->out])->assertFailed();
        $this->artisan('robetting:export-latent-fit-matches', ['--before' => self::CUTOFF])->assertFailed();
    }

    // ── Step C-LATENT — --latent-mode ────────────────────────────────────────────

    /** @test */
    public function test_default_latent_mode_is_legacy_core_only(): void
    {
        $sa2425 = $this->season('serie-a', 2024);
        $ucl    = $this->season('uefa-champions-league', 2024);

        $coreMatch = $this->match($sa2425, '2024-09-01 18:00:00', 'finished', 2, 1);
        $this->match($ucl, '2024-09-05 18:00:00', 'finished', 1, 1); // UEFA: excluded by default

        $rows = $this->export(); // no --latent-mode passed

        $this->assertSame([(string) $coreMatch->id], array_column($rows, 'match_id'));
    }

    /** @test */
    public function test_extended_mode_includes_uefa_competitions(): void
    {
        $sa2425 = $this->season('serie-a', 2024);
        $ucl    = $this->season('uefa-champions-league', 2024);
        $uel    = $this->season('uefa-europa-league', 2024);
        $uecl   = $this->season('uefa-conference-league', 2024);

        $coreMatch = $this->match($sa2425, '2024-09-01 18:00:00', 'finished', 2, 1);
        $uclMatch  = $this->match($ucl, '2024-09-05 18:00:00', 'finished', 1, 1);
        $uelMatch  = $this->match($uel, '2024-09-06 18:00:00', 'finished', 0, 2);
        $ueclMatch = $this->match($uecl, '2024-09-07 18:00:00', 'finished', 3, 0);

        $rows = $this->export('extended');

        $this->assertEqualsCanonicalizing(
            [(string) $coreMatch->id, (string) $uclMatch->id, (string) $uelMatch->id, (string) $ueclMatch->id],
            array_column($rows, 'match_id'),
        );
    }

    /** @test */
    public function test_extended_mode_excludes_a_foreign_competition(): void
    {
        $sa2425 = $this->season('serie-a', 2024);
        $ucl    = $this->season('uefa-champions-league', 2024);
        $cup    = $this->season('coppa-italia', 2024); // national cup: never in Latent extended

        $coreMatch = $this->match($sa2425, '2024-09-01 18:00:00', 'finished', 2, 1);
        $uclMatch  = $this->match($ucl, '2024-09-05 18:00:00', 'finished', 1, 1);
        $this->match($cup, '2024-09-06 18:00:00', 'finished', 4, 0);

        $rows = $this->export('extended');

        $this->assertEqualsCanonicalizing(
            [(string) $coreMatch->id, (string) $uclMatch->id],
            array_column($rows, 'match_id'),
        );
    }

    /** @test */
    public function test_future_uefa_match_is_still_excluded_by_cutoff_in_extended_mode(): void
    {
        $ucl = $this->season('uefa-champions-league', 2024);

        $this->match($ucl, '2024-09-05 18:00:00', 'finished', 1, 1); // before cutoff: kept
        $this->match($ucl, '2026-10-21 18:00:00', 'finished', 2, 2); // after cutoff: must never leak

        $rows = $this->export('extended');

        foreach ($rows as $row) {
            $this->assertLessThan(strtotime(self::CUTOFF), strtotime($row['kickoff_at']));
        }
        $this->assertCount(1, $rows);
    }

    /** @test */
    public function test_invalid_latent_mode_returns_failure(): void
    {
        $this->artisan('robetting:export-latent-fit-matches', [
            '--before'     => self::CUTOFF,
            '--output'     => $this->out,
            '--latent-mode' => 'bogus',
        ])->assertFailed();
    }
}
