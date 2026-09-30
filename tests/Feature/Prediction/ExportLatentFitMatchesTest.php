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

    private function export(): array
    {
        $this->artisan('robetting:export-latent-fit-matches', ['--before' => self::CUTOFF, '--output' => $this->out])
            ->assertSuccessful();

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
}
