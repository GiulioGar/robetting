# Bayesian Football Models

## Status

**Source status:** supported by peer-reviewed football research and recent Bayesian modelling work  
**Robetting status:** future probabilistic-model family  
**Suggested research id:** `RB-BAYES-001`  
**Decision:** Bayesian models are a serious research direction for Robetting, especially when combining uncertainty, dynamic team strength and heterogeneous information. They should not replace simpler baselines before those baselines are fully validated.

---

## Key sources

### Constantinou, Fenton & Neil (2012)

*pi-football: A Bayesian network model for forecasting Association Football match outcomes.*

Knowledge-Based Systems, 36, 322–339.

Queen Mary University accepted manuscript:
https://qmro.qmul.ac.uk/xmlui/bitstream/handle/123456789/10780/CONSTANTINOU%20Pi-Football%20a%20Bayesian%202012%20Accepted.pdf?sequence=5

Official journal record:
https://www.sciencedirect.com/science/article/pii/S0950705112001967

### Macrì-Demartino, Egidi & Torelli (2025)

*Bayesian weighted discrete-time dynamic models for association football prediction.*

Preprint:
https://arxiv.org/abs/2508.05891

This work proposes dynamic Bayesian goal models where attack and defence strengths can adapt over time and borrow information from previous periods only when supported by the data.

---

## 1. Why Bayesian modelling is relevant

Football prediction contains several forms of uncertainty:

```text
team strength uncertainty
limited samples
changing squads
manager changes
promotion/relegation
uncertain injuries / availability
noisy match outcomes
```

A Bayesian framework represents uncertain quantities with probability distributions rather than only single fitted values.

Conceptually:

```text
parameter
!=
one fixed number only
```

but:

```text
parameter
=
probability distribution
```

Example:

```text
Inter attack strength
```

could be represented not merely as:

```text
1.31
```

but as a posterior distribution describing both:

```text
estimated strength
+
uncertainty around that estimate
```

---

## 2. Bayesian updating

Bayesian inference combines:

```text
prior information
+
new observed data
↓
posterior information
```

Symbolically:

```text
Posterior
∝
Likelihood × Prior
```

For football:

```text
belief about team strength before match
+
new match result
↓
updated belief about team strength
```

This creates a natural framework for sequential learning.

---

## 3. Priors

A prior distribution represents information or assumptions before observing the current dataset.

Possible football examples:

```text
new season team strength
previous season posterior

promoted club
second-division strength prior

new club with little history
league-average prior
```

This can be particularly useful for Robetting's early-season and promoted-team problems.

A prior should never be chosen arbitrarily without sensitivity testing.

---

## 4. Posterior

After observing data:

```text
prior
+
match evidence
↓
posterior
```

The posterior becomes the updated probability distribution for parameters.

It can then be used:

```text
directly for prediction
```

and potentially:

```text
as the prior for the next time period
```

This naturally supports dynamic team-strength modelling.

---

## 5. Posterior predictive distribution

For Robetting, the most important Bayesian object is not necessarily the posterior parameter estimate.

It is the:

```text
posterior predictive distribution
```

Conceptually:

```text
uncertainty in parameters
+
uncertainty in future match outcome
↓
predictive probabilities
```

This is richer than:

```text
fit parameters
↓
plug in one best estimate
↓
forecast
```

because parameter uncertainty is propagated into the prediction.

---

## 6. pi-football

Constantinou, Fenton and Neil propose a Bayesian Network called:

```text
pi-football
```

for forecasting English Premier League match outcomes.

The model combines:

```text
objective information
+
subjective information
```

and uses uncertainty to weight time-dependent information.

The authors explicitly design the model so that information whose reliability is lower can have less influence on the final forecast.

---

## 7. Bayesian Networks

A Bayesian Network represents probabilistic dependencies between variables using a directed graph.

Conceptually:

```text
team ability
     ↓
recent performance
     ↓
match performance
     ↓
match outcome
```

while other nodes may represent:

```text
home advantage
player availability
subjective assessment
form
```

The arrows describe conditional relationships.

This architecture can combine variables that do not naturally fit into a simple regression equation.

---

## 8. Objective and subjective information

One unusual aspect of pi-football is its explicit combination of:

```text
objective historical data
```

with:

```text
subjective information
```

Potential examples include contextual assessments that are difficult to encode purely from results.

### Robetting policy

This is scientifically interesting, but Robetting should **not** introduce subjective inputs into the initial prediction engine.

Reasons:

```text
reproducibility
automation
auditability
backtesting difficulty
historical availability
```

If subjective/contextual inputs are ever tested, they must be versioned and historically reproducible.

---

## 9. Uncertainty weighting

A major Bayesian advantage is that information can be weighted according to its uncertainty.

For example:

```text
team with 30 recent matches
```

may have a narrower strength distribution than:

```text
promoted team with 3 relevant matches
```

The model can therefore express:

```text
we know less
```

instead of forcing equally confident point estimates.

This is potentially very important for Robetting.

---

## 10. Bayesian attack and defence models

Bayesian methods can also be applied directly to Poisson-style football models.

For example:

```text
attack_i ~ prior distribution
defence_i ~ prior distribution
home_effect ~ prior
```

Observed goals then follow a likelihood such as:

```text
HomeGoals ~ Poisson(lambda_home)
AwayGoals ~ Poisson(lambda_away)
```

with:

```text
log(lambda_home)
=
attack_home
-
defence_away
+
home_effect
```

and:

```text
log(lambda_away)
=
attack_away
-
defence_home
```

Bayesian inference then estimates posterior distributions for all team strengths.

---

## 11. Hierarchical modelling

A powerful extension is a hierarchical model.

Instead of treating each team's parameters as unrelated:

```text
attack_Inter
attack_Milan
attack_Roma
...
```

assume they come from a shared league-level distribution.

Conceptually:

```text
league attack distribution
        ↓
individual team attack strengths
```

This creates **partial pooling**.

Teams with little data are pulled more toward the league average.

Teams with lots of evidence can move further away.

---

## 12. Why partial pooling matters

Consider a promoted club after 3 matches:

```text
8 goals scored
```

A naive model might conclude:

```text
elite attack
```

A hierarchical Bayesian model can say:

```text
evidence is interesting
but sample is still very small
```

and shrink the estimate toward a plausible league distribution.

This could solve several Robetting cold-start problems elegantly.

---

## 13. Dynamic Bayesian models

Recent Bayesian football research models attack and defence strengths as time-varying latent parameters.

Instead of:

```text
attack_t
=
fixed attack
```

the model allows:

```text
attack_t
depends probabilistically on
attack_(t-1)
```

Macrì-Demartino et al. propose an adaptive framework where the model decides how much information to borrow from the previous period depending on whether current evidence is compatible with past strength.

This is more flexible than forcing one fixed decay rate for every team and every period.

---

## 14. Adaptive borrowing

Conceptually:

```text
past and present agree
↓
borrow heavily from previous strength

past and present disagree strongly
↓
allow faster adaptation
```

This is attractive for situations such as:

```text
transfer windows
manager changes
sudden tactical improvement
squad collapse
```

without manually inserting subjective breakpoints.

---

## 15. Separate attack and defence evolution

An important modern extension is allowing:

```text
attack evolution
```

and:

```text
defence evolution
```

to have different dynamics.

This is plausible because a team's defensive organization may stabilize at a different rate from its attacking production.

Robetting should not assume:

```text
attack half-life = defence half-life
```

without evidence.

---

## 16. Bayesian vs Dixon–Coles time decay

Dixon–Coles:

```text
old matches
↓
fixed mathematical downweighting
```

Dynamic Bayesian model:

```text
previous latent strength
↓
probabilistic prior
↓
current evidence
↓
posterior current strength
```

The Bayesian approach can potentially adapt differently across:

```text
teams
time periods
attack
defence
```

but at a much higher computational and implementation cost.

---

## 17. Bayesian vs Elo

Elo:

```text
one current scalar rating
+
deterministic update rule
```

Bayesian rating:

```text
distribution over current strength
+
uncertainty
+
probabilistic update
```

This means Bayesian models can naturally express:

```text
Team A rating estimate = strong, high confidence

Team B rating estimate = strong, low confidence
```

where classic Elo would normally provide only one number for each.

---

## 18. Uncertainty propagation

Suppose two matches have identical estimated expected goals:

```text
lambda_home = 1.6
lambda_away = 1.1
```

but:

```text
Match A:
team parameters estimated from large samples

Match B:
new/promoted teams with sparse history
```

A plug-in model may give almost identical probabilities.

A Bayesian model can produce different predictive uncertainty because the parameter uncertainty differs.

This is a potentially meaningful advantage.

---

## 19. Bayesian model outputs

A Bayesian Robetting model could persist summaries such as:

```text
posterior mean attack
posterior median attack
credible interval attack

posterior mean defence
credible interval defence

posterior home effect
```

But the primary product should remain:

```text
posterior predictive match probabilities
```

not parameter tables alone.

---

## 20. Credible intervals

A Bayesian credible interval can quantify uncertainty about a parameter.

Example:

```text
Inter attack strength

posterior mean: 1.35
90% credible interval: [1.18, 1.52]
```

This differs conceptually from a frequentist confidence interval.

For Robetting public presentation, such detail may initially be excessive.

For internal research, it can be highly useful.

---

## 21. Bayesian computation

Possible inference methods include:

```text
MCMC
Hamiltonian Monte Carlo
NUTS
Variational Inference
Laplace approximation
specialized conjugate methods
```

This is significantly more computationally demanding than:

```text
Poisson maximum likelihood
Elo
```

Therefore computational cost must be part of the evaluation.

---

## 22. Robetting implementation language

The production portal may remain Laravel/PHP.

That does **not** imply Bayesian inference should be implemented in PHP.

A sensible architecture could later be:

```text
Laravel
↓
prediction job / service boundary
↓
Python or R modelling service
↓
stored predictions
↓
Laravel reads results
```

This is a future architecture question.

No modelling stack decision is adopted yet.

---

## 23. Recommended first Bayesian experiment

### Model ID

```text
RB-BAYES-POISSON-001
```

### Goal

Create the closest Bayesian analogue to `RB-P-001`.

### Model

```text
HomeGoals ~ Poisson(lambda_home)
AwayGoals ~ Poisson(lambda_away)

attack parameters ~ hierarchical priors
defence parameters ~ hierarchical priors
home effect ~ prior
```

### Why start here

This allows a controlled comparison:

```text
frequentist Poisson
vs
Bayesian Poisson
```

without changing the entire structural model at once.

---

## 24. Proposed second experiment

### Model ID

```text
RB-BAYES-DYN-001
```

Add:

```text
time-varying attack
time-varying defence
```

with a dynamic prior structure.

Compare against:

```text
RB-DC-001
```

and:

```text
RB-P + calibrated time decay
```

The key question is whether adaptive Bayesian evolution improves unseen-match probabilities enough to justify complexity.

---

## 25. Proposed experiment IDs

```text
EXP-BAYES-001
Hierarchical Bayesian Poisson

EXP-BAYES-002
Prior sensitivity

EXP-BAYES-003
Promoted-team initialization

EXP-BAYES-004
Dynamic attack/defence

EXP-BAYES-005
Bayesian vs Dixon-Coles

EXP-BAYES-006
Parameter uncertainty contribution
```

---

## 26. Prior sensitivity

Bayesian conclusions can depend on prior choices.

Therefore every important Bayesian model should test:

```text
weak prior
moderate shrinkage prior
stronger shrinkage prior
```

and inspect whether predictions materially change.

A model that performs well only under one fragile prior specification should be treated cautiously.

---

## 27. Posterior predictive checking

Bayesian models support posterior predictive checks.

Conceptually:

```text
fit model
↓
simulate synthetic matches from posterior
↓
compare synthetic data with real data
```

Diagnostics could include:

```text
goal distribution
draw frequency
0-0 frequency
home-win frequency
high-scoring matches
```

If simulated football looks systematically unlike real football, the model structure may be inadequate.

---

## 28. Bayesian model evaluation

Bayesian models must still obey the same Robetting out-of-sample framework.

Primary evaluation:

```text
Log Loss
Brier
Calibration
```

Secondary 1X2:

```text
RPS
```

Do not promote a Bayesian model merely because:

```text
posterior diagnostics look sophisticated
```

It must improve actual predictive performance.

---

## 29. Subjective variables warning

pi-football demonstrates that subjective information can be integrated probabilistically.

Robetting should distinguish:

```text
possible in Bayesian theory
```

from:

```text
desirable for Robetting production
```

Manual judgments create major historical-backtesting problems.

Example:

```text
"Inter morale = high"
```

cannot be backtested honestly unless the historical value was recorded before the match.

Therefore subjective information remains out of scope for initial model generations.

---

## 30. Potential use for injuries and lineups

Bayesian networks could later provide a principled framework for information such as:

```text
key player unavailable
goalkeeper change
expected lineup uncertainty
```

because these variables themselves can be uncertain.

Example:

```text
P(player starts) = 70%
```

rather than:

```text
player starts = yes/no
```

This is attractive conceptually but depends on reliable historical data.

---

## 31. Early-season advantage

Bayesian priors may be particularly useful early in a season.

A frequentist current-season-only model can have extremely little data.

A Bayesian model can combine:

```text
previous-season information
league-level prior
new-season results
```

while representing uncertainty explicitly.

This should become a dedicated experiment.

---

## 32. Promoted teams

Bayesian hierarchical models provide several natural options:

```text
prior based on promoted clubs historically
prior informed by second-division performance
league-average prior with large uncertainty
cross-league hierarchical prior
```

This is one of the strongest practical reasons to explore the Bayesian family.

---

## 33. Model complexity discipline

Do not build one enormous Bayesian network containing:

```text
goals
xG
Elo
injuries
weather
manager
referee
odds
lineups
form
```

at the first attempt.

Use controlled incremental experiments.

Recommended order:

```text
hierarchical goal model
↓
dynamic team strength
↓
selected objective contextual variables
↓
only later heterogeneous uncertainty sources
```

---

## 34. Main empirical caution

The original pi-football work reports strong forecasting and betting results in its evaluation context.

Robetting must not assume those historical findings will reproduce today.

Reasons include:

```text
different era
different markets
different datasets
different information environment
different bookmaker efficiency
```

Every claim must be re-tested on Robetting data.

---

## 35. Recent research direction

The 2025 Bayesian weighted dynamic model is relevant because it suggests a modern alternative to one fixed temporal decay.

Its main conceptual contribution for Robetting is:

```text
let the model decide how strongly
past strength should carry into the present
```

rather than forcing the same temporal persistence everywhere.

Because this is recent preprint research, it should be tracked and replicated rather than immediately adopted.

---

## 36. Open questions for Robetting

- Does hierarchical shrinkage improve early-season forecasts?
- Does Bayesian modelling improve promoted-team initialization?
- How sensitive are predictions to priors?
- Does parameter uncertainty materially change match probabilities?
- Do dynamic Bayesian strengths outperform Dixon-Coles decay?
- Should attack and defence have separate evolution rates?
- What period length should dynamic models use?
- Weekly, match-by-match or monthly latent states?
- Does Bayesian computation fit our production requirements?
- Can posterior updates be incremental?
- Is MCMC required or can faster approximations work?
- Does Bayesian xG/team-strength integration add stable value?
- Which objective contextual variables justify inclusion?
- Can injury/lineup uncertainty be backtested historically?
- Does added complexity improve calibration or only fit?

---

## 37. Strengths

- explicit uncertainty representation;
- natural sequential updating;
- hierarchical partial pooling;
- strong treatment of sparse-data teams;
- principled promoted-team priors;
- parameter uncertainty propagated to predictions;
- flexible dynamic team strengths;
- can combine heterogeneous information;
- posterior predictive checking.

---

## 38. Limitations

- higher computational cost;
- greater implementation complexity;
- prior specification matters;
- convergence/inference diagnostics are required;
- subjective information can destroy reproducibility;
- more difficult to debug;
- richer models can overfit;
- good posterior fit does not guarantee better forecasts;
- production integration may require a separate modelling stack.

---

## 39. Robetting decision

### Current status

```text
BAYESIAN MODELS = STRATEGIC RESEARCH TRACK
```

not:

```text
NEXT PRODUCTION MODEL
```

### Recommended progression

```text
RB-P-001
↓
RB-DC-001
↓
validated temporal baselines
↓
RB-BAYES-POISSON-001
↓
RB-BAYES-DYN-001
```

Bayesian methods become especially attractive if Robetting needs:

```text
uncertainty
cold-start handling
adaptive strength evolution
hierarchical multi-league modelling
```

---

## 40. Evidence vs Robetting design choices

### Supported by Constantinou, Fenton & Neil

- Bayesian Networks can be used for football match forecasting;
- objective and subjective information can be combined;
- uncertainty can be explicitly represented;
- time-dependent data can be weighted through uncertainty;
- pi-football was evaluated on EPL forecasts.

### Supported by Macrì-Demartino, Egidi & Torelli

- attack and defence strengths can be modelled dynamically in a Bayesian framework;
- past information can be borrowed adaptively;
- separate time-varying precision can be used for attack and defence;
- adaptive dynamic models can improve predictive performance relative to other discrete-time dynamic models in their experiments.

### Robetting design choices

- start Bayesian research with hierarchical Poisson;
- keep subjective information out initially;
- model ids `RB-BAYES-POISSON-001` and `RB-BAYES-DYN-001`;
- compare against Poisson and Dixon-Coles;
- focus on promoted teams, early season and parameter uncertainty;
- retain standard Robetting proper-score evaluation;
- defer modelling-language architecture decisions.

---

## Sources

1. Constantinou, A. C., Fenton, N. E. & Neil, M. (2012), *pi-football: A Bayesian network model for forecasting Association Football match outcomes*, Knowledge-Based Systems, 36, 322–339.
2. Queen Mary University accepted manuscript: https://qmro.qmul.ac.uk/xmlui/bitstream/handle/123456789/10780/CONSTANTINOU%20Pi-Football%20a%20Bayesian%202012%20Accepted.pdf?sequence=5
3. Official journal record: https://www.sciencedirect.com/science/article/pii/S0950705112001967
4. Macrì-Demartino, R., Egidi, L. & Torelli, N. (2025), *Bayesian weighted discrete-time dynamic models for association football prediction*, arXiv:2508.05891.

---

## Knowledge-base tags

```text
football-analytics
bayesian
bayesian-network
hierarchical-model
dynamic-strength
uncertainty
priors
posterior
posterior-predictive
partial-pooling
poisson
team-strength
calibration
robetting
```
