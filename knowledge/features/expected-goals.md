# Expected Goals (xG)

## Status

**Source status:** supported by peer-reviewed research and official StatsBomb documentation  
**Robetting status:** important future feature / model family, not required for the first baseline  
**Suggested research id:** `RB-XG-RESEARCH-001`  
**Decision:** Robetting should study xG as a shot-quality model and as an input for future team-strength / prediction models, but should not call simple match-level shot statistics "xG".

---

## Key sources

### Peer-reviewed research

**Expected goals in football: Improving model performance and demonstrating value**  
PLOS ONE.  
https://journals.plos.org/plosone/article?id=10.1371/journal.pone.0282295

This paper studies xG as a probability assigned to individual shots and evaluates how additional contextual variables can improve predictive performance.

### StatsBomb / Hudl official material

Expected Goals (xG) glossary:
https://support.hudl.com/s/article/expected-goals?language=en_US&topic=Statsbomb_Global_Football_Data_Glossary

StatsBomb Open Data:
https://github.com/hudl/open-data

StatsBomb whitepapers:
https://support.hudl.com/s/article/whitepapers?language=en_US&topic=Statsbomb_Global_Football_Data_Glossary

---

## 1. What xG actually is

Expected Goals is a probability model applied to **shots**.

For every shot:

```text
shot features
↓
model
↓
P(goal | shot characteristics)
```

The output is a number between:

```text
0 and 1
```

Example:

```text
Shot A = 0.05 xG
Shot B = 0.20 xG
Shot C = 0.65 xG
```

Interpretation:

```text
0.20 xG
```

means that shots with comparable characteristics are estimated to become goals about 20% of the time under the model.

xG therefore measures the estimated quality of a scoring opportunity, not whether the shot actually became a goal.

---

## 2. xG is a binary probabilistic classification problem

For each historical shot:

```text
target:
goal = 1
no goal = 0
```

The model estimates:

```text
P(goal = 1 | features)
```

Common modelling approaches in the literature include:

```text
logistic regression
gradient boosting
tree-based models
neural networks
other probabilistic classifiers
```

For Robetting, logistic regression is an attractive research baseline because it is interpretable and gives a direct probability.

A more sophisticated model should only be adopted if it improves out-of-sample probability quality and calibration.

---

## 3. Core shot features

A useful xG model normally needs information describing the individual shot.

Core features commonly include:

```text
shot location
distance from goal
angle to goal
body part
shot type
assist / preceding action
set piece vs open play
```

Richer event providers can add:

```text
defensive pressure
goalkeeper position
number/location of defenders
shot technique
one-on-one context
freeze-frame / surrounding players
```

The exact features depend on the data provider.

---

## 4. Distance and angle

Two of the most fundamental variables are:

```text
distance from goal
shot angle
```

Intuitively:

```text
closer shot
→ generally higher scoring probability

more central / wider visible goal angle
→ generally higher scoring probability
```

These variables often form a minimal xG baseline.

However, they are not enough for a high-quality modern xG model.

Two shots from the same coordinates can have very different scoring probabilities depending on:

```text
body part
defensive pressure
goalkeeper position
pass type
shot type
match situation
```

---

## 5. Match context

Peer-reviewed research has found that contextual variables can improve xG model performance.

Examples include information describing the state of play and the circumstances surrounding a shot.

This is important for Robetting because:

```text
same shot coordinate
!=
same chance quality
```

if the surrounding context differs.

Therefore a future Robetting xG model should be treated as a feature-engineering problem, not merely as a distance-to-goal formula.

---

## 6. Team xG

For a match, individual shot xG values are commonly summed:

```text
Team xG
=
sum(xG of team's shots)
```

Example:

```text
Shot 1 = 0.08
Shot 2 = 0.21
Shot 3 = 0.46
Shot 4 = 0.07
```

Team xG:

```text
0.82
```

This is useful as a measure of the volume and quality of chances created.

Likewise:

```text
xGA
=
sum of opponent xG
```

represents expected goals against.

---

## 7. Important statistical caution about summed xG

Summing shot probabilities gives:

```text
expected number of goals
```

under the model.

But:

```text
Team xG = 2.0
```

does **not** mean:

```text
the team had exactly a 2-goal "true score"
```

nor:

```text
the team had a fixed probability of scoring exactly 2 goals
```

The complete distribution of goals depends on the individual shot probabilities and assumptions about dependence between shots.

Robetting must keep this distinction clear.

---

## 8. xG vs actual goals

Actual goals are sparse and noisy.

A team can produce:

```text
2.0 xG
0 goals
```

or:

```text
0.7 xG
2 goals
```

in an individual match.

Therefore single-match differences between goals and xG are not necessarily evidence that the model is wrong.

Over larger samples, xG can provide information about underlying chance creation and concession that raw goals alone may hide.

For Robetting, this makes xG attractive as a **team-performance feature**, especially when estimating attack and defence strength.

---

## 9. xG is model-dependent

There is no universal xG value inherent in a shot.

Different providers/models may estimate:

```text
Shot A:
0.18
0.22
0.25
```

because they use:

```text
different training data
different features
different model families
different calibration
different definitions
```

Therefore:

```text
xG
```

must always be interpreted as:

```text
xG according to model/provider X
```

Robetting should store the xG source/model version when possible.

---

## 10. Data requirement: event-level data

A genuine xG model needs individual shot events.

Minimum useful structure:

```text
match_id
event_id
team_id
player_id
minute
second
shot_x
shot_y
shot_outcome
body_part
shot_type
```

Useful richer fields:

```text
under_pressure
assist_type
technique
first_time
one_on_one
goalkeeper_position
defender_locations
freeze_frame
```

Without shot-level event data, Robetting cannot train a conventional xG model.

---

## 11. Match-level shots are not enough

Suppose the database only contains:

```text
home_shots = 14
away_shots = 9
home_shots_on_target = 5
away_shots_on_target = 3
```

This does **not** contain enough information to reconstruct proper xG.

Why?

Because:

```text
10 shots from 30 metres
```

and:

```text
10 shots from inside the six-yard box
```

have identical shot counts but radically different chance quality.

Therefore:

```text
shots
shots on target
```

can be useful prediction features, but they should never be renamed or treated as xG.

---

## 12. StatsBomb Open Data

StatsBomb/Hudl provides a public open-data repository containing football event data for selected competitions and matches.

The repository includes event files and, for selected matches, StatsBomb 360 information.

This makes the dataset valuable for:

```text
learning event-data structures
building prototype xG models
testing shot feature engineering
reproducing analytics experiments
```

It does not automatically provide complete historical coverage for every league/season Robetting needs.

Therefore it should initially be considered:

```text
RESEARCH DATASET
```

rather than Robetting's guaranteed production xG source.

---

## 13. StatsBomb 360

StatsBomb 360 enriches selected events with information about surrounding players.

This can support features related to:

```text
defensive pressure
defender positions
available shooting space
goalkeeper context
```

Such information can improve the description of chance quality compared with location-only models.

However, coverage and licensing/source availability must be evaluated before any production dependency is created.

---

## 14. Proposed Robetting xG research baseline

### Model ID

```text
RB-XG-LR-001
```

### Purpose

Create a simple, interpretable xG model using open event data.

### Initial model

Logistic regression.

### Candidate minimal features

```text
distance
angle
body_part
open_play/set_piece
```

Then progressively test:

```text
assist type
shot technique
pressure
one-on-one
other event context
```

Every feature addition should be evaluated independently.

---

## 15. Evaluation of an xG model

An xG model is a binary probabilistic classifier.

Therefore primary metrics should include:

```text
Log Loss
Brier Score
Calibration
```

Additional diagnostics can include:

```text
calibration curve
reliability by xG range
ROC-AUC
precision/recall diagnostics
```

But AUC alone is insufficient because Robetting needs well-calibrated probabilities, not merely ranking ability.

---

## 16. Calibration by probability range

Example:

```text
Predicted xG bucket   Actual conversion

0.00-0.05             4%
0.05-0.10             7%
0.10-0.20             15%
0.20-0.30             25%
0.30-0.50             39%
0.50-0.70             61%
0.70-1.00             79%
```

The goal is not for every individual shot to behave as predicted.

The goal is for large groups of similarly predicted shots to convert at approximately the predicted frequency.

---

## 17. Leakage warning

When training xG, features must represent information known at the moment of the shot.

Do not include fields that are consequences of the shot outcome.

For example, a feature generated after knowing whether the ball entered the goal would trivially leak the target.

Robetting must classify event fields carefully before training.

---

## 18. xG as a match-prediction feature

Robetting's main purpose is pre-match prediction, not merely shot evaluation.

Therefore the most important future application may be historical team xG features.

Examples:

```text
rolling xG for
rolling xGA
home xG
away xG
xG difference
xG per shot
opponent-adjusted xG
time-decayed xG
```

These can provide a richer measure of team performance than:

```text
goals scored
goals conceded
```

alone.

---

## 19. The crucial "as-of" rule

For a prediction before:

```text
Inter - Milan
20 September
```

Robetting may use only xG information from events before that kickoff.

For example:

```text
Inter rolling xG
```

must be calculated strictly from earlier matches.

No season-final xG statistics.

No future match events.

This is the same anti-leakage principle used across the rest of Robetting.

---

## 20. Opponent adjustment

Raw xG can still be context-dependent.

Example:

```text
1.8 xG vs elite defence
```

may convey different information from:

```text
1.8 xG vs weak defence
```

A later Robetting analytics layer should therefore test opponent-adjusted xG.

Candidate approaches:

```text
Elo-adjusted
opponent xGA strength
hierarchical attack/defence model
regression residual adjustment
```

No method is currently adopted.

---

## 21. xG and Poisson/Dixon-Coles

xG does not necessarily replace goal models.

Possible future architecture:

```text
historical xG / xGA
↓
estimate current attacking and defensive strength
↓
estimate lambda_home / lambda_away
↓
Poisson / Dixon-Coles score model
↓
1X2 / O-U / BTTS
```

Another option:

```text
xG features
+
Elo
+
other match statistics
↓
direct probabilistic model
```

Both approaches should be tested rather than assumed.

---

## 22. Post-shot xG

A distinction exists between:

```text
pre-shot xG
```

and:

```text
post-shot xG / PSxG
```

Standard xG estimates chance quality at the moment of the shot using shot context.

Post-shot models may incorporate information about where/how the shot travels relative to goal and goalkeeper.

For pre-match team-strength analysis, these concepts should not be mixed.

Robetting's initial xG research should focus on conventional pre-shot xG.

---

## 23. Penalties

Penalties are special events.

They have:

```text
fixed location
unusual context
relatively high conversion probability
```

A model can treat penalties:

```text
as a separate class
```

or assign them a fixed/historical probability.

Robetting must document the chosen treatment.

When evaluating open-play chance creation, penalties may also be separated analytically.

---

## 24. Own goals

Own goals are not conventional shot conversions.

They should not automatically be included as normal xG shots.

The event provider's definitions must be respected.

Robetting should maintain explicit rules for:

```text
own goals
penalties
shootouts
extra time
```

when working with event data.

---

## 25. Rebounds and shot dependence

Shots within the same possession may not be independent.

Example:

```text
shot
↓
goalkeeper save
↓
immediate rebound
↓
second shot
```

Simply summing both shot probabilities can overstate the true probability of scoring within the sequence if the events are conditionally dependent.

More advanced possession-level models address this issue.

For initial Robetting research, standard summed xG is acceptable as a conventional metric, but this limitation must be remembered.

---

## 26. Proposed first xG experiment

### Experiment ID

```text
EXP-XG-001
```

### Dataset

StatsBomb Open Data.

### Objective

Build an interpretable shot-level logistic-regression xG baseline.

### Target

```text
goal = 1
non-goal = 0
```

### Baseline features

```text
shot distance
shot angle
body part
shot type
```

### Evaluation

Use chronological or competition-aware split where feasible.

Report:

```text
Log Loss
Brier
Calibration
sample size
```

### Second experiment

```text
EXP-XG-002
```

Add contextual features and determine whether they improve out-of-sample probability quality.

---

## 27. Proposed team-level experiment

### Experiment ID

```text
EXP-XG-TEAM-001
```

### Objective

Test whether historical xG features improve pre-match prediction relative to goals-only features.

Compare:

```text
Goals model
vs
xG-enhanced model
```

Candidate inputs:

```text
rolling xG for
rolling xGA
xG difference
home/away xG
opponent-adjusted xG
```

### Evaluation

Same unseen matches.

Same cutoffs.

Same metrics.

No retrospective season aggregates.

---

## 28. Data-source decision for Robetting

Current status:

```text
StatsBomb Open Data
=
research / prototyping source
```

not:

```text
guaranteed production xG feed
```

Before production use we must establish:

```text
coverage
licensing
competitions
historical depth
update frequency
API/file access
commercial-use constraints
```

Robetting should not architect the production database around one research dataset before these questions are resolved.

---

## 29. Open questions for Robetting

- Do our current APIs provide genuine provider xG?
- If yes, how is that xG defined?
- Can the source/model version be identified?
- Is historical xG coverage sufficient for all five target leagues?
- Should we consume provider xG or build our own?
- Is an internally trained xG model worth the maintenance cost?
- How much does xG improve match forecasting versus raw goals?
- Does xG add value beyond shots and shots on target?
- Which rolling horizon performs best?
- Should recent xG be exponentially time-decayed?
- Should xG be opponent-adjusted?
- Should penalties be included in team attacking-strength features?
- Does non-penalty xG provide a more stable signal?
- How should promoted teams be handled?
- Does xG help mainly goal markets or also 1X2?
- Is xG performance stable across leagues?
- Can a provider's xG be legally persisted and displayed?

---

## 30. Strengths

- models chance quality instead of goals alone;
- produces probabilistic shot values;
- uses much more frequent events than goals;
- can capture underlying attacking/defensive performance;
- useful for team and player analysis;
- can enrich pre-match features;
- naturally compatible with calibration methodology;
- open datasets allow research prototyping.

---

## 31. Limitations

- requires event-level shot data;
- model/provider dependent;
- feature availability differs by source;
- standard xG conditions on observed shots;
- shot dependence can complicate aggregation;
- richer features may not be available historically;
- xG does not measure all attacking value;
- good shot-level xG does not automatically imply better match predictions;
- provider/licensing coverage may block production use.

---

## 32. Robetting decision

### Adopted research principle

```text
xG is a shot-level probability model
```

Therefore:

```text
shot counts != xG
shots on target != xG
```

### Current role

```text
FUTURE ANALYTICS / FEATURE LAYER
```

not:

```text
REQUIREMENT FOR RB-P-001
RB-DC-001
RB-BP-001
```

### Research sequence

```text
1. Finish goals-only baselines
2. Validate available event/xG data
3. Prototype RB-XG-LR-001
4. Evaluate shot-level calibration
5. Build historical rolling xG/xGA
6. Test whether xG improves pre-match probabilities
```

---

## 33. Evidence vs Robetting design choices

### Supported by peer-reviewed / official sources

- xG assigns a probability of scoring to individual shots;
- the probability is between 0 and 1;
- statistical and machine-learning classifiers can be used for xG;
- shot characteristics and contextual features influence model quality;
- official StatsBomb material defines xG using historical shots with similar characteristics;
- StatsBomb publishes selected football event data as Open Data;
- richer contextual data can support more detailed shot models.

### Robetting design choices

- `RB-XG-LR-001` as first prototype;
- logistic regression as the research baseline;
- StatsBomb Open Data as an initial research dataset;
- use of Log Loss/Brier/Calibration;
- eventual rolling xG/xGA team features;
- separation of research data source from production data source;
- keeping xG out of the first clean Poisson/Dixon-Coles baselines.

---

## Sources

1. *Expected goals in football: Improving model performance and demonstrating value*, PLOS ONE.
2. Hudl StatsBomb Expected Goals glossary.
3. Hudl/StatsBomb Open Data GitHub repository.
4. Hudl StatsBomb whitepapers.

---

## Knowledge-base tags

```text
football-analytics
expected-goals
xg
shots
event-data
logistic-regression
machine-learning
calibration
statsbomb
xga
shot-quality
feature-engineering
robetting
```
