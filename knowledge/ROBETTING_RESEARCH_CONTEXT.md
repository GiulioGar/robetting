# ROBETTING RESEARCH CONTEXT

## 1. Obiettivo scientifico

Robetting deve produrre probabilità pre-match riproducibili, calibrate e misurabili.

Il sistema non deve limitarsi a generare etichette come:

```text
HOME
OVER
BTTS YES
```

ma deve produrre probabilità esplicite per i mercati analizzati.

## 2. Architettura metodologica

Separazione obbligatoria:

```text
PREDICTION ENGINE
→ probabilità Robetting

MARKET ENGINE
→ quote
→ implied probabilities
→ de-vig
→ fair market probabilities

VALUE ENGINE
→ confronto tra probabilità Robetting e prezzo
```

Il Prediction Engine non deve dipendere obbligatoriamente dalle quote.

## 3. Principi consolidati

### No data leakage

Per una prediction generata al tempo `T`, il modello può utilizzare solo dati disponibili prima di `T`.

Ogni prediction deve avere:

```text
generated_at
data_cutoff_at
model_version
```

Le prediction storiche non devono essere ricalcolate con versioni successive del modello.

### Probabilità prima dell'accuracy

La qualità principale del modello va valutata tramite:

```text
Log Loss
Brier Score
Calibration
```

Per 1X2 si aggiunge:

```text
RPS
```

come metrica secondaria.

L'hit rate non è una metrica primaria.

### Backtest temporale

Evitare random train/test split per match forecasting.

Usare:

```text
walk-forward
rolling-origin
chronological holdout
```

### Modelli versionati

Esempi:

```text
RB-P-001
RB-DC-001
RB-BP-001
RB-ELO-001
```

Ogni modifica significativa crea una nuova versione o un nuovo esperimento.

## 4. Goal models

### RB-P-001 — Independent Poisson

Fonte principale:

```text
Maher (1982)
```

Ruolo:

```text
baseline statistica obbligatoria
```

Concetti:

```text
attack strength
defence strength
home effect
lambda_home
lambda_away
score probability matrix
```

Mercati derivabili:

```text
1X2
Over/Under
BTTS
Correct Score
```

Non include nella prima versione:

```text
xG
shots
odds
Elo
H2H
ML features
```

### RB-DC-001 — Dixon-Coles

Fonte principale:

```text
Dixon & Coles (1997)
```

Estende il Poisson con:

```text
low-score correction
rho
time weighting
```

La correzione riguarda principalmente:

```text
0-0
0-1
1-0
1-1
```

Stato:

```text
candidate baseline model
```

Non ancora production model.

### RB-BP-001 — Bivariate Poisson

Fonte principale:

```text
Karlis & Ntzoufras (2003)
```

Introduce:

```text
shared latent Poisson component
positive score dependence
lambda3
```

Ruolo:

```text
research comparison model
```

Da implementare dopo Poisson e Dixon-Coles.

## 5. Team strength

### RB-ELO-001 — Elo

Fonte principale:

```text
Hvattum & Arntzen (2010)
```

Ruolo:

```text
dynamic team-strength representation
```

Non è un goal model.

La differenza Elo può essere usata come covariata in un modello probabilistico.

Open questions:

```text
K factor
home advantage
season carry-over
promoted-team initialization
goal-difference weighting
```

### RB-TIME-001 — Time decay / dynamic strength

Fonti principali:

```text
Dixon & Coles (1997)
Crowder et al. (2002)
Ley et al.
```

Principio adottato:

```text
TEAM STRENGTH IS TIME-DEPENDENT
```

Ma:

```text
NOT ALL MODELS MUST USE THE SAME DECAY
```

Per Poisson/Dixon-Coles:

```text
exponential decay = candidate
```

Per Elo:

```text
K-driven update = primary recency mechanism
```

Per future xG features:

```text
rolling / exponential aggregation = candidate
```

## 6. Expected Goals

Fonte:

```text
peer-reviewed xG research
StatsBomb/Hudl documentation
```

Principio:

```text
xG is a shot-level probability model
```

Quindi:

```text
shots != xG
shots on target != xG
```

Ruolo attuale:

```text
future analytics / feature layer
```

Non requisito per i primi goal models.

Possibile research baseline:

```text
RB-XG-LR-001
```

con logistic regression e shot-level event data.

StatsBomb Open Data è considerato:

```text
research/prototyping source
```

non automaticamente production source.

## 7. Evaluation

Fonti principali:

```text
Gneiting & Raftery (2007)
Constantinou & Fenton
Wheatcroft
```

Metriche standard:

```text
PRIMARY:
Log Loss
Brier Score
Calibration

SECONDARY 1X2:
RPS
```

RPS non viene considerato "migliore" in senso assoluto.

I modelli devono essere confrontati sullo stesso sample out-of-sample.

## 8. Market probabilities

Le quote non sono probabilità fair.

Pipeline:

```text
odds
→ 1 / odds
→ raw implied probabilities
→ booksum / overround
→ de-vig
→ estimated fair market probabilities
```

Baseline:

```text
MARKET-MULT-001
```

Metodo:

```text
multiplicative normalization
```

Alternative da testare:

```text
Additive
Shin
Power
```

Principio:

```text
de-vig probability
!=
true probability
```

È una stima.

Il timestamp delle quote deve essere conservato.

Confrontare quando possibile:

```text
same-time market benchmark
```

separatamente da:

```text
closing market benchmark
```

## 9. Value Engine

Solo dopo la validazione probabilistica.

Definizioni:

```text
probability edge
=
P_RB - P_market
```

Expected Value:

```text
EV
=
P_RB * decimal_odds - 1
```

Un EV positivo non dimostra automaticamente una reale opportunità.

Dipende dalla qualità della probability estimate.

## 10. Ordine degli esperimenti

Sequenza corrente:

```text
EXP-P-001
Independent Poisson baseline

EXP-TIME-001
historical window / time decay

EXP-DC-001
Dixon-Coles vs Poisson

EXP-DC-TIME-001
Dixon-Coles decay calibration

EXP-BP-001
Bivariate Poisson

EXP-ELO-001
Elo

EXP-XG-001
shot-level xG prototype

EXP-XG-TEAM-001
xG features for pre-match modelling

EXP-MKT-DEVIG-001
market de-vig comparison
```

## 11. Domande aperte principali

- Quanti anni di storico usare?
- Qual è la half-life ottimale per lega?
- Un modello per lega o multi-league?
- Come inizializzare le neopromosse?
- Home advantage globale, per lega o dinamico?
- Come trattare i cambi di stagione?
- Quanto valore aggiunge Elo a Dixon-Coles?
- Quanto valore aggiunge xG rispetto ai gol?
- Quale metodo de-vig è meglio calibrato?
- Quanto il mercato closing è superiore ai modelli Robetting?
- Quali miglioramenti sono stabili across seasons?

## 12. Regola decisionale

Non promuovere un modello perché:

```text
ha preso più partite
```

o:

```text
ha avuto ROI positivo in un singolo periodo
```

Una promozione richiede evidenza out-of-sample su:

```text
Log Loss
Brier
Calibration
stabilità temporale
```

e solo successivamente valutazione economica.

## 13. Fonte canonica

I dettagli completi sono nei file:

```text
knowledge/models/
knowledge/team-strength/
knowledge/features/
knowledge/evaluation/
knowledge/betting/
```

Questo file deve restare sintetico e fungere da mappa per ChatGPT, Claude e sviluppatori.
