# Snippet da aggiungere al CLAUDE.md di Robetting

Copia il blocco seguente nel `CLAUDE.md` della root del repository.

```text
## Robetting quantitative research context

Before making or implementing decisions concerning football prediction models,
feature engineering, probability estimation, backtesting, calibration, betting
markets, odds conversion or value calculations:

1. Read `knowledge/ROBETTING_RESEARCH_CONTEXT.md`.
2. Read the relevant files under `knowledge/models`, `knowledge/evaluation`,
   `knowledge/betting`, `knowledge/simulation` and `knowledge/sources`.
3. Distinguish established Robetting decisions from literature evidence,
   implementation references, inference and hypotheses.
4. Never introduce data leakage. Every pre-match feature must be reproducible
   using only information available before the prediction timestamp.
5. Do not claim that a more complex model is better without an out-of-sample
   experiment against the current baseline.
6. Do not silently override an established quantitative decision. Explain why,
   cite the supporting knowledge/source and propose the experiment needed.
7. When new research materially changes project knowledge, update the relevant
   `knowledge/` file and source registry as part of the change.
```
