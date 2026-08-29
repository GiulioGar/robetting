@extends('layouts.app')

@section('title', 'Robetting')

@section('content')

<div class="mb-5 text-center">
    <h1 class="fw-bold mb-1">ROBETTING</h1>
    <p class="text-muted mb-0">Analisi, statistiche e trend sul calcio</p>
</div>

<h2 class="fs-5 fw-semibold mb-3">Campionati</h2>

@if($cards->isEmpty())
<p class="text-muted">Nessun campionato disponibile.</p>
@else
<div class="row g-3 mb-5">
    @foreach($cards as $card)
    @php $competition = $card['competition']; $season = $card['season']; @endphp
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body p-3 d-flex flex-column">
                <div class="fw-bold fs-5">{{ $competition->name }}</div>
                @if($competition->country)
                <div class="text-muted small mb-2">{{ $competition->country->name }}</div>
                @endif
                <div class="text-muted small mb-3">
                    {{ $season ? 'Stagione ' . $season->name : 'Nessuna stagione disponibile' }}
                </div>
                <a href="{{ route('competitions.show', $competition->slug) }}" class="btn btn-sm btn-primary mt-auto align-self-start">Apri campionato</a>
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif

@php $testCards = $cards->filter(fn($c) => $c['testMatch'] !== null); @endphp
@if($testCards->isNotEmpty())
<h2 class="fs-5 fw-semibold mb-3">Link rapidi di test</h2>
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Campionato</th>
                    <th>Squadra</th>
                    <th class="pe-3">Partita</th>
                </tr>
            </thead>
            <tbody>
                @foreach($testCards as $card)
                <tr>
                    <td class="ps-3">{{ $card['competition']->name }}</td>
                    <td>
                        <a href="{{ route('teams.show', $card['testTeam']->id) }}" class="link-body-emphasis text-decoration-none">{{ $card['testTeam']->name }}</a>
                    </td>
                    <td class="pe-3">
                        <a href="{{ route('matches.show', $card['testMatch']->id) }}" class="link-body-emphasis text-decoration-none">
                            {{ $card['testMatch']->homeTeam->name }} – {{ $card['testMatch']->awayTeam->name }}
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif


@if(app()->isLocal())
<div class="mt-4 text-end">
    <a href="{{ route('admin.api-football.dashboard') }}" class="text-muted small">Admin API-Football</a>
</div>
@endif

@endsection
