@extends('layouts.app')

@section('title', 'Admin — Sync Infortuni API-Football')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Sync Infortuni & Disponibilità API-Football</h4>
            <div>
                <a href="{{ route('admin.api-football.dashboard') }}" class="btn btn-sm btn-outline-secondary me-2">Dashboard</a>
                <a href="{{ route('admin.api-football.player-stats') }}" class="btn btn-sm btn-outline-secondary">← Stat. Giocatori</a>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.api-football.injuries.sync') }}">
            @csrf
            <div class="mb-4">
                <p class="text-muted small mb-2">
                    Aggiorna la disponibilità dei giocatori per le prossime 7 giorni di partite.
                    Il throttle per partita è: &gt;48h → max 1/24h · 12–48h → max 1/6h · ≤12h → max 1/2h.
                    Le partite già oltre il kickoff non vengono mai ri-fetched.
                </p>
                <button type="submit" class="btn btn-primary btn-sm">Sync Infortuni (prossimi 7 giorni)</button>
            </div>
        </form>

        @if($report)
            <hr>
            <h6 class="mb-3">
                Report
                &nbsp;<span class="badge bg-success">{{ $report['synced'] }} aggiornati</span>
                &nbsp;<span class="badge bg-secondary">{{ $report['skipped_throttle'] ?? 0 }} throttled</span>
                @if(($report['failed'] ?? 0) > 0)
                    &nbsp;<span class="badge bg-danger">{{ $report['failed'] }} falliti</span>
                @endif
            </h6>

            <table class="table table-sm table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th class="text-center">Candidati</th>
                        <th class="text-center">Aggiornati</th>
                        <th class="text-center">Vuoti (tutti fit)</th>
                        <th class="text-center">Throttled</th>
                        <th class="text-center">Creati</th>
                        <th class="text-center">Aggiornati</th>
                        <th class="text-center">Rimossi (guariti)</th>
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
                        <td class="text-center">{{ $report['skipped_throttle'] ?? 0 }}</td>
                        <td class="text-center">{{ $report['created'] ?? 0 }}</td>
                        <td class="text-center">{{ $report['updated'] ?? 0 }}</td>
                        <td class="text-center">{{ $report['removed'] ?? 0 }}</td>
                        <td class="text-center">{{ $report['failed'] ?? 0 }}</td>
                        <td class="text-center">{{ $report['api_calls'] }}</td>
                        <td class="text-center">{{ $report['daily_remaining'] ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        @endif

    </div>
</div>
@endsection
