# Machine Learning for Football Match Prediction

## Status

**Source status:** supported by recent review literature and comparative football studies  
**Robetting status:** future model family / controlled research track  
**Suggested research id:** `RB-ML-001`  
**Decision:** Machine learning should be introduced only after strong statistical baselines are validated. Complexity alone is not evidence of predictive superiority.

---

## Key sources

### Bunker, Yeung & Fujii (2024)

*Machine Learning for Soccer Match Result Prediction.*

This chapter reviews datasets, model families, features and evaluation methods used in soccer match-result prediction.

Preprint:
https://arxiv.org/abs/2403.07669

Springer chapter:
https://link.springer.com/content/pdf/10.1007/978-3-031-76047-1_2.pdf

Main review finding relevant to Robetting:

Gradient-boosted tree models combined with soccer-specific rating features are among the strongest reported approaches on datasets where goals are the main match features.

The authors also emphasize that:

- public benchmark datasets are limited;
- studies often use different datasets and feature definitions;
- direct comparison across papers is difficult;
- deep learning vs Random Forest vs boosting still requires more systematic comparison;
- interpretability remains important.

### Fischer & Heuer (2024)

*Match predictions in soccer: Machine learning vs. Poisson approaches.*

Preprint:
https://arxiv.org/abs/2408.08331

The study compares:

```text
Poisson approaches
Random Forest
Neural Networks
```

across the five major European leagues.

A central result is that, in their experimental setup:

```text
choice of feature set
and
choice of model
```

have only a relatively modest effect on prediction quality.

This is highly relevant for Robetting because it warns against assuming that more complex models automatically deliver large forecasting improvements.

### Yeung et al. (2024)

*Evaluating Soccer Match Prediction Models: A Deep Learning Approach and Feature Optimization for Gradient-Boosted Trees.*

Machine Learning.

Preprint:
https://arxiv.org/abs/2309.14807

This work evaluates deep learning and gradient-boosted tree models in the context of the Soccer Prediction Challenge and emphasizes the importance of benchmark datasets, feature selection and probability-based evaluation.

---

## 1. What machine learning means here

For Robetting, "machine learning" should not mean:

```text
use AI
```

as a generic label.

It means fitting a model from historical examples:

```text
match features
↓
learning algorithm
↓
probability distribution
```

Possible outputs:

```text
P(Home)
P(Draw)
P(Away)
```

or:

```text
expected home goals
expected away goals
```

or:

```text
P(Over 2.5)
P(BTTS)
```

The target and output must be defined explicitly.

---

## 2. Main ML families

Relevant candidate families include:

```text
Logistic Regression
Multinomial Logistic Regression
Random Forest
Gradient Boosted Trees
XGBoost
LightGBM
CatBoost
Neural Networks
Deep Learning
```

Robetting should not test all of them simultaneously without controlled baselines.

A sensible sequence is:

```text
simple linear probabilistic model
↓
tree ensemble
↓
boosting
↓
only then deeper neural architectures
```

---

## 3. Direct 1X2 prediction

One approach is:

```text
features
↓
multiclass model
↓
P(Home), P(Draw), P(Away)
```

Possible algorithms:

```text
multinomial logistic regression
CatBoost
XGBoost
neural network
```

Advantages:

- direct optimization toward 1X2;
- flexible nonlinear relationships;
- easy inclusion of diverse features.

Limitations:

- does not automatically produce a coherent score distribution;
- Over/Under and BTTS require separate models or additional structure;
- probabilities may require calibration.

---

## 4. Goal prediction with ML

An alternative is to predict goals.

Example:

```text
features
↓
ML model
↓
expected home goals
expected away goals
```

or even:

```text
goal-count distributions
```

Then derive:

```text
1X2
Over/Under
BTTS
Correct Score
```

This retains some of the structural advantages of Poisson-family models.

Recent tournament modelling also combines statistical learning approaches to predict expected goal counts and then simulate tournaments.

---

## 5. Feature engineering is often more important than algorithm branding

The review literature repeatedly shows that soccer-specific features matter.

Candidate features include:

```text
Elo / pi-rating
attack strength
defence strength
home advantage
recent goals
recent xG
recent xGA
shots
shots on target
opponent strength
rest days
league position
player strength
lineup strength
```

A powerful algorithm trained on poor or leaked features can be worse than a simple model using strong, temporally valid features.

Robetting should treat:

```text
feature engineering
```

as a separate research layer from:

```text
model family
```

---

## 6. Soccer-specific ratings

Bunker et al. highlight strong reported performance from boosting models when using soccer-specific rating systems.

This supports a future architecture like:

```text
Elo
pi-rating
Poisson strength
xG strength
other historical features
↓
CatBoost / XGBoost
↓
1X2 probabilities
```

But the marginal value of every rating must be tested.

---

## 7. Logistic regression as ML baseline

Before Random Forest or XGBoost, Robetting should implement a simple probabilistic baseline:

```text
RB-ML-LR-001
```

Candidate inputs:

```text
Elo difference
home advantage
recent attack strength
recent defence strength
```

Output:

```text
P(Home)
P(Draw)
P(Away)
```

Why?

- interpretable coefficients;
- fast;
- low overfitting risk;
- good calibration baseline;
- useful control for nonlinear models.

---

## 8. Gradient Boosted Trees

Gradient boosting is especially attractive because it can model:

```text
nonlinearities
feature interactions
missing values
threshold effects
```

without requiring a large neural network.

Candidates:

```text
XGBoost
LightGBM
CatBoost
```

Bunker et al. identify boosted-tree approaches as among the strongest methods reported in parts of the football prediction literature.

### Robetting candidate

```text
RB-ML-GBT-001
```

Start with one implementation only.

Do not test three libraries and treat tiny differences as meaningful without nested validation.

---

## 9. Random Forest

Random Forest is robust and useful as a benchmark.

Strengths:

```text
nonlinear
few distributional assumptions
interaction handling
feature importance tools
```

Limitations:

```text
raw probability calibration may be imperfect
large forests can obscure interpretation
may underperform boosting on structured tabular problems
```

### Robetting status

Useful benchmark, not automatic production candidate.

Suggested id:

```text
RB-ML-RF-001
```

---

## 10. Neural Networks

Neural networks can model highly nonlinear relationships.

They become more plausible when Robetting has:

```text
large datasets
event data
player information
tracking data
temporal sequences
```

For basic match-level historical features, their extra capacity may not be necessary.

Fischer & Heuer's comparison is a warning that neural networks do not automatically generate a large advantage over simpler football models.

---

## 11. Deep Learning should solve a specific data problem

Do not use deep learning merely because:

```text
more advanced = better
```

A deep architecture becomes more justified for:

```text
event sequences
tracking coordinates
player interaction graphs
large temporal histories
embeddings
multimodal information
```

rather than a table containing 20 aggregate match features.

---

## 12. Probability calibration

Many ML classifiers optimize predictive discrimination but can output poorly calibrated probabilities.

Robetting requires:

```text
P = 0.70
```

to behave approximately like a 70% event over repeated comparable forecasts.

Therefore every ML model must be evaluated with:

```text
Log Loss
Brier
Calibration
```

not merely:

```text
accuracy
F1
AUC
```

---

## 13. Recalibration

If an ML model ranks matches well but is miscalibrated, possible post-processing methods include:

```text
Platt / logistic calibration
Isotonic regression
temperature scaling
```

Recalibration must be fitted on validation data only.

Never calibrate on the final test set.

### Robetting policy

Keep:

```text
base model
```

and:

```text
calibration layer
```

versioned separately.

---

## 14. Random split is dangerous

Football is temporal.

An invalid workflow would be:

```text
all 2015-2026 matches
↓
random 80/20 split
```

because training can contain matches chronologically later than test matches.

Feature calculations can also leak future team state.

Robetting must use:

```text
rolling-origin
walk-forward
chronological validation
```

for ML just as for statistical models.

---

## 15. Feature leakage is even more dangerous in ML

Tree and neural models can exploit leakage extremely efficiently.

Examples of invalid features:

```text
final season ranking
final season goals
post-match Elo
statistics including current match
closing odds unavailable at prediction time
future rolling averages
```

A model can look spectacular while being useless in production.

Every feature needs an:

```text
as_of
```

definition.

---

## 16. Feature registry

Robetting should eventually maintain a formal feature registry.

Example:

```text
FEATURE:
elo_difference

AVAILABLE_AT:
pre-match

WINDOW:
all prior matches via rating state

SOURCE:
Robetting Elo

VERSION:
ELO-001
```

Another:

```text
FEATURE:
rolling_xg_for_5

AVAILABLE_AT:
pre-match

WINDOW:
last 5 completed matches

SOURCE:
provider X

VERSION:
XGROLL-001
```

This is essential for reproducibility.

---

## 17. Model vs feature comparison

A major methodological rule:

Do not change:

```text
algorithm
+
features
+
time window
+
calibration
```

all at once.

Otherwise we do not know what caused the improvement.

Example controlled sequence:

```text
LR + features A
vs
XGBoost + features A
```

tests algorithm.

Then:

```text
XGBoost + features A
vs
XGBoost + features A+B
```

tests new features.

---

## 18. Hyperparameter tuning

ML introduces many hyperparameters.

For boosting:

```text
depth
learning rate
number of trees
regularization
subsampling
```

For neural networks:

```text
layers
hidden units
dropout
learning rate
batch size
```

These must be optimized only inside training/validation periods.

The final test remains untouched.

---

## 19. Nested temporal validation

A robust structure:

```text
TRAIN
2015-2021

VALIDATION
2021-2023

TEST
2023-2024
```

Then roll forward:

```text
TRAIN
2015-2022

VALIDATION
2022-2024

TEST
2024-2025
```

More advanced implementations can use nested rolling validation.

This prevents hyperparameter overfitting to one season.

---

## 20. Baselines are mandatory

Every ML paper/model comparison for Robetting should include:

```text
naive league baseline
RB-P-001
RB-DC-001
RB-ELO probability model
market benchmark
```

A neural network beating another neural network is not enough.

The important question is:

```text
does ML beat strong football-specific baselines?
```

---

## 21. Market comparison

Fischer/Heuer and broader forecasting literature reinforce that the betting market is a difficult benchmark.

ML should be compared to de-vig market probabilities using the same:

```text
Log Loss
Brier
RPS
Calibration
```

This remains evaluation only unless we deliberately create a market-informed model.

---

## 22. Market odds as an ML feature

Odds can be an extremely predictive input.

But using them changes the scientific question.

Without odds:

```text
Can Robetting predict football from football data?
```

With odds:

```text
Can Robetting improve on / transform market information?
```

These must be separate model families.

Suggested naming:

```text
RB-ML-FOOTBALL-...
```

versus:

```text
RB-ML-MARKET-...
```

Never compare them as though they used the same information.

---

## 23. Player-level features

The literature suggests player ratings can improve team-strength modelling.

Potential features:

```text
expected starting XI strength
goalkeeper rating
attack-unit rating
defence-unit rating
minutes-weighted squad rating
injury-adjusted strength
```

But these require reliable historical lineups and availability data.

Robetting should not introduce player features until historical reproducibility is established.

---

## 24. Event and tracking data

Machine learning becomes particularly interesting when input moves beyond match aggregates.

Possible future inputs:

```text
shot locations
passing networks
pressing events
player tracking
spatial occupation
event sequences
```

At that point:

```text
graph neural networks
sequence models
transformers
deep learning
```

can become justified research candidates.

This is a much later stage.

---

## 25. Interpretability

Interpretability matters because Robetting should understand why a model changes.

Useful tools:

```text
feature importance
permutation importance
SHAP
partial dependence
coefficient analysis
```

Interpretability should be used carefully: feature importance is not necessarily causal importance.

---

## 26. Distribution shift

Football changes over time.

Examples:

```text
rule changes
VAR
five substitutions
empty stadiums
tactical trends
competition formats
```

An ML model trained heavily on older data can face distribution shift.

Robetting should monitor performance by:

```text
season
league
probability bucket
feature distribution
```

not only globally.

---

## 27. Cross-league training

ML may benefit from pooling:

```text
Serie A
Premier League
La Liga
Bundesliga
Ligue 1
```

because the sample becomes much larger.

But league differences can introduce bias.

Possible features:

```text
league identifier
league strength
competition-specific home effect
```

Alternative:

```text
one model per league
```

This must be tested.

---

## 28. Multi-task models

A future model could jointly learn:

```text
1X2
Over/Under
BTTS
goals
```

Shared representations may exploit common football structure.

However, outputs must remain internally coherent.

A model predicting:

```text
P(Over 2.5)=80%
```

while its implied goal expectations suggest a very low-scoring match would need scrutiny.

Goal-distribution approaches avoid some of these inconsistencies naturally.

---

## 29. Direct vs generative models

### Direct model

```text
features
↓
P(Home/Draw/Away)
```

### Generative/score model

```text
features
↓
goal distribution
↓
derive markets
```

Direct ML can optimize one market more precisely.

Generative score models offer cross-market consistency.

Robetting should eventually compare both.

---

## 30. Ensemble models

Different models may capture different information.

Potential ensemble:

```text
Dixon-Coles
Elo
XGBoost
Bayesian model
↓
weighted combination
```

But ensemble weights must be learned from validation data.

Do not use arbitrary:

```text
40% Dixon-Coles
30% Elo
30% ML
```

because it "looks balanced".

---

## 31. Stacking

A more rigorous ensemble approach is stacking.

Base models output:

```text
P_DC
P_ELO
P_ML
```

Then a meta-model learns how to combine them.

For example:

```text
multinomial logistic regression
```

on out-of-fold / out-of-time base predictions.

Critical:

The meta-model must only receive predictions generated without seeing the target match.

Otherwise stacking leaks information.

---

## 32. Proposed first ML experiment

### Experiment ID

```text
EXP-ML-001
```

### Goal

Determine whether a simple direct probabilistic ML model adds value beyond Elo and goal-model strengths.

### Model

```text
RB-ML-LR-001
```

### Features

Initial controlled set:

```text
Elo difference
home indicator/effect
Poisson lambda_home
Poisson lambda_away
```

### Output

```text
P(Home)
P(Draw)
P(Away)
```

### Evaluation

```text
Log Loss
Brier
RPS
Calibration
```

Compare against the source models.

---

## 33. Second ML experiment

### Experiment ID

```text
EXP-ML-GBT-001
```

### Model

```text
gradient boosted trees
```

Use exactly the same feature set as `RB-ML-LR-001`.

Question:

```text
Does nonlinear modelling itself improve forecasts?
```

Only after this should additional features be added.

---

## 34. Feature-addition experiment

### Experiment ID

```text
EXP-ML-FEAT-001
```

Add groups one at a time:

```text
A. basic strength
B. rolling goals
C. shots
D. xG
E. rest / schedule
F. player information
```

Measure incremental out-of-sample benefit.

This gives Robetting a defensible feature-selection process.

---

## 35. Deep-learning gate

Robetting should create a deep-learning experiment only when at least one is true:

```text
large event dataset available
tracking data available
sequence modelling is required
graph/player relations are important
tabular ML has plateaued
```

Until then, deep learning is low priority.

---

## 36. Model promotion criteria

An ML model should not be promoted because it has:

```text
better accuracy
```

alone.

Require evidence such as:

```text
better Log Loss
better or comparable Brier
acceptable calibration
stability across seasons
stability across leagues
no leakage
reasonable complexity
```

And later:

```text
market benchmark comparison
```

---

## 37. Reproducibility requirements

Every ML model version should store:

```text
model_id
feature_set_version
training_start
training_end
validation period
algorithm
hyperparameters
random_seed
calibration_method
library/version
```

Prediction records remain immutable.

---

## 38. Python vs Laravel

Most mature ML tooling is in Python.

A likely future architecture:

```text
Laravel application
↓
model execution boundary
↓
Python research / prediction service
↓
predictions stored in DB
↓
Laravel frontend consumes results
```

This is not yet an adopted implementation decision.

The important principle is:

```text
choose modelling tools for statistical reliability,
not because the portal happens to use PHP.
```

---

## 39. Main research lesson

The current literature does not justify:

```text
Machine Learning > Poisson
```

as a universal rule.

A more defensible interpretation is:

```text
performance depends on:
features
dataset
validation
calibration
rating representation
model family
```

and in some controlled studies the differences between model families are surprisingly modest.

Therefore Robetting should prioritize:

```text
clean data
good temporal features
strong baselines
correct evaluation
```

before algorithmic complexity.

---

## 40. Open questions for Robetting

- Does logistic regression beat Dixon-Coles for 1X2?
- Does boosting beat logistic regression using identical features?
- Which rating features add independent value?
- Do xG features materially improve predictions?
- Does ML mainly improve 1X2 but not score distributions?
- Should we use one model per league or pooled leagues?
- How much historical data should ML use?
- Which features suffer distribution shift?
- How much probability recalibration is needed?
- Is CatBoost preferable for categorical league/team features?
- Do neural networks add value on tabular match data?
- When do player features justify their maintenance cost?
- Can event-data representations improve pre-match forecasts?
- Does an ensemble outperform its strongest component?
- Does improvement survive comparison with market probabilities?

---

## 41. Strengths

- flexible nonlinear modelling;
- can combine heterogeneous features;
- handles interactions automatically in tree/NN models;
- can exploit ratings, xG, player and contextual data;
- useful for direct market-specific prediction;
- good foundation for ensembles.

---

## 42. Limitations

- easy to overfit;
- feature leakage can create spectacular false results;
- probability calibration may be poor;
- hyperparameter search increases researcher degrees of freedom;
- interpretation is harder;
- cross-paper results are difficult to compare;
- deep models need large datasets;
- complex models may only marginally beat simple baselines;
- model maintenance and retraining are more demanding.

---

## 43. Robetting decision

### Current status

```text
MACHINE LEARNING = CONTROLLED FUTURE RESEARCH TRACK
```

### Priority

```text
1. validated statistical baselines
2. Elo / temporal models
3. strong feature registry
4. simple ML baseline
5. gradient boosting
6. feature enrichment
7. ensembles
8. deep learning only when data justifies it
```

### Initial candidates

```text
RB-ML-LR-001
RB-ML-GBT-001
RB-ML-RF-001
```

Deep learning remains:

```text
LATER RESEARCH
```

---

## 44. Evidence vs Robetting design choices

### Supported by Bunker, Yeung & Fujii (2024)

- machine learning for soccer prediction includes multiple model and feature families;
- gradient-boosted tree methods combined with soccer-specific ratings have strong reported results in goal-feature datasets;
- benchmark datasets and direct study comparison remain limitations;
- deep-learning and Random-Forest comparisons need broader study;
- interpretability is an important research issue.

### Supported by Fischer & Heuer (2024)

- Poisson, Random Forest and neural-network approaches can be compared directly in football forecasting;
- across their five-league experiments, model and feature choices produce more modest performance differences than might be expected;
- complex ML does not automatically dominate Poisson approaches.

### Supported by Yeung et al. (2024)

- publicly available benchmark data is important for fair comparison;
- deep learning and gradient-boosted tree feature optimization can be evaluated using soccer probability-prediction benchmarks;
- feature selection is an important component of model performance.

### Robetting design choices

- logistic regression as the first ML baseline;
- controlled same-feature comparison with boosted trees;
- formal feature registry;
- strict chronological validation;
- separate market-informed and football-only models;
- deep-learning gate;
- stacking only from out-of-time predictions;
- standard Robetting proper-score evaluation.

---

## Sources

1. Bunker, R., Yeung, C. & Fujii, K. (2024), *Machine Learning for Soccer Match Result Prediction*, arXiv:2403.07669.
2. Fischer, M. & Heuer, A. (2024), *Match predictions in soccer: Machine learning vs. Poisson approaches*, arXiv:2408.08331.
3. Yeung, C. et al. (2024), *Evaluating Soccer Match Prediction Models: A Deep Learning Approach and Feature Optimization for Gradient-Boosted Trees*, Machine Learning; preprint arXiv:2309.14807.

---

## Knowledge-base tags

```text
football-analytics
machine-learning
logistic-regression
random-forest
gradient-boosting
xgboost
catboost
deep-learning
features
calibration
temporal-validation
ensemble
stacking
robetting
```
