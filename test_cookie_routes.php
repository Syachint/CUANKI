<?php

// Test cookie endpoint untuk debug
Route::get('/test-cookie', function() {
    $response = response()->json(['message' => 'Test cookie']);
    
    // Simple cookie test
    return $response->cookie('test_cookie', 'test_value', 60); // 60 minutes
});

Route::get('/check-cookie', function(Request $request) {
    return response()->json([
        'cookies' => $request->cookies->all(),
        'test_cookie' => $request->cookie('test_cookie')
    ]);
});