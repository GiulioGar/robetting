# Checkpoint — esperimento Recent (Team History vs RECENT_ALL) — 2026-11-05

Ultimo commit: `94c054d`. Origin/main è fermo a `f85286b`: **14 commit locali non pushati** (vedi sotto).
Non committati (volutamente): `tools/models/latent_strength_current.json` e gli script di ricerca `tools/scripts/goal_step38..41_*.py`.

## Stato

| Componente | Stato |
|---|---|
| Candidate47 ufficiale | Operativo, invariato (157 prediction ufficiali) |
| RECENT_ALL (`PreMatchFeatureAggregator::RECENT_WINDOW_ALL`) | Implementato, opt-in, mai di default |
| Confronto prospettico | 34 partite UEFA del 05/11/2026, 68 prediction `purpose = recent_exp` |
| Report Brier / log loss (`robetting:recent-experiment-report`) | Implementato, read-only |
| Partite valutabili | 0 (tutte ancora `scheduled` al momento del checkpoint) |

## Cosa è stato costruito dopo `f85286b`

- **Statistiche prematch (pagina Match):** note su campione e ambito; statistiche UEFA per fase (`UefaPhase`: qualificazioni / League Stage / eliminazione); selettore **Totale | Competizione** (CSS puro) con partial `matches/partials/scope-stats`; lista "Ultimi 5 incontri" per ambito; badge W/D/L con tooltip JS senza librerie; pagina riordinata (pronostico → GOAL → quote → confronto squadre → approfondimenti collassabili); nota esplicita quando si usa il fallback FULL59.
- **Structural UEFA:** snapshot esteso `structural_strength_champions_extended.json` a 165 squadre (mapping verificato in `p16_team_club_mapping.tsv`). **Manca solo Torreense** (id 576): non mappata (ricerca Transfermarkt non univoca) → la partita 12044 ha `MODEL_UNAVAILABLE`.
- **Campionati results-only:** bucket `results_only_competitions` in `config/api-football.php` (id lega => slug). Importato Hungary NB I (271, stagione 2026, 3 chiamate API, 198 partite). Protezioni (via sottoquery su slug, senza ID hardcoded): `TeamEloCalculator` legacy e `PreMatchFeatureAggregator::loadScheduleHistory`. `MatchDataScope`/Team History invariati. Verificato: Elo, feature Candidate47/FULL59, prediction e quote identici prima/dopo l'import. **Aperto:** i metadati stagione NB I dell'API sono incoerenti (start 2286-11-20, end 1970-01-01) — da gestire a parte.
- **Esperimento Recent:** `RecentExperimentCaptureService` + `robetting:capture-recent-experiment` (agganciato allo startup di `robetting:serve`, finestra 72 h, idempotente). Stesso artifact/snapshot/timestamp per le due varianti; cambia solo la finestra Recent; nessuna scrittura a/dopo il kickoff; salva divario Structural, dimensione finestra e copertura tiri in `features_json._experiment`. `model_key`: `candidate47_recent_th` / `candidate47_recent_all`. Primo run reale: 34 coppie, 1 esclusa (12044).
- **Report:** `RecentExperimentReportService` + `robetting:recent-experiment-report`: solo coppie coerenti, partite `finished` con `definitive_at` e FT dei 90'; Brier multiclasse e log loss riusando `PredictionEvaluationService::computeMetrics()` (ora pubblico); differenza = ALL − TH (positivo = ALL peggiora); dettaglio per partita; descrittivo (correlazione con divario Structural e copertura tiri, solo con ≥3 partite).

## Evidenze già raccolte

- 12031 (Milan–Ferencvárosi): Candidate47 con Team History 69,2/18,7/12,0; con RECENT_ALL 58,6/22,2/19,3 (λ 2,30/0,92 → 2,04/1,14). Cambiano solo le 6 feature Recent del Ferencvárosi (Team History = 4 partite del 2024-26; ALL = 10 partite, ma statistiche tiri solo su 3).
- Su partite dei 5 campionati le due finestre coincidono (nessuna differenza).
- Correzione per la forza degli avversari (E10): non applicabile oggi — Elo non confrontabile tra campionati (avversari ungheresi a ~1500, Elo scollegato), coefficienti E10 calibrati solo sui 5 campionati e solo su differenze (non per attacco/difesa separati). Nessun coefficiente improvvisato.

## Decisioni fissate

- La forma complessiva deve considerare tutte le partite ufficiali disponibili.
- La forza degli avversari va valutata nel contesto del loro campionato.
- Nessun coefficiente Structural arbitrario.
- Candidate47 resta ufficiale finché non ci sono evidenze sufficienti.
- ROBETTING deve estendersi progressivamente a molti campionati (bucket config, niente elenchi hardcoded).

## Ripresa — venerdì 6 novembre

1. Aggiornare i risultati delle partite UEFA del 05/11 con il sistema esistente (catch-up/refresh risultati).
2. `php artisan robetting:recent-experiment-report`.
3. Confrontare Team History vs RECENT_ALL: Brier, log loss, differenze per partita.
4. Analizzare il divario Structural (e la copertura tiri) rispetto alle differenze tra varianti.
5. Decidere il prossimo intervento sul modello in base ai risultati, senza modificare prematuramente Candidate47.

Nessuna nuova implementazione prima di leggere quel report.

## Commit locali non pushati (dopo `f85286b`, dal più vecchio)

`daf6b70` fallback FULL59 e note campione · `21cb9c5`/`4898ab9`/`6b65516`/`76bf280` Structural UEFA (165 squadre) · `a4cd39b` statistiche UEFA per fase · `6f17d87` riordino pagina · `aa4c87c` Totale/Competizione · `c9e185d` ultimi 5 per ambito · `05ca916` results-only + NB I · `de5ed4e`/`59ca6fd` tooltip badge · `7c90e0c` cattura parallela recent_exp · `94c054d` report.

## Problemi aperti

- Torreense senza Structural (12044 esclusa).
- Metadati stagione NB I incoerenti.
- Il refresh periodico risultati copre anche le partite results-only già importate (poche chiamate batch).
- `latent_strength_current.json` modificato e script `goal_step38..41` non tracciati: decidere se committare/ignorare.
