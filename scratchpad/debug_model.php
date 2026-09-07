<?php

use App\Models\ShiftGroup;

// Load Laravel
require __DIR__ . '/../backend/vendor/autoload.php';
$app = require __DIR__ . '/../backend/bootstrap/app.php';
\Illuminate\Foundation\Application::getInstance()->make('Illuminate\Contracts\Http\Kernel')->handle(
    \Illuminate\Http\Request::capture()
);

// Create a test group
$group = new ShiftGroup([
    'name' => 'Test',
    'settlement_mode' => 'quarterly',
    'weekly_hour_cap' => 40.0,
    'sort_order' => 0,
]);

// Get JSON representation
$json = $group->toJson();
echo "toJson() output: " . $json . PHP_EOL;

// Decode it
$decoded = json_decode($json, true);
echo "Decoded weekly_hour_cap type: " . gettype($decoded['weekly_hour_cap']) . PHP_EOL;
echo "Decoded weekly_hour_cap value: " . var_export($decoded['weekly_hour_cap'], true) . PHP_EOL;

// Also check toArray
$array = $group->toArray();
echo "\ntoArray() weekly_hour_cap type: " . gettype($array['weekly_hour_cap']) . PHP_EOL;
echo "toArray() weekly_hour_cap value: " . var_export($array['weekly_hour_cap'], true) . PHP_EOL;

// Check if toBe works on the array value
echo "\nUsing toBe on array value: ";
if ($array['weekly_hour_cap'] === 40.0) {
    echo "PASS (===)" . PHP_EOL;
} else {
    echo "FAIL (===)" . PHP_EOL;
}

if ($array['weekly_hour_cap'] == 40.0) {
    echo "PASS (==)" . PHP_EOL;
} else {
    echo "FAIL (==)" . PHP_EOL;
}
?>
