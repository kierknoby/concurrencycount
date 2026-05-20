<?php
$framework = \FreePBX::Modules()->getInfo('framework');
$frameworkVersion = $framework['version'] ?? '';

if (!preg_match('/^17\./', $frameworkVersion)) {
    throw new \Exception(
        'Concurrency Count requires FreePBX/PBXact 17. Detected framework ' .
        ($frameworkVersion ?: 'unknown') .
        '. Installation aborted.'
    );
}

// Concurrency Count install hook. No database changes required.
