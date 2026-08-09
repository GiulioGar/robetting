@extends('layouts.app')

@section('title', 'Gestisci fasce · ' . $competition->name . ' ' . $season->name)

@section('content')

<div class="mb-3">
    <a href="{{ route('competitions.seasons.show', ['competition' => $competition->slug, 'season' => $season->year_start]) }}"
       class="text-muted small">&larr; Competition Overview</a>
</div>

<h1 class="fs-4 mb-1">Fasce classifica</h1>
<p class="text-muted small mb-4">{{ $competition->name }} &mdash; {{ $season->name }}
    @if($teamCount > 0)
    &mdash; {{ $teamCount }} squadre
    @endif
</p>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

@if($errors->any())
<div class="alert alert-danger py-2">
    <ul class="mb-0 small">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

{{-- Existing zones --}}
@if($zones->isEmpty())
<p class="text-muted">Nessuna fascia configurata per questa stagione.</p>
@else
<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Ord.</th>
                    <th>Posizioni</th>
                    <th>Tipo</th>
                    <th>Label</th>
                    <th>CSS class</th>
                    <th>Colore</th>
                    <th>Status</th>
                    <th class="pe-3"></th>
                </tr>
            </thead>
            <tbody>
            @foreach($zones as $z)
            <tr>
                <td class="ps-3 text-muted small">{{ $z->sort_order }}</td>
                <td>{{ $z->from_position }}–{{ $z->to_position }}</td>
                <td><code class="small">{{ $z->type }}</code></td>
                <td>{{ $z->label }}</td>
                <td><code class="small">{{ $z->css_class ?? '—' }}</code></td>
                <td>
                    @if($z->color)
                    <span style="display:inline-block;width:14px;height:14px;background:{{ $z->color }};border-radius:2px;vertical-align:middle"></span>
                    <span class="small ms-1">{{ $z->color }}</span>
                    @else
                    <span class="text-muted">—</span>
                    @endif
                </td>
                <td>
                    <span class="badge {{ $z->status === 'confirmed' ? 'bg-success' : 'bg-warning text-dark' }} small">
                        {{ $z->status }}
                    </span>
                </td>
                <td class="pe-3 text-end">
                    {{-- Edit (details toggle, no JS required) --}}
                    <details class="d-inline">
                        <summary class="btn btn-sm btn-outline-secondary py-0 px-2">Modifica</summary>
                        <div class="mt-2 p-3 border rounded bg-white">
                            <form method="POST"
                                  action="{{ route('competitions.seasons.zones.update', ['competition' => $competition->slug, 'season' => $season->year_start, 'zone' => $z->id]) }}">
                                @csrf
                                @method('PATCH')
                                <div class="row g-2">
                                    <div class="col-auto">
                                        <label class="form-label small mb-0">Dal</label>
                                        <input type="number" name="from_position" value="{{ $z->from_position }}"
                                               min="1" class="form-control form-control-sm" style="width:70px">
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small mb-0">Al</label>
                                        <input type="number" name="to_position" value="{{ $z->to_position }}"
                                               min="1" class="form-control form-control-sm" style="width:70px">
                                    </div>
                                    <div class="col">
                                        <label class="form-label small mb-0">Tipo</label>
                                        <input type="text" name="type" value="{{ $z->type }}"
                                               class="form-control form-control-sm">
                                    </div>
                                    <div class="col">
                                        <label class="form-label small mb-0">Label</label>
                                        <input type="text" name="label" value="{{ $z->label }}"
                                               class="form-control form-control-sm">
                                    </div>
                                    <div class="col">
                                        <label class="form-label small mb-0">CSS class</label>
                                        <input type="text" name="css_class" value="{{ $z->css_class }}"
                                               class="form-control form-control-sm">
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small mb-0">Colore</label>
                                        <input type="text" name="color" value="{{ $z->color }}"
                                               class="form-control form-control-sm" style="width:90px">
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small mb-0">Status</label>
                                        <select name="status" class="form-select form-select-sm">
                                            <option value="confirmed" @selected($z->status === 'confirmed')>confirmed</option>
                                            <option value="provisional" @selected($z->status === 'provisional')>provisional</option>
                                        </select>
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small mb-0">Ord.</label>
                                        <input type="number" name="sort_order" value="{{ $z->sort_order }}"
                                               min="0" class="form-control form-control-sm" style="width:60px">
                                    </div>
                                    <div class="col-auto d-flex align-items-end">
                                        <button type="submit" class="btn btn-sm btn-primary">Salva</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </details>

                    {{-- Delete --}}
                    <form method="POST" class="d-inline ms-1"
                          action="{{ route('competitions.seasons.zones.destroy', ['competition' => $competition->slug, 'season' => $season->year_start, 'zone' => $z->id]) }}"
                          onsubmit="return confirm('Eliminare la fascia «{{ $z->label }}»?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2">Elimina</button>
                    </form>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- Add new zone --}}
<h2 class="fs-6 fw-semibold mb-2">Aggiungi fascia</h2>
<div class="card">
    <div class="card-body">
        <form method="POST"
              action="{{ route('competitions.seasons.zones.store', ['competition' => $competition->slug, 'season' => $season->year_start]) }}">
            @csrf
            <div class="row g-2">
                <div class="col-auto">
                    <label class="form-label small mb-0">Dal <span class="text-danger">*</span></label>
                    <input type="number" name="from_position" value="{{ old('from_position', 1) }}"
                           min="1" class="form-control form-control-sm" style="width:70px">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Al <span class="text-danger">*</span></label>
                    <input type="number" name="to_position" value="{{ old('to_position', 1) }}"
                           min="1" class="form-control form-control-sm" style="width:70px">
                </div>
                <div class="col">
                    <label class="form-label small mb-0">Tipo <span class="text-danger">*</span></label>
                    <input type="text" name="type" value="{{ old('type') }}" placeholder="es. champions_league"
                           class="form-control form-control-sm">
                </div>
                <div class="col">
                    <label class="form-label small mb-0">Label <span class="text-danger">*</span></label>
                    <input type="text" name="label" value="{{ old('label') }}" placeholder="es. Champions League"
                           class="form-control form-control-sm">
                </div>
                <div class="col">
                    <label class="form-label small mb-0">CSS class</label>
                    <input type="text" name="css_class" value="{{ old('css_class') }}" placeholder="zone-ucl"
                           class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Colore</label>
                    <input type="text" name="color" value="{{ old('color') }}" placeholder="#1d4ed8"
                           class="form-control form-control-sm" style="width:90px">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="confirmed" @selected(old('status') === 'confirmed')>confirmed</option>
                        <option value="provisional" @selected(old('status', 'provisional') === 'provisional')>provisional</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Ord.</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', 0) }}"
                           min="0" class="form-control form-control-sm" style="width:60px">
                </div>
                <div class="col-auto d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-primary">Aggiungi</button>
                </div>
            </div>
        </form>
    </div>
</div>

@endsection
