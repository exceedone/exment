<?php
declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()->in([
    'src/',
    'tests/',
]);
$config = new PhpCsFixer\Config();

return $config
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer:risky' => false,
        'blank_line_after_opening_tag' => false,
        'linebreak_after_opening_tag' => false,
        'declare_strict_types' => false,
        'phpdoc_types_order' => [
            'null_adjustment' => 'none',
            'sort_algorithm' => 'none',
        ],
        'no_superfluous_phpdoc_tags' => false,
        'php_unit_test_case_static_method_calls' => [
            'call_type' => 'this'
        ],
        'not_operator_with_successor_space' => false,
        'blank_line_after_namespace' => true,
    ])
    ->setFinder($finder);
