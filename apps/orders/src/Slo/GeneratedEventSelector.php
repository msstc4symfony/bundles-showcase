<?php

declare(strict_types=1);

namespace App\Slo;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The labels of symfony_slo_events_total that the generated recording rules of one objective select, read from
 * slo-recording.rules.yml: the backfill then follows a new revision once the rules are regenerated.
 */
final readonly class GeneratedEventSelector
{
    private const string RECORD = 'slo:events:rate5m';

    /**
     * @param non-empty-string $rulesFile
     */
    public function __construct(private string $rulesFile)
    {
    }

    /**
     * @return non-empty-array<non-empty-string, string> label => value, in the selector's order
     */
    public function labels(string $slo, string $objective): array
    {
        $expressions = [];
        foreach ($this->rules() as $rule) {
            $labels = \is_array($rule['labels'] ?? null) ? $rule['labels'] : [];
            if (($rule['record'] ?? null) === self::RECORD
                && ($labels['slo'] ?? null) === $slo
                && ($labels['objective'] ?? null) === $objective
                && \is_string($rule['expr'] ?? null)) {
                $expressions[] = $rule['expr'];
            }
        }
        if (\count($expressions) !== 1) {
            throw new RuntimeException(sprintf('Expected one %s rule of %s %s in %s, found %d.', self::RECORD, $slo, $objective, $this->rulesFile, \count($expressions)));
        }

        return $this->parseSelector($expressions[0]);
    }

    /**
     * @return non-empty-array<non-empty-string, string>
     */
    private function parseSelector(string $expression): array
    {
        if (preg_match('/' . BackfillHistory::FAMILY . '_total\{([^}]*)}/', $expression, $selector) !== 1) {
            throw new RuntimeException(sprintf('No %s_total selector in "%s".', BackfillHistory::FAMILY, $expression));
        }
        preg_match_all('/([a-zA-Z_]\w*)(=~|=)"([^"]*)"/', $selector[1], $matchers, PREG_SET_ORDER);
        $labels = [];
        foreach ($matchers as [, $name, $operator, $value]) {
            // A one-value regex (component=~"orders") is a literal; anything wider has no single historical value.
            if ($operator === '=~' && preg_match('/^[\w.-]+$/', $value) !== 1) {
                throw new RuntimeException(sprintf('The matcher %s=~"%s" selects more than one value.', $name, $value));
            }
            $labels[$name] = $value;
        }
        if ($labels === []) {
            throw new RuntimeException(sprintf('The selector in "%s" has no label matchers.', $expression));
        }

        return $labels;
    }

    /**
     * @return list<array<mixed>>
     */
    private function rules(): array
    {
        $parsed = Yaml::parseFile($this->rulesFile);
        $rules = [];
        foreach (\is_array($parsed) && \is_array($parsed['groups'] ?? null) ? $parsed['groups'] : [] as $group) {
            foreach (\is_array($group) && \is_array($group['rules'] ?? null) ? $group['rules'] : [] as $rule) {
                if (\is_array($rule)) {
                    $rules[] = $rule;
                }
            }
        }

        return $rules;
    }
}
