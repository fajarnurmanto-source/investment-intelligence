<?php

return [
    'provider' => env('II_MARKET_FEED_PROVIDER', 'local'),
    'api_key' => env('II_MARKET_FEED_API_KEY'),
    'provider_symbol' => env('II_MARKET_FEED_PROVIDER_SYMBOL'),
    'provider_mic' => env('II_MARKET_FEED_PROVIDER_MIC'),
    'enabled' => env('II_MARKET_FEED_ENABLED', false),
    'owner_id' => env('II_MARKET_FEED_OWNER_ID'),
    'ticker' => env('II_MARKET_FEED_TICKER'),
    'source_name' => env('II_MARKET_FEED_SOURCE_NAME'),
    'source_url' => env('II_MARKET_FEED_SOURCE_URL'),
    'rights_reference' => env('II_MARKET_FEED_RIGHTS_REFERENCE'),
    'storage_permitted' => env('II_MARKET_FEED_STORAGE_PERMITTED', false),
    'automation_permitted' => env('II_MARKET_FEED_AUTOMATION_PERMITTED', false),
    'currency' => env('II_MARKET_FEED_CURRENCY'),
    'price_adjustment' => env('II_MARKET_FEED_ADJUSTMENT'),
    'session_timezone' => env('II_MARKET_FEED_TIMEZONE'),
];
