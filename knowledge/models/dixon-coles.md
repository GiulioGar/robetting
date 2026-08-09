# Dixon–Coles Model

## Status

**Source status:** verified primary source  
**Robetting status:** candidate baseline model  
**Suggested model id:** `RB-DC-001`  
**Decision:** not yet adopted; must be implemented and backtested against simpler baselines and market probabilities.

---

## Primary source

Dixon, M. J. & Coles, S. G. (1997).  
*Modelling Association Football Scores and Inefficiencies in the Football Betting Market.*  
Journal of the Royal Statistical Society: Series C (Applied Statistics), 46(2), 265–280.  
DOI: 10.1111/1467-9876.00065

Official record:
https://academic.oup.com/jrsssc/article-abstract/46/2/265/6990546

Lancaster University record:
https://research.lancaster-university.uk/en/publications/modelling-association-football-scores-and-inefficiencies-in-the-f/

---

## 1. Problem addressed

The paper starts from the idea that a useful football forecasting model should:

- represent different strengths of the two teams;
- explicitly model home advantage;
- use recent performance rather than treating all historical matches equally;
- distinguish attacking ability from defensive ability;
- account for the strength of past opponents.

The model is built on Maher's Poisson framework and then modified to better represent low-scoring football results and changing team strength over time.

For Robetting, this makes Dixon–Coles a strong candidate for a first serious probabilistic baseline because it produces a complete score distribution while remaining interpretable.

---

## 2. Basic score model

For a match where team `i` plays at home against team `j`:

- `X_ij` = home goals
- `Y_ij` = away goals

The basic model assumes:

```text
X_ij ~ Poisson(lambda)
Y_ij ~ Poisson(mu)
```

with:

```text
lambda = attack_i * defence_j * home_advantage
mu     = attack_j * defence_i
```

Using the notation from the paper:

```text
lambda = alpha_i * beta_j * gamma
mu     = alpha_j * beta_i
```

where:

- `alpha_i` = attacking strength of team `i`;
- `beta_i` = defensive parameter of team `i`;
- `gamma` = home-effect parameter;
- `lambda` = expected home goals;
- `mu` = expected away goals.

Important interpretation note:

A larger `alpha` means a stronger attack.

The `beta` parameter is a multiplicative defensive rate parameter. It must not be casually interpreted as a conventional "higher = better defence" rating without checking the adopted parameterization.

---

## 3. Why independent Poisson is modified

The empirical analysis in the paper finds that independence works reasonably well for most score combinations, but is less adequate for the four low-score outcomes:

```text
0-0
0-1
1-0
1-1
```

Dixon and Coles therefore multiply the independent-Poisson joint probability by a correction term `tau`.

The resulting probability is conceptually:

```text
P(X=x, Y=y)
=
tau(lambda, mu, rho, x, y)
*
Poisson(x; lambda)
*
Poisson(y; mu)
```

The correction is:

```text
tau = 1 - lambda*mu*rho    if x=0, y=0
tau = 1 + lambda*rho       if x=0, y=1
tau = 1 + mu*rho           if x=1, y=0
tau = 1 - rho              if x=1, y=1
tau = 1                    otherwise
```

`rho` is the dependence parameter.

If:

```text
rho = 0
```

the model reduces to the independent Poisson model for these cells.

### Robetting implication

We should implement the independent Poisson model first and then add the Dixon–Coles correction as a controlled extension. That gives us a clean baseline comparison:

```text
RB-P-001  Independent Poisson
RB-DC-001 Dixon-Coles
```

---

## 4. Score probability matrix

Once `lambda`, `mu` and `rho` are known, Robetting can build:

```text
P(HomeGoals=x, AwayGoals=y)
```

for a grid such as 0–8 goals per side.

From this single matrix we can derive multiple markets consistently.

### 1X2

```text
P(Home) = sum P(x,y) where x > y
P(Draw) = sum P(x,y) where x = y
P(Away) = sum P(x,y) where x < y
```

### Over/Under 2.5

```text
P(Over 2.5)  = sum P(x,y) where x+y >= 3
P(Under 2.5) = sum P(x,y) where x+y <= 2
```

### BTTS

```text
P(BTTS Yes) = sum P(x,y) where x>0 and y>0
P(BTTS No)  = 1 - P(BTTS Yes)
```

### Correct Score

Each cell is already a correct-score probability.

### Robetting implication

A goal-distribution model gives us several markets without training a separate classifier for every market.

This is a major reason to use Dixon–Coles as a baseline.

---

## 5. Parameter estimation

For `n` teams, the model estimates:

```text
n attack parameters
n defence parameters
1 rho parameter
1 home-effect parameter
```

The paper estimates parameters by maximum likelihood.

Because the model is otherwise over-parameterized, Dixon and Coles impose an identifiability constraint on the attack parameters:

```text
average(alpha_i) = 1
```

equivalently:

```text
sum(alpha_i) / n = 1
```

### Robetting implementation requirement

An optimizer must estimate model parameters under an explicit identifiability constraint.

This must be tested carefully because multiple equivalent rescalings of attack/defence parameters can otherwise represent the same goal intensities.

---

## 6. Static model limitation

A static model assumes each team's attack and defence parameters stay constant.

Dixon and Coles explicitly reject this as unrealistic.

A team in September is not necessarily the same team in March.

Therefore the paper estimates parameters at a forecasting time `t` using only historical matches played before `t`.

This principle aligns directly with Robetting's anti-data-leakage requirement.

---

## 7. Time weighting

Historical observations are downweighted according to age.

The paper considers an exponential weighting function:

```text
phi(age) = exp(-xi * age)
```

where:

- `age` = time elapsed since the match;
- `xi > 0` = decay parameter.

Interpretation:

```text
xi = 0
```

means no time decay.

Larger values of `xi` put more relative weight on recent games.

The paper selects `xi` based on predictive performance rather than purely on in-sample fit.

### Important warning for Robetting

The value reported in the original paper must **not** be copied blindly.

Its optimal value depends on:

- time unit;
- league/sample;
- era;
- dataset;
- evaluation target.

Robetting must estimate `xi` on its own historical data using temporal validation.

---

## 8. Pseudo-likelihood at forecast time

At forecast time `t`, only matches with:

```text
match_time < t
```

are allowed into estimation.

Each historical match contributes a likelihood term raised/weighted according to its recency.

Conceptually:

```text
weighted_log_likelihood
=
sum(
    phi(age_k)
    *
    log P(observed_score_k)
)
```

This gives more influence to recent games while retaining older information.

### Robetting requirement

Every feature/model fit used for a historical prediction must be reproducible using an explicit:

```text
data_cutoff_at
```

No later match may affect the estimated parameters.

---

## 9. Opponent strength is endogenous to the model

One useful property of this framework is that recent results are not treated independently of opponent quality.

A goal against a strong defence and a goal against a weak defence do not contribute identically to estimated strengths because all team parameters are fitted jointly.

This is preferable to crude rules such as:

```text
last_5_goals_scored
```

without opponent adjustment.

---

## 10. Multi-division consideration

The original paper fits teams from several English divisions together and uses cross-division information, including cup matches, to connect relative team strengths.

For Robetting this raises an architectural question:

```text
one model per competition?
one model per country?
one cross-league model?
```

No decision should be taken yet.

For the first experiment, using one league at a time is simpler and easier to validate.

Cross-league calibration should be a later experiment.

---

## 11. Minimum data required

A basic Dixon–Coles implementation needs surprisingly little:

```text
match_id
competition_id
season_id
kickoff_at
home_team_id
away_team_id
home_score_ft
away_score_ft
```

Required conditions:

- final regulation-time scores;
- reliable kickoff/order;
- canonical team identities;
- no duplicate matches;
- no future data in historical fits.

Optional match statistics such as shots, possession or xG are **not required** by the original model.

This makes Dixon–Coles particularly suitable as an early Robetting baseline.

---

## 12. Data we should NOT feed into RB-DC-001 initially

To preserve a clean baseline, the first implementation should not contain:

```text
xG
shots
shots on target
possession
odds
table position
H2H heuristics
injuries
lineups
ML features
```

Those can be tested later as extensions or alternative models.

Otherwise we will not know whether improvements come from Dixon–Coles itself or unrelated feature additions.

---

## 13. Prediction outputs Robetting should persist

For every generated prediction:

```text
match_id
model_version
generated_at
data_cutoff_at
lambda_home
lambda_away
rho
home_probability
draw_probability
away_probability
over_2_5_probability
under_2_5_probability
btts_yes_probability
btts_no_probability
```

Potentially also:

```text
score_matrix
```

or a derived table of score probabilities.

The precise DB schema remains an implementation decision.

---

## 14. Evaluation

Dixon–Coles must not be judged primarily by "number of winners guessed".

Robetting should compare probabilistic forecasts using at least:

```text
Log Loss
Brier Score
Calibration
RPS
```

and compare models chronologically out-of-sample.

Suggested comparison:

```text
RB-BASE-001 league-frequency baseline
RB-P-001    Independent Poisson
RB-DC-001   Dixon-Coles
MARKET      de-vig bookmaker probabilities
```

Market comparison belongs to evaluation; market odds do not need to be an input to the prediction model.

---

## 15. Proposed first experiment

### Experiment ID

`EXP-DC-001`

### Objective

Determine whether Dixon–Coles improves probabilistic forecasts over independent Poisson on Robetting data.

### Competition

Start with one complete league, preferably Serie A because the import pipeline is already being validated there.

### Historical range

Use as many reliable full seasons as are available, while keeping strict chronological separation.

### Validation design

Use rolling-origin / walk-forward evaluation.

Example:

```text
Train / fit using data available before date T
Predict matches at T
Advance cutoff
Refit/update
Predict next block
```

No random train/test split.

### Models

```text
RB-P-001
RB-DC-001
```

### Parameters to compare

```text
rho
xi
home advantage
attack strengths
defence strengths
```

### Metrics

```text
1X2 Log Loss
1X2 Brier Score
RPS
Calibration

Over 2.5 Log Loss/Brier
BTTS Log Loss/Brier
```

### Secondary diagnostics

```text
mean predicted goals
scoreline calibration
home/draw/away calibration
low-score residuals
parameter stability
```

---

## 16. Open questions for Robetting

These must be answered empirically rather than by assumption.

- How many seasons should influence a current prediction?
- What is the optimal time-decay parameter for Serie A?
- Should `xi` differ by competition?
- Should home advantage differ by league?
- Should home advantage vary over time?
- How should newly promoted teams be initialized?
- How should a team with insufficient history be treated?
- Do attack/defence parameters reset or partially carry over between seasons?
- Is `rho` stable across leagues?
- Is `rho` stable across eras?
- Should cup games enter league-strength estimation?
- Should extra-time goals ever enter the model? Initial answer: no; use regulation-time scores for standard pre-match league markets.
- Does Dixon–Coles materially improve calibration versus independent Poisson on modern Serie A data?
- Does its benefit exist mainly for correct-score/low-score probabilities or also for 1X2, O/U and BTTS?

---

## 17. Strengths

- interpretable;
- statistically grounded;
- low input-data requirement;
- produces a coherent full score distribution;
- incorporates attack and defence separately;
- explicitly represents home advantage;
- adjusts low-score dependence;
- supports recency weighting;
- opponent strength enters naturally through joint estimation;
- provides a strong benchmark before adding complex ML.

---

## 18. Limitations

- goal counts contain substantial randomness;
- original model does not use event-level information such as xG;
- Poisson marginal assumptions may be imperfect;
- dependence correction only modifies a small set of low-score outcomes;
- parameter estimation can become computationally non-trivial;
- team-strength initialization is a practical issue for promoted/new teams;
- optimal decay and other hyperparameters are dataset-dependent;
- historical betting profitability reported in the 1997 paper must not be assumed to persist in modern markets.

---

## 19. Robetting decision

### Current decision

```text
Dixon-Coles = CANDIDATE
```

Not:

```text
Dixon-Coles = PRODUCTION MODEL
```

### Implementation sequence

```text
1. Validate historical match dataset
2. Implement Independent Poisson
3. Backtest RB-P-001
4. Add Dixon-Coles tau correction
5. Add time weighting
6. Backtest RB-DC-001
7. Compare calibration and proper scores
8. Compare with de-vig market benchmark
9. Decide whether to promote, extend or reject
```

---

## 20. Evidence vs Robetting design decisions

### Directly supported by Dixon & Coles (1997)

- separate attack and defence parameters;
- explicit home-effect parameter;
- Poisson-based score model;
- low-score correction involving 0-0, 0-1, 1-0 and 1-1;
- dependence parameter `rho`;
- maximum-likelihood parameter estimation;
- identifiability constraint on attack strengths;
- downweighting older observations;
- exponential weighting `exp(-xi*t)`;
- fitting based only on history available before a forecast time;
- use of predicted score distributions to obtain match-outcome probabilities.

### Robetting design choices derived from the paper

- model id `RB-DC-001`;
- storing `lambda_home`, `lambda_away` and probabilities;
- testing Serie A first;
- using rolling-origin validation;
- evaluating Log Loss, Brier, RPS and calibration;
- comparing against independent Poisson and bookmaker probabilities;
- excluding xG/shots/odds from the first clean Dixon–Coles baseline.

These choices are not claims made by the 1997 paper; they are proposed Robetting methodology.

---

## Sources

1. Dixon, M. J. & Coles, S. G. (1997), *Modelling Association Football Scores and Inefficiencies in the Football Betting Market*, JRSS Series C, 46(2), 265–280. DOI: 10.1111/1467-9876.00065.
2. Oxford Academic journal record: https://academic.oup.com/jrsssc/article-abstract/46/2/265/6990546
3. Lancaster University research record: https://research.lancaster-university.uk/en/publications/modelling-association-football-scores-and-inefficiencies-in-the-f/

---

## Knowledge-base tags

```text
football-analytics
probability
poisson
dixon-coles
goal-model
team-strength
home-advantage
time-decay
maximum-likelihood
calibration
backtesting
robetting
```
