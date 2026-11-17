<?php
require __DIR__.'/common.php';
class MBI_DB {
 public $jobs=[],$completed=[],$cleared=[],$events=[];
 function queue($type,$payload,$force=true){$this->jobs[]=(object)['id'=>count($this->jobs)+count($this->completed)+1,'job_type'=>$type,'payload'=>$payload,'attempts'=>0];return 1;}
 function complete($id){$this->completed[]=$id;}
 function log(...$a){$this->events[]=$a;}
 function clear_pending_for_batch($b){$this->cleared[]=$b;return 0;}
 function next_pending_at($b=''){return 0;}
 function counts(){return ['pending'=>0,'processing'=>0,'failed'=>0,'completed'=>0];}
 function abandon_serial_manga_work(...$a){}
}
class MBI_Resource_Wait extends RuntimeException {public $delay;function __construct($m,$d){parent::__construct($m);$this->delay=$d;}}
class MBI_Resource_Guard {static function remaining(){return 30;}static function requests_remaining(){return 30;}static function wait_seconds(){return 0;}static function has_memory(){return true;}}
class MBI_Global_Diagnostic {static $calls=[];static function probe($u,$t){self::$calls[]=$u;return ['ok'=>true,'http'=>200];}}
class MBI_Image_Proxy {static function valid_browser_url($u){return (bool)filter_var($u,FILTER_VALIDATE_URL);}}
function get_posts($args){return [];}
require PLUGIN_ROOT . '/includes/class-mbi-parser.php';
require PLUGIN_ROOT . '/includes/class-mbi-importer.php';
require PLUGIN_ROOT . '/includes/class-mbi-admin.php';
function root51($bad=false){return '<meta name="description" content="This is a sufficiently long description of a manga for integrity checks."><h1 class="manga-title">Example</h1><div class="manga-cover"><img src="https://mangalivre.blog/'.($bad?'no-cover.jpg':'wp-content/uploads/real.jpg').'"></div><span class="manga-chapters-count">2 capítulos</span><ul class="chapters-list"><li><a href="/capitulo/example-1/">Capitulo 1</a></li><li><a href="/capitulo/example-2/">Capitulo 2</a></li></ul>';}
$db=new MBI_DB;$i=new MBI_Importer($db,new MBI_Parser);
function call51($method,...$args){global $i;$result=(new ReflectionMethod($i,$method))->invoke($i,...$args);if($method==='start_serial_batch'&&!empty($result['stream53'])){global $options;unset($result['stream53'],$options['mbi_serial_batch']['stream53']);}return $result;}
$pref=new ReflectionProperty($i,'prefetched_html');$one='https://mangalivre.blog/manga/one/';$two='https://mangalivre.blog/manga/two/';
$s=call51('start_serial_batch',[$one,$two],'catalog',2);
ok(count($s['items'])===2&&$s['phase']==='collect','quantity fixes exact finite selection');
ok($db->jobs[0]->payload['pipeline_phase']==='collect','first job only collects');
$first=array_shift($db->jobs);$pref->setValue($i,[$one=>root51(true)]);call51('run_job',$first);
$s=$options['mbi_serial_batch'];ok($s['captured']===1&&$s['cursor']===1&&$s['phase']==='collect','invalid metadata is still collected before judging');
ok($posts===[]&&MBI_Global_Diagnostic::$calls===[],'capture creates no public posts and probes no images');
$key='mbi_capture51_'.md5('G|0');ok(count($transients[$key]['data']['chapter_urls'])===2,'full chapter inventory retained privately');
ok($db->jobs[0]->payload['url']===$two&&$db->jobs[0]->payload['pipeline_phase']==='collect','second work collected before first validation');
$second=array_shift($db->jobs);$pref->setValue($i,[$two=>root51(true)]);call51('run_job',$second);
$s=$options['mbi_serial_batch'];ok($s['captured']===2&&$s['phase']==='validate'&&$s['cursor']===0,'all captures end before validation starts');
ok($db->jobs[0]->payload['dedupe']!==$first->payload['dedupe'],'capture/validation identities cannot suppress each other');
$sum=['claimed'=>1,'completed'=>0,'retrying'=>0,'failed'=>0,'errors'=>[]];$run=new ReflectionMethod($i,'process_single_regular_job');
while($db->jobs){$j=array_shift($db->jobs);$args=[$j,$i->settings(),&$sum];$run->invokeArgs($i,$args);}
$s=$options['mbi_serial_batch'];ok(!$s['active']&&$s['phase']==='done'&&$s['verified']===2,'all rejected work ends the finite batch');
ok($s['completed']===0&&count(array_filter($s['rows'],fn($r)=>$r['status']==='pending'))===2,'no publication claimed for rejected works');
ok(str_contains($s['rows'][0]['reason'],'capa')&&str_contains($s['rows'][1]['reason'],'capa'),'each work retains its specific rejection reason');
ok(MBI_Global_Diagnostic::$calls===[]&&$posts===[],'invalid metadata does not waste image requests or publish');
ok(empty($options['mbi_catalog_discovery']['active'])&&$db->jobs===[],'completion never starts replacement discovery');
$admin=(new ReflectionClass(MBI_Admin::class))->newInstanceWithoutConstructor();$view=new ReflectionMethod($admin,'pipeline_display51');$v=$view->invoke($admin,$s);
ok($v['bars']===['collect'=>100,'validate'=>100,'publish'=>0]&&$v['pending']===2,'finished work and actual publications have separate progress');
ok($v['rows'][0]['status']==='Pendente de revisão','pending reasons remain visible after completion');
// Completed capture jobs cannot advance the next phase or publish again.
$old=$s;call51('release_serial_after_terminal_failure',$first,'old error');ok($options['mbi_serial_batch']===$old,'late capture failure cannot change a finished batch');
// A failed capture is resolved without an extra validation queue job.
$db->jobs=[];$s=call51('start_serial_batch',[$one,$two],'catalog',5);$job=array_shift($db->jobs);
call51('release_serial_after_terminal_failure',$job,'HTTP 404 source unavailable');$next=array_shift($db->jobs);
ok($next->payload['url']===$two&&$next->payload['pipeline_phase']==='collect','failed root does not prevent remaining collection');
$pref->setValue($i,[$two=>root51()]);call51('run_job',$next);$s=$options['mbi_serial_batch'];
ok($s['phase']==='validate'&&$s['cursor']===1&&$s['verified']===1&&count($db->jobs)===1,'failed capture skipped without a redundant job');
ok($s['target']===5&&count($s['items'])===2,'requested count preserved even when selection is smaller');
$current=$db->jobs[0];$staged=call51('staged_inventory51',$current->payload);
ok(count($staged['chapter_urls'])===2&&count(MBI_Global_Diagnostic::$calls)===1,'validation reuses captured inventory and checks the cover');
ok(MBI_Global_Diagnostic::$calls[0]==='https://mangalivre.blog/wp-content/uploads/real.jpg','cover check targets source, never Worker');
call51('staged_inventory51',$current->payload);ok(count(MBI_Global_Diagnostic::$calls)===1,'cover receipt avoids repeated network work');
$before=$options['mbi_serial_batch'];call51('release_serial_after_terminal_failure',$first,'late capture');ok($options['mbi_serial_batch']===$before,'stale phase cannot move validation cursor');
// The existing final publication callback resolves exactly one selected work.
call51('advance_serial_batch','G',0,true);$s=$options['mbi_serial_batch'];ok(!$s['active']&&$s['completed']===1&&$s['verified']===2,'publication callback ends remaining work without filling missing quota');
$v=$view->invoke($admin,$s);ok($v['bars']['publish']===20&&$v['requested']===5&&$v['total']===2,'publication denominator is requested quota');
// Pause and process reset cannot approve data or advance a collection.
$db->jobs=[];$s=call51('start_serial_batch',[$one],'manual_url',1);$job=$db->jobs[0];$options['mbi_queue_paused']=1;$paused=false;
try{call51('run_job',$job);}catch(MBI_Resource_Wait $e){$paused=true;}ok($paused&&$options['mbi_serial_batch']['capture_done']===0,'pause preserves capture checkpoint');$options['mbi_queue_paused']=0;
$options['mbi_process_generation']='NEW';$blocked=false;try{call51('run_job',$job);}catch(MBI_Skip_Exception $e){$blocked=true;}ok($blocked&&$options['mbi_serial_batch']['capture_done']===0,'generation reset blocks stale collection');$options['mbi_process_generation']='G';
// Existing in-flight batches are not silently converted.
$legacy=['id'=>'OLD','active'=>true,'mode'=>'catalog','target'=>10,'cursor'=>2,'items'=>[]];$options['mbi_serial_batch']=$legacy;
ok(call51('start_serial_batch',[$one],'catalog',1)===$legacy&&$options['mbi_serial_batch']===$legacy,'upgrade retains existing active batch');
$options['mbi_serial_batch']=[];$db->jobs=[];$s=call51('start_serial_batch',[$one],'catalog',1);$job=array_shift($db->jobs);call51('release_serial_after_terminal_failure',$job,'HTTP 404 missing root');$s=$options['mbi_serial_batch'];
ok(!$s['active']&&$s['phase']==='done'&&$s['verified']===1&&$s['captured']===0&&$db->jobs===[],'entirely failed capture closes without validation jobs or replacement search');
ok(str_contains($s['rows'][0]['reason'],'missing root'),'failed capture remains in final report');
$stopped=$s;$stopped['phase']='collect';$stopped['capture_done']=0;$v=$view->invoke($admin,$stopped);ok($v['phase']==='stopped'&&$v['bars']['collect']===0,'cancelled capture is not presented as running or finished');
$stopped['active']=true;$v=$view->invoke($admin,$stopped);ok($v['phase']==='collect','active capture retains its real stage');
echo "PASS $checks finite collection/validation pipeline checks\n";
