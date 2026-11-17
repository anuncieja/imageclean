<?php
require __DIR__.'/common.php';
class MBI_DB {public $due=0;function next_pending_at($b=''){return $this->due;}function log(...$a){}}
class MBI_Parser{}class MBI_Resource_Guard{static $lane='';static $wait=0;static $begins=0;static function status(){return ['lane'=>self::$lane];}static function wait_seconds(){return self::$wait;}static function begin(...$a){self::$begins++;return false;}}
function admin_url($s){return 'https://example.test/wp-admin/'.$s;}function apply_filters($f,$v){return $v;}function wp_unslash($s){return $s;}
$requests=[];function wp_remote_post($u,$args){global $requests;$requests[]=[$u,$args];return [];}
class HttpDone extends Exception{public $status;function __construct($s){$this->status=$s;}}function status_header($s){throw new HttpDone($s);}
require PLUGIN_ROOT . '/includes/class-mbi-importer.php';
$db=new MBI_DB;$i=new MBI_Importer($db,new MBI_Parser);$options['mbi_worker_token']='T';
$options['mbi_publication_not_before']=time()+2;$i->kick_worker(true);ok(count($requests)===1&&$requests[0][1]['body']['short_resume']===1,'short checkpoint dispatches authenticated continuation');ok($requests[0][1]['blocking']===false,'continuation does not block originating worker response');
$i->kick_worker(true);ok(count($requests)===1,'dispatch latch suppresses duplicate continuation');
delete_transient('mbi_worker_dispatching');$requests=[];$options['mbi_publication_not_before']=time()+30;$i->kick_worker(true);ok($requests===[],'network cooldown of 30 seconds never fast-dispatched');
$options['mbi_publication_not_before']=time()+2;$i->kick_worker();ok($requests===[],'ordinary callers cannot bypass a delay');
$options['mbi_publication_not_before']=0;MBI_Resource_Guard::$lane='publicação';$i->kick_worker(true);ok($requests===[],'active work lane blocks duplicate dispatch');MBI_Resource_Guard::$lane='';
$options['mbi_queue_paused']=1;$i->kick_worker(true);ok($requests===[],'paused queue dispatches nothing');$options['mbi_queue_paused']=0;
MBI_Resource_Guard::$wait=120;$i->kick_worker(true);ok($requests===[],'shared pressure remains respected');MBI_Resource_Guard::$wait=0;
$_POST=['token'=>'wrong','short_resume'=>1];$start=microtime(true);try{$i->worker_tick();}catch(HttpDone $e){ok($e->status===403,'token rejected');}ok(microtime(true)-$start<1&&MBI_Resource_Guard::$begins===0,'unauthenticated requests never wait or start work');
$options['mbi_publication_not_before']=time()+2;$_POST=['token'=>'T','short_resume'=>1];$start=microtime(true);try{$i->worker_tick();}catch(HttpDone $e){ok($e->status===204,'authenticated continuation completes');}ok(microtime(true)-$start>=1.5&&microtime(true)-$start<3.5&&MBI_Resource_Guard::$begins===1,'short gap expires before attempting resource acquisition');ok(!get_transient('mbi_worker_dispatching'),'authenticated worker releases dispatch latch for next round');
require PLUGIN_ROOT . '/includes/class-mbi-admin.php';$admin=(new ReflectionClass(MBI_Admin::class))->newInstanceWithoutConstructor();$m=new ReflectionMethod($admin,'order_display');
$s=$m->invoke($admin,['id'=>'B','active'=>true,'target'=>1,'completed'=>0,'skipped'=>18]);ok($s['text']==='0/1 concluídos'&&$s['skipped']===18,'one-order request never displays 935 as denominator');
$s=$m->invoke($admin,['id'=>'B','active'=>false,'target'=>10,'completed'=>4,'skipped'=>20]);ok($s['label']==='Último pedido de mangás'&&$s['text']==='4/10 concluídos','finished order retains its own target');
$s=$m->invoke($admin,[]);ok($s['text']==='Nenhum pedido'&&$s['skipped']===0,'idle screen never creates a fake catalog-sized request');
echo "PASS $checks continuation/display checks\n";
