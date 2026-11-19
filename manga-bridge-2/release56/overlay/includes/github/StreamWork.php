<?php
declare(strict_types=1);
namespace MangaBridge2;

/** Work data is accepted in ordered packets; the summary keeps only cursors. */
final class StreamWork {
    private const QUANTUM=12;
    public static function open(string $candidate,array $e): array {
        $data=$e['work']??null;
        if (!is_array($data) || ($data['source_url']??'')!==$candidate || !is_int($data['expected_chapters']??null) ||
            $data['expected_chapters']<1 || !is_array($e['proofs']??null)) {
            throw new \InvalidArgumentException('invalid_stream_work');
        }
        $title=Publisher::clean($data['title']??null,1,250);
        $synopsis=Publisher::clean($data['synopsis']??null,30,12000);
        $cover=Publisher::image($data['cover_url']??null,$e['proofs'],mbi()->importer->settings());
        return ['scope'=>wp_generate_uuid4(),'title'=>$title,'synopsis'=>$synopsis,'cover'=>$cover,
            'expected'=>$data['expected_chapters'],'inventory'=>0,'tip'=>'','checked'=>0,'images'=>0,'reader'=>[],
            'manga'=>0,'prepared'=>0,'released'=>0];
    }
    private static function chapterUrl(string $source,$value): string {
        $p=Publisher::https($value);
        $parent=wp_parse_url($source);
        $slug=trim(substr($parent['path'],strlen('/manga/')),'/');
        if ($p['host']!==$parent['host'] || isset($p['query']) ||
            !preg_match('~^/(?:capitulo/[^/]+|manga/'.preg_quote($slug,'~').'/capitulo-[^/]+)/$~D',$p['path']??'')) {
            throw new \InvalidArgumentException('foreign_chapter');
        }
        return $value;
    }
    public static function inventory(array &$work,string $candidate,array $e): void {
        $offset=$work['inventory']; $urls=$e['urls']??null;
        if (($e['offset']??null)!==$offset || !is_array($urls) || !array_is_list($urls) || !$urls
            || count($urls)>32 || count($urls)>$work['expected']-$offset || $work['checked']) {
            throw new \InvalidArgumentException('invalid_inventory_packet');
        }
        $entries=[];
        foreach ($urls as $url) { $url=self::chapterUrl($candidate,$url); $entries[]=[$url,$url]; }
        StreamRows::append($work['scope'],'inventory',$offset,$entries);
        $work['inventory']+=count($entries);
        $work['tip']=$urls[count($urls)-1];
    }
    public static function nextUrls(array $work): array {
        if (!$work || $work['inventory']!==$work['expected']) { return []; }
        return array_values(StreamRows::range($work['scope'],'inventory',$work['checked'],self::QUANTUM));
    }
    public static function pages(array &$work,array $e): void {
        $blocks=$e['parts']??null; $proofs=$e['proofs']??null;
        if ($work['inventory']!==$work['expected'] || !is_array($blocks) || !array_is_list($blocks) || !$blocks
            || count($blocks)>self::QUANTUM || !is_array($proofs) || count($proofs)>80) {
            throw new \InvalidArgumentException('invalid_page_packet');
        }
        $settings=mbi()->importer->settings();
        $accepted=[];
        $completed=[];
        $startChapter=$work['checked'];
        $startImage=$work['images'];
        $urls=StreamRows::range($work['scope'],'inventory',$startChapter,self::QUANTUM);
        foreach ($blocks as $block) {
            if (!is_array($block) || $work['checked'] >= $work['expected'] || ($block['index']??null)!==$work['checked'] ||
                ($block['url']??null)!==($urls[$work['checked']]??null)) {
                throw new \InvalidArgumentException('chapter_inventory_mismatch');
            }
            $number=$block['number']??null; $total=$block['total']??null; $identity=$block['identity']??null;
            $pages=$block['pages']??null;
            if (!is_string($number) || !preg_match('/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{0,3}[1-9])?$/D',$number) ||
                !is_int($total) || $total<1 || !is_string($identity) || !preg_match('/^[a-f0-9]{64}$/D',$identity) ||
                !is_array($pages) || !array_is_list($pages) || !$pages || count($pages)>80-count($accepted)) {
                throw new \InvalidArgumentException('invalid_chapter_pages');
            }
            $reader=$work['reader']?:['number'=>$number,'total'=>$total,'identity'=>$identity,'first'=>$work['images'],'have'=>0];
            if ($reader['identity']!==$identity || $reader['number']!==$number || $reader['total']!==$total ||
                ($block['offset']??null)!==$reader['have'] || count($pages)>$reader['total']-$reader['have']) {
                throw new \InvalidArgumentException('reader_changed_during_collection');
            }
            foreach ($pages as $page) {
                $url=Publisher::image($page,$proofs,$settings);
                $accepted[]=[$work['checked']."\n".$url,$url];
            }
            $reader['have']+=count($pages);
            $work['images']+=count($pages);
            if ($reader['have']===$reader['total']) {
                $completed[]=[$number,['url'=>$block['url'],'number'=>$number,'first'=>$reader['first'],'total'=>$total]];
                $work['checked']++; $work['reader']=[];
            } else { $work['reader']=$reader; }
        }
        StreamRows::append($work['scope'],'images',$startImage,$accepted);
        StreamRows::append($work['scope'],'chapters',$startChapter,$completed);
    }
    public static function seal(array $work): void {
        if (!$work || $work['inventory']!==$work['expected'] || $work['checked']!==$work['expected'] || $work['reader']) {
            throw new \InvalidArgumentException('incomplete_work');
        }
    }
    public static function publish(array &$work,string $candidate): int {
        self::seal($work);
        $settings=mbi()->importer->settings();
        if (($settings['publish_status']??'')!=='publish') { throw new \DomainException('wordpress_publication_disabled'); }
        $scope=$work['scope']; $title=$work['title'];
        $manga=$work['manga'];
        if ($manga) {
            if (get_post_meta($manga,'_mbi2_job_id',true)!==$scope ||
                get_post_meta($manga,'_mbi_source_url',true)!==$candidate) { throw new \DomainException('existing_catalogue_record_preserved'); }
        } else {
            $owned=Publisher::owner(Publisher::lookup('mbi_manga',[$candidate])[$candidate]??[],$scope);
            $manga=Publisher::record('mbi_manga',$candidate,['post_title'=>'Ler '.$title.' Online Grátis',
                'post_name'=>sanitize_title($title),'post_content'=>wpautop(esc_html($work['synopsis']))],$scope,$owned);
        }
        if (get_post_status($manga)==='publish' && (int)get_post_meta($manga,'_mbi_ready',true)===1) { return $manga; }
        if (get_post_status($manga)!=='draft') { throw new \DomainException('parent_status_changed'); }
        if (!$work['prepared']) {
            $cover=\MBI_Image_Proxy::protect($work['cover'],$settings,false);
            if (!$cover) { throw new \DomainException('cover_delivery_configuration_invalid'); }
            mbi()->importer->ensure_base_categories();
            $term=get_term_by('slug','mangas','category');
            if (!$term || is_wp_error(wp_set_object_terms($manga,(int)$term->term_id,'category'))) { throw new \RuntimeException('category_not_saved'); }
            Publisher::meta($manga,['_mbi_original_title'=>$title,'_mbi_cover_source_url'=>$cover,'_mbi_cover_original_url'=>$work['cover'],
                '_mbi_expected_chapters'=>$work['expected'],'_mbi_target_status'=>'publish','_mbi_validation_status'=>'passed']);
        }
        $work['manga']=$manga;
        if ($work['prepared']<$work['expected']) {
            self::prepare($work,$manga,$settings);
            if ($work['prepared']<$work['expected']) { return 0; }
        }
        if ($work['released']<$work['expected']) {
            self::release($work,$manga);
            if ($work['released']<$work['expected']) { return 0; }
        }
        $inventory=StreamRows::range($scope,'inventory',0,$work['expected']);
        if (count($inventory)!==$work['expected']) { throw new \RuntimeException('inventory_changed_before_publish'); }
        Publisher::meta($manga,['_mbi_source_chapter_urls'=>array_values($inventory),'_mbi_ready'=>1]);
        Publisher::status($manga,'publish');
        return $manga;
    }
    private static function prepare(array &$work,int $manga,array $settings): void {
        $start=$work['prepared']; $scope=$work['scope'];
        $part=StreamRows::range($scope,'chapters',$start,self::QUANTUM);
        if (!$part || !isset($part[$start])) { throw new \RuntimeException('chapter_packet_lost'); }
        $ids=Publisher::lookup('mbi_chapter',array_column($part,'url'));
        $entries=[];
        $proxied=!empty($settings['image_proxy_enabled']);
        foreach ($part as $pos=>$r) {
            if ($pos!==$start+count($entries)) { throw new \RuntimeException('chapter_gap'); }
            if ($entries && \MBI_Resource_Guard::remaining()<3) { break; }
            $pages=array_values(StreamRows::range($scope,'images',$r['first'],$r['total']));
            if (count($pages)!==$r['total']) { throw new \RuntimeException('image_packet_lost'); }
            $delivery=\MBI_Chapter_Integrity::delivery($pages);
            if (in_array('',$delivery,true) || ($proxied && !\MBI_Chapter_Integrity::signed_proxy_links_valid($pages,$delivery))) {
                throw new \DomainException('chapter_delivery_configuration_invalid');
            }
            $number=$r['number']; $title=$work['title'];
            $old=Publisher::owner($ids[$r['url']]??[],$scope);
            $id=Publisher::record('mbi_chapter',$r['url'],['post_parent'=>$manga,
                'post_title'=>'Ler '.$title.' Capítulo '.$number.' Online Grátis',
                'post_name'=>sanitize_title($title.'-capitulo-'.$number),'menu_order'=>(int)round((float)$number*100)],$scope,$old);
            if (get_post_status($id)!=='publish') {
                Publisher::meta($id,['_mbi_chapter_number'=>$number,'_mbi_expected_pages'=>count($pages),'_mbi_delivery_required'=>1,
                    '_mbi_target_status'=>'publish','_mbi_source_image_urls'=>$pages,
                    '_mbi_chapter_page_urls_original'=>$pages,'_mbi_chapter_page_urls'=>$delivery]);
                \MBI_Chapter_Integrity::verified($id,$proxied?'server_origin_signed_proxy':'server_image_addresses');
            }
            if (!\MBI_Chapter_Integrity::valid($id)) { throw new \RuntimeException('local_delivery_gate_failed'); }
            $entries[]=[(string)$id,$id];
        }
        StreamRows::append($scope,'prepared',$start,$entries);
        $work['prepared']+=count($entries);
    }
    private static function release(array &$work,int $manga): void {
        $start=$work['released']; $scope=$work['scope'];
        $part=StreamRows::range($scope,'prepared',$start,self::QUANTUM);
        if (!$part || !isset($part[$start])) { throw new \RuntimeException('prepared_packet_lost'); }
        foreach ($part as $pos=>$id) {
            if ($pos!==$work['released'] || (int)wp_get_post_parent_id($id)!==$manga ||
                get_post_meta($id,'_mbi2_job_id',true)!==$scope || !\MBI_Chapter_Integrity::valid($id)) {
                throw new \RuntimeException('draft_receipt_changed');
            }
            if ($work['released']>$start && \MBI_Resource_Guard::remaining()<3) { break; }
            Publisher::status($id,'publish');
            $work['released']++;
        }
    }
}
