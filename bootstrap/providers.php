<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\RouteRateLimiterServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    RouteRateLimiterServiceProvider::class,
];
