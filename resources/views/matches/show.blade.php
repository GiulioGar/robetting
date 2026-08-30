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

@if(app()->isLocal())
{{-- Local-only: manual API-Football data refresh --}}
<div class="mb-3 d-flex align-items-start gap-3 flex-wrap">
    <form method="POST" action="{{ route('matches.update-all', $match) }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-warning">
            Aggiorna tutti i dati (API-Football)
        </button>
    </form>
    @if(session('update_result'))
    @php $ur = session('update_result'); @endphp
    <div class="small text-muted">
        <strong>Status:</strong> {{ $ur['status'] }} &middot;
        <strong>API calls:</strong> {{ $ur['api_calls'] }} &middot;
        result: {{ $ur['result']['outcome'] ?? '–' }} &middot;
        lineup: {{ $ur['lineup']['outcome'] ?? '–' }} &middot;
        events: {{ $ur['events']['outcome'] ?? '–' }} &middot;
        stats: {{ $ur['statistics']['outcome'] ?? '–' }}
        @if(!empty($ur['warnings']))
            &middot; <span class="text-warning">{{ implode('; ', $ur['warnings']) }}</span>
        @endif
    </div>
    @endif
</div>
@endif

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
                    'own_goal'       => '<span class="badge bg-secondary">AUTOGOL</span>',
                    'missed_penalty' => '<span class="badge bg-secondary">RIG. SBAG.</span>',
                    'yellow_card'    => '<span class="badge bg-warning text-dark">&#9646;</span>',
                    'yellow_red_card'=> '<span class="badge bg-warning text-dark">&#9646;</span><span class="badge bg-danger ms-1">&#9646;</span>',
                    'red_card'       => '<span class="badge bg-danger">&#9646;</span>',
                    'substitution'   => '<span class="text-muted small">&#8593;&#8595;</span>',
                    'var'            => '<span class="badge bg-info text-dark">VAR</span>',
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
                        @elseif($event->event_type === 'own_goal')
                            {!! $eventIcon !!} {{ $event->player_name ?? '–' }}
                        @elseif($event->event_type === 'missed_penalty')
                            {!! $eventIcon !!} {{ $event->player_name ?? '–' }}
                        @elseif($event->event_type === 'var')
                            {!! $eventIcon !!}
                            @if(!empty($event->detail['api_detail']))
                                <span class="text-muted small">{{ $event->detail['api_detail'] }}</span>
                            @endif
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
                        @elseif($event->event_type === 'own_goal')
                            {!! $eventIcon !!} {{ $event->player_name ?? '–' }}
                        @elseif($event->event_type === 'missed_penalty')
                            {!! $eventIcon !!} {{ $event->player_name ?? '–' }}
                        @elseif($event->event_type === 'var')
                            {!! $eventIcon !!}
                            @if(!empty($event->detail['api_detail']))
                                <span class="text-muted small">{{ $event->detail['api_detail'] }}</span>
                            @endif
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

{{-- D. Formazioni --}}
@if($lineups->isNotEmpty())
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Formazioni</h2>
    <div class="row g-3">
        @foreach([$match->home_team_id, $match->away_team_id] as $teamId)
        @php
            $isHome   = $teamId === $match->home_team_id;
            $teamName = $isHome ? $match->homeTeam->name : $match->awayTeam->name;
            $lineup   = $lineups->get($teamId);
            $starters = $lineup
                ? $lineup->players->where('is_starter', true)->sortBy(fn($p) => $p->shirt_number ?? PHP_INT_MAX)->values()
                : collect();
            $bench    = $lineup
                ? $lineup->players->where('is_starter', false)->sortBy(fn($p) => $p->shirt_number ?? PHP_INT_MAX)->values()
                : collect();
        @endphp
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-3">
                    @if($lineup === null)
                        <div class="fw-semibold mb-1">{{ $teamName }}</div>
                        <p class="text-muted small mb-0">Formazione non ancora disponibile.</p>
                    @else
                        <div class="mb-1">
                            <span class="fw-semibold">{{ $teamName }}</span>
                            @if($lineup->formation)
                                <span class="badge bg-secondary ms-2">{{ $lineup->formation }}</span>
                            @endif
                        </div>
                        @if($lineup->coach_name)
                            <div class="text-muted small mb-2">Allenatore: {{ $lineup->coach_name }}</div>
                        @endif

                        @php
                            $gridStarters = $starters->filter(function($p) {
                                if (empty($p->grid) || !str_contains($p->grid, ':')) { return false; }
                                [$gr, $gc] = explode(':', $p->grid, 2);
                                return is_numeric($gr) && is_numeric($gc) && (int)$gr > 0 && (int)$gc > 0;
                            });

                            if ($gridStarters->isNotEmpty()) {
                                $parsedGrid = $gridStarters->map(function($p) {
                                    [$gr, $gc] = explode(':', $p->grid, 2);
                                    $parts = explode(' ', trim($p->player_name));
                                    $sn    = count($parts) > 1 ? end($parts) : $parts[0];
                                    $sn    = mb_strlen($sn) > 8 ? mb_substr($sn, 0, 7) . '.' : $sn;
                                    $lbl   = $p->shirt_number ?? implode('', array_map(
                                        fn($w) => mb_strtoupper(mb_substr($w, 0, 1)),
                                        array_filter(explode(' ', trim($p->player_name)))
                                    ));
                                    return ['player' => $p, 'row' => (int)$gr, 'col' => (int)$gc, 'shortName' => $sn, 'label' => (string)$lbl];
                                })->values();

                                $Rmax    = $parsedGrid->max('row');
                                $rowNr   = $parsedGrid->groupBy('row')->map(fn($g) => $g->max('col'));
                                $pitched = $parsedGrid->map(function($pd) use ($Rmax, $rowNr) {
                                    $Nr = $rowNr[$pd['row']];
                                    return array_merge($pd, [
                                        'x' => round($pd['col'] / ($Nr + 1) * 100, 1),
                                        'y' => round(($Rmax - $pd['row'] + 1) / ($Rmax + 1) * 100, 1),
                                    ]);
                                });
                                $hasPitch = true;
                            } else {
                                $hasPitch = false;
                                $pitched  = collect();
                            }
                        @endphp

                        @if($hasPitch)
                        <div class="lineup-pitch" style="position:relative;width:100%;aspect-ratio:3/4;background:#2d6a4f;border:2px solid rgba(255,255,255,0.6);border-radius:6px;overflow:hidden;margin-top:8px;margin-bottom:10px">
                            <svg style="position:absolute;inset:0;width:100%;height:100%" viewBox="0 0 300 400" preserveAspectRatio="none" aria-hidden="true">
                                <line x1="0" y1="200" x2="300" y2="200" stroke="rgba(255,255,255,0.4)" stroke-width="1.5"/>
                                <circle cx="150" cy="200" r="38" fill="none" stroke="rgba(255,255,255,0.4)" stroke-width="1.5"/>
                                <circle cx="150" cy="200" r="3" fill="rgba(255,255,255,0.45)"/>
                                <rect x="80" y="0" width="140" height="64" fill="none" stroke="rgba(255,255,255,0.35)" stroke-width="1.5"/>
                                <rect x="80" y="336" width="140" height="64" fill="none" stroke="rgba(255,255,255,0.35)" stroke-width="1.5"/>
                                <rect x="110" y="0" width="80" height="26" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="1"/>
                                <rect x="110" y="374" width="80" height="26" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="1"/>
                            </svg>
                            @foreach($pitched as $pd)
                            <div style="position:absolute;left:{{ $pd['x'] }}%;top:{{ $pd['y'] }}%;transform:translate(-50%,-50%);text-align:center;width:40px;z-index:1"
                                 title="{{ $pd['player']->player_name }}">
                                <div style="width:26px;height:26px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#1a1a1a;margin:0 auto;line-height:1">{{ $pd['label'] }}</div>
                                <div style="font-size:8px;color:#fff;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:40px;margin-top:1px;text-shadow:0 1px 2px rgba(0,0,0,0.85)">{{ $pd['shortName'] }}</div>
                            </div>
                            @endforeach
                        </div>
                        @endif

                        @if($starters->isNotEmpty())
                        <div class="small fw-semibold text-uppercase text-muted mt-2 mb-1">Titolari</div>
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                @foreach($starters as $player)
                                <tr>
                                    <td class="text-muted ps-0 text-end pe-2" style="width:28px">{{ $player->shirt_number ?? '–' }}</td>
                                    <td class="px-1">{{ $player->player_name }}</td>
                                    <td class="text-muted pe-0 text-end">{{ $player->position ?? '–' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @endif

                        @if($bench->isNotEmpty())
                        <div class="small fw-semibold text-uppercase text-muted mt-3 mb-1">Panchina</div>
                        <table class="table table-sm table-borderless mb-0">
                            <tbody>
                                @foreach($bench as $player)
                                <tr>
                                    <td class="text-muted ps-0 text-end pe-2" style="width:28px">{{ $player->shirt_number ?? '–' }}</td>
                                    <td class="px-1">{{ $player->player_name }}</td>
                                    <td class="text-muted pe-0 text-end">{{ $player->position ?? '–' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @endif
                    @endif
                </div>
            </div>
        </div>
        @endforeach
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
