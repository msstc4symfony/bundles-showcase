<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\Process\Process;
use Webmozart\Assert\Assert;

/**
 * Stops and starts compose services through the docker socket. The e2e image has the docker
 * CLI but no compose plugin, so containers are found by their compose labels.
 */
final readonly class DockerClient
{
    private string $project;

    public function __construct(?string $project = null)
    {
        $project ??= getenv('COMPOSE_PROJECT_NAME');
        Assert::stringNotEmpty($project, 'COMPOSE_PROJECT_NAME is not set.');
        $this->project = $project;
    }

    public function stop(string $service): void
    {
        $this->docker(['stop', ...$this->containers($service)]);
    }

    public function start(string $service): void
    {
        $this->docker(['start', ...$this->containers($service)]);
    }

    /**
     * @return non-empty-list<string>
     */
    private function containers(string $service): array
    {
        $ids = array_values(array_filter(explode("\n", $this->docker([
            'ps', '--all', '--quiet',
            '--filter', 'label=com.docker.compose.project=' . $this->project,
            '--filter', 'label=com.docker.compose.service=' . $service,
        ]))));
        Assert::notEmpty($ids, sprintf('No container for compose service "%s".', $service));

        return $ids;
    }

    /**
     * @param list<string> $arguments
     */
    private function docker(array $arguments): string
    {
        $process = new Process(['docker', ...$arguments]);
        $process->mustRun();

        return $process->getOutput();
    }
}
