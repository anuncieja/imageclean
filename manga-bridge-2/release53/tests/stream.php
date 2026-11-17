<?php
require __DIR__.'/pipeline.php'; // Existing in-flight pre-1.7.53 batches still pass.
function raw53($method,...$args){global $i;return (new ReflectionMethod($i,$method))->invoke($i,...$args);}
$options=['mbi_process_generation'=>'G','mbi_generation_enforced'=>1,'mbi_settings'=>['continuous_worker'=>0],'mbi_catalog_discovery'=>['page'=>1,'last_page_scanned'=>1,'page_limit'=>1]];$transients=[];$posts=[];$db=new MBI_DB;$i=new MBI_Importer($db,new MBI_Parser);$pref=new ReflectionProperty($i,'prefetched_html');
$s=raw53('start_serial_batch',[$one,$two],'catalog',2);ok(!empty($s['stream53']),'new batches use streaming selection');
$j=array_shift($db->jobs);$pref->setValue($i,[$one=>root51()]);raw53('run_job',$j);$s=$options['mbi_serial_batch'];
ok($s['phase']==='validate'&&$s['cursor']===0&&$s['captured']===1,'first work validated immediately after its own collection');
ok(count($db->jobs)===1&&$db->jobs[0]->payload['url']===$one&&$db->jobs[0]->payload['pipeline_phase']==='validate','no request for second work before validating first');
$validation=array_shift($db->jobs);$pref->setValue($i,[]);$data=raw53('staged_inventory51',$validation->payload);ok(count($data['chapter_urls'])===2,'same captured inventory reused without another HTML request');
raw53('advance_serial_batch','G',0,true);$s=$options['mbi_serial_batch'];ok($s['completed']===1&&$s['cursor']===1&&$s['phase']==='collect','publication advances to collection of second work');
$old=$s;raw53('release_serial_after_terminal_failure',$j,'late capture failure');ok($options['mbi_serial_batch']===$old,'late phase event cannot advance published work');
$j=array_shift($db->jobs);$pref->setValue($i,[$two=>root51()]);raw53('run_job',$j);array_shift($db->jobs);raw53('advance_serial_batch','G',0,true);$s=$options['mbi_serial_batch'];ok(!$s['active']&&$s['completed']===2&&$s['phase']==='done','requested publication quota closes streaming batch');
$options['mbi_serial_batch']=[];$db->jobs=[];$s=raw53('start_serial_batch',[$one,$two],'catalog',1);$j=array_shift($db->jobs);raw53('release_serial_after_terminal_failure',$j,'HTTP 404 missing root');$s=$options['mbi_serial_batch'];
ok($s['active']&&$s['phase']==='collect'&&$s['cursor']===1&&count($s['items'])===2,'failed capture selects saved replacement without catalogue reread');
$j=array_shift($db->jobs);$pref->setValue($i,[$two=>root51()]);raw53('run_job',$j);array_shift($db->jobs);raw53('advance_serial_batch','G',0,true);$s=$options['mbi_serial_batch'];ok(!$s['active']&&$s['completed']===1&&$s['verified']===2,'rejected candidate never counts toward requested publication');
$options['mbi_serial_batch']=[];$db->jobs=[];$s=raw53('start_serial_batch',[$one],'manual_url',1);$j=array_shift($db->jobs);$pref->setValue($i,[$one=>root51(true)]);raw53('run_job',$j);$s=$options['mbi_serial_batch'];ok($s['captured']===1&&$s['phase']==='validate','manual URL still collects invalid metadata before judging');
$options['mbi_queue_paused']=1;$before=$s;$blocked=false;try{raw53('staged_inventory51',$db->jobs[0]->payload);}catch(MBI_Resource_Wait $e){$blocked=true;}ok($blocked&&$options['mbi_serial_batch']===$before,'pause retains exact streaming cursor');
echo "PASS $checks streaming and legacy checks\n";
