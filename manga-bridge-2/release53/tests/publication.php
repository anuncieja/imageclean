<?php
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'staging' || MBI_VERSION !== '1.7.53') {
    throw new RuntimeException('Disposable CI staging only');
}
function publish_check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}
function fixture_ids(string $type, string $url): array {
    return get_posts(['post_type'=>$type,'post_status'=>['draft','publish','pending','private'],
        'meta_key'=>'_mbi_source_url','meta_value'=>$url,'posts_per_page'=>-1,'fields'=>'ids']);
}
$importer=mbi()->importer;
$importer->stop_all_and_reset(); // Only the disposable queue created by this CI test.
$settings=$importer->settings();
$settings['continuous_worker']=0;
$settings['source_url']='https://mangalivre.blog';
$settings['image_proxy_enabled']=0;
update_option('mbi_settings',$settings,false);
$host='https://mangalivre.blog';
$work=$host.'/manga/ci-serial/';
$chapter=$host.'/capitulo/ci-serial-1/';
$next=$host.'/capitulo/ci-serial-2/';
$cover=$host.'/wp-content/uploads/ci-cover.png';
$page=$host.'/wp-content/uploads/ci-page-1.png';
$page2=$host.'/wp-content/uploads/ci-page-2.png';
$requests=[];
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAY0lEQVR4nO3PQQ3AIADAQEAhIhCB/IngcVnSU9DOfe74s6UDXjWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgfcy8AawqxZn3AAAAAElFTkSuQmCC',true);
add_filter('pre_http_request', static function ($pre,$args,$url) use (&$requests,$work,$chapter,$next,$cover,$page,$page2,$png) {
    $requests[]=$url;
    if ($url===$work) {
        $body='<meta name="description" content="Uma sinopse fictícia suficientemente detalhada para verificar a publicação de uma obra no ambiente de testes."><h1 class="manga-title">CI Serial Manga</h1><div class="manga-cover"><img src="'.$cover.'"></div><span class="manga-chapters-count">1 capítulo</span><ul class="chapters-list"><li><a href="'.$chapter.'">Capítulo 1</a></li></ul>';
        $mime='text/html';
    } elseif ($url===$chapter || $url===$next) {
        $number=$url===$chapter ? 1 : 2;
        $image=$url===$chapter ? $page : $page2;
        $body='<meta property="og:title" content="CI Serial Manga Capítulo '.$number.'"><h1>Capítulo '.$number.'</h1><div class="reading-content"><img src="'.$image.'" alt="Página 1"></div>';
        $mime='text/html';
    } elseif (in_array($url,[$cover,$page,$page2],true)) {
        $mime='image/png';$body=$png;
    } else {
        throw new RuntimeException('Unexpected outbound HTTP: '.wp_parse_url($url,PHP_URL_HOST));
    }
    return ['headers'=>['content-type'=>$mime],'body'=>$body,'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
}, 100, 3);
$originals=get_posts(['post_type'=>'mbi_manga','name'=>'ci-preserved-slug','fields'=>'ids']);
publish_check(count($originals)===1,'preexisting title still available');
$original=(int)$originals[0];
$original_cover=get_post_meta($original,'_mbi_cover_source_url',true);
$go=$importer->enqueue_manual_manga_url($work);
publish_check(!empty($go['queued']),'manual URL queued through public importer method');
publish_check(wp_next_scheduled('mbi_process_queue')!==false,'importer has server cron hook');
$importer->pause();
publish_check($importer->process_queue()['claimed']===0 && !$requests,'pause stops queue without HTTP');
$importer->resume();
function queue_rounds(MBI_Importer $importer, callable $done): void {
    for ($round=0;$round<8 && !$done();$round++) {
        // Skip only the fixed two-second inter-round pause in this synthetic CI.
        delete_option('mbi_publication_not_before');
        do_action('mbi_process_queue');
        if (!$done()) { usleep(500000); }
    }
}
queue_rounds($importer, static fn()=>empty($importer->serial_state()['active']));
$ids=fixture_ids('mbi_manga',$work);
if (!$ids || get_post_status($ids[0])!=='publish') {
    echo 'Import failure fixture: '.wp_json_encode(['serial'=>$importer->serial_state(),'requests'=>$requests,'counts'=>mbi()->db->counts(),'logs'=>mbi()->db->recent_logs(8)],JSON_UNESCAPED_UNICODE)."\n";
}
publish_check(count($ids)===1 && get_post_status($ids[0])==='publish','manual import published one validated manga');
$parent=(int)$ids[0];
$chapter_ids=fixture_ids('mbi_chapter',$chapter);
publish_check(count($chapter_ids)===1 && get_post_status($chapter_ids[0])==='publish' &&
    wp_get_post_parent_id($chapter_ids[0])===$parent && MBI_Chapter_Integrity::valid($chapter_ids[0]),'chapter was published with parent and a valid image receipt');
publish_check((int)$importer->serial_state()['completed']===1,'quota counted one actual publication');
publish_check(count(array_filter($requests,static fn($url)=>$url===$page))===1,'chapter image was fetched exactly once');
$key=md5($next);
update_option('mbi_detected_updates',[$key=>['manga_id'=>$parent,'manga_name'=>'CI Serial Manga','manga_url'=>$work,'chapter_url'=>$next,'chapter_number'=>'2']],false);
do_action('mbi_process_queue');
publish_check(!fixture_ids('mbi_chapter',$next),'detected chapter does not publish without explicit selection');
$manual=$importer->enqueue_detected_updates([$key]);
publish_check((int)$manual['queued']===1,'administrator selection accepted one detected chapter');
do_action('mbi_prepare_manual_updates');
queue_rounds($importer, static fn()=>!empty(fixture_ids('mbi_chapter',$next)) && get_post_status(fixture_ids('mbi_chapter',$next)[0])==='publish');
$next_ids=fixture_ids('mbi_chapter',$next);
publish_check(count($next_ids)===1 && get_post_status($next_ids[0])==='publish' && wp_get_post_parent_id($next_ids[0])===$parent,'selected new chapter published with correct parent');
publish_check(fixture_ids('mbi_chapter',$chapter)===$chapter_ids && get_post_status($chapter_ids[0])==='publish','new chapter preserved old chapter');
publish_check(get_post_status($original)==='publish' && get_post_meta($original,'_mbi_cover_source_url',true)===$original_cover,'unrelated manga and cover preserved');
publish_check(!array_filter($requests,static fn($url)=>wp_parse_url($url,PHP_URL_HOST)==='img.lermangaonline.com.br'),'no request to public Worker');
echo 'Controlled fixture HTTP requests: '.count($requests)."\n";
