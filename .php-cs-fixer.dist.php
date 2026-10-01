<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/apps/gateway', __DIR__ . '/apps/orders', __DIR__ . '/apps/billing'])
    ->exclude(['vendor', 'var', 'config', 'public', 'bin'])
    ->exclude('var')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'phpdoc_align' => [
            'align' => 'left',
        ],
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
        ],
        'phpdoc_to_comment' => false,
        'linebreak_after_opening_tag' => false,
        'blank_line_after_opening_tag' => false,
        'concat_space' => [
            'spacing' => 'one',
        ],
        'increment_style' => [
            'style' => 'post',
        ],
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
        'class_attributes_separation' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arguments', 'arrays', 'match', 'parameters'],
        ],
        'multiline_whitespace_before_semicolons' => ['strategy' => 'new_line_for_chained_calls'],
        'global_namespace_import' => ['import_classes' => true],
        'blank_line_before_statement' => [
            'statements' => ['declare', 'return'],
        ],
    ])
    ->setFinder($finder)
    ->setParallelConfig(new PhpCsFixer\Runner\Parallel\ParallelConfig(4, 8))
    ->setRiskyAllowed(true)
;
