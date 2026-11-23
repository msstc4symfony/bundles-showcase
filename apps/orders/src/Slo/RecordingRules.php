<?php

declare(strict_types=1);

namespace App\Slo;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Override;
use RuntimeException;

/**
 * @implements IteratorAggregate<int, RecordingRule>
 */
final readonly class RecordingRules implements IteratorAggregate, Countable
{
    /**
     * @param list<RecordingRule> $rules
     */
    public function __construct(
        public array $rules,
    ) {
    }

    /**
     * @return ArrayIterator<int, RecordingRule>
     */
    #[Override]
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->rules);
    }

    #[Override]
    public function count(): int
    {
        return \count($this->rules);
    }

    /**
     * A backfill evaluates rules against stored data only, so a rule that reads another one's record must run in
     * a later pass, once the earlier pass's blocks are loaded.
     *
     * @return list<self> in evaluation order, none empty
     */
    public function passes(): array
    {
        $passes = [];
        $pending = $this->rules;
        while ($pending !== []) {
            $ready = [];
            $blocked = [];
            foreach ($pending as $rule) {
                if ($this->readsAnyOther($rule, $pending)) {
                    $blocked[] = $rule;
                } else {
                    $ready[] = $rule;
                }
            }
            if ($ready === []) {
                throw new RuntimeException(sprintf(
                    'The recording rules %s read each other in a cycle.',
                    implode(', ', array_unique(array_map(static fn (RecordingRule $rule): string => $rule->record, $blocked))),
                ));
            }
            $passes[] = new self($ready);
            $pending = $blocked;
        }

        return $passes;
    }

    /**
     * @param list<RecordingRule> $rules
     */
    private function readsAnyOther(RecordingRule $rule, array $rules): bool
    {
        return array_any($rules, fn (RecordingRule $other): bool => $other !== $rule && $other->record !== $rule->record && $rule->reads($other));
    }
}
