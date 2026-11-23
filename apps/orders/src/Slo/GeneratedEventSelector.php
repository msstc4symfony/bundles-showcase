<?php

declare(strict_types=1);

namespace App\Slo;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The labels of symfony_slo_events_total that the generated recording rules of one objective select, read from
 * slo-recording.rules.yml: the backfill then follows a new revision once the rules are regenerated.
 */
final readonly class GeneratedEventSelector
{
    private const string RECORD = 'slo:events:rate5m';

    /** A PromQL double-quoted string with its escapes, so a quoted "," or "}" does not end a matcher early. */
    private const string QUOTED = '"(?:[^"\\\\]|\\\\.)*"';

    /**
     * @param non-empty-string $rulesFile
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/infrastructure/observability/prometheus/slo-recording.rules.yml')]
        private string $rulesFile,
    ) {
    }

    /**
     * @param non-empty-string $slo
     * @param non-empty-string $objective
     *
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
            throw new RuntimeException(sprintf(
                'Expected one %s rule of %s %s in %s, found %d.',
                self::RECORD,
                $slo,
                $objective,
                $this->rulesFile,
                \count($expressions),
            ));
        }

        return $this->parseSelector($expressions[0]);
    }

    /**
     * @return non-empty-array<non-empty-string, string>
     */
    private function parseSelector(string $expression): array
    {
        $selectorPattern = '/' . BackfillHistory::FAMILY . '_total\{((?:[^}"]|' . self::QUOTED . ')*)}/';
        if (preg_match($selectorPattern, $expression, $selector) !== 1) {
            throw new RuntimeException(sprintf('No %s_total selector in "%s".', BackfillHistory::FAMILY, $expression));
        }
        preg_match_all('/(?:[^,"]|' . self::QUOTED . ')+/', $selector[1], $matchers);
        $labels = [];
        foreach ($matchers[0] as $matcher) {
            if (trim($matcher) === '') {
                continue;
            }
            $matcherPattern = '/^\s*([a-zA-Z_]\w*)\s*(=~|!=|!~|=)\s*(' . self::QUOTED . ')\s*$/';
            if (preg_match($matcherPattern, $matcher, $parts) !== 1) {
                throw new RuntimeException(
                    sprintf('Cannot parse the matcher "%s" in "%s".', trim($matcher), $expression),
                );
            }
            [, $name, $operator, $quoted] = $parts;
            $value = substr($quoted, 1, -1);
            // Copied verbatim into OpenMetrics: an escaped value would not equal the live label value.
            if (strpbrk($value, '\\",}') !== false) {
                throw new RuntimeException(sprintf(
                    'The matcher %s%s%s has a backslash, quote, comma or "}", which the backfill does not support.',
                    $name,
                    $operator,
                    $quoted,
                ));
            }
            if ($operator === '!=' || $operator === '!~') {
                throw new RuntimeException(
                    sprintf('The negative matcher %s%s"%s" names no historical value.', $name, $operator, $value),
                );
            }
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
     * @return list<array<array-key, mixed>>
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
