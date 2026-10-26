<?php

namespace App\Services\DataSources\ApiFootball;

use App\Models\Competition;
use App\Models\DataSource;
use App\Models\DataSyncRun;
use App\Models\FootballMatch;
use App\Models\MatchExternalId;
use App\Models\OddsSnapshot;
use App\Models\Season;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Odds V1 — prospective, append-only collection of PRE-MATCH 1X2 (Match Winner)
 * odds from API-Football `/odds`. Raw data only: no fair probability, overround,
 * edge, best bookmaker or closing line is computed here, and nothing in the
 * Prediction Engine reads this table.
 *
 * ── Call strategy (quota-efficient) ─────────────────────────────────────────
 * One aggregated request family per (league, season): `/odds?league=&season=&bet=&page=N`
 * returns every fixture of the league with its bookmakers for that bet, ~10
 * fixtures per page — never one request per bookmaker or per fixture. A
 * competition is queried only if it has a scheduled fixture (with an
 * api-football external id) kicking off within `horizon_days`, and at most once
 * per `throttle_minutes` (~3h, the provider's pre-match refresh cadence; a
 * failed attempt retries after `error_retry_minutes`). The throttle gate is the
 * latest `data_sync_runs` row (sync_type=odds_sync) per competition.
 *
 * ── Match Winner identification ─────────────────────────────────────────────
 * Requested with `bet=<config match_winner_bet_id>` (API-Football bet 1). A bet
 * in the response is accepted if its id equals that id OR its name equals
 * config match_winner_bet_name. Selections are the provider values
 * "Home" / "Draw" / "Away".
 *
 * ── Append-only + dedup ─────────────────────────────────────────────────────
 * Rows are only inserted. For each (match, bookmaker, bet) the latest stored
 * observation is compared: identical home/draw/away odds AND identical
 * provider_updated_at => duplicate, nothing written; anything else => new row.
 *
 * ── Pre-match validity ──────────────────────────────────────────────────────
 * A row is stored only if the match is still `scheduled` and
 * fetched_at < kickoff_at (kickoff as known at fetch time, stored on the row)
 * and the provider update time, when present, is also before kickoff. Anything
 * else is skipped and never becomes a pre-match quote.
 *
 * No historical backfill: only upcoming fixtures are ever queried.
 */
class ApiFootballOddsSyncService
{
    private const SYNC_TYPE = 'odds_sync';

    private ?DataSource $ds = null;

    public function __construct(private readonly ApiFootballClient $client) {}

    private function dataSource(): DataSource
    {
        return $this->ds ??= DataSource::where('slug', 'api-football')->firstOrFail();
    }

    /**
     * @param  string|null  $onlySlug        restrict to one in-scope competition slug
     * @param  bool         $ignoreThrottle  manual runs only
     * @return array{enabled:bool,competitions:int,api_calls:int,created:int,duplicates:int,skipped:int,warnings:int,errors:int,skipped_throttle:int}
     */
    public function syncPending(?string $onlySlug = null, bool $ignoreThrottle = false): array
    {
        $summary = [
            'enabled' => (bool) config('api-football.odds.enabled', true),
            'competitions' => 0, 'api_calls' => 0, 'created' => 0, 'duplicates' => 0,
            'skipped' => 0, 'warnings' => 0, 'errors' => 0, 'skipped_throttle' => 0,
        ];

        if (! $summary['enabled']) {
            return $summary;
        }

        foreach ($this->scopeLeagues() as $slug => $leagueId) {
            if ($onlySlug !== null && $slug !== $onlySlug) {
                continue;
            }

            $competition = Competition::where('slug', $slug)->first();
            if ($competition === null) {
                continue;
            }

            $candidates = $this->candidateMatches($competition);
            if ($candidates->isEmpty()) {
                continue;
            }

            if (! $ignoreThrottle && $this->throttled($competition)) {
                $summary['skipped_throttle']++;
                continue;
            }

            $summary['competitions']++;
            $r = $this->syncCompetition($competition, $leagueId, $candidates);
            foreach (['api_calls', 'created', 'duplicates', 'skipped', 'warnings'] as $k) {
                $summary[$k] += $r[$k];
            }
            $summary['errors'] += $r['error'] ? 1 : 0;
        }

        return $summary;
    }

    /** @return array<string,int> slug => API-Football league id (core leagues + configured UEFA club competitions) */
    private function scopeLeagues(): array
    {
        $core       = array_flip((array) config('api-football.core_leagues', []));
        $additional = array_flip((array) config('api-football.additional_competitions', []));
        $uefa       = array_intersect_key($additional, array_flip(MatchStatisticsSyncPolicy::UEFA_SLUGS));

        return $core + $uefa;
    }

    /**
     * Scheduled, still-future fixtures within the horizon that have an api-football id.
     *
     * @return \Illuminate\Support\Collection<string, FootballMatch>  external fixture id => match
     */
    private function candidateMatches(Competition $competition)
    {
        $ds = $this->dataSource();

        $matches = FootballMatch::where('competition_id', $competition->id)
            ->where('status', 'scheduled')
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '>', now())
            ->where('kickoff_at', '<=', now()->addDays((int) config('api-football.odds.horizon_days', 14)))
            ->get(['id', 'competition_id', 'season_id', 'kickoff_at', 'status']);

        if ($matches->isEmpty()) {
            return collect();
        }

        $ext = MatchExternalId::where('data_source_id', $ds->id)
            ->whereIn('match_id', $matches->pluck('id'))
            ->pluck('external_id', 'match_id');

        return $matches->filter(fn ($m) => isset($ext[$m->id]))
            ->keyBy(fn ($m) => (string) $ext[$m->id]);
    }

    private function throttled(Competition $competition): bool
    {
        $last = DataSyncRun::where('data_source_id', $this->dataSource()->id)
            ->where('sync_type', self::SYNC_TYPE)
            ->where('competition_id', $competition->id)
            ->orderByDesc('started_at')
            ->first();

        if ($last === null) {
            return false;
        }

        $minutes = $last->status === 'ok'
            ? (int) config('api-football.odds.throttle_minutes', 180)
            : (int) config('api-football.odds.error_retry_minutes', 60);

        return $last->started_at->gt(now()->subMinutes($minutes));
    }

    /**
     * @param  \Illuminate\Support\Collection<string, FootballMatch>  $candidates
     * @return array{api_calls:int,created:int,duplicates:int,skipped:int,warnings:int,error:bool}
     */
    private function syncCompetition(Competition $competition, int $leagueId, $candidates): array
    {
        $started   = now();
        $apiCalls  = 0;
        $created   = 0;
        $duplicates = 0;
        $skipped   = 0;
        $warnings  = 0;
        $remaining = null;
        $error     = null;
        $pages     = 0;

        $seasonYears = Season::whereIn('id', $candidates->pluck('season_id')->unique())->pluck('year_start')->unique();

        try {
            foreach ($seasonYears as $year) {
                $page = 1;
                do {
                    $response  = $this->client->get('odds', [
                        'league' => $leagueId,
                        'season' => $year,
                        'bet'    => (int) config('api-football.odds.match_winner_bet_id', 1),
                        'page'   => $page,
                    ]);
                    $fetchedAt = now();
                    $apiCalls++;
                    $pages++;
                    $remaining = $response->requestsRemaining ?? $remaining;

                    $r = $this->storePage($response->response, $candidates, $fetchedAt);
                    $created    += $r['created'];
                    $duplicates += $r['duplicates'];
                    $skipped    += $r['skipped'];
                    $warnings   += $r['warnings'];

                    $total = (int) ($response->paging['total'] ?? 1);
                    $page++;
                } while ($page <= $total && $page <= (int) config('api-football.odds.max_pages', 20));
            }
        } catch (ApiFootballException $e) {
            $error = $e->getMessage();
            Log::error("api-football-odds: {$competition->slug} — {$error}");
        }

        DataSyncRun::create([
            'data_source_id'  => $this->dataSource()->id,
            'sync_type'       => self::SYNC_TYPE,
            'competition_id'  => $competition->id,
            'season_id'       => null,
            'mode'            => 'pending',
            'started_at'      => $started,
            'finished_at'     => now(),
            'status'          => $error === null ? 'ok' : 'error',
            'created_count'   => $created,
            'updated_count'   => 0,
            'unchanged_count' => $duplicates,
            'skipped_count'   => $skipped,
            'warnings_count'  => $warnings,
            'api_calls'       => $apiCalls,
            'daily_remaining' => $remaining,
            'details'         => ['pages' => $pages, 'error' => $error],
        ]);

        return [
            'api_calls' => $apiCalls, 'created' => $created, 'duplicates' => $duplicates,
            'skipped' => $skipped, 'warnings' => $warnings, 'error' => $error !== null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  \Illuminate\Support\Collection<string, FootballMatch>  $candidates
     * @return array{created:int,duplicates:int,skipped:int,warnings:int}
     */
    private function storePage(array $items, $candidates, Carbon $fetchedAt): array
    {
        $created = $duplicates = $skipped = $warnings = 0;
        $dsId    = $this->dataSource()->id;
        $betId   = (int) config('api-football.odds.match_winner_bet_id', 1);
        $betName = (string) config('api-football.odds.match_winner_bet_name', 'Match Winner');

        $pageMatches = [];
        foreach ($items as $item) {
            $match = $candidates->get((string) ($item['fixture']['id'] ?? ''));
            if ($match !== null) {
                $pageMatches[$match->id] = true;
            }
        }
        $latest = $this->latestObservations(array_keys($pageMatches), $dsId, $betId);

        foreach ($items as $item) {
            $match = $candidates->get((string) ($item['fixture']['id'] ?? ''));
            if ($match === null) {
                continue; // fixture outside our scope / not a future scheduled match
            }

            $providerUpdatedAt = $this->parseTime($item['update'] ?? null);

            // Pre-match validity: never store anything acquired at/after kickoff.
            if (! $fetchedAt->lt($match->kickoff_at)
                || ($providerUpdatedAt !== null && ! $providerUpdatedAt->lt($match->kickoff_at))) {
                $skipped++;
                continue;
            }

            foreach ($item['bookmakers'] ?? [] as $bookmaker) {
                foreach ($bookmaker['bets'] ?? [] as $bet) {
                    if ((int) ($bet['id'] ?? 0) !== $betId && ($bet['name'] ?? null) !== $betName) {
                        continue;
                    }

                    $odds = $this->parseOdds($bet['values'] ?? []);
                    if ($odds === null || ! isset($bookmaker['id'])) {
                        $warnings++;
                        continue;
                    }

                    $key  = $match->id . '|' . (int) $bookmaker['id'];
                    $prev = $latest[$key] ?? null;
                    if ($prev !== null && $this->sameObservation($prev, $odds, $providerUpdatedAt)) {
                        $duplicates++;
                        continue;
                    }

                    $row = OddsSnapshot::create([
                        'match_id'            => $match->id,
                        'data_source_id'      => $dsId,
                        'bookmaker_id'        => (int) $bookmaker['id'],
                        'bookmaker_name'      => (string) ($bookmaker['name'] ?? ''),
                        'bet_id'              => (int) ($bet['id'] ?? $betId),
                        'bet_name'            => (string) ($bet['name'] ?? $betName),
                        'home_odd'            => $odds['home'],
                        'draw_odd'            => $odds['draw'],
                        'away_odd'            => $odds['away'],
                        'provider_updated_at' => $providerUpdatedAt,
                        'fetched_at'          => $fetchedAt,
                        'kickoff_at'          => $match->kickoff_at,
                        'raw_data'            => ['update' => $item['update'] ?? null, 'bet' => $bet],
                    ]);
                    $latest[$key] = $row; // same bookmaker repeated within a response must not double-insert
                    $created++;
                }
            }
        }

        return compact('created', 'duplicates', 'skipped', 'warnings');
    }

    /** @return array<string, OddsSnapshot>  "matchId|bookmakerId" => latest stored observation */
    private function latestObservations(array $matchIds, int $dsId, int $betId): array
    {
        if ($matchIds === []) {
            return [];
        }

        $rows = OddsSnapshot::whereIn('id', function ($q) use ($matchIds, $dsId, $betId) {
            $q->from('odds_snapshots')->selectRaw('MAX(id)')
                ->whereIn('match_id', $matchIds)
                ->where('data_source_id', $dsId)
                ->where('bet_id', $betId)
                ->groupBy('match_id', 'bookmaker_id');
        })->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->match_id . '|' . $r->bookmaker_id] = $r;
        }

        return $out;
    }

    /** @param array{home:float,draw:float,away:float} $odds */
    private function sameObservation(OddsSnapshot $prev, array $odds, ?Carbon $providerUpdatedAt): bool
    {
        $sameOdds = round($prev->home_odd, 3) === round($odds['home'], 3)
            && round($prev->draw_odd, 3) === round($odds['draw'], 3)
            && round($prev->away_odd, 3) === round($odds['away'], 3);

        $prevTs = $prev->provider_updated_at?->getTimestamp();
        $newTs  = $providerUpdatedAt?->getTimestamp();

        return $sameOdds && $prevTs === $newTs;
    }

    /** @return array{home:float,draw:float,away:float}|null  null if any selection is missing/invalid */
    private function parseOdds(array $values): ?array
    {
        $map = [];
        foreach ($values as $v) {
            $label = strtolower((string) ($v['value'] ?? ''));
            $odd   = $v['odd'] ?? null;
            if (in_array($label, ['home', 'draw', 'away'], true) && is_numeric($odd) && (float) $odd > 1.0) {
                $map[$label] = (float) $odd;
            }
        }

        return count($map) === 3 ? ['home' => $map['home'], 'draw' => $map['draw'], 'away' => $map['away']] : null;
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
