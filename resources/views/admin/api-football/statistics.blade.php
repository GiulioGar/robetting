@extends('layouts.app')

@section('title', 'Admin — Sync Statistiche API-Football')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Sync Statistiche Match API-Football</h4>
            <div>
                <a href="{{ route('admin.api-football.dashboard') }}" class="btn btn-sm btn-outline-secondary me-2">Dashboard</a>
                <a href="{{ route('admin.api-football.fixtures') }}" class="btn btn-sm btn-outline-secondary">← Calendario</a>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.api-football.statistics.sync') }}">
            @csrf
            <div class="mb-4">
                <p class="text-muted small mb-2">
                    Recupera tiri, falli, angoli e cartellini per tutti i match definitivi che non hanno statistiche complete.
                    Una API call per match. Idempotente: i match già completi non generano chiamate API.
                </p>
                <button type="submit" class="btn btn-primary btn-sm">Sync Statistiche</button>
            </div>
        </form>

        @if($report)
            <hr>
            <h6 class="mb-3">
                Report
                &nbsp;<span class="badge bg-success">+{{ $report['created'] }} create</span>
                &nbsp;<span class="badge bg-warning text-dark">~{{ $report['updated'] }} aggiornate</span>
                &nbsp;<span class="badge bg-secondary">={{ $report['unchanged'] }} invariate</span>
                @if($report['skipped'] > 0)
                    &nbsp;<span class="badge bg-danger">{{ $report['skipped'] }} skip</span>
                @endif
            </h6>

            <table class="table table-sm table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th class="text-center">Candidati</th>
                        <th class="text-center">Create</th>
                        <th class="text-center">Aggiornate</th>
                        <th class="text-center">Invariate</th>
                        <th class="text-center">Skip</th>
                        <th class="text-center">API calls</th>
                        <th class="text-center">Daily rem.</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="text-center">{{ $report['candidates'] }}</td>
                        <td class="text-center">{{ $report['created'] }}</td>
                        <td class="text-center">{{ $report['updated'] }}</td>
                        <td class="text-center">{{ $report['unchanged'] }}</td>
                        <td class="text-center">{{ $report['skipped'] }}</td>
                        <td class="text-center">{{ $report['api_calls'] }}</td>
                        <td class="text-center">{{ $report['daily_remaining'] ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>

            @if(!empty($report['warnings']))
                <div class="alert alert-warning py-2">
                    <ul class="mb-0 small">
                        @foreach($report['warnings'] as $w)
                            <li>{{ $w }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif

    </div>
</div>
@endsection
