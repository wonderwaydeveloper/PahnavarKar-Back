<?php

$outputPath = $argv[1] ?? null;
if (! is_string($outputPath) || $outputPath === '') {
    fwrite(STDERR, "Usage: php tools/generate-plan-feature-details.php <app-output-file>\n");
    exit(2);
}

$catalog = require dirname(__DIR__).'/config/plans.php';
$descriptions = [];
foreach ($catalog['features'] as $key => $feature) {
    if (in_array($key, ['group-calculation', 'legal_library'], true)) {
        continue;
    }
    $descriptions[$key] = $feature['description'];
}

$json = json_encode(
    $descriptions,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);
$contents = "// Generated from PahnavarKar-Back/config/plans.php. Do not edit by hand.\n"
    ."export const PLAN_FEATURE_DETAILS = {$json} as const;\n";

if (file_put_contents($outputPath, $contents) === false) {
    fwrite(STDERR, "Could not write generated feature details to {$outputPath}\n");
    exit(1);
}

fwrite(STDOUT, "Generated ".count($descriptions)." plan feature descriptions.\n");
