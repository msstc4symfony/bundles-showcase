<?php

declare(strict_types=1);

namespace App\Slo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The recording rules slo:generate wrote for this app, as Prometheus loads them.
 */
final readonly class GeneratedRecordingRules
{
    /**
     * @param non-empty-string $file
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/infrastructure/observability/prometheus/slo-recording.rules.yml')]
        public string $file,
    ) {
    }

    /**
     * @param non-empty-string $slo
     * @param non-empty-string $objective
     */
    public function ofObjective(string $slo, string $objective): RecordingRules
    {
        $rules = [];
        foreach ($this->all() as $rule) {
            if (($rule->labels['slo'] ?? null) === $slo && ($rule->labels['objective'] ?? null) === $objective) {
                $rules[] = $rule;
            }
        }

        return new RecordingRules($rules);
    }

    /**
     * @return list<RecordingRule>
     */
    private function all(): array
    {
        $parsed = Yaml::parseFile($this->file);
        $rules = [];
        foreach (\is_array($parsed) && \is_array($parsed['groups'] ?? null) ? $parsed['groups'] : [] as $group) {
            foreach (\is_array($group) && \is_array($group['rules'] ?? null) ? $group['rules'] : [] as $rule) {
                if (!\is_array($rule)) {
                    continue;
                }
                $record = $rule['record'] ?? null;
                $expr = $rule['expr'] ?? null;
                if (\is_string($record) && $record !== '' && \is_string($expr) && $expr !== '') {
                    $rules[] = new RecordingRule($record, $expr, $this->labels($rule['labels'] ?? null));
                }
            }
        }

        return $rules;
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function labels(mixed $labels): array
    {
        $strings = [];
        foreach (\is_array($labels) ? $labels : [] as $name => $value) {
            if (\is_string($name) && $name !== '' && \is_scalar($value)) {
                $strings[$name] = (string) $value;
            }
        }

        return $strings;
    }
}
