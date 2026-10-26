# ROBETTING GOAL — Checkpoint della ricerca statistica (2026-10-26)

Documento di ripartenza. Riassume l'analisi offline sui mercati GOAL derivati da Candidate47 Team History. Nessuna modifica ai modelli produttivi o agli artifact; nessun nuovo modello o feature deciso.

> **AVVERTENZA — il TEST 2025/26 è già stato esplorato.** Le distribuzioni, le calibrazioni Over/Under e le baseline degli Step 4-6 sono state guardate sul TEST. Il TEST 2025/26 **non deve diventare il dataset di selezione** per nuovi modelli, feature o correzioni: ogni scelta futura va fatta su dati di TRAIN/validazione temporale distinti, oppure verificata su dati prospettici (stagione 2026/27).

## 1. Obiettivo e mercati

Capire quali mercati GOAL si possono derivare dalla distribuzione di Candidate47 e quanto sono calibrati: Over/Under (linee 0.5-4.5), BTTS, Team Goals, Multigol (intervalli inclusivi), risultati esatti. Mercati su tempi, supplementari, marcatori, corner/cartellini richiedono dati o modelli aggiuntivi (non studiati).

## 2. Modello

- Artifact `tools/models/prediction_engine_candidate47_team_history_recent.json`: `model_version` 1.1.0, 47 feature, `trained_at` 2026-10-20T15:43:24Z, addestrato su **tutte le 3511 partite (TRAIN + TEST)**. Non è utilizzabile per prediction out-of-sample sul TEST.
- Due `PoissonRegressor(alpha=1.0, max_iter=500)` indipendenti (home e away) sulle stesse 47 feature standardizzate. `lambda_home`/`lambda_away` = `exp(η)` = medie marginali dei gol (nessun limite superiore).
- Dipendenza: Bivariate Poisson (Karlis-Ntzoufras), **`lambda3 = 0.15` fisso e globale**, non stimato dalla verosimiglianza congiunta (calibrato sull'1X2/pareggi, audit P8U). `lambda1 = λH − λ3`, `lambda2 = λA − λ3`; `X = X1+X3`, `Y = X2+X3`. Marginali Poisson(λH), Poisson(λA); Cov = λ3.
- Nel codice la matrice è 0..10 per squadra (`MAX_GOALS = 10`), usata solo per l'1X2 (rinormalizzato); la matrice non è esposta né salvata.
- Totale gol `T = X1 + X2 + 2·X3`: `E[T] = λH + λA`, `Var[T] = λH + λA + 2·λ3` per partita; `P(T=t) = Σ_k Poisson(k; λ3)·Poisson(t−2k; λ1+λ2)` (esatta, senza troncamento).
- Derivabilità: Over/Under e Multigol dal totale; BTTS in forma chiusa `1 − e^(−λH) − e^(−λA) + e^(−(λH+λA−λ3))`; Team Goals e clean sheet dalle marginali (indipendenti da λ3); risultati esatti dalle celle.

## 3. Dati e benchmark di riferimento

- Dataset `tools/datasets/dataset_core_v1_team_history_2024_2025.csv`, 3511 righe. Split temporale del benchmark: **TRAIN `season_id` 28-32 = 2024/25, 1756 partite; TEST `season_id` 23-27 = 2025/26, 1755 partite**.
- Composizione TEST per `competition_id` (mapping verificato solo per 15 = Serie A): 15: 380, 16: 380, 17: 380, 18: 308, 19: 307. Id 18 = 306 di campionato + 2 gare di spareggio retrocessione; id 19 = 305 di campionato + 2 spareggi, con **una partita di campionato mancante** (squadre 361 e 382 con 33 gare): il DB ne conta 308; ipotesi (non verificata) esclusione per stato `awarded`/`walkover`, coerente con il builder (solo `finished`).
- Benchmark 1X2 confermato sul TEST: LogLoss 0.9948, Brier 0.5926, Accuracy 0.5123 (ricalcolo da CSV: 0.994828 / 0.592603 / 0.512251). `lambda3` effettivo 0.15 su tutte le righe (cap mai attivo); `lambda_home` 0.58-5.93, `lambda_away` 0.53-3.20.

## 4. Risultati quantitativi (TEST 2025/26, N = 1755)

### Step 4 — distribuzione dei gol totali
Media osservata 2.7641, prevista 2.8882 (−0.124). Varianza osservata 2.7478; prevista aggregata 3.3348 (= 3.1882 interna + 0.1466 tra le medie), rapporto 0.824. Varianza osservata ≈ media (0.994), prevista ≈ 1.155 × media.

| Gol | % osservata | % attesa | Diff pp |
|---|---|---|---|
| 0 | 6.55 | 6.86 | −0.31 |
| 1 | 16.92 | 17.02 | −0.10 |
| 2 | 23.53 | 22.42 | +1.11 |
| 3 | 23.30 | 20.75 | +2.56 |
| 4 | 15.38 | 15.09 | +0.29 |
| 5+ | 14.30 | 17.86 | −3.56 |

Per lega (media obs/pred; varianza obs/pred): 15: 2.426/2.708; 2.355/3.082 — 16: 2.750/2.927; 2.461/3.288 — 17: 2.695/2.867; 2.275/3.361 — 18: 3.221/3.128; 3.094/3.644 — 19: 2.827/2.849; 3.465/3.261.

### Step 5 — calibrazione Over/Under
| Linea | Osservata % | Prevista % | Diff pp | LogLoss | Brier |
|---|---|---|---|---|---|
| O 0.5 | 93.45 | 93.14 | +0.31 | 0.2374 | 0.0608 |
| O 1.5 | 76.52 | 76.12 | +0.40 | 0.5365 | 0.1768 |
| O 2.5 | 52.99 | 53.70 | −0.70 | 0.6813 | 0.2443 |
| O 3.5 | 29.69 | 32.95 | −3.26 | 0.6025 | 0.2066 |
| O 4.5 | 14.30 | 17.86 | −3.56 | 0.4087 | 0.1225 |

Differenze osservata − prevista per lega (pp), O0.5 / O1.5 / O2.5 / O3.5 / O4.5: id 15: −1.46 / −3.21 / −3.99 / −7.12 / −5.33; id 16: −0.71 / +1.81 / +0.15 / −5.38 / −5.40; id 17: +3.17 / +2.61 / −3.06 / −6.97 / −4.49; id 18: +1.28 / +3.76 / +4.64 / +4.68 / −1.22; id 19: −0.77 / −2.95 / −0.14 / +0.77 / −0.26.
Quantili (351 per gruppo): in Over 2.5 e 3.5 il gruppo con probabilità più alta è sovrastimato (O2.5: 65.3% prevista vs 60.7%; O3.5: 44.7% vs 36.8%, −7.95 pp, ~3 SE) e i gruppi non crescono in modo monotono (SE ~2.5 pp).

### Step 6 — baseline temporale (frequenze TRAIN 2024/25, stesso TEST)
Frequenze TRAIN Over (globale): O0.5 94.25%, O1.5 77.90%, O2.5 53.42%, O3.5 31.89%, O4.5 15.60%. `competition_id` di TRAIN = TEST (nessuna esclusione).

| Linea | LL Cand47 | LL globale | LL per-comp | Brier Cand47 | Brier globale | Brier per-comp | Migliore |
|---|---|---|---|---|---|---|---|
| O 0.5 | 0.2374 | 0.2425 | 0.2442 | 0.0608 | 0.0613 | 0.0614 | Cand47 |
| O 1.5 | 0.5365 | 0.5455 | 0.5475 | 0.1768 | 0.1798 | 0.1804 | Cand47 |
| O 2.5 | 0.6813 | 0.6914 | 0.6858 | 0.2443 | 0.2491 | 0.2463 | Cand47 |
| O 3.5 | 0.6025 | 0.6093 | 0.6000 | 0.2066 | 0.2092 | 0.2052 | per-comp |
| O 4.5 | 0.4087 | 0.4111 | 0.4064 | 0.1225 | 0.1227 | 0.1215 | per-comp |

Candidate47 batte la frequenza globale su tutte le linee (+0.22% … +2.12%); sulle linee 3.5 e 4.5 la baseline per competizione è leggermente migliore (−0.42%/−0.57% LogLoss, −0.67%/−0.75% Brier). Scarti piccoli (0.001-0.01 in assoluto); incertezza campionaria dei punteggi non stimata.

## 5. Feature: utilizzate e disponibili (audit)

Utilizzate (47): forma recente Team History ultime 10 (gol/tiri/tiri in porta fatti-subiti, 12), Elo (3), latent attack/defence (4, solo gol, walk-forward), valore rosa Transfermarkt TOP25 log (3), contesto campionato (9), calendario (8), continuità titolari (8).
Disponibili e non usate: xG (4 feature esportate in `recent_xg_features_2024_2025.csv`, cutoff 120 h; copertura entrambe le squadre 96.9% 2024/25 e 99.0% 2025/26; benchmark xG non incluso in questo checkpoint), corner, possesso, passaggi, parate, falli/cartellini, tiri dentro/fuori area e bloccati, `goals_prevented`; nel dataset 120 feature opponent-adjusted, 18 assenze, 14 età; Availability V1, eventi, formazioni, statistiche giocatore, infortuni. Copertura di tiri nelle feature recenti: 98.1% (2024/25) e 99.9% (2025/26) (proxy dal CSV; copertura grezza di `match_statistics` per stagione/lega non misurata). Strict as-of: finestre `kickoff < target`; per l'xG storico serve cutoff conservativo (arricchimento tardivo, primo avvistamento non ricostruibile).

## 6. Criticità statistiche e anomalie note

- Media prevista dei totali > osservata (−0.124 in termini osservata − prevista). Il modello sovrastima la coda: 5+ gol osservati 14.30% contro 17.86% attesi (−3.56 pp, ~4 SE) e O3.5/O4.5 (−3.3/−3.6 pp), con lo stesso segno in id 15, 16, 17; id 18 opposto (O3.5 +4.7 pp), id 19 vicino a zero.
- Dispersione prevista > osservata (rapporto 0.82; 0.68-0.85 nelle leghe, eccezione id 19 con 1.06). `lambda3` fisso è tarato sull'1X2 e potrebbe compensare effetti locali sui punteggi bassi più che una vera correlazione tra i gol; la varianza del totale dipende da λ3.
- λ estremi (max `E[T]` 6.707; 2 partite con `E[T] > 5`) e `exp(η)` senza cap. Esiti con 7+ gol: 40 (2.3%), massimo 10.
- Stima non congiunta (due Poisson marginali + λ3 esterno), regolarizzazione L2 (`alpha=1`) che può ritirare gli estremi.
- Un solo artifact per cinque leghe e due stagioni; mapping `competition_id` → lega non verificato salvo 15.
- Campione: una stagione di TEST, 307-380 partite per lega; scarti per singola lega/gruppo dell'ordine dell'errore standard (~2-3 pp). Nessuna correzione per confronti multipli. Il TEST è stato già esplorato.

## 7. Decisioni prese

- Nessuna modifica ai modelli produttivi, agli artifact, a λ3 o alle feature.
- Il CSV dei lambda out-of-sample è generato **solo dal fit sul TRAIN** (non dall'artifact finale, addestrato anche sul TEST) e resta locale, non versionato.
- Il confronto con le quote bookmaker per questi mercati non è possibile ora (Odds V1 raccoglie solo 1X2).

## 8. Questioni aperte

- Gli errori sui totali derivano dalla stima dei λ (livello medio, spread tra partite) o dalla forma della distribuzione (marginali Poisson, λ3 fisso)?
- Calibrazione di BTTS, Team Goals, Multigol, risultati esatti e P(0-0)/P(1-1): non analizzata.
- Stabilità di λ3 e dipendenza dal livello di gol; adeguatezza della dipendenza non negativa.
- Trattamento della coda oltre 10 gol e dei λ estremi per i mercati con linee alte.
- Eterogeneità tra leghe/stagioni; effetto di spareggi (id 18/19) e della partita mancante in id 19.
- Valore informativo di xG e delle altre statistiche non usate (benchmark xG non incluso).

## 9. Punto di ripartenza

**Distinguere gli errori nella stima dei lambda dagli errori nella distribuzione**, senza assumere nuove feature o modelli: usando solo i lambda out-of-sample già esportati e gli esiti, verificare (a) se la media di `E[T]` e dei singoli λ è calibrata per classi di lambda (errore di livello/spread), e (b) se, a λ dati, la forma implicata (Poisson marginali + λ3 = 0.15) descrive la dispersione e la coda osservate. Ogni decisione di selezione va presa fuori dal TEST 2025/26 (TRAIN/validazione temporale distinta o dati prospettici 2026/27).

## 10. File e rigenerazione

Script (in `tools/scripts/`, leggono i CSV in `tools/datasets/`, non scrivono nulla):
- `goal_step4_total_goals.py` — distribuzione dei gol totali (osservata vs prevista, esatta).
- `goal_step5_over_under.py` — calibrazione Over/Under 0.5-4.5 e quantili.
- `goal_step6_baseline.py` — baseline di frequenza TRAIN (globale e per competizione).
- `diagnose_candidate47_calibration.py` — calibrazione 1X2 e opzione `--export-test-csv`.

CSV locale (non versionato; escluso in locale via `.git/info/exclude`): `tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv` (1755 righe: `match_id, competition_id, season_id, kickoff_at, home_goals, away_goals, lambda_home, lambda_away, lambda3, p_home, p_draw, p_away`).

Rigenerazione minima (circa 10 secondi, nessun DB/API; fit solo sul TRAIN, fornisce anche l'1X2 di controllo):

```powershell
cd C:\xampp\htdocs\robetting
py -3 tools/scripts/diagnose_candidate47_calibration.py --export-test-csv tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv
```

Su un nuovo clone: aggiungere `tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv` a `.git/info/exclude` per evitare di committare il CSV. Controllo di coerenza atteso: N = 1755, LogLoss 0.9948, Brier 0.5926, Accuracy 0.5123.
