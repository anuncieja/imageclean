<?php
use MangaBridge2\{Batch,Job,Store};
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(get_option('siteurl'),PHP_URL_HOST)!=='127.0.0.1') {
    throw new RuntimeException('Disposable CI only');
}
function check56($ok,string $name):void { if(!$ok)throw new RuntimeException($name); echo "PASS $name\n"; }
function snap56(): array {
    $id=(int)get_option('mbi56_existing');
    return [get_post($id,ARRAY_A),get_post_meta($id),wp_get_object_terms($id,'category',['fields'=>'slugs'])];
}
$host='mangalivre.blog';$prefix="https://$host";
$one=getenv('MBI56_PHASE'); $file=getenv('MBI56_TEST_STATE');
if ($one==='baseline') {
    check56(MBI_VERSION==='1.7.55','1.7.55 starts as baseline');
    $id=wp_insert_post(['post_type'=>'mbi_manga','post_status'=>'publish','post_name'=>'preexisting-56','post_title'=>'Preserved title','meta_input'=>[
        '_mbi_source_url'=>"$prefix/manga/mbi-existing/", '_mbi_cover_source_url'=>'https://example.org/kept.png','_mbi_ready'=>1]],true);
    check56(is_int($id) && $id>0,'seeded published work');
    update_option('mbi56_existing',$id,false);
    update_option('mbi56_snapshot',hash('sha256',wp_json_encode(snap56())),false);
    return;
}
if (in_array($one,['disabled','rollback','verify_main','verify_partial'],true)) {
    check56(hash('sha256',wp_json_encode(snap56()))===get_option('mbi56_snapshot'),'existing work, slug, metadata and cover unchanged');
}
if ($one==='disabled') {
    check56(MBI_VERSION==='1.7.56' && !Batch::enabled(),'build upgrades with batches disabled');
    check56(!isset(rest_get_server()->get_routes()['/mbi2/v1/batches/(?P<id>[a-f0-9-]{36})']),'disabled batch REST route absent'); return;
}
if ($one==='rollback') {
    check56(MBI_VERSION==='1.7.55' && !class_exists(\MangaBridge2\StreamWork::class),'rollback starts previous version');
    $found=wp_count_posts('mbi_manga');
    check56((int)$found->publish===109,'all complete published works survive rollback');
    printf("Environment: WordPress %s; PHP %s; MariaDB %s\n",get_bloginfo('version'),PHP_VERSION,$GLOBALS['wpdb']->db_version());
    return;
}
if (str_starts_with($one,'seed_')) {
    $scenario=substr($one,5);
    $target=$scenario==='main'?101:20;
    check56(Batch::enabled(),'production profile recognizes configured site');
    $id=wp_generate_uuid4();
    $seed=Batch::seed($id,$target,$host);
    if ($scenario==='main') { $seed['slices']=240; $seed['created']-=2*DAY_IN_SECONDS; }
    $ticket=Store::reserve($id,true);
    check56($ticket && Store::create($seed),'production batch reserved');
    Store::finish($ticket,true);
    check56(!Store::reserve(wp_generate_uuid4(),true),'second live batch refused');
    file_put_contents($file,wp_json_encode(compact('id','scenario')));
    return;
}
$d=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
$id=$d['id'];
$job=Batch::get($id);
if ($one==='tick') {
    if (in_array($job['state'],['published','blocked'],true)) { echo $job['state']; return; }
    // The suite advances the due time; no control path depends on an admin browser.
    if ($job['state']==='queued' && $job['not_before']>0) {
        $ready=$job; $ready['not_before']=0;
        if (!Store::swap($job,$ready)) { throw new RuntimeException('cannot advance test clock'); }
        $job=$ready;
    }
    $sends=0;
    add_filter('pre_http_request',static function($pre,$args,$url)use($id,&$sends) {
        if ($url!=='https://api.github.com/repos/anuncieja/imageclean/actions/workflows/manga-bridge-batch-stream.yml/dispatches') {
            throw new RuntimeException('WordPress tried to contact the origin instead of the workflow');
        }
        $body=json_decode($args['body'],true,32,JSON_THROW_ON_ERROR);
        if ($body!==['ref'=>'main','inputs'=>['job_id'=>$id,'generation'=>Batch::get($id)['generation']]]) {
            throw new RuntimeException('incorrect batch dispatch');
        }
        $sends++;
        return ['headers'=>[], 'body'=>'','response'=>['code'=>204],'cookies'=>[]];
    },100,3);
    if ($job['state']==='received') {
        $before=(int)wp_count_posts('mbi_manga')->publish;
    }
    wp_unschedule_hook('mbi2_batch_tick');
    do_action('mbi2_batch_tick',$id);
    $after=Batch::get($id);
    if ($job['state']==='received' && $after['state']==='received' && $after['reason']==='wordpress_drafts_in_progress') {
        if ($before!==(int)wp_count_posts('mbi_manga')->publish || !$after['stream_work']['prepared']) {
            throw new RuntimeException('partial draft would count or become public');
        }
        update_option('mbi56_chunk_seen',true,false);
    }
    if ($after['state']==='dispatching') {
        do_action('mbi2_batch_tick',$id);
        if ($sends!==1) { throw new RuntimeException('duplicate workflow dispatch'); }
        $d['generation']=$after['generation'];
        file_put_contents($file,wp_json_encode($d));
    }
    echo $after['state'];
    return;
}
if ($one==='verify_main') {
    check56($job['state']==='published' && $job['published']===101 && (int)wp_count_posts('mbi_manga')->publish===102,'101 requested and 101 new complete works published');
    check56($job['ignored']===1 && $job['rejected']===1 && $job['slices']>240,'catalogue duplicates ignored and rejected candidate substituted across slices');
    check56((bool)get_option('mbi56_chunk_seen'),'large works prepared across PHP requests while still draft');
    check56($job['created']<time()-DAY_IN_SECONDS,'new job continued beyond the old lifetime and round budgets');
    $ids=PublisherTest56("$prefix/manga/mbi-work-4/");
    check56(count($ids)===1,'176-chapter work published once');
    $children=mbi_get_manga_chapters((int)$ids[0]);
    check56(count($children)===176 && array_reduce($children,static fn($ok,$post)=>$ok && MBI_Chapter_Integrity::valid($post->ID),true),'all 176 chapter receipts and relationships verified');
} elseif ($one==='verify_partial') {
    check56($job['state']==='blocked' && $job['reason']==='catalogue_exhausted' && $job['published']===7 && $job['target']===20,'second batch correctly reports 7/20 when catalogue is exhausted');
    check56($job['ignored']===102 && (int)wp_count_posts('mbi_manga')->publish===109,'second batch excludes existing publications in groups');
}
echo 'BATCH '.wp_json_encode(array_intersect_key($job,array_flip(['state','target','published','ignored','rejected','slices'])))."\n";
function PublisherTest56(string $url):array {
    return get_posts(['post_type'=>'mbi_manga','post_status'=>'publish','posts_per_page'=>2,'fields'=>'ids',
        'meta_key'=>'_mbi_source_url','meta_value'=>$url]);
}
