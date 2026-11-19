<?php
use MangaBridge2\{Job,Plugin,Publisher,Store};
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(get_option('siteurl'), PHP_URL_HOST)!=='127.0.0.1') {
    throw new RuntimeException('Disposable CI only');
}
function pass54(bool $ok,string $message):void{
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}
function preserved54(): string {
    global $wpdb;
    $ids = get_option('mbi54_ids');
    $data = [];
    foreach ($ids as $id) {
        $data[] = get_post($id, ARRAY_A);
        $data[] = get_post_meta($id);
        $data[] = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->term_relationships} WHERE object_id=%d ORDER BY term_taxonomy_id",$id), ARRAY_A);
    }
    return hash('sha256',wp_json_encode($data));
}
$mode=getenv('MBI54_PHASE');
if ($mode==='seed') {
    pass54(MBI_VERSION==='1.7.53','baseline installed');
    $m=wp_insert_post(['post_type'=>'mbi_manga','post_title'=>'Existing CI work','post_name'=>'existing-ci-preserved','post_status'=>'publish','meta_input'=>[
        '_mbi_source_url'=>'https://mangalivre.blog/manga/mbi54-existing/','_mbi_cover_original_url'=>'https://example.org/cover.png','_mbi_ready'=>1]],true);
    $c=wp_insert_post(['post_type'=>'mbi_chapter','post_status'=>'publish','post_parent'=>$m,'post_title'=>'Existing CI chapter','meta_input'=>[
        '_mbi_source_url'=>'https://mangalivre.blog/capitulo/mbi54-existing-1/','_mbi_chapter_page_urls'=>['https://example.org/page.png']]],true);
    pass54(is_int($m)&&is_int($c),'seeded existing public work and chapter with image metadata');
    update_option('mbi54_ids',[$m,$c],false);
    update_option('mbi54_digest',preserved54(),false);
    update_option('mbi54_settings',get_option('mbi_settings'),false);
    return;
}
pass54(preserved54()===get_option('mbi54_digest'),'preexisting catalogue, IDs, slugs, covers and chapter relationships preserved');
pass54(get_option('mbi54_settings')===get_option('mbi_settings'),'preexisting importer settings preserved');
if ($mode==='rollback') {
    pass54(MBI_VERSION==='1.7.53' && !class_exists(Plugin::class),'rollback loaded previous plugin');
    pass54(get_post_status((int)get_option('mbi54_new_post'))==='publish','rollback retained newly published work');
    printf("Environment: WordPress %s; PHP %s; DB %s\n",get_bloginfo('version'),PHP_VERSION,$GLOBALS['wpdb']->db_version());
    return;
}
pass54(MBI_VERSION==='1.7.54','updated package active');
if ($mode==='upgrade' || $mode==='wrong_host') {
    pass54(Plugin::configuration()===($mode==='upgrade'?'disabled':'unexpected_site') && !Publisher::enabled(),'disabled by default and cannot publish on an unapproved production hostname');
    pass54(!isset(rest_get_server()->get_routes()['/mbi2/v1/jobs/(?P<id>[a-f0-9-]{36})']),'disabled transport does not register signed REST routes');
    return;
}
pass54(Publisher::enabled(),'enable flags and production hostname unlock only the new GitHub mode');
$path=getenv('MBI54_JOB_FILE');
if (str_starts_with($mode,'prepare_')) {
    $scenario=substr($mode,8);
    $slug=in_array($scenario,['success','duplicate'],true)?'mbi54-first':'mbi54-'.$scenario;
    $source='https://mangalivre.blog/manga/'.$slug.'/';
    $job=Job::create(wp_generate_uuid4(),wp_generate_uuid4(),$source,Plugin::hosts(),time());
    $job['publish_on_ready']=true;
    $ticket=Store::reserve($job['id']);
    pass54(is_string($ticket) && !Store::reserve(wp_generate_uuid4()),'only one request can reserve the slot');
    pass54(Store::create($job) && !Store::create($job),'UUID insertion is idempotent');
    Store::finish($ticket);
    pass54(Store::latest()===$job['id'] && !Store::reserve(wp_generate_uuid4()),'new request cannot replace an active job');
    $old=$job['generation'];
    if (in_array($scenario,['wrong_generation','resume'],true)) {
        $stopped=Job::admin($job,'cancel',wp_generate_uuid4(),time());
        pass54(Store::swap($job,$stopped) && !Store::swap($job,$stopped),'cancellation changes generation using compare-and-swap');
        $job=Job::admin($stopped,'resume',wp_generate_uuid4(),time());
        pass54(Store::swap($stopped,$job),'cancelled job resumes with a different generation');
    }
    $id=$job['id'];$generation=$job['generation'];$calls=0;
    add_filter('pre_http_request',static function($pre,$args,$url)use($id,$generation,&$calls){
        $calls++;
        pass54($url==='https://api.github.com/repos/anuncieja/imageclean/actions/workflows/manga-bridge-production.yml/dispatches','WordPress selects the production workflow');
        pass54(json_decode($args['body'],true)===['ref'=>'main','inputs'=>['job_id'=>$id,'generation'=>$generation]],'dispatch binds the job generation');
        return ['response'=>['code'=>204], 'body'=>'','headers'=>[],'cookies'=>[]];
    },99,3);
    Plugin::dispatch($id,wp_generate_uuid4());
    do_action('mbi2_dispatch',$id,$generation);
    do_action('mbi2_dispatch',$id,$generation);
    pass54($calls===1 && Store::read($id)['reason']==='github_dispatched','stale cron events are ignored and dispatch is sent once');
    file_put_contents($path,wp_json_encode(compact('id','source','slug','generation','scenario','old')));
    return;
}
$d=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
$id=$d['id'];$job=Store::read($id);
if ($mode==='check_wrong_generation') {
    pass54($job['state']==='queued' && $job['attempts']===0,'stale Actions run cannot claim the resumed job');
    pass54(Store::swap($job,Job::admin($job,'cancel',wp_generate_uuid4(),time())),'stale run test ended without catalogue writes');
    return;
}
$find=static fn(string $type,string $url):array=>get_posts(['post_type'=>$type,'post_status'=>['draft','publish','pending','private'],'posts_per_page'=>-1,'fields'=>'ids',
    'meta_key'=>'_mbi_source_url','meta_value'=>$url]);
$before=$find('mbi_manga',$d['source']);
if ($mode==='check_incomplete') {
    pass54($job['state']==='blocked' && $job['reason']==='unproven_reader_page_count' && !$before,'incomplete reader cannot publish or create a draft');
    return;
}
pass54($job['state']==='ready' && $job['published']===0 && (bool)wp_next_scheduled('mbi2_publish',[$id]),'signed result queues WordPress publication');
add_filter('pre_http_request',static function() { throw new RuntimeException('publisher must not perform HTTP'); },100);
global $wpdb;$queryStart=$wpdb->num_queries;$started=microtime(true);
wp_unschedule_hook('mbi2_publish');
do_action('mbi2_publish',$id);
printf("Publication: %.3fs, %d WordPress SQL statements\n",microtime(true)-$started,$wpdb->num_queries-$queryStart);
do_action('mbi2_publish',$id);
$job=Store::read($id);$works=$find('mbi_manga',$d['source']);
if ($mode==='check_duplicate') {
    pass54($job['state']==='blocked' && $job['reason']==='existing_catalogue_record_preserved' && $works===$before && count($works)===1,'new job cannot alter or duplicate an existing work');
    return;
}
pass54($job['state']==='published' && $job['published']===1 && !$before && count($works)===1 && (int)$works[0]===$job['post_id'],'GitHub collector caused one authenticated WordPress publication');
foreach ([1,2] as $n) {
    $chapter=$find('mbi_chapter','https://mangalivre.blog/capitulo/'.$d['slug'].'-'.$n.'/');
    pass54(count($chapter)===1 && get_post_status($chapter[0])==='publish' &&
        (int)wp_get_post_parent_id($chapter[0])===$job['post_id'] && \MBI_Chapter_Integrity::valid($chapter[0]),'chapter '.$n.' published with verified delivery receipt and correct parent');
}
if ($mode==='check_success') { update_option('mbi54_new_post',$job['post_id'],false); }
