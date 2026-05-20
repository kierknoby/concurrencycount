<?php

$framework = \FreePBX::Modules()->getInfo('framework');
$frameworkVersion = $framework['version'] ?? '';

if (!preg_match('/^17\./', $frameworkVersion)) {

    @chdir('/root');

    $moduleDir = realpath(__DIR__);

    if (
        $moduleDir &&
        basename($moduleDir) === 'concurrencycount' &&
        strpos($moduleDir, '/var/www/html/admin/modules/concurrencycount') === 0
    ) {
        exec('rm -rf ' . escapeshellarg($moduleDir));
    }

    throw new \Exception(
        'Concurrency Count requires FreePBX/PBXact 17. Detected framework ' .
        ($frameworkVersion ?: 'unknown') .
        '. Module removed automatically. Installation aborted.'
    );
}

// Concurrency Count install hook. No database changes required.
