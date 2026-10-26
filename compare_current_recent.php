<?php

$base = 'tools/datasets/dataset_core_v1_legacy_current_2024_2025.csv';
$challenger = 'tools/datasets/dataset_core_v1_team_history_2024_2025.csv';

function openCsv(string $path) {
    $h = fopen($path, 'r');
    if (!$h) {
        throw new RuntimeException("Cannot open $path");
    }
    return $h;
}

$b = openCsv($base);
$c = openCsv($challenger);

$bh = fgetcsv($b);
$ch = fgetcsv($c);

echo "Columns identical: " . (($bh === $ch) ? 'True' : 'False') . PHP_EOL;

if ($bh !== $ch) {
    exit(1);
}

$recent = [];
$nonRecent = [];

foreach ($bh as $i => $name) {
    if (str_starts_with($name, 'core_recent_')) {
        $recent[$i] = $name;
    } else {
        $nonRecent[$i] = $name;
    }
}

$modelRecent = array_filter(
    $recent,
    fn($name) => !str_ends_with($name, '_matches_considered')
);

echo "Recent columns total: " . count($recent) . PHP_EOL;
echo "Recent Candidate47 features: " . count($modelRecent) . PHP_EOL;

$rows = 0;
$nonRecentDiffs = 0;
$recentStats = [];

foreach ($recent as $i => $name) {
    $recentStats[$name] = [
        'changed' => 0,
        'legacy_null' => 0,
        'team_history_null' => 0,
    ];
}

while (true) {
    $br = fgetcsv($b);
    $cr = fgetcsv($c);

    if ($br === false && $cr === false) {
        break;
    }

    if ($br === false || $cr === false) {
        echo "ROW COUNT MISMATCH" . PHP_EOL;
        exit(1);
    }

    $rows++;

    foreach ($nonRecent as $i => $name) {
        if (($br[$i] ?? null) !== ($cr[$i] ?? null)) {
            $nonRecentDiffs++;

            if ($nonRecentDiffs <= 5) {
                echo "NON-RECENT DIFF row={$rows} column={$name}" . PHP_EOL;
            }
        }
    }

    foreach ($recent as $i => $name) {
        $lv = trim((string)($br[$i] ?? ''));
        $tv = trim((string)($cr[$i] ?? ''));

        if ($lv === '') {
            $recentStats[$name]['legacy_null']++;
        }

        if ($tv === '') {
            $recentStats[$name]['team_history_null']++;
        }

        if ($lv !== $tv) {
            $recentStats[$name]['changed']++;
        }
    }
}

fclose($b);
fclose($c);

echo PHP_EOL;
echo "Rows compared: {$rows}" . PHP_EOL;
echo "Non-Recent identical: " . ($nonRecentDiffs === 0 ? 'True' : 'False') . PHP_EOL;
echo "Non-Recent differences: {$nonRecentDiffs}" . PHP_EOL;
echo PHP_EOL;

foreach ($recentStats as $name => $s) {
    echo "{$name}: changed_rows={$s['changed']}, legacy_null={$s['legacy_null']}, team_history_null={$s['team_history_null']}" . PHP_EOL;
}
