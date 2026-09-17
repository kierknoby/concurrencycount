<?php
if (!interface_exists('BMO')) { interface BMO {} }
if (!function_exists('_')) { function _($value) { return $value; } }
require_once dirname(__DIR__) . '/Concurrencycount.class.php';
use FreePBX\modules\Concurrencycount\Services\HistoricalCallExclusionService;
use FreePBX\modules\Concurrencycount\Services\HistoricalResultFloor;
use FreePBX\modules\Concurrencycount\Services\PjsipIdentityService;
class TrunkPeakEvidenceConcurrencycount extends \FreePBX\modules\Concurrencycount {
	public $sourceFetches=0;
	public $detailWindows=[];
	public $sourceRows=[];
	public $detailRows=[];
	public function __construct() {}
	public function details(string $trunk,string $start,string $end,string $from,string $to,array $source,array $detail,PjsipIdentityService $identity,array $exclusions):array{return $this->buildPeakDetailsFromRows($trunk,$start,$end,$from,$to,$source,$detail,$identity,$exclusions);}
	public function exportEvidence(array $results,array $source,array $detail,PjsipIdentityService $identity):array{
		$this->sourceRows=$source;$this->detailRows=$detail;
		foreach(['pjsipIdentityService'=>$identity,'historicalCallExclusions'=>[]] as $name=>$value){$property=new ReflectionProperty(\FreePBX\modules\Concurrencycount::class,$name);$property->setAccessible(true);$property->setValue($this,$value);}
		return $this->attachTrunkPeakEvidence($results);
	}
	protected function fetchPjsipCdrRows(string $start,string $end,string $accountcode=''):array{$this->sourceFetches++;return $this->sourceRows;}
	protected function fetchTrunkDetailRows(string $trunk,string $start,string $end,string $from,string $to):array{$this->detailWindows[]=[$from,$to];return $this->detailRows;}
	protected function formatPeakCall(array $leg,string $trunk):array{$row=$leg['cdr'];return ['calldate'=>$row['calldate'],'caller_id'=>$row['clid']??'','source'=>$row['src']??'','destination'=>$row['dst']??'','trunk'=>$trunk,'trunk_channel'=>$leg['chan'],'direction'=>$leg['direction'],'duration'=>(int)$row['duration'],'linkedid'=>$row['linkedid']??'','uniqueid'=>$row['uniqueid']??'','call_identity'=>(new HistoricalCallExclusionService())->identityForRow($row)];}
}
function evidence_assert($condition,string $message):void{if(!$condition)throw new Exception($message);}
function evidence_row(string $at,int $duration,string $trunk,string $linkedId,string $accountcode=''):array{return ['calldate'=>$at,'duration'=>$duration,'disposition'=>'ANSWERED','channel'=>'PJSIP/101-a1b2c3','dstchannel'=>'PJSIP/'.$trunk.'-d4e5f6','clid'=>'Alice <101>','src'=>'101','dst'=>'5551000','linkedid'=>$linkedId,'uniqueid'=>'unique-'.$linkedId,'accountcode'=>$accountcode];}
$start='2026-09-01 00:00:00';$end='2026-09-16 23:59:59';$from='2026-09-10 10:00:10';$to='2026-09-10 10:01:00';
$validOne=evidence_row('2026-09-10 10:00:00',60,'carrier-a','valid-1');$validTwo=evidence_row('2026-09-10 10:00:10',50,'carrier-a','valid-2');$demo=evidence_row('2026-09-10 10:00:10',50,'carrier-a','demo','CCDEMOdeadbeef');$zero=evidence_row('2026-09-10 10:00:10',0,'carrier-a','zero');$excluded=evidence_row('2026-09-10 10:00:10',50,'carrier-a','excluded');$wrongTrunk=evidence_row('2026-09-10 10:00:20',10,'carrier-b','wrong-trunk');$outside=evidence_row('2026-08-31 23:59:59',60,'carrier-a','outside');
$identity=new PjsipIdentityService(['carrier-a'=>['channelid'=>'carrier-a'],'carrier-b'=>['channelid'=>'carrier-b']],['101'=>['id'=>'101']],[]);$exclusionService=new HistoricalCallExclusionService();$exclusions=$exclusionService->exclude([],'linkedid:excluded',['src'=>'101'],1);$rows=[$validOne,$validTwo,$demo,$zero,$excluded,$wrongTrunk,$outside];
$module=new TrunkPeakEvidenceConcurrencycount();$detail=$module->details('carrier-a',$start,$end,$from,$to,$rows,$rows,$identity,$exclusions);
evidence_assert($detail['peak']===2&&$detail['from']===$from&&$detail['to']===$to,'Real peak analysis selects the exact qualifying occurrence for the calculated trunk');
evidence_assert(count($detail['calls'])===2,'Only the two eligible, in-range, non-excluded carrier-a calls contribute evidence');$callIds=array_column($detail['calls'],'call_identity');sort($callIds);
evidence_assert($callIds===['linkedid:valid-1','linkedid:valid-2'],'Global exclusion, other classifications, range and zero duration are excluded from evidence');
evidence_assert(!in_array('linkedid:demo',$callIds,true),'Reserved Demo rows cannot contaminate Historical GUI or export evidence');
foreach($detail['calls'] as $call)evidence_assert($call['trunk']==='carrier-a'&&$call['duration']>0&&$call['source']==='101'&&$call['destination']==='5551000'&&$call['linkedid']!==''&&$call['direction']==='outbound','Evidence retains identity, caller/source, destination, trunk, duration, linked ID, timestamp and direction');
evidence_assert(!in_array('linkedid:zero',$callIds,true),'A zero-duration call cannot appear in Trunk contributing-call evidence');
$floor=new HistoricalResultFloor();$calculated=['mode'=>'trunk','per_name'=>['carrier-a'=>2],'global_max'=>2,'peak_occurrences'=>['carrier-a'=>[['from'=>$from,'to'=>$to,'peak'=>2]]]];
evidence_assert(empty($floor->apply($calculated,3)['peak_occurrences']),'Minimum concurrency above the exact result removes its peak occurrence and therefore evidence input');evidence_assert(isset($floor->apply($calculated,2)['peak_occurrences']['carrier-a']),'Minimum concurrency equal to the exact result preserves its peak occurrence and evidence input');
$rejected=false;try{$module->details('carrier-b',$start,$end,$from,$to,$rows,$rows,$identity,$exclusions);}catch(InvalidArgumentException $expected){$rejected=true;}evidence_assert($rejected,'A requested trunk without that exact qualifying occurrence cannot borrow another trunk peak');
$separated=[evidence_row('2026-09-02 11:00:00',10,'carrier-a','separated-1'),evidence_row('2026-09-02 11:00:05',2,'carrier-a','separated-2'),evidence_row('2026-09-15 11:00:00',10,'carrier-a','separated-3'),evidence_row('2026-09-15 11:00:05',2,'carrier-a','separated-4'),evidence_row('2026-09-15 11:00:05',5,'carrier-a','separated-demo','CCDEMO1234abcd')];
$occurrences=[['from'=>'2026-09-02 11:00:05','to'=>'2026-09-02 11:00:07','peak'=>2],['from'=>'2026-09-15 11:00:05','to'=>'2026-09-15 11:00:07','peak'=>2]];
$export=$module->exportEvidence(['mode'=>'trunk','start'=>$start,'end'=>$end,'peak_occurrences'=>['carrier-a'=>$occurrences]],$separated,$separated,$identity);
evidence_assert($module->sourceFetches===1,'Export acquires and classifies the complete source report exactly once');
evidence_assert($module->detailWindows===[[$occurrences[0]['from'],$occurrences[0]['to']],[$occurrences[1]['from'],$occurrences[1]['to']]],'Distant peaks use exact occurrence detail windows rather than one giant intervening-range query');
evidence_assert(count($export['peak_evidence']['carrier-a'])===2,'Every separated peak occurrence remains present in export evidence');
$firstIds=array_column($export['peak_evidence']['carrier-a'][0]['calls'],'call_identity');sort($firstIds);$secondIds=array_column($export['peak_evidence']['carrier-a'][1]['calls'],'call_identity');sort($secondIds);
evidence_assert($firstIds===['linkedid:separated-1','linkedid:separated-2']&&$secondIds===['linkedid:separated-3','linkedid:separated-4'],'Each distant occurrence retains its exact contributing logical calls');
evidence_assert(!in_array('linkedid:separated-demo',array_merge($firstIds,$secondIds),true),'CSV/email evidence excludes overlapping reserved Demo rows');
echo "Trunk peak evidence tests passed\n";
