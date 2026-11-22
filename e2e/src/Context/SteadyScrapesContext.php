<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\ScrapeBarrier;
use Behat\Behat\Context\Context;
use Behat\Hook\BeforeSuite;

/**
 * Right after "make up" the first scenarios would send traffic before Prometheus has scraped the targets; the NoData
 * scenario needs an instant before the stack's first SLO event at which orders was already scraped.
 */
final class SteadyScrapesContext implements Context
{
    #[BeforeSuite]
    public static function waitForSteadyScrapes(): void
    {
        new ScrapeBarrier()->awaitTargetsScrapedFor(20, 90);
    }
}
