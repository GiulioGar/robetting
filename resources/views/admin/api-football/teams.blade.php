@extends('layouts.app')

@section('title', 'Admin — Sync Squadre API-Football')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">

        <h4 class="mb-3">Sync Squadre API-Football</h4>

        <form method="POST" action="{{ route('admin.api-football.teams.sync') }}">
            @csrf
            <div class="d-flex align-items-center gap-3 mb-4">
                <div>
                    <label class="form-label mb-1 small fw-semibold">Season</label>
                    <input type="number" name="season" value="2026" class="form-control form-control-sm" style="width:100px">
                </div>
                <div class="align-self-end">
                    <button type="submit" class="btn btn-primary btn-sm">Aggiorna squadre</button>
                </div>
            </div>
        </form>

        @if($report)
            <hr>
            <h6 class="mb-3">
                Report — season {{ $report['season'] }}
                &nbsp;<span class="badge bg-success">+{{ $report['teams_created'] }} create</span>
                &nbsp;<span class="badge bg-warning text-dark">~{{ $report['teams_updated'] }} aggiornate</span>
            </h6>

            <table class="table table-sm table-bordered align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Competizione</th>
                        <th>League ID</th>
                        <th>Status</th>
                        <th class="text-center">Create</th>
                        <th class="text-center">Agg.</th>
                        <th class="text-center">Inv.</th>
                        <th class="text-center">API calls</th>
                        <th class="text-center">Rem.</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['results'] as $r)
                        <tr>
                            <td><code>{{ $r['competition_slug'] }}</code></td>
                            <td>{{ $r['league_id'] }}</td>
                            <td>
                                @if($r['status'] === 'ok')
                                    <span class="badge bg-success">ok</span>
                                @elseif($r['status'] === 'skipped')
                                    <span class="badge bg-secondary">skipped</span>
                                @else
                                    <span class="badge bg-danger">failed</span>
                                @endif
                            </td>
                            <td class="text-center">{{ $r['created'] }}</td>
                            <td class="text-center">{{ $r['updated'] }}</td>
                            <td class="text-center">{{ $r['unchanged'] }}</td>
                            <td class="text-center">{{ $r['api_calls'] }}</td>
                            <td class="text-center">{{ $r['requests_remaining'] ?? '—' }}</td>
                            <td class="small text-muted">
                                {{ $r['message'] ?? '' }}
                                @foreach($r['warnings'] ?? [] as $w)
                                    <div class="text-warning">{{ $w }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

    </div>
</div>
@endsection
