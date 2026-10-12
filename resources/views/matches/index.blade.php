@extends('layouts.app')

@section('title', 'Partite · ' . $date->format('d/m/Y') . ' · Robetting')

@section('content')

@php
    $statusBadge = fn(string $status) => match ($status) {
        'finished'  => ['success', 'Terminata'],
        'live'      => ['danger', 'Live'],
        'scheduled' => ['secondary', 'Programmata'],
        'postponed' => ['warning', 'Rinviata'],
        'suspended' => ['warning', 'Sospesa'],
        'cancelled' => ['dark', 'Annullata'],
        default     => ['secondary', $status],
    };
    $pct = fn(?float $p) => $p === null ? '–' : number_format($p, 0) . '%';
@endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <h1 class="fs-4 fw-bold mb-0">Partite</h1>
    <div class="d-flex align-items-center gap-2">
        <a href="{{ route('home', ['date' => $prevDate]) }}" class="btn btn-sm btn-outline-secondary">&laquo; Giorno precedente</a>
        <form method="get" action="{{ route('home') }}" class="d-inline-flex align-items-center gap-1">
            <input type="date" name="date" value="{{ $date->format('Y-m-d') }}" class="form-control form-control-sm" style="width:auto" onchange="this.form.submit()">
            @if($isToday)<span class="badge bg-primary">Oggi</span>@endif
        </form>
        <a href="{{ route('home', ['date' => $nextDate]) }}" class="btn btn-sm btn-outline-secondary">Giorno successivo &raquo;</a>
        @unless($isToday)
        <a href="{{ route('home') }}" class="btn btn-sm btn-primary">Oggi</a>
        @endunless
    </div>
</div>

@if($matchesByCompetition->isEmpty())
<p class="text-muted">Nessuna partita in programma per questo giorno.</p>
@else
    @foreach($matchesByCompetition as $competitionName => $dayMatches)
    <h2 class="fs-6 fw-semibold text-uppercase text-muted mb-2">{{ $competitionName }}</h2>
    <div class="list-group mb-4">
        @foreach($dayMatches as $match)
        @php
            $badge = $statusBadge($match->status);
            $prediction = $predictions[$match->id] ?? null;
        @endphp
        <a href="{{ route('matches.show', $match) }}" target="_blank" rel="noopener noreferrer" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
            <div class="text-muted small" style="min-width:3.5rem">
                {{ $match->kickoff_at?->copy()->setTimezone('Europe/Rome')->format('H:i') ?? '–' }}
            </div>
            <div class="flex-fill">
                {{ $match->homeTeam->name }} &ndash; {{ $match->awayTeam->name }}
            </div>
            @if($match->status === 'scheduled' && $prediction !== null)
            <div class="d-flex gap-2 small text-muted" style="min-width:8rem">
                <span>1 {{ $pct($prediction['probability_home'] * 100) }}</span>
                <span>X {{ $pct($prediction['probability_draw'] * 100) }}</span>
                <span>2 {{ $pct($prediction['probability_away'] * 100) }}</span>
            </div>
            @else
            <div style="min-width:8rem"></div>
            @endif
            <div class="fw-semibold" style="min-width:3rem">
                @if($match->home_score_ft !== null && $match->away_score_ft !== null)
                    {{ $match->home_score_ft }} - {{ $match->away_score_ft }}
                @else
                    &ndash;
                @endif
            </div>
            <span class="badge bg-{{ $badge[0] }}">{{ $badge[1] }}</span>
        </a>
        @endforeach
    </div>
    @endforeach
@endif

@if(app()->isLocal())
<div class="mt-4 text-end">
    <a href="{{ route('admin.api-football.dashboard') }}" class="text-muted small">Admin API-Football</a>
</div>
@endif

@endsection
