@extends('layouts.app')

@section('title', 'Admin — Prediction Engine V1')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-12">

{{-- Header --}}
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Prediction Engine V1 <span class="badge bg-secondary fw-normal fs-6">debug</span></h4>
    <a href="{{ route('admin.api-football.dashboard') }}" class="btn btn-sm btn-outline-secondary">← Admin Dashboard</a>
</div>

{{-- Error --}}
@if($error)
<div class="alert alert-danger py-2 mb-3">{{ $error }}</div>
@endif

{{-- Prediction result --}}
@if($prediction)
@php
    $m    = $prediction['match'];
    $pH   = $prediction['probability_home'];
    $pD   = $prediction['probability_draw'];
    $pA   = $prediction['probability_away'];
    $pSum = $pH + $pD + $pA;
    $winner = $pH >= $pD && $pH >= $pA ? 'H' : ($pA >= $pD ? 'A' : 'D');
@endphp

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white py-2 px-3">
        <span class="fw-semibold">{{ $m->homeTeam->name }} vs {{ $m->awayTeam->name }}</span>
        <span class="ms-3 text-secondary small">
            {{ $m->competition->name }} &mdash;
            {{ \Carbon\Carbon::parse($m->kickoff_at)->format('d/m/Y H:i') }} &mdash;
            <span class="badge {{ $m->status === 'finished' ? 'bg-success' : 'bg-warning text-dark' }}">{{ $m->status }}</span>
        </span>
    </div>
    <div class="card-body py-3 px-3">

        {{-- 1X2 probabilities — main visual element --}}
        <div class="row g-3 mb-4">
            <div class="col-4">
                <div class="p-3 rounded text-center {{ $winner === 'H' ? 'bg-success bg-opacity-10 border border-success' : 'bg-light' }}">
                    <div class="text-muted small mb-1">1 HOME</div>
                    <div class="fw-bold fs-2">{{ number_format($pH * 100, 1) }}%</div>
                    <div class="text-muted small">{{ $m->homeTeam->name }}</div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded text-center {{ $winner === 'D' ? 'bg-success bg-opacity-10 border border-success' : 'bg-light' }}">
                    <div class="text-muted small mb-1">X DRAW</div>
                    <div class="fw-bold fs-2">{{ number_format($pD * 100, 1) }}%</div>
                    <div class="text-muted small">Pareggio</div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded text-center {{ $winner === 'A' ? 'bg-success bg-opacity-10 border border-success' : 'bg-light' }}">
                    <div class="text-muted small mb-1">2 AWAY</div>
                    <div class="fw-bold fs-2">{{ number_format($pA * 100, 1) }}%</div>
                    <div class="text-muted small">{{ $m->awayTeam->name }}</div>
                </div>
            </div>
        </div>

        {{-- Metadata + goal expectancy --}}
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <table class="table table-sm table-borderless mb-0 small">
                    <caption class="caption-top fw-semibold text-dark pt-0">MODEL</caption>
                    <tr><td class="text-muted">model_version</td><td class="font-monospace">{{ $prediction['model_version'] }}</td></tr>
                    <tr><td class="text-muted">feature_set_version</td><td class="font-monospace">{{ $prediction['feature_set_version'] }}</td></tr>
                </table>
            </div>
            <div class="col-md-4">
                <table class="table table-sm table-borderless mb-0 small">
                    <caption class="caption-top fw-semibold text-dark pt-0">FEATURE</caption>
                    <tr><td class="text-muted">feature_count</td><td class="font-monospace">{{ $prediction['feature_count'] }}</td></tr>
                    <tr>
                        <td class="text-muted">null_count</td>
                        <td class="font-monospace {{ $prediction['null_count'] > 0 ? 'text-warning' : '' }}">
                            {{ $prediction['null_count'] }}
                            @if($prediction['null_count'] > 0)
                            <span class="text-muted">(→ median imputation)</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </div>
            <div class="col-md-4">
                <table class="table table-sm table-borderless mb-0 small">
                    <caption class="caption-top fw-semibold text-dark pt-0">GOAL EXPECTANCY</caption>
                    <tr><td class="text-muted">λ home</td><td class="font-monospace">{{ number_format($prediction['lambda_home'], 4) }}</td></tr>
                    <tr><td class="text-muted">λ away</td><td class="font-monospace">{{ number_format($prediction['lambda_away'], 4) }}</td></tr>
                    <tr><td class="text-muted">sum P</td>
                        <td class="font-monospace {{ abs($pSum - 1.0) > 1e-6 ? 'text-danger fw-bold' : 'text-success' }}">
                            {{ number_format($pSum, 8) }}
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        {{-- Timing --}}
        <div class="small text-muted mb-3">
            Feature aggregation: <strong>{{ $prediction['timing_agg_ms'] }} ms</strong> &nbsp;|&nbsp;
            Model inference: <strong>{{ $prediction['timing_inf_ms'] }} ms</strong> &nbsp;|&nbsp;
            Total: <strong>{{ $prediction['timing_total_ms'] }} ms</strong>
        </div>

        {{-- Feature debug (collapsible) --}}
        <div>
            <button class="btn btn-sm btn-outline-secondary" type="button"
                data-bs-toggle="collapse" data-bs-target="#featureDebug">
                Feature utilizzate ({{ $prediction['feature_count'] }}) ▼
            </button>
            <div class="collapse mt-2" id="featureDebug">
                <table class="table table-sm table-bordered table-hover font-monospace small mb-0">
                    <thead class="table-light">
                        <tr><th style="width:60%">Feature</th><th>Valore</th></tr>
                    </thead>
                    <tbody>
                        @foreach($prediction['features'] as $feat => $val)
                        <tr>
                            <td class="text-break">{{ $feat }}</td>
                            <td class="{{ $val === null ? 'text-warning' : '' }}">
                                @if($val === null)
                                    <em>NULL → median imputation</em>
                                @else
                                    {{ number_format($val, 6) }}
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

    </div>{{-- card-body --}}
</div>{{-- card --}}
@endif

{{-- Match selection --}}
<div class="card border-0 shadow-sm">
    <div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center">
        <span class="fw-semibold small">Seleziona match</span>
        <form method="GET" action="{{ route('admin.prediction-engine.index') }}" class="d-flex gap-2 align-items-center m-0">
            <select name="competition_id" class="form-select form-select-sm" style="width:160px" onchange="this.form.submit()">
                <option value="">Tutte le leghe</option>
                @foreach([15=>'Serie A',16=>'Premier League',17=>'La Liga',18=>'Bundesliga',19=>'Ligue 1'] as $cid => $cname)
                <option value="{{ $cid }}" {{ $competitionId == $cid ? 'selected' : '' }}>{{ $cname }}</option>
                @endforeach
            </select>
            <div class="form-check form-check-inline mb-0 ms-1">
                <input class="form-check-input" type="checkbox" name="finished" value="1"
                    id="chkFinished" {{ $showFinished ? 'checked' : '' }} onchange="this.form.submit()">
                <label class="form-check-label small" for="chkFinished">Completate</label>
            </div>
            @if($selectedId)
            <input type="hidden" name="match_id" value="{{ $selectedId }}">
            @endif
        </form>
    </div>
    <div class="card-body p-0">
        @if($matches->isEmpty())
        <p class="text-muted text-center py-3 mb-0 small">Nessun match trovato per i filtri selezionati.</p>
        @else
        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-dark sticky-top">
                    <tr>
                        <th>ID</th>
                        <th>Competizione</th>
                        <th>Kickoff</th>
                        <th>Home</th>
                        <th>Away</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($matches as $m)
                    <tr class="{{ $selectedId == $m->id ? 'table-warning' : '' }}">
                        <td class="font-monospace text-muted">{{ $m->id }}</td>
                        <td>{{ $m->competition->name ?? '—' }}</td>
                        <td class="font-monospace">{{ \Carbon\Carbon::parse($m->kickoff_at)->format('d/m H:i') }}</td>
                        <td>{{ $m->homeTeam->name }}</td>
                        <td>{{ $m->awayTeam->name }}</td>
                        <td>
                            <span class="badge {{ $m->status === 'finished' ? 'bg-success' : 'bg-secondary' }}">
                                {{ $m->status }}
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('admin.prediction-engine.index', array_filter(['match_id' => $m->id, 'competition_id' => $competitionId, 'finished' => $showFinished ? 1 : null])) }}"
                               class="btn btn-xs btn-outline-primary btn-sm py-0 px-2"
                               style="font-size:0.75rem">
                                Analizza
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

</div>{{-- col --}}
</div>{{-- row --}}
@endsection
