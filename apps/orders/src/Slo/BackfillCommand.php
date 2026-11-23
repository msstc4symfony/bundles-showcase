<?php

declare(strict_types=1);

namespace App\Slo;

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
 * covered from the stack's start. Only the raw series: app:slo:backfill-recorded derives the recorded ones from it,
 * and alerts compute live.
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
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Unix seconds of the last sample (default: the clock)')
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = new ConsoleOptions($input);
        $history = new BackfillHistory(
            $options->positiveInt('days'),
            $options->positiveInt('events-per-minute'),
            $options->ratio('bad-ratio'),
            $options->nonNegativeInt('burning-days'),
            $options->ratio('burning-bad-ratio'),
        );
        $labels = [
            ...$this->selector->labels($options->string('slo'), $options->string('objective')),
            ...self::SCRAPE_LABELS,
        ];
        if ($input->getOption('now') === null) {
            $now = $this->clock->now()->getTimestamp();
            Assert::positiveInteger($now, 'The clock must be after the Unix epoch.');
        } else {
            $now = $options->positiveInt('now');
        }

        foreach ($history->lines($labels, $now) as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
}
