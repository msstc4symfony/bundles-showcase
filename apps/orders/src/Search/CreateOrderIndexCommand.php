<?php

declare(strict_types=1);

namespace App\Search;

use Elastica\Index;
use LogicException;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * fos:elastica:create fails on an existing index and fos:elastica:reset drops its documents; the stand
 * runs this on every start, so the index (with the configured mapping) is created only when it is missing.
 */
#[AsCommand('app:search:create-index', 'Creates the orders search index with its mapping unless it exists')]
final class CreateOrderIndexCommand extends Command
{
    private const string INDEX = 'orders';

    public function __construct(
        #[Autowire(service: 'fos_elastica.index.orders')]
        private readonly Index $index,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->index->exists()) {
            $output->writeln(sprintf('Index "%s" already exists.', $this->index->getName()));

            return Command::SUCCESS;
        }

        $application = $this->getApplication() ?? throw new LogicException('The command runs inside a console application only.');
        $create = new ArrayInput(['--index' => self::INDEX]);
        $create->setInteractive(false);

        return $application->find('fos:elastica:create')->run($create, $output);
    }
}
