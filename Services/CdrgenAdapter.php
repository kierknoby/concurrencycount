<?php

namespace FreePBX\modules\Concurrencycount\Services;

require_once __DIR__ . '/../lib/cdrgen/src/autoload.php';

/** The sole Concurrency Count boundary to the pinned, unmodified CDRgen generation core. */
final class CdrgenAdapter {
	const EXPECTED_CORE_VERSION = '1.1.0';
	const IMPORT_REVISION = 'e8f45d82163b081196efb82219751ce66b65cca4';
	const UPSTREAM_REPOSITORY = 'https://github.com/kierknoby/cdrgen';

	public static function identityFromSeed(int $seed): array {
		if ($seed === 0) throw new \InvalidArgumentException('A non-zero Demo seed is required for deterministic identity derivation.');
		return ['token'=>substr(hash('sha256','Concurrency Count Demo seed:'.$seed),0,32), 'generation'=>1];
	}

	public function generate(array $scenario): array {
		if (\CdrGen\Version::VERSION !== self::EXPECTED_CORE_VERSION) throw new \RuntimeException('The bundled CDRgen core is incompatible with this Concurrency Count release.');
		$identity=(string)($scenario['identity']??'');
		$random=new \CdrGen\Random\HashStreamRandomSource($identity);
		$profilingRandom=$random->fork('trunk-profiling-v1');
		$profiler=new \CdrGen\TrunkProfiler();
		$trunks=[];
		foreach(array_values((array)($scenario['trunks']??[])) as $index=>$trunk){
			if(is_string($trunk))$trunk=['channel'=>$trunk,'name'=>$trunk];
			$channel=(string)($trunk['channel']??$trunk['channelid']??'');
			$dids=(array)($trunk['dids']??[$this->didFor($index,0),$this->didFor($index,1)]);
			// Keep varied domestic, international and service-number destination prefixes.
			$prefixes=(array)($trunk['prefixes']??['0207','4420','2125','1212','9005']);
			$trunks[]=$profiler->profile($channel,$dids,$prefixes,$index,(string)($trunk['name']??$channel),$profilingRandom);
		}
		$profile=\CdrGen\TrafficProfile::named((string)($scenario['profile']??'light'));
		$request=new \CdrGen\GenerationRequest($profile,strtotime((string)($scenario['start']??'')),strtotime((string)($scenario['end']??'')),$random,(array)($scenario['extensions']??[]),$trunks,[
			'rows'=>(int)($scenario['rows']??$profile->rows()), 'extension_names'=>(array)($scenario['extension_names']??[]),
			'accountcode'=>(string)($scenario['accountcode']??''), 'timezone'=>date_default_timezone_get(), 'technologies'=>['PJSIP'],
		]);
		$progress=isset($scenario['progress'])&&is_callable($scenario['progress'])?$scenario['progress']:null;
		$generated=(new \CdrGen\Generator())->generate($request,$progress,500);
		$rows=$generated->rows();
		$metadata=['core_version'=>$generated->version(),'dataset_identity'=>$generated->datasetIdentity(),'statistics'=>$generated->statistics(),'generation'=>$generated->metadata(),'import_revision'=>self::IMPORT_REVISION];
		unset($generated);
		foreach($rows as &$row){
			$extensions=array_values(array_map('strval',(array)($row['_extensions']??[])));
			$row['_handled_extension']=($row['_direction']??'')==='internal'&&isset($extensions[1])?$extensions[1]:($extensions[0]??'');
			$row['_flow']=($row['_direction']??'')==='inbound'?(string)($row['_inbound_type']??'inbound'):(string)($row['_direction']??'');
			unset($row['_start_ts'],$row['_answer_ts'],$row['_end_ts'],$row['_extension'],$row['_inbound_type']);
		}
		unset($row);
		return ['rows'=>$rows,'metadata'=>$metadata];
	}

	public function provenance(): array {
		return ['name'=>'CDRgen','version'=>\CdrGen\Version::VERSION,'source_revision'=>self::IMPORT_REVISION,'source_repository'=>self::UPSTREAM_REPOSITORY];
	}

	private function didFor(int $index,int $offset): string { $areas=['212','646','718','800','888','415','617','202','303','310']; return $areas[$index%count($areas)].'555'.sprintf('%04d',(100+$index*7+$offset)%10000); }
}
