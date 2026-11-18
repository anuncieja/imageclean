<?php
require __DIR__.'/common.php';
class MBI_DB{}class MBI_Parser{}
class SelectionDB {public $postmeta='wp_postmeta',$posts='wp_posts',$calls=[],$rows=[];function prepare($sql,...$a){return $sql.' '.json_encode($a);}function get_results($sql){$this->calls[]=$sql;return $this->rows;}}
$wpdb=new SelectionDB;
require PLUGIN_ROOT . '/includes/class-mbi-importer.php';
$i=new MBI_Importer(new MBI_DB,new MBI_Parser);$key=new ReflectionMethod($i,'source_key');$select=new ReflectionMethod($i,'catalog_existing_keys51');
$url='https://mangalivre.blog/manga/existing/';$k=$key->invoke($i,$url);
$wpdb->rows=[(object)['meta_key'=>'_mbi_source_key','meta_value'=>$k],(object)['meta_key'=>'_mbi_source_url','meta_value'=>'https://mangalivre.blog/manga/legacy/']];
$urls=[];for($n=0;$n<240;$n++)$urls[]='https://mangalivre.blog/manga/work-'.$n.'/';$urls=array_merge($urls,[$urls[0],$urls[1]]);
$found=$select->invoke($i,$urls);ok(count($wpdb->calls)===2,'240 candidates plus duplicates use two bounded queries, not 480 individual lookups');
ok(isset($found[$k])&&isset($found[$key->invoke($i,'https://mangalivre.blog/manga/legacy/')]),'source key and legacy URL both identify existing works');
ok(str_contains($wpdb->calls[0],"NOT IN ('trash','auto-draft')"),'deleted and placeholder posts do not consume selection slots');
$before=count($wpdb->calls);ok($select->invoke($i,[])===[]&&count($wpdb->calls)===$before,'empty selection performs no database query');
echo "PASS $checks catalogue lookup checks\n";
