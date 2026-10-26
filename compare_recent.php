<?php

$baseFile = 'tools/datasets/dataset_core_v1_2024_2025.csv';
$challengerFile = 'tools/datasets/dataset_core_v1_team_history_2024_2025.csv';

function loadCsv(string $file): array {
    $h = fopen($file, 'r');
    $header = fgetcsv($h);

    $rows = [];
    while (($row = fgetcsv($h)) !== false) {
        $rows[] = $row;
    }

    fclose($h);

    return [$header, $rows];
}

[$baseHeader, $baseRows] = loadCsv($baseFile);
[$challengerHeader, $challengerRows] = loadCsv($challengerFile);

echo "BASE: " . count($baseRows) . " rows, " . count($baseHeader) . " columns\n";
echo "CHALLENGER: " . count($challengerRows) . " rows, " . count($challengerHeader) . " columns\n";

$columnsIdentical = ($baseHeader === $challengerHeader);
echo "Columns identical: " . ($columnsIdentical ? 'True' : 'False') . "\n";

if (!$columnsIdentical) {
    exit(1);
}

$recentIndexes = [];
$otherIndexes = [];

foreach ($baseHeader as $i => $name) {
    if (str_starts_with($name, 'core_recent_')) {
        $recentIndexes[$i] = $name;
    } else {
        $otherIndexes[$i] = $name;
    }
}

echo "Recent features: " . count($recentIndexes) . "\n";

$nonRecentIdentical = count($baseRows) === count($challengerRows);

if ($nonRecentIdentical) {
    foreach ($baseRows as $r => $baseRow) {
        $challengerRow = $challengerRows[$r];

        foreach ($otherIndexes as $i => $name) {
            if (($baseRow[$i] ?? null) !== ($challengerRow[$i] ?? null)) {
                $nonRecentIdentical = false;
                echo "First non-Recent difference: row " . ($r + 2) . ", column {$name}\n";
                break 2;
            }
        }
    }
}

echo "Non-Recent identical: " . ($nonRecentIdentical ? 'True' : 'False') . "\n\n";

foreach ($recentIndexes as $i => $name) {
    $changed = 0;
    $legacyNull = 0;
    $teamHistoryNull = 0;

    foreach ($baseRows as $r => $baseRow) {
        $a = trim((string)($baseRow[$i] ?? ''));
        $b = trim((string)($challengerRows[$r][$i] ?? ''));

        if ($a === '') $legacyNull++;
        if ($b === '') $teamHistoryNull++;

        if ($a !== $b) {
            $changed++;
        }
    }

    echo "{$name}: changed_rows={$changed}, legacy_null={$legacyNull}, team_history_null={$teamHistoryNull}\n";
}
