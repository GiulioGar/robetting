# GOAL research — checkpoint 2026-10-30 (Step 15–37)

Documento di ripartenza, autosufficiente. Segue `GOAL_MARKETS_CHECKPOINT_2026-10-26.md` (Step 4–6) e
`GOAL_MARKETS_CHECKPOINT_2026-10-29.md` (Step 7–14). Tutta l'analisi sul 2025/26 è **esplorativa** (la stagione è stata
ampiamente esplorata e non è un test indipendente). Nessuna modifica a Candidate47 né al motore produttivo è stata approvata.

## 1. Stato di Candidate47 e dati

- **Riferimento 1X2 invariato:** Candidate47 v1.1.0, `tools/models/prediction_engine_candidate47_team_history_recent.json`,
  SHA-256 `9c11330ed725d20c1609098643051d69b12ab717e2688e9116b2a2d4fa7135d8`, `model_key = candidate47_structural_log`,
  47 feature, due PoissonRegressor (alpha 1.0), Bivariate Poisson con `lambda3 = 0.15` fisso, addestrato il 2026-10-20 su
  **tutte le 3511 partite 2024/25 + 2025/26** (quindi sui lambda 2025/26 è in-sample: non valutabile come out-of-sample).
  v1.0.0 (`…candidate47_structural_log.json`, sha `f5060a4a…`) era il runtime fino al 26/10.
- **Dati disponibili:** `tools/datasets/dataset_core_v1_team_history_2024_2025.csv` (3511 righe: 2024/25 = 1756 con season_id
  28–32, 2025/26 = 1755 con season_id 23–27; leghe core 15–19). `tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv`
  (1755 righe, **non versionato**; lambda out-of-sample da fit con la stessa procedura **solo su 2024/25**; rigenerabile con
  `diagnose_candidate47_calibration.py --export-test-csv`). Il 2023/24 è importato nel DB ma non ha dataset feature (copertura da
  verificare). `dataset_core_v1_2026.csv` (203 righe, 20/09) e `latent_fit_matches_extended.csv` sono ricostruzioni parziali.
- **Latent snapshot** (`tools/models/latent_strength_current.json`): rigenerato il 2026-10-30 13:57:40Z con 3915 partite, 121
  squadre, `last_match_included_at` 2026-10-29T19:45Z (prima: 04/10, 3761 partite, ultima partita 20/09; la versione precedente
  resta recuperabile da git). Contiene solo metadati di generazione e, per squadra, `team_id`, `attack`, `defence`.
- **Prediction official 2026/27** (DB locale, alla sera del 30/10): 132 righe `candidate47_structural_log` su 132 partite, nessun duplicato;
  86 con v1.0.0 (latent del 04/10) e 46 con v1.1.0 (36 del 29/10 con latent 04/10, 10 del 30/10 con latent 30/10). Copertura delle partite
  finite dall'avvio della cattura (05/10) circa 56% (buchi 11/10 e 24–25/10, causa: cattura legata all'avvio di `robetting:serve`).
  Le prediction sono append-only e non sono state modificate.
- **Pipeline di avvio** (commit `fc604cd`): in `robetting:serve` il refresh del latent parte solo se il DB ha partite eleggibili non
  incluse nello snapshot (stessa politica del fit, `py`), la cattura delle prediction official è saltata se la sincronizzazione di
  catch-up/calendario è fallita o se il refresh latent fallisce; catch-up ora espone `failed_batches`.

## 2. Risultati esplorativi (2025/26, N = 1755, lambda TRAIN-only; bootstrap per settimana ISO, 2000 repliche, seed 20261031)

**Step 27 — incertezza del bias (previsto − osservato, gol/partita):** HOME +0.0265 [−0.0154, +0.0695]; AWAY +0.0976 [+0.0423, +0.1535];
AWAY − HOME +0.0711 [+0.0035, +0.1452] (fragile: escludendo la lega 17, 18 o 19 l'IC include 0). Per lega (HOME / AWAY): 15 +0.244 / +0.038;
16 +0.063 / +0.115; 17 −0.018 / +0.191; 18 −0.119 / +0.026; 19 −0.086 / +0.107. L'AWAY è positivo in tutte le leghe; l'asimmetria viene
dalla variabilità dell'errore HOME. Script: `goal_e1_bias_uncertainty.py`.

**Step 28 — baseline storica AWAY** (media per lega del 2024/25): bias +0.0995 vs +0.0976 di Candidate47; le medie AWAY osservate scendono in tutte
le leghe (globale 1.3274 → 1.2279, −0.0995). Candidate47 batte la baseline sul LogLoss Poisson: Δ −0.0429 [−0.0550, −0.0320], in tutte e
5 le leghe. Lettura: la sovrastima AWAY non è specifica di Candidate47, è coerente con un cambio di livello tra stagioni; i dati di una stagione
non permettono di dirlo persistente. Script: `goal_e1_away_baseline.py`.

**Step 29 — HOME, totale, vantaggio casa:** HOME 1.4994 → 1.5362 (+0.037); totale 2.8269 → 2.7641 (−0.063); HOME − AWAY 0.172 → 0.308.
Candidate47 vs baseline HOME: Δ LogLoss −0.0509 [−0.0685, −0.0348], tutte le leghe. Vantaggio casa previsto − osservato −0.0711
[−0.1452, −0.0035] (positivo in lega 15, negativo nelle altre). Il calo dei gol è soprattutto in trasferta; il vantaggio casa osservato quasi
raddoppia. Stessa composizione delle stagioni salvo lega 19 (308 vs 307). Script: `goal_e1_home_total_advantage.py`.

**Step 30 — totale T, BP (λ3 = 0.15) vs Poisson indipendenti, lambda fissi:** ΔNLL(T) A − B = +0.00340 [+0.00018, +0.00664] (A leggermente
peggiore); ΔRPS +0.00066 [−0.00046, +0.00181]; massa residua ~1e-16; 5+ gol sovrastimati da entrambe (osservato 14.3%, BP 17.9%,
indip. 17.0%). Per lega: solo la 19 va nel verso opposto. Script: `goal_b1_total_goals_bp.py`.

**Step 31 — dipendenza congiunta:** BTTS LogLoss Δ +0.00029 [−0.00057, +0.00117]; NLL risultato esatto Δ +0.00087 [−0.0045, +0.0059];
LogLoss 1X2 Δ −0.00192 [−0.00455, +0.00069]; P(pareggio) media A 0.2459, B 0.2315, osservata 0.2553. λ3 = 0.15 sposta i pareggi verso
l'osservato ma nessun IC esclude lo zero salvo l'NLL(T) sfavorevole ad A. Script: `goal_b2_joint_dependence_bp.py`.

**Limiti comuni:** una sola stagione già esplorata; bootstrap con 38 blocchi (IC approssimati); lambda con bias di livello noto, che confonde
il confronto di forma; confronti multipli non corretti; λ3 fisso, tarato sull'1X2. Nessuna di queste evidenze giustifica una promozione di modello.

## 3. Protocollo B-P1 (congelato)

- **Commit `15a76a6b18c6b2132a30b9faca6d72f1b1d9789f`** con `knowledge/GOAL_RESEARCH_PROTOCOL.md` (§12), `tools/scripts/goal_bp1_prospective_analysis.py`,
  `app/Console/Commands/ExportBp1Sample.php` (exporter read-only), `tests/Feature/Console/ExportBp1SampleCommandTest.php`,
  `knowledge/GOAL_BP1_FREEZE_MANIFEST.json`.
- **`T_freeze` = 2026-10-30T15:35:28Z** (effettivo, non retrodatato); commit base `fc604cd`. Hash SHA-256 nel manifest (script `e9b32460c8db…`,
  protocollo `763d369c63c0…`); verifica: `git show <commit>:<path> | sha256sum`. Non modificare questi file se non con emendamento datato e nuovo manifest.
- **Disegno:** confronto A (BP λ3 = 0.15) vs B (Poisson indipendenti) a parità di `lambda_home/away` delle prediction official pre-match;
  campione = righe `official`, v1.1.0, SHA completo, `generated_at < kickoff_at`, `kickoff_at ≥ T_freeze`, leghe 15–19, `finished`, punteggio a 90',
  `lambda3 = 0.15`, **spareggi esclusi** (descrittivi a parte). **Metrica primaria unica:** ΔNLL del totale esatto (A − B), IC bilaterale 95% con
  bootstrap per settimana ISO (2000 rep., seed 20261031). Esito: IC < 0 evidenza per A; IC > 0 evidenza per B; IC contiene 0 **inconcludente**
  (nessuna equivalenza dichiarata). Una sola analisi, a fine stagione regolare 2026/27 (σ = 0.0652 per partita è solo un riferimento di precisione,
  non una potenza). Nessun `n_min`, `δ`, soglia di copertura. Metriche secondarie (RPS, Over 2.5/3.5/4.5, BTTS, risultato esatto, LogLoss 1X2)
  descrittive. Esecuzione: `php artisan robetting:export-bp1-sample --out-dir=<dir>` poi lo script con `--t-freeze` dal manifest.

## 4. Architettura COM-Poisson (Step 36)

T ~ COM-Poisson con E[T] = λH + λA e dispersione ν; H | T ~ Binomiale(T, q), q = λH/(λH+λA); A = T − H. Proprietà dimostrabili: medie HOME/AWAY
conservate per qualunque ν; `Cov(H,A) = q(1−q)(Var(T) − μ)` (positiva se sovradispersione, negativa se sottodispersione); `Var(H) = λH(1−q) + q²Var(T)`;
ν = 1 riproduce due Poisson indipendenti (la ripartizione binomiale di un Poisson dà componenti indipendenti: A è un caso annidato); Bivariate Poisson
non è annidato (Var(T) = μ + 2λ3 ≥ μ, Cov = λ3 ≥ 0, marginali Poisson). Un solo parametro di dispersione, stesso numero di parametri di B. Famiglie
alternative scartate: Generalized Poisson (normalizzazione non esatta per η < 0), Double Poisson (normalizzazione approssimata), binomiale negativa (solo sovradispersione).
**Ipotesi non verificate:** la sottodispersione condizionata di T (confusa con l'errore di livello dei lambda), un possibile peggioramento dei pareggi con ν > 1
(covarianza negativa, di segno opposto alla correlazione dei residui ≈ +0.04 vista nello Step 9), l'effetto della coda più leggera sulle partite da 7+ gol, e
la modifica delle marginali (quindi dei mercati Team Goals).

## 5. Prototipo COM-Poisson (Step 37)

`tools/scripts/goal_com_poisson_prototype.py` (stdlib, sintetico, indipendente dal motore): COM-Poisson con inversione della media per ogni partita
(Newton con bisezione), normalizzazione log-space con bound rigoroso di coda 1e-15 (massa mancante riportata, mai rinormalizzata), congiunta
`P(h,a) = P_T(h+a)·C(h+a,h)q^h(1−q)^a` e mercati derivati (totale, Over, Team Goals, BTTS, 1X2, P(0–0)). `--self-test`: 1569 controlli su 10 coppie di lambda
(0.02–10) × 9 valori di ν (0.4–5): massa, medie, varianze, covarianza, ν = 1 ≡ Poisson indipendenti, segni per ν ≶ 1, continuità, riferimento Decimal a 60 cifre,
casi estremi; errori massimi ~1e-14; supporto massimo K = 128, ≤ 32 iterazioni; ~0.8 ms/partita. **Limiti:** nessuna stima di ν, nessun dato reale, congiunta K²
elementi (cresce per ν molto basso o μ alto, tetto KMAX = 20000), Var(T) ≈ μ/ν solo approssimata. Pronto matematicamente per la stima; mancano criterio di stima,
lambda out-of-sample di sviluppo e protocollo di confronto.

## 6. Decisioni già prese

Candidate47 v1.1.0 resta il riferimento 1X2; nessuna correzione numerica dei lambda dal TEST 2025/26; il 2025/26 è solo sviluppo/diagnosi esplorativa; 2026/27 è la
conferma prospettica (distinguendo versione/SHA/snapshot, senza ricostruire prediction mancanti come official); B-P1 congelato e non modificabile; spareggi fuori dal
campione primario; nessun uso dei risultati prospettici per scegliere modelli; lo snapshot dei latent non è un prerequisito di B-P1 (bastano lambda e metadati salvati).

## 7. Questioni aperte

Fonte dei lambda out-of-sample per la fase di sviluppo (CSV TRAIN-only 2024/25 → 2025/26, oppure walk-forward da definire; 2023/24 ricostruibile?); criterio di stima di ν
(massima verosimiglianza del totale con lambda fissi, intervallo con bootstrap a blocchi, test ν = 1); come separare la sottodispersione dall'errore di livello dei lambda;
valutazione dei pareggi e dei Team Goals sotto C; ruolo di λ3; correzione per confronti multipli per le analisi esplorative; periodo di sviluppo/validazione/test
indipendente per l'esperimento COM-Poisson; archiviazione degli snapshot latent a ogni refresh.

## 8. Prossimo Step 38 (non iniziato)

Definire (protocollo, prima di eseguire): (1) la **stima sperimentale di ν** su lambda out-of-sample di sviluppo (un solo parametro, ν = 1 come ipotesi nulla) e la
sua incertezza; (2) la **separazione temporale** dei dati: sviluppo, validazione, test indipendente (2026/27 prospettico), senza usare il 2025/26 per scegliere ν;
(3) i **confronti** di C con A (Poisson indipendenti) e B (Bivariate Poisson) su NLL(T) come metrica primaria, con controlli su pareggi, 1X2, Team Goals, BTTS,
risultato esatto e coda; (4) cosa è vietato (tuning sul TEST, aggregazione di versioni). Nessun risultato di Step 38 deve essere ottenuto prima di definire il disegno.

## 9. Ripartenza e note operative

Leggere, nell'ordine: questo checkpoint → `knowledge/GOAL_RESEARCH_PROTOCOL.md` (§9–§12) → `knowledge/GOAL_BP1_FREEZE_MANIFEST.json`. Script esplorativi in
`tools/scripts/` (`goal_e1_*`, `goal_b1_*`, `goal_b2_*`, `goal_com_poisson_prototype.py`, `goal_bp1_prospective_analysis.py`), tutti con output deterministico (seed 20261031).
Nota: nel tool Bash evitare backtick e caratteri non ASCII in heredoc/comandi (vengono interpretati dalla shell); per testi usare gli strumenti Write/Edit. Nessun push
effettuato: i commit di questa sessione sono locali.
