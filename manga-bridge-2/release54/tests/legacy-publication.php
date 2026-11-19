<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(get_option('siteurl'),PHP_URL_HOST) !== '127.0.0.1' || MBI_VERSION !== '1.7.54') {
    throw new RuntimeException('Disposable CI only');
}
function publish_check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}
function fixture_ids(string $type, string $url): array {
    return get_posts(['post_type'=>$type,'post_status'=>['draft','publish','pending','private'],
        'meta_key'=>'_mbi_source_url','meta_value'=>$url,'posts_per_page'=>-1,'fields'=>'ids']);
}
$saved_settings=get_option('mbi_settings');
$importer=mbi()->importer;
$importer->stop_all_and_reset(); // Only the disposable queue created by this CI test.
$settings=$importer->settings();
$settings['continuous_worker']=0;
$settings['source_url']='https://mangalivre.blog';
$settings['image_proxy_enabled']=0;
update_option('mbi_settings',$settings,false);
$host='https://mangalivre.blog';
$work=$host.'/manga/mbi54-first/';
$chapter=$host.'/capitulo/mbi54-first-1/';
$next=$host.'/capitulo/mbi54-first-3/';
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
        $number=$url===$chapter ? 1 : 3;
        $image=$url===$chapter ? $page : $page2;
        $body='<meta property="og:title" content="CI Serial Manga Capítulo '.$number.'"><h1>Capítulo '.$number.'</h1><div class="chapter-content"><div class="chapter-image-container" data-page="1"><img class="chapter-image" src="'.$image.'" alt="Página 1"><span class="page-number">1/1</span></div></div>';
        $mime='text/html';
    } elseif (in_array($url,[$cover,$page,$page2],true)) {
        $mime='image/png';$body=$png;
    } else {
        throw new RuntimeException('Unexpected outbound HTTP: '.wp_parse_url($url,PHP_URL_HOST));
    }
    return ['headers'=>['content-type'=>$mime],'body'=>$body,'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
}, 100, 3);
$originals=get_posts(['post_type'=>'mbi_manga','name'=>'existing-ci-preserved','fields'=>'ids']);
publish_check(count($originals)===1,'preexisting title still available');
$original=(int)$originals[0];
$original_cover=get_post_meta($original,'_mbi_cover_source_url',true);
$parent=(int)get_option('mbi54_new_post');
$chapter_ids=fixture_ids('mbi_chapter',$chapter);
publish_check(get_post_status($parent)==='publish' && get_post_meta($parent,'_mbi_source_url',true)===$work
    && count($chapter_ids)===1 && MBI_Chapter_Integrity::valid($chapter_ids[0]),'chapter update starts with the work published by GitHub');
function queue_rounds(MBI_Importer $importer, callable $done): void {
    for ($round=0;$round<16 && !$done();$round++) {
        delete_option('mbi_publication_not_before');
        do_action('mbi_process_queue');
        if (!$done()) { usleep(500000); }
    }
}
$key=md5($next);
update_option('mbi_detected_updates',[$key=>['manga_id'=>$parent,'manga_title'=>'CI Serial Manga','manga_url'=>$work,'chapter_url'=>$next,'chapter_number'=>'3']],false);
do_action('mbi_process_queue');
publish_check(!fixture_ids('mbi_chapter',$next),'detected chapter does not publish without explicit selection');
$manual=$importer->enqueue_detected_updates([$key]);
publish_check((int)$manual['queued']===1,'administrator selection accepted one detected chapter');
do_action('mbi_prepare_manual_updates');
queue_rounds($importer, static fn()=>!empty(fixture_ids('mbi_chapter',$next)) && get_post_status(fixture_ids('mbi_chapter',$next)[0])==='publish');
$next_ids=fixture_ids('mbi_chapter',$next);
if (!$next_ids || get_post_status($next_ids[0])!=='publish') {
    echo 'CI Manual state: '.wp_json_encode($importer->manual_update_progress())."\n";
    echo 'CI Manual queue logs: '.wp_json_encode(mbi()->db->recent_logs(8))."\n";
}
publish_check(count($next_ids)===1 && get_post_status($next_ids[0])==='publish' && wp_get_post_parent_id($next_ids[0])===$parent,'selected new chapter published with correct parent');
publish_check(fixture_ids('mbi_chapter',$chapter)===$chapter_ids && get_post_status($chapter_ids[0])==='publish','new chapter preserved old chapter');
publish_check(get_post_status($original)==='publish' && get_post_meta($original,'_mbi_cover_source_url',true)===$original_cover,'unrelated manga and cover preserved');
publish_check(!array_filter($requests,static fn($url)=>wp_parse_url($url,PHP_URL_HOST)==='img.lermangaonline.com.br'),'no request to public Worker');
echo 'Controlled fixture HTTP requests: '.count($requests)."\n";

if ($saved_settings===false) { delete_option('mbi_settings'); } else { update_option('mbi_settings',$saved_settings,false); }
