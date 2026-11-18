<?php
require __DIR__.'/pipeline.php';
function reset52(){global $options,$transients,$db,$i,$pref;$options=['mbi_process_generation'=>'G','mbi_generation_enforced'=>1,'mbi_settings'=>['continuous_worker'=>0],'mbi_catalog_discovery'=>['page'=>1,'last_page_scanned'=>1,'page_limit'=>1,'seen'=>[]]];$transients=[];$db=new MBI_DB;$i=new MBI_Importer($db,new MBI_Parser);$pref=new ReflectionProperty($i,'prefetched_html');MBI_Global_Diagnostic::$calls=[];}
function url52($n){return 'https://mangalivre.blog/manga/quota-'.$n.'/';}
function collect52($bad=false){global $db,$pref,$i;$job=array_shift($db->jobs);ok($job&&$job->payload['pipeline_phase']==='collect','next queued job collects exactly one candidate');$pref->setValue($i,[$job->payload['url']=>root51($bad)]);call51('run_job',$job);}
function reject52(){global $db,$i;$job=array_shift($db->jobs);$sum=['claimed'=>1,'completed'=>0,'retrying'=>0,'failed'=>0,'errors'=>[]];$args=[$job,$i->settings(),&$sum];(new ReflectionMethod($i,'process_single_regular_job'))->invokeArgs($i,$args);}
reset52();$urls=[];for($n=1;$n<=10;$n++)$urls[]=url52($n);$s=call51('start_serial_batch',$urls,'catalog',5);
ok(count($s['items'])===5&&count($s['candidate_backlog'])===5,'first selection keeps remaining same-page candidates');
for($n=0;$n<5;$n++)collect52(true);
for($n=0;$n<5;$n++)reject52();$s=$options['mbi_serial_batch'];
ok($s['active']&&$s['phase']==='collect'&&$s['cursor']===5&&$s['completed']===0,'five blocked candidates create replacement wave, not completed request');
ok(count($s['items'])===10&&count($s['rows'])===10&&$s['candidate_backlog']===[],'replacement selection uses saved candidates without new catalogue page');
ok(count(array_filter($db->jobs,fn($j)=>$j->job_type==='starter'))===0,'replacement costs no catalogue HTTP job when page candidates remain');
ok(count($options['mbi_rejected_candidates52'])===5,'metadata failures remembered across requests');
for($n=0;$n<5;$n++)collect52(false);$s=$options['mbi_serial_batch'];
ok($s['phase']==='validate'&&$s['cursor']===5&&$s['verified']===5,'second validation starts at replacement wave, never revisits rejected first wave');
for($n=0;$n<5;$n++){
 $job=array_shift($db->jobs);$data=call51('staged_inventory51',$job->payload);ok(count($data['chapter_urls'])===2,'replacement reuses collected inventory');
 // Exercise the same callback invoked by the existing final publication gate.
 call51('advance_serial_batch','G',0,true);
 if($n<4)ok($options['mbi_serial_batch']['active']&&$options['mbi_serial_batch']['completed']===$n+1,'request stays active until all five publication callbacks');
}
$s=$options['mbi_serial_batch'];ok(!$s['active']&&$s['completed']===5&&$s['target']===5&&$s['finish_reason']==='target_reached','five blocked followed by five approved fulfils five, not ten analyses');
ok($s['verified']===10&&$s['capture_done']===10&&$db->jobs===[],'each selected candidate resolved once and queue stops at actual quota');
$v=$view->invoke($admin,$s);ok($v['bars']['publish']===100&&$v['counts']['publish']==='5/5'&&!$v['can_resume'],'publication denominator stays at requested five despite ten candidates');
ok(count(array_filter($s['rows'],fn($r)=>$r['status']==='pending'))===5,'failed candidates retain reasons after target attained');
// One requested post must replace a rejected work and stop after one publication.
reset52();call51('start_serial_batch',[url52(1),url52(2),url52(3)],'catalog',1);collect52(true);reject52();collect52(false);array_shift($db->jobs);call51('advance_serial_batch','G',0,true);$s=$options['mbi_serial_batch'];
ok(!$s['active']&&$s['completed']===1&&count($s['items'])===2&&count($s['candidate_backlog'])===1,'one requested post stops after first publication, leaving third candidate unused');
// Duplicate and recently rejected candidates cannot create another loop.
$before=count($s['items']);$s['completed']=0;$s['candidate_backlog']=[$s['items'][0],$s['items'][1],['url'=>url52(3)],['url'=>url52(3)]];$a=call51('append_candidates52',$s,[]);
ok(count($a['items'])===$before+1&&$a['items'][$before]['url']===url52(3),'tried/duplicate URLs removed before next capture');
// No candidate left: show an unmet request, never a fake publication or 100%.
reset52();call51('start_serial_batch',[url52(1)],'catalog',1);collect52(true);reject52();$s=$options['mbi_serial_batch'];$v=$view->invoke($admin,$s);
ok(!$s['active']&&$s['finish_reason']==='candidates_exhausted'&&$s['completed']===0,'unusable catalogue terminates at finite page limit');
ok($v['bars']['publish']===0&&$v['can_resume']&&$v['counts']['publish']==='0/1','unmet request offers completion action and remains zero percent published');
// A new page is requested once, with all previous keys excluded.
reset52();$options['mbi_catalog_discovery']['page_limit']=3;call51('start_serial_batch',[url52(1)],'catalog',1);collect52(true);reject52();$s=$options['mbi_serial_batch'];$c=$options['mbi_catalog_discovery'];
ok(!$s['active']&&$s['waiting_catalog']&&$c['page']===2&&$c['limit']===1,'replacement discovery advances to next page with only remaining quota');
ok(count($db->jobs)===1&&$db->jobs[0]->job_type==='starter'&&count($c['seen'])>=1,'one discovery job, with prior candidate remembered');
$v=$view->invoke($admin,$s);ok($v['phase']==='select'&&$v['bars']['publish']===0&&!$v['can_resume'],'waiting discovery stays active in UI rather than fake completed');
// Preserve old captured results while resuming only new candidates from discovery.
$options['mbi_catalog_discovery']['seen']['NEW']=true;$next=call51('start_serial_batch',[url52(2),url52(3)],'catalog',1);
ok($next['id']===$s['id']&&$next['target']===1&&$next['wave_start']===1&&$next['cursor']===1,'next catalogue page resumes same ID, target and capture cursor');
ok(count($next['items'])===2&&count($next['candidate_backlog'])===1&&$next['rows'][0]['status']==='pending','old failure retained, surplus URLs saved on resumed discovery');
// If discovery fails before reaching its limit, retry that page, not its limit.
$options['mbi_serial_batch']=$s;$db->jobs=[];
call51('finish_empty_catalog_refill',['resume_serial_batch'=>'G','page'=>2,'page_limit'=>3,'consecutive_source_failures'=>2]);
$options['mbi_catalog_discovery']['active']=false;$result=$i->resume_quantity_goal52();
ok($result['queued']&&$options['mbi_catalog_discovery']['page']===2&&$options['mbi_catalog_discovery']['page_limit']===3,'source recovery resumes failed page without skipping remaining pages or expanding range');
// Explicit v1.7.51 completion action rereads its last partially consumed page once.
reset52();$options['mbi_serial_batch']=['id'=>'OLD','generation'=>'G','mode'=>'catalog','workflow'=>'collect51','phase'=>'done','active'=>false,'items'=>[['url'=>url52(1),'manga_id'=>0]],'rows'=>[['status'=>'pending','reason'=>'capa sem URL HTTP(S) válida','title'=>'Blocked']],'target'=>1,'completed'=>0,'cursor'=>1,'capture_done'=>1,'captured'=>1,'verified'=>1,'catalog_next_page'=>2,'catalog_page_limit'=>3,'finish_reason'=>'batch_reviewed'];
$r=$i->resume_quantity_goal52();$s=$options['mbi_serial_batch'];$c=$options['mbi_catalog_discovery'];
ok($r['queued']&&$s['id']==='OLD'&&$s['target']===1&&$s['waiting_catalog'],'old screenshot request resumes without reset or zeroing counters');
ok($c['page']===1&&count($c['seen'])===1&&isset($options['mbi_rejected_candidates52']),'v1.7.51 rereads only last page and excludes bad work');
$jobs=count($db->jobs);$r=$i->resume_quantity_goal52();ok(!$r['queued']&&count($db->jobs)===$jobs,'repeated completion click never adds concurrent discovery');
reset52();$old['id']='ACTIVE51';$old['generation']='G';$old['mode']='catalog';$old['workflow']='collect51';$old['active']=true;$old['items']=[['url'=>url52(1)]];$old['target']=1;$old['completed']=0;$old['cursor']=1;$old['catalog_next_page']=2;$old['catalog_page_limit']=3;unset($old['quantity_goal52']);$options['mbi_serial_batch']=$old;
call51('finish_serial_batch',$old);ok($options['mbi_catalog_discovery']['page']===1&&!empty($options['mbi_serial_batch']['waiting_catalog']),'already running v1.7.51 request recovers unused last-page candidates on first refill');
// Temporary transport errors must not poison the persistent rejection index.
reset52();call51('remember_rejection52',url52(1),'capa: cURL error 28 timeout');ok(empty($options['mbi_rejected_candidates52']),'network timeout not cached as confirmed defective cover');
// Systemic source failures stop an unproductive scan, preserving unmet quota.
reset52();call51('start_serial_batch',[url52(1),url52(2),url52(3)],'catalog',1);
$job=array_shift($db->jobs);call51('release_serial_after_terminal_failure',$job,'cURL error 28 timeout');
$job=array_shift($db->jobs);call51('release_serial_after_terminal_failure',$job,'cURL error 28 timeout');$s=$options['mbi_serial_batch'];
ok(!$s['active']&&$s['finish_reason']==='source_unavailable'&&$s['completed']===0&&count($s['candidate_backlog'])===1,'two terminal network failures stop source churn without claiming a post');
// A manual URL request must never substitute a different manga.
reset52();call51('start_serial_batch',[url52(1),url52(2)],'manual_url',1);collect52(true);reject52();$s=$options['mbi_serial_batch'];
ok(!$s['active']&&count($s['items'])===1&&$s['completed']===0,'manual URL keeps selected work despite blocked validation');
class Catalogue52DB {public $postmeta='wp_postmeta',$posts='wp_posts',$queries=[];function prepare($sql,...$a){return $sql;}function get_results($sql){$this->queries[]=$sql;return [];}}
$wpdb=new Catalogue52DB;
reset52();$options['mbi_serial_batch']=[];$options['mbi_catalog_discovery']=['id'=>'SCAN','active'=>true,'mode'=>'catalog','limit'=>1,'page'=>1,'page_limit'=>2,'seen'=>[],'generation'=>'G'];
call51('remember_rejection52',url52(1),'capa sem URL HTTP(S) válida');
$html='<html><body><h1>Catalogue</h1><a href="'.url52(1).'">Known bad</a><a href="'.url52(2).'">Next</a><a href="'.url52(3).'">Spare</a><a href="/manga/page/2/">2</a></body></html>';
$pref->setValue($i,['https://mangalivre.blog/manga/'=>$html]);
call51('import_starter',['generation'=>'G','catalog_scan_id'=>'SCAN','mode'=>'catalog','limit'=>1]);$s=$options['mbi_serial_batch'];
ok(count($s['items'])===1&&$s['items'][0]['url']===url52(2),'real catalogue parser skips remembered rejection before root HTTP');
ok(count($s['candidate_backlog'])===1&&$s['candidate_backlog'][0]['url']===url52(3),'actual discovery preserves remaining URLs on first page');
ok(count($wpdb->queries)===1&&MBI_Global_Diagnostic::$calls===[],'page selection uses one grouped lookup and no image request');
collect52(true);reject52();$s=$options['mbi_serial_batch'];
ok($s['current_url']===url52(3)&&$s['phase']==='collect'&&count(array_filter($db->jobs,fn($j)=>$j->job_type==='starter'))===0,'real discovery and validation refill from same page without fetching catalogue twice');
echo "PASS $checks pipeline and publication-quota checks\n";
