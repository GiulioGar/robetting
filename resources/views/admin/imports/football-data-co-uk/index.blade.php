@extends('layouts.app')

@section('title', 'Gestione dati Football-Data.co.uk')

@section('content')
    <h1 class="h3 mb-4">Gestione dati Football-Data.co.uk</h1>

    <p class="text-muted">
        Carica qui i file raw (CSV singoli o <code>data.zip</code>) in
        <code>storage/app/imports/football-data-co-uk/&#123;stagione&#125;/</code>.
        Questa pagina <strong>non</strong> importa nulla nel database — serve solo a preparare i file
        per un import successivo.
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
            <div class="card-header bg-success-subtle">Report upload</div>
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

    <form method="POST" action="{{ route('admin.imports.football-data-co-uk.store') }}" enctype="multipart/form-data" class="mb-5">
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

        <button type="submit" class="btn btn-primary mt-2">Carica</button>
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
