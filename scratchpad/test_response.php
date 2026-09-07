<?php

use Illuminate\Http\JsonResponse;

// Load Laravel
require __DIR__ . '/../backend/vendor/autoload.php';
$app = require __DIR__ . '/../backend/bootstrap/app.php';

// Create a response with a float value
$data = ['weekly_hour_cap' => 40.0, 'name' => 'Test'];

$response = new JsonResponse($data, 201);

// Get the response content
$content = $response->getContent();
echo "Response content: " . $content . PHP_EOL;
echo "Response content length: " . strlen($content) . PHP_EOL;

// Decode it
$decoded = json_decode($content, true);
echo "Decoded weekly_hour_cap type: " . gettype($decoded['weekly_hour_cap']) . PHP_EOL;
echo "Decoded weekly_hour_cap value: " . var_export($decoded['weekly_hour_cap'], true) . PHP_EOL;

// Check if toLet's test the actual HTTP response through the test framework
echo "Done" . PHP_EOL;
?>
