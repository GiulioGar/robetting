# Robetting Knowledge Base

Questa cartella contiene la knowledge base tecnica e scientifica condivisa di Robetting.

Obiettivo: mantenere in un unico punto fonti, modelli, decisioni metodologiche, ipotesi di ricerca ed esperimenti, in modo che ChatGPT, Claude e chi lavora sul repository possano usare lo stesso contesto.

## Struttura

```text
knowledge/
├── README.md
├── ROBETTING_RESEARCH_CONTEXT.md
├── AI_USAGE.md
├── models/
│   ├── poisson.md
│   ├── dixon-coles.md
│   └── bivariate-poisson.md
├── team-strength/
│   ├── elo.md
│   └── time-decay-dynamic-strength.md
├── features/
│   └── expected-goals.md
├── evaluation/
│   ├── probabilistic-evaluation.md
│   └── rps.md
├── betting/
│   └── market-probabilities.md
├── simulation/
├── sources/
└── templates/
```

## Principi di utilizzo

1. Le fonti originali hanno priorità sulle sintesi.
2. Le decisioni di Robetting devono essere separate dalle conclusioni dei paper.
3. Nessun modello è considerato "migliore" senza backtest cronologico out-of-sample.
4. Vietato usare dati futuri rispetto al kickoff (`data leakage`).
5. Prediction Engine, Market Engine e Value Engine restano separati.
6. Le probabilità vengono valutate con scoring rules e calibrazione, non solo con hit rate.
7. Ogni modello, feature e trasformazione di mercato deve essere versionata.
8. Le ipotesi restano ipotesi finché non vengono testate.

## Stato attuale della ricerca

### Goal models

| ID | Modello | Stato |
|---|---|---|
| RB-P-001 | Independent Poisson / Maher | Baseline obbligatoria |
| RB-DC-001 | Dixon-Coles | Candidato principale |
| RB-BP-001 | Bivariate Poisson | Research candidate |

### Team strength

| ID | Modello / concetto | Stato |
|---|---|---|
| RB-ELO-001 | Elo | Research candidate |
| RB-TIME-001 | Time decay / dynamic strength | Principio metodologico |

### Features

| ID | Feature / modello | Stato |
|---|---|---|
| RB-XG-RESEARCH-001 | Expected Goals | Future analytics / feature layer |

### Evaluation

Metriche attualmente adottate:

```text
PRIMARY
- Log Loss
- Brier Score
- Calibration

SECONDARY 1X2
- Ranked Probability Score (RPS)
```

### Market probabilities

Baseline iniziale:

```text
MARKET-MULT-001
```

Pipeline:

```text
decimal odds
→ inverse odds
→ booksum
→ de-vig
→ fair market probabilities
```

La normalizzazione moltiplicativa è solo il baseline; Shin, Power e altri metodi restano da confrontare.

## Ordine di sviluppo raccomandato

```text
1. RB-P-001
2. RB-DC-001
3. RB-BP-001
4. Elo research track
5. Time-decay experiments
6. xG research
7. Market benchmark
8. Value Engine
```

## Regola per nuove fonti

Ogni nuova fonte deve essere classificata come:

```text
PRIMARY
paper / fonte accademica originale

SECONDARY
documentazione tecnica autorevole

PRACTICAL
blog / libreria / implementazione

UNVERIFIED
idea da verificare
```

Una fonte non diventa automaticamente una decisione di progetto.

## Regola per gli esperimenti

Ogni esperimento dovrebbe avere almeno:

```text
experiment_id
hypothesis
dataset
data_cutoff policy
model versions
metrics
results
decision
```

## Fonte canonica

La cartella `knowledge/` nel repository Git è la fonte canonica.

Claude dovrebbe leggerla direttamente dal repository.
ChatGPT dovrebbe consultarla dal repository GitHub o dai file caricati quando necessario.
