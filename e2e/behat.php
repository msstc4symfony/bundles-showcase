<?php

declare(strict_types=1);

use App\E2e\Context\OrderContext;
use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;

return new Config()
    ->withProfile(new Profile('default')
        ->withSuite(new Suite('showcase')
            ->withPaths('%paths.base%/features')
            ->withContexts(OrderContext::class)))
;
