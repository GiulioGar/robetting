# Robetting Knowledge Base

Questa cartella è la knowledge base scientifica e tecnica condivisa del progetto Robetting.

## Scopo

Deve essere consultata da sviluppatori e assistenti AI prima di prendere decisioni su:

- modelli di previsione calcistica;
- feature engineering;
- stima delle probabilità;
- backtesting;
- calibrazione;
- quote e probabilità implicite;
- value betting;
- simulazioni.

Non è documentazione Laravel e non viene letta automaticamente dall'applicazione.
È conoscenza di progetto versionata insieme al codice.

## Ordine di lettura per AI

1. `ROBETTING_RESEARCH_CONTEXT.md`
2. Il file tematico pertinente (`models/`, `evaluation/`, `betting/`, ecc.)
3. `sources/registry.md` per verificare le fonti
4. Gli eventuali esperimenti in `experiments/`

## Regola fondamentale

Le note di questa knowledge base NON trasformano una fonte in una verità assoluta. Distinguere sempre:

- **ESTABLISHED**: decisione già adottata nel progetto;
- **EVIDENCE**: risultato sostenuto da fonti;
- **CANDIDATE**: approccio promettente ma da confrontare;
- **HYPOTHESIS**: ipotesi da testare;
- **REJECTED**: approccio testato e scartato.

Una modifica importante a un modello deve essere accompagnata, quando possibile, da un esperimento riproducibile.

## Struttura iniziale

- `models/poisson.md`
- `models/dixon-coles.md`
- `models/bivariate-poisson.md`
- `models/elo.md`
- `evaluation/probabilistic-evaluation.md`
- `betting/market-probabilities.md`
- `simulation/monte-carlo.md`
- `sources/registry.md`
- `templates/source-note.md`
- `templates/experiment.md`

Questa base crescerà soltanto quando nuove fonti o nuovi esperimenti aggiungeranno informazione utile a Robetting.
