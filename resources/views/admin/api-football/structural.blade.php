@extends('layouts.app')

@section('title', 'Admin — Forza Strutturale')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-12">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Forza Strutturale</h4>
            <a href="{{ route('admin.api-football.dashboard') }}" class="btn btn-sm btn-outline-secondary">← Dashboard</a>
        </div>

        {{-- Error banner --}}
        @if($uploadError)
        <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
            {{ $uploadError }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        @endif

        {{-- Confirm result --}}
        @if($confirmResult)
        <div class="alert alert-success py-2">
            Import completato: <strong>{{ $confirmResult['inserted'] }}</strong> snapshot inseriti.
            @if($confirmResult['skipped_existing'] > 0)
                <span class="text-muted">({{ $confirmResult['skipped_existing'] }} già presenti, saltati)</span>
            @endif
        </div>
        @endif

        {{-- ── Structural summary ──────────────────────────────────────────── --}}
        <div class="card mb-4">
            <div class="card-header"><strong>Riepilogo snapshot</strong></div>
            <div class="card-body p-0">
                @if(!$summary['source_exists'])
                    <p class="px-3 py-2 mb-0 text-danger small">
                        Fonte <code>transfermarkt</code> non presente in <code>data_sources</code>.
                        Inseriscila manualmente prima di procedere con l'import.
                    </p>
                @elseif($summary['latest_snapshot_date'] === null)
                    <p class="px-3 py-2 mb-0 text-muted small">Nessuno snapshot disponibile.</p>
                @else
                    <table class="table table-sm table-bordered mb-0" style="width:auto">
                        <tbody>
                            <tr>
                                <td class="text-muted small pe-4">Ultimo snapshot</td>
                                <td class="small fw-semibold">
                                    {{ \Carbon\Carbon::parse($summary['latest_snapshot_date'])->format('d/m/Y') }}
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted small">Fonte</td>
                                <td class="small">{{ $summary['source_name'] }}</td>
                            </tr>
                            <tr>
                                <td class="text-muted small">Team con valore</td>
                                <td class="small fw-semibold">{{ $summary['teams_with_value'] }}</td>
                            </tr>
                            <tr>
                                <td class="text-muted small {{ $summary['teams_without_value'] > 0 ? 'text-warning' : '' }}">
                                    Team senza valore
                                </td>
                                <td class="small {{ $summary['teams_without_value'] > 0 ? 'fw-bold text-warning' : '' }}">
                                    {{ $summary['teams_without_value'] }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- ── Team table ──────────────────────────────────────────────────── --}}
        @if($tableRows->isNotEmpty())
        <div class="card mb-4">
            <div class="card-header">
                <strong>Market Value &amp; Structural Rating</strong>
                <span class="text-muted small ms-2">(snapshot più recente per squadra)</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Team</th>
                            <th class="text-end">Market Value</th>
                            <th class="text-center">Structural Rating</th>
                            <th class="text-center">Snapshot Date</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tableRows as $i => $row)
                        <tr>
                            <td class="text-muted small">{{ $i + 1 }}</td>
                            <td class="small fw-semibold">{{ $row['team_name'] }}</td>
                            <td class="text-end small font-monospace">
                                €{{ number_format($row['market_value'], 0, ',', '.') }}
                            </td>
                            <td class="text-center small fw-semibold">
                                {{ number_format($row['structural_rating'], 1, ',', '.') }}
                            </td>
                            <td class="text-center small">
                                {{ \Carbon\Carbon::parse($row['snapshot_date'])->format('d/m/Y') }}
                            </td>
                            <td class="small text-muted">{{ $row['source_name'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- ── Teams without value ─────────────────────────────────────────── --}}
        @if($summary['teams_without_value'] > 0 && $teamsWithoutValue->isNotEmpty())
        <div class="card mb-4 border-warning">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <strong>Team senza market value</strong>
                <span class="badge bg-warning text-dark">{{ $summary['teams_without_value'] }}</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-bordered mb-0">
                    <tbody>
                        @foreach($teamsWithoutValue as $t)
                        <tr><td class="small">{{ $t->name }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- ── Genera JSON da Transfermarkt ────────────────────────────────── --}}
        <div class="card mb-4 border-primary">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Genera JSON da Transfermarkt</strong>
                <span class="badge bg-primary">Automatico</span>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Scarica i market value di tutte le 96 squadre (Serie A, Premier League,
                    La Liga, Bundesliga, Ligue 1) direttamente da Transfermarkt e avvia
                    automaticamente la preview di import. Richiede circa 20–30 secondi.
                </p>
                <form method="POST" action="{{ route('admin.api-football.structural.generate') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm"
                            onclick="this.disabled=true; this.innerHTML='&#9203; Scraping in corso…'; this.form.submit();">
                        &#128257; Genera &amp; Analizza (oggi — {{ now()->toDateString() }})
                    </button>
                </form>

                @if($generateOutput)
                <div class="mt-3">
                    <details>
                        <summary class="small text-muted" style="cursor:pointer">
                            Output collector (clicca per espandere)
                        </summary>
                        <pre class="mt-2 p-2 bg-light border rounded small" style="white-space:pre-wrap;max-height:220px;overflow-y:auto;">{{ $generateOutput }}</pre>
                    </details>
                </div>
                @endif
            </div>
        </div>

        {{-- ── Upload JSON manuale (funzione avanzata) ────────────────────── --}}
        <details class="mb-4">
            <summary class="text-muted small" style="cursor:pointer;list-style:none;user-select:none;">
                <span class="border rounded px-2 py-1">&#9654; Analizza JSON esistente (funzione avanzata)</span>
            </summary>
            <div class="card mt-2">
                <div class="card-header py-2"><strong class="small">Analizza JSON manuale</strong></div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Carica un file JSON nel formato snapshot Robetting generato esternamente.
                    </p>
                    <form method="POST"
                          action="{{ route('admin.api-football.structural.preview') }}"
                          enctype="multipart/form-data">
                        @csrf
                        <div class="d-flex gap-2 align-items-end">
                            <div class="flex-grow-1">
                                <label for="json_file" class="form-label small mb-1">File JSON</label>
                                <input type="file"
                                       name="json_file"
                                       id="json_file"
                                       accept=".json,application/json"
                                       class="form-control form-control-sm"
                                       required>
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                &#128269; Analizza
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </details>

        {{-- ── Preview ─────────────────────────────────────────────────────── --}}
        @if($preview)
        @php
            $pv = $preview;
            $pvBadge = $pv['valid'] ? 'success' : 'danger';
        @endphp
        <div class="card mb-4 border-{{ $pvBadge }}">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <strong>Preview import</strong>
                <span class="badge bg-{{ $pvBadge }}">{{ $pv['valid'] ? 'VALIDO' : 'ERRORE' }}</span>
            </div>
            <div class="card-body">

                @if(!$pv['valid'])
                    <p class="text-danger mb-0"><strong>Errore:</strong> {{ $pv['error'] }}</p>
                @else
                    @php $s = $pv['summary']; @endphp

                    {{-- File metadata --}}
                    <table class="table table-sm table-borderless mb-3 small" style="width:auto">
                        <tbody>
                            @if($pendingFile)
                            <tr>
                                <td class="text-muted pe-3 py-1">File</td>
                                <td class="py-1 font-monospace">{{ $pendingFile['filename'] }}</td>
                            </tr>
                            <tr>
                                <td class="text-muted pe-3 py-1">Source</td>
                                <td class="py-1">{{ $pendingFile['source'] }}</td>
                            </tr>
                            @endif
                            <tr>
                                <td class="text-muted pe-3 py-1">Snapshot date</td>
                                <td class="py-1 fw-semibold">{{ $pv['snapshot_date'] }}</td>
                            </tr>
                            <tr>
                                <td class="text-muted pe-3 py-1">Teams</td>
                                <td class="py-1 fw-semibold">{{ $s['total_teams'] }}</td>
                            </tr>
                        </tbody>
                    </table>

                    {{-- Summary badges --}}
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="badge bg-secondary">Totale: {{ $s['total_teams'] }}</span>
                        <span class="badge bg-success">Mappati: {{ $s['mapped_teams'] }}</span>
                        @if($s['unmapped_teams'] > 0)
                        <span class="badge bg-warning text-dark">Non mappati: {{ $s['unmapped_teams'] }}</span>
                        @endif
                        @if($s['invalid_values'] > 0)
                        <span class="badge bg-danger">Valori non validi: {{ $s['invalid_values'] }}</span>
                        @endif
                        @if($s['duplicate_teams'] > 0)
                        <span class="badge bg-warning text-dark">Duplicati: {{ $s['duplicate_teams'] }}</span>
                        @endif
                        @if($s['existing_snapshots'] > 0)
                        <span class="badge bg-secondary">Già presenti: {{ $s['existing_snapshots'] }}</span>
                        @endif
                        <span class="badge bg-primary">Nuovi: {{ $s['new_snapshots'] }}</span>
                    </div>

                    {{-- Row detail --}}
                    <table class="table table-sm table-bordered mb-3">
                        <thead class="table-light">
                            <tr>
                                <th>Team</th>
                                <th class="text-end">Market Value</th>
                                <th class="text-center">Stato</th>
                                <th>Dettaglio</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pv['rows'] as $row)
                            @php
                                $rowClass = match($row['status']) {
                                    'ok'                   => '',
                                    'already_exists'       => 'table-secondary',
                                    'unmapped'             => 'table-warning',
                                    'duplicate_in_file'    => 'table-warning',
                                    default                => 'table-danger',
                                };
                                $statusLabel = match($row['status']) {
                                    'ok'                   => '<span class="badge bg-success">PRONTO</span>',
                                    'already_exists'       => '<span class="badge bg-secondary">GIÀ PRESENTE</span>',
                                    'unmapped'             => '<span class="badge bg-warning text-dark">NON MAPPATO</span>',
                                    'duplicate_in_file'    => '<span class="badge bg-warning text-dark">DUPLICATO</span>',
                                    'invalid_market_value' => '<span class="badge bg-danger">VALORE NON VALIDO</span>',
                                    'missing_market_value' => '<span class="badge bg-danger">VALORE MANCANTE</span>',
                                    'missing_team_name'    => '<span class="badge bg-danger">NOME MANCANTE</span>',
                                    default                => '<span class="badge bg-danger">ERRORE</span>',
                                };
                            @endphp
                            <tr class="{{ $rowClass }}">
                                <td class="small">{{ $row['team_name'] ?? '—' }}</td>
                                <td class="text-end small font-monospace">
                                    @if($row['market_value'] !== null)
                                        €{{ number_format($row['market_value'], 0, ',', '.') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-center">{!! $statusLabel !!}</td>
                                <td class="small text-muted">{{ $row['detail'] ?? '' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>

                    {{-- Confirm button — only if there are ok rows --}}
                    @if($s['new_snapshots'] > 0)
                    <form method="POST" action="{{ route('admin.api-football.structural.confirm') }}">
                        @csrf
                        <button type="submit" class="btn btn-success">
                            &#10003; CONFERMA IMPORT ({{ $s['new_snapshots'] }} snapshot)
                        </button>
                    </form>
                    @else
                    <p class="text-muted small mb-0">Nessuna riga importabile. Correggi il file e rianalizza.</p>
                    @endif
                @endif

            </div>
        </div>
        @endif

    </div>
</div>
@endsection
