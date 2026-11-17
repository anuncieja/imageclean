<?php
/**
 * Ten synthetic manga: WordPress DB publication only.
 * Never run outside disposable CI. No real URLs, no network image validations.
 */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'staging'
 || !defined('MBI_BENCHMARK_CI') || !MBI_BENCHMARK_CI
 || $wpdb->dbname !== 'mbi_benchmark_ci'
 || !defined('MBI_VERSION') || MBI_VERSION !== '1.7.53') {
    throw new RuntimeException('DISPOSABLE_CI_ONLY');
}
set_time_limit(0);
$profiles = ['poucos' => [10,12], 'medios' => [50,20], 'muitos' => [200,24]];
$scenario = (string) getenv('MBI_BENCH_SCENARIO');
if (!isset($profiles[$scenario])) { throw new RuntimeException('INVALID_PROFILE'); }
[$chapters, $pagesCount] = $profiles[$scenario];
$times = []; $totalPages = 0; $queryStart = $wpdb->num_queries;
$start = hrtime(true); $mangas = [];
$base = 'https://example.org/synthetic';
function check_id($x, string $label): int {
    if (is_wp_error($x) || !is_int($x) || $x < 1) { throw new RuntimeException('FAILED_' . $label); }
    return $x;
}
for ($i=1; $i<=10; $i++) {
    $began = hrtime(true);
    $slug = 'mb2bench-' . $scenario . '-' . $i;
    $manga = check_id(wp_insert_post([
       'post_type'=>'mbi_manga', 'post_status'=>'draft',
       'post_title'=>'Synthetic manga ' . $slug,
       'post_name'=>$slug, 'post_content'=>'Test only, no actual source.',
    ], true), 'MANGA');
    update_post_meta($manga, '_mbi_source_url', $base . '/manga/' . $slug . '/');
    update_post_meta($manga, '_mbi_expected_chapters', $chapters);
    update_post_meta($manga, '_mbi_ready', 0);
    $chapterUrls = [];
    for ($c=1; $c<=$chapters; $c++) {
        $chapterUrl = $base . '/capitulo/' . $slug . '-' . $c . '/';
        $chapterUrls[] = $chapterUrl;
        $chapterId = check_id(wp_insert_post([
           'post_type'=>'mbi_chapter', 'post_status'=>'draft',
           'post_title'=>'Chapter ' . $c,
           'post_name'=>$slug . '-chapter-' . $c,
           'post_parent'=>$manga,
        ], true), 'CHAPTER');
        $pages = [];
        for($p=1; $p<=$pagesCount; $p++){
            $pages[] = $base . '/media/' . $slug . '/' . $c . '/' . $p . '.webp';
        }
        update_post_meta($chapterId, '_mbi_source_url', $chapterUrl);
        update_post_meta($chapterId, '_mbi_chapter_number', (string)$c);
        update_post_meta($chapterId, '_mbi_expected_pages', $pagesCount);
        update_post_meta($chapterId, '_mbi_source_image_urls', $pages);
        update_post_meta($chapterId, '_mbi_chapter_page_urls_original', $pages);
        update_post_meta($chapterId, '_mbi_chapter_page_urls', MBI_Chapter_Integrity::delivery($pages));
        update_post_meta($chapterId, '_mbi_target_status', 'publish');
        // Benchmark ONLY: synthetic verification receipt. NEVER for production.
        MBI_Chapter_Integrity::verified($chapterId, 'synthetic_benchmark_only');
        if (!MBI_Chapter_Integrity::valid($chapterId)) { throw new RuntimeException('INTEGRITY_GATE'); }
        check_id(wp_update_post(['ID'=>$chapterId,'post_status'=>'publish'],true), 'PUBLISH_CHAPTER');
        if(get_post_status($chapterId) !== 'publish') { throw new RuntimeException('CHAPTER_NOT_PUBLISHED'); }
        $totalPages += $pagesCount;
    }
    update_post_meta($manga,'_mbi_source_chapter_urls',$chapterUrls);
    update_post_meta($manga,'_mbi_ready',1);
    check_id(wp_update_post(['ID'=>$manga,'post_status'=>'publish'],true),'PUBLISH_MANGA');
    if(get_post_status($manga)!=='publish'){throw new RuntimeException('MANGA_NOT_PUBLISHED');}
    $mangas[]=$manga;
    $times[]= (hrtime(true)-$began)/1e9;
}
$published = 0;
foreach($mangas as $id){
    $published += (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent=%d AND post_type='mbi_chapter' AND post_status='publish'",$id));
}
if(count($mangas)!==10 || $published!==10*$chapters){throw new RuntimeException('PUBLISHED_COUNT_MISMATCH');}
sort($times);
$duration=(hrtime(true)-$start)/1e9;
$report=[
 'kind'=>'synthetic_wp_db_publication_without_real_network',
 'scenario'=>$scenario,'mangas'=>10,'chapters_per_manga'=>$chapters,
 'pages_per_chapter'=>$pagesCount,'chapters_published'=>$published,
 'image_url_references'=>$totalPages,'duration_seconds'=>round($duration,3),
 'manga_mean_seconds'=>round(array_sum($times)/10,3),
 'manga_p50_seconds'=>round(($times[4]+$times[5])/2,3),
 'manga_p95_seconds'=>round($times[9],3),
 'database_queries'=>$wpdb->num_queries-$queryStart,
 'php_peak_mib'=>round(memory_get_peak_usage(true)/1048576,2),
 'origin_requests'=>0,'image_probes'=>0,
 'wp_version'=>get_bloginfo('version'),'php_version'=>PHP_VERSION,
 'mariadb_version'=>$wpdb->db_version(),
 'production_equivalent'=>false
];
$output=(string)getenv('MBI_BENCH_OUT');
if(!str_starts_with($output,'/tmp/mb2bench/')){throw new RuntimeException('INVALID_OUTPUT');}
file_put_contents($output,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo 'MB2BENCH_REPORT '.json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
