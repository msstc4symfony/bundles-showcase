<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Override;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Behaves like a healthy cache until the given save() call, from which on every save reports failure.
 */
final class FailingSaveCache extends ArrayAdapter
{
    private int $saves = 0;

    /**
     * @param positive-int $failingSave counted from 1
     */
    public function __construct(private readonly int $failingSave)
    {
        parent::__construct();
    }

    #[Override]
    public function save(CacheItemInterface $item): bool
    {
        return ++$this->saves < $this->failingSave && parent::save($item);
    }
}
