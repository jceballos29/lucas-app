<?php

declare(strict_types=1);

// Estilo (R8 de docs/AUDITORIA.md): PSR-12 más unas pocas reglas que el código ya sigue. Solo reglas seguras (sin «risky»).
//   bin/estatico estilo           → muestra qué cambiaría (CI falla si hay algo)
//   bin/estatico estilo:arreglar  → lo aplica
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src/app', __DIR__ . '/src/public', __DIR__ . '/src/scripts', __DIR__ . '/src/tests', __DIR__ . '/src/views'])
    ->name('*.php')
    ->exclude(['vendor', 'storage']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_trailing_whitespace' => true,
        'no_extra_blank_lines' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
