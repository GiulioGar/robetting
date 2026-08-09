# Istruzioni per assistenti AI

Questo file definisce come ChatGPT, Claude o altri assistenti devono usare la Robetting Knowledge Base.

## Prima di elaborare decisioni quantitative

1. Leggere `knowledge/ROBETTING_RESEARCH_CONTEXT.md`.
2. Individuare e leggere le schede tematiche pertinenti.
3. Controllare `knowledge/sources/registry.md` prima di attribuire un'affermazione a una fonte.
4. Distinguere chiaramente fra:
   - decisione già stabilita;
   - evidenza della letteratura;
   - inferenza;
   - ipotesi da testare.
5. Non trasformare automaticamente un blog, una libreria software o una singola pubblicazione in una regola del progetto.
6. Non proporre cambiamenti quantitativi importanti senza indicare come confrontarli out-of-sample.
7. Segnalare esplicitamente eventuali rischi di data leakage.
8. Quando si aggiunge una nuova fonte, aggiornare prima `sources/registry.md`, poi la scheda tecnica pertinente.

## Per Claude Code

Nel `CLAUDE.md` della root del repository è consigliato inserire:

```text
ROBETTING RESEARCH RULE
Before making or implementing decisions concerning football prediction models,
feature engineering, probability estimation, backtesting, calibration, betting
markets or value calculations, read knowledge/ROBETTING_RESEARCH_CONTEXT.md.
Then read the relevant topic files under knowledge/. Treat the knowledge base as
project context, but distinguish established project decisions from hypotheses
and external evidence. Do not override an established quantitative decision
silently: explain the reason and propose an experiment or supporting evidence.
```

## Per ChatGPT

Quando il repository è accessibile tramite GitHub o i file `knowledge/` sono caricati nella File Library, usare questi file come prima fonte di contesto scientifico del progetto.
