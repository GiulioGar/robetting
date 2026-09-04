@extends('layouts.app')

@section('title', 'Admin — Sync Statistiche Giocatori API-Football')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Sync Statistiche Giocatori API-Football</h4>
            <div>
                <a href="{{ route('admin.api-football.dashboard') }}" class="btn btn-sm btn-outline-secondary me-2">Dashboard</a>
                <a href="{{ route('admin.api-football.events') }}" class="btn btn-sm btn-outline-secondary">← Eventi</a>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.api-football.player-stats.sync') }}">
            @csrf
            <div class="mb-4">
                <p class="text-muted small mb-2">
                    Recupera tiri, passaggi, dribbling e valutazioni individuali per tutti i match definitivi
                    della stagione che non hanno ancora <code>player_stats_fetched_at</code>.
                    Una API call per match (copre entrambe le squadre). Richiede che i giocatori siano
                    già importati (Squadre → Sync Squadre).
                </p>
                <div class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label for="season" class="form-label small mb-1">Stagione (year_start)</label>
                        <input type="number" name="season" id="season" class="form-control form-control-sm"
                               value="{{ date('Y') }}" min="2020" max="{{ date('Y') + 1 }}" style="width:100px">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">Sync Statistiche Giocatori</button>
                    </div>
                </div>
            </div>
        </form>

        @if($report)
            <hr>
            @php
                $season = $report['season'] ?? date('Y');
            @endphp
            <h6 class="mb-3">
                Report stagione {{ $season }}
                &nbsp;<span class="badge bg-success">{{ $report['synced'] }} sincronizzati</span>
                &nbsp;<span class="badge bg-secondary">{{ $report['empty'] ?? 0 }} vuoti</span>
                @if(($report['failed'] ?? 0) > 0)
                    &nbsp;<span class="badge bg-danger">{{ $report['failed'] }} falliti</span>
                @endif
            </h6>

            <table class="table table-sm table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th class="text-center">Candidati</th>
                        <th class="text-center">Sincronizzati</th>
                        <th class="text-center">Vuoti</th>
                        <th class="text-center">Falliti</th>
                        <th class="text-center">API calls</th>
                        <th class="text-center">Daily rem.</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="text-center">{{ $report['candidates'] }}</td>
                        <td class="text-center">{{ $report['synced'] }}</td>
                        <td class="text-center">{{ $report['empty'] ?? 0 }}</td>
                        <td class="text-center">{{ $report['failed'] ?? 0 }}</td>
                        <td class="text-center">{{ $report['api_calls'] }}</td>
                        <td class="text-center">{{ $report['daily_remaining'] ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>

            @if(($report['status'] ?? '') !== 'ok')
                <div class="alert alert-warning py-2 small">
                    Status: <code>{{ $report['status'] }}</code>
                </div>
            @endif
        @endif

    </div>
</div>
@endsection
