<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('vendor')
    ->notPath([
        'config/bundles.php',
        // reference.php is gitignored but regenerated locally by Symfony Flex - keep it out of the lint scope
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    // Only so the ONE risky rule below can run. No risky *set* is enabled: `@Symfony:risky`
    // stays off, and `declare_strict_types` stays a documented convention rather than a fixer
    // (story 33.2's finding). Adding a risky rule here is a deliberate, per-rule decision.
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,

        // PHPUnit assertions are static methods. Calling them on $this is a dynamic call to a
        // static method - phpstan-strict-rules' `staticMethod.dynamicCall` flags every one
        // (304 of them when the rules were switched on in story 33.14). Fixing them by hand
        // would be a one-off; this fixer makes `self::assert*()` the enforced convention, so
        // the finding cannot come back. "Risky" only because a subclass could redeclare an
        // assertion as an instance method - nothing here does.
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
    ])
    ->setFinder($finder)
;
