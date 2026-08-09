# Ranked Probability Score (RPS) for Football Forecasts

## Status

**Source status:** supported by football-specific literature, but method choice is debated  
**Robetting status:** secondary evaluation metric  
**Decision:** include RPS in Robetting's evaluation dashboard, but do not use it as the sole or primary model-selection metric.

---

## Key football-specific sources

### Constantinou & Fenton (2012)

Constantinou, A. C. & Fenton, N. E.  
*Solving the Problem of Inadequate Scoring Rules for Assessing Probabilistic Football Forecast Models.*  
Journal of Quantitative Analysis in Sports, 8(1).

QMUL accepted manuscript:
https://qmro.qmul.ac.uk/xmlui/bitstream/handle/123456789/10783/Constantinou%20Solving%20the%20Problem%20of%20Inadequate%202012%20Accepted.pdf

Repository record:
https://qmro.qmul.ac.uk/xmlui/handle/123456789/10783

### Wheatcroft (2021; preprint 2019)

Wheatcroft, E.  
*Evaluating probabilistic forecasts of football matches: The case against the Ranked Probability Score.*  
Journal of Quantitative Analysis in Sports, 17(4), 273–287.

Preprint:
https://arxiv.org/abs/1908.08980

---

## 1. Why RPS was proposed for football

Football 1X2 forecasts have three outcomes:

```text
Away Win
Draw
Home Win
```

These outcomes can be treated as ordered.

A draw is conceptually between an away win and a home win.

Constantinou & Fenton argue that evaluation metrics for football forecasts should take this ordinal structure into account and recommend the Ranked Probability Score.

The key intuition is:

```text
Home Win
is "closer" to Draw
than to Away Win
```

RPS incorporates this ordering through cumulative probabilities.

---

## 2. RPS definition

Suppose the ordered categories are:

```text
1 = Away Win
2 = Draw
3 = Home Win
```

and predicted probabilities are:

```text
p1, p2, p3
```

with:

```text
p1 + p2 + p3 = 1
```

Define cumulative predicted probabilities:

```text
F1 = p1
F2 = p1 + p2
```

Define cumulative observed values according to the realized category.

Then:

```text
RPS
=
sum over k=1..K-1
(F_k - O_k)^2
```

For three football outcomes:

```text
RPS
=
(F1 - O1)^2
+
(F2 - O2)^2
```

Some implementations divide by `K-1`.

### Robetting requirement

The normalization convention must be explicit and fixed.

Recommended Robetting convention:

```text
RPS_normalized
=
1 / (K-1)
*
sum cumulative squared errors
```

For 1X2:

```text
divide by 2
```

This keeps values easier to compare across contexts.

---

## 3. Example

Prediction:

```text
Away = 0.20
Draw = 0.30
Home = 0.50
```

Suppose actual outcome:

```text
Home Win
```

Cumulative forecast:

```text
F1 = 0.20
F2 = 0.50
```

Cumulative observed vector for Home:

```text
O1 = 0
O2 = 0
```

Unnormalized RPS:

```text
(0.20 - 0)^2
+
(0.50 - 0)^2

=
0.04 + 0.25
=
0.29
```

Normalized:

```text
0.29 / 2
=
0.145
```

Lower is better when expressed as loss.

---

## 4. Why RPS differs from multiclass Brier

The multiclass Brier score evaluates squared error separately for each category.

RPS evaluates squared error on **cumulative probabilities**.

Therefore it is sensitive to the ordering of the categories.

Example:

If Home Win occurs, these two wrong forecasts are treated differently:

```text
Forecast A:
Away 0.00
Draw 0.80
Home 0.20
```

and:

```text
Forecast B:
Away 0.80
Draw 0.00
Home 0.20
```

Both assign the same probability:

```text
P(Home) = 0.20
```

but RPS penalizes the large probability placed on Away more strongly because Away is ordinally further from Home than Draw.

This "distance sensitivity" is the main reason RPS became popular in football forecast evaluation.

---

## 5. Constantinou & Fenton's argument

Constantinou & Fenton examine scoring rules previously used to assess football forecasting models and argue that some can lead to counterintuitive rankings.

They propose RPS because it is designed for probability forecasts over ordered categories and because it satisfies the football examples they consider important.

The paper helped popularize RPS as a football-specific evaluation metric.

### Robetting interpretation

This is a legitimate reason to include RPS in the evaluation suite.

It is **not** sufficient reason to make RPS the only model-selection criterion.

---

## 6. Wheatcroft's criticism

Wheatcroft directly challenges the idea that "sensitivity to distance" is necessarily desirable for evaluating football forecasts.

His argument is methodological:

The purpose of a proper scoring rule is to reward good probability distributions.

The fact that:

```text
Draw is closer to Home than Away
```

does not automatically imply that forecasts should be rewarded based on this distance.

He compares:

```text
RPS
Brier score
Ignorance score / logarithmic score
```

using simulation experiments in a football setting.

The paper finds that the ignorance score can outperform both RPS and Brier in the experiments considered.

### Important consequence

There is genuine methodological disagreement in the literature.

Therefore Robetting should **not** state:

```text
RPS is the objectively best football scoring rule
```

That claim is not supported.

---

## 7. Locality

Wheatcroft also discusses **local scoring rules**.

A scoring rule is local when the score depends only on the probability assigned to the event that actually occurs.

Logarithmic / ignorance score is local.

RPS is non-local because probabilities assigned to outcomes that did not occur also affect the score through cumulative probabilities.

Brier is also non-local.

This gives us another reason to report more than one scoring rule.

---

## 8. RPS is still a proper scoring rule

The criticism does not imply that RPS is invalid.

RPS remains a proper probabilistic scoring rule for ordered categorical outcomes.

The disagreement concerns whether its ordering-sensitive properties are **useful or desirable** in football-model evaluation.

This distinction must be retained in the knowledge base.

---

## 9. Robetting policy

Use RPS as:

```text
secondary football-specific diagnostic
```

alongside:

```text
Log Loss
Brier Score
Calibration
```

Do not optimize model selection exclusively for RPS.

Recommended standard table:

```text
MODEL            LOG LOSS   BRIER   RPS   CALIBRATION
RB-P-001
RB-DC-001
RB-BP-001
RB-ELO-...
MARKET
```

This allows us to see whether conclusions are robust across different proper scores.

---

## 10. Why disagreement between metrics is useful

Suppose:

```text
Model A
wins on RPS

Model B
wins on Log Loss
```

This should not be hidden.

It tells us the models make different kinds of probabilistic errors.

We should investigate:

```text
overconfidence
draw probabilities
extreme misses
probability calibration
favorite/underdog regions
```

rather than choosing whichever metric supports the preferred model.

---

## 11. Applicability

RPS is natural for:

```text
1X2
```

because the categories are ordinal.

It is unnecessary for binary markets such as:

```text
Over / Under 2.5
BTTS Yes / No
```

For binary outcomes, use:

```text
Log Loss
Brier Score
Calibration
```

RPS adds no meaningful advantage there.

---

## 12. Ordering convention

For Robetting, choose one canonical ordering:

```text
Away
Draw
Home
```

or:

```text
Home
Draw
Away
```

Both are mathematically valid if used consistently.

Recommended convention:

```text
Away
Draw
Home
```

because it forms a natural increasing result scale from negative to positive from the home-team perspective.

However, software output may still display:

```text
Home
Draw
Away
```

for user readability.

The evaluation layer should internally document its order explicitly.

---

## 13. Numerical tests

The RPS implementation should have deterministic unit tests.

Example cases:

### Perfect forecast

```text
Forecast:
Away 0
Draw 0
Home 1

Actual:
Home
```

Expected:

```text
RPS = 0
```

### Certain opposite outcome

```text
Forecast:
Away 1
Draw 0
Home 0

Actual:
Home
```

This should produce the maximum loss under the chosen normalization.

### Draw-only forecast

```text
Forecast:
Away 0
Draw 1
Home 0

Actual:
Home
```

Its loss should be smaller than predicting Away with certainty when Home occurs, reflecting the ordinal nature of RPS.

These tests should be documented in code.

---

## 14. Proposed Robetting experiment

### Experiment ID

`EXP-EVAL-RPS-001`

### Objective

Verify whether conclusions about model ranking are stable across:

```text
Log Loss
Brier
RPS
```

### Models

```text
RB-P-001
RB-DC-001
RB-BP-001
RB-ELO-OL-001
market benchmark
```

### Dataset

Same chronological 1X2 prediction sample for every model.

### Outputs

For each model:

```text
mean Log Loss
mean Brier
mean RPS
calibration diagnostics
```

Then compute rank ordering under each metric.

### Key question

```text
Do the scoring rules agree on which models are better?
```

If not, inspect the probability distributions causing disagreement.

---

## 15. Open questions for Robetting

- Should normalized or raw RPS be canonical?
- Should RPS remain visible in public model-performance pages?
- Is ordinal distance meaningful enough for our 1X2 evaluation goals?
- Does RPS systematically favor models with conservative draw probabilities?
- Does model ranking materially change between Log Loss and RPS?
- How often do RPS and Brier disagree?
- Should Ignorance/Log Loss be our primary score because of locality?
- Should model-promotion decisions require improvement on two or more proper scores?
- Would statistical significance tests or bootstrap intervals help compare close models?
- Should market forecasts be evaluated under all three scores identically?

---

## 16. Strengths

- proper probabilistic score;
- designed for ordered categories;
- intuitive football interpretation;
- sensitive to the ordinal separation between outcomes;
- widely discussed in football-forecast literature;
- easy to compute;
- useful as a complementary diagnostic.

---

## 17. Limitations

- ordering sensitivity is methodologically debated;
- non-local;
- should not be assumed superior to Log Loss or Brier;
- normalization conventions vary;
- unnecessary for binary markets;
- average RPS alone can hide calibration failures;
- model rankings can differ from those produced by other proper scores.

---

## 18. Robetting decision

### Previous status

```text
RPS = planned
```

### Updated status

```text
RPS = ADOPTED AS SECONDARY 1X2 EVALUATION METRIC
```

Primary Robetting evaluation remains:

```text
Log Loss
Brier Score
Calibration
```

For 1X2 add:

```text
RPS
```

Therefore:

```text
PRIMARY:
Log Loss
Brier
Calibration

SUPPLEMENTARY 1X2:
RPS
```

This decision reflects both sides of the football-specific literature rather than assuming one paper settles the issue.

---

## 19. Evidence vs Robetting design choices

### Supported by Constantinou & Fenton (2012)

- football outcomes are ordinal categories;
- RPS is an established scoring rule for ranked categorical outcomes;
- RPS can address weaknesses in some scoring rules previously used for football forecasting;
- RPS accounts for outcome ordering.

### Supported by Wheatcroft (2021 / 2019 preprint)

- the supposed advantage of distance sensitivity in football is debatable;
- RPS is non-local;
- Brier is non-local and insensitive to distance;
- ignorance/log score is local;
- simulation results in the paper favor ignorance score over RPS and Brier in the examined setting;
- RPS should not automatically be assumed to be the best scoring rule for football forecasts.

### Robetting design choices

- keep RPS as a secondary metric;
- use Log Loss, Brier and Calibration as primary evaluation;
- apply RPS only to 1X2;
- use a normalized implementation;
- canonical internal outcome ordering;
- compare model ranks across multiple scoring rules.

---

## Sources

1. Constantinou, A. C. & Fenton, N. E. (2012), *Solving the Problem of Inadequate Scoring Rules for Assessing Probabilistic Football Forecast Models*, Journal of Quantitative Analysis in Sports, 8(1).
2. QMUL accepted manuscript: https://qmro.qmul.ac.uk/xmlui/bitstream/handle/123456789/10783/Constantinou%20Solving%20the%20Problem%20of%20Inadequate%202012%20Accepted.pdf
3. Wheatcroft, E. (2021), *Evaluating probabilistic forecasts of football matches: The case against the Ranked Probability Score*, Journal of Quantitative Analysis in Sports, 17(4), 273–287.
4. Wheatcroft preprint: https://arxiv.org/abs/1908.08980

---

## Knowledge-base tags

```text
probability
forecast-evaluation
rps
ranked-probability-score
football
proper-scoring-rule
log-loss
brier
calibration
model-selection
robetting
```
