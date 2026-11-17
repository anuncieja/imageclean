<?php
require __DIR__.'/metadata.php';
$norm=new ReflectionMethod($i,'normalize_manga_fields');$cover=new ReflectionMethod($i,'preflight_manga_cover');
$d=$seed['data'];$d['status']='Em hiato';$clean=$norm->invoke($i,$d);ok($clean['status']===''&&!empty($clean['_metadata_notes50']),'unknown plain descriptive status omitted with evidence');ok($v->invoke($i,$clean,false)===[],'optional status never rejects otherwise sound metadata');
$d['status']='window.location=bad';$clean=$norm->invoke($i,$d);ok(in_array('status fora do padrão',$v->invoke($i,$clean,false)),'code-like status still blocked');
$d=$seed['data'];$d['author']='';ok($v->invoke($i,$d,false)===[],'missing optional author does not block');$d['synopsis']='x';ok(in_array('sinopse curta/incompleta',$v->invoke($i,$d,false)),'required synopsis still enforced');
// Physical invalid cover is discovered before REST pagination.
$transients=[];MBI_Global_Diagnostic::$calls=[];MBI_Global_Diagnostic::$response=['ok'=>false,'http'=>404,'message'=>'HTTP 404'];MBI_Resource_Guard::$left=12;
$pref->setValue($i,array_merge([$url=>roothtml()],$responses));$error=null;try{$method->invoke($i,$url);}catch(MBI_Skip_Exception $e){$error=$e;}
ok($error!==null&&count($pref->getValue($i))===8&&count(MBI_Global_Diagnostic::$calls)===1,'bad cover skips eight REST requests after one bounded cover probe');
// Network failure is not a permanent rejection.
$transients=[];MBI_Global_Diagnostic::$response=['ok'=>false,'http'=>0,'message'=>'cURL error 28 timeout'];$pref->setValue($i,array_merge([$url=>roothtml()],$responses));$error=null;try{$method->invoke($i,$url);}catch(RuntimeException $e){$error=$e;}
ok($error!==null&&!($error instanceof MBI_Skip_Exception)&&count($pref->getValue($i))===8,'cover timeout preserves retry and never starts chapter pagination');ok(!isset($transients['mbi_broken_work_'.md5($url)]),'timeout never quarantines work');
// A positive receipt avoids repeated checks in long inventories and expires.
$transients=[];MBI_Global_Diagnostic::$response=null;MBI_Global_Diagnostic::$calls=[];$d=$seed['data'];$cover->invoke($i,$url,$d);$cover->invoke($i,$url,$d);ok(count(MBI_Global_Diagnostic::$calls)===1,'same work URL and generation reuse one cover proof');
$cover->invoke($i,$url.'other/',$d);ok(count(MBI_Global_Diagnostic::$calls)===2,'proof cannot be borrowed by another work');
$key='mbi_cover_receipt50_'.md5('G|'.$url.'|'.$d['cover_url']);$transients[$key]['at']=time()-301;$cover->invoke($i,$url,$d);ok(count(MBI_Global_Diagnostic::$calls)===3,'expired cover proof is checked again');
$options['mbi_process_generation']='NEW';$cover->invoke($i,$url,$d);ok(count(MBI_Global_Diagnostic::$calls)===4,'new generation cannot inherit cover proof');$options['mbi_process_generation']='G';
// Existing published catalogue entry is skipped before any origin fetch.
$transients=[];$posts[9]=['post_type'=>'mbi_manga','post_status'=>'publish'];
// get_posts() is already stubbed empty: explicit existing hint supplies the identity.
$pref->setValue($i,[$url=>roothtml()]);$error=null;try{$im->invoke($i,['url'=>$url,'generation'=>'G','catalog_import'=>true,'manga_id_hint'=>9]);}catch(MBI_Skip_Exception $e){$error=$e;}
ok($error!==null&&str_contains($error->getMessage(),'já existente')&&count($pref->getValue($i))===1,'existing manga skip happens before origin HTTP or inventory');
// A fully reconciled 3201-chapter work passes; arbitrary size alone is not corruption.
$transients=[];MBI_Global_Diagnostic::$calls=[];$large=str_replace('254','3201',roothtml());$parts=[];
for($offset=30;$offset<3201;$offset+=30){$next=min(3201,$offset+30);$parts[add_query_arg(['offset'=>$offset,'search'=>''],$endpoint)]=json_encode(['list'=>links($offset+1,$next),'offset'=>$next,'total'=>3201]);}
$pref->setValue($i,array_merge([$url=>$large],$parts));$done=null;
for($r=0;$r<60;$r++){MBI_Resource_Guard::$left=12;try{$done=$method->invoke($i,$url);break;}catch(MBI_Resource_Wait $e){$pref->setValue($i,array_merge($pref->getValue($i),[$url=>$large]));}}
ok(is_array($done)&&count($done['chapter_urls'])===3201&&count(array_unique($done['chapter_urls']))===3201,'3201 chapters accepted only after exact pagination reconciliation');ok($v->invoke($i,$done)===[],'full validator has no arbitrary 3000 ceiling');ok(count(MBI_Global_Diagnostic::$calls)===1,'long work validates cover once across repeated bounded rounds');
$done['chapter_urls']=[];ok(in_array('nenhum capítulo reconhecido',$v->invoke($i,$done)),'empty chapter list still blocked');
echo "PASS $checks combined metadata/inventory/preflight checks\n";
$transients=[];$d=$seed['data'];MBI_Global_Diagnostic::$response=['ok'=>false,'http'=>404,'message'=>'HTTP 404'];MBI_Global_Diagnostic::$onprobe=function(){global $options;$options['mbi_process_generation']='RESET';};$error=null;try{$cover->invoke($i,$url,$d);}catch(MBI_Resource_Wait $e){$error=$e;}
ok($error!==null&&!isset($transients['mbi_broken_work_'.md5($url)]),'reset during cover probe cannot quarantine or approve old work');
$options['mbi_process_generation']='G';MBI_Global_Diagnostic::$onprobe=function(){global $options;$options['mbi_queue_paused']=1;};$error=null;try{$cover->invoke($i,$url,$d);}catch(MBI_Resource_Wait $e){$error=$e;}
ok($error!==null&&!isset($transients['mbi_broken_work_'.md5($url)]),'pause during cover probe preserves source state');
$options['mbi_queue_paused']=0;MBI_Global_Diagnostic::$onprobe=null;MBI_Global_Diagnostic::$response=null;
// Older active checkpoints retain pagination progress; final root still checks cover.
$transients=[];$legacy=$seed;unset($legacy['data']['_cover_candidates50']);$transients[$saved_key]=$legacy;$pref->setValue($i,$responses);MBI_Resource_Guard::$left=12;try{$method->invoke($i,$url);}catch(MBI_Resource_Wait $e){}
ok($transients[$saved_key]['loader']['offset']===90&&count($pref->getValue($i))===6,'pre-v50 checkpoint resumes its offset rather than restarting the root');
echo "PASS $checks full validation preflight checks\n";
$transients=[];MBI_Global_Diagnostic::$calls=[];$d=$seed['data'];$a=$d['cover_url'];$d['_cover_candidates50']=[$a,'https://mangalivre.blog/wp-content/uploads/cover-alternate.jpg'];MBI_Global_Diagnostic::$onprobe=function()use($a){$u=end(MBI_Global_Diagnostic::$calls);MBI_Global_Diagnostic::$response=['ok'=>$u!==$a,'http'=>$u===$a?404:200,'message'=>$u===$a?'HTTP 404':'ok'];};$chosen=$cover->invoke($i,$url,$d);$cover->invoke($i,$url,$chosen);
ok(str_contains($chosen['cover_url'],'cover-alternate')&&count(MBI_Global_Diagnostic::$calls)===2,'one same-cover alternate is tested and reused without repeated probes');
MBI_Global_Diagnostic::$onprobe=null;MBI_Global_Diagnostic::$response=null;
echo "PASS $checks full preflight and bounded alternate checks\n";
