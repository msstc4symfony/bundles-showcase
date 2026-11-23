<?php

declare(strict_types=1);

namespace App\Slo;

use InvalidArgumentException;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The recorded series of one SLO objective over the raw history app:slo:backfill wrote, so dashboards show that
 * history too (compose service prometheus-backfill). A rule that reads another rule's record lands in a later
 * pass: the caller loads each pass's blocks into the queried Prometheus before asking for the next one.
 */
#[AsCommand('app:slo:backfill-recorded', "Writes the OpenMetrics history of one SLO objective's recording rules to stdout")]
final class BackfillRecordedCommand extends Command
{
    public function __construct(
        private readonly GeneratedRecordingRules $rules,
        private readonly RecordedHistory $history,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('slo', null, InputOption::VALUE_REQUIRED, 'SLO name', 'orders.search_index')
            ->addOption('objective', null, InputOption::VALUE_REQUIRED, 'Objective type', 'availability')
            ->addOption('passes', null, InputOption::VALUE_NONE, 'Print the number of passes and exit')
            ->addOption('pass', null, InputOption::VALUE_REQUIRED, 'The pass to write, from 1')
            ->addOption('prometheus-url', null, InputOption::VALUE_REQUIRED, 'Prometheus holding the raw history and the earlier passes')
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'First evaluation, Unix seconds')
            ->addOption('end', null, InputOption::VALUE_REQUIRED, 'Last evaluation at or before, Unix seconds')
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = new ConsoleOptions($input);
        $slo = $options->string('slo');
        $objective = $options->string('objective');
        $passes = $this->rules->ofObjective($slo, $objective)->passes();
        if ($passes === []) {
            throw new InvalidArgumentException(sprintf('%s has no recording rules of %s %s.', $this->rules->file, $slo, $objective));
        }
        if ($input->getOption('passes') === true) {
            $output->writeln((string) \count($passes));

            return Command::SUCCESS;
        }

        $pass = $options->positiveInt('pass');
        $rules = $passes[$pass - 1] ?? throw new InvalidArgumentException(
            sprintf('--pass must be at most %d, the number of passes of %s %s.', \count($passes), $slo, $objective),
        );
        foreach ($this->history->lines($options->string('prometheus-url'), $rules, $options->positiveInt('start'), $options->positiveInt('end')) as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
}
