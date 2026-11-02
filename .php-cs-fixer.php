<?php

/**
 * PHP-CS-Fixer config for the mcpserverlite GLPI plugin.
 *
 * Rules copied verbatim from GLPI core's own .php-cs-fixer.dist.php
 * (verified against the 11.0/bugfixes branch, 2026-09) so this plugin is
 * checked against the exact same style GLPI itself currently enforces;
 * this is what glpi-project/plugin-ci-workflows falls back to running
 * against a plugin that doesn't ship a `.php-cs-fixer.php` of its own, so
 * shipping our own copy just makes that check runnable locally too.
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude([
        '.git/',
        'vendor/',
        'node_modules/',
    ])
;

return (new PhpCsFixer\Config())
    ->setUnsupportedPhpVersionAllowed(true) // allow upcoming PHP versions
    ->setRules([
        '@PER-CS3x0' => true,
        '@PHP8x4Migration' => true,
        'fully_qualified_strict_types' => ['import_symbols' => true],
        'ordered_imports' => ['imports_order' => ['class', 'const', 'function']],
        'no_unused_imports' => true,
        'heredoc_indentation' => false,
        'phpdoc_scalar' => true,
        'phpdoc_types' => true,
        'new_expression_parentheses' => false,
    ])
    ->setFinder($finder)
;
