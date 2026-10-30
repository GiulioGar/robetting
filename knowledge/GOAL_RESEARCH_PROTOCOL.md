# GOAL markets — protocollo di ricerca statistica (Step 15)

Stato: **bozza metodologica, 2026-10-30; aggiornata con il primo esperimento E1 (§9–§11) e il protocollo prospettico BP vs indipendenza B-P1 (§12)**. Precede qualsiasi esperimento. Parte da
`GOAL_MARKETS_CHECKPOINT_2026-10-26.md` (Step 4–6) e `GOAL_MARKETS_CHECKPOINT_2026-10-29.md` (Step 7–14).
Non contiene nuove analisi, soglie numeriche, periodi di validazione o modelli. Le scelte non ancora prese sono
elencate al §7.

**Riferimento invariato:** Candidate47 v1.1.0 (`prediction_engine_candidate47_team_history_recent.json`, 47 feature,
due PoissonRegressor alpha=1.0, Bivariate Poisson con λ3 = 0.15 fisso) resta il riferimento 1X2. Questo protocollo
non lo modifica e non autorizza modifiche al runtime, agli artifact o a λ3.

---

## 1. Evidenze già raccolte

### 1.1 Osservato (descrittivo, TEST 2025/26, N = 1755, lambda out-of-sample da fit solo su TRAIN 2024/25)
- Gol totali: previsti 2.888, osservati 2.764 (prev−oss +0.124, SE 0.041). La sovrastima è soprattutto AWAY (+0.098, SE 0.027) rispetto a HOME (+0.026, SE 0.030).
- Eterogeneità per competizione: totale prev−oss +0.281 / +0.177 / +0.173 / −0.093 / +0.021 per id 15–19. Nessun andamento monotono per quintili di λ_tot.
- Dispersione: varianza osservata ≈ media (2.748 vs 2.764); varianza prevista maggiore (rapporto osservata/prevista 0.82 nel checkpoint 26/10). Pearson φ < 1 (HOME 0.936, AWAY 0.877, totale 0.846 con λ3), cioè lieve sottodispersione, non sovradispersione.
- Coda: 5+ gol osservati 14.30% contro 17.86% attesi; O3.5/O4.5 sovrastimati soprattutto in id 15/16/17 (id 18 con segno opposto).
- λ3: non cambia la media prevista; incide sulla varianza condizionale del totale (3.188 con λ3 = 0.15 vs 2.888 con λ3 = 0) e quasi nulla sulle probabilità Over (≤ 0.01).
- Team Goals: HOME ben calibrato; AWAY sovrastimato (+0.018/+0.031/+0.025 su O0.5/1.5/2.5). Candidate47 batte le baseline di frequenza TRAIN in tutte le 6 combinazioni (ΔLogLoss −0.023…−0.034, ≈3–6 SE appaiate).
- BTTS: previsto 0.553, osservato 0.539 (+1.40 pp, SE 1.18). Vantaggio sulla baseline per competizione: ΔLogLoss −0.0016 (SE 0.0018), ≈0.9 SE.
- Multigol: 1–3 peggiore della baseline (ΔLL +0.0079, SE 0.0039); 2–4 pari; 2–5 e 3–6 lievemente migliori (≈1.2 SE). Le fasce centrali risultano sottostimate di ≈3–4 pp.
- Over/Under: Candidate47 batte la frequenza globale su tutte le linee 0.5–4.5; su 3.5/4.5 la baseline per competizione è lievemente migliore (scarti 0.001–0.01 in assoluto, incertezza non stimata nello Step 6).
- Correlazione dei residui H/A ≈ +0.04.

### 1.2 Ipotesi NON dimostrate
- La sovrastima AWAY alimenta quella sul totale (osservata la co-occorrenza, non la causa).
- λ3 costante, tarato sull'1X2, accentua la sottodispersione del totale o compensa effetti locali sui punteggi bassi.
- I Multigol centrali risentono di massa spostata verso gli estremi.
- L'eterogeneità per competizione (15/16/17 vs 18/19) è strutturale e non rumore di una sola stagione.
- Il mapping `competition_id` → lega è verificato solo per id 15 (Serie A).

### 1.3 Limiti noti dell'evidenza
- Una sola stagione di TEST, 307–380 partite per competizione; scarti per singola competizione dell'ordine di 2–3 SE al massimo.
- Le SE dei checkpoint assumono partite indipendenti e modello corretto (salvo dove indicato "appaiata"). Non è stata applicata alcuna correzione per confronti multipli.
- Gli Step 7–14 sono calcoli one-off senza script salvati; il CSV dei lambda non è versionato.
- L'artifact finale è addestrato su tutte le 3511 partite (TRAIN + TEST): non è utilizzabile per valutare il TEST.
- Confronto con quote bookmaker per questi mercati non possibile: Odds V1 raccoglie solo l'1X2, in modo prospettico.

---

## 2. Aree di ricerca

**A. Qualità dei lambda.** Domanda: la media di λ_home, λ_away e λ_tot è calibrata, e come varia per classi di λ,
sede, competizione e stagione? Separa l'errore di livello (bias medio) dall'errore di spread (discriminazione tra
partite).

**B. Distribuzione probabilistica.** Domanda: dati i λ, la forma implicata (marginali Poisson + dipendenza λ3) descrive
dispersione, coda, punteggi bassi, BTTS e Multigol? Un errore qui è distinto da un errore in A e va diagnosticato
separatamente (es. condizionando sulla calibrazione dei λ).

**C. Capacità predittiva dei mercati.** Domanda: le probabilità derivate (Over/Under, BTTS, Team Goals, Multigol)
battono baseline definite a priori e, quando disponibili in modo prospettico, il mercato? Dipende da A e B ma si valuta
sulle probabilità finali.

Le tre aree si valutano in ordine A → B → C: non si interpreta un risultato di C senza sapere se la causa è in A o B.

---

## 3. Ipotesi statistiche da verificare

Formulate come ipotesi nulle/alternative sugli effetti, senza prescrivere feature, algoritmi o correzioni. Le soglie
di decisione sono da fissare prima dell'esperimento (§7).

**Area A**
- H-A1: il bias medio di E[T] (prev−oss) è zero, sia in aggregato sia per competizione.
- H-A2: il bias è lo stesso per HOME e AWAY (la sovrastima AWAY non è dovuta al caso).
- H-A3: il bias non dipende dalla classe di λ (assenza di errore di spread).
- H-A4: il segno e la dimensione del bias per competizione sono stabili tra stagioni.

**Area B**
- H-B1: a λ dati, varianza osservata del totale = varianza implicata (con il λ3 corrente); in alternativa sotto/sovradispersione.
- H-B2: le frequenze osservate di 0, 1, 2, 3, 4, 5+ gol coincidono con quelle implicate, in particolare nella coda (5+).
- H-B3: la dipendenza tra gol casa e trasferta (λ3 ≥ 0 e costante) è compatibile con i dati; λ3 non varia con il livello dei gol o con la competizione.
- H-B4: BTTS e Multigol derivati dalla stessa distribuzione sono calibrati.

**Area C**
- H-C1: per ciascun mercato, la perdita attesa di Candidate47 non è inferiore a quella della baseline di frequenza (globale e per competizione).
- H-C2: la differenza rispetto alla baseline non cambia tra competizioni (nessuna eterogeneità del vantaggio).
- H-C3: (solo con quote disponibili) le probabilità del modello non aggiungono informazione rispetto al consenso di mercato.

---

## 4. Metriche

- **LogLoss** e **Brier** per ogni mercato binario (Over/Under, BTTS, Team Goals, fasce Multigol); per mercati a più esiti, LogLoss/Brier multiclasse e **RPS** dove l'ordine conta (vedi `knowledge/rps.md`).
- **Calibrazione:** differenza media prev−oss (con la stessa convenzione di segno dei checkpoint del 29/10), tabelle per gruppi di probabilità/λ, e per competizione. Stessa definizione di gruppi tra modelli confrontati.
- **Diagnostica delle distribuzioni:** media, varianza e rapporto varianza/media del totale e delle marginali; Pearson φ; frequenze 0…5+ osservate vs implicate; correlazione dei residui H/A; coda (5+, 7+).
- **Incertezza delle differenze appaiate:** ogni confronto tra due modelli/baseline è riportato come differenza appaiata per partita con la sua SE (o intervallo), non come confronto di due punteggi aggregati con incertezza non stimata (limite dello Step 6). Le SE dei checkpoint assumono indipendenza: dichiararlo, e valutare se serve un metodo che rispetti la dipendenza (es. per squadra/giornata) — da decidere (§7).
- Ogni metrica va riportata con N e per competizione; un'unica statistica aggregata non basta.
- Benchmark di sanità: LogLoss 1X2 0.9948, Brier 0.5926, Accuracy 0.5123 sul TEST da fit solo su TRAIN. Se non si ritrovano, il confronto non è valido.

---

## 5. Separazione temporale

Tre ruoli distinti e non intercambiabili:

1. **Sviluppo** — dati su cui si esplora, si formulano ipotesi e si fanno scelte. Solo dati precedenti alla validazione.
2. **Validazione temporale** — dati successivi allo sviluppo, usati per scegliere tra alternative già definite. Può essere consultata più volte, ma ogni consultazione conta come confronto (§6).
3. **Test indipendente** — dati mai osservati nello sviluppo né nella validazione, usati **una sola volta** per l'esito finale delle ipotesi pre-registrate. Candidato naturale: 2026/27 prospettico (da confermare).

Principi:
- L'ordine temporale è rigido: nessun dato successivo a una partita entra nella previsione di quella partita (stessa regola walk-forward del progetto, come in [methodology rules]).
- Il **TEST 2025/26 è esaurito come test**: è stato usato in Step 4–14 per guardare distribuzioni, calibrazioni e baseline. Può servire solo come materiale descrittivo e per generare ipotesi, non per sceglierne tra alternative né per confermarle.
- Le baseline di frequenza devono usare solo dati antecedenti al periodo valutato.
- Eventuali stime (es. λ3, baseline) vanno fatte sul periodo di sviluppo, non sul periodo in valutazione.

---

## 6. Regole anti-leakage, anti-tuning e confronti multipli

**Leakage**
- Nessun artifact addestrato su un periodo che include quello da valutare (l'artifact finale v1.1.0 contiene TRAIN + TEST: vietato per valutare 2025/26). Per ogni valutazione il fit usa solo dati precedenti.
- Feature e baseline strict as-of kickoff; per statistiche arricchite tardivamente (es. xG) vale il cutoff conservativo già adottato.
- Nessuna informazione dell'esito (gol, stato partita) nelle feature della partita stessa; nessuna normalizzazione/standardizzazione stimata su dati futuri.
- Le partite incluse devono essere definite prima (es. regole per spareggi id 18/19 e per la partita mancante in id 19) e applicate in modo uguale a tutti i modelli confrontati.

**Tuning sul TEST 2025/26**
- Vietato scegliere modelli, feature, iperparametri, soglie, fasce o correzioni guardando i risultati sul TEST 2025/26.
- Vietato dichiarare "validato" un miglioramento sulla base del TEST 2025/26.
- Ogni analisi sul TEST 2025/26 va etichettata come esplorativa.

**Confronti multipli**
- Prima di ogni esperimento: elenco chiuso di ipotesi, mercati, competizioni, linee e metriche valutate; ciò che non è nell'elenco è esplorativo.
- Distinguere ipotesi primarie (poche, pre-registrate) da secondarie/esplorative; solo le primarie sostengono una decisione.
- Le analisi per sottogruppo (competizione, quintile, fascia) sono esplorative, salvo pre-registrazione.
- Applicare una correzione per confronti multipli scelta in anticipo (metodo da decidere, §7) e riportare il numero totale di confronti effettuati, inclusi quelli scartati.
- Non cambiare metrica, sottogruppo o criterio dopo aver visto i risultati; ogni deviazione va dichiarata nel report.
- Esperimenti su un solo fold/stagione non bastano a concludere stabilità.

---

## 7. Decisioni ancora da prendere (prima di qualsiasi esperimento)

1. **Periodo di sviluppo, validazione e test indipendente**: quali stagioni/date, e se il test è 2026/27 prospettico o un walk-forward ancora non osservato. Quando e con quale quantità di partite lo si considera maturo.
2. **Ipotesi primarie**: quali tra §3 sono primarie, quali esplorative; quali mercati e linee entrano nell'elenco.
3. **Soglie di decisione**: cosa conta come differenza rilevante (SE, intervallo, dimensione minima di effetto) e cosa come "nessuna evidenza"; nessuna è fissata qui.
4. **Metodo per l'incertezza**: SE analitica appaiata vs bootstrap, e se raggruppare per squadra/giornata per rispettare la dipendenza.
5. **Correzione per confronti multipli**: quale metodo e su quale famiglia di ipotesi.
6. **Baseline di confronto per C**: frequenza globale, per competizione, o altre; come stimarle nel periodo di sviluppo.
7. **Trattamento dei dati**: spareggi id 18/19, partita mancante in id 19, verifica del mapping `competition_id` → lega (solo id 15 verificato), λ estremi e coda oltre 10 gol.
8. **Riproducibilità**: salvare gli script degli Step 7–14 e decidere se/come versionare o rigenerare il CSV dei lambda; definire come generare lambda per periodi di sviluppo/validazione senza usare l'artifact finale.
9. **Ruolo di λ3 nella ricerca**: se stimare λ3 rientra nello scopo (area B) o se resta fisso a 0.15 finché l'1X2 è il riferimento; criterio per non peggiorare l'1X2.
10. **Quote e mercato (area C, H-C3)**: quando e come ottenere quote per mercati diversi dall'1X2; finché non esistono, H-C3 non è verificabile.
11. **Scope della ricerca**: se e quando valutare mercati oltre a quelli derivati dalla distribuzione corrente (tempi, marcatori, ecc.).

---

## 8. Cosa questo documento non fa

Non sceglie feature, algoritmi o correzioni; non modifica Candidate47 v1.1.0, λ3 o il runtime; non fissa soglie, periodi
o numerosità; non contiene analisi nuove. Prossimo passo: sciogliere le decisioni del §7 e del §11, poi redigere
l'elenco chiuso di ipotesi pre-registrate per E1 (§9–§10).

---

## 9. Ruoli dei periodi (aggiornamento Step 26)

| Periodo | Ruolo | Vincoli |
|---|---|---|
| 2024/25 | Training disponibile | I lambda di Candidate47 su questa stagione sono **in-sample** (l'artifact è addestrato su 2024/25 + 2025/26): un bias nullo qui non è evidenza di calibrazione. |
| 2025/26 | Sviluppo e diagnosi **esplorativa** | Già esplorato (Step 4–14): utile per generare ipotesi e capire i meccanismi, **non** per scegliere correzioni, soglie o modelli né per confermare. Non è un test indipendente per nuove scelte. |
| 2026/27 | Conferma prospettica | Unica fonte di test indipendente; le prediction vanno stratificate per versione e snapshot (§9.2). |

### 9.1 Lambda "out-of-sample" sul 2025/26
- L'artifact finale Candidate47 (v1.0.0 e v1.1.0, 3511 partite) **non può essere valutato sul 2025/26 dichiarandolo out-of-sample**: i lambda che ne derivano su quella stagione sono in-sample.
- Gli unici lambda 2025/26 con significato out-of-sample sono quelli del CSV locale `candidate47_team_history_test_lambdas_2025_26.csv` (fit con la stessa procedura **solo su 2024/25**). Vanno etichettati "fit TRAIN-only 2024/25", non "Candidate47 v1.1.0".
- Conseguenza: il bias osservato su 2025/26 descrive l'estrapolazione dal 2024/25 al 2025/26 (deriva tra stagioni più eventuale errore di specificazione), non l'errore atteso dell'artifact finale in produzione.

### 9.2 Prediction prospettiche 2026/27
- Valgono come prova prospettica solo le righe `purpose='official'` con `generated_at < kickoff_at`. Le `public_latest` e le prediction ricostruite non lo sono.
- **Stratificare** per (`model_version`, `artifact_sha256`, `latent_snapshot_generated_at`): v1.0.0 con latent del 04/10, v1.1.0 con latent del 04/10, v1.1.0 con latent del 30/10 sono campioni distinti (semantica delle finestre recenti e freschezza del latent diverse). Non si aggregano senza dichiararlo e senza analisi per strato.
- **Non ricostruire** come official le prediction mancanti. Le partite non catturate si possono ricostruire solo per un'analisi di sensibilità separata, etichettata "ricostruita", mai mescolata alle official.
- Verificare e riportare la **copertura della cattura** (partite finite con/senza prediction): la selezione non è casuale (§ Step 17–18).
- Il latent snapshot è un input dell'artifact: la sua freschezza (`last_match_included_at`) va riportata per riga; la validità temporale dello snapshot (`generated_at < kickoff`) non implica che le feature fossero aggiornate.

---

## 10. Esperimento E1 — sovrastima sistematica di `lambda_away`

Scopo: valutare la **qualità dei lambda come predittori della media dei gol**, indipendentemente dalla Bivariate Poisson e dagli esiti 1X2.

### 10.1 Ipotesi (generata dall'esplorazione su 2025/26; da confermare altrove)
Osservato (esplorativo, fit TRAIN-only): `lambda_away` supera i gol in trasferta di +0.098 (SE 0.027) contro +0.026 (SE 0.030) per HOME. Ipotesi nulla e alternative, tutte da trattare come **non dimostrate**:
- **H-E1.0:** `E[Y_away − λ_away] = 0` (calibrazione in media).
- **H-E1.a — livello/deriva:** il bias è un cambio di livello della media dei gol in trasferta tra stagioni (rapporto costante), non legato a λ.
- **H-E1.b — pendenza:** il bias dipende da λ (calibrazione condizionata: la sovrastima cresce o cala con λ_away).
- **H-E1.c — eterogeneità:** il bias differisce per competizione (le 5 leghe) o nel tempo (mese/giornata).
- **H-E1.d — asimmetria:** il bias AWAY è diverso dal bias HOME (differenza appaiata, non due test separati).

Su `lambda_away`: `λ3` non entra, perché la marginale Poisson(λH, λA) non dipende da λ3. Questo isola la valutazione dei lambda.

### 10.2 Dati e fasi
1. **Congelamento** (prima di guardare qualsiasi dato nuovo): elenco partite, filtri (spareggi id 18/19, partita mancante in id 19, §7), etichette degli strati.
2. **Fase esplorativa (2025/26, fit TRAIN-only):** decomporre il bias in livello, pendenza, competizione e tempo; confrontare la media dei gol in trasferta osservata 2024/25 vs 2025/26 (deriva descrittiva). Nessuna decisione e nessun parametro di correzione nascono da qui.
3. **Fase confermativa (2026/27 official):** ipotesi primaria valutata su uno strato fissato in anticipo (§11), con partite finite al momento di un'analisi pre-specificata. Gli altri strati sono secondari.
4. **Fase B separata (dopo E1):** valutazione della Bivariate Poisson (totale, BTTS, Multigol, λ3), con protocollo proprio. E1 non la usa e non ne condiziona la scelta.

### 10.3 Confronti e metriche (sui gol, non sull'1X2)
Unità: la partita; `d_i = λ_away,i − y_away,i`.
- **Calibrazione in media:** media di `d` e rapporto `Σλ / Σy` (con IC). Stessa quantità per HOME e per la differenza AWAY−HOME (appaiata).
- **Calibrazione condizionata:** regressione di Poisson (link log) di `y_away` su `log λ_away` (intercetta e pendenza; pendenza = 1 e intercetta = 0 sotto calibrazione) e/o su `offset(log λ_away)` per il fattore moltiplicativo; tabella per classi di λ con **numero di gruppi fissato in anticipo**, uguale tra confronti.
- **Punteggio probabilistico per i conteggi:** log-score di Poisson (`log P(y | λ)`) e devianza di Poisson, per modello e per baseline; errore quadratico su `y` come misura secondaria (rumorosa).
- **Baseline di riferimento:** media costante dei gol in trasferta stimata su dati **precedenti** al periodo valutato (globale e per competizione, come in §4); differenza appaiata per partita con SE.
- **Controlli:** per competizione (5 leghe), per mese/giornata, per classe di λ, per versione/snapshot (§9.2); leave-one-competition-out per verificare che l'effetto non dipenda da una lega.
- **Diagnostica della varianza:** φ di Pearson per AWAY; usarlo per scegliere tra SE basata sul modello Poisson e SE robusta (empirica), non assumere Var = λ.

### 10.4 Incertezza
- IC per `mean(d)` e per il rapporto con SE robuste e con **bootstrap a blocchi** (blocco = giornata di campionato per competizione), sensibilità con blocco = squadra, perché le partite non sono indipendenti (le SE dei checkpoint assumono indipendenza).
- Differenze appaiate (modello vs baseline, AWAY vs HOME) con la stessa procedura sulle differenze per partita.
- Riportare sempre N per cella e il numero totale di confronti eseguiti (anche quelli scartati).

### 10.5 Evidenza statistica vs variabilità
Criteri da pre-registrare (valori numerici da decidere, §11):
- **Ipotesi primaria unica:** H-E1.0 sullo strato confermativo scelto; tutto il resto (competizione, pendenza, tempo, asimmetria) è secondario o esplorativo.
- **Evidenza** solo se: (i) l'IC robusto sullo strato confermativo esclude lo zero (o l'intervallo di equivalenza scelto), (ii) il segno coincide con quello esplorativo, (iii) non dipende da una singola lega (leave-one-out), (iv) resiste alla correzione per confronti multipli sulle ipotesi secondarie.
- **Variabilità** (non evidenza) se l'IC include lo zero, o se l'effetto compare solo in sottogruppi esplorativi o cambia di segno tra strati o stagioni.
- **Inconcludente** se il campione confermativo è sotto la numerosità minima pre-specificata: non si interpreta come assenza di effetto.
- Distinguere significatività statistica e rilevanza pratica (dimensione minima dell'effetto, da decidere).

### 10.6 Correzioni numeriche
**Non sono scelte qui né devono derivare dal TEST 2025/26.** Una eventuale ricalibrazione (es. fattore su λ_away) è un esperimento successivo, con parametri stimati su un periodo strettamente precedente a quello valutato e validati in avanti sul 2026/27. Il valore osservato su 2025/26 (+0.098) non va usato come correzione.

---

## 11. Decisioni ancora aperte per E1
1. **Fonte di lambda OOS per la fase esplorativa e per stimare l'eventuale deriva:** solo il CSV TRAIN-only 2024/25→2025/26, oppure lambda walk-forward (per stagione o per blocco di giornate), la cui procedura va definita prima. Il 2023/24 ricostruibile (copertura feature da verificare, Step 16) come ulteriore periodo di sviluppo.
2. **Strato primario 2026/27:** quale (`model_version`, snapshot) è l'ipotesi primaria (verosimilmente v1.1.0 con latent del 30/10, oggi con pochi risultati) e quando si analizza.
3. **Numerosità minima e calcolo della potenza** per la conferma: da stimare dalla varianza su dati di sviluppo prima di guardare i risultati 2026/27.
4. **Livello di significatività, metodo per i confronti multipli** (famiglia: 5 leghe, classi di λ, mesi, asimmetria) **e margine di equivalenza / effetto minimo rilevante.**
5. **Metodo di incertezza definitivo:** blocchi per giornata o per squadra come primari; numero di repliche bootstrap.
6. **Numero e definizione delle classi di λ** per le tabelle di calibrazione.
7. **Trattamento di spareggi, partite mancanti, λ estremi** (§7) e della **selezione non casuale** delle partite catturate nel 2026/27.
8. **Baseline per le linee 3.5/4.5 e per HOME:** stessa famiglia di confronti o fuori scopo di E1.
9. **Criterio per passare alla Fase B** (Bivariate Poisson) e se condizionarla all'esito di E1.

---

## 12. Protocollo B-P1 — confronto prospettico BP (λ3 = 0.15) vs Poisson indipendenti

Stato: **congelabile** (checklist al §12.8). Una volta congelato non si modifica prima di aver valutato gli esiti. Riguarda solo la
distribuzione congiunta a lambda dati; la qualità dei lambda resta in E1 (§10). Candidate47 v1.1.0 resta il riferimento 1X2
invariato, qualunque sia l'esito.

### 12.1 Evidenza esplorativa disponibile (2025/26, fit TRAIN-only, N = 1755; Step 30–31)
Non è conferma e non determina il disegno: NLL(T) A − B = +0.00340 (IC 95% [+0.00018, +0.00664]); RPS(T) +0.00066
([−0.00046, +0.00181]); BTTS LogLoss +0.00029 ([−0.00057, +0.00117]); NLL risultato esatto +0.00087
([−0.00450, +0.00590]); LogLoss 1X2 −0.00192 ([−0.00455, +0.00069]); P(pareggio) media A 0.2459, B 0.2315, osservata 0.2553.
Script: `goal_b1_total_goals_bp.py`, `goal_b2_joint_dependence_bp.py` (formule da non cambiare).

### 12.2 Confronto
- **A:** Bivariate Poisson con `lambda3` salvato nella riga (deve essere 0.15, altrimenti la riga è esclusa e segnalata):
  `T = Pois(λH + λA − 2λ3) + 2·Pois(λ3)`; congiunta `P(x,y) = Σ_k Pois(k;λ3)·Pois(x−k;λH−λ3)·Pois(y−k;λA−λ3)`.
- **B:** Poisson indipendenti (λ3 = 0), stessi `lambda_home` e `lambda_away` di A.
- A e B si calcolano a posteriori con la formula esatta (senza troncamento a 10, senza rinormalizzazione; massa residua
  dichiarata) dai `lambda_home`/`lambda_away` salvati nella prediction official. Nessun lambda viene ricalcolato, corretto o
  ricostruito; B non è salvata, è derivata in analisi.

### 12.3 Campione primario (regola, non elenco)
Riga della tabella `predictions` con **tutte** le condizioni:
1. `purpose = 'official'`, `model_key = 'candidate47_structural_log'`, `model_version = '1.1.0'`,
   `artifact_sha256` = `9c11330ed725d20c1609098643051d69b12ab717e2688e9116b2a2d4fa7135d8`;
2. `generated_at < kickoff_at` e `kickoff_at` della riga = `kickoff_at` attuale della partita;
3. partita **successiva al congelamento**: `kickoff_at ≥ T_freeze` (§12.8). Le righe generate prima di `T_freeze` per partite
   che si giocano dopo sono ammesse: i risultati non erano noti;
4. competizione core (id 15–19), stato `finished` e punteggio a fine tempi regolamentari valido (`home_score_ft`,
   `away_score_ft`); **gli spareggi sono esclusi dall'analisi primaria** (`is_playoff = 1`: etichetta di fase della partita
   diversa da `Regular Season - N`; se l'etichetta manca il flag non si indovina e la riga è esclusa come
   `invalid_playoff_flag`); ammessi solo in un blocco descrittivo separato, senza IC e fuori dal verdetto; escluse anche
   rinviate, sospese, a tavolino, `abandoned`;
5. `lambda_home`, `lambda_away` finiti, `lambda_i − lambda3 > 0`, `lambda3 = 0.15`.

Una sola riga per partita (cattura append-only; se ve ne fossero più d'una vale la prima per `generated_at`, e il caso va
segnalato). **Nessuna ricostruzione retroattiva**: partite senza prediction official valida restano fuori e si contano come
mancanti. Le `public_latest` e le prediction ricostruite non entrano mai nel campione primario. Se in futuro l'artifact official
cambia (nuova versione o SHA), le nuove righe **non** entrano nel campione primario, che resta quello dello SHA indicato; non si
aggregano versioni diverse.

**Snapshot latent.** Le righe v1.1.0 possono avere `latent_snapshot_generated_at` diversi (04/10, 30/10, successivi). Il
confronto A − B è condizionato ai lambda e **non è indipendente dalla loro qualità**: la forma attesa del totale, e quindi
l'effetto di λ3, dipende dal livello e dalla dispersione dei lambda (anche dal loro bias, E1). Il campione primario unisce gli
snapshot, ma il risultato è riportato **anche per snapshot** (per `latent_snapshot_generated_at`), come descrittivo, per vedere se
le differenze tra snapshot spostano la stima.

### 12.4 Metrica primaria e descrittive
- **Primaria, unica:** **ΔNLL** = media sulle partite del campione di `NLL_A − NLL_B`, con `NLL = −ln P(T = t_obs)`, `T =
  home_goals + away_goals` a fine tempi regolamentari (negativo = A migliore). **IC bilaterale 95%.**
- **Descrittive (nessuna decisione né correzione per confronti multipli):** RPS totale (ordinale, supporto illimitato, non
  diviso per K−1); LogLoss Over 2.5, 3.5, 4.5; LogLoss BTTS Sì (forma chiusa); NLL del risultato esatto; **LogLoss 1X2 completo di
  A e di B** (controllo separato dei compromessi con il modello principale); frequenze osservate e probabilità medie per 0, 1, 2,
  3, 4, 5+ gol; P(pareggio) media vs frequenza; N e Δ medio per competizione. Si riportano valori e differenze A − B con il
  loro IC, senza usarli per concludere.

### 12.5 Analisi, incertezza, interpretazione
- **Una sola analisi primaria alla conclusione del 2026/27:** quando tutte le partite di **campionato regolare** (non di
  spareggio, `is_playoff = 0`) delle cinque leghe core hanno stato finale e i risultati sono stati catturati. Gli spareggi **non
  ritardano l'analisi e non entrano nel campione primario**: se già finiti e con prediction valida compaiono solo nel blocco
  descrittivo separato. La data di analisi si registra nel report. Nessuna valutazione di
  punteggi o differenze durante la stagione:
  i controlli intermedi riguardano solo copertura e qualità dei dati (§12.7). Non esiste `n_min` né un effetto minimo `δ`.
- **Incertezza:** IC 95% percentile con bootstrap a blocchi per settimana ISO del kickoff (tutte le leghe insieme), 2000
  repliche, seed 20261031, come negli Step 27–31. Il bootstrap è ricondotto alla settimana perché le partite degli stessi giorni
  non sono indipendenti.
- **Riferimento per la precisione attesa (non è un calcolo di potenza):** la deviazione standard per partita della differenza
  NLL(T) A − B sul 2025/26 è σ = 0.0652. Con N partite indipendenti la semi-ampiezza dell'IC sarebbe circa 1.96·σ/√N; è solo
  un ordine di grandezza per pianificare la precisione, **non** una potenza calcolata per il bootstrap a blocchi né per un effetto
  specifico. Non vincola l'analisi né definisce numerosità minime.
- **Regole di interpretazione dell'esito primario (ΔNLL, IC 95% bilaterale):**

| Esito | Condizione | Lettura |
|---|---|---|
| **Evidenza a favore di A** | IC interamente `< 0` | La dipendenza BP migliora il totale in questo campione; non è una promozione. |
| **Evidenza a favore di B** | IC interamente `> 0` | I Poisson indipendenti descrivono meglio il totale in questo campione. |
| **Inconcludente** | IC contiene 0 | Nessuna conclusione. **Non si dichiara equivalenza** né assenza di effetto. |

- Le descrittive non cambiano la classificazione. Un eventuale svantaggio di B sul LogLoss 1X2 si riporta come compromesso.
  Qualunque esito richiede un successivo confronto con questo documento prima di modificare λ3, il motore o l'artifact.

### 12.6 Cosa è vietato
Valutare esiti prima del momento prefissato; scegliere o cambiare λ3, metrica primaria, sottogruppi o periodo dopo aver visto
risultati prospettici; ricalcolare i lambda; includere prediction non official o ricostruite; aggregare versioni o SHA diversi;
usare i risultati per scegliere un modello.

### 12.7 Registrazione e copertura
Bastano i **lambda e i metadati official già salvati** nella tabella `predictions`: `id`, `match_id`, `competition_id`,
`model_version`, `artifact_sha256`, `generated_at`, `kickoff_at`, lead time, `lambda_home`, `lambda_away`, `lambda3`,
`latent_snapshot_generated_at`, `structural_snapshot_version`, presenza di `availability_snapshot_json`. L'archivio dei file
completi degli snapshot (es. con `last_match_included_at`) è utile per audit delle feature ma **non è un prerequisito** del
confronto.

Nel report finale (e, per la sola copertura, durante la stagione, senza punteggi):
- **copertura per competizione e per settimana ISO:** partite eleggibili vs con prediction valida; motivi di esclusione
  (nessuna prediction, troppo vicina al kickoff, modello non disponibile, rinviata/abbandonata, spareggio, λ3 ≠ 0.15, riga
  duplicata); gli spareggi non fanno parte del denominatore primario (se ne riporta il numero a parte);
- **numero di blocchi** settimanali del campione e N per blocco;
- **differenze tra snapshot latent:** N e ΔNLL per `latent_snapshot_generated_at`.
Copertura e numero di blocchi non fissano soglie del verdetto: determinano la larghezza dell'IC; se è troppo larga l'esito
è inconcludente. Il campione è selezionato dalla cattura (non casuale); lo si dichiara senza correggerlo.

### 12.8 Congelamento
Il congelamento è registrato in un file separato, `knowledge/GOAL_BP1_FREEZE_MANIFEST.json`, che contiene `t_freeze_utc`
(**timestamp UTC effettivo, preso al momento dell'operazione e mai retrodatato**), il commit base e gli SHA-256 dei file
congelati (questo protocollo, lo script di analisi, l'exporter e il suo test). Per evitare riferimenti circolari: nessun file
congelato contiene hash di sé stesso o di altri file; il manifest non figura nell'elenco degli hash; il congelamento è identificato
dal commit che introduce il manifest. Dopo il congelamento, modifiche solo come emendamenti datati e motivati che non possano
dipendere da risultati prospettici (con un nuovo manifest).

**Componenti congelati**
- **Script di analisi:** `tools/scripts/goal_bp1_prospective_analysis.py`. Formule identiche a `goal_b1_total_goals_bp.py` e
  `goal_b2_joint_dependence_bp.py` (verificate su dati sintetici: differenza massima 0). Legge due export CSV in sola lettura
  (`--predictions`; `--eligible` come denominatore della copertura; colonne nel docstring), applica i filtri di §12.3 con
  `--t-freeze`, calcola la metrica primaria e le descrittive di §12.4, bootstrap, copertura per lega e settimana, analisi per
  snapshot, blocco descrittivo separato per gli spareggi, e stampa gli hash di script, input e campione estratto. `--self-test`
  esegue 48 controlli su dati sintetici. Avvisi tecnici (N o blocchi bassi, denominatore assente) non cambiano il verdetto.
- **Exporter di sola lettura:** `php artisan robetting:export-bp1-sample --out-dir=<dir> [--season-year=2026]` (file
  `app/Console/Commands/ExportBp1Sample.php`). Solo `SELECT`; esporta tutte le righe `official` della stagione (qualunque
  modello o versione, per contare le esclusioni) con risultato e metadati, e il denominatore delle partite core. `is_playoff`
  deriva dall'etichetta di fase `matches.round` (`Regular Season - N` → 0, altra etichetta → 1, mancante → vuoto). Sulle stagioni
  2024/25 e 2025/26 le uniche etichette diverse da `Regular Season - N` sono le 2 partite di spareggio delle leghe 18 e 19
  (`Relegation Round` / `Final`), e nel 2026/27 non ne risultano ancora (verifica sui soli metadati). Il test
  (`ExportBp1SampleCommandTest`) usa dati sintetici e verifica il flusso exporter → script.
- **Esecuzione a fine stagione:** si esegue l'exporter, poi lo script con `--t-freeze` letto dal manifest; i comandi e il
  report (compresi gli hash degli input) si conservano insieme al risultato.

### 12.9 Limiti residui
1. La regola `is_playoff` dipende dalle etichette di fase fornite dal provider: una fase non regolare con etichetta
   `Regular Season - N` sarebbe trattata come regolare, e una regolare con etichetta diversa come spareggio; l'export non le
   corregge e il report mostra quante righe sono state escluse come `playoff` o `invalid_playoff_flag`.
2. Il campione è determinato dalla cattura delle prediction (non casuale) e dalla copertura ottenuta; non si corregge.
3. I timestamp del DB sono letti come UTC.
4. Nessuna soglia di numerosità, `δ`, copertura o blocchi minimi: con pochi dati l'esito sarà inconcludente.
