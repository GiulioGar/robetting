# Elo Ratings / Hvattum–Arntzen

## Status

**Source status:** verified primary source  
**Robetting status:** team-strength candidate / benchmark feature  
**Suggested rating id:** `RB-ELO-001`  
**Decision:** not yet adopted as production predictor; should be implemented after the first goal-model baselines and evaluated both as a standalone strength signal and as a covariate.

---

## Primary source

Hvattum, L. M. & Arntzen, H. (2010).  
*Using ELO ratings for match result prediction in association football.*  
International Journal of Forecasting, 26(3), 460–470.  
DOI: `10.1016/j.ijforecast.2009.10.002`

Official journal record:
https://www.sciencedirect.com/science/article/pii/S0169207009001708

---

## 1. Problem addressed

The paper asks whether a dynamically updated Elo rating can encode football team strength well enough to improve match-result forecasts.

The authors do **not** use Elo alone as a direct three-way probability model.

Instead they:

1. compute Elo ratings from past results;
2. take the pre-match rating difference between the home and away team;
3. use that rating difference as a covariate in an ordered logit model;
4. generate probabilities for home win, draw and away win;
5. compare the Elo-based methods with six benchmark methods.

This distinction is important for Robetting:

```text
Elo rating
!=
complete match probability model
```

Elo is primarily a dynamic strength representation.

A second model is still needed if we want calibrated 1X2 probabilities.

---

## 2. Core Elo idea

Before a match, let:

```text
R_H = current home-team Elo rating
R_A = current away-team Elo rating
```

The expected score for the home team is a logistic function of the rating difference.

The paper writes this in a general form using scale parameters.

Conceptually:

```text
ExpectedHome
=
1 / (1 + c^((R_A - R_H)/d))
```

and:

```text
ExpectedAway
=
1 - ExpectedHome
```

The actual score is encoded as:

```text
win  = 1
draw = 0.5
loss = 0
```

After the match:

```text
R_H,new
=
R_H,old
+
k * (ActualHome - ExpectedHome)
```

and the away rating is updated analogously.

The key mechanism is:

```text
performance above expectation
→ rating increases

performance below expectation
→ rating decreases
```

---

## 3. Dynamic team strength

Unlike a static regression coefficient, the Elo rating follows the team through time and is updated after every match.

This gives Elo a useful property for Robetting:

```text
old matches affect today's rating indirectly
through the current rating state
```

rather than needing every historical result to remain an explicit covariate.

The authors emphasize that Elo can be an efficient way of encoding past results, especially when using relatively short historical spans.

---

## 4. Importance of the update coefficient k

The parameter `k` controls how fast ratings react.

If `k` is too small:

```text
rating changes too slowly
```

and may fail to capture a genuine change in team strength.

If `k` is too large:

```text
rating becomes too volatile
```

and overreacts to individual results.

Therefore `k` is a hyperparameter that must be calibrated rather than copied mechanically.

### Robetting implication

Do not hardcode a conventional chess Elo `K`.

Estimate or validate `K` on football data using chronological out-of-sample performance.

---

## 5. Goal-difference Elo variant

The paper also studies a football-specific modification where the Elo update magnitude depends on the absolute goal difference.

Conceptually:

```text
k_match
=
k0 * (1 + goal_difference)^lambda
```

where:

```text
k0 > 0
lambda > 0
```

This means, for example:

```text
3-0
```

can produce a larger rating change than:

```text
2-1
```

even though both are wins.

The paper labels this goal-based Elo variant separately from the basic Elo system.

### Robetting implication

We should test two clean variants:

```text
RB-ELO-BASIC-001
RB-ELO-GD-001
```

rather than silently mixing margin-of-victory weighting into the baseline.

---

## 6. Initial ratings

Elo requires a starting rating for every team.

The paper explicitly notes that ratings cannot be expected to be reliable until a sufficient number of historical results have been incorporated.

This creates a practical football problem:

```text
newly promoted team
new competition
missing historical seasons
renamed/merged team identities
```

### Robetting open design issue

We need a principled initialization rule.

Candidate approaches:

```text
league mean
promoted-team prior
previous-division rating
previous-season rating
country-wide rating pool
```

No option should be chosen without testing.

---

## 7. Elo as a covariate

The main prediction model in the paper uses the rating difference:

```text
x = R_H - R_A
```

as a covariate in an **ordered logit regression**.

The dependent result has three ordered outcomes:

```text
away win
draw
home win
```

The ordered logit then converts the scalar rating difference into probabilities for the three outcomes.

This is methodologically important.

A rating difference by itself tells us:

```text
relative strength
```

but not automatically a fully calibrated:

```text
P(Home)
P(Draw)
P(Away)
```

distribution.

---

## 8. Home advantage

In the paper's Elo prediction framework, the home/away context is represented through the rating-difference setup and model calibration rather than by simply assuming neutral-site ratings.

For Robetting, home advantage should remain explicit and testable.

Possible implementations include:

```text
home-rating bonus
ordered-logit intercept/threshold structure
league-specific home effect
```

The exact Robetting choice must be documented and compared empirically.

---

## 9. Benchmark methodology

A major strength of Hvattum & Arntzen is that they do not evaluate Elo in isolation.

They compare Elo-based forecasts against six benchmarks, including:

```text
uniform probabilities
historical frequency methods
other statistical prediction methods
bookmaker-odds-derived probabilities
```

Bookmaker probabilities are obtained by taking inverse odds and normalizing them so that the three outcome probabilities sum to one.

This is a simple de-vig approach.

### Robetting implication

The paper strongly supports our architectural principle:

```text
statistical model
vs
market benchmark
```

The market should be evaluated as a benchmark even when it is not used as an input to the prediction engine.

---

## 10. Evaluation metrics

The paper evaluates probabilistic predictions using:

```text
quadratic loss
informational loss
```

Quadratic loss is identified with the Brier-score family.

Informational loss corresponds to logarithmic/information-based scoring.

The authors explicitly argue that probabilistic prediction methods should be evaluated by comparing the forecast probabilities with the observed outcome.

They also use economic/betting measures, but distinguish these from statistical forecast quality.

### Robetting implication

This directly reinforces our evaluation policy:

```text
probabilistic quality first
ROI/yield second
```

A model should not be called superior merely because one historical staking simulation happened to be profitable.

---

## 11. Main empirical finding

The Elo-based prediction methods outperform the non-market statistical benchmarks considered in the paper in terms of observed probabilistic loss.

However, the two methods based on betting-market odds outperform the Elo-based methods.

The authors conclude that the Elo rating difference is a highly significant predictor of football match outcomes, while also highlighting how difficult it is for statistical models to match market probabilities.

### Robetting interpretation

This is a useful benchmark result, not a promise that Elo will outperform current bookmakers.

Our task is to test whether an Elo-based strength signal adds incremental value to Robetting's modern models.

---

## 12. Why Elo is attractive for Robetting

Elo compresses historical results into one current state per team.

Instead of using a large set of raw historical-result covariates:

```text
last match
last 2
last 3
...
opponent history
```

we obtain:

```text
current_team_rating
```

The rating already reflects:

- opponent strength;
- unexpected wins/losses;
- recency through sequential updating;
- cumulative historical performance.

This makes Elo a potentially efficient feature.

---

## 13. Important limitation: one scalar strength

Classic Elo gives each team one number.

That means it does not separately represent:

```text
attack strength
defence strength
```

This differs fundamentally from Poisson/Dixon–Coles.

Example:

```text
Team A:
excellent attack
weak defence

Team B:
average attack
average defence
```

could potentially have similar overall Elo ratings while implying different goal distributions.

### Robetting implication

Elo should initially be viewed as:

```text
team-strength signal
```

not as a replacement for attack/defence goal models.

---

## 14. Data required

A basic Elo implementation needs:

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

For goal-difference Elo:

```text
home_score_ft
away_score_ft
```

are sufficient to calculate margin of victory.

No xG, shots or odds are required to construct the rating itself.

---

## 15. Strict chronological requirement

Elo is inherently sequential.

For a prediction at time `T`:

```text
rating used
=
rating immediately before kickoff at T
```

The result of that match must update the rating **only after** the prediction has been stored.

This creates a very clean anti-leakage workflow:

```text
read current ratings
↓
generate prediction
↓
store prediction
↓
match happens
↓
update ratings
```

For historical backtesting, events must be replayed in chronological order.

---

## 16. Rating history should be persisted or reproducible

For auditability, Robetting should be able to reconstruct:

```text
Inter Elo before match X
Inter Elo after match X
```

Possible storage:

```text
team_id
rating_model_version
match_id
rating_before
rating_after
updated_at
```

or deterministic regeneration from historical matches.

Persisting snapshots may simplify debugging and research.

This is a Robetting implementation choice, not a requirement of the paper.

---

## 17. Proposed Robetting models

### Basic rating

```text
RB-ELO-BASIC-001
```

Inputs:

```text
W/D/L
opponent rating
pre-match rating difference
K
```

### Goal-difference variant

```text
RB-ELO-GD-001
```

Additional input:

```text
absolute goal difference
```

### Probability wrapper

Potential later model:

```text
RB-ELO-OL-001
```

where:

```text
Elo rating difference
↓
ordered logit
↓
P(Home), P(Draw), P(Away)
```

This most closely mirrors the paper's predictive setup.

---

## 18. Elo as feature rather than standalone model

A later Robetting experiment could test Elo inside a richer model:

```text
Dixon-Coles lambda estimates
Elo difference
home advantage
recent xG
shots
other features
        ↓
calibration / ensemble layer
```

But this must happen only after we measure Elo independently.

Otherwise we cannot determine its marginal contribution.

---

## 19. Proposed experiment

### Experiment ID

`EXP-ELO-001`

### Objective

Measure whether dynamically updated Elo ratings contain useful out-of-sample information about modern Serie A match outcomes.

### Models

```text
RB-ELO-BASIC-001
RB-ELO-GD-001
RB-ELO-OL-001
```

### Dataset

Chronological Serie A history.

### Procedure

```text
initialize ratings
↓
replay historical matches chronologically
↓
before each test match:
    save ratings
    generate probabilities
↓
after result:
    update ratings
```

### Hyperparameters

At minimum:

```text
initial rating
K / k0
rating scale
goal-difference lambda
home effect
```

These must be tuned only on training/validation history.

---

## 20. Evaluation

Primary metrics:

```text
1X2 Log Loss
Brier Score
RPS
Calibration
```

Comparisons:

```text
naive frequency baseline
RB-P-001
RB-DC-001
RB-ELO-OL-001
de-vig bookmaker market
```

Secondary diagnostics:

```text
rating stability
promotion initialization
season-transition behaviour
response to winning/losing streaks
home/away calibration
```

---

## 21. Experiments worth separating

Do not put every Elo modification into one model.

Suggested research sequence:

```text
EXP-ELO-001
basic Elo

EXP-ELO-002
goal-difference Elo

EXP-ELO-003
home-advantage treatment

EXP-ELO-004
season carry-over

EXP-ELO-005
promoted-team initialization

EXP-ELO-006
league-specific K

EXP-ELO-007
Elo + Dixon-Coles ensemble/feature
```

This allows each modification to earn its complexity.

---

## 22. Open questions for Robetting

- What initial rating should all teams receive?
- Should promoted clubs inherit a second-division rating?
- Should ratings partially regress toward league mean between seasons?
- What `K` gives the best out-of-sample calibration?
- Should `K` vary by competition?
- Should `K` vary during the first matches of a season?
- Does goal-difference weighting improve predictions?
- How much home advantage should enter the rating system?
- Should home advantage be league-specific?
- Should cup matches update league Elo ratings?
- Should European matches affect domestic ratings?
- Can one unified European Elo pool improve cross-league comparisons?
- Does Elo add information beyond Dixon-Coles attack/defence strengths?
- Does Elo help most early in the season?
- How robust is Elo when coaching/squad changes are large?
- Should player-transfer information ever affect initialization?
- Does Elo improve 1X2 but not goal-based markets?
- Does Elo remain useful once market odds are included only as an evaluation benchmark?

---

## 23. Strengths

- very simple state representation;
- dynamically updated;
- opponent-adjusted by construction;
- interpretable;
- computationally cheap;
- requires only historical results;
- naturally chronological;
- useful for ranking and relative team strength;
- can serve as a compact covariate in other models;
- empirically shown in the paper to be a significant predictor.

---

## 24. Limitations

- one scalar rating does not separate attack and defence;
- initialization matters;
- K-factor choice matters;
- classic Elo does not directly produce calibrated 1X2 probabilities;
- football draws require an additional probability model;
- goal margin treatment is a modelling choice;
- season transitions can create structural breaks;
- promoted teams are problematic;
- market probabilities outperformed the Elo methods in the paper;
- good ranking performance does not automatically imply good probability calibration.

---

## 25. Robetting decision

### Current status

```text
RB-ELO = RESEARCH CANDIDATE
```

Role:

```text
TEAM-STRENGTH MODEL / FEATURE
```

not:

```text
PRIMARY GOAL DISTRIBUTION MODEL
```

Initial priority remains:

```text
1. RB-P-001
2. RB-DC-001
3. RB-BP-001
4. RB-ELO research track
```

However Elo can be implemented relatively cheaply and may become useful early as a diagnostic team-strength measure.

---

## 26. Evidence vs Robetting design choices

### Directly supported by Hvattum & Arntzen (2010)

- Elo ratings as a representation of current team strength;
- sequential updating after each match;
- win/draw/loss encoded as 1 / 0.5 / 0;
- update based on actual minus expected score;
- sensitivity of the update factor;
- goal-difference-dependent Elo variant;
- Elo rating difference used as a covariate;
- ordered logit used to generate 1X2 probabilities;
- probabilistic evaluation using quadratic and informational loss;
- comparison with multiple benchmarks;
- bookmaker-odds-based probabilities outperforming the statistical methods examined;
- Elo-based methods outperforming the other non-market statistical methods considered;
- rating difference being a significant predictor.

### Robetting design choices

- ids `RB-ELO-BASIC-001`, `RB-ELO-GD-001`, `RB-ELO-OL-001`;
- Serie A as the first experiment;
- persistence of rating snapshots;
- comparison with Poisson and Dixon-Coles;
- testing season regression and promoted-team priors;
- eventual use of Elo as a feature in ensembles;
- using Log Loss, Brier, RPS and calibration as the common Robetting scorecard.

These are project decisions, not claims made by the paper.

---

## Sources

1. Hvattum, L. M. & Arntzen, H. (2010), *Using ELO ratings for match result prediction in association football*, International Journal of Forecasting, 26(3), 460–470. DOI: 10.1016/j.ijforecast.2009.10.002
2. Official journal record: https://www.sciencedirect.com/science/article/pii/S0169207009001708

---

## Knowledge-base tags

```text
football-analytics
elo
team-strength
ratings
ordered-logit
dynamic-rating
goal-difference
forecasting
calibration
brier
log-loss
market-benchmark
robetting
```
