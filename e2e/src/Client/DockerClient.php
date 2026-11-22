<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\Process\Process;
use Webmozart\Assert\Assert;

/**
 * Stops, starts and runs commands in compose services through the docker socket. The e2e image has the docker
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

    /**
     * @param non-empty-string $service
     */
    public function stop(string $service): void
    {
        $this->docker(['stop', ...$this->containers($service)]);
    }

    /**
     * @param non-empty-string $service
     */
    public function start(string $service): void
    {
        $this->docker(['start', ...$this->containers($service)]);
    }

    /**
     * @param non-empty-string $service
     * @param non-empty-list<string> $command
     *
     * @return string the command's standard output
     */
    public function exec(string $service, array $command): string
    {
        $containers = $this->containers($service);
        Assert::count($containers, 1, sprintf('Compose service "%s" has %d containers, exec needs exactly one.', $service, \count($containers)));

        return $this->docker(['exec', $containers[0], ...$command]);
    }

    /**
     * @param non-empty-string $service
     *
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
