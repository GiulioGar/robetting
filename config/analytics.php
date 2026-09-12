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

];
