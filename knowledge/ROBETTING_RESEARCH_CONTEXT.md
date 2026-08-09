# Robetting Research Context

> File di orientamento rapido. Prima di prendere decisioni quantitative su Robetting, leggere questo file e poi aprire la scheda tecnica pertinente.

## 1. Obiettivo scientifico

Robetting deve produrre **previsioni probabilistiche pre-match misurabili e riproducibili**.

Il primo obiettivo non è massimizzare il numero di pronostici indovinati e non è trovare immediatamente value bet. È ottenere probabilità utili e ben valutate.

## 2. Separazione architetturale stabilita

### Prediction Engine

Input: dati calcistici disponibili prima del calcio d'inizio.

Output: probabilità del modello Robetting.

### Odds / Market Engine

Input: quote disponibili e relativo timestamp.

Output: probabilità implicite e, quando possibile, stima delle probabilità di mercato al netto del margine.

### Value Engine

Confronta le probabilità Robetting con quelle di mercato.

Il Prediction Engine deve poter funzionare senza quote bookmaker.

## 3. Principi obbligatori

### 3.1 No data leakage

Per una previsione generata al tempo `T`, possono essere utilizzate soltanto informazioni disponibili a `T`.

Non utilizzare retroattivamente:

- statistiche prodotte dopo il kickoff;
- classifiche finali;
- rating aggiornati con il match da prevedere;
- quote future rispetto al timestamp della prediction;
- aggregati stagionali che includono eventi successivi.

Ogni feature futura dovrà poter essere calcolata **as-of** un timestamp.

### 3.2 Versionare i modelli

Ogni famiglia/modifica rilevante deve avere una versione identificabile, ad esempio:

- `RB-P-001` — Independent Poisson baseline
- `RB-DC-001` — Dixon-Coles baseline
- `RB-BP-001` — Bivariate Poisson baseline

Ogni prediction destinata al backtest deve poter essere collegata alla versione del modello e al cutoff dei dati.

### 3.3 Baseline prima della complessità

Machine learning avanzato non è assunto come superiore.

Percorso iniziale:

1. Independent Poisson
2. Dixon-Coles
3. Bivariate Poisson / alternative count models
4. Rating (Elo e varianti) come modello o feature
5. Feature aggiuntive motivate dai dati
6. ML / ensemble soltanto tramite confronto out-of-sample

### 3.4 Valutare probabilità, non soltanto esiti

Accuracy, hit rate o percentuale di pronostici corretti non sono sufficienti.

Usare almeno metriche probabilistiche e diagnostiche di calibrazione. Vedi `evaluation/probabilistic-evaluation.md`.

### 3.5 Mercato come benchmark

Le probabilità di mercato sono un benchmark importante, ma non devono contaminare una baseline che vogliamo valutare come modello puramente sportivo, salvo esperimenti esplicitamente market-informed.

## 4. Stato iniziale dei modelli

| Modello | Stato | Ruolo previsto |
|---|---|---|
| Independent Poisson | CANDIDATE | baseline minima |
| Dixon-Coles | CANDIDATE | prima baseline calcistica seria |
| Bivariate Poisson | CANDIDATE | confronto sulla dipendenza dei punteggi |
| Elo | CANDIDATE | rating dinamico / feature |
| ML / ensemble | HYPOTHESIS | fase successiva |

Nessuno dei modelli sopra è ancora dichiarato modello di produzione dalla knowledge base.

## 5. Mercati iniziali

Una distribuzione congiunta dei gol può essere trasformata in probabilità coerenti per più mercati, fra cui:

- 1X2;
- Over/Under;
- BTTS;
- correct score;
- team totals;
- handicap, se la rappresentazione è sufficientemente completa.

Questo rende i modelli di score una baseline particolarmente utile per Robetting.

## 6. Dati iniziali utili

Per i modelli score-based di base sono sufficienti almeno:

- data/ora partita;
- competition/season;
- home team;
- away team;
- home goals;
- away goals.

Dati aggiuntivi possono essere introdotti soltanto dopo aver definito come vengono calcolati senza leakage.

Fonti utili già identificate:

- Football-Data.co.uk: risultati, statistiche di match e quote storiche;
- StatsBomb Open Data: event data pubblici per ricerca e sperimentazione;
- dati canonici già importati in Robetting.

## 7. Regole per feature engineering

Non assumere che una feature sia utile perché intuitivamente calcistica.

Esempi da trattare come ipotesi, non come verità:

- ultime 5 partite;
- head-to-head;
- possesso;
- tiri in porta;
- streak W/D/L;
- posizione di classifica.

Ogni feature deve dimostrare valore out-of-sample e non deve introdurre leakage.

## 8. Regola temporale

Preferire validazione temporale (walk-forward / rolling-origin / train-past-test-future) a split casuali quando si valutano modelli pre-match.

## 9. Domande aperte prioritarie

- Quanta storia deve utilizzare un modello?
- Meglio parametri per singola lega o struttura multi-league?
- Come gestire promosse/neopromosse e squadre con pochi dati?
- Quale forma di time decay usare?
- Home advantage globale, per lega, per stagione o dinamico?
- Quanto sono stabili attack/defence strengths nel tempo?
- Come confrontare correttamente modelli con output diversi?
- Qual è il benchmark bookmaker appropriato: opening, timestamped o closing?
- Quale metodo usare per rimuovere il margine dalle quote nei diversi mercati?

## 10. Criterio decisionale Robetting

Una tecnica entra nel sistema perché migliora un obiettivo misurabile o risolve un problema dimostrato, non perché è più sofisticata.

Flusso preferito:

`SOURCE -> KNOWLEDGE NOTE -> HYPOTHESIS -> EXPERIMENT -> OUT-OF-SAMPLE RESULT -> DECISION -> MODEL VERSION`
