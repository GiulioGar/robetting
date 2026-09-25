<?php
require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$match = \App\Models\FootballMatch::with(['homeTeam','awayTeam','competition','season'])
    ->findOrFail(5334);

$svc  = $app->make(\App\Services\Prediction\MatchPredictionService::class);
$pred = $svc->predictWithDebug($match);

echo json_encode([
    'match_id'    => $pred['match_id'],
    'lambda_home' => $pred['lambda_home'],
    'lambda_away' => $pred['lambda_away'],
    'features'    => $pred['features'],
], JSON_PRETTY_PRINT);
