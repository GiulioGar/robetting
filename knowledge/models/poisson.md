# Independent Poisson / Maher Model

## Status

**Source status:** verified primary source  
**Robetting status:** baseline candidate  
**Suggested model id:** `RB-P-001`  
**Decision:** implement before Dixon-Coles and use as the minimum statistical benchmark.

---

## Primary source

Maher, M. J. (1982).  
*Modelling Association Football Scores.*  
Statistica Neerlandica, 36(3), 109–118.  
DOI: 10.1111/j.1467-9574.1982.tb00782.x

Reference record:
https://ideas.repec.org/a/bla/stanee/v36y1982i3p109-118.html

Accessible paper copy:
https://www.90minut.pl/misc/maher.pdf

---

## 1. Problem addressed

Maher investigates whether football scores can be represented adequately using Poisson models.

Earlier work had raised objections to simple Poisson assumptions and had considered alternatives such as the Negative Binomial.

Maher's key contribution is not merely to fit one overall goal distribution. He introduces parameters representing the inherent attacking and defensive strengths of individual teams and studies a hierarchy of models.

The paper concludes that, despite some small systematic discrepancies, an independent Poisson model gives a reasonably accurate description of football scores.

It also notes that a bivariate Poisson formulation with modest positive correlation can improve the fit.

---

## 2. Core Robetting interpretation

The first Robetting baseline should answer a simple question:

> If we know only historical match results and team identities, how well can a structured Poisson model estimate future score probabilities?

This is important because every later model must beat something meaningful.

The comparison chain should begin as:

```text
naive frequency baseline
        ↓
RB-P-001 Independent Poisson
        ↓
RB-DC-001 Dixon-Coles
        ↓
more complex models
```

If a complex model cannot consistently improve over `RB-P-001` out of sample, the extra complexity is not justified.

---

## 3. Match score representation

For a match where team `i` plays team `j`, define:

```text
X_ij = goals scored by one team
Y_ij = goals scored by the opponent
```

Under an independent Poisson specification:

```text
X_ij ~ Poisson(lambda_ij)
Y_ij ~ Poisson(mu_ij)
```

and:

```text
P(X=x, Y=y)
=
Poisson(x; lambda)
*
Poisson(y; mu)
```

where:

```text
Poisson(k; lambda)
=
exp(-lambda) * lambda^k / k!
```

The independence assumption means that, conditional on the model parameters, the home and away goal counts are treated as independent.

---

## 4. Team attacking and defensive strengths

The important idea from Maher is that scoring rates depend on both teams.

Conceptually:

```text
expected goals for Team A
=
Team A attacking strength
×
Team B defensive tendency
×
context/home effect
```

and vice versa.

Thus a team's historical goal average should not be used in isolation.

A team scoring two goals against a strong defensive opponent conveys different information from scoring two goals against a weak defensive opponent because parameters are jointly estimated across all fixtures.

---

## 5. Home advantage

Maher's model hierarchy considers home/away effects rather than assuming a neutral venue.

For Robetting, the first Poisson baseline should therefore contain an explicit home-effect parameter.

Conceptually:

```text
lambda_home = attack_home * defence_away * home_effect
lambda_away = attack_away * defence_home
```

Exact parameterization must be documented in implementation so that attack/defence parameter interpretation remains consistent.

---

## 6. Full score probability matrix

Once `lambda_home` and `lambda_away` are estimated, build a matrix:

```text
              Away goals
             0     1     2     3   ...
Home  0     P00   P01   P02   P03
goals 1     P10   P11   P12   P13
      2     P20   P21   P22   P23
      3     P30   P31   P32   P33
      ...
```

Every cell is:

```text
P(Home=x, Away=y)
```

From the matrix we can derive several football markets without fitting separate models.

---

## 7. 1X2 probabilities

```text
P(Home Win)
=
sum P(x,y) for x > y

P(Draw)
=
sum P(x,y) for x = y

P(Away Win)
=
sum P(x,y) for x < y
```

The three probabilities should sum to approximately 1, subject only to numerical truncation if a finite score grid is used.

---

## 8. Total Goals

For Over/Under 2.5:

```text
P(Over 2.5)
=
sum P(x,y) where x+y >= 3

P(Under 2.5)
=
sum P(x,y) where x+y <= 2
```

The same score matrix supports other totals:

```text
0.5
1.5
2.5
3.5
4.5
...
```

No new model is required.

---

## 9. Both Teams To Score

```text
P(BTTS Yes)
=
sum P(x,y) where x > 0 and y > 0

P(BTTS No)
=
1 - P(BTTS Yes)
```

Again, this is derived from the same underlying score distribution.

---

## 10. Correct Score

Each matrix cell directly represents a correct-score probability:

```text
P(0-0)
P(1-0)
P(1-1)
P(2-0)
P(2-1)
...
```

This is useful diagnostically even if Robetting initially exposes only 1X2, O/U and BTTS.

Correct-score calibration can reveal weaknesses that aggregate markets hide.

---

## 11. Parameter estimation

The team-specific attack and defence parameters and home effect are fitted from historical results.

The natural estimation approach is maximum likelihood.

For observed matches `k=1...N`, conceptually maximize:

```text
L(theta)
=
product over matches
P(observed home goals, observed away goals | theta)
```

or equivalently maximize log-likelihood:

```text
log L(theta)
=
sum over matches
log P(observed score | theta)
```

where `theta` includes all attack parameters, defence parameters and the home-effect parameter.

---

## 12. Identifiability

Attack and defence parameters can be rescaled in mathematically equivalent ways unless an identifying constraint is imposed.

Therefore implementation must include a normalization convention.

For example:

```text
mean attack strength = 1
```

or another mathematically equivalent constraint.

The precise convention used by Robetting must be explicit and stable across model versions.

---

## 13. Data required

A clean implementation of `RB-P-001` requires only:

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

Critical requirements:

- scores must refer to regulation-time final results;
- team identities must be canonical;
- duplicate matches must not exist;
- historical order must be reliable;
- future results must never enter a historical forecast.

No xG, shots or bookmaker odds are required.

---

## 14. What RB-P-001 should deliberately exclude

The first baseline should remain intentionally simple.

Do not initially add:

```text
xG
shots
shots on target
possession
Elo
recent-form heuristics
H2H
table position
injuries
lineups
bookmaker odds
machine-learning features
```

These are potential extensions or competing models.

Adding them immediately would destroy the value of Poisson as a controlled baseline.

---

## 15. Static-strength issue

The basic Maher-style model is fundamentally a static-strength framework over the estimation sample.

This means parameter estimates can become stale if too much historical data is pooled indiscriminately.

This limitation is one of the main reasons Dixon-Coles is a natural next model: it explicitly introduces recency weighting and a correction for low-score dependence.

For `RB-P-001`, Robetting should initially keep the model simple and then test alternative training windows rather than silently importing Dixon-Coles time weighting into the Poisson baseline.

Possible experiments:

```text
current season only
last 1 season
last 2 seasons
last 3 seasons
expanding history
```

---

## 16. Independent-goal assumption

The model assumes conditional independence between the two teams' goal counts.

Maher finds that the independent Poisson model is reasonably accurate overall, but also reports small systematic differences and notes improved fit from a bivariate Poisson model with positive correlation.

This is an important warning:

```text
reasonable baseline
!=
perfect generative description
```

Robetting should therefore measure where the model fails rather than assuming independence is true.

---

## 17. Diagnostics to calculate

Besides market-level forecast scores, inspect:

```text
observed vs predicted score frequencies
0-0 frequency
1-0 frequency
0-1 frequency
1-1 frequency
high-scoring tail frequency
home goal distribution
away goal distribution
mean goals
variance of goals
```

This will help explain why Dixon-Coles or Bivariate Poisson may improve the baseline.

---

## 18. Prediction outputs to persist

Suggested outputs:

```text
match_id
model_version
generated_at
data_cutoff_at

lambda_home
lambda_away

home_probability
draw_probability
away_probability

over_2_5_probability
under_2_5_probability

btts_yes_probability
btts_no_probability
```

Potentially also persist or reproducibly regenerate:

```text
score probability matrix
```

The DB design remains separate from this research decision.

---

## 19. Evaluation methodology

`RB-P-001` must be tested chronologically.

Do not use random train/test splitting for historical football predictions.

For every predicted match:

```text
training data cutoff < kickoff
```

Suggested evaluation:

```text
Log Loss
Brier Score
RPS
Calibration
```

for 1X2, plus binary proper scores/calibration for:

```text
Over 2.5
BTTS
```

Later compare with de-vig bookmaker probabilities as an external benchmark.

---

## 20. Proposed experiment

### Experiment ID

`EXP-P-001`

### Objective

Establish Robetting's first reproducible probabilistic football baseline.

### Initial competition

Serie A.

### Inputs

Only:

```text
historical fixtures
teams
regulation-time goals
kickoff date/time
```

### Evaluation protocol

Walk-forward / rolling-origin.

Example:

```text
fit using all permitted historical matches
↓
predict next chronological block
↓
advance cutoff
↓
refit
↓
predict next block
```

### Outputs

Evaluate:

```text
1X2
Over 2.5
BTTS
score distribution
```

### Metrics

```text
Log Loss
Brier
RPS
Calibration
```

### Diagnostic comparison

Later compare directly:

```text
RB-P-001
vs
RB-DC-001
```

using exactly the same historical prediction dates and evaluation sample.

---

## 21. Open questions for Robetting

- How much history should `RB-P-001` use?
- Should parameters be reset every season?
- Should previous-season parameters initialize the new season?
- How should promoted clubs be initialized?
- Should one model be fitted per league?
- Is one home-effect parameter sufficient?
- Does the home effect vary materially between leagues?
- How quickly do static parameters become stale?
- Where does the independent Poisson model systematically miscalibrate?
- Are low-score cells the dominant problem in our data?
- Are high-score tails over- or under-predicted?
- How much does Dixon-Coles improve over this baseline on modern Serie A?
- Does Bivariate Poisson outperform both?
- Are improvements stable across seasons or caused by a few anomalous periods?

---

## 22. Strengths

- simple;
- interpretable;
- low data requirement;
- reproducible;
- full score distribution;
- jointly estimates opponent-adjusted attack and defence;
- allows explicit home effect;
- provides many market probabilities from one model;
- excellent benchmark for measuring whether later complexity is useful.

---

## 23. Limitations

- conditional independence may be imperfect;
- static team strengths can become stale;
- no event-level information;
- no xG;
- no explicit recency weighting in the clean baseline;
- Poisson variance assumptions may not fully match football data;
- promoted/new teams need initialization rules;
- model quality depends strongly on estimation sample design;
- it should not be assumed that historical goodness-of-fit implies modern predictive superiority.

---

## 24. Relationship to Dixon-Coles

The clean conceptual progression is:

```text
Maher / Independent Poisson
│
├── attack strength
├── defence strength
├── home effect
└── score probability matrix
        ↓
Dixon-Coles
│
├── same core goal-intensity structure
├── low-score dependence correction
└── temporal weighting / dynamic estimation
```

Therefore Robetting should not implement Dixon-Coles without first having a validated Poisson implementation.

Otherwise we lose the ability to quantify what the Dixon-Coles extensions actually contribute.

---

## 25. Robetting decision

### Current status

```text
RB-P-001 = REQUIRED BASELINE
```

This does **not** mean:

```text
RB-P-001 = EXPECTED PRODUCTION WINNER
```

Its purpose is scientific control.

Every future model should be able to answer:

> Does it improve probabilistic forecasts over RB-P-001 on truly unseen matches?

---

## 26. Evidence vs Robetting design choices

### Directly supported by Maher (1982)

- further investigation of Poisson models for football scores;
- explicit team attacking and defensive strength parameters;
- comparison of a hierarchy of models;
- independent Poisson gives a reasonably accurate overall description despite small systematic discrepancies;
- a bivariate Poisson specification with positive correlation can improve fit.

### Robetting design choices derived from the source

- model id `RB-P-001`;
- using this as the minimum statistical baseline;
- starting with Serie A;
- excluding xG/odds/ML features from the baseline;
- walk-forward evaluation;
- Log Loss, Brier, RPS and calibration;
- persistence of lambda and derived market probabilities;
- direct controlled comparison with `RB-DC-001`.

These are Robetting methodology decisions, not claims made by Maher.

---

## Sources

1. Maher, M. J. (1982), *Modelling Association Football Scores*, Statistica Neerlandica, 36(3), 109–118. DOI: 10.1111/j.1467-9574.1982.tb00782.x
2. IDEAS/RePEc bibliographic record: https://ideas.repec.org/a/bla/stanee/v36y1982i3p109-118.html
3. Accessible paper copy: https://www.90minut.pl/misc/maher.pdf

---

## Knowledge-base tags

```text
football-analytics
probability
poisson
maher
goal-model
attack-strength
defence-strength
home-advantage
maximum-likelihood
baseline
calibration
backtesting
robetting
```
