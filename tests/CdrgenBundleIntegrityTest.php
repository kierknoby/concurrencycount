<?php
$root=__DIR__.'/../lib/cdrgen';$manifest=file($root.'/SHA256SUMS',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if($manifest===false)throw new Exception('Missing CDRgen bundle manifest');
$listed=[];foreach($manifest as $line){if(!preg_match('/^([a-f0-9]{64})  (.+)$/',$line,$m))throw new Exception('Invalid CDRgen manifest line');$listed[]=$m[2];if(!is_file($root.'/'.$m[2])||hash_file('sha256',$root.'/'.$m[2])!==$m[1])throw new Exception('Bundled upstream CDRgen file differs: '.$m[2]);}
$actual=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src',FilesystemIterator::SKIP_DOTS));foreach($it as $file)if($file->isFile())$actual[]=substr($file->getPathname(),strlen($root)+1);$actual[]='LICENSE';sort($actual);sort($listed);if($actual!==$listed)throw new Exception('Bundled CDRgen dependency closure differs from manifest');
require_once $root.'/src/autoload.php';if(CdrGen\Version::VERSION!=='1.1.0')throw new Exception('Unexpected bundled CDRgen version');
echo "CDRgen bundle integrity tests passed\n";
