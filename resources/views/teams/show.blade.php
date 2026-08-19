@extends('layouts.app')

@section('title', $team->name . ' · ' . $competition->name . ' · ' . $season->name)

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
@endphp

{{-- Header --}}
<div class="mb-4">
    <h1 class="mb-0 fs-3">{{ $team->name }}</h1>
    <div class="text-muted small">
        Statistiche: {{ $competition->name }}
        @if($competition->country) · {{ $competition->country->name }} @endif
        · {{ $season->name }}
    </div>
</div>

{{-- Next match --}}
<div class="card mb-4">
    <div class="card-body p-3">
        <div class="text-muted small mb-2">Prossima partita</div>
        @if($nextMatch)
        @php
            $isHome = (int) $nextMatch->home_team_id === $team->id;
            $opponent = $isHome ? $nextMatch->awayTeam->name : $nextMatch->homeTeam->name;
            $kickoffRome = $nextMatch->kickoff_at?->copy()->setTimezone('Europe/Rome');
        @endphp
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <span class="fw-semibold">{{ $opponent }}</span>
                <span class="badge bg-{{ $isHome ? 'primary' : 'secondary' }} ms-2">{{ $isHome ? 'Casa' : 'Trasferta' }}</span>
            </div>
            <div class="text-muted small">
                <a href="{{ route('matches.show', $nextMatch->id) }}" class="text-muted text-decoration-none">{{ $kickoffRome?->format('d/m/Y H:i') ?? '–' }}</a>
            </div>
        </div>
        @else
        <p class="text-muted mb-0">Nessuna partita programmata.</p>
        @endif
    </div>
</div>

{{-- Form --}}
<div class="card mb-4">
    <div class="card-body p-3">
        <div class="row">
            <div class="col-6">
                <div class="text-muted small mb-2">Forma (ultime 5)</div>
                @if(empty($last5Analytics['form']))
                    <span class="text-muted">–</span>
                @else
                    @foreach($last5Analytics['form'] as $r)
                        <span class="badge bg-{{ $badgeClass($r) }} me-1">{{ $r }}</span>
                    @endforeach
                @endif
            </div>
            <div class="col-6">
                <div class="text-muted small mb-2">Forma (ultime 10)</div>
                @if(empty($last10Analytics['form']))
                    <span class="text-muted">–</span>
                @else
                    @foreach($last10Analytics['form'] as $r)
                        <span class="badge bg-{{ $badgeClass($r) }} me-1">{{ $r }}</span>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Season summary --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Riepilogo stagione</h2>
    @php $s = $seasonAnalytics['summary']; @endphp
    @if($s['matches_played'] === 0)
    <p class="text-muted">Nessuna partita disputata in questa stagione.</p>
    @else
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 text-center align-middle">
                <thead class="table-light">
                    <tr>
                        <th>PG</th><th>V</th><th>N</th><th>P</th>
                        <th>GF</th><th>GS</th><th>DR</th><th class="fw-bold">PT</th>
                        <th>Media GF</th><th>Media GS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $s['matches_played'] }}</td>
                        <td>{{ $s['wins'] }}</td>
                        <td>{{ $s['draws'] }}</td>
                        <td>{{ $s['losses'] }}</td>
                        <td>{{ $s['goals_for'] }}</td>
                        <td>{{ $s['goals_against'] }}</td>
                        <td>{{ $s['goal_difference'] >= 0 ? '+' : '' }}{{ $s['goal_difference'] }}</td>
                        <td class="fw-bold">{{ $s['points'] }}</td>
                        <td>{{ $avg($s['avg_goals_for']) }}</td>
                        <td>{{ $avg($s['avg_goals_against']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

{{-- Home / Away split --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Casa / Trasferta</h2>
    <div class="row g-3">
        @foreach([['label' => 'Casa', 'a' => $homeAnalytics], ['label' => 'Trasferta', 'a' => $awayAnalytics]] as $block)
        @php $bs = $block['a']['summary']; @endphp
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small mb-2">{{ $block['label'] }} <span class="text-muted">({{ $bs['matches_played'] }} PG)</span></div>
                    @if($bs['matches_played'] === 0)
                        <p class="text-muted mb-0">–</p>
                    @else
                        <div class="small">
                            V {{ $bs['wins'] }} · N {{ $bs['draws'] }} · P {{ $bs['losses'] }}
                        </div>
                        <div class="small">
                            GF {{ $bs['goals_for'] }} · GS {{ $bs['goals_against'] }}
                        </div>
                        <div class="small text-muted">
                            Media GF {{ $avg($bs['avg_goals_for']) }} · Media GS {{ $avg($bs['avg_goals_against']) }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- Technical stats --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Statistiche tecniche</h2>
    @php $t = $seasonAnalytics['technical']; @endphp
    @if($seasonAnalytics['coverage']['shots']['available_matches'] === 0)
    <p class="text-muted">Statistiche non disponibili.</p>
    @else
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 text-center align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Tiri fatti</th><th>Tiri subiti</th>
                        <th>Tiri in porta fatti</th><th>Tiri in porta subiti</th>
                        <th>Corner fatti</th><th>Corner subiti</th>
                        <th>Cartellini gialli</th><th>Cartellini rossi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $avg($t['avg_shots_for']) }}</td>
                        <td>{{ $avg($t['avg_shots_against']) }}</td>
                        <td>{{ $avg($t['avg_shots_on_target_for']) }}</td>
                        <td>{{ $avg($t['avg_shots_on_target_against']) }}</td>
                        <td>{{ $avg($t['avg_corners_for']) }}</td>
                        <td>{{ $avg($t['avg_corners_against']) }}</td>
                        <td>{{ $avg($t['avg_yellow_cards']) }}</td>
                        <td>{{ $avg($t['avg_red_cards']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

{{-- Market trends --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Trend</h2>
    @php $mt = $seasonAnalytics['market_trends']; @endphp
    @if($mt['coverage']['full_time'] === 0)
    <p class="text-muted">Trend non disponibili.</p>
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
    <p class="text-muted small mt-1">
        La stagione mescola partite casa e trasferta: eventuali metriche home/away-oriented (es. team scoring) non sono mostrate qui perché non semanticamente attribuibili in modo pulito alla sola squadra.
    </p>
    @endif
</div>

{{-- Recent matches --}}
<div class="mt-4 mb-4">
    <h2 class="fs-5 fw-semibold mb-3">Ultime partite</h2>
    @if($recentMatches->isEmpty())
    <p class="text-muted">Nessuna partita disputata.</p>
    @else
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Data</th>
                        <th class="text-end">Casa</th>
                        <th class="text-center" style="width:90px">Risultato</th>
                        <th>Trasferta</th>
                        <th class="text-center pe-3" style="width:50px"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentMatches as $row)
                    @php
                        $m = $row['match'];
                        $kickoffRome = $m->kickoff_at?->copy()->setTimezone('Europe/Rome');
                    @endphp
                    <tr>
                        <td class="ps-3 text-muted small text-nowrap">{{ $kickoffRome?->format('d/m/Y') ?? '–' }}</td>
                        <td class="text-end fw-semibold">
                            <a href="{{ route('teams.show', $m->home_team_id) }}" class="link-body-emphasis text-decoration-none">{{ $m->homeTeam->name }}</a>
                        </td>
                        <td class="text-center fw-bold">
                            <a href="{{ route('matches.show', $m->id) }}" class="link-body-emphasis text-decoration-none">{{ $m->home_score_ft }} – {{ $m->away_score_ft }}</a>
                        </td>
                        <td class="fw-semibold">
                            <a href="{{ route('teams.show', $m->away_team_id) }}" class="link-body-emphasis text-decoration-none">{{ $m->awayTeam->name }}</a>
                        </td>
                        <td class="text-center pe-3">
                            <span class="badge bg-{{ $badgeClass($row['result']) }}">{{ $row['result'] }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

@endsection
