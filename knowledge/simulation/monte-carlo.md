# Monte Carlo Simulation

**Stato Robetting:** CANDIDATE — strumento derivato, non prediction model autonomo

## Idea

Una volta che il modello fornisce una distribuzione probabilistica per un match, è possibile campionare ripetutamente da tale distribuzione.

La simulazione non migliora automaticamente le probabilità di base: propaga il modello sottostante verso quantità più complesse.

## Match simulation

Se abbiamo una distribuzione congiunta del punteggio, possiamo simulare N match e stimare per frequenza:

- 1X2;
- totals;
- BTTS;
- scoreline distribution.

Per mercati semplici, quando le probabilità sono ottenibili analiticamente dalla matrice, Monte Carlo non è necessario salvo verifica o scenari complessi.

## Season / competition simulation

Utilità maggiore:

1. ottenere distribuzioni di risultato per le fixture future;
2. simulare tutte le partite rimanenti;
3. aggiornare classifica in ogni simulazione;
4. ripetere migliaia di volte;
5. derivare probabilità di titolo, qualificazione, retrocessione, posizione finale.

## Requisiti

- modello pre-match coerente per tutte le fixture;
- calendario disponibile;
- regole esatte della competizione e tie-breaker;
- gestione dei possibili cambiamenti di forza nel tempo, se il modello li prevede.

## Rischio importante

Se ogni fixture futura viene simulata con forze congelate alla data corrente, l'interpretazione deve essere esplicita. Una simulazione dinamica che aggiorna rating/forze dopo i risultati simulati rappresenta un modello differente.

## Esperimento futuro

`EXP-XXX-SEASON-MONTE-CARLO`

Confrontare:

- probabilità di posizione ottenute da forza congelata;
- simulazione con rating dinamico;
- accuratezza/calibrazione storica delle probabilità di obiettivi stagionali, se il campione lo consente.
