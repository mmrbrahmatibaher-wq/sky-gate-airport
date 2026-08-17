<?php
/**
 * Flightradar24 API credentials — server-side only.
 * Never expose this file or the token to the browser.
 *
 * 1. Copy this file to config/fr24.php
 * 2. Replace the placeholder with your real token
 *
 * Auth: Authorization: Bearer <token>
 * Header: Accept-Version: v1
 * Docs: https://fr24api.flightradar24.com/docs
 */
return [
    // Put your full API token here (UUID|secret)
    'token' => 'YOUR_FR24_TOKEN_HERE',

    'base_url' => 'https://fr24api.flightradar24.com/api',

    // Hartsfield–Jackson Atlanta (KATL / ATL)
    'atl_lat' => 33.6407,
    'atl_lon' => -84.4277,

    // Local radar box around ATL (north,south,west,east)
    'local_bounds' => '34.45,32.85,-85.50,-83.35',

    // Continental US / wider view for Global Traffic
    'global_bounds' => '49.5,24.0,-125.0,-66.0',

    'local_limit' => 80,
    'global_limit' => 100,
];
