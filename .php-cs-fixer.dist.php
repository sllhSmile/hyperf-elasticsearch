<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/benchmarks']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules(['@PER-CS2.0' => true, 'array_syntax' => ['syntax' => 'short']])
    ->setFinder($finder);
