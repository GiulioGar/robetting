# Monte Carlo and Season Simulation

## Status

**Source status:** supported by peer-reviewed football research  
**Robetting status:** future simulation layer  
**Suggested simulation id:** `RB-SIM-001`  
**Decision:** season simulation should sit downstream of calibrated match probabilities. It must not invent probabilities independently of the prediction engine.

---

## Primary football source

Van Eetvelde, H., Hvattum, L. M. & Ley, C.  
*The Probabilistic Final Standing Calculator: a fair stochastic tool to handle abruptly stopped football seasons.*

Published in *AStA Advances in Statistical Analysis*.  
DOI: `10.1007/s10182-021-00416-6`

Springer:
https://link.springer.com/article/10.1007/s10182-021-00416-6

Open-access copy:
https://www.ncbi.nlm.nih.gov/pmc/articles/PMC8355582/

Preprint:
https://arxiv.org/abs/2101.10597

---

## 1. Core idea

A league season contains many uncertain future matches.

Instead of predicting one deterministic final table:

```text
1. Inter
2. Juventus
3. Milan
...
```

a simulation system repeatedly samples possible outcomes for all remaining fixtures.

Each complete simulated season produces one possible final table.

Repeating this process many times produces probabilities such as:

```text
Inter

Champion       35.4%
Top 4          79.1%
Top 6          94.0%
Relegation      0.1%
```

The output is therefore a probability distribution over future season outcomes.

---

## 2. Monte Carlo principle

Suppose each future match has probabilities:

```text
P(Home)
P(Draw)
P(Away)
```

Example:

```text
Inter - Milan

Home = 0.48
Draw = 0.28
Away = 0.24
```

For one simulation, sample one result according to those probabilities.

Repeat for every remaining match.

Then:

```text
calculate points
apply ranking rules
produce final table
```

One run is one possible future.

After many runs:

```text
N simulations
```

estimate probabilities by frequency.

Example:

```text
Inter champion in 34,720
of 100,000 simulations

P(title)
≈
34.72%
```

---

## 3. Monte Carlo does not improve bad probabilities

This is a critical Robetting principle.

Monte Carlo simulation is a propagation mechanism.

It takes:

```text
input probabilities
```

and propagates them through:

```text
remaining fixture structure
league rules
random outcomes
```

If match probabilities are badly calibrated:

```text
simulation output will also be unreliable
```

Therefore:

```text
good simulation
cannot rescue
bad match model
```

Robetting must validate match-level probabilities before treating season probabilities as trustworthy.

---

## 4. Relationship with the Prediction Engine

Recommended architecture:

```text
MATCH DATA
↓
PREDICTION ENGINE
↓
P(Home), P(Draw), P(Away)
↓
SEASON SIMULATOR
↓
100,000 possible seasons
↓
league outcome probabilities
```

The simulation layer should not contain its own undocumented strength heuristics.

It consumes versioned match probabilities.

---

## 5. Input requirements

A league-season simulator needs:

### Current state

```text
competition_id
season_id
current standings
points
goals for
goals against
other tie-break state if required
```

### Remaining fixtures

```text
match_id
home_team_id
away_team_id
kickoff_at
```

### Probabilities

For each future fixture:

```text
P(Home)
P(Draw)
P(Away)
model_version
generated_at
data_cutoff_at
```

### Competition rules

```text
points for win
points for draw
tie-break order
number of relegated teams
European qualification rules
playoffs if relevant
```

Without competition rules, the final table cannot be reproduced correctly.

---

## 6. Simulation algorithm

Conceptually:

```text
for simulation in 1..N:

    standings = current real standings

    for each remaining match:

        draw random u from Uniform(0,1)

        if u < P(Home):
            result = HOME

        else if u < P(Home) + P(Draw):
            result = DRAW

        else:
            result = AWAY

        update standings

    apply competition tie-break rules

    save final ranking
```

After all runs:

```text
aggregate final positions
```

---

## 7. Position probabilities

For every team calculate:

```text
P(position = 1)
P(position = 2)
...
P(position = N)
```

This gives a full probability matrix:

```text
             1st   2nd   3rd   ... 20th

Inter        .35   .27   .18
Milan        .20   .25   .21
Juventus     .18   .22   .19
...
```

From that matrix derive:

```text
P(champion)
P(top 4)
P(top 6)
P(relegation)
```

---

## 8. Expected final position

Also calculate:

```text
E[position]
=
sum(
    position * P(position)
)
```

Example:

```text
Inter

1st = 0.35
2nd = 0.30
3rd = 0.20
4th = 0.10
5th = 0.05
```

Expected position:

```text
1*0.35
+2*0.30
+3*0.20
+4*0.10
+5*0.05
```

This can be useful, but it should never replace the full distribution.

Two teams can have similar expected positions but very different uncertainty.

---

## 9. Why schedule strength matters

A current table ignores the difficulty of remaining fixtures.

Example:

```text
Team A: 60 points
Team B: 58 points
```

but:

```text
Team A remaining:
top clubs

Team B remaining:
bottom clubs
```

The simulation naturally incorporates this difference because every remaining fixture has different outcome probabilities.

This is one of the main motivations behind the Probabilistic Final Standing Calculator.

---

## 10. Dynamic vs fixed future probabilities

There are two simulation philosophies.

### A. Fixed probabilities

Before each simulation:

```text
calculate probabilities for all remaining matches
```

Then use the same probabilities throughout that simulated season.

Advantages:

```text
simple
fast
auditable
```

Limitation:

A simulated result does not change future simulated team strength.

### B. Dynamic simulation

After each simulated match:

```text
update simulated rating/state
↓
recalculate future match probabilities
```

Advantages:

```text
models path dependency
```

Limitations:

```text
much more computationally expensive
model behavior becomes harder to audit
simulated results influence simulated ratings
```

### Robetting decision

Start with:

```text
FIXED-PROBABILITY SIMULATION
```

Only test dynamic simulation later.

---

## 11. Why fixed simulation is a good first baseline

For pre-match league probabilities, the current Prediction Engine already represents our knowledge of team strength.

Re-updating it using randomly simulated results introduces a second assumption:

```text
a result that never actually happened
changes estimated future strength
```

That may be defensible, but it must be validated.

Therefore `RB-SIM-001` should initially keep future probabilities fixed within each simulation cycle.

---

## 12. Number of simulations

Monte Carlo probabilities contain sampling error.

More simulations reduce this error.

Typical research/production candidates:

```text
10,000
50,000
100,000
500,000
1,000,000
```

The correct value depends on:

```text
required precision
number of teams
remaining fixtures
runtime
```

Robetting should test convergence rather than selecting a number arbitrarily.

---

## 13. Monte Carlo standard error

If an estimated probability is:

```text
p
```

from:

```text
N
```

independent simulations, an approximate Monte Carlo standard error is:

```text
sqrt(
    p * (1-p) / N
)
```

Example:

```text
p = 0.50
N = 100,000
```

gives approximately:

```text
0.00158
```

or about:

```text
0.16 percentage points
```

This helps choose an adequate number of simulations.

---

## 14. Random seed and reproducibility

Every simulation run used for research should record:

```text
random_seed
simulation_count
simulator_version
prediction_model_version
```

This allows an experiment to be reproduced exactly.

Production probability runs may use new seeds, but research comparisons should be deterministic when possible.

---

## 15. Tie-break rules matter

Football competitions differ.

Possible ranking rules include:

```text
points
goal difference
goals scored
head-to-head points
head-to-head goal difference
playoff
```

Therefore the simulator must not hardcode:

```text
points → goal difference → goals scored
```

as a universal rule.

Competition rules need to be configurable.

This will become especially important when Robetting expands beyond the initial domestic leagues.

---

## 16. Problem: simulated goal difference

If the simulator samples only:

```text
Home / Draw / Away
```

it knows the points outcome but not the simulated score.

This can be insufficient when tie-break rules depend on:

```text
goal difference
goals scored
head-to-head score
```

There are two possible approaches.

### Outcome-only simulation

Sample:

```text
H / D / A
```

Then use simplified tie-breaking assumptions.

### Scoreline simulation

Sample:

```text
0-0
1-0
1-1
2-1
...
```

from the prediction engine's score probability matrix.

Then simulated standings can update:

```text
goals for
goals against
goal difference
```

### Robetting recommendation

Because Poisson/Dixon-Coles naturally produce a score distribution, prefer **scoreline simulation** when available.

This makes league rules substantially more realistic.

---

## 17. Natural synergy with goal models

With:

```text
RB-P-001
RB-DC-001
RB-BP-001
```

we already obtain:

```text
P(HomeGoals=x, AwayGoals=y)
```

The simulator can sample directly from this matrix.

Architecture:

```text
GOAL MODEL
↓
score matrix
↓
sample scoreline
↓
update simulated standings
```

This is more powerful than sampling only 1X2.

---

## 18. Simulation with Elo/direct 1X2 models

An Elo ordered-logit model may output only:

```text
P(Home)
P(Draw)
P(Away)
```

In that case a scoreline cannot be sampled directly.

Options:

```text
use outcome-only simulation
```

or:

```text
combine Elo with a conditional score model
```

This is one reason goal-distribution models are particularly attractive for season simulation.

---

## 19. Current standings must be real

At simulation start:

```text
already played matches
=
fixed historical reality
```

Only future fixtures are sampled.

Never resimulate matches that have already occurred when producing a current season forecast.

Historical experiment frameworks may intentionally "stop" a past season at date T and simulate from there.

---

## 20. Historical backtesting of season probabilities

Season forecasts should also be evaluated historically.

Example:

For every past season:

```text
after matchday 5
simulate rest of season

after matchday 10
simulate rest

after matchday 15
simulate rest
...
```

Then compare predicted probabilities with eventual outcomes.

This produces questions such as:

```text
When a team was assigned 70% title probability,
how often did it actually become champion?
```

That is calibration at the **season-event level**.

---

## 21. Season-event calibration

Possible events:

```text
champion
top 4
top 6
relegation
```

Collect historical predictions.

Example:

```text
all team-season states
where P(top4) was 70-80%
```

Observed frequency should be approximately comparable.

However, samples will be much smaller than match-level samples.

Interpretation must therefore be cautious.

---

## 22. Evaluation against naive alternatives

`RB-SIM-001` should be compared against simple baselines such as:

```text
current table rank
current points per game
current point extrapolation
```

A simulation model should demonstrate that incorporating fixture-specific probabilities adds useful information.

The PFSC literature specifically motivates simulation because an incomplete table may be biased by differences in the schedule already played and still remaining.

---

## 23. Proposed Robetting simulation model

### Simulation ID

```text
RB-SIM-001
```

### Inputs

```text
current standings
remaining fixtures
RB-DC-001 score matrices
competition rules
```

Initial preferred prediction model:

```text
RB-DC-001
```

only if Dixon-Coles wins the preceding probabilistic backtests.

Otherwise the simulator uses whichever score model is the current validated Robetting model.

### Run count

Start research with:

```text
100,000 simulations
```

but confirm convergence empirically.

---

## 24. Suggested stored outputs

Per run configuration:

```text
competition_id
season_id
simulation_version
prediction_model_version
simulation_count
random_seed
generated_at
data_cutoff_at
```

Per team:

```text
team_id
expected_position
expected_points

p_position_1
p_position_2
...

p_champion
p_top4
p_top6
p_relegation
```

Raw individual simulation tables generally do not need permanent production storage unless needed for audit/research.

---

## 25. Public-facing possibilities

Future Robetting competition page:

```text
SERIE A — SEASON PROJECTIONS

Inter
Title       42%
Top 4       88%

Milan
Title       24%
Top 4       76%

Napoli
Title       19%
Top 4       70%
```

Potential visualizations:

```text
position probability heatmap
title probability history
top-4 probability history
relegation probability history
```

These outputs are more informative than one deterministic predicted table.

---

## 26. Probability evolution

If simulations are run regularly:

```text
Matchday 5
Matchday 6
Matchday 7
...
```

Robetting can store the evolution:

```text
Inter title probability

MD5  18%
MD10 27%
MD15 41%
MD20 55%
...
```

This creates an interesting analytical timeline.

The prediction model version and data cutoff must remain attached to every snapshot.

---

## 27. Qualification rules

European qualification is not always identical to a fixed league position.

It can depend on:

```text
domestic cup winner
European titleholder
coefficient allocations
competition regulations
```

Therefore:

```text
P(top4)
```

is not always identical to:

```text
P(Champions League qualification)
```

Robetting should initially expose simple position probabilities.

Competition-specific qualification logic can be introduced separately.

---

## 28. Knockout competitions

Monte Carlo can later simulate cups too.

Architecture:

```text
match probability
↓
simulate leg / tie
↓
simulate bracket
↓
P(quarter-final)
P(semi-final)
P(final)
P(champion)
```

But knockout competitions require:

```text
extra time
penalties
two-legged aggregate scores
away/home legs
draw rules
```

This belongs to a later simulation version.

---

## 29. Uncertainty in model parameters

Basic Monte Carlo season simulation samples:

```text
match outcomes
```

while treating model probabilities as fixed.

A richer Bayesian simulation could also sample uncertainty in:

```text
team strength
model parameters
```

This produces wider and potentially more realistic forecast uncertainty.

However, this is substantially more complex.

Robetting should begin with outcome uncertainty only.

---

## 30. Model uncertainty

Another future extension is model averaging.

Example:

```text
RB-DC
RB-BP
RB-ELO
```

each implies different probabilities.

A future ensemble simulation could account for:

```text
model uncertainty
```

instead of choosing exactly one model.

This should not be attempted before individual models are validated.

---

## 31. Proposed experiment

### Experiment ID

```text
EXP-SIM-001
```

### Objective

Validate the Robetting season simulator on completed historical Serie A seasons.

### Procedure

Choose historical cutoffs:

```text
after MD5
after MD10
after MD15
after MD20
after MD25
after MD30
```

At each cutoff:

```text
use only information available then
generate future match probabilities
simulate remaining season
```

Store:

```text
P(champion)
P(top4)
P(relegation)
position distribution
```

Compare with final real standings.

---

## 32. Convergence experiment

### Experiment ID

```text
EXP-SIM-CONV-001
```

Run identical season state with:

```text
1,000
10,000
50,000
100,000
500,000
```

simulations.

Compare probability estimates and runtime.

Goal:

Choose the smallest run count providing sufficiently stable estimates for production.

---

## 33. Scoreline vs 1X2 simulation experiment

### Experiment ID

```text
EXP-SIM-SCORE-001
```

Compare:

```text
A. sample only H/D/A
B. sample full score distribution
```

Inspect differences in:

```text
final rankings
goal difference tie-breaks
position probabilities
runtime
```

Expected hypothesis:

Scoreline simulation should be preferable when competition tie-breaks depend on goals.

This remains a Robetting hypothesis until tested.

---

## 34. Open questions for Robetting

- How many simulations are sufficient?
- Which validated match model should feed the simulator?
- Fixed or dynamically updated future probabilities?
- Sample 1X2 or full scorelines?
- How should head-to-head tie-breaks be implemented?
- Should model parameter uncertainty be simulated?
- How should postponed/unplayed fixtures be handled?
- How often should season projections be regenerated?
- Should probabilities update immediately after every completed match?
- How should cup qualification affect European qualification probabilities?
- How should deductions or sanctions be represented?
- How should competitions with playoffs be simulated?
- How should knockout stages later be supported?
- How should uncertainty be communicated to portal users?
- What sample size is needed to assess season-probability calibration?

---

## 35. Strengths

- converts match probabilities into season-level forecasts;
- directly incorporates remaining fixture difficulty;
- provides full position distributions;
- supports title/top-N/relegation probabilities;
- naturally reuses Robetting match probabilities;
- easy to interpret;
- easy to parallelize;
- can handle complex competition schedules.

---

## 36. Limitations

- quality cannot exceed quality of input probabilities;
- Monte Carlo introduces sampling error;
- fixed-probability simulation ignores path-dependent strength changes;
- tie-break rules can add complexity;
- outcome-only simulation loses goal information;
- season-level calibration has relatively small samples;
- qualification and cup rules can become complex;
- simulation uncertainty is not the same as model-parameter uncertainty.

---

## 37. Robetting decision

### Current status

```text
RB-SIM-001 = FUTURE SIMULATION LAYER
```

### Core principle

```text
Prediction Engine
comes first.

Simulation Engine
consumes its probabilities.
```

### Preferred initial design

```text
validated score-distribution model
↓
sample future scorelines
↓
apply real competition rules
↓
100k+ repeated seasons
↓
position/event probabilities
```

The simulator should not be implemented before the match prediction models have undergone proper out-of-sample validation.

---

## 38. Evidence vs Robetting design choices

### Supported by Van Eetvelde, Hvattum & Ley

- remaining football matches can be simulated using a stochastic model;
- repeated simulation yields probabilities for possible final rankings;
- fixture schedule differences matter when evaluating incomplete standings;
- probabilistic final standings contain richer information than a single deterministic ranking;
- the approach can be historically evaluated by pretending previous seasons stopped at earlier points.

### Robetting design choices

- id `RB-SIM-001`;
- use of the current validated Robetting probability model as input;
- preference for scoreline simulation;
- initial 100,000-run target;
- storage of title/top4/top6/relegation probabilities;
- convergence experiment;
- fixed probabilities as the first implementation;
- historical simulation snapshots by matchday;
- simulator as a separate application layer.

---

## Sources

1. Van Eetvelde, H., Hvattum, L. M. & Ley, C., *The Probabilistic Final Standing Calculator: a fair stochastic tool to handle abruptly stopped football seasons*, AStA Advances in Statistical Analysis. DOI: 10.1007/s10182-021-00416-6.
2. Springer article: https://link.springer.com/article/10.1007/s10182-021-00416-6
3. Open-access copy: https://www.ncbi.nlm.nih.gov/pmc/articles/PMC8355582/
4. Preprint: https://arxiv.org/abs/2101.10597

---

## Knowledge-base tags

```text
football-analytics
monte-carlo
simulation
season-simulation
league-table
probability
standings
title-probability
relegation
top4
scoreline-simulation
uncertainty
backtesting
robetting
```
