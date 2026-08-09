# Source Registry

Registro delle fonti accettate nella knowledge base.

Classificazione:

- **PRIMARY** — paper originale / pubblicazione accademica / dataset ufficiale
- **PRACTICAL** — documentazione o implementazione utile
- **SECONDARY** — spiegazione tecnica non primaria

Le fonti PRACTICAL e SECONDARY non devono sostituire le fonti PRIMARY quando si implementa una formula o si attribuisce un risultato scientifico.

---

## SRC-001 — Maher 1982

**Class:** PRIMARY  
**Topic:** Independent/Bivariate Poisson, team attack/defence strengths  
**Citation:** Michael J. Maher (1982), *Modelling association football scores*, Statistica Neerlandica 36(3), 109-118.  
**Record:** https://ideas.repec.org/a/bla/stanee/v36y1982i3p109-118.html  
**Accessible copy located during research:** https://www.90minut.pl/misc/maher.pdf  
**Used by:** `models/poisson.md`, `models/bivariate-poisson.md`

Evidence noted: independent Poisson gives a reasonably accurate description in the studied data; the paper also discusses improvement through a bivariate model.

---

## SRC-002 — Dixon & Coles 1997

**Class:** PRIMARY  
**Topic:** Poisson regression, low-score dependence, dynamic team performance, betting market  
**Citation:** Mark J. Dixon & Stuart G. Coles (1997), *Modelling Association Football Scores and Inefficiencies in the Football Betting Market*, Journal of the Royal Statistical Society Series C, 46, 265-280.  
**Publisher:** https://academic.oup.com/jrsssc/article-abstract/46/2/265/6990546  
**Institutional record:** https://research-information.bris.ac.uk/en/publications/modelling-association-football-scores-and-inefficiencies-in-the-f/  
**Used by:** `models/dixon-coles.md`

---

## SRC-003 — Karlis & Ntzoufras 2003

**Class:** PRIMARY  
**Topic:** Bivariate Poisson for sports scores  
**Citation:** Dimitris Karlis & Ioannis Ntzoufras (2003), *Analysis of Sports Data by Using Bivariate Poisson Models*.  
**Accessible author-hosted PDF:** https://www2.stat-athens.aueb.gr/~jbn/papers2/08_Karlis_Ntzoufras_2003_RSSD.pdf  
**JSTOR record:** https://www.jstor.org/stable/4128211  
**Used by:** `models/bivariate-poisson.md`

---

## SRC-004 — Hvattum & Arntzen 2010

**Class:** PRIMARY  
**Topic:** Elo ratings for football prediction  
**Citation:** Lars Magnus Hvattum & Halvard Arntzen (2010), *Using ELO ratings for match result prediction in association football*, International Journal of Forecasting, 26(3), 460-470/471 depending on bibliographic record.  
**Publisher:** https://www.sciencedirect.com/science/article/pii/S0169207009001708  
**Used by:** `models/elo.md`

Note: verify exact final page range from the published PDF before formal bibliography export.

---

## SRC-005 — Gneiting & Raftery 2007

**Class:** PRIMARY  
**Topic:** Proper scoring rules, probabilistic forecast evaluation  
**Citation:** Tilmann Gneiting & Adrian E. Raftery (2007), *Strictly Proper Scoring Rules, Prediction, and Estimation*, Journal of the American Statistical Association, 102, 359-378.  
**University-hosted PDF:** https://www.stat.washington.edu/raftery/Research/PDF/Gneiting2007jasa.pdf  
**University record:** https://stat.uw.edu/research/preprints/tech-report/strictly-proper-scoring-rules-prediction-and-estimation  
**Used by:** `evaluation/probabilistic-evaluation.md`

---

## SRC-006 — StatsBomb Open Data

**Class:** PRIMARY DATASET  
**Topic:** football event data  
**Official/current repository located during research:** https://github.com/hudl/open-data  
**Used by:** future xG/event-data research

The repository states that the open data is shared to encourage football research and analysis. Check its license/terms before redistributing or using data beyond research workflows.

---

## SRC-007 — Football-Data.co.uk

**Class:** PRIMARY DATA SOURCE / PRACTICAL  
**Topic:** historical results, match statistics, betting odds  
**Official data page:** https://www.football-data.co.uk/data.php  
**Used by:** historical baseline/backtest and market benchmark research

At the time of verification (August 2026), the site describes computer-ready historical results, match statistics and betting odds data for betting-system development and analysis.

---

## SRC-008 — penaltyblog

**Class:** PRACTICAL  
**Topic:** football score models, Dixon-Coles, Poisson, Bivariate Poisson, ratings, betting tools  
**Documentation:** https://docs.pena.lt/y/models/index.html  
**Dixon-Coles docs:** https://docs.pena.lt/y/models/dixon_coles.html  
**Repository:** https://github.com/martineastwood/penaltyblog  
**Used by:** implementation cross-checks

Do not cite penaltyblog as the scientific source for Dixon-Coles/Maher/Karlis when the original paper is available.

---

## Candidate sources to add after deeper review

- dynamic football strength models;
- Bayesian football prediction models;
- expected-goals literature;
- calibration-specific football papers;
- de-vig methodology literature;
- favourite-longshot bias literature;
- closing-line / market-efficiency literature;
- ML vs statistical model comparisons.
