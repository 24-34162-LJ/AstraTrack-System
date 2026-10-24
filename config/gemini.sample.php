<?php
// Copy this file to "config/gemini.php" and paste your Gemini API key
// (Google AI Studio -> "Get API key"). config/gemini.php must NOT be committed to git.
return [
    'api_key' => 'PASTE_YOUR_GEMINI_API_KEY_HERE',
    // If you get "model not found", copy the current model name from Google AI Studio.
    'model'   => 'gemini-3.6-flash',
    // Set to true ONLY if you get "SSL certificate problem" (curl error 60) on XAMPP. Local testing only.
    'insecure_ssl_for_local_testing' => false,
];
