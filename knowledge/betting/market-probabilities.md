# Bookmaker Probabilities, Overround and De-vig Methods

## Status

**Source status:** supported by technical literature and betting-market research  
**Robetting status:** foundational market-normalization framework  
**Decision:** bookmaker odds must never be compared directly with Robetting probabilities before removing the bookmaker margin.

---

## Key sources

### Clarke et al. (2017)

*Adjusting Bookmaker's Odds to Allow for Overround.*

This paper compares several ways to transform bookmaker-implied probabilities into adjusted probabilities, including:

- multiplicative normalization;
- additive adjustment;
- Shin's method.

Reference copy:
https://www.researchgate.net/publication/326510904_Adjusting_Bookmaker%27s_Odds_to_Allow_for_Overround

### Hvattum & Arntzen (2010)

*Using ELO ratings for match result prediction in association football.*

The paper converts bookmaker odds to probabilities by taking inverse odds and normalizing them to sum to one. These normalized market probabilities are then used as a forecasting benchmark.

Official record:
https://www.sciencedirect.com/science/article/pii/S0169207009001708

### Recent research direction

Goto, Takeishi & Yairi (2026).  
*Forecast Sports Outcomes under Efficient Market Hypothesis: Theoretical and Experimental Analysis of Odds-Only and Generalised Linear Models.*

This recent preprint compares existing odds-to-probability methods such as Multiplicative, Shin and Power and proposes newer conversion approaches.

Preprint:
https://arxiv.org/abs/2604.17194

This source is useful as a research lead, but Robetting should not treat a recent preprint as an established standard without replication.

---

## 1. Decimal odds are not fair probabilities

Given decimal odds:

```text
Home = 2.00
Draw = 3.40
Away = 4.00
```

the naive implied probabilities are:

```text
q_home = 1 / 2.00 = 0.5000
q_draw = 1 / 3.40 ≈ 0.2941
q_away = 1 / 4.00 = 0.2500
```

Their sum is:

```text
0.5000 + 0.2941 + 0.2500
=
1.0441
```

or:

```text
104.41%
```

The excess above 100% is usually called:

```text
overround
booksum excess
bookmaker margin indicator
```

In this example:

```text
overround = 4.41%
```

The raw inverse odds therefore cannot be interpreted as mutually consistent fair probabilities.

---

## 2. Booksums

For a market with decimal odds `o_i`:

```text
q_i = 1 / o_i
```

and:

```text
booksum = sum(q_i)
```

For a conventional fixed-odds market:

```text
booksum > 1
```

normally indicates a bookmaker margin.

A smaller booksum generally means odds closer to a fair 100% probability book, but the booksum alone does **not** tell us how the margin is distributed between outcomes.

This distinction matters.

---

## 3. Overround is not automatically the bookmaker's realized profit

It is tempting to write:

```text
booksum - 1
=
bookmaker profit
```

but that is too simplistic.

The booksum is a pricing-margin indicator.

Actual bookmaker profitability depends on:

- how stakes are distributed;
- liabilities;
- pricing errors;
- market movement;
- customer selection;
- promotions;
- limits and hedging.

Robetting should therefore use precise language:

```text
overround
```

rather than claiming it is equal to realized bookmaker profit.

---

## 4. Why de-vig is required

Suppose Robetting says:

```text
Home = 48%
Draw = 29%
Away = 23%
```

while raw inverse bookmaker odds give:

```text
Home = 50%
Draw = 30%
Away = 25%
```

These market values sum to 105%.

Comparing:

```text
Robetting 48%
vs
Market 50%
```

is not a fair comparison because the bookmaker probabilities still contain margin.

We first need:

```text
ODDS
↓
RAW IMPLIED PROBABILITIES
↓
REMOVE MARGIN
↓
FAIR / DE-VIG MARKET PROBABILITIES
↓
COMPARE WITH ROBETTING
```

---

## 5. Multiplicative / normalization method

The simplest de-vig transformation is:

```text
p_i
=
q_i / sum(q)
```

where:

```text
q_i = 1 / odds_i
```

Example:

```text
raw:
Home = 0.5000
Draw = 0.2941
Away = 0.2500

booksum = 1.0441
```

Normalize:

```text
Home = 0.5000 / 1.0441
Draw = 0.2941 / 1.0441
Away = 0.2500 / 1.0441
```

giving approximately:

```text
Home = 47.89%
Draw = 28.17%
Away = 23.94%
```

Sum:

```text
100%
```

### Assumption

The multiplicative method effectively removes margin proportionally from all raw implied probabilities.

It assumes the overround can be allocated proportionately.

### Advantages

- trivial to implement;
- deterministic;
- widely used;
- no historical calibration required;
- suitable as a baseline market conversion.

### Limitation

It assumes proportional margin allocation, which need not reflect real bookmaker pricing.

---

## 6. Additive method

Another approach subtracts an equal absolute amount from each raw implied probability.

For `n` outcomes:

```text
p_i
=
q_i
-
(booksum - 1) / n
```

Conceptually:

```text
same probability-point correction
for every selection
```

### Difference from normalization

Multiplicative:

```text
same relative correction
```

Additive:

```text
same absolute correction
```

These can produce meaningfully different fair probabilities, especially when:

```text
favorite odds
and
longshot odds
```

are far apart.

### Caution

For extreme markets, an additive correction can potentially create invalid negative probabilities.

Implementation must guard against this.

---

## 7. Favourite–longshot bias

Betting markets often exhibit a phenomenon known as:

```text
favourite-longshot bias
```

The precise direction and magnitude vary by market and dataset, but the general issue is that bookmaker margin may not be distributed proportionally across outcomes.

Longshots can carry different effective pricing distortions than favourites.

This is one reason why:

```text
simple normalization
```

may not always be the best estimate of underlying fair probabilities.

Robetting should treat favourite–longshot bias as an empirical question to measure on its own odds data.

---

## 8. Shin method

Shin's approach was developed to account for bookmaker pricing under assumptions involving informed bettors / insider trading.

Instead of simply scaling all inverse odds proportionally, the method estimates a latent parameter and adjusts implied probabilities nonlinearly.

The exact Shin formula should be implemented from a verified mathematical reference or tested library rather than reconstructed from memory.

### Robetting interpretation

Shin is useful because it represents a different assumption about how overround enters the market.

It should be treated as:

```text
alternative de-vig method
```

not as automatically superior.

---

## 9. Power method

Another common family is the **Power method**.

Conceptually:

```text
p_i
∝
q_i^k
```

where `k` is chosen so that:

```text
sum(p_i) = 1
```

This transformation can redistribute margin differently across favourites and longshots.

It is particularly relevant when proportional normalization appears systematically biased.

### Robetting status

Candidate method for comparison.

Do not make it canonical before empirical testing.

---

## 10. No single de-vig method should be assumed correct

This is a major research principle.

The true fair probabilities are unobserved.

Therefore:

```text
Multiplicative
Additive
Shin
Power
```

are all estimation procedures.

We can compare them retrospectively using realized outcomes and proper scoring rules.

But we should not write:

```text
de-vig probability = true probability
```

A better description is:

```text
estimated fair market probability
```

---

## 11. Robetting market-probability pipeline

Recommended pipeline:

```text
BOOKMAKER ODDS
    ↓
validate market completeness
    ↓
convert decimal odds to inverse odds
    ↓
calculate booksum / overround
    ↓
apply de-vig method
    ↓
validate probabilities sum to 1
    ↓
store method/version
    ↓
compare with Robetting probability
```

Every market probability must retain information about **how** it was generated.

---

## 12. Suggested persisted fields

Conceptually:

```text
match_id
bookmaker_id
market_code
selection_code

odds_decimal
odds_captured_at

raw_implied_probability

devig_method
devig_method_version
fair_probability

booksum
overround
```

Potentially:

```text
is_opening
is_closing
source_timestamp
```

The exact DB schema remains an implementation decision.

---

## 13. Version the de-vig method

Do not merely store:

```text
fair_probability = 0.4789
```

Store:

```text
devig_method = MULTIPLICATIVE
devig_version = 1
```

because later we may compare:

```text
MULTIPLICATIVE
SHIN
POWER
NEW METHOD
```

Historical values must remain reproducible.

---

## 14. Market completeness

Never de-vig an incomplete market.

For 1X2 we need all three selections from the same:

```text
bookmaker
market
timestamp / snapshot
```

For example:

```text
Home from Bookmaker A at 10:00
Draw from Bookmaker B at 11:00
Away from Bookmaker C at 12:00
```

does not define one genuine bookmaker book.

Mixing best prices can be useful for betting analysis, but not for estimating one bookmaker's overround.

Robetting must distinguish:

```text
single-bookmaker probability benchmark
```

from:

```text
best-odds composite
```

---

## 15. Opening vs closing odds

Odds move over time.

Therefore:

```text
market probability
```

is incomplete unless we know **when** the odds were observed.

Candidate snapshots:

```text
opening
24h before kickoff
6h before kickoff
1h before kickoff
closing
```

A model generated 24 hours before kickoff should not be benchmarked against closing odds without clearly stating that the market has 24 additional hours of information.

For fair comparisons:

```text
Robetting cutoff
≈
market odds timestamp
```

whenever possible.

---

## 16. Closing market as a benchmark

Closing odds are often a strong information benchmark because they incorporate late information and market activity.

However:

```text
closing market
```

and:

```text
Robetting pre-match forecast generated much earlier
```

are not information-equivalent.

Robetting should therefore maintain at least two possible comparisons:

```text
same-time market benchmark
closing-market benchmark
```

The second measures how the final market differs from our earlier estimate, not a perfectly fair simultaneous forecasting contest.

---

## 17. Consensus market probability

Later Robetting may want a multi-bookmaker consensus.

Possible approaches:

```text
average fair probabilities
median fair probabilities
weighted average by bookmaker quality
exchange reference
best-price derived estimate
```

These are separate research questions.

For `MARKET-001`, start with:

```text
one bookmaker
one complete market snapshot
one documented de-vig method
```

This is easier to audit.

---

## 18. Comparing Robetting with market probabilities

After de-vig:

```text
P_RB(selection)
P_market(selection)
```

Define raw probability edge:

```text
edge_pp
=
P_RB - P_market
```

Example:

```text
Robetting Over 2.5 = 0.58
Market fair Over 2.5 = 0.51

edge = +0.07
```

or:

```text
+7 percentage points
```

Important:

```text
probability edge
!=
expected value
```

Expected value additionally depends on the actual offered odds.

---

## 19. Expected value

For decimal odds `o` and Robetting probability `p`:

```text
EV
=
p * o - 1
```

Example:

```text
p = 0.58
o = 2.00
```

then:

```text
EV
=
0.58 * 2.00 - 1
=
0.16
```

or:

```text
+16%
```

This is model-implied expected return before practical considerations.

### Important

A positive model-implied EV does not prove a profitable opportunity.

It depends entirely on the quality and calibration of `p`.

---

## 20. Prediction Engine vs Market Engine vs Value Engine

Robetting architecture should retain three layers:

```text
PREDICTION ENGINE
↓
P_RB
```

independent of:

```text
MARKET ENGINE
↓
odds
↓
de-vig
↓
P_market
```

then:

```text
VALUE ENGINE
↓
P_RB vs price
↓
edge / EV
```

This separation is mandatory for scientific evaluation.

Otherwise bookmaker information can leak into what we later claim is an independent football prediction.

---

## 21. Market benchmark evaluation

Market probabilities should be scored exactly like Robetting forecasts.

For 1X2:

```text
Market Log Loss
Market Brier
Market RPS
Market Calibration
```

Then compare:

```text
RB-P-001
RB-DC-001
RB-BP-001
RB-ELO-...
MARKET
```

This is a much stronger evaluation than:

```text
who predicted more winners?
```

---

## 22. Proposed first market model

### Benchmark ID

```text
MARKET-MULT-001
```

### Method

```text
decimal odds
↓
inverse odds
↓
multiplicative normalization
```

### Why first

Not because it is known to be best.

Because it is:

- simple;
- transparent;
- reproducible;
- historically common;
- easy to audit.

It becomes our **market baseline**, analogous to `RB-P-001` for football modelling.

---

## 23. Proposed de-vig experiment

### Experiment ID

`EXP-MKT-DEVIG-001`

### Objective

Determine whether alternative margin-removal methods improve the probabilistic accuracy of bookmaker-derived 1X2 probabilities.

### Methods

```text
MULTIPLICATIVE
ADDITIVE
SHIN
POWER
```

### Dataset

Historical complete bookmaker 1X2 odds with final results.

### Requirements

Same:

```text
bookmaker
odds type
snapshot timing
competition
sample
```

for all methods.

### Metrics

```text
Log Loss
Brier
RPS
Calibration
```

### Question

```text
Which de-vig transformation produces the best
out-of-sample probability estimates?
```

---

## 24. Recent 2026 research

Goto, Takeishi & Yairi (2026) argue that converting bookmaker odds into accurate probabilities remains an open problem.

Their preprint evaluates existing methods including:

```text
Multiplicative
Shin
Power
```

on a large football dataset and proposes new methods.

This is highly relevant to Robetting because it reinforces:

```text
1 / odds
followed by one arbitrary de-vig rule
```

should not be treated as solved science.

### Robetting policy

Track this research.

Do not make a new 2026 preprint part of production methodology until:

- methodology is reviewed;
- results are independently reproducible;
- Robetting data confirms improvement.

---

## 25. Favourite-longshot diagnostics

For each de-vig method we should evaluate probability errors by odds region.

Example:

```text
raw implied probability
0-10%
10-20%
20-30%
...
80-90%
90-100%
```

Then inspect:

```text
predicted fair probability
vs
actual outcome frequency
```

This can reveal whether a de-vig method systematically:

```text
overprices longshots
underprices favourites
```

or vice versa.

---

## 26. Market-selection warning

Different bookmakers have different:

```text
margins
customer profiles
pricing models
limits
market-making behavior
```

Therefore a de-vig method that works best for one bookmaker may not be optimal for another.

Robetting should store:

```text
bookmaker_id
```

and test bookmaker-specific calibration later.

---

## 27. Exchange odds

Betting exchanges behave differently from traditional fixed-odds bookmakers.

Their prices may involve:

```text
back/lay spread
commission
liquidity
```

Therefore exchange-implied probabilities should not automatically use the same processing assumptions as bookmaker 1X2 prices.

Create a separate research note when exchange data becomes relevant.

---

## 28. Data-quality requirements

Before using odds:

- decimal odds must be positive and valid;
- market selections must be complete;
- bookmaker must be known;
- timestamp must be known whenever available;
- postponed/cancelled matches must be excluded appropriately;
- duplicated snapshots must be handled;
- opening and closing prices must not be confused;
- regulation-time market definitions must match match-result definitions.

For 1X2:

```text
90-minute result
```

must be distinguished from:

```text
qualification
extra time
penalties
```

especially for cup competitions.

---

## 29. Open questions for Robetting

- Which bookmaker(s) should define the benchmark?
- Which de-vig method should be canonical?
- Should method vary by bookmaker?
- Does multiplicative normalization systematically miscalibrate longshots?
- Does Shin improve modern football 1X2 probabilities?
- Does Power improve them?
- How stable are results across leagues?
- How stable are results across seasons?
- How much does overround vary by bookmaker?
- How much does overround vary by market?
- Opening or closing odds for model evaluation?
- How should same-time odds snapshots be aligned with `data_cutoff_at`?
- Should we create a bookmaker-consensus probability?
- Should consensus weight sharper bookmakers more heavily?
- Should exchanges later become the primary market benchmark?
- Does market calibration change with booksum?
- Which de-vig method performs best under Robetting's own scoring framework?

---

## 30. Strengths of market probabilities

- incorporate large amounts of information;
- provide an external benchmark;
- naturally available for many matches;
- can be evaluated using the same proper scores as Robetting;
- allow model-vs-market edge calculations.

---

## 31. Limitations

- raw odds include margin;
- fair probabilities are unobservable;
- de-vig requires assumptions;
- favourite-longshot bias may distort simple normalization;
- bookmakers differ;
- odds change over time;
- market probabilities may incorporate information unavailable to Robetting at its forecast cutoff;
- pricing is not identical to pure probability estimation;
- market efficiency varies by context.

---

## 32. Robetting decision

### Adopted principle

```text
RAW IMPLIED PROBABILITY
=
1 / odds
```

but:

```text
RAW IMPLIED PROBABILITY
!=
FAIR MARKET PROBABILITY
```

Initial canonical benchmark:

```text
MARKET-MULT-001
=
multiplicative normalization
```

only as the **baseline de-vig method**.

Planned research comparison:

```text
MULTIPLICATIVE
ADDITIVE
SHIN
POWER
```

No method is considered universally correct.

---

## 33. Evidence vs Robetting design choices

### Supported by cited literature

- bookmaker odds can be transformed into implied probabilities through inverse odds;
- inverse probabilities generally need adjustment for overround;
- multiplicative normalization is an established adjustment method;
- additive adjustment and Shin-type approaches are established alternatives;
- bookmaker-derived probabilities can serve as strong forecasting benchmarks;
- different margin-removal methods can generate different probability estimates;
- the choice of conversion method remains an active research problem.

### Robetting design choices

- `MARKET-MULT-001` as the initial market baseline;
- versioning every de-vig method;
- storing booksum and raw implied probability;
- requiring complete same-bookmaker market snapshots;
- aligning odds timestamp with model cutoff;
- scoring market probabilities with Log Loss/Brier/RPS/calibration;
- testing favourite-longshot calibration by probability bucket;
- separating Prediction, Market and Value engines.

---

## Sources

1. Clarke et al. (2017), *Adjusting Bookmaker's Odds to Allow for Overround*.
2. Hvattum, L. M. & Arntzen, H. (2010), *Using ELO ratings for match result prediction in association football*, International Journal of Forecasting, 26(3), 460–470.
3. Goto, K., Takeishi, N. & Yairi, T. (2026), *Forecast Sports Outcomes under Efficient Market Hypothesis: Theoretical and Experimental Analysis of Odds-Only and Generalised Linear Models*, arXiv:2604.17194.

---

## Knowledge-base tags

```text
betting
bookmaker
odds
implied-probability
overround
devig
multiplicative
additive
shin
power-method
favourite-longshot-bias
market-benchmark
expected-value
robetting
```
