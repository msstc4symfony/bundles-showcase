<?php

declare(strict_types=1);

use App\E2e\Context\HealthContext;
use App\E2e\Context\IdempotencyContext;
use App\E2e\Context\MetricsContext;
use App\E2e\Context\OrderContext;
use App\E2e\Context\TracingContext;
use App\E2e\Context\WorkerResetContext;
use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;

return new Config()
    ->withProfile(new Profile('default')
        ->withSuite(new Suite('showcase')
            ->withPaths('%paths.base%/features')
            ->withContexts(OrderContext::class, IdempotencyContext::class, TracingContext::class, WorkerResetContext::class, MetricsContext::class, HealthContext::class)))
;
