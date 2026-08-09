# Probabilistic Evaluation / Proper Scoring Rules

## Status

**Source status:** verified primary methodological source  
**Robetting status:** foundational evaluation framework  
**Decision:** proper scoring rules must be part of the standard Robetting model-evaluation protocol.

---

## Primary source

Gneiting, T. & Raftery, A. E. (2007).  
*Strictly Proper Scoring Rules, Prediction, and Estimation.*  
Journal of the American Statistical Association, 102, 359–378.

Author PDF:
https://www.stat.washington.edu/raftery/Research/PDF/Gneiting2007jasa.pdf

University of Washington record:
https://stat.uw.edu/research/preprints/tech-report/strictly-proper-scoring-rules-prediction-and-estimation

---

## 1. Why this matters for Robetting

Robetting does not merely output labels such as:

```text
HOME
OVER
BTTS YES
```

It outputs probabilities:

```text
Home  48%
Draw  29%
Away  23%
```

Therefore evaluation must judge the **quality of the probabilities**, not only whether the most likely outcome happened.

A model saying:

```text
Home 51%
```

and a model saying:

```text
Home 95%
```

both receive the same simple "correct" mark if the home team wins.

But probabilistically these forecasts are very different.

Robetting therefore requires scoring rules that evaluate the complete probability forecast.

---

## 2. Scoring rule

A scoring rule assigns a numerical value to a probabilistic forecast after the actual outcome becomes known.

Conceptually:

```text
forecast probabilities
+
realized outcome
↓
numerical score
```

The score can then be averaged over many unseen predictions.

This provides a common framework for comparing:

```text
RB-P-001
RB-DC-001
RB-BP-001
RB-ELO-...
future ML models
market probabilities
```

---

## 3. Proper scoring rule

A scoring rule is **proper** when a forecaster minimizes/maximizes expected loss/score by reporting the probability distribution they genuinely believe to be correct.

A scoring rule is **strictly proper** when that truthful forecast is uniquely optimal.

This property matters because we want evaluation metrics that do not reward strategic distortion.

Robetting should prefer proper scoring rules for probabilistic model comparison.

---

## 4. Why accuracy is insufficient

Suppose the true outcomes are evaluated over many matches.

Model A:

```text
Home 51%
Draw 25%
Away 24%
```

Model B:

```text
Home 90%
Draw  6%
Away  4%
```

If home wins, both have:

```text
prediction = HOME
accuracy = correct
```

Accuracy cannot distinguish their confidence.

If home loses, however, Model B made a much stronger error.

Proper probabilistic scores capture this difference.

---

## 5. Logarithmic score / Log Loss

For a categorical event, the logarithmic score focuses on the probability assigned to the outcome that actually occurred.

Using loss notation:

```text
LogLoss = -log(p_actual)
```

For 1X2, if the actual result is a draw:

```text
Home = 0.45
Draw = 0.30
Away = 0.25
```

then:

```text
LogLoss = -log(0.30)
```

### Interpretation

Lower average Log Loss is better when expressed as loss.

A model is punished strongly when it assigns a very small probability to an event that occurs.

Example:

```text
actual = Away

Model A: P(Away)=0.25
Model B: P(Away)=0.01
```

Model B receives a much larger penalty.

### Robetting relevance

Log Loss is useful because it discourages unjustified overconfidence.

---

## 6. Quadratic score / Brier family

Gneiting & Raftery discuss the quadratic score as a strictly proper score for categorical forecasts.

In loss notation, the multiclass Brier score can be represented as:

```text
Brier
=
sum over outcomes
(predicted_probability - observed_indicator)^2
```

For 1X2:

```text
prediction:
H = 0.50
D = 0.30
A = 0.20

actual:
H = 0
D = 1
A = 0
```

then:

```text
Brier
=
(0.50 - 0)^2
+
(0.30 - 1)^2
+
(0.20 - 0)^2
```

Normalization conventions can vary.

### Robetting requirement

The exact convention used must be documented and kept stable across experiments.

Never compare Brier values computed with different normalizations.

---

## 7. Binary markets

For markets such as:

```text
Over 2.5 / Under 2.5
BTTS Yes / No
```

the binary Brier loss is:

```text
(p - y)^2
```

where:

```text
p = predicted probability
y = 1 if event occurred, otherwise 0
```

This makes Brier especially straightforward for market-by-market evaluation.

---

## 8. Log Loss vs Brier

Neither metric should automatically replace the other.

### Log Loss

Characteristics:

- strongly penalizes assigning tiny probability to realized events;
- highly sensitive to overconfident catastrophic errors;
- closely related to likelihood-based modelling.

### Brier

Characteristics:

- quadratic penalty;
- bounded for finite categorical outcomes under common conventions;
- intuitive for calibration analysis;
- less explosive than Log Loss near probability zero.

### Robetting decision

Report both.

A model improvement should ideally be visible across multiple proper scores rather than depend on one metric alone.

---

## 9. Calibration

A forecast system is calibrated when events predicted with a certain probability occur approximately at that frequency over repeated forecasts.

Example:

Among predictions where Robetting says:

```text
Home Win ≈ 60%
```

we would like roughly:

```text
60% actual home wins
```

over a sufficiently large, comparable sample.

Calibration is not merely about average accuracy.

It asks whether stated probabilities correspond to observed frequencies.

---

## 10. Calibration bins

A practical Robetting diagnostic can group forecasts into bins:

```text
0-10%
10-20%
20-30%
30-40%
40-50%
50-60%
60-70%
70-80%
80-90%
90-100%
```

For each bin compare:

```text
mean predicted probability
vs
observed event frequency
```

Example:

```text
Predicted avg   Observed
0.15            0.17
0.25            0.24
0.35            0.31
0.45            0.46
0.55            0.54
0.65            0.67
```

This becomes a calibration/reliability curve.

---

## 11. Important warning about calibration bins

Binning is a diagnostic convenience, not the underlying definition of calibration.

Results depend on:

```text
bin width
sample size
distribution of predictions
```

Therefore Robetting should retain raw prediction-level probabilities so calibration can be recomputed using improved methods later.

---

## 12. Sharpness

Gneiting and related probabilistic-forecast literature distinguish **calibration** from **sharpness**.

A forecast can be trivially well calibrated by always issuing broad, uninformative probabilities close to base rates.

Useful forecasts should also be informative.

Conceptually:

```text
Calibration:
Are 70% forecasts correct about 70% of the time?

Sharpness:
Does the model confidently differentiate easy and difficult cases
without becoming miscalibrated?
```

Robetting wants probabilities that are both reliable and informative.

---

## 13. Why "percentage of predictions won" can mislead

Consider:

```text
Model A
always predicts favorite at 51%

Model B
produces calibrated probabilities from 20% to 80%
```

Both might have similar top-choice accuracy.

But Model B can contain far more useful probabilistic information.

Therefore dashboards such as:

```text
Robetting accuracy = 63%
```

must never be the primary scientific evaluation.

Accuracy may be displayed as a secondary descriptive metric only.

---

## 14. Market benchmark

Bookmaker probabilities can be evaluated with the **same scoring rules** as Robetting forecasts.

After removing bookmaker margin:

```text
market probability vector
=
P_market(H), P_market(D), P_market(A)
```

we can compute:

```text
Market Log Loss
Market Brier
Market calibration
```

and compare directly with:

```text
Robetting Log Loss
Robetting Brier
Robetting calibration
```

This is much more informative than comparing only ROI.

---

## 15. Statistical quality vs economic quality

These are separate questions.

### Statistical

```text
Are the probabilities accurate and calibrated?
```

Metrics:

```text
Log Loss
Brier
Calibration
RPS (football-specific ordered diagnostic)
```

### Economic

```text
Do discrepancies versus market prices produce profitable opportunities?
```

Metrics:

```text
ROI
Yield
Expected Value
Closing-line comparison
drawdown
```

A model can be probabilistically strong but offer little exploitable market edge.

A profitable historical strategy can also result from noise.

Robetting must keep these evaluation layers separate.

---

## 16. Ranked Probability Score

RPS is commonly used for ordered categorical outcomes such as football 1X2 because:

```text
Away < Draw < Home
```

or the reverse coding can be treated as an ordered sequence.

RPS evaluates cumulative probability differences across ordered categories.

However, `RPS` is not the central subject of Gneiting & Raftery (2007).

Therefore:

```text
Log Loss
Brier
```

are directly grounded in the core proper-scoring framework here, while the football-specific choice to include RPS requires separate source documentation.

### Robetting decision

Keep RPS in the scorecard, but create a dedicated knowledge note before treating it as a formally established project standard.

---

## 17. Evaluation unit

Every stored Robetting prediction should remain immutable after kickoff.

For each record:

```text
match_id
model_version
generated_at
data_cutoff_at
probabilities
actual_outcome
```

After the match:

```text
score prediction
```

Do not recompute historical probabilities using newer model versions.

Otherwise evaluation becomes retrospective rather than genuine forecasting.

---

## 18. Aggregation levels

Robetting should evaluate scores at multiple levels.

### Global

```text
all matches
```

### Competition

```text
Serie A
Premier League
La Liga
...
```

### Season

```text
2024/25
2025/26
...
```

### Market

```text
1X2
Over 2.5
BTTS
```

### Probability range

```text
50-60%
60-70%
70-80%
...
```

### Model version

```text
RB-P-001
RB-DC-001
...
```

This helps identify whether an apparent global improvement is concentrated in one league or probability region.

---

## 19. Sample-size discipline

Calibration diagnostics can be misleading on small samples.

For example:

```text
Robetting predicts 80%
3 times
2 events occur
```

does not prove the model is badly calibrated.

Therefore every calibration report should include:

```text
number of predictions
```

and preferably uncertainty estimates in later versions.

Robetting must resist drawing conclusions from tiny subsets.

---

## 20. Proposed Robetting evaluation scorecard

For every model version:

```text
MODEL
RB-DC-001

SAMPLE
N matches

1X2
- Log Loss
- Brier Score
- RPS
- Calibration

Over 2.5
- Log Loss
- Brier Score
- Calibration

BTTS
- Log Loss
- Brier Score
- Calibration
```

Benchmark rows:

```text
Naive baseline
RB-P-001
Current candidate
Market de-vig
```

This should become the standard model-comparison table.

---

## 21. Proposed experiment

### Experiment ID

`EXP-EVAL-001`

### Objective

Build a deterministic Robetting scoring pipeline that evaluates stored historical forecasts identically across all model families.

### Input

Prediction records:

```text
match_id
model_version
market
selection probabilities
generated_at
data_cutoff_at
```

Final match result.

### Output

Per prediction:

```text
log_loss
brier
rps where applicable
```

Aggregated:

```text
mean score
sample size
calibration bins
```

### Validation

Create manually calculable test cases and verify metric implementations against independent reference calculations.

---

## 22. Model promotion rule

A model should **not** be promoted because:

```text
accuracy increased
```

or:

```text
ROI was positive in one season
```

A candidate should instead show evidence such as:

```text
out-of-sample Log Loss improvement
+
out-of-sample Brier improvement
+
acceptable/better calibration
+
stability across seasons
```

Economic evaluation comes after probabilistic validation.

The exact thresholds for promotion remain an open Robetting decision.

---

## 23. Open questions for Robetting

- Which Brier normalization should be canonical?
- Should Log Loss probabilities be numerically clipped to prevent `log(0)`?
- If clipping is used, at what epsilon?
- Which RPS formulation should be canonical?
- How should calibration uncertainty be displayed?
- Minimum sample size per calibration bin?
- Fixed-width bins or adaptive bins?
- Should isotonic/logistic recalibration be allowed?
- If a model improves discrimination but worsens calibration, should it be recalibrated rather than rejected?
- Should calibration be global or league-specific?
- Should market probabilities themselves be recalibrated?
- What constitutes a practically meaningful Log Loss improvement?
- How many seasons are required before model promotion?
- Should model selection optimize one primary metric or a composite scorecard?

---

## 24. Strengths of proper scoring rules

- evaluate full probability distributions;
- reward honest probabilistic assessment;
- distinguish mild uncertainty from extreme confidence;
- allow direct model-to-model comparison;
- allow direct model-to-market comparison;
- discourage evaluation by hit rate alone;
- provide a principled basis for model selection.

---

## 25. Limitations and cautions

- no single score describes every aspect of forecast quality;
- different proper scores emphasize different errors;
- average scores can hide subgroup failures;
- calibration needs adequate sample size;
- a better proper score does not automatically imply profitable betting;
- economic utility depends on market prices and decisions, not probabilities alone;
- model comparisons must use identical out-of-sample matches.

---

## 26. Robetting decision

### Adopted principle

```text
ROBETTING MODELS ARE PROBABILISTIC FORECASTERS
```

Therefore:

```text
PRIMARY EVALUATION
=
proper probabilistic scoring
+
calibration
```

and not:

```text
PRIMARY EVALUATION
=
hit rate
```

Initial canonical metrics:

```text
Log Loss
Brier Score
Calibration
```

`RPS` remains planned but requires its own dedicated research source/note.

---

## 27. Evidence vs Robetting design choices

### Directly supported by Gneiting & Raftery (2007)

- scoring rules assess probabilistic forecasts numerically;
- proper scoring rules encourage truthful probability assessments;
- strictly proper scoring rules uniquely reward the true distribution in expectation;
- logarithmic scoring rules;
- quadratic scoring rules;
- scoring-rule use for ranking competing forecast procedures;
- general relationship between probabilistic prediction and proper scoring.

### Robetting design choices

- Log Loss and Brier as standard model metrics;
- calibration dashboards;
- bookmaker market as a scored benchmark;
- immutable pre-match prediction records;
- multi-level aggregation by league/season/market;
- model-promotion criteria;
- separation of statistical and economic evaluation;
- eventual inclusion of RPS.

These are project methodology decisions built on the scoring-rule framework.

---

## Sources

1. Gneiting, T. & Raftery, A. E. (2007), *Strictly Proper Scoring Rules, Prediction, and Estimation*, Journal of the American Statistical Association, 102, 359–378.
2. Author-hosted PDF: https://www.stat.washington.edu/raftery/Research/PDF/Gneiting2007jasa.pdf
3. University of Washington research record: https://stat.uw.edu/research/preprints/tech-report/strictly-proper-scoring-rules-prediction-and-estimation

---

## Knowledge-base tags

```text
probability
forecast-evaluation
proper-scoring-rules
log-loss
brier-score
calibration
sharpness
model-selection
backtesting
market-benchmark
robetting
```
