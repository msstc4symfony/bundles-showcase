<?php

declare(strict_types=1);

namespace App\Slo;

use RuntimeException;
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
     * Alerting rules are skipped; anything else that is not a well-formed recording rule fails, so a typo never
     * silently drops a rule's history.
     *
     * @return list<RecordingRule>
     */
    private function all(): array
    {
        $parsed = Yaml::parseFile($this->file);
        $rules = [];
        foreach (\is_array($parsed) && \is_array($parsed['groups'] ?? null) ? $parsed['groups'] : [] as $group) {
            if (!\is_array($group) || !\is_array($group['rules'] ?? null)) {
                throw new RuntimeException(sprintf('%s has a group without a rules list.', $this->file));
            }
            $name = \is_string($group['name'] ?? null) ? $group['name'] : '?';
            foreach ($group['rules'] as $rule) {
                if (\is_array($rule) && isset($rule['alert'])) {
                    continue;
                }
                $record = \is_array($rule) ? $rule['record'] ?? null : null;
                $expr = \is_array($rule) ? $rule['expr'] ?? null : null;
                if (!\is_string($record) || $record === '' || !\is_string($expr) || $expr === '') {
                    throw new RuntimeException(sprintf(
                        '%s, group %s: %s is neither an alerting rule nor a recording rule with a record and an expr.',
                        $this->file,
                        $name,
                        Diagnostics::describe($rule),
                    ));
                }
                $rules[] = new RecordingRule(
                    $record,
                    $expr,
                    $this->labels(\is_array($rule) ? $rule['labels'] ?? null : null),
                    $this->interval($group['interval'] ?? null, $name),
                );
            }
        }

        return $rules;
    }

    /**
     * @return positive-int seconds
     */
    private function interval(mixed $interval, string $group): int
    {
        if (!\is_string($interval) || preg_match('/^([1-9]\d*)(s|m|h)$/', $interval, $parts) !== 1) {
            throw new RuntimeException(sprintf(
                '%s, group %s: the interval %s is not a whole number of s, m or h.',
                $this->file,
                $group,
                Diagnostics::describe($interval),
            ));
        }
        $unit = ['s' => 1, 'm' => 60, 'h' => 3600][$parts[2]];
        $count = filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, $unit)]]);
        if (!\is_int($count) || $count < 1) {
            throw new RuntimeException(sprintf('%s, group %s: the interval %s does not fit in an integer of seconds.', $this->file, $group, $interval));
        }

        return $count * $unit;
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
