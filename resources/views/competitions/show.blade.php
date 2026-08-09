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
                        {{ $match->homeTeam->name }}
                    </td>
                    <td class="text-center px-3">
                        @if($hasScore)
                            <span class="fw-bold">{{ $match->home_score_ft }} – {{ $match->away_score_ft }}</span>
                        @elseif($match->status === 'live')
                            <span class="text-danger fw-bold">Live</span>
                        @else
                            <span class="text-muted">–</span>
                        @endif
                    </td>
                    <td class="fw-semibold">
                        {{ $match->awayTeam->name }}
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
    <h2 class="fs-5 fw-semibold mb-3">Classifica</h2>
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
                        <td class="fw-semibold">{{ $row['name'] }}</td>
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

@endsection
