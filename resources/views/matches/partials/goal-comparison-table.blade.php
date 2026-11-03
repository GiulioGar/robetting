{{-- GOAL comparison table: 4 always-visible columns (Selezione, Robetting, Migliore quota, EV teorico) so it reads at 375px
     without horizontal scroll. $detail adds consensus / fair odd / bookmaker count / freshness under the selection label.
     Diagnostic only: no colours or labels that suggest a validated opportunity. --}}
<table class="table table-sm align-middle mb-0 small text-end">
    <thead class="table-light">
        <tr>
            <th class="text-start ps-2 ps-sm-3">Selezione</th>
            <th>Robetting</th>
            <th>Migliore quota</th>
            <th class="pe-2 pe-sm-3" title="Probabilità Robetting × migliore quota − 1. Indicatore teorico, non un consiglio.">EV teorico</th>
        </tr>
    </thead>
    <tbody>
    @foreach($sections as $secMarket => $secTitle)
        @php $secRows = array_values(array_filter($rows, fn($e) => $e['market'] === $secMarket)); @endphp
        @if(count($secRows) > 0)
        <tr class="table-light"><th colspan="4" class="text-start ps-2 ps-sm-3 fw-semibold text-muted">{{ $secTitle }}</th></tr>
        @foreach($secRows as $e)
        <tr data-market="{{ $e['market'] }}" data-line="{{ $e['line'] === null ? '' : number_format($e['line'], 1) }}" data-selection="{{ $e['selection'] }}">
            <td class="text-start ps-2 ps-sm-3">{{ $label($e) }}
                @if($detail)
                <div class="text-muted lh-sm" style="font-size:.85em" data-detail>
                    Consenso {{ $pct($e['consensus_probability'] * 100) }}
                    · Quota equa {{ $e['model_fair_odd'] !== null ? number_format($e['model_fair_odd'], 2) : '–' }}
                    · {{ $e['bookmaker_count'] }} bookmaker
                    · quote delle {{ $rome($e['latest_fetched_at'])->format('H:i') }}
                </div>
                @endif
            </td>
            <td>{{ $pct($e['model_probability'] * 100) }}</td>
            <td>{{ number_format($e['best_odd'], 2) }}
                <span class="text-muted d-block">{{ $e['best_bookmakers'][0]['name'] }}@if(count($e['best_bookmakers']) > 1) +{{ count($e['best_bookmakers']) - 1 }}@endif</span></td>
            <td class="pe-2 pe-sm-3">{{ ($e['theoretical_ev'] >= 0 ? '+' : '−') . number_format(abs($e['theoretical_ev']) * 100, 1) }}%</td>
        </tr>
        @endforeach
        @endif
    @endforeach
    </tbody>
</table>
