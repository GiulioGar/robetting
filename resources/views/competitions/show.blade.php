@extends('layouts.app')

@section('title', $competition->name . ' · Giornata ' . $currentMatchday . ' · ' . $season->name)

@section('content')

{{-- Header: competition name + season selector --}}
<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h1 class="mb-0 fs-3">{{ $competition->name }}</h1>
        @if($competition->country)
        <div class="text-muted small">{{ $competition->country->name }}</div>
        @endif
    </div>
    <select class="form-select form-select-sm mt-1" style="width:auto;min-width:100px"
            onchange="location.href=this.value">
        @foreach($allSeasons as $s)
        <option value="{{ route('competitions.seasons.show', ['competition' => $competition->slug, 'season' => $s->year_start]) }}"
                @selected($s->id === $season->id)>{{ $s->name }}</option>
        @endforeach
    </select>
</div>

{{-- Tab navigation --}}
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link active fw-semibold"
           href="{{ route('competitions.seasons.show', ['competition' => $competition->slug, 'season' => $season->year_start]) }}">
            Overview
        </a>
    </li>
    <li class="nav-item">
        <span class="nav-link disabled text-muted" tabindex="-1">Calendario</span>
    </li>
    <li class="nav-item">
        <span class="nav-link disabled text-muted" tabindex="-1">Classifica</span>
    </li>
    <li class="nav-item">
        <span class="nav-link disabled text-muted" tabindex="-1">Statistiche</span>
    </li>
</ul>

{{-- Matchday navigation --}}
<div class="d-flex align-items-center justify-content-between mb-3">

    {{-- Previous --}}
    @if($currentMatchday > $minMatchday)
        <a href="{{ route('competitions.seasons.show', ['competition' => $competition->slug, 'season' => $season->year_start, 'matchday' => $currentMatchday - 1]) }}"
           class="btn btn-sm btn-outline-secondary">&#8592;</a>
    @else
        <span class="btn btn-sm btn-outline-secondary disabled">&#8592;</span>
    @endif

    {{-- Current matchday label --}}
    <div class="text-center">
        <div class="fw-bold fs-5">Giornata {{ $currentMatchday }}</div>
        @if($window['first'])
        @php
            $rFirst = $window['first']->copy()->setTimezone('Europe/Rome');
            $rLast  = $window['last']->copy()->setTimezone('Europe/Rome');
        @endphp
        <div class="text-muted small">
            @if($rFirst->isSameDay($rLast))
                {{ $rFirst->format('d/m/Y') }}
            @else
                {{ $rFirst->format('d/m') }} – {{ $rLast->format('d/m/Y') }}
            @endif
        </div>
        @endif
    </div>

    {{-- Next --}}
    @if($currentMatchday < $maxMatchday)
        <a href="{{ route('competitions.seasons.show', ['competition' => $competition->slug, 'season' => $season->year_start, 'matchday' => $currentMatchday + 1]) }}"
           class="btn btn-sm btn-outline-secondary">&#8594;</a>
    @else
        <span class="btn btn-sm btn-outline-secondary disabled">&#8594;</span>
    @endif

</div>

{{-- Match list --}}
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th class="text-muted fw-normal small ps-3">Data</th>
                    <th class="text-muted fw-normal small">Ora</th>
                    <th class="text-end">Casa</th>
                    <th class="text-center px-3" style="width:90px">Risultato</th>
                    <th>Trasferta</th>
                    <th class="text-center pe-3" style="width:70px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($matches as $match)
                @php
                    $kickoffRome = $match->kickoff_at?->copy()->setTimezone('Europe/Rome');
                    $hasScore    = $match->home_score_ft !== null && $match->away_score_ft !== null;
                    $badgeClass  = match($match->status) {
                        'finished'  => 'success',
                        'live'      => 'danger',
                        'postponed' => 'warning',
                        'suspended' => 'warning',
                        'cancelled' => 'dark',
                        default     => 'secondary',
                    };
                @endphp
                <tr>
                    <td class="text-muted small ps-3 text-nowrap">
                        {{ $kickoffRome?->format('d/m') ?? '–' }}
                    </td>
                    <td class="text-muted small text-nowrap">
                        {{ $kickoffRome?->format('H:i') ?? '–' }}
                    </td>
                    <td class="text-end fw-semibold">
                        <a href="{{ route('teams.show', $match->home_team_id) }}" class="link-body-emphasis text-decoration-none">{{ $match->homeTeam->name }}</a>
                    </td>
                    <td class="text-center px-3">
                        @if($hasScore)
                            <a href="{{ route('matches.show', $match->id) }}" class="fw-bold link-body-emphasis text-decoration-none">{{ $match->home_score_ft }} – {{ $match->away_score_ft }}</a>
                        @elseif($match->status === 'live')
                            <span class="text-danger fw-bold">Live</span>
                        @else
                            <a href="{{ route('matches.show', $match->id) }}" class="text-muted text-decoration-none">–</a>
                        @endif
                    </td>
                    <td class="fw-semibold">
                        <a href="{{ route('teams.show', $match->away_team_id) }}" class="link-body-emphasis text-decoration-none">{{ $match->awayTeam->name }}</a>
                    </td>
                    <td class="text-center pe-3">
                        @if($match->status === 'finished')
                            <span class="badge bg-{{ $badgeClass }}">Fin.</span>
                        @elseif($match->status !== 'scheduled')
                            <span class="badge bg-{{ $badgeClass }}">
                                {{ match($match->status) {
                                    'live'      => 'Live',
                                    'postponed' => 'Rinv.',
                                    'suspended' => 'Sosp.',
                                    'cancelled' => 'Ann.',
                                    default     => $match->status,
                                } }}
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">Nessuna partita trovata per la giornata {{ $currentMatchday }}.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>


{{-- Standings (league format only) --}}
@if($standings !== null)

{{-- Zone row indicators: one border-left color per zone type, generated from config --}}
@if(!empty($zoneLegend))
<style>
@foreach($zoneLegend as $z)
.{{ $z['css_class'] }} td:first-child { border-left: 3px solid {{ $z['color'] }}; }
@endforeach
</style>
@endif

<div class="mt-5">
    <div class="d-flex align-items-baseline justify-content-between mb-3">
        <h2 class="fs-5 fw-semibold mb-0">Classifica</h2>
        <a href="{{ route('competitions.seasons.zones.index', ['competition' => $competition->slug, 'season' => $season->year_start]) }}"
           class="small text-muted">Gestisci fasce</a>
    </div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-center ps-3" style="width:40px">#</th>
                        <th>Squadra</th>
                        <th class="text-center">PG</th>
                        <th class="text-center">V</th>
                        <th class="text-center">N</th>
                        <th class="text-center">P</th>
                        <th class="text-center">GF</th>
                        <th class="text-center">GS</th>
                        <th class="text-center">DR</th>
                        <th class="text-center fw-bold pe-3">PT</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($standings as $row)
                    @php $zone = $zoneMap[$row['position']] ?? null; @endphp
                    <tr data-position="{{ $row['position'] }}"
                        @if($zone) data-zone="{{ $zone['type'] }}" class="{{ $zone['css_class'] }}" @endif>
                        <td class="text-center text-muted small ps-3">{{ $row['position'] }}</td>
                        <td class="fw-semibold">
                            <a href="{{ route('teams.show', $row['team_id']) }}" class="link-body-emphasis text-decoration-none">{{ $row['name'] }}</a>
                        </td>
                        <td class="text-center">{{ $row['played'] }}</td>
                        <td class="text-center">{{ $row['wins'] }}</td>
                        <td class="text-center">{{ $row['draws'] }}</td>
                        <td class="text-center">{{ $row['losses'] }}</td>
                        <td class="text-center">{{ $row['goals_for'] }}</td>
                        <td class="text-center">{{ $row['goals_against'] }}</td>
                        <td class="text-center">{{ $row['goal_difference'] >= 0 ? '+' . $row['goal_difference'] : $row['goal_difference'] }}</td>
                        <td class="text-center fw-bold pe-3">{{ $row['points'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Zone legend --}}
    @if(!empty($zoneLegend))
    <div class="d-flex flex-wrap gap-3 mt-2">
        @foreach($zoneLegend as $z)
        <span class="small text-muted d-flex align-items-center gap-1">
            <span style="display:inline-block;width:10px;height:10px;background-color:{{ $z['color'] }};border-radius:2px;flex-shrink:0"></span>
            {{ $z['label'] }}
        </span>
        @endforeach
    </div>
    @endif

    <p class="text-muted small mt-1">
        Ordinamento: PT → DR → GF → nome. Scontri diretti non considerati.
    </p>
</div>
@endif

{{-- Statistics (league format only) --}}
@if($statistics !== null)
<div class="mt-5">
    <h2 class="fs-5 fw-semibold mb-3">Statistiche</h2>

    @if(!$statistics['available'])
    <p class="text-muted">Statistiche disponibili dopo l'inizio della stagione.</p>
    @else
    @php
        $teamList = fn(array $entries) => collect($entries)->pluck('team')->implode(', ');
    @endphp
    <div class="row g-3">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small">Media gol / partita</div>
                    <div class="fs-5 fw-bold">{{ number_format($statistics['avg_goals_per_match'], 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small">Miglior attacco</div>
                    <div class="fw-bold">{{ $teamList($statistics['best_attack']) }}</div>
                    <div class="text-muted small">{{ $statistics['best_attack'][0]['value'] }} gol fatti</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small">Miglior difesa</div>
                    <div class="fw-bold">{{ $teamList($statistics['best_defense']) }}</div>
                    <div class="text-muted small">{{ $statistics['best_defense'][0]['value'] }} gol subiti</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small">Miglior differenza reti</div>
                    <div class="fw-bold">{{ $teamList($statistics['best_goal_difference']) }}</div>
                    @php $bgd = $statistics['best_goal_difference'][0]['value']; @endphp
                    <div class="text-muted small">{{ $bgd >= 0 ? '+' . $bgd : $bgd }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small">Peggior difesa</div>
                    <div class="fw-bold">{{ $teamList($statistics['worst_defense']) }}</div>
                    <div class="text-muted small">{{ $statistics['worst_defense'][0]['value'] }} gol subiti</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small">Più vittorie</div>
                    <div class="fw-bold">{{ $teamList($statistics['most_wins']) }}</div>
                    <div class="text-muted small">{{ $statistics['most_wins'][0]['value'] }} vittorie</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small mb-1">Partita con più gol</div>
                    @foreach($statistics['highest_scoring_match'] as $m)
                    <div class="fw-semibold">
                        {{ $m['home_team'] }} {{ $m['home_score'] }} – {{ $m['away_score'] }} {{ $m['away_team'] }}
                        <span class="text-muted small fw-normal">({{ $m['total_goals'] }} gol @if($m['kickoff_at']){{ ' · ' . $m['kickoff_at']->copy()->setTimezone('Europe/Rome')->format('d/m/Y') }}@endif)</span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small mb-1">Vittoria più larga</div>
                    @forelse($statistics['biggest_win'] as $m)
                    <div class="fw-semibold">
                        {{ $m['home_team'] }} {{ $m['home_score'] }} – {{ $m['away_score'] }} {{ $m['away_team'] }}
                        <span class="text-muted small fw-normal">({{ $m['winning_team'] }} +{{ $m['margin'] }} @if($m['kickoff_at']){{ ' · ' . $m['kickoff_at']->copy()->setTimezone('Europe/Rome')->format('d/m/Y') }}@endif)</span>
                    </div>
                    @empty
                    <div class="text-muted small">Nessuna vittoria registrata (solo pareggi finora).</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Gameplay stats (match_statistics — may be entirely unavailable for a season/source) --}}
    @php
        $gp = $statistics['gameplay'];
        $fmt = fn(?float $v, string $suffix = '') => $v === null ? '–' : number_format($v, 2) . $suffix;
        $coverageNote = function(?array $metric) {
            if ($metric === null || $metric['coverage']['coverage_percent'] >= 100) {
                return null;
            }
            return 'Copertura ' . rtrim(rtrim(number_format($metric['coverage']['coverage_percent'], 1), '0'), '.') . '%';
        };
    @endphp
    <div class="mt-5">
        <h3 class="fs-6 fw-semibold mb-3">Dati di gioco</h3>
        <div class="row g-3">
            @foreach([
                ['label' => 'Tiri medi per squadra',        'key' => 'avg_team_shots'],
                ['label' => 'Tiri in porta medi per squadra', 'key' => 'avg_team_shots_on_target'],
                ['label' => 'Corner medi per squadra',       'key' => 'avg_team_corners'],
                ['label' => 'Falli medi per squadra',        'key' => 'avg_team_fouls'],
                ['label' => 'Gialli medi per squadra',       'key' => 'avg_team_yellow_cards'],
                ['label' => 'Rossi medi per squadra',        'key' => 'avg_team_red_cards'],
            ] as $card)
            @php $metric = $gp['competition'][$card['key']] ?? null; @endphp
            <div class="col-6 col-md-4 col-lg-2">
                <div class="card h-100">
                    <div class="card-body p-3">
                        <div class="text-muted small">{{ $card['label'] }}</div>
                        <div class="fs-5 fw-bold">{{ $fmt($metric['value'] ?? null) }}</div>
                        @if($coverageNote($metric))
                        <div class="text-muted small">{{ $coverageNote($metric) }}</div>
                        @endif
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <h3 class="fs-6 fw-semibold mt-4 mb-3">Leader</h3>
        <div class="row g-3">
            @foreach([
                ['label' => 'Più tiri medi',          'key' => 'most_shots',           'suffix' => ' tiri'],
                ['label' => 'Più tiri in porta medi', 'key' => 'most_shots_on_target', 'suffix' => ' tiri in porta'],
                ['label' => 'Più corner medi',        'key' => 'most_corners',         'suffix' => ' corner'],
                ['label' => 'Meno tiri subiti medi',  'key' => 'fewest_shots_against', 'suffix' => ' tiri subiti'],
            ] as $card)
            @php $entries = $gp['leaders'][$card['key']] ?? []; @endphp
            <div class="col-6 col-md-3">
                <div class="card h-100">
                    <div class="card-body p-3">
                        <div class="text-muted small">{{ $card['label'] }}</div>
                        @if(empty($entries))
                        <div class="fw-bold">–</div>
                        @else
                        <div class="fw-bold">{{ $teamList($entries) }}</div>
                        <div class="text-muted small">{{ number_format($entries[0]['value'], 2) }}{{ $card['suffix'] }}</div>
                        @endif
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endif

{{-- Market trends (derived from canonical FT/HT scores — independent of match_statistics) --}}
@if($marketTrends !== null)
<div class="mt-5">
    <h2 class="fs-5 fw-semibold mb-3">Trend risultati</h2>

    @if($marketTrends['coverage']['full_time'] === 0)
    <p class="text-muted">Trend disponibili dopo l'inizio della stagione.</p>
    @else
    @php
        $pct = fn(?float $p) => $p === null ? '–' : number_format($p, 1) . '%';
    @endphp

    <div class="card mb-3">
        <div class="card-body p-3">
            <div class="text-muted small mb-2">1X2 <span class="text-muted">(su {{ $marketTrends['coverage']['full_time'] }} partite)</span></div>
            <div class="row text-center">
                <div class="col-4">
                    <div class="fw-bold fs-5">{{ $pct($marketTrends['result_1x2']['home']['percentage']) }}</div>
                    <div class="text-muted small">Casa ({{ $marketTrends['result_1x2']['home']['count'] }}/{{ $marketTrends['result_1x2']['home']['total'] }})</div>
                </div>
                <div class="col-4">
                    <div class="fw-bold fs-5">{{ $pct($marketTrends['result_1x2']['draw']['percentage']) }}</div>
                    <div class="text-muted small">Pareggio ({{ $marketTrends['result_1x2']['draw']['count'] }}/{{ $marketTrends['result_1x2']['draw']['total'] }})</div>
                </div>
                <div class="col-4">
                    <div class="fw-bold fs-5">{{ $pct($marketTrends['result_1x2']['away']['percentage']) }}</div>
                    <div class="text-muted small">Trasferta ({{ $marketTrends['result_1x2']['away']['count'] }}/{{ $marketTrends['result_1x2']['away']['total'] }})</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body p-3">
            <div class="text-muted small mb-2">GG/NG</div>
            <div class="row text-center">
                <div class="col-6">
                    <div class="fw-bold fs-5">{{ $pct($marketTrends['btts']['yes']['percentage']) }}</div>
                    <div class="text-muted small">GG ({{ $marketTrends['btts']['yes']['count'] }}/{{ $marketTrends['btts']['yes']['total'] }})</div>
                </div>
                <div class="col-6">
                    <div class="fw-bold fs-5">{{ $pct($marketTrends['btts']['no']['percentage']) }}</div>
                    <div class="text-muted small">NG ({{ $marketTrends['btts']['no']['count'] }}/{{ $marketTrends['btts']['no']['total'] }})</div>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-1 text-muted small">Over / Under — Full Time</div>
    <div class="card mb-4">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Linea</th>
                        <th class="text-center">Over</th>
                        <th class="text-center pe-3">Under</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(['0_5' => '0.5', '1_5' => '1.5', '2_5' => '2.5', '3_5' => '3.5', '4_5' => '4.5'] as $key => $label)
                    <tr>
                        <td class="ps-3">{{ $label }}</td>
                        <td class="text-center">
                            {{ $pct($marketTrends['full_time_goals']['over_' . $key]['percentage']) }}
                            <span class="text-muted small">({{ $marketTrends['full_time_goals']['over_' . $key]['count'] }}/{{ $marketTrends['full_time_goals']['over_' . $key]['total'] }})</span>
                        </td>
                        <td class="text-center pe-3">
                            {{ $pct($marketTrends['full_time_goals']['under_' . $key]['percentage']) }}
                            <span class="text-muted small">({{ $marketTrends['full_time_goals']['under_' . $key]['count'] }}/{{ $marketTrends['full_time_goals']['under_' . $key]['total'] }})</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mb-1 text-muted small">Primo tempo <span class="text-muted">(su {{ $marketTrends['coverage']['half_time'] }} partite)</span></div>
    @if($marketTrends['coverage']['half_time'] === 0)
    <p class="text-muted small">–</p>
    @else
    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Linea</th>
                        <th class="text-center">Over</th>
                        <th class="text-center pe-3">Under</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(['0_5' => '0.5 HT', '1_5' => '1.5 HT', '2_5' => '2.5 HT'] as $key => $label)
                    <tr>
                        <td class="ps-3">{{ $label }}</td>
                        <td class="text-center">
                            {{ $pct($marketTrends['half_time_goals']['over_' . $key . '_ht']['percentage']) }}
                            <span class="text-muted small">({{ $marketTrends['half_time_goals']['over_' . $key . '_ht']['count'] }}/{{ $marketTrends['half_time_goals']['over_' . $key . '_ht']['total'] }})</span>
                        </td>
                        <td class="text-center pe-3">
                            {{ $pct($marketTrends['half_time_goals']['under_' . $key . '_ht']['percentage']) }}
                            <span class="text-muted small">({{ $marketTrends['half_time_goals']['under_' . $key . '_ht']['count'] }}/{{ $marketTrends['half_time_goals']['under_' . $key . '_ht']['total'] }})</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-3">
            <div class="text-muted small mb-2">GG HT / NG HT</div>
            <div class="row text-center">
                <div class="col-6">
                    <div class="fw-bold fs-5">{{ $pct($marketTrends['btts_half_time']['yes']['percentage']) }}</div>
                    <div class="text-muted small">GG HT ({{ $marketTrends['btts_half_time']['yes']['count'] }}/{{ $marketTrends['btts_half_time']['yes']['total'] }})</div>
                </div>
                <div class="col-6">
                    <div class="fw-bold fs-5">{{ $pct($marketTrends['btts_half_time']['no']['percentage']) }}</div>
                    <div class="text-muted small">NG HT ({{ $marketTrends['btts_half_time']['no']['count'] }}/{{ $marketTrends['btts_half_time']['no']['total'] }})</div>
                </div>
            </div>
        </div>
    </div>
    @endif
    @endif
</div>
@endif

@endsection
