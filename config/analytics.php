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

];
