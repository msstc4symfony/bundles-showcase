<?php

declare(strict_types=1);

namespace App\Slo;

use InvalidArgumentException;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Assert\Assert;

/**
 * Raw SLO counter history for an empty Prometheus TSDB (compose service prometheus-backfill), so the 30d window is
 * covered from the stack's start. Only the raw series: recording rules and alerts compute from it live.
 */
#[AsCommand('app:slo:backfill', "Writes the OpenMetrics history of one SLO objective's event counters to stdout")]
final class BackfillCommand extends Command
{
    /**
     * The scrape labels prometheus.yml adds; instance "backfill" keeps the history apart from the live series, whose
     * instance is the container address, so a live counter never continues (or "resets") a historical one.
     */
    private const array SCRAPE_LABELS = ['job' => 'showcase', 'service' => 'orders', 'instance' => 'backfill'];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly GeneratedEventSelector $selector,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('slo', null, InputOption::VALUE_REQUIRED, 'SLO name', 'orders.search_index')
            ->addOption('objective', null, InputOption::VALUE_REQUIRED, 'Objective type', 'availability')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'History length, ending now', '60')
            ->addOption('events-per-minute', null, InputOption::VALUE_REQUIRED, 'Total events per minute', '2')
            ->addOption('bad-ratio', null, InputOption::VALUE_REQUIRED, 'Bad share before the burning tail', '0.002')
            ->addOption(
                'burning-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Length of the burning tail, ending now (0: none)',
                '4',
            )
            ->addOption('burning-bad-ratio', null, InputOption::VALUE_REQUIRED, 'Bad share in the burning tail', '0.03')
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $history = new BackfillHistory(
            $this->positiveInt($input, 'days'),
            $this->positiveInt($input, 'events-per-minute'),
            $this->ratio($input, 'bad-ratio'),
            $this->nonNegativeInt($input, 'burning-days'),
            $this->ratio($input, 'burning-bad-ratio'),
        );
        $labels = [
            ...$this->selector->labels($this->string($input, 'slo'), $this->string($input, 'objective')),
            ...self::SCRAPE_LABELS,
        ];
        $now = $this->clock->now()->getTimestamp();
        Assert::positiveInteger($now, 'The clock must be after the Unix epoch.');

        foreach ($history->lines($labels, $now) as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }

    /**
     * @return non-empty-string
     */
    private function string(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return \is_string($value) && $value !== ''
            ? $value
            : throw new InvalidArgumentException(sprintf('--%s must be set and not empty.', $name));
    }

    /**
     * @return positive-int
     */
    private function positiveInt(InputInterface $input, string $name): int
    {
        $value = filter_var($this->string($input, $name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return \is_int($value) && $value > 0
            ? $value
            : throw new InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
    }

    /**
     * @return non-negative-int
     */
    private function nonNegativeInt(InputInterface $input, string $name): int
    {
        $value = filter_var($this->string($input, $name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return \is_int($value) && $value >= 0
            ? $value
            : throw new InvalidArgumentException(sprintf('--%s must be a non-negative integer.', $name));
    }

    private function ratio(InputInterface $input, string $name): float
    {
        $value = filter_var($this->string($input, $name), FILTER_VALIDATE_FLOAT);

        return \is_float($value)
            ? $value
            : throw new InvalidArgumentException(sprintf('--%s must be a number.', $name));
    }
}
