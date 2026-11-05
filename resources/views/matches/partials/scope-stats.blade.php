{{-- Descriptive blocks for ONE scope ($sc from MatchController::scopeData): Confronto pre-match, Forma, Split, Trend. --}}
{{-- D. Confronto pre-match --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Confronto pre-match <span class="text-muted small fw-normal">(stagione, prima del match{{ ($sc['key'] === 'competition' && ($uefaPhaseLabel ?? null)) ? ', ' . $uefaPhaseLabel : '' }})</span> <span class="badge text-bg-light border ms-1">{{ $sc['label'] }}</span></h2>
    <p class="text-muted small" data-testid="season-sample-note-{{ $sc['key'] }}">
        @if($sc['key'] === 'total')
        Totale: tutte le competizioni presenti nel database, stagione {{ $match->season->name }}, solo partite concluse prima del calcio d'inizio.
        @elseif($uefaPhaseLabel ?? null)
        Solo {{ $match->competition->name }} {{ $match->season->name }}, <strong>{{ $uefaPhaseLabel }}</strong> (stessa fase di questa partita): le altre fasi sono escluse perché non confrontabili.
        @else
        Solo {{ $match->competition->name }} {{ $match->season->name }}, tutte le fasi disputate (comprese eventuali qualificazioni): campioni di squadre con percorsi diversi non sono equivalenti.
        @endif
        {{ $match->homeTeam->name }}: {{ $sampleTxt($sc['sample']['season']['home']) }} · {{ $match->awayTeam->name }}: {{ $sampleTxt($sc['sample']['season']['away']) }}.
        Vale anche per Split casa / trasferta e Trend pre-match.
    </p>
    @if($sc['key'] === 'total')
        @foreach(['home' => $match->homeTeam->name, 'away' => $match->awayTeam->name] as $cs => $cn)
        @unless($sc['covered'][$cs])
        <p class="text-muted small mb-1" data-testid="coverage-note-{{ $cs }}"><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Copertura parziale</span> {{ $cn }}: il campionato nazionale non è presente nel database; «Totale» indica tutte le partite disponibili, non necessariamente tutte quelle disputate.</p>
        @endunless
        @endforeach
    @endif
    @php $hs = $sc['season']['home']['summary']; $as = $sc['season']['away']['summary']; @endphp
    @if($hs['matches_played'] === 0 && $as['matches_played'] === 0)
    <p class="text-muted">Dati stagionali precedenti non disponibili.</p>
    @else
    @php $ht = $sc['season']['home']['technical']; $at = $sc['season']['away']['technical']; @endphp
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 text-center align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-start ps-3">&nbsp;</th>
                        <th>{{ $match->homeTeam->name }}</th>
                        <th>{{ $match->awayTeam->name }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="text-start ps-3 text-muted">Partite giocate</td><td>{{ $hs['matches_played'] }}</td><td>{{ $as['matches_played'] }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">V / N / P</td><td>{{ $hs['wins'] }} / {{ $hs['draws'] }} / {{ $hs['losses'] }}</td><td>{{ $as['wins'] }} / {{ $as['draws'] }} / {{ $as['losses'] }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Punti</td><td>{{ $hs['points'] }}</td><td>{{ $as['points'] }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">GF / GS</td><td>{{ $hs['goals_for'] }} / {{ $hs['goals_against'] }}</td><td>{{ $as['goals_for'] }} / {{ $as['goals_against'] }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Differenza reti</td><td>{{ $hs['goal_difference'] >= 0 ? '+' : '' }}{{ $hs['goal_difference'] }}</td><td>{{ $as['goal_difference'] >= 0 ? '+' : '' }}{{ $as['goal_difference'] }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media GF</td><td>{{ $avg($hs['avg_goals_for']) }}</td><td>{{ $avg($as['avg_goals_for']) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media GS</td><td>{{ $avg($hs['avg_goals_against']) }}</td><td>{{ $avg($as['avg_goals_against']) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media gol totali</td><td>{{ $avg($hs['avg_total_goals']) }}</td><td>{{ $avg($as['avg_total_goals']) }}</td></tr>
                </tbody>
            </table>
        </div>
        <details class="border-top" data-testid="confronto-technical">
            <summary class="small text-muted px-3 py-2">Dettaglio tecnico (tiri, corner, cartellini)</summary>
            <div class="table-responsive"><table class="table table-sm mb-0 text-center align-middle"><tbody>
                    <tr><td class="text-start ps-3 text-muted">Media tiri fatti</td><td>{{ $avg($ht['avg_shots_for'] ?? null) }}</td><td>{{ $avg($at['avg_shots_for'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media tiri subiti</td><td>{{ $avg($ht['avg_shots_against'] ?? null) }}</td><td>{{ $avg($at['avg_shots_against'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media tiri in porta fatti</td><td>{{ $avg($ht['avg_shots_on_target_for'] ?? null) }}</td><td>{{ $avg($at['avg_shots_on_target_for'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media tiri in porta subiti</td><td>{{ $avg($ht['avg_shots_on_target_against'] ?? null) }}</td><td>{{ $avg($at['avg_shots_on_target_against'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media corner fatti</td><td>{{ $avg($ht['avg_corners_for'] ?? null) }}</td><td>{{ $avg($at['avg_corners_for'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media corner subiti</td><td>{{ $avg($ht['avg_corners_against'] ?? null) }}</td><td>{{ $avg($at['avg_corners_against'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media cartellini gialli</td><td>{{ $avg($ht['avg_yellow_cards'] ?? null) }}</td><td>{{ $avg($at['avg_yellow_cards'] ?? null) }}</td></tr>
                    <tr><td class="text-start ps-3 text-muted">Media cartellini rossi</td><td>{{ $avg($ht['avg_red_cards'] ?? null) }}</td><td>{{ $avg($at['avg_red_cards'] ?? null) }}</td></tr>
                </tbody>
            </table>
        </div>
        </details>
    </div>
    @endif
</div>

{{-- E. Forma prima del match --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Forma prima del match <span class="badge text-bg-light border ms-1">{{ $sc['label'] }}</span></h2>
    <div class="row g-3">
        @foreach([
            ['label' => $match->homeTeam->name, 'last5' => $sc['form']['home']['last5'], 'last10' => $sc['form']['home']['last10'], 'side' => 'home'],
            ['label' => $match->awayTeam->name, 'last5' => $sc['form']['away']['last5'], 'last10' => $sc['form']['away']['last10'], 'side' => 'away'],
        ] as $block)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small mb-2">{{ $block['label'] }} <span class="text-muted">(ultime 5)</span></div>
                    @if(empty($block['last5']['form']))
                        <span class="text-muted">Dati precedenti non disponibili</span>
                    @else
                        @foreach($block['last5']['form'] as $i => $r)
                            <a class="badge match-badge bg-{{ $badgeClass($r) }} me-1" {{ $badgePop($sc['tooltips'][$block['side']]['last5'][$i] ?? null) }}>{{ $r }}</a>
                        @endforeach
                    @endif
                    <div class="text-muted small mt-2 mb-1">Ultime 10</div>
                    @if(empty($block['last10']['form']))
                        <span class="text-muted">Dati precedenti non disponibili</span>
                    @else
                        @foreach($block['last10']['form'] as $i => $r)
                            <a class="badge match-badge bg-{{ $badgeClass($r) }} me-1" {{ $badgePop($sc['tooltips'][$block['side']]['last10'][$i] ?? null) }}>{{ $r }}</a>
                        @endforeach
                    @endif
                    @php $f10 = $sc['sample']['form10'][$block['side']] ?? null; @endphp
                    @if($f10 !== null)
                    <div class="text-muted small mt-2" data-testid="form-sample-note-{{ $block['side'] }}">
                        Campione: {{ $sampleTxt($f10) }}@if($f10['n'] < 10) — <strong>campione ridotto</strong>: meno di 10 partite disponibili in questo ambito @endif
                    </div>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>


{{-- Ultimi 5 incontri of this scope (Totale: any competition; Competizione: same competition/season, UEFA: same phase) --}}
<div class="mt-4" data-testid="recent-matches-{{ $sc['key'] }}">
    <h2 class="fs-5 fw-semibold mb-1">Ultimi 5 incontri <span class="badge text-bg-light border ms-1">{{ $sc['label'] }}</span></h2>
    <p class="text-muted small">
        @if($sc['key'] === 'total')
            Tutte le competizioni presenti nel database.
        @else
            Solo {{ $match->competition->name }} {{ $match->season->name }}@if($uefaPhaseLabel ?? null), {{ $uefaPhaseLabel }}@endif.
        @endif
        Ogni incontro riporta la competizione e, per le coppe UEFA, la fase. Solo risultati definitivi precedenti al calcio d'inizio.
    </p>
    <div class="row g-3">
        @foreach([['name' => $match->homeTeam->name, 'rows' => $sc['recent']['home'], 'side' => 'home'], ['name' => $match->awayTeam->name, 'rows' => $sc['recent']['away'], 'side' => 'away']] as $ub)
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small mb-2">{{ $ub['name'] }}
                        <span class="text-muted">({{ count($ub['rows']) }} {{ count($ub['rows']) === 1 ? 'incontro' : 'incontri' }}@if(count($ub['rows']) > 0), dal {{ $ub['rows'][0]['date']->format('d/m/Y') }} al {{ $ub['rows'][count($ub['rows']) - 1]['date']->format('d/m/Y') }}@endif)</span>
                    </div>
                    @if(count($ub['rows']) === 0)
                        <span class="text-muted">Nessun incontro precedente nei dati.</span>
                    @else
                    <table class="table table-sm mb-0 small align-middle" data-testid="recent-{{ $sc['key'] }}-{{ $ub['side'] }}">
                        <tbody>
                        @foreach($ub['rows'] as $u)
                            <tr>
                                <td class="text-nowrap text-muted">{{ $u['date']->format('d/m/Y') }}</td>
                                <td><span class="badge text-bg-light border">{{ $u['competition'] ?? '?' }}</span>@if($u['phase_label']) <span class="text-muted">{{ $u['phase_label'] }}</span>@endif</td>
                                <td>{{ $u['venue'] === 'C' ? 'vs' : '@' }} {{ $u['opponent'] }}</td>
                                <td class="text-nowrap">{{ $u['score'] }}</td>
                                <td><a class="badge match-badge bg-{{ $badgeClass($u['result']) }}" {{ $badgePop($u) }}>{{ $u['result'] }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- E. Split casa/trasferta --}}
<div class="mt-4">
    <h2 class="fs-5 fw-semibold mb-3">Split casa / trasferta <span class="text-muted small fw-normal">(prima del match{{ ($sc['key'] === 'competition' && ($uefaPhaseLabel ?? null)) ? ', ' . $uefaPhaseLabel : '' }})</span> <span class="badge text-bg-light border ms-1">{{ $sc['label'] }}</span></h2>
    <div class="row g-3">
        @foreach([
            ['label' => $match->homeTeam->name . ' — in casa', 'a' => $sc['split']['home']],
            ['label' => $match->awayTeam->name . ' — in trasferta', 'a' => $sc['split']['away']],
        ] as $block)
        @php $bs = $block['a']['summary']; $bt = $block['a']['technical']; $bmt = $block['a']['market_trends']; @endphp
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body p-3">
                    <div class="text-muted small mb-2">{{ $block['label'] }} <span class="text-muted">({{ $bs['matches_played'] }} PG)</span></div>
                    @if($bs['matches_played'] === 0)
                        <p class="text-muted mb-0">Dati precedenti non disponibili.</p>
                    @else
                        <div class="small">V {{ $bs['wins'] }} · N {{ $bs['draws'] }} · P {{ $bs['losses'] }}</div>
                        <div class="small">GF {{ $bs['goals_for'] }} · GS {{ $bs['goals_against'] }}</div>
                        <div class="small text-muted">Media GF {{ $avg($bs['avg_goals_for']) }} · Media GS {{ $avg($bs['avg_goals_against']) }}</div>
                        <div class="small text-muted">Media tiri fatti {{ $avg($bt['avg_shots_for'] ?? null) }} · subiti {{ $avg($bt['avg_shots_against'] ?? null) }}</div>
                        <div class="small mt-1">
                            GG {{ $pct($bmt['btts']['yes']['percentage']) }}
                            <span class="text-muted">({{ $bmt['btts']['yes']['count'] }}/{{ $bmt['btts']['yes']['total'] }})</span>
                            · Over 2.5 {{ $pct($bmt['full_time_goals']['over_2_5']['percentage']) }}
                            <span class="text-muted">({{ $bmt['full_time_goals']['over_2_5']['count'] }}/{{ $bmt['full_time_goals']['over_2_5']['total'] }})</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- F. Trend pre-match (ambiguo pubblico/tecnico — vedi report Step 1C, mantenuto qui perché stesso dato "a mercato" di Split) --}}
<details class="mt-4 mb-4" data-testid="trend-details">
    <summary class="fs-5 fw-semibold mb-3">Trend pre-match <span class="text-muted small fw-normal">(stagione, prima del match{{ ($sc['key'] === 'competition' && ($uefaPhaseLabel ?? null)) ? ', ' . $uefaPhaseLabel : '' }})</span> <span class="badge text-bg-light border ms-1">{{ $sc['label'] }}</span></summary>
    <div class="row g-3">
        @foreach([['label' => $match->homeTeam->name, 'a' => $sc['season']['home']], ['label' => $match->awayTeam->name, 'a' => $sc['season']['away']]] as $block)
        @php $mt = $block['a']['market_trends']; @endphp
        <div class="col-md-6">
            <div class="text-muted small mb-2">{{ $block['label'] }}</div>
            @if($mt['coverage']['full_time'] === 0)
            <p class="text-muted">Dati stagionali precedenti non disponibili.</p>
            @else
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Mercato</th>
                                <th class="text-center pe-3">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="ps-3">GG</td>
                                <td class="text-center pe-3">
                                    {{ $pct($mt['btts']['yes']['percentage']) }}
                                    <span class="text-muted small">({{ $mt['btts']['yes']['count'] }}/{{ $mt['btts']['yes']['total'] }})</span>
                                </td>
                            </tr>
                            <tr>
                                <td class="ps-3">NG</td>
                                <td class="text-center pe-3">
                                    {{ $pct($mt['btts']['no']['percentage']) }}
                                    <span class="text-muted small">({{ $mt['btts']['no']['count'] }}/{{ $mt['btts']['no']['total'] }})</span>
                                </td>
                            </tr>
                            @foreach(['1_5' => 'Over 1.5', '2_5' => 'Over 2.5', '3_5' => 'Over 3.5'] as $key => $label)
                            <tr>
                                <td class="ps-3">{{ $label }}</td>
                                <td class="text-center pe-3">
                                    {{ $pct($mt['full_time_goals']['over_' . $key]['percentage']) }}
                                    <span class="text-muted small">({{ $mt['full_time_goals']['over_' . $key]['count'] }}/{{ $mt['full_time_goals']['over_' . $key]['total'] }})</span>
                                </td>
                            </tr>
                            @endforeach
                            @foreach(['0_5' => 'Over 0.5 HT', '1_5' => 'Over 1.5 HT'] as $key => $label)
                            <tr>
                                <td class="ps-3">{{ $label }}</td>
                                <td class="text-center pe-3">
                                    {{ $pct($mt['half_time_goals']['over_' . $key . '_ht']['percentage']) }}
                                    <span class="text-muted small">({{ $mt['half_time_goals']['over_' . $key . '_ht']['count'] }}/{{ $mt['half_time_goals']['over_' . $key . '_ht']['total'] }})</span>
                                </td>
                            </tr>
                            @endforeach
                            <tr>
                                <td class="ps-3">GG HT</td>
                                <td class="text-center pe-3">
                                    {{ $pct($mt['btts_half_time']['yes']['percentage']) }}
                                    <span class="text-muted small">({{ $mt['btts_half_time']['yes']['count'] }}/{{ $mt['btts_half_time']['yes']['total'] }})</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
        </div>
        @endforeach
    </div>
</details>
