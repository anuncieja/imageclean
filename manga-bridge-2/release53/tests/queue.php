<?php
require __DIR__.'/common.php';
function maybe_unserialize($v){return unserialize($v);}function maybe_serialize($v){return serialize($v);}
class QueueWpdb {
 public $prefix='wp_';public $rows=[];public $sql=[];
 function prepare($s,...$a){return $s.' /*ARGS'.json_encode($a).'*/';}
 function args($q){preg_match('~/\*ARGS(.*?)\*/$~',$q,$m);return isset($m[1])?json_decode($m[1],true):[];}
 function get_row($q){$this->sql[]=$q;$a=$this->args($q);if(str_contains($q,"WHERE id=%d AND job_type='chapter'")){$r=$this->rows[$a[0]]??null;return $r&&$r->status==='pending'?clone $r:null;}foreach($this->rows as $r){if($r->status==='pending'&&strtotime($r->available_at.' UTC')<=time())return clone $r;}return null;}
 function query($q){$this->sql[]=$q;$a=$this->args($q);if(str_contains($q,"SET status = 'processing'")){$r=$this->rows[$a[2]]??null;if(!$r||$r->status!=='pending')return 0;$r->status='processing';return 1;}return 0;}
 function update($t,$data,$where){foreach($data as $k=>$v)$this->rows[$where['id']]->$k=$v;return 1;}
 function get_var($q){$this->sql[]=$q;if(str_contains($q,'MIN(available_at)')){ $v=[];foreach($this->rows as $r)if($r->status==='pending')$v[]=$r->available_at;return $v?min($v):null;}return null;}
}
$wpdb=new QueueWpdb;require PLUGIN_ROOT . '/includes/class-mbi-db.php';$db=new MBI_DB;
foreach([1,2,3] as $id)$wpdb->rows[$id]=(object)['id'=>$id,'job_type'=>'chapter','status'=>$id===1?'processing':'pending','payload'=>'{"url":"https://source.test/capitulo/'.$id.'/","serial_batch":"B","manga_id":7}','batch_id'=>'B','queue_class'=>'serial','available_at'=>gmdate('Y-m-d H:i:s',time()-1),'attempts'=>0,'locked_at'=>'T'];
$options['mbi_serial_batch']=['active'=>true,'id'=>'B'];$options['mbi_validation_focus']=['id'=>1,'generation'=>'G','batch'=>'B','after'=>time()-1,'until'=>time()+60];
$posts[7]=['post_type'=>'mbi_manga','post_status'=>'draft'];$meta[7]['_mbi_expected_chapters']=3;$before=[$posts,$meta];
$db->defer($wpdb->rows[1],30,'cURL error 28 timeout');ok(!isset($options['mbi_validation_focus']),'timeout unpins only its own focused chapter');
$j=$db->claim_next('B');ok($j&&$j->id===2,'second chapter claimed while first waits thirty seconds');ok($wpdb->rows[1]->status==='pending'&&count($wpdb->rows)===3,'timed-out chapter and full queue retained');ok([$posts,$meta]===$before,'queue policy does not publish or truncate the manga');ok($j->payload['manga_id']===7,'next chapter remains in same manga');
$options['mbi_validation_focus']=['id'=>3,'generation'=>'G','batch'=>'B','after'=>time()+3,'until'=>time()+60];$db->defer($wpdb->rows[1],30,'timeout');ok($options['mbi_validation_focus']['id']===3,'deferring one job cannot remove another focus');
unset($options['mbi_validation_focus']);$wpdb->rows[3]->status='pending';
class MBI_Resource_Guard{static function wait_seconds(){return 0;}}class MBI_Parser{}require PLUGIN_ROOT . '/includes/class-mbi-importer.php';$i=new MBI_Importer($db,new MBI_Parser);
ok($i->worker_wait_seconds()===0,'due sibling avoids global wait for a future retry');
$wpdb->rows[3]->available_at=gmdate('Y-m-d H:i:s',time()+30);ok($i->worker_wait_seconds()>=29,'only future jobs still sleep rather than busy-poll');
$options['mbi_publication_not_before']=time()+120;ok($i->worker_wait_seconds()>=119,'explicit global cooldown never bypassed');
echo "PASS $checks queue continuation checks\n";
