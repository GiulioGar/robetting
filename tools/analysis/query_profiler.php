<?php
/**
 * P2B — Query Profiler per HistoricalPredictionDatasetBuilder.
 *
 * Strumenta DB::listen() + debug_backtrace() per attribuire ogni query SQL
 * al componente che la genera.
 *
 * Uso: php artisan tinker --execute="require 'tools/analysis/query_profiler.php';"
 *
 * NON modifica alcun codice applicativo. Solo lettura.
 */

use App\Models\FootballMatch;
use App\Services\Prediction\HistoricalPredictionDatasetBuilder;
use Illuminate\Support\Facades\DB;

// ── Match IDs dal mini-dataset P2A ─────────────────────────────────────────

$targetIds = [7426, 7865, 7873, 7122, 7538, 7999, 7645, 8372, 8118, 8155];

$matches = FootballMatch::with(['season', 'competition'])
    ->whereIn('id', $targetIds)
    ->get();

echo 'Loaded: ' . $matches->count() . ' matches' . PHP_EOL . PHP_EOL;

// ── Normalizzatore SQL fingerprint ─────────────────────────────────────────

function normalize_sql(string $sql): string
{
    // Replace bound parameter placeholders with ?
    $sql = preg_replace('/\b\d+\b/', '?', $sql);
    // Collapse multiple ? in IN(...) to IN(?)
    $sql = preg_replace('/\(\?\s*(?:,\s*\?)*\s*\)/', '(?)', $sql);
    // Collapse extra whitespace
    $sql = preg_replace('/\s+/', ' ', trim($sql));
    return $sql;
}

// ── Caller attribution via backtrace ──────────────────────────────────────
// Note: map is defined inside get_caller() because global scope is not
// accessible when the file is required from within Tinker's eval context.

function get_caller(): string
{
    static $serviceClasses = [
        'TeamEloCalculator'                         => 'Elo',
        'TeamStrengthComparisonCalculator'          => 'Strength/Structural',
        'TeamStructuralRatingCalculator'            => 'Structural',
        'TeamOpponentQualityCalculator'             => 'E9 OppQuality',
        'TeamOpponentAdjustedPerformanceCalculator' => 'E10 AdjPerf',
        'TeamAbsenceImpactCalculator'               => 'Absence (E5)',
        'TeamStarterContinuityCalculator'           => 'Continuity (E5)',
        'TeamAgeProfileCalculator'                  => 'Age (E5)',
        'TeamScheduleLoadCalculator'                => 'Schedule (E4)',
        'TeamTimeDecayedPerformanceCalculator'      => 'TimeDecay (E12)',
        'HeadToHeadCalculator'                      => 'H2H',
        'CompetitionStatisticsCalculator'           => 'LeagueContext (E11)',
        'PreferredMatchStatisticResolver'           => 'MatchStatResolver',
        'PreMatchFeatureAggregator'                 => 'Aggregator',
        'HistoricalPredictionDatasetBuilder'        => 'DatasetBuilder',
    ];
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60);
    foreach ($trace as $frame) {
        $class = $frame['class'] ?? '';
        foreach ($serviceClasses as $shortName => $label) {
            if (str_contains($class, $shortName)) {
                $method = $frame['function'] ?? '?';
                return $label . '::' . $method;
            }
        }
    }
    return 'unknown';
}

// ── Structures ────────────────────────────────────────────────────────────

$allCaptures    = [];   // [{sql, fingerprint, ms, caller, match_id}]
$perMatchCounts = [];   // [match_id => total queries]
$currentMatchId = null;

// ── Install listener ──────────────────────────────────────────────────────

DB::listen(function ($query) use (&$allCaptures, &$currentMatchId) {
    $allCaptures[] = [
        'match_id'    => $currentMatchId,
        'sql_raw'     => $query->sql,
        'fingerprint' => normalize_sql($query->sql),
        'ms'          => $query->time,
        'caller'      => get_caller(),
    ];
});

// ── Process each match individually ──────────────────────────────────────

$builder = new HistoricalPredictionDatasetBuilder();

foreach ($matches->sortBy('kickoff_at') as $match) {
    $currentMatchId = $match->id;
    $countBefore    = count($allCaptures);

    try {
        $builder->build(collect([$match]), 'core_only');
    } catch (\Throwable $e) {
        echo '  SKIP #' . $match->id . ': ' . $e->getMessage() . PHP_EOL;
        continue;
    }

    $perMatchCounts[$match->id] = count($allCaptures) - $countBefore;
}

DB::flushQueryLog();

// ── Analysis ──────────────────────────────────────────────────────────────

$total     = count($allCaptures);
$totalMs   = array_sum(array_column($allCaptures, 'ms'));
$matchCount = count($perMatchCounts);

echo '═══════════════════════════════════════════════════════════════════════' . PHP_EOL;
echo ' TOTALI: ' . $total . ' queries · ' . round($totalMs) . ' ms · ' . $matchCount . ' match' . PHP_EOL;
echo ' Avg: ' . round($total / $matchCount, 1) . ' q/match · ' . round($totalMs / $matchCount, 0) . ' ms/match' . PHP_EOL;
echo '═══════════════════════════════════════════════════════════════════════' . PHP_EOL . PHP_EOL;

// ── Per-match query counts ────────────────────────────────────────────────

echo '── PER-MATCH QUERY COUNT ─────────────────────────────────────────────' . PHP_EOL;
foreach ($perMatchCounts as $mid => $cnt) {
    $m   = $matches->firstWhere('id', $mid);
    $slug = substr($m->competition->slug ?? '?', 0, 13);
    echo sprintf('  #%-5d  %-13s  %2d queries', $mid, $slug, $cnt) . PHP_EOL;
}
echo PHP_EOL;

// ── Aggregate by caller ──────────────────────────────────────────────────

$byCaller = [];
foreach ($allCaptures as $c) {
    $component = explode('::', $c['caller'])[0];
    if (!isset($byCaller[$component])) {
        $byCaller[$component] = ['count' => 0, 'ms' => 0.0, 'fingerprints' => []];
    }
    $byCaller[$component]['count']++;
    $byCaller[$component]['ms'] += $c['ms'];
    $byCaller[$component]['fingerprints'][$c['fingerprint']] = true;
}

// Sort by count DESC
uasort($byCaller, fn ($a, $b) => $b['count'] <=> $a['count']);

echo '── COSTO PER COMPONENTE (ordinato per query totali) ──────────────────' . PHP_EOL;
echo sprintf('  %-38s  %6s  %5s  %8s  %6s  %6s  %6s',
    'Componente', 'Total', 'q/m', 'ms_tot', 'ms/m', '%q', '%ms') . PHP_EOL;
echo '  ' . str_repeat('-', 90) . PHP_EOL;

foreach ($byCaller as $comp => $data) {
    $qpm  = round($data['count'] / $matchCount, 1);
    $mpm  = round($data['ms'] / $matchCount, 1);
    $pctq = round($data['count'] / $total * 100, 1);
    $pctm = round($data['ms'] / $totalMs * 100, 1);
    echo sprintf('  %-38s  %6d  %5.1f  %8.1f  %6.1f  %5.1f%%  %5.1f%%',
        substr($comp, 0, 38),
        $data['count'], $qpm, $data['ms'], $mpm, $pctq, $pctm
    ) . PHP_EOL;
}
echo PHP_EOL;

// ── Top fingerprints by executions ───────────────────────────────────────

$byFingerprint = [];
foreach ($allCaptures as $c) {
    $fp = $c['fingerprint'];
    if (!isset($byFingerprint[$fp])) {
        $byFingerprint[$fp] = [
            'count'  => 0,
            'ms'     => 0.0,
            'caller' => $c['caller'],
            'sample' => substr($fp, 0, 100),
        ];
    }
    $byFingerprint[$fp]['count']++;
    $byFingerprint[$fp]['ms'] += $c['ms'];
}

uasort($byFingerprint, fn ($a, $b) => $b['ms'] <=> $a['ms']);

echo '── TOP 15 QUERY FINGERPRINTS (ordinato per ms cumulativi) ───────────' . PHP_EOL;
$i = 0;
foreach ($byFingerprint as $fp => $data) {
    $avgMs = $data['count'] > 0 ? round($data['ms'] / $data['count'], 1) : 0;
    echo sprintf('  [%2d] exec=%-3d  ms_tot=%7.1f  ms_avg=%6.1f  caller=%-40s',
        ++$i, $data['count'], $data['ms'], $avgMs, substr($data['caller'], 0, 40)) . PHP_EOL;
    echo '       SQL: ' . substr($fp, 0, 110) . PHP_EOL;
    if ($i >= 15) { break; }
}
echo PHP_EOL;

// ── ELO AUDIT ─────────────────────────────────────────────────────────────

echo '── ELO AUDIT ─────────────────────────────────────────────────────────' . PHP_EOL;
$eloQueries = array_filter($allCaptures, fn ($c) => str_contains($c['caller'], 'Elo'));
$eloByMethod = [];
foreach ($eloQueries as $c) {
    $method = $c['caller'];
    if (!isset($eloByMethod[$method])) {
        $eloByMethod[$method] = ['count' => 0, 'ms' => 0.0];
    }
    $eloByMethod[$method]['count']++;
    $eloByMethod[$method]['ms'] += $c['ms'];
}
echo sprintf('  Total Elo queries   : %d (%.1f/match)' . PHP_EOL, count($eloQueries), count($eloQueries) / $matchCount);
foreach ($eloByMethod as $method => $data) {
    echo sprintf('    %-55s  exec=%d  ms=%.1f' . PHP_EOL,
        substr($method, 0, 55), $data['count'], $data['ms']);
}
echo PHP_EOL;

// ── PREVIOUS MATCHES LOAD AUDIT ───────────────────────────────────────────

echo '── PREVIOUS MATCHES LOAD AUDIT ───────────────────────────────────────' . PHP_EOL;
// Detect all "select * from `matches` where ... kickoff_at < ?" queries
$prevMatchQueries = array_filter($allCaptures, function ($c) {
    return str_contains(strtolower($c['sql_raw']), 'from `matches`')
        && (str_contains($c['sql_raw'], 'kickoff_at') || str_contains($c['sql_raw'], 'home_team_id'));
});
echo sprintf('  Total "matches" table queries: %d (%.1f/match)' . PHP_EOL,
    count($prevMatchQueries), count($prevMatchQueries) / $matchCount);
$prevByMethod = [];
foreach ($prevMatchQueries as $c) {
    $comp = explode('::', $c['caller'])[0];
    if (!isset($prevByMethod[$comp])) {
        $prevByMethod[$comp] = 0;
    }
    $prevByMethod[$comp]++;
}
arsort($prevByMethod);
foreach ($prevByMethod as $comp => $cnt) {
    echo sprintf('    %-38s  %d queries  (%.1f/match)' . PHP_EOL,
        $comp, $cnt, $cnt / $matchCount);
}
echo PHP_EOL;

// ── STRUCTURAL AUDIT ──────────────────────────────────────────────────────

echo '── STRUCTURAL (snapshot) AUDIT ───────────────────────────────────────' . PHP_EOL;
$structQueries = array_filter($allCaptures, fn ($c) =>
    str_contains($c['caller'], 'Structural') || str_contains($c['caller'], 'Strength'));
$structTotal = count($structQueries);
$structMs    = array_sum(array_column($structQueries, 'ms'));
echo sprintf('  Total structural queries : %d (%.1f/match)  ms=%.1f' . PHP_EOL,
    $structTotal, $structTotal / $matchCount, $structMs);

// Snapshot queries returning null
$snapshotQueries = array_filter($allCaptures, fn ($c) =>
    str_contains(strtolower($c['sql_raw']), 'team_market_value_snapshots') ||
    str_contains($c['fingerprint'], 'snapshot'));
echo sprintf('  Snapshot table queries   : %d (%.1f/match)' . PHP_EOL,
    count($snapshotQueries), count($snapshotQueries) / $matchCount);
echo PHP_EOL;

// ── ABSENCE / CONTINUITY / AGE AUDIT ─────────────────────────────────────

echo '── ABSENCE / CONTINUITY / AGE AUDIT ─────────────────────────────────' . PHP_EOL;
foreach (['Absence (E5)', 'Continuity (E5)', 'Age (E5)'] as $comp) {
    $cq  = array_filter($allCaptures, fn ($c) => str_starts_with($c['caller'], $comp));
    $cnt = count($cq);
    $ms  = array_sum(array_column($cq, 'ms'));
    echo sprintf('  %-22s  q=%2d (%.1f/m)  ms=%6.1f (%.1f/m)' . PHP_EOL,
        $comp, $cnt, $cnt / $matchCount, $ms, $ms / $matchCount);
}
echo PHP_EOL;

// ── DATASOURCE LOOKUP AUDIT ───────────────────────────────────────────────

echo '── DATASOURCE LOOKUP AUDIT ───────────────────────────────────────────' . PHP_EOL;
$dsQueries = array_filter($allCaptures, fn ($c) =>
    str_contains(strtolower($c['sql_raw']), 'data_sources'));
echo sprintf('  DataSource lookups total : %d (%.1f/match)' . PHP_EOL,
    count($dsQueries), count($dsQueries) / $matchCount);
$dsByMethod = [];
foreach ($dsQueries as $c) {
    $key = $c['caller'];
    if (!isset($dsByMethod[$key])) $dsByMethod[$key] = 0;
    $dsByMethod[$key]++;
}
foreach ($dsByMethod as $m => $cnt) {
    echo sprintf('    %-55s  %d times' . PHP_EOL, substr($m, 0, 55), $cnt);
}
echo PHP_EOL;

// ── QUERY VALUE MATRIX ────────────────────────────────────────────────────

echo '── QUERY VALUE MATRIX ────────────────────────────────────────────────' . PHP_EOL;
$matrix = [
    ['Elo (E6 via TSCC + E9)',          'Elo',          3.0,  '100%',  'YES', 'VERY HIGH'],
    ['Structural snapshot (E7)',        'Structural',    2.0,  '0%',    'YES', 'HIGH (null short-circuit possible)'],
    ['Previous matches loads',          'Aggregator+E5', 8.0,  '100%',  'YES', 'VERY HIGH (8x duplicate)'],
    ['Match statistics loads',          'Aggregator',    2.0,  '100%',  'YES', 'MEDIUM (already batch)'],
    ['Schedule history (E4)',           'Aggregator',    2.0,  '100%',  'YES', 'MEDIUM'],
    ['League context (E11)',            'Aggregator',    2.0,  '100%',  'YES', 'MEDIUM'],
    ['H2H (loaded but filtered away)',  'Aggregator',    1.0,  'N/A',   'NO',  'LOW (skip in core_only)'],
    ['DataSource lookups (duplicate)',  'Various',       3.0,  'N/A',   'NO',  'HIGH (easy fix)'],
    ['Absence (E5)',                    'Absence',       2.0,  '~50%',  'YES', 'MEDIUM'],
    ['Continuity (E5)',                 'Continuity',    2.0,  '~50%',  'YES', 'MEDIUM'],
    ['Age profile (E5)',                'AgeProfile',    3.0,  '~30%',  'YES', 'MEDIUM'],
    ['League team IDs (E9)',            'Aggregator',    1.0,  '100%',  'YES', 'LOW (1 query)'],
];

echo sprintf('  %-38s  %6s  %8s  %5s  %-30s' . PHP_EOL,
    'Feature Group', 'q/m', 'Coverage', 'Core?', 'Opt Priority');
echo '  ' . str_repeat('-', 100) . PHP_EOL;

foreach ($matrix as [$fg, $src, $qpm, $cov, $core, $prio]) {
    echo sprintf('  %-38s  %6.1f  %8s  %5s  %-30s' . PHP_EOL,
        $fg, $qpm, $cov, $core, $prio);
}
echo PHP_EOL;

// ── TOP 3 OTTIMIZZAZIONI ──────────────────────────────────────────────────

$actualQPM  = round($total / $matchCount, 1);
$actualMSPM = round($totalMs / $matchCount, 0);

echo '── TOP 3 OTTIMIZZAZIONI (senza modificare aggregate() per uso singolo) ' . PHP_EOL;
echo PHP_EOL;

echo '  OPT-1: Single Elo replay via BulkPreMatchFeatureContext' . PHP_EOL;
echo '    Query eliminate: 2 per match (replay #2 e #3 di E9, condivisi da #1)' . PHP_EOL;
echo '    Usa metodi GIA ESISTENTI: TeamEloCalculator::calculateRatingsBeforeMatchesWithLeagueMean()' . PHP_EOL;
echo '    + pre-calcolare il map[match_id => {home_elo, away_elo, league_mean_elo}]' . PHP_EOL;
echo '    per TUTTI i match storici in un unico pass, poi iniettarlo in E9 come eloMap pre-calcolato.' . PHP_EOL;
echo '    q/match dopo: ' . round($actualQPM - 2, 1) . PHP_EOL;
echo '    Complessita: MEDIA (aggiunge parametro opzionale a E9::calculate())' . PHP_EOL;
echo '    Rischio regressione: BASSO (retrocompatibile via param opzionale)' . PHP_EOL;
echo '    Calculator coinvolti: TeamEloCalculator, TeamOpponentQualityCalculator, Aggregator' . PHP_EOL;
echo PHP_EOL;

echo '  OPT-2: Condivisione prev-match Collections tra Aggregator e E5 (Absence/Continuity/Age)' . PHP_EOL;
echo '    Query eliminate: ~6 per match (3 coppie home/away di prev-match loads duplicate)' . PHP_EOL;
echo '    Pattern: BulkContext contiene homePrev e awayPrev pre-caricati dal Aggregator' . PHP_EOL;
echo '    e li passa a TeamAbsenceImpactCalculator, TeamStarterContinuityCalculator,' . PHP_EOL;
echo '    TeamAgeProfileCalculator invece di ricaricarli internamente.' . PHP_EOL;
echo '    q/match dopo: ' . round($actualQPM - 2 - 6, 1) . PHP_EOL;
echo '    Complessita: MEDIA-ALTA (refactor firma di 3 calculator)' . PHP_EOL;
echo '    Rischio regressione: MEDIO (modifica interfaccia pubblica dei 3 calculator)' . PHP_EOL;
echo '    Calculator coinvolti: TeamAbsenceImpactCalculator, TeamStarterContinuityCalculator, TeamAgeProfileCalculator' . PHP_EOL;
echo PHP_EOL;

echo '  OPT-3: DataSource lookup cache + H2H skip in core_only' . PHP_EOL;
echo '    Query eliminate: ~3 per match (2 DataSource duplicate + 1 H2H non usato in core)' . PHP_EOL;
echo '    DataSource: cache statico in Aggregator dopo prima lookup (gia pattern in TSCC)' . PHP_EOL;
echo '    H2H: skip loadH2HMatches() in core_only mode (query eseguita ma risultato non usato)' . PHP_EOL;
echo '    q/match dopo: ' . round($actualQPM - 2 - 6 - 3, 1) . PHP_EOL;
echo '    Complessita: BASSA' . PHP_EOL;
echo '    Rischio regressione: BASSO' . PHP_EOL;
echo PHP_EOL;

// ── PROIEZIONI ────────────────────────────────────────────────────────────

echo '── PROIEZIONI per 3511 match ─────────────────────────────────────────' . PHP_EOL;
$scenarios = [
    ['Attuale (baseline)',       $actualQPM,                           $actualMSPM],
    ['Dopo OPT-1 (Elo share)',   round($actualQPM - 2, 1),             null],
    ['Dopo OPT-1+2 (prev-match share)', round($actualQPM - 2 - 6, 1), null],
    ['Dopo OPT-1+2+3 (full)',   round($actualQPM - 2 - 6 - 3, 1),     null],
];

// Rough time model: ms ≈ base + slope*q_per_match
// From baseline: actualMSPM for actualQPM queries
$slope = $actualMSPM / $actualQPM;  // ms per query (rough linear)

foreach ($scenarios as &$row) {
    if ($row[2] === null) {
        $row[2] = round($row[1] * $slope, 0);
    }
    $totalMin = round(3511 * $row[2] / 1000 / 60, 1);
    $gate = $totalMin <= 10 ? 'GREEN' : ($totalMin <= 20 ? 'YELLOW' : 'RED');
    echo sprintf('  %-38s  q/m=%5.1f  ms/m=%5.0f  min=%5.1f  %s' . PHP_EOL,
        $row[0], $row[1], $row[2], $totalMin, $gate);
}

echo PHP_EOL;
echo 'NOTA: la proiezione tempo usa un modello lineare semplificato (ms ≈ q * slope).' . PHP_EOL;
echo 'Il risparmio reale potrebbe essere maggiore (Elo replay carica migliaia di righe).' . PHP_EOL;
echo PHP_EOL;
