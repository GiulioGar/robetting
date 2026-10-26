<?php

$base = 'tools/datasets/dataset_core_v1_legacy_current_2024_2025.csv';
$challenger = 'tools/datasets/dataset_core_v1_team_history_2024_2025.csv';

$b = fopen($base, 'r');
$c = fopen($challenger, 'r');

$header = fgetcsv($b);
$header2 = fgetcsv($c);

if ($header !== $header2) {
    die("Headers differ\n");
}

$diffs = [];
$rows = 0;

while (($br = fgetcsv($b)) !== false && ($cr = fgetcsv($c)) !== false) {
    $rows++;

    foreach ($header as $i => $name) {
        if (str_starts_with($name, 'core_recent_')) {
            continue;
        }

        if (($br[$i] ?? null) !== ($cr[$i] ?? null)) {
            $diffs[$name] = ($diffs[$name] ?? 0) + 1;
        }
    }
}

fclose($b);
fclose($c);

arsort($diffs);

echo "Rows compared: $rows\n";
echo "Non-Recent columns with differences: " . count($diffs) . "\n\n";

foreach ($diffs as $name => $count) {
    echo "$name: $count\n";
}
