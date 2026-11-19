<?php
declare(strict_types=1);
namespace MangaBridge2;

/** Final WordPress writer. No origin, image Worker or GitHub requests here. */
final class Publisher {
    public static function enabled(): bool {
        return Plugin::enabled() && defined('MBI2_EXTERNAL_PUBLISH') && MBI2_EXTERNAL_PUBLISH === true
            && defined('MBI_VERSION') && version_compare(MBI_VERSION, '1.7.53', '>=')
            && function_exists('mbi') && class_exists('\MBI_Chapter_Integrity');
    }

    public static function manifest(array $job, array $state): array {
        $work = $state['work'] ?? null;
        $chapters = $state['chapters'] ?? null;
        $proofs = $state['images'] ?? null;
        if (($state['complete'] ?? false) !== true || !is_array($work) || !is_array($chapters) || !array_is_list($chapters) || !is_array($proofs)
            || ($work['source_url'] ?? '') !== $job['source_url'] || count($chapters) < 1 || count($chapters) > 20
            || ($work['expected_chapters'] ?? 0) !== count($chapters) || count($proofs) > 250) {
            throw new \InvalidArgumentException('incomplete_or_oversized_manifest');
        }
        $title = self::clean($work['title'] ?? null, 1, 250);
        $synopsis = self::clean($work['synopsis'] ?? null, 30, 12000);
        $settings = mbi()->importer->settings();
        $cover = self::image($work['cover_url'] ?? null, $proofs, $settings);
        $numbers = [];
        $urls = [];
        $out = [];
        $parent = wp_parse_url($job['source_url']);
        $slug = trim(substr($parent['path'], strlen('/manga/')), '/');
        foreach ($chapters as $row) {
            if (!is_array($row)) { throw new \InvalidArgumentException('invalid_chapter'); }
            $url = $row['url'] ?? null;
            $p = self::https($url);
            if ($p['host'] !== $parent['host'] || isset($p['query']) ||
                !preg_match('~^/(?:capitulo/[^/]+|manga/' . preg_quote($slug, '~') . '/capitulo-[^/]+)/$~D', $p['path'] ?? '') || isset($urls[$url])) {
                throw new \InvalidArgumentException('foreign_or_duplicate_chapter');
            }
            $number = $row['number'] ?? null;
            if (!is_string($number) || !preg_match('/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{0,3}[1-9])?$/D', $number) || isset($numbers[$number])) {
                throw new \InvalidArgumentException('ambiguous_chapter_number');
            }
            $pages = $row['pages'] ?? null;
            if (!is_array($pages) || !array_is_list($pages) || !$pages || count($pages) > 100 || count($pages) !== ($row['expected_pages'] ?? 0)) {
                throw new \InvalidArgumentException('incomplete_pages');
            }
            $pages = array_map(static fn($p) => self::image($p, $proofs, $settings), $pages);
            if (count(array_unique($pages)) !== count($pages)) { throw new \InvalidArgumentException('duplicate_pages'); }
            $numbers[$number] = true; $urls[$url] = true;
            $out[] = compact('url','number','pages');
        }
        if (array_keys($urls) !== ($work['chapter_urls'] ?? null)) { throw new \InvalidArgumentException('chapter_inventory_mismatch'); }
        return ['title'=>$title,'synopsis'=>$synopsis,'cover'=>$cover,'chapters'=>$out];
    }

    private static function clean($value, int $min, int $max): string {
        if (!is_string($value) || $value !== trim(wp_strip_all_tags($value)) ||
            preg_match('/(?:javascript:|document\.|window\.|<\s*(?:script|style|iframe)|\bfunction\s*\()/iu', $value)) {
            throw new \InvalidArgumentException('invalid_editorial_text');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length < $min || $length > $max) { throw new \InvalidArgumentException('invalid_editorial_length'); }
        return $value;
    }

    private static function https($url): array {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { throw new \InvalidArgumentException('invalid_url'); }
        $p = wp_parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) ||
            isset($p['port']) || isset($p['fragment']) || !\MBI_Image_Proxy::valid_browser_url($url)) {
            throw new \InvalidArgumentException('invalid_https_url');
        }
        return $p;
    }

    private static function image($url, array $proofs, array $settings): string {
        $p = self::https($url);
        $allowed = array_merge(Plugin::hosts(), array_filter(array_map('trim', explode(',', defined('MBI2_ALLOWED_MEDIA_HOSTS') ? strtolower((string) MBI2_ALLOWED_MEDIA_HOSTS) : ''))));
        $proof = $proofs[$url] ?? null;
        if (!in_array(strtolower($p['host']), $allowed, true) || strtolower($p['host']) === 'img.lermangaonline.com.br'
            || \MBI_Image_Proxy::is_proxy_url($url, $settings) || \MBI_Parser::looks_like_placeholder_url($url)
            || !is_array($proof) || ($proof['mode'] ?? '') !== 'origin_range_header'
            || !is_string($proof['sha256_prefix'] ?? null) || !preg_match('/^[0-9a-f]{64}$/D', $proof['sha256_prefix'])
            || !is_int($proof['width'] ?? null) || !is_int($proof['height'] ?? null) ||
            min($proof['width'], $proof['height']) < 1 || max($proof['width'], $proof['height']) < 40) {
            throw new \InvalidArgumentException('image_proof_missing_or_invalid');
        }
        return $url;
    }

    private static function sourceKey(string $url): string {
        $p = wp_parse_url($url);
        return md5('https://' . preg_replace('/^www\./i', '', strtolower($p['host'])) . trailingslashit($p['path']));
    }

    /** Resolve the whole job in one read per record type, including old source URL variants. */
    private static function lookup(string $type, array $urls): array {
        global $wpdb;
        $keys = $variants = [];
        foreach ($urls as $url) {
            $p = wp_parse_url($url);
            $hosts = $type === 'mbi_manga' ? Plugin::hosts() : [$p['host'], preg_replace('/^www\./i','',$p['host']), 'www.' . preg_replace('/^www\./i','',$p['host'])];
            foreach ($hosts as $host) {
                $keys[self::sourceKey('https://' . $host . $p['path'])][] = $url;
                foreach (['http','https'] as $scheme) {
                    $value = $scheme . '://' . $host . $p['path'];
                    $variants[$value][] = $url;
                    $variants[untrailingslashit($value)][] = $url;
                }
            }
        }
        $ids = [];
        foreach (['_mbi_source_key'=>$keys, '_mbi_source_url'=>$variants] as $key=>$map) {
            $marks = implode(',', array_fill(0,count($map),'%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID,m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id
                    WHERE p.post_type=%s AND m.meta_key=%s AND m.meta_value IN ($marks)",
                array_merge([$type,$key],array_keys($map))
            ));
            if ($wpdb->last_error !== '') { throw new \RuntimeException('catalogue_lookup_failed'); }
            foreach ($rows as $row) {
                foreach ($map[$row->meta_value] ?? [] as $url) { $ids[$url][(int)$row->ID] = (int)$row->ID; }
                // MariaDB's default collation can compare values equal with different case.
                // An ambiguous match must stop instead of risking a duplicate.
                if (!isset($map[$row->meta_value])) { throw new \DomainException('existing_catalogue_record_preserved'); }
            }
        }
        return $ids;
    }

    private static function owner(array $ids, string $jobId): int {
        if (!$ids) { return 0; }
        $id = (int)reset($ids);
        if (count($ids)!==1 || get_post_meta($id,'_mbi2_job_id',true)!==$jobId ||
            !in_array(get_post_status($id),['draft','publish'],true)) {
            throw new \DomainException('existing_catalogue_record_preserved');
        }
        return $id;
    }

    /** Never take ownership of an existing local post, even if it is a draft. */
    private static function record(string $type, string $url, array $args, string $jobId, int $owned = 0): int {
        if ($owned) {
            if ((int)wp_get_post_parent_id($owned)!==(int)($args['post_parent']??0)) {
                throw new \DomainException('existing_catalogue_record_preserved');
            }
            return $owned;
        }
        $args['post_type'] = $type; $args['post_status'] = 'draft';
        $args['meta_input'] = ['_mbi2_job_id'=>$jobId,'_mbi_source_key'=>self::sourceKey($url),'_mbi_source_url'=>$url];
        $id=wp_insert_post(wp_slash($args),true);
        if (!is_int($id) || !$id || get_post_meta($id,'_mbi2_job_id',true)!==$jobId) { throw new \RuntimeException('draft_not_saved'); }
        return $id;
    }

    private static function meta(int $id, array $values): void {
        foreach ($values as $k=>$value) {
            update_post_meta($id,$k,wp_slash($value));
            $stored=get_post_meta($id,$k,true);
            if (is_array($value) ? $stored!==$value : (string)$stored!==(string)$value) { throw new \RuntimeException('metadata_not_saved'); }
        }
    }

    public static function publish(array $job, array $manifest): int {
        if (!self::enabled()) { throw new \DomainException('publisher_disabled'); }
        $settings=mbi()->importer->settings();
        if (($settings['publish_status']??'')!=='publish') { throw new \DomainException('wordpress_publication_disabled'); }
        $cover=\MBI_Image_Proxy::protect($manifest['cover'],$settings,false);
        if (!$cover) { throw new \DomainException('cover_delivery_configuration_invalid'); }
        $title=$manifest['title'];
        $owned = self::owner(self::lookup('mbi_manga',[$job['source_url']])[$job['source_url']]??[],$job['id']);
        $chapterMatches = self::lookup('mbi_chapter',array_column($manifest['chapters'],'url'));
        $ownedChapters = [];
        foreach ($chapterMatches as $url=>$matches) {
            $child = self::owner($matches,$job['id']);
            if ($child && (!$owned || (int)wp_get_post_parent_id($child)!==$owned)) {
                throw new \DomainException('existing_catalogue_record_preserved');
            }
            $ownedChapters[$url] = $child;
        }
        $manga=self::record('mbi_manga',$job['source_url'],[
            'post_title'=>'Ler '.$title.' Online Grátis','post_name'=>sanitize_title($title),'post_content'=>wpautop(esc_html($manifest['synopsis']))
        ],$job['id'],$owned);
        if (get_post_status($manga)==='publish') {
            if ((int)get_post_meta($manga,'_mbi_ready',true)===1) { return $manga; }
            throw new \DomainException('public_work_not_owned_as_complete');
        }
        mbi()->importer->ensure_base_categories();
        $term=get_term_by('slug','mangas','category');
        if (!$term || is_wp_error(wp_set_object_terms($manga,(int)$term->term_id,'category'))) { throw new \RuntimeException('category_not_saved'); }
        self::meta($manga,['_mbi_original_title'=>$title,'_mbi_cover_source_url'=>$cover,'_mbi_cover_original_url'=>$manifest['cover'],
            '_mbi_expected_chapters'=>count($manifest['chapters']),'_mbi_source_chapter_urls'=>array_column($manifest['chapters'],'url'),
            '_mbi_target_status'=>'publish','_mbi_validation_status'=>'passed']);
        $children=[];
        try {
            foreach ($manifest['chapters'] as $chapter) {
                $number=$chapter['number'];
                $pages=$chapter['pages'];
                $urls=\MBI_Chapter_Integrity::delivery($pages);
                $proxied=!empty($settings['image_proxy_enabled']);
                if (in_array('', $urls, true) || ($proxied && !\MBI_Chapter_Integrity::signed_proxy_links_valid($pages,$urls))) {
                    throw new \DomainException('chapter_delivery_configuration_invalid');
                }
                $id=self::record('mbi_chapter',$chapter['url'],['post_parent'=>$manga,'post_title'=>'Ler '.$title.' Capítulo '.$number.' Online Grátis',
                    'post_name'=>sanitize_title($title.'-capitulo-'.$number),'menu_order'=>(int)round((float)$number*100)],$job['id'],$ownedChapters[$chapter['url']]??0);
                $children[]=$id;
                if (get_post_status($id)!=='publish') {
                    self::meta($id,['_mbi_chapter_number'=>$number,'_mbi_expected_pages'=>count($pages),'_mbi_delivery_required'=>1,'_mbi_target_status'=>'publish',
                        '_mbi_source_image_urls'=>$pages,'_mbi_chapter_page_urls_original'=>$pages,'_mbi_chapter_page_urls'=>$urls]);
                    \MBI_Chapter_Integrity::verified($id,$proxied?'server_origin_signed_proxy':'server_image_addresses');
                } elseif (get_post_meta($id,'_mbi_source_image_urls',true)!==$pages || get_post_meta($id,'_mbi_chapter_number',true)!==$number) {
                    throw new \DomainException('published_chapter_not_overwritten');
                }
                if (!\MBI_Chapter_Integrity::valid($id)) { throw new \RuntimeException('local_delivery_gate_failed'); }
            }
            foreach ($children as $id) { self::status($id,'publish'); }
            self::meta($manga,['_mbi_ready'=>1]);
            self::status($manga,'publish');
        } catch (\Throwable $e) {
            if (get_post_status($manga)!=='publish') {
                foreach ($children as $id) { if (get_post_status($id)==='publish') { self::status($id,'draft'); } }
                delete_post_meta($manga,'_mbi_ready');
            }
            throw $e;
        }
        return $manga;
    }

    private static function status(int $id, string $status): void {
        if (get_post_status($id) === $status) { return; }
        $r=wp_update_post(['ID'=>$id,'post_status'=>$status],true);
        if (is_wp_error($r) || get_post_status($id)!==$status) { throw new \RuntimeException('wordpress_status_not_confirmed'); }
    }
}
