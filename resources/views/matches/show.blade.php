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
    $statusBadge = match ($match->status) {
        'finished'  => ['success', 'Terminata'],
        'live'      => ['danger', 'Live'],
        'scheduled' => ['secondary', 'Programmata'],
        'postponed' => ['warning', 'Rinviata'],
        'suspended' => ['warning', 'Sospesa'],
        'cancelled' => ['dark', 'Annullata'],
        default     => ['secondary', $match->status],
    };
    $kickoffRome = $match->kickoff_at?->copy()->setTimezone('Europe/Rome');
    $hasFt   = $match->home_score_ft !== null && $match->away_score_ft !== null;
    $hasHt   = $match->home_score_ht !== null && $match->away_score_ht !== null;
    $hasLive = $match->status === 'live'
        && $match->current_home_score !== null
        && $match->current_away_score !== null;
    $liveStatusLabel = match($match->live_status) {
        '1H'    => '1° tempo',
        'HT'    => 'Intervallo',
        '2H'    => '2° tempo',
        'ET'    => 'Supplementari',
        'P'     => 'Rigori',
        default => $match->live_status ?? 'Live',
    };
@endphp

{{-- A. Header --}}
<div class="mb-4">
    <div class="text-muted small mb-1">
        <a href="{{ route('competitions.seasons.show', ['competition' => $match->competition->slug, 'season' => $match->season->year_start]) }}" class="link-body-emphasis text-decoration-none">{{ $match->competition->name }}</a>
        · {{ $match->season->name }}
        @if($match->matchday) · Giornata {{ $match->matchday }} @elseif($match->round) · {{ $match->round }} @endif
        · <span class="badge bg-{{ $statusBadge[0] }}">{{ $statusBadge[1] }}</span>
    </div>
    <div class="d-flex align-items-center justify-content-center gap-3 py-3">
        <div class="text-end flex-fill fs-4 fw-semibold">
            <a href="{{ route('teams.show', $match->home_team_id) }}" class="link-body-emphasis text-decoration-none">{{ $match->homeTeam->name }}</a>
        </div>
        <div class="text-center px-3" style="min-width:110px">
            @if($match->status === 'live')
                @if($hasLive)
                    <div class="fs-2 fw-bold text-danger">{{ $match->current_home_score }} – {{ $match->current_away_score }}</div>
                    <div class="text-danger small">
                        {{ $liveStatusLabel }}@if($match->live_minute !== null) · {{ $match->live_minute }}'@endif
                    </div>
                @else
                    <div class="fs-4 text-danger">Live</div>
                @endif
            @elseif($hasFt)
                <div class="fs-2 fw-bold">{{ $match->home_score_ft }} – {{ $match->away_score_ft }}</div>
            @else
                <div class="fs-4 text-muted">vs</div>
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
        @if(in_array($match->status, ['finished', 'live'], true))
        <p class="text-muted">Statistiche in aggiornamento.</p>
        @else
        <p class="text-muted">Statistiche non disponibili per questa partita.</p>
        @endif
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

{{-- C. Match Events --}}
@if($matchEvents->isNotEmpty())
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Eventi partita</h2>
    <div class="card">
        <div class="card-body p-0">
            @foreach($matchEvents as $event)
            @php
                $isHome    = $event->team_id === $match->home_team_id;
                $minLabel  = ($event->minute_label ?? $event->minute) . "'";
                $eventIcon = match($event->event_type) {
                    'goal'           => '<span class="badge bg-success">GOL</span>',
                    'yellow_card'    => '<span class="badge bg-warning text-dark">&#9646;</span>',
                    'yellow_red_card'=> '<span class="badge bg-warning text-dark">&#9646;</span><span class="badge bg-danger ms-1">&#9646;</span>',
                    'red_card'       => '<span class="badge bg-danger">&#9646;</span>',
                    'substitution'   => '<span class="text-muted small">&#8593;&#8595;</span>',
                    default          => '',
                };
            @endphp
            <div class="d-flex align-items-center px-2 py-1 {{ !$loop->last ? 'border-bottom' : '' }}">
                {{-- Home side --}}
                <div class="flex-fill text-end pe-2 small">
                    @if($isHome)
                        @if($event->event_type === 'goal')
                            {!! $eventIcon !!}
                            <span class="fw-semibold">{{ $event->player_name ?? '–' }}</span>
                            @if($event->related_player_name)
                                <span class="text-muted">({{ $event->related_player_name }})</span>
                            @endif
                        @elseif($event->event_type === 'substitution')
                            <span class="text-success small">&#8593; {{ $event->related_player_name ?? '–' }}</span>
                            <span class="text-danger small ms-1">&#8595; {{ $event->player_name ?? '–' }}</span>
                        @else
                            {!! $eventIcon !!} {{ $event->player_name ?? '–' }}
                        @endif
                    @endif
                </div>
                {{-- Minute --}}
                <div class="text-muted small text-center fw-semibold" style="width:48px;flex-shrink:0">{{ $minLabel }}</div>
                {{-- Away side --}}
                <div class="flex-fill text-start ps-2 small">
                    @if(!$isHome)
                        @if($event->event_type === 'goal')
                            {!! $eventIcon !!}
                            <span class="fw-semibold">{{ $event->player_name ?? '–' }}</span>
                            @if($event->related_player_name)
                                <span class="text-muted">({{ $event->related_player_name }})</span>
                            @endif
                        @elseif($event->event_type === 'substitution')
                            <span class="text-success small">&#8593; {{ $event->related_player_name ?? '–' }}</span>
                            <span class="text-danger small ms-1">&#8595; {{ $event->player_name ?? '–' }}</span>
                        @else
                            {!! $eventIcon !!} {{ $event->player_name ?? '–' }}
                        @endif
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endif

@if($match->status !== 'finished')
{{-- E. Forma prima del match --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Forma prima del match</h2>
    <div class="row g-3">
        @foreach([
            ['label' => $match->homeTeam->name, 'last5' => $homeLast5Analytics, 'last10' => $homeLast10Analytics],
            ['label' => $match->awayTeam->name, 'last5' => $awayLast5Analytics, 'last10' => $awayLast10Analytics],
        ] as $block)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small mb-2">{{ $block['label'] }} <span class="text-muted">(ultime 5)</span></div>
                    @if(empty($block['last5']['form']))
                        <span class="text-muted">Dati precedenti non disponibili</span>
                    @else
                        @foreach($block['last5']['form'] as $r)
                            <span class="badge bg-{{ $badgeClass($r) }} me-1">{{ $r }}</span>
                        @endforeach
                    @endif
                    <div class="text-muted small mt-2 mb-1">Ultime 10</div>
                    @if(empty($block['last10']['form']))
                        <span class="text-muted">Dati precedenti non disponibili</span>
                    @else
                        @foreach($block['last10']['form'] as $r)
                            <span class="badge bg-{{ $badgeClass($r) }} me-1">{{ $r }}</span>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- D. Confronto pre-match --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Confronto pre-match <span class="text-muted small fw-normal">(stagione, prima del match)</span></h2>
    @php $hs = $homeSeasonAnalytics['summary']; $as = $awaySeasonAnalytics['summary']; @endphp
    @if($hs['matches_played'] === 0 && $as['matches_played'] === 0)
    <p class="text-muted">Dati stagionali precedenti non disponibili.</p>
    @else
    @php $ht = $homeSeasonAnalytics['technical']; $at = $awaySeasonAnalytics['technical']; @endphp
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
                    <tr><td class="text-start ps-3 text-muted">Punti</td><td>{{ $hs['points'] }}</td><td>{{ $as['points'] }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">GF / GS</td><td>{{ $hs['goals_for'] }} / {{ $hs['goals_against'] }}</td><td>{{ $as['goals_for'] }} / {{ $as['goals_against'] }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Differenza reti</td><td>{{ $hs['goal_difference'] >= 0 ? '+' : '' }}{{ $hs['goal_difference'] }}</td><td>{{ $as['goal_difference'] >= 0 ? '+' : '' }}{{ $as['goal_difference'] }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media GF</td><td>{{ $avg($hs['avg_goals_for']) }}</td><td>{{ $avg($as['avg_goals_for']) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media GS</td><td>{{ $avg($hs['avg_goals_against']) }}</td><td>{{ $avg($as['avg_goals_against']) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media gol totali</td><td>{{ $avg($hs['avg_total_goals']) }}</td><td>{{ $avg($as['avg_total_goals']) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media tiri fatti</td><td>{{ $avg($ht['avg_shots_for'] ?? null) }}</td><td>{{ $avg($at['avg_shots_for'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media tiri subiti</td><td>{{ $avg($ht['avg_shots_against'] ?? null) }}</td><td>{{ $avg($at['avg_shots_against'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media tiri in porta fatti</td><td>{{ $avg($ht['avg_shots_on_target_for'] ?? null) }}</td><td>{{ $avg($at['avg_shots_on_target_for'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media tiri in porta subiti</td><td>{{ $avg($ht['avg_shots_on_target_against'] ?? null) }}</td><td>{{ $avg($at['avg_shots_on_target_against'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media corner fatti</td><td>{{ $avg($ht['avg_corners_for'] ?? null) }}</td><td>{{ $avg($at['avg_corners_for'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media corner subiti</td><td>{{ $avg($ht['avg_corners_against'] ?? null) }}</td><td>{{ $avg($at['avg_corners_against'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media cartellini gialli</td><td>{{ $avg($ht['avg_yellow_cards'] ?? null) }}</td><td>{{ $avg($at['avg_yellow_cards'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media cartellini rossi</td><td>{{ $avg($ht['avg_red_cards'] ?? null) }}</td><td>{{ $avg($at['avg_red_cards'] ?? null) }}</td></tr>
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
            ['label' => $match->homeTeam->name . ' — in casa', 'a' => $homeHomeAnalytics],
            ['label' => $match->awayTeam->name . ' — in trasferta', 'a' => $awayAwayAnalytics],
        ] as $block)
        @php $bs = $block['a']['summary']; $bt = $block['a']['technical']; $bmt = $block['a']['market_trends']; @endphp
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
                        <div class="small text-muted">Media tiri fatti {{ $avg($bt['avg_shots_for'] ?? null) }} · subiti {{ $avg($bt['avg_shots_against'] ?? null) }}</div>
                        <div class="small mt-1">
                            GG {{ $pct($bmt['btts']['yes']['percentage']) }}
                            <span class="text-muted">({{ $bmt['btts']['yes']['count'] }}/{{ $bmt['btts']['yes']['total'] }})</span>
                            · Over 2.5 {{ $pct($bmt['full_time_goals']['over_2_5']['percentage']) }}
                            <span class="text-muted">({{ $bmt['full_time_goals']['over_2_5']['count'] }}/{{ $bmt['full_time_goals']['over_2_5']['total'] }})</span>
                        </div>
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
        @foreach([['label' => $match->homeTeam->name, 'a' => $homeSeasonAnalytics], ['label' => $match->awayTeam->name, 'a' => $awaySeasonAnalytics]] as $block)
        @php $mt = $block['a']['market_trends']; @endphp
        <div class="col-md-6">
            <div class="text-muted small mb-2">{{ $block['label'] }}</div>
            @if($mt['coverage']['full_time'] === 0)
            <p class="text-muted">Dati stagionali precedenti non disponibili.</p>
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
                            <tr>
                                <td class="ps-3">NG</td>
                                <td class="text-center pe-3">
                                    {{ $pct($mt['btts']['no']['percentage']) }}
                                    <span class="text-muted small">({{ $mt['btts']['no']['count'] }}/{{ $mt['btts']['no']['total'] }})</span>
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

{{-- G. H2H --}}
<div class="mt-4 mb-4">
    <h2 class="fs-5 fw-semibold mb-3">Precedenti <span class="text-muted small fw-normal">(stessa competition, prima del match)</span></h2>
    @if($headToHead['total_h2h'] === 0)
    <p class="text-muted">Nessun precedente disponibile.</p>
    @else
    <div class="card mb-3">
        <div class="card-body p-3">
            <div class="row text-center">
                <div class="col-4">
                    <div class="fw-bold fs-5">{{ $headToHead['target_home_team_wins'] }}</div>
                    <div class="text-muted small">{{ $match->homeTeam->name }}</div>
                </div>
                <div class="col-4">
                    <div class="fw-bold fs-5">{{ $headToHead['draws'] }}</div>
                    <div class="text-muted small">Pareggi</div>
                </div>
                <div class="col-4">
                    <div class="fw-bold fs-5">{{ $headToHead['target_away_team_wins'] }}</div>
                    <div class="text-muted small">{{ $match->awayTeam->name }}</div>
                </div>
            </div>
            <hr>
            <div class="row text-center">
                <div class="col-4">
                    <div class="fw-bold">{{ $pct($headToHead['btts']['percentage']) }}</div>
                    <div class="text-muted small">GG ({{ $headToHead['btts']['count'] }}/{{ $headToHead['btts']['total'] }})</div>
                </div>
                <div class="col-4">
                    <div class="fw-bold">{{ $pct($headToHead['over_2_5']['percentage']) }}</div>
                    <div class="text-muted small">Over 2.5 ({{ $headToHead['over_2_5']['count'] }}/{{ $headToHead['over_2_5']['total'] }})</div>
                </div>
                <div class="col-4">
                    <div class="fw-bold">{{ $avg($headToHead['avg_total_goals']) }}</div>
                    <div class="text-muted small">Media gol</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Data</th>
                        <th class="text-end">Casa</th>
                        <th class="text-center" style="width:90px">Risultato</th>
                        <th>Trasferta</th>
                        <th class="pe-3">Stagione</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($h2hMatches as $h)
                    @php $hKickoff = $h->kickoff_at?->copy()->setTimezone('Europe/Rome'); @endphp
                    <tr>
                        <td class="ps-3 text-muted small text-nowrap">{{ $hKickoff?->format('d/m/Y') ?? '–' }}</td>
                        <td class="text-end fw-semibold">{{ $h->homeTeam->name }}</td>
                        <td class="text-center fw-bold">
                            <a href="{{ route('matches.show', $h->id) }}" class="link-body-emphasis text-decoration-none">{{ $h->home_score_ft }} – {{ $h->away_score_ft }}</a>
                        </td>
                        <td class="fw-semibold">{{ $h->awayTeam->name }}</td>
                        <td class="pe-3 text-muted small">{{ $h->season->name }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@endif

@endsection
