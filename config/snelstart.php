<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SnelStart B2B API
    |--------------------------------------------------------------------------
    |
    | Read these values through Darvis\Snelstart\Support\SnelstartConfig. The
    | keys are in alphabetical order.
    |
    */

    // Base URL of the v2 API.
    'base_url' => env('SNELSTART_BASE_URL', 'https://b2bapi.snelstart.nl/v2'),

    // Your client key (tile "Maatwerk" in SnelStart Web). Sent in the body of the token request.
    'client_key' => env('SNELSTART_CLIENT_KEY'),

    // Subscription key from the B2B developer portal (usually the primary key). Sent as a header.
    'subscription_key' => env('SNELSTART_SUBSCRIPTION_KEY'),

    // Token endpoint from the developer portal.
    'token_url' => env('SNELSTART_TOKEN_URL', 'https://auth.snelstart.nl/b2b/token'),

];
