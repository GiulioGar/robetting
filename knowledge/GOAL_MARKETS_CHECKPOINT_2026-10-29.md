# GOAL markets — checkpoint 2026-10-29 (Step 7–14)

Segue `GOAL_MARKETS_CHECKPOINT_2026-10-26.md`. Tutto offline e diagnostico, sul CSV out-of-sample
`tools/datasets/candidate47_team_history_test_lambdas_2025_26.csv` (TEST 2025/26, N=1755, non versionato).
Baseline: frequenze dal TRAIN 2024/25 (`dataset_core_v1_team_history_2024_2025.csv`, season_id 28–32, N=1756).
Convenzioni: diff = prevista − osservata; lambda_home/away sono medie marginali Poisson;
bivariate Poisson con componenti X~Pois(λh−λ3), Y~Pois(λa−λ3), Z~Pois(λ3), λ3=0.15 costante;
Var(T)=λh+λa+2·λ3. Tutte le SE assumono partite indipendenti e modello corretto (salvo "appaiata").

Nota di riproducibilità: gli Step 7–14 sono stati eseguiti come calcoli Python one-off sul CSV, senza script
salvati. Nessuno script nuovo da conservare; i numeri sono ricalcolabili dal CSV con le formule sopra.

## 1. Risultati quantitativi

**Step 7 — gol totali.** Prevista 2.888, osservata 2.764, diff +0.124 (SE 0.041, z≈+3.1; z≈+2.9 con Var corretta).
Per competizione (15..19): +0.281, +0.177, +0.173, −0.093, +0.021. Per quintili di λ_tot: +0.04, +0.22, +0.09,
−0.05, +0.32 (Q5 λ>3.10; nessun andamento monotono).

**Step 8 — effetto λ3.** Media identica con λ3=0 o 0.15. Var condizionale media 3.188 (A) vs 2.888 (B); varianza
osservata 2.749. P(0 gol): A .0686, B .0590, oss .0655. P(5+): A .179, B .170, oss .143. Over: effetto di λ3 ≤0.01.
Entrambe sovrastimano O3.5/O4.5 in comp 15/16/17.

**Step 9 — marginali.** HOME +0.026 (SE 0.030), AWAY +0.098 (SE 0.027): la sovrastima del totale viene soprattutto
dalla trasferta (positiva in tutti i quintili e comp). HOME comp 15 +0.244; 18/19 sottostimate. Pearson φ: HOME 0.936,
AWAY 0.877, totale 0.846 (Var con λ3) / 0.935 (senza): nessuna sovradispersione, lieve sottodispersione.
Correlazione residui H/A ≈ +0.04.

**Step 10 — Team Goals (O0.5/1.5/2.5).** HOME ben calibrato (|diff| ≤0.007). AWAY sovrastima +0.018/+0.031/+0.025.
Comp 15 HOME +0.06–0.07; comp 17 AWAY O1.5/O2.5 +0.06–0.07.

**Step 11 — baseline Team Goals.** Candidate47 batte la migliore baseline (globale o per comp) in tutte le 6 combinazioni:
ΔLogLoss −0.023…−0.034 (SE appaiata 0.0045–0.0079, ≈3–6 SE); ΔBrier −0.006…−0.017.

**Step 12 — BTTS.** P=1−e^−λh−e^−λa+e^−(λh+λa−λ3). Prevista 0.553, osservata 0.539, +1.40 pp (SE 1.18). LogLoss 0.6863,
Brier 0.2466. Comp 15 +6.6 pp (≈2.6 SE), 19 +4.0, 17 −2.2, 18 −3.0. Quantile 2: +5.7 pp (≈2.1 SE), altri ≤1.4 pp.

**Step 13 — baseline BTTS.** Frequenza TRAIN globale 0.5541; per comp 0.516/0.574/0.542/0.575/0.571. LL: C47 0.6863,
globale 0.6906, per comp 0.6880. Δ vs migliore (per comp): LL −0.0016 (SE 0.0018), Brier −0.0008 (SE 0.0009): ≈0.9 SE.

**Step 14 — Multigol (fasce predefinite).** Prevista vs osservata: 1–3 .602/.638, 2–4 .583/.622, 2–5 .674/.705,
3–6 .498/.507 (diff −3.6, −4.0, −3.1, −0.9 pp). Vs baseline per comp: ΔLL +0.0079 (SE .0039), +0.0009 (.0025),
−0.0035 (.0028), −0.0031 (.0027). Solo 1–3 supera 2 SE, ed è a sfavore di Candidate47.

## 2. Problemi identificati e incertezza
- Sovrastima dei gol totali (+0.124), concentrata in comp 15/16/17 e in AWAY: ≈3 SE aggregata, 2–3 SE per
  singole competizioni. Una sola stagione, nessuna correzione per confronti multipli.
- Eterogeneità per competizione (15 sovrastimata; 18/19 sottostimate): segno che cambia, non stabile.
- Code/Over alti sovrastimati (O3.5/O4.5; 5+ gol .179 vs .143); sottodispersione vs Poisson (φ 0.85–0.94).
- BTTS: aggregato compatibile con rumore; scarsa discriminazione (60% delle partite tra 0.52 e 0.59).
- Multigol centrali (1–3, 2–4, 2–5) sottostimati di ≈3–4 pp (≈2.7–3.4 SE) e non superiori alla baseline per comp.

## 3. Dimostrato / ipotesi / aperto
- **Dimostrato (descrittivo, su questo TEST):** i numeri della sezione 1; λ3 non cambia la media prevista; BTTS Sì/No
  complementari; le baseline sono costanti e deboli; Candidate47 batte nettamente le baseline sui Team Goals.
- **Ipotesi diagnostiche (non verificate):** la sovrastima AWAY alimenta il totale; λ3 costante accentua la
  sottodispersione del totale; i Multigol centrali risentono di massa spostata verso gli estremi.
- **Aperto:** stabilità tra stagioni; causa dell'eterogeneità per competizione; se un λ3 variabile, una
  dispersione diversa o una correzione del livello AWAY migliorino davvero; significatività dopo correzione per
  confronti multipli; confronto con le quote di mercato (Odds V1 solo prospettico).

## 4. Decisione
Candidate47 resta il riferimento 1X2. **Nessuna modifica approvata** né applicata al modello o al runtime.

## 5. Avvertenza sul TEST 2025/26
Il TEST 2025/26 è stato ampiamente esplorato (Step 4–14). Non va usato per selezionare nuovi modelli, feature,
iperparametri o soglie, né per dichiararne successivamente la validità: ogni conclusione costruita su di esso sarebbe
contaminata.

## 6. Prossima fase / punto di ripartenza
Progettazione metodologica di possibili miglioramenti GOAL (prima del codice): definire ipotesi e metriche
pre-registrate, usare TRAIN/validazione senza TEST 2025/26, e ottenere una nuova validazione temporale
indipendente (es. stagione 2026/27 prospettica o walk-forward ancora non osservato) con correzione per confronti multipli.
