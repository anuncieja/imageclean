<?php
require __DIR__.'/inventory.php';
$v=new ReflectionMethod($i,'validate_manga_data');
$p->load(file_get_contents(__DIR__.'/fixtures/witch.html'),'https://mangalivre.blog/manga/witch-craft-works/');
$d=$p->parse_manga('https://mangalivre.blog/manga/witch-craft-works/',true);
ok($d['status']==='Finalizado','real source Concluído maps to Finalizado');
ok($v->invoke($i,$d,false)===[],'real Witch metadata passes all metadata guards');
$d['status']='status arbitrário';ok(in_array('status fora do padrão',$v->invoke($i,$d,false)),'unknown status remains blocked');
$d['status']='Concluído';ok(!in_array('status fora do padrão',$v->invoke($i,$d,false)),'old checkpoint Concluído accepted');
$d['status']='Concluído<script>bad</script>';ok(in_array('status fora do padrão',$v->invoke($i,$d,false)),'injected status rejected');
$p->load(file_get_contents(__DIR__.'/fixtures/renai.html'),'https://mangalivre.blog/manga/renai-no-jugyou/');$d=$p->parse_manga('https://mangalivre.blog/manga/renai-no-jugyou/',true);
ok($d['cover_url']===''&&in_array('capa sem URL HTTP(S) válida',$v->invoke($i,$d,false)),'real placeholder not replaced by recommendation image');
// Long invalid work must use only root, retaining API responses untouched.
$transients=[];MBI_Resource_Guard::$left=12;$bad=str_replace('https://mangalivre.blog/wp-content/uploads/cover-real.jpg','https://mangalivre.blog/no-cover.jpg',roothtml());
$pref->setValue($i,array_merge([$url=>$bad],$responses));$d=$method->invoke($i,$url);
ok(count($pref->getValue($i))===8,'invalid metadata skips all eight REST requests');
ok(in_array('capa sem URL HTTP(S) válida',$v->invoke($i,$d)),'early result reaches unchanged full validation as invalid');
ok(!isset($transients[$saved_key]),'invalid metadata leaves no inventory checkpoint');
// Cached v47 work with a missing cover also stops before API calls.
$cached=$seed;$cached['data']['cover_url']='';$transients[$saved_key]=$cached;$pref->setValue($i,$responses);$d=$method->invoke($i,$url);
ok(count($pref->getValue($i))===8&&!isset($transients[$saved_key]),'invalid old checkpoint exits without API or queue reset');
$d=$seed['data'];$d['chapter_urls']=[];ok($v->invoke($i,$d,false)===[]&&in_array('nenhum capítulo reconhecido',$v->invoke($i,$d)),'metadata preflight does not approve missing chapters for publication');
echo 'PASS '.$checks." combined metadata and inventory checks\n";
function get_posts($args){return [];}
$transients=[];$pref->setValue($i,[$url=>$bad]);$im=new ReflectionMethod($i,'import_manga');$payload=['url'=>$url,'generation'=>'G','serial_batch'=>'B'];$err=null;try{$im->invoke($i,$payload);}catch(MBI_Skip_Exception $e){$err=$e->getMessage();}
ok(str_contains((string)$err,'capa sem URL HTTP(S) válida'),'first bulk read records real metadata issue');
$pref->setValue($i,[$url=>$bad]);try{$im->invoke($i,$payload);}catch(MBI_Skip_Exception $e){$err=$e->getMessage();}
ok(str_contains($err,'última hora')&&count($pref->getValue($i))===1,'next bulk order avoids another HTTP read for rejected metadata');
unset($payload['serial_batch']);try{$im->invoke($i,$payload);}catch(MBI_Skip_Exception $e){$err=$e->getMessage();}
ok(!str_contains($err,'última hora')&&count($pref->getValue($i))===0,'explicit manual URL bypasses temporary rejection cache');
echo "PASS $checks metadata/inventory/cache checks\n";
