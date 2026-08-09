# Time Decay and Dynamic Team Strength

## Status

**Source status:** supported by primary football-modelling literature  
**Robetting status:** foundational temporal-modelling principle  
**Suggested research id:** `RB-TIME-001`  
**Decision:** recency must be treated explicitly, but the decay mechanism must be model-specific and empirically validated. Robetting must not impose one universal time-decay rule across Poisson, Dixon–Coles, Elo and xG features without testing.

---

## Key sources

### Dixon & Coles (1997)

Dixon, M. J. & Coles, S. G.  
*Modelling Association Football Scores and Inefficiencies in the Football Betting Market.*

The model recognizes that team attacking and defensive strengths should not be assumed constant forever and uses a tapered likelihood that gives greater weight to recent matches.

Official journal record:
https://academic.oup.com/jrsssc/article-abstract/46/2/265/6990546

### Crowder, Dixon, Ledford & Robinson (2002)

*Dynamic modelling and prediction of English Football League matches for betting.*

Journal of the Royal Statistical Society: Series D (The Statistician), 51(2), 157–168.  
DOI: `10.1111/1467-9884.00308`

Official journal record:
https://academic.oup.com/jrsssd/article/51/2/157/7120674

This paper moves beyond simple historical down-weighting and models team strengths explicitly as stochastic processes evolving through time.

### Ley, Van de Wiele & Van Eetvelde (2019)

*Ranking soccer teams on basis of their current strength: a comparison of maximum likelihood approaches.*

This work compares several football strength models using weighted maximum likelihood with both match-importance and time-depreciation factors.

Preprint:
https://arxiv.org/abs/1705.09575

---

## 1. Why time matters

A football team is not a static object.

Its effective strength can change because of:

```text
player transfers
injuries
manager changes
tactical changes
promotion/relegation
fixture congestion
ageing
club investment
season transitions
```

Therefore:

```text
Inter today
!=
Inter two seasons ago
```

A model that treats every historical match equally implicitly assumes a level of stability that may not exist.

Robetting must therefore distinguish:

```text
historical information
```

from:

```text
current team strength
```

---

## 2. Two fundamentally different ways to handle time

There are two broad approaches.

### A. Time-decayed historical estimation

Older matches remain in the dataset but contribute less.

Conceptually:

```text
recent match
→ high weight

old match
→ lower weight
```

This is the Dixon–Coles approach.

### B. Explicit dynamic latent strength

Team-strength parameters themselves evolve through time according to a stochastic process.

Conceptually:

```text
strength_t
depends on
strength_(t-1)
+
new information / innovation
```

This is the direction taken by Crowder et al.

These approaches should not be confused.

---

## 3. Exponential time decay

A common decay function is:

```text
w(age)
=
exp(-xi * age)
```

where:

```text
age >= 0
xi >= 0
```

Properties:

```text
age = 0
→ weight = 1

age increases
→ weight decreases
```

If:

```text
xi = 0
```

then:

```text
all matches have equal weight
```

Larger `xi` means faster forgetting.

---

## 4. Half-life interpretation

A more intuitive way to communicate exponential decay is the **half-life**.

Half-life is the age at which a historical match receives half the weight of a current match.

If:

```text
w(t) = exp(-xi*t)
```

then:

```text
half_life
=
ln(2) / xi
```

This representation may be easier for Robetting experiments.

Example:

```text
half-life = 180 days
```

means:

```text
match today       weight 1.00
match 180d old    weight 0.50
match 360d old    weight 0.25
match 540d old    weight 0.125
```

### Robetting recommendation

Store/configure decay in whichever parameterization the model requires, but expose a calculated half-life in research reports because it is easier to interpret.

---

## 5. Do not copy Dixon–Coles xi blindly

The original Dixon–Coles parameter was calibrated on:

```text
English football
early/mid 1990s
specific dataset
specific time unit
specific prediction objective
```

Therefore a numerical value from the original paper is not a universal football constant.

For Robetting:

```text
xi_SerieA
```

must be validated on Robetting data.

The same applies to:

```text
Premier League
La Liga
Bundesliga
Ligue 1
```

No assumption should be made that one decay rate is optimal for all leagues.

---

## 6. Time decay belongs inside the training process

A common mistake would be:

```text
calculate last 10 average
then apply another recency score
```

without a coherent probabilistic interpretation.

For a weighted likelihood model, match weights should enter directly into the fitting objective.

Conceptually:

```text
weighted_log_likelihood
=
sum_k
w_k * log P(result_k | model)
```

This means older matches have less influence on estimated parameters.

---

## 7. Time window vs continuous decay

Another approach is to use a hard window.

Example:

```text
use last 2 seasons
ignore anything older
```

This produces:

```text
weight = 1
```

inside the window and:

```text
weight = 0
```

outside it.

Continuous decay is smoother:

```text
1.00
0.92
0.83
0.71
...
```

instead of:

```text
1
1
1
0
```

### Robetting decision

Both should be experimentally compared.

Do not assume exponential decay is automatically superior.

---

## 8. Candidate temporal baselines

For the same Poisson-type model, test:

```text
TIME-00
all available history, equal weight

TIME-01
current season only

TIME-02
rolling 365 days

TIME-03
rolling 730 days

TIME-04
exponential decay

TIME-05
exponential decay + maximum age cutoff
```

This will show whether decay itself adds value or simply mimics a shorter training window.

---

## 9. Dynamic team-strength model

Crowder et al. explicitly model attack and defence strength through time.

Rather than assuming:

```text
attack_i = constant
defence_i = constant
```

they model latent parameters as evolving processes.

The paper uses an autoregressive structure.

Conceptually:

```text
strength_t
=
long_run_level
+
persistence * (strength_(t-1) - long_run_level)
+
innovation_t
```

This allows:

```text
gradual change
+
random shocks
```

in team ability.

---

## 10. AR(1) interpretation

In an AR(1)-style process:

```text
theta_t
=
theta_mean
+
phi * (theta_(t-1) - theta_mean)
+
epsilon_t
```

where:

```text
phi
```

controls persistence.

If:

```text
phi close to 1
```

team strength changes slowly.

If lower:

```text
old strength loses relevance faster
```

and the process moves more quickly toward its long-term level plus new information.

This is conceptually different from weighting historical matches externally.

---

## 11. Dynamic strength vs time decay

### Time decay

```text
observations age
↓
their influence decreases
```

### Dynamic latent process

```text
team strength itself evolves
↓
old latent strength informs new latent strength
```

The second is statistically richer but generally more computationally demanding.

Robetting should begin with time-decayed baselines before moving to explicit stochastic state models.

---

## 12. Elo already contains implicit recency

Elo does not normally multiply old match observations by a decay weight.

Instead:

```text
rating_before_match
↓
new result
↓
rating_update
↓
new current rating
```

Old information survives only through the current rating state.

Therefore Elo contains an **implicit temporal forgetting mechanism**.

Its speed is controlled largely by:

```text
K factor
```

A higher `K` gives recent results greater influence.

This means we should not automatically add an external exponential decay on top of Elo.

That would create two overlapping recency mechanisms.

---

## 13. Elo K and Dixon–Coles xi are analogous, not identical

Both control responsiveness.

```text
Dixon-Coles xi
→ how rapidly old matches lose fitting weight

Elo K
→ how rapidly new results move the rating
```

But they act on different mathematical structures.

Therefore Robetting should not attempt to translate:

```text
xi
```

directly into:

```text
K
```

They need separate calibration.

---

## 14. Rolling xG and time

Future xG features will face the same problem.

Example feature:

```text
average xG last 10 matches
```

has an arbitrary cutoff.

Alternative:

```text
time-decayed xG
```

where:

```text
recent xG
gets more weight
than older xG
```

Possible feature:

```text
weighted_xG
=
sum(w_i * xG_i) / sum(w_i)
```

with:

```text
w_i = exp(-xi_xg * age_i)
```

Important:

```text
xi_xg
```

does not need to equal:

```text
xi_goals
```

because different statistics may have different stability properties.

---

## 15. Match count vs calendar time

There are at least two ways to define recency:

### Calendar time

```text
days since match
```

### Match index

```text
number of matches ago
```

These differ during:

```text
fixture congestion
winter breaks
international breaks
postponements
```

Dixon–Coles uses elapsed time.

For Robetting, elapsed calendar time is theoretically cleaner for a temporal process, but match-index decay could still be tested.

---

## 16. Season boundaries

A season transition is not necessarily just another 60–90 day gap.

Summer can include:

```text
transfers
manager changes
promotion/relegation
squad turnover
```

Therefore Robetting should test whether a specific season-boundary adjustment improves predictions.

Candidate approaches:

```text
pure continuous decay
```

versus:

```text
continuous decay
+
season regression toward mean
```

or:

```text
partial parameter reset
```

No season reset should be assumed automatically.

---

## 17. Promoted teams

Promoted teams create a temporal and structural problem.

Their recent history comes from another competition.

Options:

```text
ignore lower-league history
use lower-league history with strength adjustment
initialize near promoted-team historical prior
use cross-league rating
use hierarchical model
```

Time decay alone does not solve this problem.

This should remain a separate team-initialization research topic.

---

## 18. Structural shocks

Some changes may be too abrupt for ordinary gradual decay.

Examples:

```text
manager replacement
major transfer window
financial collapse
points deduction
empty-stadium period
competition-format change
```

A purely exponential model reacts only after new match results arrive.

A future dynamic model could incorporate explicit structural shocks.

However, Robetting should not add subjective shock adjustments until a reproducible rule exists.

---

## 19. COVID / empty-stadium seasons

Home advantage changed materially in many competitions during periods with restricted crowds.

This provides a concrete example where:

```text
home advantage
```

should not necessarily be treated as time-invariant.

Robetting's historical models should consider whether special structural periods need:

```text
time-varying home effect
```

or separate validation.

This must be decided empirically for the actual competitions used.

---

## 20. Leakage rule

Time modelling creates a strict requirement.

For a prediction at time `T`:

```text
only matches with kickoff < T
```

may influence:

```text
weights
parameters
rolling statistics
ratings
hyperparameter state
```

Even if a later match falls inside the numerical time window, it must never enter.

Every historical prediction must have:

```text
data_cutoff_at
```

---

## 21. Hyperparameter leakage

A subtler error is tuning `xi` on the final test period.

Example of invalid procedure:

```text
try 20 xi values
on 2025/26 test data
↓
choose best
↓
report that same score
```

This leaks test information into model design.

Correct structure:

```text
training
↓
validation
choose xi
↓
locked test
```

or nested rolling validation.

---

## 22. Suggested first decay experiment

### Experiment ID

```text
EXP-TIME-001
```

### Base model

```text
RB-P-001
```

### Goal

Measure whether recency weighting improves out-of-sample forecasts.

### Variants

```text
A: equal-weight expanding history

B: current-season only

C: rolling 365 days

D: rolling 730 days

E: exponential decay, several candidate half-lives
```

### Candidate half-lives

For initial exploration only:

```text
90 days
180 days
270 days
365 days
540 days
730 days
```

These are experiment values, not literature-derived optimal constants.

### Metrics

```text
1X2 Log Loss
Brier
RPS
Calibration

Over 2.5
BTTS
```

---

## 23. Suggested Dixon–Coles decay experiment

### Experiment ID

```text
EXP-DC-TIME-001
```

Compare:

```text
RB-DC without decay
vs
RB-DC with exponential decay
```

Then optimize the half-life only on rolling validation.

This isolates the contribution of:

```text
tau correction
```

from:

```text
time weighting
```

which is important because they are separate ideas within the broader Dixon–Coles framework.

---

## 24. Suggested stability report

For each fitted temporal model store/report:

```text
competition
training cutoff
model version
decay type
xi
half-life
max lookback
sample matches
```

and optionally team-strength snapshots.

This makes historical experiments reproducible.

---

## 25. Model-specific temporal policy

Robetting should eventually maintain a table like:

```text
MODEL             TEMPORAL MECHANISM

RB-P-001          fixed/equal-weight baseline
RB-P-TD-001       exponential likelihood decay
RB-DC-001         Dixon-Coles + calibrated decay
RB-ELO-001        sequential update via K
RB-XG-FEAT-001    rolling/time-decayed features
future dynamic    latent stochastic process
```

This prevents one generic `recent_form_weight` from being used everywhere without mathematical justification.

---

## 26. What not to do

Avoid arbitrary constructions such as:

```text
last 5 matches = 50%
last 10 matches = 30%
season = 20%
```

unless they arise from an explicitly tested model.

Also avoid:

```text
W = 3
D = 1
L = 0
```

combined with undocumented time weights and then presented as a probability model.

Such scores may be useful exploratory features, but they are not calibrated probabilities.

---

## 27. Proposed development sequence

```text
1. RB-P-001 equal-weight baseline

2. EXP-TIME-001
   hard windows vs exponential decay

3. RB-DC-001
   low-score correction

4. EXP-DC-TIME-001
   calibrate decay

5. Elo track
   calibrate K separately

6. xG feature track
   calibrate rolling horizons / decay separately

7. only later:
   explicit dynamic latent-strength models
```

This sequence keeps complexity measurable.

---

## 28. Open questions for Robetting

- How quickly does Serie A team strength become stale?
- Is optimal decay stable across seasons?
- Does optimal decay differ by league?
- Does attack strength decay differently from defence strength?
- Should home advantage be time-varying?
- Does calendar-time decay outperform match-count decay?
- Is a hard rolling window competitive with exponential decay?
- Is a hybrid cutoff + decay better?
- How much previous-season data should survive into August?
- Should decay accelerate after a managerial change?
- Can structural shocks be detected automatically?
- How should promoted-team lower-division history be weighted?
- Does xG require a different half-life from goals?
- Does Elo add enough responsiveness without explicit decay?
- When does an explicit latent dynamic model justify its computational cost?

---

## 29. Strengths of temporal weighting

- simple;
- interpretable;
- easy to add to likelihood models;
- retains information while emphasizing current form/strength;
- provides a continuous alternative to arbitrary "last N" windows;
- can be tuned objectively with predictive validation.

---

## 30. Limitations

- one decay rate may be too simplistic;
- gradual decay cannot instantly model structural shocks;
- optimal decay can differ across leagues/eras/features;
- hyperparameter tuning can leak future information;
- decay can overfit if tuned aggressively;
- older matches may still contain useful structural information;
- shorter memory worsens sample-size problems for new/promoted teams.

---

## 31. Robetting decision

### Adopted principle

```text
TEAM STRENGTH IS TIME-DEPENDENT
```

but not:

```text
ALL ROBETTING MODELS USE THE SAME DECAY
```

### Initial policy

Use model-specific temporal mechanisms and evaluate them chronologically.

For Poisson/Dixon–Coles:

```text
exponential time decay = candidate
```

For Elo:

```text
K-driven sequential updating = primary recency mechanism
```

For future xG features:

```text
rolling / exponential aggregation = candidate
```

Explicit stochastic team-strength processes remain a later research stage.

---

## 32. Evidence vs Robetting design choices

### Supported by Dixon & Coles (1997)

- team performance is dynamic;
- older match information can be downweighted;
- tapered likelihood gives greater weight to recent results;
- exponential time weighting is a practical modelling approach;
- temporal weighting is motivated by prediction rather than static fit alone.

### Supported by Crowder et al. (2002)

- fixed attack and defence parameters are restrictive;
- team-strength processes can be modelled explicitly through time;
- autoregressive stochastic processes provide an alternative to Dixon–Coles tapering;
- dynamic modelling can be used for football match prediction.

### Supported by Ley et al.

- time depreciation can be incorporated into weighted maximum likelihood;
- current-strength ranking models can be compared on predictive performance;
- temporal weighting is applicable across several statistical football model families.

### Robetting design choices

- use half-life as an interpretable reporting concept;
- compare hard windows and exponential decay;
- separate model-specific decay settings;
- initial candidate half-life grid;
- separate Dixon–Coles tau and decay experiments;
- preserve temporal configuration with model versions;
- treat structural shocks as future research rather than manual overrides.

---

## Sources

1. Dixon, M. J. & Coles, S. G. (1997), *Modelling Association Football Scores and Inefficiencies in the Football Betting Market*, JRSS Series C.
2. Crowder, M., Dixon, M., Ledford, A. & Robinson, M. (2002), *Dynamic modelling and prediction of English Football League matches for betting*, JRSS Series D, 51(2), 157–168. DOI: 10.1111/1467-9884.00308.
3. Ley, C., Van de Wiele, T. & Van Eetvelde, H., *Ranking soccer teams on basis of their current strength: a comparison of maximum likelihood approaches*, arXiv:1705.09575.
4. Hvattum, L. M. & Arntzen, H. (2010), *Using ELO ratings for match result prediction in association football*, International Journal of Forecasting, 26(3), 460–470.

---

## Knowledge-base tags

```text
football-analytics
time-decay
dynamic-strength
recency
dixon-coles
poisson
elo
rolling-features
half-life
weighted-likelihood
ar1
backtesting
data-leakage
robetting
```
