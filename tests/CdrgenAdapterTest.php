<?php
require_once __DIR__ . '/../Services/CdrgenAdapter.php';
use FreePBX\modules\Concurrencycount\Services\CdrgenAdapter;
function cdrgen_assert($condition,string $message):void{if(!$condition)throw new Exception($message);}
$adapter=new CdrgenAdapter();$identity='00112233445566778899aabbccddeeff:1';
$scenario=['profile'=>'light','rows'=>1000,'start'=>'2026-01-01 00:00:00','end'=>'2026-01-02 00:00:00','identity'=>$identity,'accountcode'=>'CCDEMO1234abcd','extensions'=>['7301','8402','9503'],'extension_names'=>['7301'=>'Alice'],'trunks'=>[['channel'=>'PJSIP/primary-main','name'=>'Primary Main'],['channel'=>'PJSIP/backup-failover','name'=>'Backup Failover']]];
$progress=[];$progressScenario=$scenario;$progressScenario['progress']=function($complete,$total)use(&$progress){$progress[]=[$complete,$total];};$adapter->generate($progressScenario);cdrgen_assert(end($progress)===[1000,1000],'Adapter must expose upstream generation checkpoints for CC cancellation');unset($progressScenario,$progress);
$first=$adapter->generate($scenario);$firstHash=hash('sha256',serialize($first));unset($first);$second=(new CdrgenAdapter())->generate($scenario);
cdrgen_assert($firstHash===hash('sha256',serialize($second)),'The same complete CDRgen scenario must reproduce byte-for-byte');
$profileChanged=$scenario;$profileChanged['profile']='medium';$profileChanged['rows']=1000;$profileResult=$adapter->generate($profileChanged);cdrgen_assert($profileResult['metadata']['dataset_identity']!==$second['metadata']['dataset_identity'],'Authoritative upstream dataset identity must change when profile changes with the same token/generation');unset($profileResult);
$different=$scenario;$different['identity']='00112233445566778899aabbccddeeff:2';$changed=$adapter->generate($different);cdrgen_assert($firstHash!==hash('sha256',serialize($changed)),'Changing generation must change traffic');unset($changed);
$different['identity']='10112233445566778899aabbccddeeff:1';$changed=$adapter->generate($different);cdrgen_assert($firstHash!==hash('sha256',serialize($changed)),'Changing the full token must change traffic');unset($changed);
cdrgen_assert(count($second['rows'])===1000,'Light must generate 1,000 rows');
cdrgen_assert(min(array_map('strtotime',array_column($second['rows'],'calldate')))>=strtotime($scenario['start'])&&max(array_map('strtotime',array_column($second['rows'],'calldate')))<strtotime($scenario['end']),'CDRgen one-day generation must use start-inclusive/end-exclusive timestamps');
cdrgen_assert($second['metadata']['core_version']===CdrgenAdapter::EXPECTED_CORE_VERSION&&$second['metadata']['import_revision']===CdrgenAdapter::IMPORT_REVISION,'Adapter must expose and enforce pinned provenance');
$directions=array_unique(array_column($second['rows'],'_direction'));$dispositions=array_unique(array_column($second['rows'],'disposition'));foreach(['inbound','outbound','internal'] as $v)cdrgen_assert(in_array($v,$directions,true),'Missing direction '.$v);foreach(['ANSWERED','NO ANSWER','BUSY','FAILED'] as $v)cdrgen_assert(in_array($v,$dispositions,true),'Missing disposition '.$v);
foreach($second['rows'] as $row)cdrgen_assert(strpos($row['channel'],'SIP/')!==0&&strpos($row['dstchannel'],'SIP/')!==0,'CC must constrain CDRgen to PJSIP');
$eligible=false;$excluded=false;foreach($second['rows'] as $row)if($row['_direction']==='outbound'){if(preg_match('/^[19]/',$row['dst']))$excluded=true;else$eligible=true;}cdrgen_assert($eligible&&$excluded,'CC trunk prefix adaptation must exercise eligible and excluded Extension destinations');
$seed=CdrgenAdapter::identityFromSeed(12345);cdrgen_assert($seed===CdrgenAdapter::identityFromSeed(12345)&&preg_match('/^[a-f0-9]{32}$/',$seed['token']),'Legacy CLI seed identity must be deterministic');
unset($second);foreach([['light',1000],['medium',5000],['heavy',20000]] as $profile){$s=$scenario;$s['profile']=$profile[0];unset($s['rows']);$result=$adapter->generate($s);cdrgen_assert(count($result['rows'])===$profile[1],$profile[0].' profile count changed');unset($result);}
cdrgen_assert(!file_exists(__DIR__.'/../Services/DemoCdrGenerator.php'),'Duplicate Demo generator must not remain');
foreach(glob(__DIR__.'/../Services/*.php') as $source)if(basename($source)!=='CdrgenAdapter.php')cdrgen_assert(strpos(file_get_contents($source),'CdrGen\\')===false,'Only adapter may know upstream CDRgen classes');
echo "CDRgen adapter tests passed\n";
