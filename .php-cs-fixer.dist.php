<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/public', __DIR__ . '/tests'])
    ->append([
        __DIR__ . '/openemr.bootstrap.php',
        __DIR__ . '/ModuleManagerListener.php',
        __DIR__ . '/moduleConfig.php',
        __DIR__ . '/version.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'parameters']],
    ])
    ->setFinder($finder);
