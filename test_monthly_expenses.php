<?php

// Test script untuk Monthly Expenses API
// Jalankan dengan: php test_monthly_expenses.php

echo "=== Testing Monthly Expenses API ===\n\n";

// Test 1: Get Categories
echo "1. Testing GET /api/monthly-expenses/categories\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/api/monthly-expenses/categories');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer YOUR_ACCESS_TOKEN_HERE',
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . substr($response, 0, 200) . "...\n\n";

// Test 2: Get Monthly Expenses (should be empty initially)
echo "2. Testing GET /api/monthly-expenses\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/api/monthly-expenses');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer YOUR_ACCESS_TOKEN_HERE',
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . substr($response, 0, 200) . "...\n\n";

// Test 3: Create Monthly Expense
echo "3. Testing POST /api/monthly-expenses\n";
$data = [
    'expense_category_id' => 1,
    'total_amount' => 500000,
    'note' => 'Budget bensin untuk testing'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/api/monthly-expenses');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer YOUR_ACCESS_TOKEN_HERE',
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . substr($response, 0, 300) . "...\n\n";

echo "=== Test completed ===\n";
echo "Note: Ganti YOUR_ACCESS_TOKEN_HERE dengan access token yang valid untuk test yang sesungguhnya\n";