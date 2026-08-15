# ROBETTING — Piano operativo di sviluppo

## Obiettivo generale

Robetting deve diventare una piattaforma multi-campionato che raccoglie e normalizza dati calcistici, calcola statistiche e trend, genera in futuro probabilità pre-match e confronta tali probabilità con le quote di mercato.

Principi:
- multi-source;
- multi-campionato;
- niente logica hardcoded sulla Serie A;
- sviluppo per piccoli vertical slice;
- backend e frontend avanzano insieme;
- grafica definitiva solo dopo aver validato struttura e contenuti;
- pronostici come probabilità/tendenze, non verdetti assoluti;
- prediction engine separato dal value/odds engine;
- niente live prediction nella prima versione; solo eventuale aggiornamento risultato live;
- ogni prediction futura deve essere versionata e storicizzata;
- nessun data leakage: ogni analisi pre-match usa solo dati disponibili prima del kickoff.

---

# FASE 0 — Stato attuale

Già completato:
- Laravel 13 / PHP 8.4;
- database canonico;
- countries;
- competitions;
- seasons;
- teams;
- matches;
- data_sources;
- mapping external IDs;
- CanonicalMatchResolver multi-source;
- FootballData.org importer;
- Football-Data.co.uk importer;
- match_statistics multi-source;
- Serie A 2025/26 completa;
- Serie A 2026/27 fixture complete;
- matchday 1–38;
- Competition Overview;
- selezione stagione;
- giornata rilevante;
- classifica league;
- fasce classifica storicizzate per stagione e modificabili;
- statistiche competizione di base;
- trend mercati in corso di sviluppo.

---

# FASE 1 — Chiudere Analytics Competition

## 1.1 Trend mercati
Completare e validare:
- 1X2: casa / pareggio / trasferta;
- GG / NG;
- Over/Under FT:
  - 0.5
  - 1.5
  - 2.5
  - 3.5
  - 4.5
- Over/Under HT:
  - 0.5
  - 1.5
  - 2.5
- GG/NG HT;
- count + total + percentuale;
- denominatori FT e HT separati;
- gestione null / zero coverage;
- sanity check.

## 1.2 Statistiche tecniche
Validare nella Competition Overview:
- media tiri;
- media tiri in porta;
- media corner;
- media falli;
- media gialli;
- media rossi;
- coverage per metrica;
- leader:
  - più tiri medi;
  - più tiri in porta medi;
  - più corner medi;
  - meno tiri subiti medi.

## Gate di uscita
La Competition Overview Serie A 2025/26 deve mostrare correttamente:
- calendario/risultati;
- classifica;
- fasce;
- statistiche generali;
- statistiche tecniche;
- trend mercati.

La Serie A 2026/27 deve gestire correttamente l'assenza di statistiche future.

---

# FASE 2 — Test multi-campionato precoce

Prima di sviluppare Team e Match Page in profondità, importare un secondo campionato.

## Campionato pilota
Premier League.

## Ordine
1. verificare supporto FDO;
2. import stagione corrente;
3. import almeno una stagione storica;
4. import FDCUK storico/statistiche se disponibile;
5. mapping team;
6. cross-source linking;
7. verifica classifica;
8. verifica calendario;
9. verifica statistics;
10. verifica trend mercati.

## Gate di uscita
La stessa Competition Overview deve funzionare su:
- Serie A;
- Premier League;

senza:
- if specifici per campionato;
- ID hardcoded;
- source_id hardcoded;
- formule duplicate.

Eventuali differenze regolamentari devono essere configurazione/dati, non condizioni sparse nel codice.

---

# FASE 3 — Team Analytics

Creare un layer analytics riutilizzabile per squadra.

## Contesti obbligatori
Per una squadra:
- intera stagione;
- casa;
- trasferta;
- ultime 5;
- ultime 10.

## Metriche
Risultati:
- PG;
- V/N/P;
- GF/GS;
- medie;
- forma.

Tecniche:
- tiri fatti/subiti;
- tiri in porta fatti/subiti;
- corner fatti/subiti;
- falli;
- cartellini.

Trend:
- 1X2;
- GG/NG;
- Over/Under FT;
- Over/Under HT;
- GG HT.

## Regola architetturale
Riutilizzare i calculator esistenti passando Collection diverse.
Non creare formule diverse per Competition Page, Team Page e Match Page.

---

# FASE 4 — Team Page minimale

Route concettuale:
`/teams/{team}`

Contenuti iniziali:
- nome squadra;
- stagione;
- competizione selezionata;
- prossima partita;
- ultime partite;
- forma;
- statistiche stagione;
- split casa/trasferta;
- ultime 5 / ultime 10;
- trend mercati;
- elenco competizioni della stagione.

Non ancora:
- rosa;
- giocatori;
- statistiche individuali.

## Gate di uscita
Testare almeno:
- una squadra Serie A;
- una squadra Premier League.

---

# FASE 5 — Match Page

Route concettuale:
`/matches/{match}`

## Match terminato
Mostrare:
- risultato;
- HT/FT;
- statistiche tecniche;
- contesto squadre;
- trend pre-match storici;
- in futuro prediction storicizzata.

## Match futuro
Mostrare:
- data/ora;
- forma delle due squadre;
- home/away split;
- ultime 5/10;
- GF/GS;
- tiri;
- corner;
- cartellini;
- trend 1X2 / GG-NG / Over-Under;
- confronto tra i profili delle due squadre.

## Obiettivo
Questa pagina diventerà il contenitore principale delle future probabilità Robetting.

---

# FASE 6 — Portare le 5 leghe principali

Perimetro iniziale:
- Serie A;
- Premier League;
- La Liga;
- Bundesliga;
- Ligue 1.

Per ogni lega:
- stagione corrente;
- almeno 2–3 stagioni storiche iniziali;
- risultati;
- fixture;
- matchday;
- statistiche disponibili;
- mapping multi-source;
- competition zones storiche;
- verifica Competition Page;
- verifica Team Page;
- verifica Match Page.

Non è necessario aspettare il dataset storico completo prima di continuare lo sviluppo, ma il prediction engine non deve partire seriamente con una sola stagione.

---

# FASE 7 — Homepage

Costruire solo quando abbiamo fixture correnti affidabili per più competizioni.

Contenuti:
- partite del giorno;
- raggruppamento per competizione;
- ordine per kickoff;
- risultato/status se in corso;
- link Competition;
- link Team;
- link Match;
- in futuro probabilità Robetting sintetiche.

La homepage non deve calcolare prediction on-demand.

---

# FASE 8 — Coppe e competizioni non-league

Prima di importare seriamente:
- Champions League;
- Europa League;
- Conference League;
- coppe nazionali;

progettare:
- competition_stage;
- league/group/knockout;
- round;
- eventuale tie;
- leg 1 / leg 2;
- aggregato;
- extra time;
- penalties.

Regola:
nessun codice deve assumere che ogni competition abbia 38 giornate o una classifica unica.

---

# FASE 9 — Players / rose

Prima scegliere una fonte affidabile.

Dominio futuro:
- players;
- squad memberships per stagione;
- ruoli;
- numeri maglia;
- presenze;
- gol;
- assist;
- statistiche individuali.

Solo dopo:
- rosa nella Team Page;
- capocannoniere nella Competition Page;
- player impact nelle analisi.

---

# FASE 10 — Analytics Layer pre-match

Costruire feature calcolabili "as of" una data.

Possibili feature:
- Elo / team strength;
- forma;
- home/away strength;
- GF/GS;
- tiri;
- tiri in porta;
- xG quando disponibile;
- strength degli avversari affrontati;
- giorni di riposo;
- trend GG/NG;
- trend O/U;
- ranking relativo;
- momentum;
- eventuali dati giocatori.

Ogni feature deve poter essere ricostruita senza usare dati successivi al match analizzato.

---

# FASE 11 — Prediction Engine baseline

Prima versione:
- 1X2;
- Over/Under 2.5;
- GG/NG.

Approccio:
1. baseline statistica;
2. Poisson / rating;
3. backtest;
4. calibration;
5. confronto modelli;
6. solo dopo ML/ensemble se migliora davvero.

Output:
- probabilità, non verdetti;
- model_version;
- generated_at;
- data_cutoff_at;
- prediction salvata nel DB.

---

# FASE 12 — Odds e Value Engine

Separare:
- probabilità Robetting;
- probabilità implicita bookmaker;
- margine bookmaker;
- edge;
- value.

Mercati generici:
- market;
- selection;
- odds;
- prediction probability;
- implied probability;
- edge.

Metriche future:
- ROI teorico;
- yield;
- closing line value;
- performance per fascia di edge;
- performance per campionato/mercato/modello.

---

# FASE 13 — Performance Robetting

Robetting deve misurare se stesso.

Dashboard interna/pubblica futura:
- calibration;
- accuracy;
- Brier score / log loss dove opportuno;
- risultati per mercato;
- risultati per lega;
- risultati per model version;
- ROI/yield quando presenti quote.

Mai modificare retroattivamente una prediction storica.

---

# FASE 14 — Live score

Prima versione:
nessun live prediction.

Eventuale aggiornamento:
- current_home_score;
- current_away_score;
- current_minute;
- current_period;
- live_updated_at;

solo dopo aver scelto una fonte live adeguata.

I campi FT non devono essere usati per score parziali.

---

# FASE 15 — Articoli editoriali

Workflow semplice:
- Robetting genera un Analysis Packet;
- dati partita;
- forma;
- statistiche;
- trend;
- prediction;
- value eventuale.

Il pacchetto viene usato per creare manualmente l'articolo.
L'editoria resta separata dal motore quantitativo.

---

# FASE 16 — Layout e UX definitiva

Non prima di avere almeno:
- Competition Page;
- Team Page;
- Match Page;
- due campionati funzionanti.

Poi definire:
- navigazione;
- gerarchia card;
- mobile;
- colori;
- componenti condivisi;
- grafici;
- filtri;
- layout homepage.

Principio:
prima struttura informativa corretta, poi design definitivo.

---

# Prossime azioni immediate

1. Chiudere e validare `CompetitionMarketTrendsCalculator`.
2. Committare il blocco trend.
3. Importare Premier League come secondo campionato pilota.
4. Verificare la Competition Overview sulla Premier.
5. Correggere eventuali assunzioni Serie A-specifiche.
6. Iniziare Team Analytics.
7. Costruire Team Page.
8. Costruire Match Page.
9. Estendere gradualmente alle altre 3 leghe principali.
10. Solo dopo rifinire layout/UX.

---

# Regole operative per ogni step

Ogni intervento deve:
1. avere un obiettivo singolo e chiaro;
2. evitare refactor non richiesti;
3. essere multi-campionato;
4. evitare hardcode di source/id/league;
5. usare dati canonici;
6. rispettare null e data coverage;
7. evitare N+1;
8. prevedere dry-run quando si importano dati;
9. verificare idempotenza degli importer;
10. fare commit separati e leggibili;
11. non includere file sensibili;
12. essere verificato visivamente quando produce output frontend.

Fine documento.
