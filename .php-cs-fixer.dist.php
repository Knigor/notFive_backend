<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->ignoreDotFiles(false)
    ->exclude(['var', 'vendor'])
    ->notPath('config/reference.php')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder)
;
