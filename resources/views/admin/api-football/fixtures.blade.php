@extends('layouts.app')

@section('title', 'Admin — Sync Calendario API-Football')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-11">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Sync Calendario API-Football</h4>
            <div>
                <a href="{{ route('admin.api-football.dashboard') }}" class="btn btn-sm btn-outline-secondary me-2">Dashboard</a>
                <a href="{{ route('admin.api-football.teams') }}" class="btn btn-sm btn-outline-secondary">← Squadre</a>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.api-football.fixtures.sync') }}">
            @csrf
            <div class="d-flex align-items-end gap-3 mb-4">
                <div>
                    <label class="form-label mb-1 small fw-semibold">Season</label>
                    <input type="number" name="season" value="2026" class="form-control form-control-sm" style="width:100px">
                </div>
                <div>
                    <label class="form-label mb-1 small fw-semibold">Modalità</label>
                    <select name="mode" class="form-select form-select-sm" style="width:280px">
                        @foreach($modes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary btn-sm">Aggiorna calendario</button>
                </div>
            </div>
        </form>

        @if($report)
            <hr>
            <h6 class="mb-3">
                Report — season {{ $report['season'] }}
                &nbsp;<span class="badge bg-secondary text-uppercase">{{ $report['mode'] }}</span>
                &nbsp;<span class="badge bg-success">+{{ $report['fixtures_created'] }} create</span>
                &nbsp;<span class="badge bg-warning text-dark">~{{ $report['fixtures_updated'] }} aggiornate</span>
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
                        <th class="text-center">Skip</th>
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
                            <td class="text-center">{{ $r['skipped'] }}</td>
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
