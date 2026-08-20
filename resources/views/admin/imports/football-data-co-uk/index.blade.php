@extends('layouts.app')

@section('title', 'Gestione dati Football-Data.co.uk')

@section('content')
    <h1 class="h3 mb-4">Gestione dati Football-Data.co.uk</h1>

    <p class="text-muted">
        Carica qui i file raw (CSV singoli o <code>data.zip</code>) in
        <code>storage/app/imports/football-data-co-uk/&#123;stagione&#125;/</code>.
        Il file verrà salvato e, se la directory della stagione risulta completa (tutti e 5 i CSV core),
        i dati verranno importati automaticamente nel database.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($report)
        <div class="card mb-4 border-success">
            <div class="card-header bg-success-subtle">Upload — completato</div>
            <div class="card-body">
                <p class="mb-3">
                    Stagione: <strong>{{ $report['season_label'] }}</strong><br>
                    Directory: <code>{{ $report['season_value'] }}</code>
                </p>
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Righe dati</th>
                            <th>Esito</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['files'] as $f)
                            <tr>
                                <td>{{ $f['filename'] }}</td>
                                <td>{{ $f['rows'] }}</td>
                                <td>
                                    @if ($f['status'] === 'sostituito')
                                        <span class="badge text-bg-warning">sostituito</span>
                                    @else
                                        <span class="badge text-bg-success">nuovo</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($importTriggerError)
        <div class="alert alert-danger">{{ $importTriggerError }}</div>
    @endif

    @if ($importMissingCore)
        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning-subtle">
                Upload completato — Import non eseguito
            </div>
            <div class="card-body">
                <p class="mb-2">File core mancanti nella directory della stagione:</p>
                <ul class="mb-0">
                    @foreach ($importMissingCore as $f)
                        <li>{{ $f['name'] }} — <code>{{ $f['filename'] }}</code></li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if ($importReport)
        <div class="card mb-4 border-{{ $importReport['status'] === 'success' ? 'success' : 'danger' }}">
            <div class="card-header {{ $importReport['status'] === 'success' ? 'bg-success-subtle' : 'bg-danger-subtle' }}">
                Import — stagione {{ $importReport['season'] }} ({{ $importReport['season_code'] }}) —
                {{ strtoupper($importReport['status']) }}
            </div>
            <div class="card-body">
                @foreach ($importReport['leagues'] as $league)
                    <div class="mb-3 pb-3 border-bottom">
                        <h3 class="h6 mb-2">
                            {{ $league['slug'] }} ({{ $league['div'] }} / FDO {{ $league['fdo_code'] }})
                            @if ($league['status'] === 'success')
                                <span class="badge text-bg-success">completato</span>
                            @elseif ($league['status'] === 'failed')
                                <span class="badge text-bg-danger">import interrotto</span>
                            @else
                                <span class="badge text-bg-secondary">non eseguita</span>
                            @endif
                        </h3>

                        @if ($league['status'] === 'skipped')
                            <p class="text-muted small mb-0">
                                Non eseguita — batch fermato per un errore in una lega precedente.
                            </p>
                        @elseif ($league['status'] === 'failed')
                            <p class="mb-1 small"><strong>Step:</strong> {{ $league['failed_step'] }}</p>
                            <pre class="small bg-light p-2 mb-0" style="white-space: pre-wrap;">{{ $league['error'] }}</pre>
                        @else
                            @if ($league['fdo']['real'] ?? null)
                                @php $fdo = $league['fdo']['real']; @endphp
                                <p class="mb-1 small">
                                    <strong>FDO</strong> —
                                    teams created: {{ $fdo['teams']['created'] }}, existing: {{ $fdo['teams']['updated'] }};
                                    matches created: {{ $fdo['matches']['created'] }},
                                    linked: {{ $fdo['matches']['linked'] }},
                                    updated: {{ $fdo['matches']['updated'] }},
                                    skipped: {{ $fdo['matches']['skipped'] }}
                                </p>
                            @endif
                            @if ($league['fdcuk']['real'] ?? null)
                                @php $fdcuk = $league['fdcuk']['real']; @endphp
                                <p class="mb-1 small">
                                    <strong>FDCUK</strong> —
                                    matches created: {{ $fdcuk['matches']['created'] }},
                                    linked: {{ $fdcuk['matches']['linked'] }},
                                    updated: {{ $fdcuk['matches']['updated'] }};
                                    statistics created: {{ $fdcuk['statistics']['created'] }},
                                    updated: {{ $fdcuk['statistics']['updated'] }},
                                    skipped: {{ $fdcuk['statistics']['skipped'] }}
                                </p>
                            @endif
                            @if ($league['verification'] ?? null)
                                @php $v = $league['verification']; @endphp
                                <p class="mb-0 small">
                                    <strong>Verification</strong> —
                                    matches: {{ $v['match_count'] ?? '—' }},
                                    statistics: {{ $v['match_statistics_count'] ?? '—' }},
                                    duplicate pairs: {{ $v['duplicate_pairs'] ?? '—' }},
                                    duplicate statistics: {{ $v['duplicate_statistics'] ?? '—' }}
                                </p>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <form method="GET" action="{{ route('admin.imports.football-data-co-uk.index') }}" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
            <label for="seasonSelect" class="form-label fw-semibold">Stagione</label>
            <select name="season" id="seasonSelect" class="form-select" onchange="this.form.submit()">
                @foreach ($seasonOptions as $value => $label)
                    <option value="{{ $value }}" @selected((string) $value === $selectedSeason)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary">Aggiorna</button>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.imports.football-data-co-uk.store') }}" enctype="multipart/form-data" class="mb-5" id="uploadForm">
        @csrf
        <input type="hidden" name="season" value="{{ $selectedSeason }}">

        <p class="mb-3">
            Stagione destinazione:
            <strong id="targetSeasonLabel">{{ $seasonOptions[$selectedSeason] }}</strong>
            (<code>{{ $selectedSeason }}</code>)
        </p>

        <div id="dropzone" class="border border-2 border-dashed rounded p-5 text-center bg-white">
            <p class="mb-2">Trascina qui <strong>data.zip</strong> o un CSV</p>
            <p class="text-muted small mb-3">oppure</p>
            <button type="button" id="browseBtn" class="btn btn-outline-primary btn-sm">Sfoglia file</button>
            <input type="file" name="upload" id="fileInput" accept=".csv,.zip" class="d-none" required>
            <div id="fileInfo" class="mt-3 text-start small text-muted mx-auto" style="display:none; max-width: 420px;"></div>
        </div>

        <p class="text-muted small mt-2">
            Solo file .csv o .zip, un file per upload. Dimensione massima gestita dal form: 20&nbsp;MB
            (il limite effettivo dipende comunque da <code>upload_max_filesize</code>/<code>post_max_size</code> di PHP).
        </p>

        <p class="text-muted small mb-2">
            Il file verrà salvato e i dati della stagione verranno aggiornati automaticamente
            (solo quando tutti e 5 i CSV core sono presenti nella directory).
        </p>

        <button type="submit" id="uploadBtn" class="btn btn-primary mt-2">Carica e importa</button>
    </form>

    <h2 class="h5 mb-3">File già presenti — stagione {{ $seasonOptions[$selectedSeason] }}</h2>

    @if (empty($existingFiles))
        <p class="text-muted">Nessun file presente per questa stagione.</p>
    @else
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Dimensione</th>
                    <th>Ultima modifica</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($existingFiles as $f)
                    <tr>
                        <td>{{ $f['filename'] }}</td>
                        <td>{{ $f['size_human'] }}</td>
                        <td>{{ $f['modified_at']->format('d/m/Y H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2 class="h5 mb-3 mt-5">Reimport manuale</h2>

    <p class="text-muted small">
        Di norma l'import parte già automaticamente dopo l'upload. Usa questo pulsante solo per
        rilanciare l'import nel database senza ricaricare i file (es. dopo aver corretto un alias).
    </p>

    @error('import')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    <div class="card mb-5">
        <div class="card-body">
            <p class="mb-2">
                Stagione: <strong>{{ $seasonOptions[$selectedSeason] }}</strong><br>
                Directory: <code>{{ $selectedSeason }}</code><br>
                Stato DB:
                @if ($dbAlreadyPresent)
                    <span class="badge text-bg-info">Già presente nel DB</span>
                @else
                    <span class="badge text-bg-secondary">Non importata</span>
                @endif
            </p>

            <table class="table table-sm mb-3">
                <thead>
                    <tr>
                        <th>Lega</th>
                        <th>File core</th>
                        <th>Stato</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($coreFiles as $f)
                        <tr>
                            <td>{{ $f['name'] }}</td>
                            <td><code>{{ $f['filename'] }}</code></td>
                            <td>
                                @if ($f['present'])
                                    <span class="badge text-bg-success">presente</span>
                                @else
                                    <span class="badge text-bg-danger">mancante</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p class="text-muted small">
                Puoi rieseguire l'import dopo aver aggiornato i CSV. Gli importer aggiornano/collegano
                i dati esistenti senza creare duplicati.
            </p>

            <form method="POST"
                  action="{{ route('admin.imports.football-data-co-uk.import', ['season' => $selectedSeason]) }}"
                  id="seasonImportForm">
                @csrf
                <button type="submit" id="seasonImportBtn" class="btn btn-outline-primary" @disabled(!$allCoreFilesPresent)>
                    Reimporta stagione {{ $seasonOptions[$selectedSeason] }}
                </button>
                @unless ($allCoreFilesPresent)
                    <span class="text-danger small ms-2">Carica tutti i 5 file core prima di poter importare.</span>
                @endunless
            </form>
        </div>
    </div>

    <script>
        (function () {
            const uploadForm = document.getElementById('uploadForm');
            const uploadBtn  = document.getElementById('uploadBtn');
            const uploadSeasonLabel = @json($seasonOptions[$selectedSeason]);

            if (uploadForm) {
                uploadForm.addEventListener('submit', function (e) {
                    const confirmed = confirm(
                        'Caricare il file per la stagione ' + uploadSeasonLabel + '?\n' +
                        'Se la directory risulta completa, i dati verranno importati automaticamente ' +
                        'e l\'operazione può richiedere alcuni minuti.'
                    );
                    if (!confirmed) {
                        e.preventDefault();
                        return;
                    }
                    uploadBtn.disabled = true;
                    uploadBtn.textContent = 'Caricamento e importazione in corso...';
                });
            }
        })();
    </script>

    <script>
        (function () {
            const seasonImportForm = document.getElementById('seasonImportForm');
            const seasonImportBtn  = document.getElementById('seasonImportBtn');
            const seasonImportLabel = @json($seasonOptions[$selectedSeason]);

            if (seasonImportForm) {
                seasonImportForm.addEventListener('submit', function (e) {
                    const confirmed = confirm(
                        'Importare/reimportare i dati della stagione ' + seasonImportLabel + '?\n' +
                        'L\'operazione può richiedere alcuni minuti.'
                    );
                    if (!confirmed) {
                        e.preventDefault();
                        return;
                    }
                    seasonImportBtn.disabled = true;
                    seasonImportBtn.textContent = 'Importazione in corso...';
                });
            }
        })();
    </script>

    <script>
        (function () {
            const dropzone   = document.getElementById('dropzone');
            const input      = document.getElementById('fileInput');
            const browseBtn  = document.getElementById('browseBtn');
            const fileInfo   = document.getElementById('fileInfo');
            const seasonLbl  = document.getElementById('targetSeasonLabel');

            function formatSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                const kb = bytes / 1024;
                if (kb < 1024) return kb.toFixed(1) + ' KB';
                return (kb / 1024).toFixed(1) + ' MB';
            }

            function showFile(file) {
                if (!file) {
                    fileInfo.style.display = 'none';
                    return;
                }
                fileInfo.innerHTML =
                    '<strong>File:</strong> ' + file.name + '<br>' +
                    '<strong>Dimensione:</strong> ' + formatSize(file.size) + '<br>' +
                    '<strong>Stagione destinazione:</strong> ' + (seasonLbl ? seasonLbl.textContent : '');
                fileInfo.style.display = 'block';
            }

            browseBtn.addEventListener('click', () => input.click());
            input.addEventListener('change', () => showFile(input.files[0]));

            ['dragenter', 'dragover'].forEach((evt) => {
                dropzone.addEventListener(evt, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('border-primary');
                });
            });

            ['dragleave', 'drop'].forEach((evt) => {
                dropzone.addEventListener(evt, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('border-primary');
                });
            });

            dropzone.addEventListener('drop', (e) => {
                const dt = e.dataTransfer;
                if (dt && dt.files && dt.files.length) {
                    input.files = dt.files;
                    showFile(input.files[0]);
                }
            });
        })();
    </script>
@endsection
