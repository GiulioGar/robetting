@extends('layouts.app')

@section('title', $match->homeTeam->name . ' - ' . $match->awayTeam->name . ' · ' . $match->competition->name)

@section('content')

@php
    $pct = fn(?float $p) => $p === null ? '–' : number_format($p, 1) . '%';
    $avg = fn(?float $v) => $v === null ? '–' : number_format($v, 2);
    $badgeClass = fn(string $r) => match ($r) {
        'W'     => 'success',
        'D'     => 'secondary',
        'L'     => 'danger',
        default => 'light',
    };
    $kickoffRome = $match->kickoff_at?->copy()->setTimezone('Europe/Rome');
    $hasFt = $match->home_score_ft !== null && $match->away_score_ft !== null;
    $hasHt = $match->home_score_ht !== null && $match->away_score_ht !== null;
@endphp

{{-- A. Header --}}
<div class="mb-4">
    <div class="text-muted small mb-1">
        <a href="{{ route('competitions.seasons.show', ['competition' => $match->competition->slug, 'season' => $match->season->year_start]) }}" class="link-body-emphasis text-decoration-none">{{ $match->competition->name }}</a>
        · {{ $match->season->name }}
        @if($match->matchday) · Giornata {{ $match->matchday }} @elseif($match->round) · {{ $match->round }} @endif
    </div>
    <div class="d-flex align-items-center justify-content-center gap-3 py-3">
        <div class="text-end flex-fill fs-4 fw-semibold">
            <a href="{{ route('teams.show', $match->home_team_id) }}" class="link-body-emphasis text-decoration-none">{{ $match->homeTeam->name }}</a>
        </div>
        <div class="text-center px-3" style="min-width:110px">
            @if($hasFt)
                <div class="fs-2 fw-bold">{{ $match->home_score_ft }} – {{ $match->away_score_ft }}</div>
            @else
                <div class="fs-4 text-muted">–</div>
            @endif
            @if($hasHt)
                <div class="text-muted small">HT {{ $match->home_score_ht }} – {{ $match->away_score_ht }}</div>
            @endif
        </div>
        <div class="text-start flex-fill fs-4 fw-semibold">
            <a href="{{ route('teams.show', $match->away_team_id) }}" class="link-body-emphasis text-decoration-none">{{ $match->awayTeam->name }}</a>
        </div>
    </div>
    <div class="text-muted small text-center">
        {{ $kickoffRome?->format('d/m/Y H:i') ?? '–' }}
    </div>
</div>

{{-- B. Match statistics --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Statistiche match</h2>
    @if($matchStatistic === null)
    <p class="text-muted">Statistiche non disponibili per questa partita.</p>
    @else
    @php
        $rows = [
            ['label' => 'Tiri',              'home' => $matchStatistic->home_shots,           'away' => $matchStatistic->away_shots],
            ['label' => 'Tiri in porta',     'home' => $matchStatistic->home_shots_on_target, 'away' => $matchStatistic->away_shots_on_target],
            ['label' => 'Corner',            'home' => $matchStatistic->home_corners,         'away' => $matchStatistic->away_corners],
            ['label' => 'Falli',             'home' => $matchStatistic->home_fouls,           'away' => $matchStatistic->away_fouls],
            ['label' => 'Cartellini gialli', 'home' => $matchStatistic->home_yellow_cards,    'away' => $matchStatistic->away_yellow_cards],
            ['label' => 'Cartellini rossi',  'home' => $matchStatistic->home_red_cards,       'away' => $matchStatistic->away_red_cards],
        ];
    @endphp
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 text-center align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-start ps-3">&nbsp;</th>
                        <th>Casa</th>
                        <th>Trasferta</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                    <tr>
                        <td class="text-start ps-3 text-muted">{{ $row['label'] }}</td>
                        <td>{{ $row['home'] ?? '–' }}</td>
                        <td>{{ $row['away'] ?? '–' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

{{-- C. Forma prima del match --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Forma prima del match</h2>
    <div class="row g-3">
        @foreach([['label' => $match->homeTeam->name, 'a' => $homeAnalytics], ['label' => $match->awayTeam->name, 'a' => $awayAnalytics]] as $block)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small mb-2">{{ $block['label'] }} <span class="text-muted">(ultime 5)</span></div>
                    @if(empty($block['a']['last5']['form']))
                        <span class="text-muted">Dati precedenti non disponibili</span>
                    @else
                        @foreach($block['a']['last5']['form'] as $r)
                            <span class="badge bg-{{ $badgeClass($r) }} me-1">{{ $r }}</span>
                        @endforeach
                    @endif
                    <div class="text-muted small mt-2">
                        Ultime 10: {{ $block['a']['last10']['summary']['wins'] }}V
                        {{ $block['a']['last10']['summary']['draws'] }}N
                        {{ $block['a']['last10']['summary']['losses'] }}P
                        <span class="text-muted">({{ $block['a']['last10']['summary']['matches_played'] }} giocate)</span>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- D. Confronto pre-match --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Confronto pre-match <span class="text-muted small fw-normal">(stagione, prima del match)</span></h2>
    @php $hs = $homeAnalytics['season']['summary']; $as = $awayAnalytics['season']['summary']; @endphp
    @if($hs['matches_played'] === 0 && $as['matches_played'] === 0)
    <p class="text-muted">Dati precedenti non disponibili.</p>
    @else
    @php $ht = $homeAnalytics['season']['technical']; $at = $awayAnalytics['season']['technical']; @endphp
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 text-center align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-start ps-3">&nbsp;</th>
                        <th>{{ $match->homeTeam->name }}</th>
                        <th>{{ $match->awayTeam->name }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="text-start ps-3 text-muted">Partite giocate</td><td>{{ $hs['matches_played'] }}</td><td>{{ $as['matches_played'] }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">V / N / P</td><td>{{ $hs['wins'] }} / {{ $hs['draws'] }} / {{ $hs['losses'] }}</td><td>{{ $as['wins'] }} / {{ $as['draws'] }} / {{ $as['losses'] }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">GF / GS</td><td>{{ $hs['goals_for'] }} / {{ $hs['goals_against'] }}</td><td>{{ $as['goals_for'] }} / {{ $as['goals_against'] }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media GF</td><td>{{ $avg($hs['avg_goals_for']) }}</td><td>{{ $avg($as['avg_goals_for']) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media GS</td><td>{{ $avg($hs['avg_goals_against']) }}</td><td>{{ $avg($as['avg_goals_against']) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media tiri fatti</td><td>{{ $avg($ht['avg_shots_for'] ?? null) }}</td><td>{{ $avg($at['avg_shots_for'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media tiri subiti</td><td>{{ $avg($ht['avg_shots_against'] ?? null) }}</td><td>{{ $avg($at['avg_shots_against'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media tiri in porta fatti</td><td>{{ $avg($ht['avg_shots_on_target_for'] ?? null) }}</td><td>{{ $avg($at['avg_shots_on_target_for'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media corner fatti</td><td>{{ $avg($ht['avg_corners_for'] ?? null) }}</td><td>{{ $avg($at['avg_corners_for'] ?? null) }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

{{-- E. Split casa/trasferta --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Split casa / trasferta <span class="text-muted small fw-normal">(prima del match)</span></h2>
    <div class="row g-3">
        @foreach([
            ['label' => $match->homeTeam->name . ' — in casa', 'a' => $homeAnalytics['home_only']],
            ['label' => $match->awayTeam->name . ' — in trasferta', 'a' => $awayAnalytics['away_only']],
        ] as $block)
        @php $bs = $block['a']['summary']; @endphp
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small mb-2">{{ $block['label'] }} <span class="text-muted">({{ $bs['matches_played'] }} PG)</span></div>
                    @if($bs['matches_played'] === 0)
                        <p class="text-muted mb-0">Dati precedenti non disponibili.</p>
                    @else
                        <div class="small">V {{ $bs['wins'] }} · N {{ $bs['draws'] }} · P {{ $bs['losses'] }}</div>
                        <div class="small">GF {{ $bs['goals_for'] }} · GS {{ $bs['goals_against'] }}</div>
                        <div class="small text-muted">Media GF {{ $avg($bs['avg_goals_for']) }} · Media GS {{ $avg($bs['avg_goals_against']) }}</div>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- F. Trend pre-match --}}
<div class="mt-4 mb-4">
    <h2 class="fs-5 fw-semibold mb-3">Trend pre-match <span class="text-muted small fw-normal">(stagione, prima del match)</span></h2>
    <div class="row g-3">
        @foreach([['label' => $match->homeTeam->name, 'a' => $homeAnalytics['season']], ['label' => $match->awayTeam->name, 'a' => $awayAnalytics['season']]] as $block)
        @php $mt = $block['a']['market_trends']; @endphp
        <div class="col-md-6">
            <div class="text-muted small mb-2">{{ $block['label'] }}</div>
            @if($mt['coverage']['full_time'] === 0)
            <p class="text-muted">Dati precedenti non disponibili.</p>
            @else
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Mercato</th>
                                <th class="text-center pe-3">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="ps-3">GG</td>
                                <td class="text-center pe-3">
                                    {{ $pct($mt['btts']['yes']['percentage']) }}
                                    <span class="text-muted small">({{ $mt['btts']['yes']['count'] }}/{{ $mt['btts']['yes']['total'] }})</span>
                                </td>
                            </tr>
                            @foreach(['1_5' => 'Over 1.5', '2_5' => 'Over 2.5', '3_5' => 'Over 3.5'] as $key => $label)
                            <tr>
                                <td class="ps-3">{{ $label }}</td>
                                <td class="text-center pe-3">
                                    {{ $pct($mt['full_time_goals']['over_' . $key]['percentage']) }}
                                    <span class="text-muted small">({{ $mt['full_time_goals']['over_' . $key]['count'] }}/{{ $mt['full_time_goals']['over_' . $key]['total'] }})</span>
                                </td>
                            </tr>
                            @endforeach
                            @foreach(['0_5' => 'Over 0.5 HT', '1_5' => 'Over 1.5 HT'] as $key => $label)
                            <tr>
                                <td class="ps-3">{{ $label }}</td>
                                <td class="text-center pe-3">
                                    {{ $pct($mt['half_time_goals']['over_' . $key . '_ht']['percentage']) }}
                                    <span class="text-muted small">({{ $mt['half_time_goals']['over_' . $key . '_ht']['count'] }}/{{ $mt['half_time_goals']['over_' . $key . '_ht']['total'] }})</span>
                                </td>
                            </tr>
                            @endforeach
                            <tr>
                                <td class="ps-3">GG HT</td>
                                <td class="text-center pe-3">
                                    {{ $pct($mt['btts_half_time']['yes']['percentage']) }}
                                    <span class="text-muted small">({{ $mt['btts_half_time']['yes']['count'] }}/{{ $mt['btts_half_time']['yes']['total'] }})</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
        </div>
        @endforeach
    </div>
</div>

@endsection
