<?php
require __DIR__.'/common.php';
class WP_Error{function __construct(public $message){}function get_error_message(){return $this->message;}}
function wp_remote_retrieve_response_code($r){return $r['status']??0;}function wp_remote_retrieve_header($r,$h){return $r[$h]??'';}
function maybe_unserialize($v){return unserialize($v);}function maybe_serialize($v){return serialize($v);}
class GuardWpdb{public $options='wp_options';public $prefix='wp_';public $queries=[];function prepare($s,...$a){return json_encode([$s,$a]);}function get_var($q){[$sql,$a]=json_decode($q,true);global $options;return isset($options[$a[0]])?serialize($options[$a[0]]):null;}}
$wpdb=new GuardWpdb;
require PLUGIN_ROOT . '/includes/class-mbi-resource-guard.php';
function prop($name,$value){(new ReflectionProperty(MBI_Resource_Guard::class,$name))->setValue(null,$value);}
prop('lease',['token'=>'A','lane'=>'publicação','until'=>time()+90]);prop('budget',20);prop('deadline',microtime(true)+19);
$args=['timeout'=>15,'headers'=>['Accept'=>'text/html,application/xhtml+xml']];$out=MBI_Resource_Guard::http_args($args,'https://source.test/manga/a/');ok($out['timeout']===15.0,'fresh HTML gets full fifteen seconds rather than clipped ten');
prop('deadline',microtime(true)+8);$yield=false;try{MBI_Resource_Guard::http_args($args,'https://source.test/capitulo/a/');}catch(MBI_Resource_Wait $e){$yield=$e->delay===3;}ok($yield,'leftover eight seconds yields before opening HTML connection');
prop('deadline',microtime(true)+19);$out=MBI_Resource_Guard::http_args(['timeout'=>6,'headers'=>['Accept'=>'image/*']],'https://images.test/a');ok($out['timeout']===6.0,'image request keeps its explicit bounded timeout');
prop('budget',8);prop('deadline',microtime(true)+7.5);$out=MBI_Resource_Guard::http_args($args,'https://source.test/a');ok($out['timeout']===5.0,'low PHP budget adapts without impossible fifteen-second reservation');
prop('budget',20);prop('deadline',microtime(true)+19);
$url='https://source.test/capitulo/1/';$err=new WP_Error('cURL error 28: Operation timed out after 15000 milliseconds with 0 bytes received');
MBI_Resource_Guard::http_result($err,'response','fake',[],$url);ok(!isset($options['mbi_resource_pressure']),'first timeout does not freeze all jobs');
MBI_Resource_Guard::http_result($err,'response','fake',[],$url);ok(!isset($options['mbi_resource_pressure']),'second consecutive timeout remains job-local');
MBI_Resource_Guard::http_result($err,'response','fake',[],$url);ok($options['mbi_resource_pressure']['until']>=time()+29,'third consecutive timeout activates protection');
unset($options['mbi_resource_pressure']);MBI_Resource_Guard::http_result(['status'=>200],'response','fake',[],$url);MBI_Resource_Guard::http_result($err,'response','fake',[],$url);ok(!isset($options['mbi_resource_pressure']),'successful response resets timeout streak');
MBI_Resource_Guard::http_result(['status'=>429,'retry-after'=>'180'],'response','fake',[],$url);ok($options['mbi_resource_pressure']['until']>=time()+179,'HTTP 429 Retry-After remains immediate');
$before=$options['mbi_resource_pressure'];MBI_Resource_Guard::http_result($err,'response','fake',[],'https://other.test/a');ok($options['mbi_resource_pressure']===$before,'isolated timeout cannot clear existing pressure');
unset($options['mbi_resource_pressure']);MBI_Resource_Guard::http_result(new WP_Error('cURL error 6: Could not resolve host'),'response','fake',[],$url);ok($options['mbi_resource_pressure']['until']>=time()+119,'DNS protection remains immediate');
unset($options['mbi_resource_pressure']);MBI_Resource_Guard::http_result(['status'=>503],'response','fake',[],$url);ok($options['mbi_resource_pressure']['until']>=time()+29,'HTTP 503 retains shared backoff');
prop('lease',[]); // No shutdown unlock required for this isolated simulation.
require PLUGIN_ROOT . '/includes/class-mbi-db.php';
require PLUGIN_ROOT . '/includes/class-mbi-parser.php';
require PLUGIN_ROOT . '/includes/class-mbi-importer.php';
$i=new MBI_Importer(new MBI_DB,new MBI_Parser);$open=new ReflectionMethod($i,'open_source_circuit');$clear=new ReflectionMethod($i,'clear_source_circuit');$remaining=new ReflectionMethod($i,'source_circuit_remaining');
$transients=[];unset($options['mbi_resource_pressure']);
ok($open->invoke($i,$url,$err->message)===0&&$remaining->invoke($i,$url)===0,'first source timeout does not open host circuit');
ok($open->invoke($i,$url,$err->message)===0,'second source timeout stays local');
ok($open->invoke($i,$url,$err->message)>=30&&$remaining->invoke($i,$url)>=29,'third source timeout opens bounded circuit');
$transients=[];$open->invoke($i,$url,$err->message);$clear->invoke($i,$url);ok($open->invoke($i,$url,$err->message)===0,'successful source clears timeout streak');
$transients=[];ok($open->invoke($i,$url,'HTTP 429',180)>=180,'source circuit honors explicit rate limit');
ok($open->invoke($i,$url,$err->message)>=180,'timeout never bypasses a live 429 circuit');
// Zero source/Worker HTTP is opened in these transport-policy tests.
echo "PASS $checks transport policy checks\n";
define('MB_IN_BYTES',1048576);define('MBI_VERSION','1.7.49');function home_url($p=''){return 'https://example.test'.$p;}
prop('lease',['token'=>'A','lane'=>'detecção','until'=>time()+90]);prop('deadline',microtime(true)+19);
$out=MBI_Resource_Guard::http_args($args,'https://mangalivre.blog/manga/a/');ok($out['timeout']===15.0,'new-chapter detection HTML also gets fifteen seconds instead of six');
prop('deadline',microtime(true)+8);$http_callback=function($u,$a){MBI_Resource_Guard::http_args($a,$u);throw new Exception('Unexpected transport opened');};
$transients=[];unset($options['mbi_resource_pressure']);$fetch=new ReflectionMethod($i,'parallel_fetch_update_htmls');$r=$fetch->invoke($i,[0=>['manga_url'=>'https://mangalivre.blog/manga/a/']]);ok(($r[0]['wait']??0)===3,'detection preserves budget wait as structured wait, not parse failure');
$options['mbi_update_scan']=['active'=>true,'id'=>'S','phase'=>'catalog','sources'=>[['base'=>'https://mangalivre.blog','page'=>1]],'page_limit'=>1,'catalog_checked'=>0];
$scan=new ReflectionMethod($i,'process_update_scan_tick_bounded');$scan->invoke($i);$s=$options['mbi_update_scan'];ok($s['sources'][0]['page']===1&&($s['source_failures']??0)===0,'catalog budget yield does not skip first page or increment failures');ok(($s['budget_wait_until']??0)>=time()+2&&!isset($transients['mbi_update_scan_lock']),'detection checkpoint releases its lock and shows a short interval');
$state=['active'=>true,'id'=>'S','phase'=>'mangas','items'=>[['status'=>'processing'],['status'=>'pending']],'checked'=>0,'total'=>2];$defer=new ReflectionMethod($i,'defer_update_scan_budget');$r=$defer->invoke($i,$state,3);ok($options['mbi_update_scan']['items'][0]['status']==='pending'&&$options['mbi_update_scan']['checked']===0,'manga detection yield retains pending work and checked count');ok($r['status']==='INTERVALO ENTRE RODADAS','normal detection interval is not presented as source outage');
prop('lease',[]);echo "PASS $checks transport/detection checks\n";
prop('lease',['token'=>'A','lane'=>'publicação','until'=>time()+90]);prop('proxy_host51','img.lermangaonline.com.br');prop('proxy_blocked51',0);
$blocked=MBI_Resource_Guard::before_http(false,[],'https://img.lermangaonline.com.br/i/TOKEN');ok($blocked instanceof WP_Error,'publication Worker call blocked before HTTP');
ok(MBI_Resource_Guard::network_usage51()['worker_blocked']===1,'blocked call recorded without transport');
$prior=['cached'=>'response'];ok(MBI_Resource_Guard::before_http($prior,[],'https://img.lermangaonline.com.br/i/TOKEN')===$prior,'existing short-circuit does not create new request');
prop('lease',[]);ok(MBI_Resource_Guard::before_http(false,[],'https://img.lermangaonline.com.br/i/TOKEN')===false,'reader/unrelated requests outside work lane untouched');
echo "PASS $checks transport/detection/Worker boundary checks\n";

foreach (['reparo de imagens','reparo de capas','checagem de imagens','detecção','preparação'] as $lane) {prop('lease',['token'=>'A','lane'=>$lane,'until'=>time()+90]);ok(MBI_Resource_Guard::before_http(false,[],'https://img.lermangaonline.com.br/i/TOKEN') instanceof WP_Error,'maintenance lane never opens public Worker HTTP');}prop('lease',[]);echo "PASS $checks all import/maintenance Worker boundaries\n";
