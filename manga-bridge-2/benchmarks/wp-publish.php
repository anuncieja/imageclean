<?php
/**
 * Synthetic WordPress database write benchmark. Never call against a real site.
 * It is NOT the real Manga Bridge publisher and does NOT verify remote images.
 */
if ( ! defined('WP_CLI') || ! WP_CLI || wp_get_environment_type() !== 'staging'
     || getenv('MB2_DISPOSABLE_BENCH') !== 'I_UNDERSTAND_DISPOSABLE' ) {
    throw new RuntimeException('Benchmark blocked: disposable WP-CLI staging only.');
}
if ( ! defined('MBI_VERSION') || MBI_VERSION !== '1.7.53' ) {
    throw new RuntimeException('Importer 1.7.53 not loaded.');
}
ini_set('memory_limit', '512M');
global $wpdb;
$cases = [
    'poucos' => ['works' => 10, 'chapters' => 10, 'pages' => 12],
    'medios' => ['works' => 10, 'chapters' => 60, 'pages' => 20],
    'muitos' => ['works' => 10, 'chapters' => 200, 'pages' => 25],
];
$result = [
    'benchmark' => 'synthetic_wp_post_and_postmeta_writes_only',
    'tested_environment' => [
        'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION,
        'mariadb' => $wpdb->db_version(), 'importer' => MBI_VERSION,
    ],
    'limitations' => [
        'No source HTTP, image download, image verification, proxy, WordPress importer queue or real end-to-end publication.',
        'Fixtures are synthetic. New posts use publish status, not a validated delivery receipt.',
        'Single WP-CLI process and disposable MariaDB; hosting performance is not measured.',
        'The three cases run sequentially in the same disposable database.',
    ],
    'cases' => [],
];
foreach ( $cases as $label => $case ) {
    $count_posts = 0;
    $count_chapters = 0;
    $count_pages = 0;
    $manga_times = [];
    $started = hrtime(true);
    $start_queries = $wpdb->num_queries;
    for ( $m = 1; $m <= $case['works']; $m++ ) {
        $mstart = hrtime(true);
        $title = sprintf('Benchmark %s obra %02d', $label, $m);
        $manga_id = wp_insert_post([
            'post_type' => 'mbi_manga',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => 'Synthetic benchmark; no remote source was accessed.',
            'post_name' => 'mb2-bench-' . $label . '-' . $m
        ], true);
        if ( is_wp_error($manga_id) || !is_int($manga_id) || get_post_status($manga_id) !== 'publish' ) {
            throw new RuntimeException('Failed to create synthetic manga');
        }
        $count_posts++;
        update_post_meta($manga_id, '_mbi_source_url', 'https://example.org/manga/bench-' . $label . '-' . $m . '/');
        update_post_meta($manga_id, '_mbi_cover_source_url', 'https://example.org/media/benchmark/cover.webp');

        for ( $ch = 1; $ch <= $case['chapters']; $ch++ ) {
            $chapter_id = wp_insert_post([
                'post_type' => 'mbi_chapter',
                'post_status' => 'publish',
                'post_parent' => $manga_id,
                'post_title' => sprintf('Capítulo %d - %s', $ch, $title),
                'post_name' => 'mb2-bench-' . $label . '-' . $m . '-' . $ch,
            ], true);
            if ( is_wp_error($chapter_id) || !is_int($chapter_id) || get_post_status($chapter_id) !== 'publish') {
                throw new RuntimeException('Failed to create synthetic chapter');
            }
            $count_chapters++;
            $urls = [];
            for ( $i = 1; $i <= $case['pages']; $i++ ) {
                $urls[] = sprintf('https://example.org/media/benchmark/%s-%02d/%04d/p%03d.webp',
                                  $label, $m, $ch, $i);
            }
            $count_pages += count($urls);
            update_post_meta($chapter_id, '_mbi_source_url', 'https://example.org/capitulo/bench-' . $m . '-' . $ch . '/');
            update_post_meta($chapter_id, '_mbi_chapter_number', (string) $ch);
            update_post_meta($chapter_id, '_mbi_expected_pages', $case['pages']);
            update_post_meta($chapter_id, '_mbi_source_image_urls', $urls);
            update_post_meta($chapter_id, '_mbi_chapter_page_urls', $urls);
        }
        $manga_times[] = round((hrtime(true) - $mstart) / 1000000000, 4);
    }
    $elapsed = (hrtime(true) - $started) / 1000000000;
    $result['cases'][] = [
        'label' => $label,
        'works' => $case['works'],
        'chapters_per_work' => $case['chapters'],
        'pages_per_chapter' => $case['pages'],
        'manga_posts_written' => $count_posts,
        'chapter_posts_written' => $count_chapters,
        'image_urls_stored_in_metadata' => $count_pages,
        'write_elapsed_seconds' => round($elapsed, 3),
        'seconds_per_manga' => $manga_times,
        'sql_query_count' => $wpdb->num_queries - $start_queries,
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2)
    ];
    if ($count_posts !== 10 || $count_chapters !== 10 * $case['chapters'] ||
        $count_pages !== 10 * $case['chapters'] * $case['pages']) {
        throw new RuntimeException('Synthetic accounting mismatch');
    }
    echo sprintf("Benchmark %s: %d mangas, %d chapters, %d page URLs, %.3f seconds, %d SQL queries\n",
        $label, $count_posts, $count_chapters, $count_pages, $elapsed, $wpdb->num_queries - $start_queries);
}
$dest = getenv('MB2_BENCH_REPORT');
if (!$dest || !is_dir(dirname($dest))) { throw new RuntimeException('Output directory missing'); }
file_put_contents($dest, wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Synthetic writes complete. No source HTTP or Cloudflare Worker requests performed.' . "\n";
