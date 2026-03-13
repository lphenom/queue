<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                       => true,
        'declare_strict_types'         => true,
        'no_unused_imports'            => true,
        'ordered_imports'              => ['sort_algorithm' => 'alpha'],
        'array_syntax'                 => ['syntax' => 'short'],
        'trailing_comma_in_multiline'  => ['elements' => ['arrays']],
        'no_trailing_whitespace'       => true,
        'single_quote'                 => true,
        'blank_line_after_namespace'   => true,
    ])
    ->setFinder($finder);

