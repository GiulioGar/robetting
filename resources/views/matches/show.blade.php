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

{{-- E1. Carico recente --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Carico recente <span class="text-muted small fw-normal">(tutte le competizioni, prima del match)</span></h2>
    <div class="row g-3">
        @foreach([
            ['label' => $match->homeTeam->name, 'load' => $homeScheduleLoad],
            ['label' => $match->awayTeam->name, 'load' => $awayScheduleLoad],
        ] as $block)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small fw-semibold mb-3">{{ $block['label'] }}</div>
                    <div class="row text-center small">
                        <div class="col-3">
                            <div class="fw-bold fs-5">{{ $block['load']['rest_days'] ?? '—' }}</div>
                            <div class="text-muted">Riposo (gg)</div>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold fs-5">{{ $block['load']['matches_last_7_days'] }}</div>
                            <div class="text-muted">Ultime 7 gg</div>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold fs-5">{{ $block['load']['matches_last_14_days'] }}</div>
                            <div class="text-muted">Ultime 14 gg</div>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold fs-5">{{ $block['load']['matches_last_30_days'] }}</div>
                            <div class="text-muted">Ultime 30 gg</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- E2. Utilizzo recente giocatori --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Utilizzo recente giocatori <span class="text-muted small fw-normal">(top 8 per 30gg, prima del match)</span></h2>
    <div class="row g-3">
        @foreach([
            ['label' => $match->homeTeam->name, 'players' => $homeTopPlayers],
            ['label' => $match->awayTeam->name, 'players' => $awayTopPlayers],
        ] as $block)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-0">
                    <div class="px-3 pt-3 pb-2 small fw-semibold text-muted">{{ $block['label'] }}</div>
                    @if(empty($block['players']))
                        <p class="px-3 pb-3 text-muted small mb-0">Dati giocatori non disponibili.</p>
                    @else
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Giocatore</th>
                                    <th class="text-center">7gg</th>
                                    <th class="text-center">14gg</th>
                                    <th class="text-center">30gg</th>
                                    <th class="text-center">Ult.5</th>
                                    <th class="text-center">Pres.</th>
                                    <th class="text-center pe-3">Tit.</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($block['players'] as $p)
                                <tr>
                                    <td class="ps-3">{{ $p['name'] }}</td>
                                    <td class="text-center">{{ $p['minutes_last_7_days'] !== null ? $p['minutes_last_7_days']."'" : '—' }}</td>
                                    <td class="text-center">{{ $p['minutes_last_14_days'] !== null ? $p['minutes_last_14_days']."'" : '—' }}</td>
                                    <td class="text-center">{{ $p['minutes_last_30_days'] !== null ? $p['minutes_last_30_days']."'" : '—' }}</td>
                                    <td class="text-center">{{ $p['minutes_last_5_matches'] !== null ? $p['minutes_last_5_matches']."'" : '—' }}</td>
                                    <td class="text-center">{{ $p['appearances_last_5_matches'] }}</td>
                                    <td class="text-center pe-3">{{ $p['starts_last_5_matches'] }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- E3. Profilo età recente --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Profilo età recente <span class="text-muted small fw-normal">(ultimi 5 match, età al calcio d'inizio)</span></h2>
    <div class="row g-3">
        @foreach([
            ['label' => $match->homeTeam->name, 'age' => $homeAgeProfile],
            ['label' => $match->awayTeam->name, 'age' => $awayAgeProfile],
        ] as $block)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="fw-semibold text-muted small mb-2">{{ $block['label'] }}</div>
                    @php $ap = $block['age']; @endphp
                    @if($ap['players_with_birth_date_count'] === 0)
                        <p class="text-muted small mb-0">Dati anagrafici non disponibili.</p>
                    @else
                    <table class="table table-sm table-borderless mb-0 small">
                        <tbody>
                            <tr>
                                <td class="text-muted ps-0">Età media utilizzati</td>
                                <td class="fw-semibold text-end pe-0">
                                    {{ $ap['average_age_used_last_5'] !== null ? number_format($ap['average_age_used_last_5'], 1) : '—' }}
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Età media pesata minuti</td>
                                <td class="fw-semibold text-end pe-0">
                                    {{ $ap['weighted_average_age_last_5'] !== null ? number_format($ap['weighted_average_age_last_5'], 1) : '—' }}
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Età media titolari</td>
                                <td class="fw-semibold text-end pe-0">
                                    {{ $ap['average_starter_age_last_5'] !== null ? number_format($ap['average_starter_age_last_5'], 1) : '—' }}
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Copertura dati</td>
                                <td class="fw-semibold text-end pe-0">
                                    {{ $ap['players_with_birth_date_count'] }}/{{ $ap['players_used_count'] }}
                                    @if($ap['birth_date_coverage_percentage'] !== null)
                                        ({{ number_format($ap['birth_date_coverage_percentage'], 0) }}%)
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- E4. Continuità titolari --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Continuità titolari <span class="text-muted small fw-normal">(ultimi 5 match, solo titolari)</span></h2>
    <div class="row g-3">
        @foreach([
            ['label' => $match->homeTeam->name, 'sc' => $homeStarterContinuity],
            ['label' => $match->awayTeam->name, 'sc' => $awayStarterContinuity],
        ] as $block)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="fw-semibold text-muted small mb-2">{{ $block['label'] }}</div>
                    @php $sc = $block['sc']; @endphp
                    @if($sc['matches_considered'] === 0)
                        <p class="text-muted small mb-0">Dati lineup non disponibili.</p>
                    @else
                    <table class="table table-sm table-borderless mb-0 small">
                        <tbody>
                            <tr>
                                <td class="text-muted ps-0">Titolari confermati in media</td>
                                <td class="fw-semibold text-end pe-0">
                                    {{ $sc['average_starters_retained'] !== null ? number_format($sc['average_starters_retained'], 1) : '—' }}
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Cambi medi nell'XI</td>
                                <td class="fw-semibold text-end pe-0">
                                    {{ $sc['average_starters_changed'] !== null ? number_format($sc['average_starters_changed'], 1) : '—' }}
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Titolari ≥4/5 volte</td>
                                <td class="fw-semibold text-end pe-0">{{ $sc['players_started_4_of_last_5'] }}</td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Sempre titolari (5/5)</td>
                                <td class="fw-semibold text-end pe-0">{{ $sc['players_started_5_of_last_5'] }}</td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Titolari diversi usati</td>
                                <td class="fw-semibold text-end pe-0">{{ $sc['distinct_starters_last_5'] }}</td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Copertura lineup</td>
                                <td class="fw-semibold text-end pe-0">
                                    {{ $sc['matches_with_complete_starting_xi'] }}/{{ $sc['matches_considered'] }}
                                    @if($sc['lineup_coverage_percentage'] !== null)
                                        ({{ number_format($sc['lineup_coverage_percentage'], 0) }}%)
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Transizioni complete analizzate</td>
                                <td class="fw-semibold text-end pe-0">{{ $sc['complete_transitions_count'] }}</td>
                            </tr>
                        </tbody>
                    </table>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- E5. Impatto indisponibili --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Impatto indisponibili <span class="text-muted small fw-normal">(snapshot pre-match)</span></h2>
    <div class="row g-3">
        @foreach([
            ['label' => $match->homeTeam->name, 'ai' => $homeAbsenceImpact],
            ['label' => $match->awayTeam->name, 'ai' => $awayAbsenceImpact],
        ] as $block)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="fw-semibold text-muted small mb-2">{{ $block['label'] }}</div>
                    @php $ai = $block['ai']; @endphp
                    @if($ai['absences_count'] === 0)
                        <p class="text-muted small mb-0">Nessuna indisponibilità registrata.</p>
                    @elseif($ai['absent_players_with_stats_count'] === 0)
                        <table class="table table-sm table-borderless mb-0 small">
                            <tbody>
                                <tr>
                                    <td class="text-muted ps-0">Assenti</td>
                                    <td class="fw-semibold text-end pe-0">{{ $ai['absences_count'] }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Impatto recente</td>
                                    <td class="fw-semibold text-end pe-0 text-warning">non calcolabile</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Copertura dati</td>
                                    <td class="fw-semibold text-end pe-0">0/{{ $ai['absences_count'] }} (0%)</td>
                                </tr>
                            </tbody>
                        </table>
                    @else
                        <table class="table table-sm table-borderless mb-0 small">
                            <tbody>
                                <tr>
                                    <td class="text-muted ps-0">Assenti</td>
                                    <td class="fw-semibold text-end pe-0">{{ $ai['absences_count'] }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Minuti persi ultimi 30 gg</td>
                                    <td class="fw-semibold text-end pe-0">
                                        {{ $ai['absent_minutes_last_30_days'] !== null ? $ai['absent_minutes_last_30_days'] . "'" : '—' }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Peso sui minuti squadra</td>
                                    <td class="fw-semibold text-end pe-0">
                                        {{ $ai['absent_minutes_share_percentage'] !== null ? number_format($ai['absent_minutes_share_percentage'], 1) . '%' : '—' }}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Presenze ultime 5 degli assenti</td>
                                    <td class="fw-semibold text-end pe-0">{{ $ai['absent_appearances_last_5'] }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Titolarità ultime 5 degli assenti</td>
                                    <td class="fw-semibold text-end pe-0">{{ $ai['absent_starts_last_5'] }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Assenti molto utilizzati</td>
                                    <td class="fw-semibold text-end pe-0">{{ $ai['heavily_used_absences_count'] }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Copertura dati</td>
                                    <td class="fw-semibold text-end pe-0">
                                        {{ $ai['absent_players_with_stats_count'] }}/{{ $ai['absences_count'] }}
                                        @if($ai['absence_stats_coverage_percentage'] !== null)
                                            ({{ number_format($ai['absence_stats_coverage_percentage'], 0) }}%)
                                        @endif
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- E6. Forza dinamica Elo --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Forza dinamica Elo <span class="text-muted small fw-normal">(pre-match, tutte le competizioni)</span></h2>
    @php
        $absDiff  = abs($eloData['elo_difference']);
        $eloLabel = match(true) {
            $absDiff < 25  => 'Molto equilibrata',
            $absDiff < 75  => 'Leggero vantaggio',
            $absDiff < 150 => 'Vantaggio chiaro',
            default        => 'Forte differenza',
        };
        $diffSign = $eloData['elo_difference'] >= 0 ? '+' : '';
    @endphp
    <div class="row g-3">
        @foreach([
            ['label' => $match->homeTeam->name, 'elo' => $eloData['home_elo']],
            ['label' => $match->awayTeam->name, 'elo' => $eloData['away_elo']],
        ] as $block)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="fw-semibold text-muted small mb-1">{{ $block['label'] }}</div>
                    <div class="fs-4 fw-bold">{{ number_format($block['elo'], 1) }}</div>
                    <div class="text-muted small">Rating Elo</div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    <div class="card mt-3">
        <div class="card-body p-3">
            <div class="row text-center small">
                <div class="col-6">
                    <div class="text-muted">Differenza Elo</div>
                    <div class="fw-semibold">{{ $diffSign }}{{ number_format($eloData['elo_difference'], 1) }}</div>
                </div>
                <div class="col-6">
                    <div class="text-muted">Equilibrio</div>
                    <div class="fw-semibold">{{ $eloLabel }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- E7. Confronto forza squadre --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Confronto forza squadre <span class="text-muted small fw-normal">(analytics)</span></h2>

    <div class="row g-3">
        @foreach([
            ['label' => $match->homeTeam->name, 'structural' => $strengthComparison['home_structural'], 'elo' => $strengthComparison['home_elo']],
            ['label' => $match->awayTeam->name, 'structural' => $strengthComparison['away_structural'], 'elo' => $strengthComparison['away_elo']],
        ] as $block)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="fw-semibold text-muted small mb-2">{{ $block['label'] }}</div>
                    <table class="table table-sm table-borderless mb-0 small">
                        <tbody>
                            <tr>
                                <td class="text-muted ps-0">Structural Rating</td>
                                <td class="fw-semibold text-end pe-0">
                                    @if($block['structural']['structural_rating'] !== null)
                                        {{ number_format($block['structural']['structural_rating'], 1) }}
                                    @else
                                        <span class="text-muted">N/D</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Elo dinamico</td>
                                <td class="fw-semibold text-end pe-0">{{ number_format($block['elo'], 1) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="card mt-3">
        <div class="card-body p-3">
            @php
                $sc_sd = $strengthComparison['structural_rating_diff'];
                $sc_ed = $strengthComparison['elo_diff'];
                $sc_eSign  = $sc_ed >= 0 ? '+' : '';
                $sc_eLabel = $sc_ed > 0 ? 'HOME' : ($sc_ed < 0 ? 'AWAY' : 'EVEN');
            @endphp
            <div class="row text-center small">
                <div class="col-4">
                    <div class="text-muted">Differenza Structural</div>
                    @if($sc_sd !== null)
                        @php $sc_sSign = $sc_sd >= 0 ? '+' : ''; $sc_sLabel = $sc_sd > 0 ? 'HOME' : ($sc_sd < 0 ? 'AWAY' : 'EVEN'); @endphp
                        <div class="fw-semibold">{{ $sc_sSign }}{{ number_format($sc_sd, 1) }} {{ $sc_sLabel }}</div>
                    @else
                        <div class="fw-semibold text-muted">N/D</div>
                    @endif
                </div>
                <div class="col-4">
                    <div class="text-muted">Differenza Elo</div>
                    <div class="fw-semibold">{{ $sc_eSign }}{{ number_format($sc_ed, 1) }} {{ $sc_eLabel }}</div>
                </div>
                <div class="col-4">
                    <div class="text-muted">Segnali</div>
                    @if($strengthComparison['signals_agree'] === true)
                        <div class="fw-semibold">CONCORDI</div>
                    @elseif($strengthComparison['signals_agree'] === false)
                        <div class="fw-semibold">DIVERGENTI</div>
                    @else
                        <div class="fw-semibold text-muted">NON VALUTABILE</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- E8. Prestazione recente --}}
<div class="mt-4">
    @php
        $rtpDiff = fn(?float $v) => $v === null ? 'N/D' : ($v > 0 ? '+' : '') . number_format($v, 2);
        $rtpFrac = fn(int $num, int $den) => $den > 0 ? $num . ' / ' . $den : 'N/D';
        $rtpAvg  = fn(?float $v) => $v === null ? 'N/D' : number_format($v, 2);
    @endphp
    <h2 class="fs-5 fw-semibold mb-3">Prestazione recente <span class="text-muted small fw-normal">(analytics)</span></h2>
    <ul class="nav nav-tabs mb-0" id="rtpTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active small" id="rtp-5-tab" data-bs-toggle="tab" data-bs-target="#rtp-5" type="button" role="tab">Ultime 5</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link small" id="rtp-10-tab" data-bs-toggle="tab" data-bs-target="#rtp-10" type="button" role="tab">Ultime 10</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link small" id="rtp-venue-tab" data-bs-toggle="tab" data-bs-target="#rtp-venue" type="button" role="tab">Sede del match</button>
        </li>
    </ul>
    <div class="tab-content border border-top-0 rounded-bottom p-3" id="rtpTabContent">
        @foreach([
            ['id' => 'rtp-5',  'active' => true,  'hs' => $homeLast5Analytics['summary'],  'ht' => $homeLast5Analytics['technical'],  'as' => $awayLast5Analytics['summary'],  'at' => $awayLast5Analytics['technical']],
            ['id' => 'rtp-10', 'active' => false, 'hs' => $homeLast10Analytics['summary'], 'ht' => $homeLast10Analytics['technical'], 'as' => $awayLast10Analytics['summary'], 'at' => $awayLast10Analytics['technical']],
        ] as $rtpPanel)
        <div class="tab-pane fade {{ $rtpPanel['active'] ? 'show active' : '' }}" id="{{ $rtpPanel['id'] }}" role="tabpanel">
            @php $hs = $rtpPanel['hs']; $ht = $rtpPanel['ht']; $as = $rtpPanel['as']; $at = $rtpPanel['at']; @endphp
            @if($hs['matches_played'] === 0 && $as['matches_played'] === 0)
                <p class="text-muted mb-0">Dati precedenti non disponibili.</p>
            @else
            <div class="table-responsive">
                <table class="table table-sm mb-0 text-center align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start">&nbsp;</th>
                            <th>{{ $match->homeTeam->name }} <span class="text-muted fw-normal small">({{ $hs['matches_played'] }} PG)</span></th>
                            <th>{{ $match->awayTeam->name }} <span class="text-muted fw-normal small">({{ $as['matches_played'] }} PG)</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Gol</td></tr>
                        <tr><td class="text-start text-muted">Media GF</td><td>{{ $rtpAvg($hs['avg_goals_for']) }}</td><td>{{ $rtpAvg($as['avg_goals_for']) }}</td></tr>
                        <tr><td class="text-start text-muted">Media GS</td><td>{{ $rtpAvg($hs['avg_goals_against']) }}</td><td>{{ $rtpAvg($as['avg_goals_against']) }}</td></tr>
                        <tr><td class="text-start text-muted">Diff. reti/partita</td><td>{{ $rtpDiff($hs['goal_diff_per_match']) }}</td><td>{{ $rtpDiff($as['goal_diff_per_match']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Tiri</td></tr>
                        <tr><td class="text-start text-muted">Media tiri fatti</td><td>{{ $rtpAvg($ht['avg_shots_for']) }}</td><td>{{ $rtpAvg($at['avg_shots_for']) }}</td></tr>
                        <tr><td class="text-start text-muted">Media tiri subiti</td><td>{{ $rtpAvg($ht['avg_shots_against']) }}</td><td>{{ $rtpAvg($at['avg_shots_against']) }}</td></tr>
                        <tr><td class="text-start text-muted">Diff. tiri/partita</td><td>{{ $rtpDiff($ht['avg_shot_diff']) }}</td><td>{{ $rtpDiff($at['avg_shot_diff']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Tiri in porta</td></tr>
                        <tr><td class="text-start text-muted">Media TiP fatti</td><td>{{ $rtpAvg($ht['avg_shots_on_target_for']) }}</td><td>{{ $rtpAvg($at['avg_shots_on_target_for']) }}</td></tr>
                        <tr><td class="text-start text-muted">Media TiP subiti</td><td>{{ $rtpAvg($ht['avg_shots_on_target_against']) }}</td><td>{{ $rtpAvg($at['avg_shots_on_target_against']) }}</td></tr>
                        <tr><td class="text-start text-muted">Diff. TiP/partita</td><td>{{ $rtpDiff($ht['avg_shots_on_target_diff']) }}</td><td>{{ $rtpDiff($at['avg_shots_on_target_diff']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Esiti</td></tr>
                        <tr><td class="text-start text-muted">Clean sheet</td><td>{{ $rtpFrac($hs['clean_sheets'], $hs['matches_played']) }}</td><td>{{ $rtpFrac($as['clean_sheets'], $as['matches_played']) }}</td></tr>
                        <tr><td class="text-start text-muted">Zero gol segnati</td><td>{{ $rtpFrac($hs['failed_to_score'], $hs['matches_played']) }}</td><td>{{ $rtpFrac($as['failed_to_score'], $as['matches_played']) }}</td></tr>
                    </tbody>
                </table>
            </div>
            @endif
        </div>
        @endforeach
        <div class="tab-pane fade" id="rtp-venue" role="tabpanel">
            @php
                $hVs = $homeRecentHomeAnalytics['summary'];
                $hVt = $homeRecentHomeAnalytics['technical'];
                $aVs = $awayRecentAwayAnalytics['summary'];
                $aVt = $awayRecentAwayAnalytics['technical'];
            @endphp
            @if($hVs['matches_played'] === 0 && $aVs['matches_played'] === 0)
                <p class="text-muted mb-0">Dati precedenti non disponibili.</p>
            @else
            <div class="table-responsive">
                <table class="table table-sm mb-0 text-center align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start">&nbsp;</th>
                            <th>{{ $match->homeTeam->name }} <span class="text-muted fw-normal small">({{ $hVs['matches_played'] }} in casa)</span></th>
                            <th>{{ $match->awayTeam->name }} <span class="text-muted fw-normal small">({{ $aVs['matches_played'] }} in trasf.)</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Gol</td></tr>
                        <tr><td class="text-start text-muted">Media GF</td><td>{{ $rtpAvg($hVs['avg_goals_for']) }}</td><td>{{ $rtpAvg($aVs['avg_goals_for']) }}</td></tr>
                        <tr><td class="text-start text-muted">Media GS</td><td>{{ $rtpAvg($hVs['avg_goals_against']) }}</td><td>{{ $rtpAvg($aVs['avg_goals_against']) }}</td></tr>
                        <tr><td class="text-start text-muted">Diff. reti/partita</td><td>{{ $rtpDiff($hVs['goal_diff_per_match']) }}</td><td>{{ $rtpDiff($aVs['goal_diff_per_match']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Tiri</td></tr>
                        <tr><td class="text-start text-muted">Media tiri fatti</td><td>{{ $rtpAvg($hVt['avg_shots_for']) }}</td><td>{{ $rtpAvg($aVt['avg_shots_for']) }}</td></tr>
                        <tr><td class="text-start text-muted">Media tiri subiti</td><td>{{ $rtpAvg($hVt['avg_shots_against']) }}</td><td>{{ $rtpAvg($aVt['avg_shots_against']) }}</td></tr>
                        <tr><td class="text-start text-muted">Diff. tiri/partita</td><td>{{ $rtpDiff($hVt['avg_shot_diff']) }}</td><td>{{ $rtpDiff($aVt['avg_shot_diff']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Tiri in porta</td></tr>
                        <tr><td class="text-start text-muted">Media TiP fatti</td><td>{{ $rtpAvg($hVt['avg_shots_on_target_for']) }}</td><td>{{ $rtpAvg($aVt['avg_shots_on_target_for']) }}</td></tr>
                        <tr><td class="text-start text-muted">Media TiP subiti</td><td>{{ $rtpAvg($hVt['avg_shots_on_target_against']) }}</td><td>{{ $rtpAvg($aVt['avg_shots_on_target_against']) }}</td></tr>
                        <tr><td class="text-start text-muted">Diff. TiP/partita</td><td>{{ $rtpDiff($hVt['avg_shots_on_target_diff']) }}</td><td>{{ $rtpDiff($aVt['avg_shots_on_target_diff']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Esiti</td></tr>
                        <tr><td class="text-start text-muted">Clean sheet</td><td>{{ $rtpFrac($hVs['clean_sheets'], $hVs['matches_played']) }}</td><td>{{ $rtpFrac($aVs['clean_sheets'], $aVs['matches_played']) }}</td></tr>
                        <tr><td class="text-start text-muted">Zero gol segnati</td><td>{{ $rtpFrac($hVs['failed_to_score'], $hVs['matches_played']) }}</td><td>{{ $rtpFrac($aVs['failed_to_score'], $aVs['matches_played']) }}</td></tr>
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
</div>

{{-- E9. Qualità avversari recenti --}}
<div class="mt-4" id="opponent-quality-section">
    <h2 class="fs-5 fw-semibold mb-3">Qualità avversari recenti <span class="text-muted small fw-normal">(analytics)</span></h2>
    <ul class="nav nav-tabs mb-0" id="oqTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active small" id="oq-5-tab" data-bs-toggle="tab" data-bs-target="#oq-5" type="button" role="tab">Ultime 5</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link small" id="oq-10-tab" data-bs-toggle="tab" data-bs-target="#oq-10" type="button" role="tab">Ultime 10</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link small" id="oq-venue-tab" data-bs-toggle="tab" data-bs-target="#oq-venue" type="button" role="tab">Sede del match</button>
        </li>
    </ul>
    <div class="tab-content border border-top-0 rounded-bottom p-3" id="oqTabContent">

        @foreach([
            ['id' => 'oq-5',  'active' => true,  'hoq' => $homeOpponentQuality['last5'],  'aoq' => $awayOpponentQuality['last5']],
            ['id' => 'oq-10', 'active' => false, 'hoq' => $homeOpponentQuality['last10'], 'aoq' => $awayOpponentQuality['last10']],
        ] as $oqPanel)
        @php $hoq = $oqPanel['hoq']; $aoq = $oqPanel['aoq']; @endphp
        <div class="tab-pane fade {{ $oqPanel['active'] ? 'show active' : '' }}" id="{{ $oqPanel['id'] }}" role="tabpanel">
            @if($hoq['matches_considered'] === 0 && $aoq['matches_considered'] === 0)
                <p class="text-muted mb-0">Dati precedenti non disponibili.</p>
            @else
            <div class="table-responsive">
                <table class="table table-sm mb-0 text-center align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start">&nbsp;</th>
                            <th>{{ $match->homeTeam->name }} <span class="text-muted fw-normal small">({{ $hoq['matches_considered'] }} PG)</span></th>
                            <th>{{ $match->awayTeam->name }} <span class="text-muted fw-normal small">({{ $aoq['matches_considered'] }} PG)</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Elo avversari</td></tr>
                        <tr>
                            <td class="text-start text-muted">Elo medio avversari</td>
                            <td>{{ $hoq['average_opponent_elo'] !== null ? number_format($hoq['average_opponent_elo'], 1) : 'N/D' }}</td>
                            <td>{{ $aoq['average_opponent_elo'] !== null ? number_format($aoq['average_opponent_elo'], 1) : 'N/D' }}</td>
                        </tr>
                        <tr>
                            <td class="text-start text-muted">Elo mediano avversari</td>
                            <td>{{ $hoq['median_opponent_elo'] !== null ? number_format($hoq['median_opponent_elo'], 1) : 'N/D' }}</td>
                            <td>{{ $aoq['median_opponent_elo'] !== null ? number_format($aoq['median_opponent_elo'], 1) : 'N/D' }}</td>
                        </tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Structural avversari</td></tr>
                        <tr>
                            <td class="text-start text-muted">Structural media avversari</td>
                            <td>{{ $hoq['average_opponent_structural'] !== null ? number_format($hoq['average_opponent_structural'], 1) : 'N/D' }}</td>
                            <td>{{ $aoq['average_opponent_structural'] !== null ? number_format($aoq['average_opponent_structural'], 1) : 'N/D' }}</td>
                        </tr>
                        <tr>
                            <td class="text-start text-muted">Structural mediana avversari</td>
                            <td>{{ $hoq['median_opponent_structural'] !== null ? number_format($hoq['median_opponent_structural'], 1) : 'N/D' }}</td>
                            <td>{{ $aoq['median_opponent_structural'] !== null ? number_format($aoq['median_opponent_structural'], 1) : 'N/D' }}</td>
                        </tr>
                        <tr>
                            <td class="text-start text-muted">Coverage Structural</td>
                            <td>{{ $hoq['structural_matches_available'] }} / {{ $hoq['matches_considered'] }}</td>
                            <td>{{ $aoq['structural_matches_available'] }} / {{ $aoq['matches_considered'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            @endif
        </div>
        @endforeach

        <div class="tab-pane fade" id="oq-venue" role="tabpanel">
            @php
                $hoqV = $homeOpponentQuality['venue'];
                $aoqV = $awayOpponentQuality['venue'];
            @endphp
            @if($hoqV['matches_considered'] === 0 && $aoqV['matches_considered'] === 0)
                <p class="text-muted mb-0">Dati precedenti non disponibili.</p>
            @else
            <div class="table-responsive">
                <table class="table table-sm mb-0 text-center align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start">&nbsp;</th>
                            <th>{{ $match->homeTeam->name }} <span class="text-muted fw-normal small">({{ $hoqV['matches_considered'] }} in casa)</span></th>
                            <th>{{ $match->awayTeam->name }} <span class="text-muted fw-normal small">({{ $aoqV['matches_considered'] }} in trasferta)</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Elo avversari</td></tr>
                        <tr>
                            <td class="text-start text-muted">Elo medio avversari</td>
                            <td>{{ $hoqV['average_opponent_elo'] !== null ? number_format($hoqV['average_opponent_elo'], 1) : 'N/D' }}</td>
                            <td>{{ $aoqV['average_opponent_elo'] !== null ? number_format($aoqV['average_opponent_elo'], 1) : 'N/D' }}</td>
                        </tr>
                        <tr>
                            <td class="text-start text-muted">Elo mediano avversari</td>
                            <td>{{ $hoqV['median_opponent_elo'] !== null ? number_format($hoqV['median_opponent_elo'], 1) : 'N/D' }}</td>
                            <td>{{ $aoqV['median_opponent_elo'] !== null ? number_format($aoqV['median_opponent_elo'], 1) : 'N/D' }}</td>
                        </tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Structural avversari</td></tr>
                        <tr>
                            <td class="text-start text-muted">Structural media avversari</td>
                            <td>{{ $hoqV['average_opponent_structural'] !== null ? number_format($hoqV['average_opponent_structural'], 1) : 'N/D' }}</td>
                            <td>{{ $aoqV['average_opponent_structural'] !== null ? number_format($aoqV['average_opponent_structural'], 1) : 'N/D' }}</td>
                        </tr>
                        <tr>
                            <td class="text-start text-muted">Structural mediana avversari</td>
                            <td>{{ $hoqV['median_opponent_structural'] !== null ? number_format($hoqV['median_opponent_structural'], 1) : 'N/D' }}</td>
                            <td>{{ $aoqV['median_opponent_structural'] !== null ? number_format($aoqV['median_opponent_structural'], 1) : 'N/D' }}</td>
                        </tr>
                        <tr>
                            <td class="text-start text-muted">Coverage Structural</td>
                            <td>{{ $hoqV['structural_matches_available'] }} / {{ $hoqV['matches_considered'] }}</td>
                            <td>{{ $aoqV['structural_matches_available'] }} / {{ $aoqV['matches_considered'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            @endif
        </div>

    </div>
</div>

{{-- E10. Performance corretta per qualità avversari --}}
<div class="mt-4" id="adjusted-performance-section">
    @php
        $apFmt = fn(?float $v) => $v === null ? 'N/D' : ($v > 0 ? '+' : '') . number_format($v, 2);
        $apCov = fn(int $n, int $total) => $total === 0 ? 'N/D' : $n . ' / ' . $total;
    @endphp
    <h2 class="fs-5 fw-semibold mb-3">Performance corretta per qualità avversari <span class="text-muted small fw-normal">(analytics)</span></h2>
    <ul class="nav nav-tabs mb-0" id="apTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active small" id="ap-5-tab" data-bs-toggle="tab" data-bs-target="#ap-5" type="button" role="tab">Ultime 5</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link small" id="ap-10-tab" data-bs-toggle="tab" data-bs-target="#ap-10" type="button" role="tab">Ultime 10</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link small" id="ap-venue-tab" data-bs-toggle="tab" data-bs-target="#ap-venue" type="button" role="tab">Sede del match</button>
        </li>
    </ul>
    <div class="tab-content border border-top-0 rounded-bottom p-3" id="apTabContent">

        @foreach([
            ['id' => 'ap-5',  'active' => true,  'hap' => $homeAdjustedPerformance['last5'],  'aap' => $awayAdjustedPerformance['last5']],
            ['id' => 'ap-10', 'active' => false, 'hap' => $homeAdjustedPerformance['last10'], 'aap' => $awayAdjustedPerformance['last10']],
        ] as $apPanel)
        @php $hap = $apPanel['hap']; $aap = $apPanel['aap']; @endphp
        <div class="tab-pane fade {{ $apPanel['active'] ? 'show active' : '' }}" id="{{ $apPanel['id'] }}" role="tabpanel">
            @if($hap['matches_considered'] === 0 && $aap['matches_considered'] === 0)
                <p class="text-muted mb-0">Dati precedenti non disponibili.</p>
            @else
            <div class="table-responsive">
                <table class="table table-sm mb-0 text-center align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start">&nbsp;</th>
                            <th>{{ $match->homeTeam->name }} <span class="text-muted fw-normal small">({{ $hap['matches_considered'] }} PG)</span></th>
                            <th>{{ $match->awayTeam->name }} <span class="text-muted fw-normal small">({{ $aap['matches_considered'] }} PG)</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Differenziale gol</td></tr>
                        <tr><td class="text-start text-muted ps-3">· grezzo</td><td>{{ $apFmt($hap['goal_diff_raw_avg']) }}</td><td>{{ $apFmt($aap['goal_diff_raw_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· corretto</td><td>{{ $apFmt($hap['goal_diff_adjusted_avg']) }}</td><td>{{ $apFmt($aap['goal_diff_adjusted_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· correzione media</td><td>{{ $apFmt($hap['goal_diff_avg_adjustment']) }}</td><td>{{ $apFmt($aap['goal_diff_avg_adjustment']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Differenziale tiri</td></tr>
                        <tr><td class="text-start text-muted ps-3">· grezzo</td><td>{{ $apFmt($hap['shot_diff_raw_avg']) }}</td><td>{{ $apFmt($aap['shot_diff_raw_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· corretto</td><td>{{ $apFmt($hap['shot_diff_adjusted_avg']) }}</td><td>{{ $apFmt($aap['shot_diff_adjusted_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· correzione media</td><td>{{ $apFmt($hap['shot_diff_avg_adjustment']) }}</td><td>{{ $apFmt($aap['shot_diff_avg_adjustment']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Differenziale tiri in porta</td></tr>
                        <tr><td class="text-start text-muted ps-3">· grezzo</td><td>{{ $apFmt($hap['sot_diff_raw_avg']) }}</td><td>{{ $apFmt($aap['sot_diff_raw_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· corretto</td><td>{{ $apFmt($hap['sot_diff_adjusted_avg']) }}</td><td>{{ $apFmt($aap['sot_diff_adjusted_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· correzione media</td><td>{{ $apFmt($hap['sot_diff_avg_adjustment']) }}</td><td>{{ $apFmt($aap['sot_diff_avg_adjustment']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Copertura statistiche</td></tr>
                        <tr><td class="text-start text-muted ps-3">Copertura gol</td><td>{{ $apCov($hap['goal_diff_coverage'], $hap['matches_considered']) }}</td><td>{{ $apCov($aap['goal_diff_coverage'], $aap['matches_considered']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">Copertura tiri</td><td>{{ $apCov($hap['shot_diff_coverage'], $hap['matches_considered']) }}</td><td>{{ $apCov($aap['shot_diff_coverage'], $aap['matches_considered']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">Copertura SoT</td><td>{{ $apCov($hap['sot_diff_coverage'], $hap['matches_considered']) }}</td><td>{{ $apCov($aap['sot_diff_coverage'], $aap['matches_considered']) }}</td></tr>
                    </tbody>
                </table>
            </div>
            @endif
        </div>
        @endforeach

        <div class="tab-pane fade" id="ap-venue" role="tabpanel">
            @php
                $hapV = $homeAdjustedPerformance['venue'];
                $aapV = $awayAdjustedPerformance['venue'];
            @endphp
            @if($hapV['matches_considered'] === 0 && $aapV['matches_considered'] === 0)
                <p class="text-muted mb-0">Dati precedenti non disponibili.</p>
            @else
            <div class="table-responsive">
                <table class="table table-sm mb-0 text-center align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start">&nbsp;</th>
                            <th>{{ $match->homeTeam->name }} <span class="text-muted fw-normal small">({{ $hapV['matches_considered'] }} in casa)</span></th>
                            <th>{{ $match->awayTeam->name }} <span class="text-muted fw-normal small">({{ $aapV['matches_considered'] }} in trasferta)</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Differenziale gol</td></tr>
                        <tr><td class="text-start text-muted ps-3">· grezzo</td><td>{{ $apFmt($hapV['goal_diff_raw_avg']) }}</td><td>{{ $apFmt($aapV['goal_diff_raw_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· corretto</td><td>{{ $apFmt($hapV['goal_diff_adjusted_avg']) }}</td><td>{{ $apFmt($aapV['goal_diff_adjusted_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· correzione media</td><td>{{ $apFmt($hapV['goal_diff_avg_adjustment']) }}</td><td>{{ $apFmt($aapV['goal_diff_avg_adjustment']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Differenziale tiri</td></tr>
                        <tr><td class="text-start text-muted ps-3">· grezzo</td><td>{{ $apFmt($hapV['shot_diff_raw_avg']) }}</td><td>{{ $apFmt($aapV['shot_diff_raw_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· corretto</td><td>{{ $apFmt($hapV['shot_diff_adjusted_avg']) }}</td><td>{{ $apFmt($aapV['shot_diff_adjusted_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· correzione media</td><td>{{ $apFmt($hapV['shot_diff_avg_adjustment']) }}</td><td>{{ $apFmt($aapV['shot_diff_avg_adjustment']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Differenziale tiri in porta</td></tr>
                        <tr><td class="text-start text-muted ps-3">· grezzo</td><td>{{ $apFmt($hapV['sot_diff_raw_avg']) }}</td><td>{{ $apFmt($aapV['sot_diff_raw_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· corretto</td><td>{{ $apFmt($hapV['sot_diff_adjusted_avg']) }}</td><td>{{ $apFmt($aapV['sot_diff_adjusted_avg']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">· correzione media</td><td>{{ $apFmt($hapV['sot_diff_avg_adjustment']) }}</td><td>{{ $apFmt($aapV['sot_diff_avg_adjustment']) }}</td></tr>
                        <tr class="table-light"><td class="text-start fw-semibold small text-muted" colspan="3">Copertura statistiche</td></tr>
                        <tr><td class="text-start text-muted ps-3">Copertura gol</td><td>{{ $apCov($hapV['goal_diff_coverage'], $hapV['matches_considered']) }}</td><td>{{ $apCov($aapV['goal_diff_coverage'], $aapV['matches_considered']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">Copertura tiri</td><td>{{ $apCov($hapV['shot_diff_coverage'], $hapV['matches_considered']) }}</td><td>{{ $apCov($aapV['shot_diff_coverage'], $aapV['matches_considered']) }}</td></tr>
                        <tr><td class="text-start text-muted ps-3">Copertura SoT</td><td>{{ $apCov($hapV['sot_diff_coverage'], $hapV['matches_considered']) }}</td><td>{{ $apCov($aapV['sot_diff_coverage'], $aapV['matches_considered']) }}</td></tr>
                    </tbody>
                </table>
            </div>
            @endif
        </div>

    </div>
</div>

{{-- E11. Contesto campionato --}}
<div class="mt-4" id="league-context-section">
    @php
        $lcFmt  = fn(?float $v, int $dec = 2) => $v === null ? 'N/D' : number_format($v, $dec);
        $lcPct  = fn(?float $v) => $v === null ? 'N/D' : number_format($v * 100, 1) . '%';
    @endphp
    <h2 class="fs-5 fw-semibold mb-3">Contesto campionato <span class="text-muted small fw-normal">(analytics)</span></h2>
    @if($leagueContext['matches_considered'] === 0)
        <div class="card"><div class="card-body text-muted small">Dati campionato precedenti non disponibili.</div></div>
    @else
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Metrica</th>
                        <th class="pe-3 text-end">Valore</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="ps-3 text-muted small" colspan="2"><strong>Partite campionato (stagione in corso, ante partita)</strong></td></tr>
                    <tr>
                        <td class="ps-3">Partite considerate</td>
                        <td class="pe-3 text-end">{{ $leagueContext['matches_considered'] }}</td>
                    </tr>

                    <tr class="table-light"><td class="ps-3 fw-semibold small text-muted" colspan="2">Ambiente gol</td></tr>
                    <tr>
                        <td class="ps-3">Media gol / partita</td>
                        <td class="pe-3 text-end">{{ $lcFmt($leagueContext['avg_goals_per_match']) }}</td>
                    </tr>
                    <tr>
                        <td class="ps-3">Media gol casa</td>
                        <td class="pe-3 text-end">{{ $lcFmt($leagueContext['avg_home_goals']) }}</td>
                    </tr>
                    <tr>
                        <td class="ps-3">Media gol trasferta</td>
                        <td class="pe-3 text-end">{{ $lcFmt($leagueContext['avg_away_goals']) }}</td>
                    </tr>
                    <tr>
                        <td class="ps-3">Differenziale casa/trasferta</td>
                        <td class="pe-3 text-end">{{ $lcFmt($leagueContext['home_vs_away_goal_diff']) }}</td>
                    </tr>

                    <tr class="table-light"><td class="ps-3 fw-semibold small text-muted" colspan="2">Baseline risultati</td></tr>
                    <tr>
                        <td class="ps-3">Vittorie casa</td>
                        <td class="pe-3 text-end">{{ $lcPct($leagueContext['home_win_rate']) }}</td>
                    </tr>
                    <tr>
                        <td class="ps-3">Pareggi</td>
                        <td class="pe-3 text-end">{{ $lcPct($leagueContext['draw_rate']) }}</td>
                    </tr>
                    <tr>
                        <td class="ps-3">Vittorie trasferta</td>
                        <td class="pe-3 text-end">{{ $lcPct($leagueContext['away_win_rate']) }}</td>
                    </tr>

                    @if($leagueContext['avg_home_shots'] !== null || $leagueContext['avg_away_shots'] !== null)
                    <tr class="table-light"><td class="ps-3 fw-semibold small text-muted" colspan="2">Tiri <span class="fw-normal">({{ $leagueContext['shots_coverage'] }} / {{ $leagueContext['matches_considered'] }} partite)</span></td></tr>
                    <tr>
                        <td class="ps-3">Media tiri casa</td>
                        <td class="pe-3 text-end">{{ $lcFmt($leagueContext['avg_home_shots']) }}</td>
                    </tr>
                    <tr>
                        <td class="ps-3">Media tiri trasferta</td>
                        <td class="pe-3 text-end">{{ $lcFmt($leagueContext['avg_away_shots']) }}</td>
                    </tr>
                    @endif
                    @if($leagueContext['avg_home_shots_on_target'] !== null || $leagueContext['avg_away_shots_on_target'] !== null)
                    <tr class="table-light"><td class="ps-3 fw-semibold small text-muted" colspan="2">Tiri in porta <span class="fw-normal">({{ $leagueContext['shots_on_target_coverage'] }} / {{ $leagueContext['matches_considered'] }} partite)</span></td></tr>
                    <tr>
                        <td class="ps-3">Media SoT casa</td>
                        <td class="pe-3 text-end">{{ $lcFmt($leagueContext['avg_home_shots_on_target']) }}</td>
                    </tr>
                    <tr>
                        <td class="ps-3">Media SoT trasferta</td>
                        <td class="pe-3 text-end">{{ $lcFmt($leagueContext['avg_away_shots_on_target']) }}</td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

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
