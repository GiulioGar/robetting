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

{{-- Save official prediction (Candidate V2 LOG — current champion) --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2 px-3">
        <span class="fw-semibold small">PREDICTION UFFICIALE</span>
        <span class="text-muted small ms-2">ROBETTING CANDIDATE V2 LOG — salvataggio esplicito, non automatico</span>
    </div>
    <div class="card-body py-3 px-3">

        @if(session('official_prediction_saved'))
        <div class="alert alert-success py-2 mb-3">Prediction ufficiale Candidate V2 LOG salvata.</div>
        @endif

        @if(session('official_prediction_error'))
        <div class="alert alert-danger py-2 mb-3">{{ session('official_prediction_error') }}</div>
        @endif

        <form method="POST" action="{{ route('admin.prediction-engine.save-official', ['match' => $m->id]) }}" class="mb-3">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">
                Salva prediction ufficiale
            </button>
        </form>

        <div class="small">
            @if($lastOfficialPrediction)
            <span class="text-muted">Stato: SALVATA</span> —
            <span class="font-monospace ms-1">
                generated_at {{ $lastOfficialPrediction->generated_at->format('d/m/Y H:i:s') }} —
                P1 {{ number_format($lastOfficialPrediction->probability_home * 100, 1) }}% /
                PX {{ number_format($lastOfficialPrediction->probability_draw * 100, 1) }}% /
                P2 {{ number_format($lastOfficialPrediction->probability_away * 100, 1) }}%
            </span>
            @else
            <span class="text-muted">Stato: NON SALVATA</span>
            @endif
        </div>

    </div>{{-- card-body --}}
</div>{{-- card --}}

{{-- Diagnostic context --}}
@if($matchContext)
@php
    $ctx = $matchContext;
    $fmtMv = fn(?int $v) => $v ? '€'.number_format($v / 1_000_000, 1).'M' : '—';
    $fmtF  = fn(?float $v, int $d = 1) => $v !== null ? number_format($v, $d) : '—';
@endphp
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-info bg-opacity-10 py-2 px-3">
        <span class="fw-semibold small">Contesto Match</span>
        <span class="text-muted small ms-2">diagnostico — non usato dal modello</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-bordered mb-0 small font-monospace">
            <thead class="table-light">
                <tr>
                    <th style="width:35%"></th>
                    <th class="text-center">{{ $m->homeTeam->name }}</th>
                    <th class="text-center">{{ $m->awayTeam->name }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-muted">Elo pre-match</td>
                    <td class="text-center">{{ $fmtF($ctx['home_elo']) }}</td>
                    <td class="text-center">{{ $fmtF($ctx['away_elo']) }}</td>
                </tr>
                <tr>
                    <td class="text-muted">Structural Strength</td>
                    <td class="text-center">{{ $fmtF($ctx['home_structural']) }}</td>
                    <td class="text-center">{{ $fmtF($ctx['away_structural']) }}</td>
                </tr>
                <tr>
                    <td class="text-muted">Market Value</td>
                    <td class="text-center">{{ $fmtMv($ctx['home_market_value']) }}</td>
                    <td class="text-center">{{ $fmtMv($ctx['away_market_value']) }}</td>
                </tr>
                <tr>
                    <td class="text-muted">RECENT considerati</td>
                    <td class="text-center">{{ $ctx['home_recent_n'] }} / 10</td>
                    <td class="text-center">{{ $ctx['away_recent_n'] }} / 10</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
@endif
@endif

{{-- Model comparison --}}
@if($comparison)
@php
    // Only FULL59 (production) and Candidate V2 LOG (P18D) are shown here.
    // NO_E9/CANDIDATE 39/40/C40 ROBUST BP/C44 BP/C48 V2 RAW stay computed by
    // CandidateModelService (rollback/debug) and covered by tests — just hidden from this view.
    $models = [
        ['label' => 'FULL 59 — PRODUCTION',       'feat' => 59, 'data' => $comparison['full59'],                         'avail' => true],
        ['label' => 'ROBETTING CANDIDATE V2 LOG', 'feat' => 47, 'data' => $comparison['candidate47_structural_log'] ?? null, 'avail' => $comparison['candidate47_structural_log_available'] ?? false],
    ];
    $rows = [
        ['key' => 'lambda_home',      'label' => 'λ home',   'fmt' => fn($v) => number_format($v, 4), 'highlight' => false],
        ['key' => 'lambda_away',      'label' => 'λ away',   'fmt' => fn($v) => number_format($v, 4), 'highlight' => false],
        ['key' => 'lambda3',          'label' => 'λ3 (BP)',  'fmt' => fn($v) => number_format($v, 4), 'highlight' => false],
        ['key' => 'probability_home', 'label' => 'P(1) HOME','fmt' => fn($v) => number_format($v * 100, 1).'%', 'highlight' => true,  'show_odds' => true],
        ['key' => 'probability_draw', 'label' => 'P(X) DRAW','fmt' => fn($v) => number_format($v * 100, 1).'%', 'highlight' => true,  'show_odds' => true],
        ['key' => 'probability_away', 'label' => 'P(2) AWAY','fmt' => fn($v) => number_format($v * 100, 1).'%', 'highlight' => true,  'show_odds' => true],
    ];
@endphp
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-secondary bg-opacity-10 py-2 px-3">
        <span class="fw-semibold small">Model Comparison</span>
        <span class="text-muted small ms-2">FULL 59 — production / ROBETTING CANDIDATE V2 LOG</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-bordered mb-0 small font-monospace">
            <thead class="table-light">
                <tr>
                    <th style="width:20%"></th>
                    @foreach($models as $m)
                    <th class="text-center">
                        {{ $m['label'] }}
                        @if(!$m['avail'])
                        <span class="text-muted fw-normal">(n/a)</span>
                        @endif
                    </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                @php
                    $vals = [];
                    foreach ($models as $m) {
                        $vals[] = ($m['avail'] && $m['data']) ? ($m['data'][$row['key']] ?? null) : null;
                    }
                    $maxVal = $row['highlight'] ? max(array_filter($vals, fn($v) => $v !== null)) : null;
                @endphp
                <tr>
                    <td class="text-muted">{{ $row['label'] }}</td>
                    @foreach($models as $idx => $m)
                    @php
                        $val = $vals[$idx];
                        $isMax = $row['highlight'] && $val !== null && abs($val - $maxVal) < 1e-9;
                    @endphp
                    <td class="text-center {{ $isMax ? 'fw-bold text-success' : '' }} {{ $val === null ? 'text-muted' : '' }}">
                        @if($val === null)
                            —
                        @else
                            {{ $row['fmt']($val) }}@if(!empty($row['show_odds']) && $val > 0)
                            <span class="text-muted fw-normal ms-1">({{ number_format(1 / $val, 2) }})</span>@endif
                        @endif
                    </td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Structural TOP25 inputs (real euros) — Candidate V2 LOG only, so its incidence on the model is legible --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2 px-3">
        <span class="fw-semibold small">Structural TOP25</span>
        <span class="text-muted small ms-2">input di ROBETTING CANDIDATE V2 LOG — somma TOP25 market value point-in-time</span>
    </div>
    <div class="card-body py-3 px-3">
        @if($comparison['candidate47_structural_log_inputs'] ?? null)
        @php $si = $comparison['candidate47_structural_log_inputs']; @endphp
        <table class="table table-sm table-borderless mb-0 small font-monospace">
            <tr><td class="text-muted" style="width:120px">Home</td><td>&euro;{{ number_format($si['structural_home'], 0, ',', '.') }}</td></tr>
            <tr><td class="text-muted">Away</td><td>&euro;{{ number_format($si['structural_away'], 0, ',', '.') }}</td></tr>
            <tr><td class="text-muted">Gap</td><td class="{{ $si['structural_gap'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $si['structural_gap'] >= 0 ? '+' : '' }}&euro;{{ number_format($si['structural_gap'], 0, ',', '.') }}</td></tr>
        </table>
        @else
        <p class="text-muted small mb-0">Structural TOP25 non disponibile (snapshot corrente mancante o non valido per questo match) — Candidate V2 LOG mostra (n/a).</p>
        @endif
    </div>
</div>
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
