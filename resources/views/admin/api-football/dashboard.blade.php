@extends('layouts.app')

@section('title', 'Admin — Monitor API-Football')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-12">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Monitor API-Football</h4>
            <div>
                <a href="{{ route('admin.api-football.teams') }}" class="btn btn-sm btn-outline-secondary me-2">Squadre</a>
                <a href="{{ route('admin.api-football.fixtures') }}" class="btn btn-sm btn-outline-secondary">Calendario</a>
            </div>
        </div>

        @if($stats->isEmpty())
            <div class="alert alert-warning">
                Nessuna competition_external_id api-football trovata. Esegui prima il League Sync.
            </div>
        @else
            @foreach($stats as $s)
                @php
                    $statusClass = match($s['status']) {
                        'ok'         => 'success',
                        'attenzione' => 'warning',
                        default      => 'danger',
                    };
                @endphp
                <div class="card mb-4 border-{{ $statusClass }}">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <strong>{{ $s['competition']?->name ?? '—' }}</strong>
                            <span class="text-muted ms-2 small">League ID: {{ $s['league_id'] }}</span>
                        </div>
                        <span class="badge bg-{{ $statusClass }} text-uppercase">{{ $s['status'] }}</span>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Teams</th>
                                    <th>Team ExtIDs</th>
                                    <th class="{{ $s['teams_without_mapping'] > 0 ? 'text-danger fw-bold' : '' }}">Senza mapp.</th>
                                    <th>Match tot.</th>
                                    <th>Definitivi</th>
                                    <th>Non def.</th>
                                    <th>Postponed</th>
                                    <th>Suspended</th>
                                    <th>TBD</th>
                                    <th>Match ExtIDs</th>
                                    <th class="{{ $s['matches_without_mapping'] > 0 ? 'text-danger fw-bold' : '' }}">Senza mapp.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>{{ $s['total_teams'] }}</td>
                                    <td>{{ $s['team_external_ids'] }}</td>
                                    <td class="{{ $s['teams_without_mapping'] > 0 ? 'text-danger fw-bold' : '' }}">
                                        {{ $s['teams_without_mapping'] }}
                                    </td>
                                    <td>{{ $s['total_matches'] }}</td>
                                    <td>{{ $s['definitive_matches'] }}</td>
                                    <td>{{ $s['non_definitive_matches'] }}</td>
                                    <td>{{ $s['postponed'] }}</td>
                                    <td>{{ $s['suspended'] }}</td>
                                    <td>{{ $s['tbd'] }}</td>
                                    <td>{{ $s['match_external_ids'] }}</td>
                                    <td class="{{ $s['matches_without_mapping'] > 0 ? 'text-danger fw-bold' : '' }}">
                                        {{ $s['matches_without_mapping'] }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <table class="table table-sm table-bordered mb-0 border-top">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:20%">Tipo sync</th>
                                    <th>Ultima esecuzione</th>
                                    <th>Stato</th>
                                    <th class="text-center">+Cre</th>
                                    <th class="text-center">~Agg</th>
                                    <th class="text-center">Skip</th>
                                    <th class="text-center">Warn</th>
                                    <th class="text-center">API calls</th>
                                    <th class="text-center">Daily rem.</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach([
                                    ['Team Sync',       $s['last_team_sync']],
                                    ['Fixture FULL',    $s['last_fixture_full']],
                                    ['Fixture REFRESH', $s['last_fixture_refresh']],
                                ] as [$label, $run])
                                    <tr>
                                        <td class="text-muted small">{{ $label }}</td>
                                        @if($run)
                                            <td class="small">{{ $run->started_at->format('d/m/Y H:i') }}</td>
                                            <td>
                                                @if($run->status === 'ok')
                                                    <span class="badge bg-success">ok</span>
                                                @elseif($run->status === 'skipped')
                                                    <span class="badge bg-secondary">skipped</span>
                                                @else
                                                    <span class="badge bg-danger">failed</span>
                                                @endif
                                            </td>
                                            <td class="text-center">{{ $run->created_count }}</td>
                                            <td class="text-center">{{ $run->updated_count }}</td>
                                            <td class="text-center">{{ $run->skipped_count }}</td>
                                            <td class="text-center">{{ $run->warnings_count }}</td>
                                            <td class="text-center">{{ $run->api_calls }}</td>
                                            <td class="text-center">{{ $run->daily_remaining ?? '—' }}</td>
                                        @else
                                            <td colspan="8" class="text-muted small fst-italic">mai eseguito</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endif

    </div>
</div>
@endsection
