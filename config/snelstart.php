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

    // Seconds to wait for the connection to SnelStart. Not a positive number: 10.
    'connect_timeout' => env('SNELSTART_CONNECT_TIMEOUT', 10),

    // Subscription key from the B2B developer portal (usually the primary key). Sent as a header.
    'subscription_key' => env('SNELSTART_SUBSCRIPTION_KEY'),

    // Seconds to wait for a whole request, the token request included. Not a positive number: 30.
    'timeout' => env('SNELSTART_TIMEOUT', 30),

    // The access token is kept, encrypted, in the cache until sixty seconds before it expires, so a
    // web request does not fetch its own. 'store' is a store from config/cache.php, null is the
    // default store. With 'enabled' false the token only lives in memory, on the client instance.
    'token_cache' => [
        'enabled' => env('SNELSTART_TOKEN_CACHE', true),
        'store' => env('SNELSTART_TOKEN_CACHE_STORE'),
    ],

    // Token endpoint from the developer portal.
    'token_url' => env('SNELSTART_TOKEN_URL', 'https://auth.snelstart.nl/b2b/token'),

];
