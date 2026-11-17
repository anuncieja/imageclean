<?php
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'staging') { throw new RuntimeException('CI staging only'); }
function verify53($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function digest53(){global $wpdb;$data=[];foreach([$wpdb->posts,$wpdb->postmeta,$wpdb->term_relationships,$wpdb->prefix.'mbi_queue'] as $table){$rows=$wpdb->get_results("SELECT * FROM $table",ARRAY_A);usort($rows,fn($a,$b)=>strcmp(wp_json_encode($a),wp_json_encode($b)));$data[$table]=$rows;}return hash('sha256',wp_json_encode($data));}
$phase=getenv('MBI53_PHASE');
if($phase==='seed'){
 $m=wp_insert_post(['post_type'=>'mbi_manga','post_status'=>'publish','post_title'=>'CI existing work','post_name'=>'ci-preserved-slug'],true);
 $c=wp_insert_post(['post_type'=>'mbi_chapter','post_status'=>'publish','post_parent'=>$m,'post_title'=>'CI existing chapter'],true);
 verify53(is_int($m)&&is_int($c),'published fixtures created');
 update_post_meta($m,'_mbi_cover_original_url','https://example.org/retained-cover.jpg');update_post_meta($m,'_mbi_cover_source_url','https://example.org/retained-cover.jpg');
 update_post_meta($c,'_mbi_source_url','https://mangalivre.blog/capitulo/ci-1/');update_post_meta($c,'_mbi_source_image_urls',['https://example.org/page.jpg']);update_post_meta($c,'_mbi_chapter_page_urls',['https://example.org/page.jpg']);
 update_option('mbi_queue_paused',1,false);update_option('mbi_serial_batch',['id'=>'ci-prior-batch','active'=>true,'cursor'=>0,'target'=>10,'completed'=>0,'items'=>[]],false);
 mbi()->db->queue('manga',['url'=>'https://mangalivre.blog/manga/ci-preserved/','generation'=>wp_generate_uuid4()]);
 update_option('mbi53_preserved_state',digest53(),false);update_option('mbi53_settings_before',get_option('mbi_settings'),false);update_option('mbi53_serial_before',get_option('mbi_serial_batch'),false);
 update_option('mbi_run_dedupe_upgrade',1,false);update_option('mbi_run_validation_upgrade',1,false);update_option('mbi_version','1.7.52',false);
 echo "Seeded unchanged 1.7.52 schema with published catalogue and pending queue.\n";return;
}
verify53(hash_equals((string)get_option('mbi53_preserved_state'),digest53()),'catalogue and pending queue preserved');
verify53(get_option('mbi53_settings_before')===get_option('mbi_settings'),'settings preserved');
verify53(get_option('mbi53_serial_before')===get_option('mbi_serial_batch'),'active batch preserved');
if($phase==='verify'){
 verify53(get_option('mbi_version')==='1.7.53','version upgrade completed');
 verify53(!get_option('mbi_run_dedupe_upgrade')&&!get_option('mbi_run_validation_upgrade')&&get_option('mbi_upgrade53')['deferred_legacy_flags']['validation']===1,'historical flags archived without catalogue mutations');
 delete_transient('mbi_invalid_cover_count');global $wpdb;$before=$wpdb->num_queries;
 $guard=function($sql){global $wpdb;if(stripos($sql,'SELECT')!==false&&strpos($sql,$wpdb->posts)!==false){throw new RuntimeException('Catalogue scan in cached-only counter');}return $sql;};
 add_filter('query',$guard);$count=mbi()->importer->invalid_cover_count(true);remove_filter('query',$guard);
 verify53($count===-1,'opening maintenance counter performs no catalogue scan');
 $r=new ReflectionMethod(MBI_Importer::class,'validation_control_current53');$gen=wp_generate_uuid4();update_option('mbi_process_generation',$gen,false);update_option('mbi_generation_enforced',1,false);update_option('mbi_queue_paused',0,false);
 $before=$wpdb->num_queries;verify53($r->invoke(mbi()->importer,['generation'=>$gen])===true,'validation permits current owner');$queries=$wpdb->num_queries-$before;verify53($queries===1,'fresh pause and generation control uses exactly one SQL read');
 update_option('mbi_queue_paused',1,false);verify53(!$r->invoke(mbi()->importer,['generation'=>$gen]),'pause observed without stale cache');
 update_option('mbi_process_generation',wp_generate_uuid4(),false);$blocked=false;try{$r->invoke(mbi()->importer,['generation'=>$gen]);}catch(MBI_Skip_Exception $e){$blocked=true;}verify53($blocked,'old generation cannot publish');
 verify53(hash_equals((string)get_option('mbi53_preserved_state'),digest53()),'control checks preserve catalogue and queue');
}
echo 'Environment: WP '.get_bloginfo('version').'; PHP '.PHP_VERSION.'; MariaDB '.$GLOBALS['wpdb']->db_version()."\n";
