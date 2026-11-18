<?php
use MangaBridge2\Job;
use MangaBridge2\Store;
use MangaBridge2\Plugin;
use MangaBridge2\Publisher;
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='staging' || !Publisher::enabled()) {
    throw new RuntimeException('Disposable CI staging only');
}
function pilot_assert($ok,string $message):void {
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}
$mode=getenv('MBI2_PILOT_CASE');
if (str_starts_with($mode,'seed_')) {
    $mode=substr($mode,5);
    $slug=$mode==='incomplete'?'github-pilot-incomplete':'github-pilot-complete';
    $source='https://mangalivre.blog/manga/'.$slug.'/';
    $id=wp_generate_uuid4();
    $job=Job::create($id,wp_generate_uuid4(),$source,Plugin::hosts(),time());
    $job['publish_on_ready']=true;
    pilot_assert(Store::create($job),'admin-authorized publication job stored');
    file_put_contents('/tmp/mbi2-pilot-job.json',wp_json_encode(compact('id','source','slug','mode')));
    return;
}
$d=json_decode(file_get_contents('/tmp/mbi2-pilot-job.json'),true,32,JSON_THROW_ON_ERROR);
$id=$d['id'];
$job=Store::read($id);
$find=static fn(string $type,string $url):array=>get_posts(['post_type'=>$type,'post_status'=>['draft','publish','pending','private'],'posts_per_page'=>-1,'fields'=>'ids',
    'meta_key'=>'_mbi_source_url','meta_value'=>$url]);
if ($mode==='check_incomplete') {
    pilot_assert($job['state']==='blocked' && $job['reason']==='unproven_reader_page_count','incomplete origin stopped in Python before publish');
    pilot_assert(!$find('mbi_manga',$d['source']),'incomplete origin caused zero catalogue writes');
    return;
}
pilot_assert($job['state']==='ready' && $job['published']===0 && wp_next_scheduled('mbi2_publish',[$id]),'signed result ready and scheduled; callback did not publish');
$before=$find('mbi_manga',$d['source']);
add_filter('pre_http_request',static function($pre,$args,$url) { throw new RuntimeException('WordPress publication must not perform HTTP'); }, 100, 3);
do_action('mbi2_publish',$id);
do_action('mbi2_publish',$id);
$job=Store::read($id);
$works=$find('mbi_manga',$d['source']);
if ($mode==='check_duplicate') {
    pilot_assert($job['state']==='blocked' && $job['reason']==='existing_catalogue_record_preserved' && $works===$before && count($works)===1,'other job cannot overwrite or duplicate an existing work');
    return;
}
pilot_assert($job['state']==='published' && $job['published']===1 && !$before && count($works)===1 && (int)$works[0]===$job['post_id'],'signed GitHub-engine result published exactly one new work');
$manga=(int)$works[0];
$chapter=$find('mbi_chapter','https://mangalivre.blog/capitulo/'.$d['slug'].'-1/');
pilot_assert(get_post_status($manga)==='publish' && count($chapter)===1 && get_post_status($chapter[0])==='publish' &&
    wp_get_post_parent_id($chapter[0])===$manga && \MBI_Chapter_Integrity::valid($chapter[0]),'manga and chapter public with locally verified image receipt');
pilot_assert(wp_get_object_terms($manga,'category',['fields'=>'slugs'])===['mangas'],'published work classified in the existing Mangás category');
