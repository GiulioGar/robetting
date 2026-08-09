# Bivariate Poisson / Karlis–Ntzoufras Model

## Status

**Source status:** verified primary source  
**Robetting status:** candidate comparison model  
**Suggested model id:** `RB-BP-001`  
**Decision:** not adopted; should be implemented only after the Independent Poisson and Dixon–Coles baselines are validated.

---

## Primary source

Karlis, D. & Ntzoufras, I. (2003).  
*Analysis of Sports Data by Using Bivariate Poisson Models.*  
The Statistician / Journal of the Royal Statistical Society: Series D, 52(3), 381–393.  
DOI: `10.1111/1467-9884.00366`

Author-hosted paper:
https://www2.stat-athens.aueb.gr/~jbn/papers2/08_Karlis_Ntzoufras_2003_RSSD.pdf

JSTOR record:
https://www.jstor.org/stable/4128211

Author publication page:
https://www2.stat-athens.aueb.gr/~jbn/papers/paper8.htm

---

## 1. Problem addressed

Independent Poisson football models assume that, conditional on the estimated team-strength parameters, the two teams' goal counts are independent.

Karlis and Ntzoufras replace that assumption with a **Bivariate Poisson** model that allows the two scores to be positively correlated.

The paper explicitly motivates this because correlation between the scores of two teams competing in the same game is plausible in sport.

The authors also discuss an important practical consequence: even a small correlation term can materially change some predicted probabilities, particularly the probability of a draw.

---

## 2. Construction of the Bivariate Poisson distribution

Let:

```text
X1 ~ Poisson(lambda1)
X2 ~ Poisson(lambda2)
X3 ~ Poisson(lambda3)
```

with `X1`, `X2`, and `X3` independent.

Define:

```text
X = X1 + X3
Y = X2 + X3
```

Then `(X,Y)` follows a Bivariate Poisson distribution:

```text
(X,Y) ~ BP(lambda1, lambda2, lambda3)
```

The key point is that both scores share the latent component `X3`.

This shared component creates dependence.

---

## 3. Marginal distributions

The two marginal score distributions remain Poisson:

```text
X ~ Poisson(lambda1 + lambda3)
Y ~ Poisson(lambda2 + lambda3)
```

Therefore:

```text
E[X] = lambda1 + lambda3
E[Y] = lambda2 + lambda3
```

and:

```text
Cov(X,Y) = lambda3
```

Thus:

```text
lambda3 >= 0
```

acts as the dependence parameter.

If:

```text
lambda3 = 0
```

the model reduces to the product of two independent Poisson distributions.

---

## 4. Interpretation for football

A natural interpretation proposed in the paper is:

```text
lambda1 = net scoring component of Team 1
lambda2 = net scoring component of Team 2
lambda3 = shared match component
```

The shared component can represent match-level conditions affecting both teams, such as the general speed or openness of the game, weather, or stadium/game conditions.

For Robetting, we should treat that interpretation cautiously: `lambda3` is fundamentally a statistical dependence parameter. Any football interpretation must be validated rather than assumed.

---

## 5. Joint probability function

For scores `x` and `y`, the Bivariate Poisson probability is:

```text
P(X=x,Y=y)
=
exp(-(lambda1 + lambda2 + lambda3))
*
(lambda1^x / x!)
*
(lambda2^y / y!)
*
sum over k=0..min(x,y) [
    C(x,k) C(y,k) k!
    (lambda3/(lambda1*lambda2))^k
]
```

Equivalent algebraic forms exist.

The main implementation implication is that each cell of the score matrix now depends on all three parameters, not simply on the product of two marginal Poisson probabilities.

---

## 6. Difference between Independent and Bivariate Poisson

Independent Poisson:

```text
P(X=x,Y=y)
=
P(X=x) * P(Y=y)
```

Bivariate Poisson:

```text
P(X=x,Y=y)
!=
P(X=x) * P(Y=y)
```

when:

```text
lambda3 > 0
```

Even when marginal means are similar, the joint score distribution can change.

This is precisely why using independent Poisson probabilities with marginal means derived from a correlated model can be wrong.

---

## 7. Effect on draws

The paper places particular emphasis on draw probabilities.

Draws correspond to diagonal cells:

```text
0-0
1-1
2-2
3-3
...
```

Because the Bivariate Poisson introduces a shared score component, it can alter the mass placed on the diagonal.

Karlis and Ntzoufras discuss the inadequacy of simpler Poisson models in modelling the observed number of draws and propose extensions specifically aimed at improving this behaviour.

### Robetting implication

When comparing models, we should not only compare aggregate 1X2 accuracy.

We should inspect:

```text
predicted draw probability
observed draw frequency
draw calibration
score-diagonal residuals
```

---

## 8. Important property of the goal difference

The paper notes an interesting property:

The distribution of:

```text
Z = X - Y
```

under the standard Bivariate Poisson construction is the same as the difference of two independent Poisson variables associated with the non-shared components.

The shared component `X3` cancels:

```text
(X1 + X3) - (X2 + X3)
=
X1 - X2
```

This has an important consequence.

A dependence model may materially change the full joint score distribution while affecting some match-outcome quantities differently from markets based on totals or exact scores.

Therefore the added value of Bivariate Poisson must be measured separately by market.

---

## 9. Team-strength regression structure

For football applications, the Poisson parameters can be linked to team characteristics using regression structures.

Conceptually:

```text
log(lambda_home_component)
=
home attack
-
away defence
+
home effect

log(lambda_away_component)
=
away attack
-
home defence
```

The paper develops Bivariate Poisson regression models so that expected scores depend on explanatory variables.

For Robetting, the first comparison should preserve a structure as close as possible to the existing Poisson/Dixon–Coles team-strength parameterization.

This is necessary for a fair model comparison.

---

## 10. Estimation

The paper discusses maximum-likelihood estimation and uses the **EM algorithm** to estimate Bivariate Poisson model parameters.

The latent common Poisson component makes estimation more computationally involved than the Independent Poisson case.

### Robetting implication

Computational complexity is a legitimate criterion.

A model that produces a tiny predictive improvement but greatly increases:

```text
fit time
implementation complexity
debugging difficulty
numerical instability
```

may not be worthwhile.

We must measure both statistical benefit and operational cost.

---

## 11. Diagonal-inflated extensions

Karlis and Ntzoufras also propose **inflated Bivariate Poisson models**.

The key motivation is that draws correspond to diagonal cells of the joint score distribution.

An additional inflation component can assign extra probability mass to selected diagonal outcomes.

Conceptually:

```text
standard BP distribution
+
extra probability mass on draw cells
```

The paper studies several variants.

### Robetting decision

Do **not** include diagonal inflation in `RB-BP-001`.

The first model should be the clean standard Bivariate Poisson.

If standard BP is promising but draw calibration remains problematic, diagonal-inflated variants can become separate experiments.

Suggested future model id:

```text
RB-DIBP-001
```

---

## 12. Relationship with Maher

Maher (1982) had already discussed the possibility of a Bivariate Poisson model and reported improved fit with a modest positive correlation.

Karlis and Ntzoufras revisit this idea and provide a more developed modelling and estimation framework.

The conceptual progression is therefore:

```text
Maher
│
├── Independent Poisson works reasonably well
└── positive score correlation may improve fit
        ↓
Karlis & Ntzoufras
│
├── explicit Bivariate Poisson framework
├── regression models
├── EM estimation
└── diagonal-inflated extensions
```

---

## 13. Relationship with Dixon–Coles

Dixon–Coles and Bivariate Poisson address dependence differently.

### Dixon–Coles

Starts with independent Poisson and modifies specifically:

```text
0-0
0-1
1-0
1-1
```

through a correction factor `tau`.

### Bivariate Poisson

Introduces a shared latent Poisson component that affects the entire joint score distribution.

Conceptually:

```text
Dixon-Coles
=
local low-score correction

Bivariate Poisson
=
global dependence structure
```

Neither should be assumed superior before backtesting.

---

## 14. Minimum data required

A basic team-strength Bivariate Poisson model can use the same historical core as Poisson/Dixon–Coles:

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

No xG, shots, possession or odds are inherently required.

This is useful because all three baseline families can be evaluated on the exact same match dataset.

---

## 15. Clean comparison design

Robetting should compare:

```text
RB-P-001   Independent Poisson
RB-DC-001  Dixon-Coles
RB-BP-001  Bivariate Poisson
```

using:

```text
same matches
same chronological cutoff
same competition
same evaluation period
same market definitions
same score truncation
```

Otherwise the comparison is not meaningful.

---

## 16. Prediction outputs

Suggested outputs for `RB-BP-001`:

```text
match_id
model_version
generated_at
data_cutoff_at

lambda1
lambda2
lambda3

expected_home_goals
expected_away_goals

home_probability
draw_probability
away_probability

over_2_5_probability
under_2_5_probability

btts_yes_probability
btts_no_probability
```

Potentially:

```text
score_probability_matrix
```

The names of stored parameters should avoid confusion between latent components and marginal expected goals.

For example:

```text
expected_home_goals = lambda1 + lambda3
expected_away_goals = lambda2 + lambda3
```

---

## 17. Diagnostics that matter

In addition to standard proper scoring rules, inspect:

```text
estimated lambda3
lambda3 stability over time
draw calibration
0-0 residual
1-1 residual
2-2 residual
BTTS calibration
total-goals calibration
score correlation
```

A model should not be retained merely because `lambda3` is statistically non-zero.

The relevant question is whether it improves **out-of-sample probabilistic forecasts**.

---

## 18. Proposed experiment

### Experiment ID

`EXP-BP-001`

### Objective

Determine whether explicitly modelling positive home/away score dependence improves Robetting forecasts over Independent Poisson and Dixon–Coles.

### Initial competition

Serie A.

### Models

```text
RB-P-001
RB-DC-001
RB-BP-001
```

### Evaluation protocol

Strict walk-forward / rolling-origin evaluation.

For each forecast date:

```text
fit using only data available before kickoff
↓
predict unseen match(es)
↓
store forecast
↓
advance cutoff
```

### Primary metrics

```text
1X2 Log Loss
1X2 Brier Score
RPS
Calibration
```

### Secondary markets

```text
Over 2.5
BTTS
Correct Score
```

### Model-specific diagnostics

```text
draw calibration
joint score residuals
estimated lambda3
fit/runtime cost
```

---

## 19. Expected hypotheses

These are **Robetting hypotheses**, not conclusions from the paper.

### H1

```text
RB-BP-001 improves draw calibration
relative to RB-P-001.
```

### H2

```text
RB-BP-001 improves full-score distribution fit
more clearly than it improves 1X2 probabilities.
```

### H3

```text
A non-zero dependence parameter may be more valuable
for Correct Score / BTTS / totals than for match winner.
```

### H4

```text
RB-DC-001 may remain competitive despite being simpler
because its correction targets football-specific low scores.
```

All four must be tested.

---

## 20. Open questions for Robetting

- Is estimated score correlation materially positive in modern Serie A?
- Is `lambda3` stable across seasons?
- Should `lambda3` be global, league-specific or match-dependent?
- Does Bivariate Poisson improve draw calibration?
- Does it outperform Dixon–Coles for 1X2?
- Does it outperform Dixon–Coles for BTTS?
- Does it outperform Dixon–Coles for totals?
- Is its strongest benefit only in Correct Score?
- Does diagonal inflation add real out-of-sample value?
- Does EM estimation remain practical as the historical dataset grows?
- How sensitive is the model to promoted/new clubs?
- Should dependence vary by expected scoring level?
- Is positive-only covariance sufficient, or do modern data require more flexible dependency models?
- Does model performance remain stable across leagues?

---

## 21. Strengths

- explicitly models score dependence;
- retains Poisson marginals;
- interpretable latent shared component;
- full joint score distribution;
- natural extension of Independent Poisson;
- useful theoretical comparison with Dixon–Coles;
- no need for event-level data;
- can improve modelling of draws and joint score frequencies.

---

## 22. Limitations

- standard construction permits non-negative covariance only;
- estimation is more complex than Independent Poisson;
- the shared-component interpretation may be too restrictive;
- improved in-sample fit does not guarantee better forecasts;
- diagonal inflation adds further complexity;
- dependence may vary over time or by match context;
- parameter interpretation requires care;
- the model may improve exact-score fit without materially improving commercially relevant markets.

---

## 23. Robetting decision

### Current status

```text
RB-BP-001 = RESEARCH CANDIDATE
```

Priority should remain:

```text
1. RB-P-001
2. RB-DC-001
3. RB-BP-001
```

Reason:

Bivariate Poisson is valuable scientifically, but we first need two simpler, reproducible baselines.

The key decision criterion will be:

> Does explicit global score dependence produce a stable, out-of-sample improvement large enough to justify the extra complexity?

---

## 24. Evidence vs Robetting design choices

### Directly supported by Karlis & Ntzoufras (2003)

- use of Bivariate Poisson models for sports scores;
- replacement of score independence with correlated score modelling;
- construction using three independent Poisson components;
- Poisson marginal distributions;
- covariance equal to the shared parameter `lambda3`;
- reduction to Independent Poisson when `lambda3 = 0`;
- relevance of even small correlation;
- implications for draw probabilities;
- maximum-likelihood estimation through an EM algorithm;
- diagonal-inflated extensions;
- football application.

### Robetting design choices

- model id `RB-BP-001`;
- implementing it after Poisson and Dixon–Coles;
- using Serie A first;
- excluding diagonal inflation from the first BP model;
- walk-forward evaluation;
- using Log Loss, Brier, RPS and calibration;
- direct three-model comparison;
- explicit runtime/complexity evaluation;
- storing latent and marginal goal parameters separately.

These are Robetting methodology decisions, not claims made by the paper.

---

## Sources

1. Karlis, D. & Ntzoufras, I. (2003), *Analysis of Sports Data by Using Bivariate Poisson Models*, The Statistician / JRSS Series D, 52(3), 381–393. DOI: 10.1111/1467-9884.00366
2. Author-hosted PDF: https://www2.stat-athens.aueb.gr/~jbn/papers2/08_Karlis_Ntzoufras_2003_RSSD.pdf
3. JSTOR record: https://www.jstor.org/stable/4128211
4. Ioannis Ntzoufras publication page: https://www2.stat-athens.aueb.gr/~jbn/papers/paper8.htm

---

## Knowledge-base tags

```text
football-analytics
probability
bivariate-poisson
karlis
ntzoufras
goal-model
score-dependence
draws
correlation
em-algorithm
maximum-likelihood
calibration
backtesting
robetting
```
