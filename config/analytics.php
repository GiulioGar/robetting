<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Structural Rating v1
    |--------------------------------------------------------------------------
    |
    | Parameters for TeamStructuralRatingCalculator.
    |
    |   structural_rating = base_rating + beta * ln(market_value / reference_market_value)
    |
    | beta and reference_market_value are experimental calibrations, not
    | mathematical constants — they can be updated without re-importing data.
    |
    */

    'structural_rating' => [
        'base_rating'             => 1500,
        'beta'                    => 150,
        'reference_market_value'  => 100_000_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Opponent Adjustment v1
    |--------------------------------------------------------------------------
    |
    | Coefficients for TeamOpponentAdjustedPerformanceCalculator.
    |
    | Calibrated via OLS on the Robetting historical dataset:
    |   7 324 team-match observations, 5 leagues (SA/PL/LaLiga/Ligue1/Buli),
    |   ~2.5 seasons.  Model: metric ~ elo_diff + is_home.
    |
    | Interpretation: per +1 Elo point of opponent advantage over league mean,
    |   the expected diff for the observed team changes by the coefficient.
    |   Positive: stronger opponent → our raw metric was suppressed →
    |   adjusted pushes the value upward to reflect neutral-opponent level.
    |
    |   shot_diff  : -4.17 per +100 Elo opponent (i.e. coeff 0.04170)
    |   sot_diff   : -1.71 per +100 Elo opponent (i.e. coeff 0.01709)
    |   goal_diff  : -0.76 per +100 Elo opponent (i.e. coeff 0.00764)
    |
    | calibration_version allows future re-calibrations to be tracked.
    |
    */

    'opponent_adjustment' => [
        'calibration_version' => 'v1',
        'coefficients'        => [
            'shot_diff'  => 0.04170,
            'sot_diff'   => 0.01709,
            'goal_diff'  => 0.00764,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Recent Time Decay v1
    |--------------------------------------------------------------------------
    |
    | Parameters for TeamTimeDecayedPerformanceCalculator (E12).
    |
    | Exponential decay formula:
    |
    |   weight(M) = 0.5 ^ (days_ago / half_life_days)
    |
    |   days_ago = target_kickoff_at − historical_match_kickoff_at (in days)
    |
    | half_life_days = 28 was selected as a methodological compromise:
    |   empirical analysis on 3 703 matches (5 leagues, 3 seasons) showed
    |   that no-decay last-10 is the strongest predictor (r ≈ 0.35 on goal_diff).
    |   HL-28 loses ~3% correlation vs no-decay but handles schedule irregularity
    |   (international breaks, cup runs) more robustly.  The no-decay Last-10
    |   baseline (E8) is preserved and should be used alongside E12.
    |
    | E12 is a *candidate feature family* for the Prediction Engine feature
    | selection phase.  It does NOT replace E8 (Last-10 flat).
    |
    | calibration_version: bump when parameters are updated.
    |
    */

    'recent_time_decay' => [
        'half_life_days'      => 28,
        'max_matches'         => 10,
        'max_horizon_days'    => 90,
        'calibration_version' => 'v1',
    ],

];
