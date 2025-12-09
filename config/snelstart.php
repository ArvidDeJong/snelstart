<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Snelstart API Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration is used for the Snelstart API integration.
    |
    */

  
        // Base URL of the v2 API
        'base_url' => env('SNELSTART_BASE_URL', 'https://b2bapi.snelstart.nl/v2'),

        // Token endpoint from the developer portal
        'token_url' => env('SNELSTART_TOKEN_URL','https://auth.snelstart.nl/b2b/token'),

        // Your custom clientkey (tile "Maatwerk" in SnelStart Web)
        'client_key' => env('SNELSTART_CLIENT_KEY'),

        // Subscription key from the B2B portal (usually the primary key)
        'subscription_key' => env('SNELSTART_SUBSCRIPTION_KEY'),

];
