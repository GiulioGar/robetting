# Quote, probabilità implicite e benchmark di mercato

**Stato Robetting:** ESTABLISHED per la separazione Prediction / Market / Value

## Probabilità implicita grezza

Per quota decimale `o`:

`q = 1 / o`

Questa non è automaticamente una probabilità fair perché le quote possono incorporare margine e altre caratteristiche di pricing.

## Overround

Per un mercato con esiti mutuamente esclusivi:

`overround = sum_i(1 / o_i) - 1`

Se la somma delle probabilità implicite supera 1, la differenza è una misura semplice dell'overround.

## Normalizzazione proporzionale

Una prima baseline per rimuovere il margine è:

`p_i = q_i / sum_j(q_j)`

Questa è soltanto UNA metodologia e implica un'allocazione proporzionale del margine. Non deve essere trattata come unico metodo corretto.

## Robetting: dati da salvare

Quando possibile conservare:

- bookmaker/source;
- market;
- selection;
- decimal odds;
- observed_at;
- opening/closing flag se disponibile;
- eventuale exchange vs sportsbook;
- metodo di de-vig usato in analisi.

## Benchmark

Il mercato deve essere confrontato con il Prediction Engine usando lo stesso istante informativo.

Esempio scorretto:

- prediction Robetting 24h prima;
- benchmark con closing odds registrate pochi secondi prima del kickoff.

Questo confronto può essere interessante, ma misura informazioni differenti e deve essere etichettato come tale.

## Value / Expected Value

Per probabilità del modello `p` e quota decimale `o`, ignorando per semplicità commissioni e altri costi:

`EV_per_unit = p * o - 1`

Questo valore è significativo soltanto se `p` è una stima affidabile e la quota è realmente disponibile nelle condizioni considerate.

## Regola Robetting

Non ottimizzare il Prediction Engine direttamente sul ROI senza un esperimento esplicitamente definito. La qualità probabilistica e la strategia di betting sono problemi collegati ma distinti.

## Fonte dati pratica iniziale

Football-Data.co.uk distribuisce storicamente risultati, statistiche di match e betting odds in formati computer-ready. Vedi `../sources/registry.md`.
