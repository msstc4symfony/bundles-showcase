<?php

declare(strict_types=1);

namespace App\Tests\Unit\Slo;

use App\Slo\GeneratedEventSelector;
use App\Slo\GeneratedRecordingRules;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GeneratedEventSelectorTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    #[Override]
    protected function tearDown(): void
    {
        array_map(unlink(...), $this->files);
    }

    public function testTheLabelsAreThoseTheGeneratedRulesSelect(): void
    {
        $labels = new GeneratedEventSelector(new GeneratedRecordingRules(\dirname(__DIR__, 3) . '/infrastructure/observability/prometheus/slo-recording.rules.yml'))
            ->labels('orders.search_index', 'availability')
        ;

        self::assertSame(['application', 'component', 'slo', 'objective', 'revision'], array_keys($labels));
        self::assertSame(['showcase', 'orders', 'orders.search_index', 'availability'], \array_slice(array_values($labels), 0, 4));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $labels['revision']);
    }

    public function testNoMatchingRuleFails(): void
    {
        $this->expectExceptionMessageMatches('/found 0\.$/');

        $this->selector([$this->rule('application="showcase",slo="other"', 'other')])->labels('orders.search_index', 'availability');
    }

    public function testSeveralMatchingRulesFail(): void
    {
        $this->expectExceptionMessageMatches('/found 2\.$/');

        $rule = $this->rule('application="showcase"');
        $this->selector([$rule, $rule])->labels('orders.search_index', 'availability');
    }

    public function testAnExpressionWithoutTheEventSelectorFails(): void
    {
        $this->expectExceptionMessageMatches('/^No symfony_slo_events_total selector/');

        $this->selector([$this->rule(null)])->labels('orders.search_index', 'availability');
    }

    #[TestWith(['application="showcase",component=~"orders|gateway"', 'selects more than one value'])]
    #[TestWith(['application="showcase",component=~".+"', 'selects more than one value'])]
    #[TestWith(['application="showcase",result!="bad"', 'negative matcher'])]
    #[TestWith(['application="showcase",result!~"bad"', 'negative matcher'])]
    #[TestWith(['application="showcase",component=orders', 'Cannot parse the matcher'])]
    #[TestWith(['', 'has no label matchers'])]
    public function testASelectorWithoutOneHistoricalValuePerLabelFails(string $selector, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->selector([$this->rule($selector)])->labels('orders.search_index', 'availability');
    }

    #[TestWith(['application="showcase",action="App\\\\Order"'])]
    #[TestWith(['application="showcase",action="say \\"hi\\""'])]
    #[TestWith(['application="showcase",action="a,b"'])]
    #[TestWith(['application="showcase",action="a}b"'])]
    public function testAValueTheBackfillWouldCopyWronglyFails(string $selector): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has a backslash, quote, comma or "}"');

        $this->selector([$this->rule($selector)])->labels('orders.search_index', 'availability');
    }

    /**
     * @param list<string> $rules
     */
    private function selector(array $rules): GeneratedEventSelector
    {
        $file = tempnam(sys_get_temp_dir(), 'rules');
        self::assertIsString($file);
        $this->files[] = $file;
        file_put_contents($file, "groups:\n  - name: test\n    rules:\n" . implode('', $rules));

        return new GeneratedEventSelector(new GeneratedRecordingRules($file));
    }

    private function rule(?string $matchers, string $slo = 'orders.search_index'): string
    {
        $expression = $matchers === null ? 'vector(1)' : sprintf('sum(rate(symfony_slo_events_total{%s}[5m]))', $matchers);

        return sprintf("      - record: 'slo:events:rate5m'\n        expr: '%s'\n        labels: { slo: %s, objective: availability }\n", $expression, $slo);
    }
}
