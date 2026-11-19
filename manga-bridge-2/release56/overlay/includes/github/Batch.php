<?php
declare(strict_types=1);
namespace MangaBridge2;

/** Production catalogue jobs. Actions reads the origin; WordPress only checks IDs and writes results. */
final class Batch {
    public const CHAPTERS = 120;
    public const IMAGES = 2400;
    private const BLOB = 1120000;
    private const SLICES = 240;
    private const PAGE = 'edit.php?post_type=mbi_manga&page=mbi-github-batch';
    private const TERMINAL = ['published','blocked','cancelled'];
    private const LEASE = 150;
    private const STREAM_FLOW = 'https://api.github.com/repos/anuncieja/imageclean/actions/workflows/manga-bridge-batch-stream.yml/dispatches';
    private const FLOW = 'https://api.github.com/repos/anuncieja/imageclean/actions/workflows/manga-bridge-batch.yml/dispatches';

    public static function boot(): void {
        add_action('rest_api_init', [self::class,'routes']);
        add_action('admin_menu', [self::class,'menu']);
        add_action('admin_post_mbi2_batch_create', [self::class,'create']);
        add_action('admin_post_mbi2_batch_control', [self::class,'control']);
        add_action('wp_ajax_mbi2_batch_status', [self::class,'status']);
        add_action('mbi2_stream_cleanup', [StreamRows::class,'clean']);
        add_action('mbi2_batch_tick', [self::class,'tick']);
        add_action('mbi2_batch_watch', [self::class,'watch'],10,2);
        add_action('admin_enqueue_scripts', static function (): void {
            if (($_GET['page'] ?? '')==='mbi-github-batch') {
                wp_enqueue_script('mbi-github', MBI_URL.'assets/github-status.js', [], MBI_VERSION, true);
            }
        });
    }
    public static function enabled(): bool {
        return defined('MBI2_BATCH_ENABLED') && MBI2_BATCH_ENABLED===true
            && wp_get_environment_type()==='production' && Publisher::enabled();
    }
    public static function seed(string $id, int $target, string $origin): array {
        $origin = strtolower($origin);
        if (!Job::uuid($id) || $target<1 || !in_array($origin,Plugin::hosts(),true)) {
            throw new \InvalidArgumentException('invalid_batch');
        }
        StreamRows::install();
        return ['id'=>$id,'mode'=>'batch','generation'=>wp_generate_uuid4(),'state'=>'queued','revision'=>0,
            'target'=>$target,'published'=>0,'origin'=>$origin,'page'=>1,'streaming'=>true,'page_offset'=>0,'page_signature'=>'','seen_slots'=>0,'stream_work'=>[],
            'exhausted'=>false,'seen'=>[],'queue'=>[],'checkpoint'=>[],'pending'=>[],'reports'=>[],
            'ignored'=>0,'rejected'=>0,'created'=>time(),'slices'=>0,'failures'=>0,'write_failures'=>0,
            'prepared'=>[],'attempt'=>'','lease'=>0,'not_before'=>0,'last_event'=>'','reason'=>''];
    }
    public static function get(string $id): ?array {
        if (!Job::uuid($id)) { return null; }
        $job=Store::read($id);
        return ($job['mode']??'')==='batch' ? $job : null;
    }
    private static function view(array $job, bool $checkpoint = false): array {
        $view=array_intersect_key($job,array_flip(['id','generation','state','target','published','ignored',
            'rejected','origin','page','page_limit','exhausted','slices','reason']));
        $view['candidate']=$job['queue'][0]??'';
        if (!empty($job['streaming'])) {
            $view['page_offset']=$job['page_offset'];
            $view['page_signature']=$job['page_signature'];
            $view['stream']=$job['stream_work'];
            if (in_array($job['state'],['running','dispatching'],true)) { $view['next_urls']=StreamWork::nextUrls($job['stream_work']); }
        } elseif ($checkpoint) { $view['checkpoint']=$job['checkpoint']; }
        return $view;
    }
    public static function routes(): void {
        if (!self::enabled()) { return; }
        $base='/batches/(?P<id>[a-f0-9-]{36})';
        register_rest_route('mbi2/v1',$base,['methods'=>'GET','permission_callback'=>[self::class,'auth'],
            'callback'=>static function (\WP_REST_Request $request) {
                $job=self::get((string)$request['id']);
                return $job? self::response(self::view($job,true)) : new \WP_Error('mbi2_batch_missing','Lote inexistente.',['status'=>404]);
            }]);
        register_rest_route('mbi2/v1',$base.'/events',['methods'=>'POST','permission_callback'=>[self::class,'auth'],
            'callback'=>[self::class,'event']]);
    }
    private static function response(array $body): \WP_REST_Response {
        $response=new \WP_REST_Response($body);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public static function auth(\WP_REST_Request $r) {
        $method=$r->get_method();
        $route='batches/'.$r['id'].($method==='POST'?'/events':'');
        $headers=['timestamp'=>$r->get_header('x-mbi2-timestamp'),'nonce'=>$r->get_header('x-mbi2-nonce'),
            'signature'=>$r->get_header('x-mbi2-signature')];
        if (!self::enabled() || !Protocol::verify(Protocol::key(MBI2_HMAC_KEY_B64),$method,$route,$headers,$r->get_body(),time())) {
            return new \WP_Error('mbi2_batch_auth','Assinatura inválida.',['status'=>401]);
        }
        return true;
    }
    private static function swap(array $before,array &$after): bool {
        $after['revision']=$before['revision']+1;
        if (!Store::swap($before,$after)) { return false; }
        if (!empty($before['stream_work']['scope']) && empty($after['stream_work']['scope'])) {
            StreamRows::retire($before['stream_work']['scope']);
        }
        if (!empty($after['streaming']) && ($after['state']==='published' || $after['reason']==='catalogue_exhausted')) {
            StreamRows::retire($after['id']);
        }
        return true;
    }
    private static function report(array &$job, string $code, string $candidate = ''): void {
        $job['reports'][]=['code'=>$code,'candidate'=>$candidate,'at'=>time()];
        $job['reports']=array_slice($job['reports'],-15);
    }
    private static function shift(array &$job, string $failure = ''): void {
        $candidate=array_shift($job['queue']); $job['checkpoint']=[]; $job['pending']=[]; $job['prepared']=[];
        if (!empty($job['streaming'])) { $job['stream_work']=[]; }
        $job['failures']=0; $job['write_failures']=0;
        if ($failure!=='') { $job['rejected']++; self::report($job,$failure,(string)$candidate); }
        if ($job['published']>=$job['target']) {
            $job['state']='published'; $job['queue']=[]; $job['seen']=[]; $job['reason']='target_met';
        } elseif (!$job['queue'] && $job['exhausted']) {
            $job['state']='blocked'; $job['reason']='catalogue_exhausted'; $job['seen']=[];
        }
    }
    private static function page(array &$job, array $event): void {
        $urls=$event['urls']??null;
        if ($job['queue'] || $job['exhausted'] || ($event['page']??0)!==$job['page'] ||
            !is_array($urls) || !array_is_list($urls) || count($urls)>80 || !is_bool($event['more']??null)) {
            throw new \InvalidArgumentException('invalid_page');
        }
        $fresh=[];
        foreach ($urls as $url) {
            if (!is_string($url) || strlen($url)>2048 || !Job::source($url,[$job['origin']])) {
                throw new \InvalidArgumentException('invalid_candidate');
            }
            $key=hash('sha256',$url);
            if (!isset($job['seen'][$key])) { $fresh[]=$url; $job['seen'][$key]=1; }
        }
        $existing=Publisher::existingSources($fresh);
        $job['ignored']+=count($existing);
        $job['queue']=array_values(array_diff($fresh,$existing));
        $job['empty_pages']=$fresh ? 0 : ($job['empty_pages']??0)+1;
        $job['exhausted']=!$event['more'] || $job['page']>=$job['page_limit'] || $job['empty_pages']>=2;
        if ($job['page']>=$job['page_limit'] && $event['more']) { self::report($job,'page_budget_reached'); }
        $job['page']++;
        $job['failures']=0;
        if (!$job['queue'] && $job['exhausted']) { $job['state']='blocked'; $job['reason']='catalogue_exhausted'; $job['seen']=[]; }
    }
    private static function streamPage(array &$job,array $e): void {
        $items=$e['urls']??null; $sig=$e['signature']??'';
        if ($job['queue'] || $job['exhausted'] || ($e['page']??null)!==$job['page'] ||
            ($e['offset']??null)!==$job['page_offset'] || !is_bool($e['final']??null) || !is_bool($e['more']??null) ||
            !is_array($items) || !array_is_list($items) || count($items)>32 || (!$items && !$e['final']) ||
            !is_string($sig) || !preg_match('/^[a-f0-9]{64}$/D',$sig)) {
            throw new \InvalidArgumentException('invalid_catalogue_packet');
        }
        if ($job['page_signature']!=='' && $job['page_signature']!==$sig) {
            $job['state']='blocked'; $job['reason']='catalogue_contents_changed'; return;
        }
        if ($job['page_offset']===0 && empty($job['reopen_page']) && $e['more'] && !StreamRows::first($job['id'],'catalogue',$job['page'],[$sig])) {
            $job['state']='blocked'; $job['reason']='catalogue_repeated_page'; return;
        }
        foreach ($items as $url) {
            if (!is_string($url) || strlen($url)>2048 || !Job::source($url,[$job['origin']])) {
                throw new \InvalidArgumentException('invalid_candidate');
            }
        }
        $fresh=StreamRows::first($job['id'],'seen',$job['seen_slots'],$items);
        $job['seen_slots']+=count($items);
        $existing=Publisher::existingSources($fresh);
        $job['ignored']+=count($existing);
        $job['queue']=array_values(array_diff($fresh,$existing));
        $job['failures']=0;
        if ($e['final']) {
            $job['page']++; unset($job['reopen_page']); $job['page_offset']=0; $job['page_signature']=''; $job['exhausted']=!$e['more'];
        } else { $job['page_offset']+=count($items); $job['page_signature']=$sig; }
        if (!$job['queue'] && $job['exhausted']) { $job['state']='blocked'; $job['reason']='catalogue_exhausted'; }
    }
    private static function streamEvent(array &$job,array $event,string $kind): void {
        $candidate=$job['queue'][0]??'';
        if (!$candidate || $candidate!==($event['candidate']??null)) { throw new \DomainException('candidate_changed'); }
        $work=$job['stream_work'];
        try {
            if ($kind==='stream_open' && !$work) { $work=StreamWork::open($candidate,$event); }
            elseif ($kind==='stream_inventory' && $work) { StreamWork::inventory($work,$candidate,$event); }
            elseif ($kind==='stream_pages' && $work) { StreamWork::pages($work,$event); }
            elseif ($kind==='stream_seal' && $work) {
                StreamWork::seal($work);
                $job['pending']=['stream'=>true]; $job['state']='received';
            } else { throw new \InvalidArgumentException('unexpected_stream_packet'); }
            $job['stream_work']=$work;
            $job['failures']=0;
        } catch (\InvalidArgumentException $e) { self::shift($job,$e->getMessage()); }
    }
    private static function blob($value): array {
        if (!is_array($value) || strlen(wp_json_encode($value,JSON_THROW_ON_ERROR))>self::BLOB) {
            throw new \InvalidArgumentException('checkpoint_size');
        }
        return $value;
    }
    public static function event(\WP_REST_Request $request) {
        try {
            $body=$request->get_body();
            $e=json_decode($body,true,32,JSON_THROW_ON_ERROR);
            $job=self::get((string)$request['id']);
            if (!$job || !is_array($e) || array_is_list($e)) { throw new \InvalidArgumentException('job_or_event_missing'); }
            $attempt=(string)($e['attempt']??'');
            $kind=$e['kind']??'';
            if (!Job::uuid($attempt) || $job['generation']!==($e['generation']??'') || $job['state']==='cancelled') {
                throw new \DomainException('stale_workflow');
            }
            $hash=hash('sha256',$body);
            if (hash_equals($job['last_event'],$hash)) { return self::response(self::view($job)); }
            $next=$job;
            if ($kind==='claim') {
                if ($job['state']!=='dispatching' || $job['lease']<time()) { throw new \DomainException('already_claimed'); }
                $next['state']='running'; $next['attempt']=$attempt; $next['failures']=max(0,$job['failures']);
                $next['reason']='';
            } else {
                if ($job['state']!=='running' || $job['attempt']!==$attempt || $job['lease']<time()) {
                    throw new \DomainException('lease_expired');
                }
                if ($kind==='heartbeat') {
                    // Renew the lease for paginated origin requests.
                } elseif ($kind==='page') {
                    if (!empty($job['streaming'])) { self::streamPage($next,$e); } else { self::page($next,$e); }
                } elseif (!empty($job['streaming']) && in_array($kind,['stream_open','stream_inventory','stream_pages','stream_seal'],true)) {
                    self::streamEvent($next,$e,$kind);
                } elseif ($kind==='checkpoint' || $kind==='ready' || $kind==='reject') {
                    if (!empty($job['streaming']) && $kind!=='reject') { throw new \InvalidArgumentException('legacy_packet'); }
                    if (!$job['queue'] || $job['queue'][0]!==($e['candidate']??'')) { throw new \DomainException('candidate_changed'); }
                    if ($kind==='checkpoint') { $next['checkpoint']=self::blob($e['checkpoint']??[]); }
                    elseif ($kind==='reject') {
                        $code=sanitize_key((string)($e['reason']??'invalid_work'));
                        self::shift($next,substr($code,0,100)?:'invalid_work');
                    } else {
                        $raw=self::blob($e['checkpoint']??null);
                        try {
                            $next['pending']=Publisher::manifest(['mode'=>'batch','source_url'=>$job['queue'][0]],$raw); $next['checkpoint']=[]; $next['state']='received';
                        } catch (\InvalidArgumentException $error) {
                            self::shift($next,$error->getMessage());
                        }
                    }
                } elseif ($kind==='yield') {
                    $next['state']='queued'; $next['not_before']=time()+2;
                    if (($e['retry']??false)===true) {
                        $next['failures']++; $next['not_before']=time()+min(120,20*$next['failures']);
                        if ($next['failures']>=3) {
                            if ($next['queue']) { self::shift($next,'origin_retry_exhausted'); }
                            else { $next['state']='blocked'; $next['reason']='catalogue_not_available'; }
                        }
                    }
                } else { throw new \InvalidArgumentException('invalid_kind'); }
            }
            $next['lease']=time()+self::LEASE;
            $next['last_event']=$hash;
            if (!self::swap($job,$next)) { throw new \DomainException('race'); }
            if ($next['state']==='received') {
                self::wake($next['id'],2);
                if (!empty($next['streaming']) ? $next['stream_work']['expected']<=12 : count($next['pending']['chapters'])<=20) { self::publish($next,true); }
                $next=self::get($next['id'])??$next;
            } elseif ($next['state']==='queued') { self::wake($next['id'],max(2,$next['not_before']-time())); }
            if ($next['state']==='running' && !wp_next_scheduled('mbi2_batch_watch',[$next['id'],$next['generation']])) {
                wp_schedule_single_event($next['lease']+3,'mbi2_batch_watch',[$next['id'],$next['generation']]);
            }
            return self::response(self::view($next));
        } catch (\DomainException $e) { return new \WP_Error('mbi2_batch_conflict',$e->getMessage(),['status'=>409]); }
        catch (\InvalidArgumentException | \JsonException $e) { return new \WP_Error('mbi2_batch_input','Dados do lote inválidos.',['status'=>400]); }
        catch (\Throwable $e) { return new \WP_Error('mbi2_batch_error','Lote indisponível.',['status'=>500]); }
    }
    private static function wake(string $id,int $seconds=2): void {
        if (!wp_next_scheduled('mbi2_batch_tick',[$id])) {
            wp_schedule_single_event(time()+max(1,$seconds),'mbi2_batch_tick',[$id]);
        }
    }
    public static function watch(string $id,string $generation): void {
        if (!self::enabled()) { return; }
        $job=self::get($id);
        if (!$job || $job['generation']!==$generation || !in_array($job['state'],['dispatching','running'],true)) { return; }
        if ($job['lease']>=time()) {
            wp_schedule_single_event($job['lease']+3,'mbi2_batch_watch',[$id,$generation]); return;
        }
        $next=$job; $next['state']='queued'; $next['reason']='github_lease_expired';
        $next['not_before']=time()+10; $next['failures']++;
        if ($next['failures']>=3) {
            if ($next['queue']) { self::shift($next,'github_interrupted_repeatedly'); }
            else { $next['state']='blocked'; $next['reason']='github_did_not_complete'; }
        }
        if (self::swap($job,$next) && $next['state']==='queued') { self::wake($id,10); }
    }
    public static function tick(string $id): void {
        if (!self::enabled()) { return; }
        $job=self::get($id);
        if (!$job || in_array($job['state'],self::TERMINAL,true)) { return; }
        if ($job['state']==='publishing') {
            if ($job['lease']>=time()) { self::wake($id,$job['lease']-time()+3); return; }
            $next=$job; $next['state']='received';
            if (!self::swap($job,$next)) { return; }
            $job=$next;
        }
        if ($job['state']==='received') { self::publish($job,false); return; }
        if ($job['state']!=='queued') { return; }
        if ($job['not_before']>time()) { self::wake($id,$job['not_before']-time()); return; }
        if (empty($job['streaming']) && ($job['slices']>=self::SLICES || time()-$job['created']>DAY_IN_SECONDS)) {
            $next=$job; $next['state']='blocked'; $next['reason']='batch_budget_exhausted'; self::swap($job,$next); return;
        }
        $next=$job; $next['state']='dispatching'; $next['generation']=wp_generate_uuid4(); $next['attempt']='';
        $next['lease']=time()+240; $next['slices']++; $next['last_event']=''; $next['reason']='github_dispatched';
        if (!self::swap($job,$next)) { return; }
        $gen=$next['generation'];
        wp_schedule_single_event($next['lease']+3,'mbi2_batch_watch',[$id,$gen]);
        $result=wp_remote_post(empty($job['streaming'])?self::FLOW:self::STREAM_FLOW,['timeout'=>10,'redirection'=>0,'headers'=>[
            'Authorization'=>'Bearer '.MBI2_GITHUB_TOKEN,'Content-Type'=>'application/json','Accept'=>'application/vnd.github+json',
            'X-GitHub-Api-Version'=>'2022-11-28'],
            'body'=>wp_json_encode(['ref'=>'main','inputs'=>['job_id'=>$id,'generation'=>$gen]])]);
        $code=(int)wp_remote_retrieve_response_code($result);
        if (in_array($code,[401,403,404,422],true)) {
            $blocked=$next; $blocked['state']='blocked'; $blocked['reason']='github_http_'.$code;
            self::swap($next,$blocked);
        }
    }
    private static function workId(array $job): string {
        // Reuse only drafts created by this same batch for this exact candidate.
        $h=hash('sha256',$job['id']."\n".$job['queue'][0]);
        return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);
    }
    private static function publish(array $job,bool $inline): void {
        $id=$job['id'];
        if ($job['state']!=='received' || !$job['queue'] || !$job['pending']) { return; }
        if (mbi()->importer->is_paused()) {
            // Keep the verified result without polling the paused catalogue.
            $next=$job; $next['state']='blocked'; $next['reason']='wordpress_paused';
            self::swap($job,$next); return;
        }
        if (!\MBI_Resource_Guard::begin('publicação de lote GitHub',false)) { self::wake($id,30); return; }
        try {
            $next=$job; $next['state']='publishing'; $next['lease']=time()+150; $next['write_failures']++;
            if (!self::swap($job,$next)) { return; }
            self::wake($id,155);
            $after=$next; $after['state']=$inline?'running':'queued'; $after['reason']='';
            $after['lease']=time()+self::LEASE;
            try {
                if ($next['write_failures']>3) { throw new \DomainException('publication_retry_exhausted'); }
                $work=['mode'=>'batch','id'=>self::workId($next),'source_url'=>$next['queue'][0]];
                $prepared=$next['prepared'];
                $stream=$next['stream_work']??[];
                $post=$stream ? StreamWork::publish($stream,$next['queue'][0]) : Publisher::publish($work,$next['pending'],$prepared);
                if ($stream) { $after['stream_work']=$stream; }
                if (!$post) {
                    $after['state']='received'; $after['prepared']=$prepared;
                    $after['write_failures']=0; $after['reason']='wordpress_drafts_in_progress';
                } else {
                    if (get_post_status($post)!=='publish') { throw new \RuntimeException('publication_not_confirmed'); }
                    $after['published']++; self::shift($after);
                }
            } catch (\DomainException | \InvalidArgumentException $e) { self::shift($after,substr($e->getMessage(),0,100)); }
            catch (\Throwable $e) { $after['state']='received'; $after['reason']='wordpress_write_retry'; }
            if (self::swap($next,$after) && in_array($after['state'],['queued','received'],true)) {
                // The in-flight recovery watchdog remains as a fallback.
                wp_unschedule_event((int)wp_next_scheduled('mbi2_batch_tick',[$id]),'mbi2_batch_tick',[$id]);
                self::wake($id,$after['reason']==='wordpress_write_retry'?30:2);
            }
        } finally { \MBI_Resource_Guard::end(); }
    }
    public static function menu(): void {
        add_submenu_page('edit.php?post_type=mbi_manga','Importar lote pelo GitHub','Lotes via GitHub','manage_options','mbi-github-batch',[self::class,'render']);
    }
    private static function guard(string $nonce): void {
        if (!current_user_can('manage_options')) { wp_die('Acesso negado.','',['response'=>403]); }
        check_admin_referer($nonce);
        if (!self::enabled()) { wp_die('Motor de lotes desativado.','',['response'=>403]); }
    }
    public static function create(): void {
        self::guard('mbi2_batch_create');
        $id=sanitize_text_field(wp_unslash((string)($_POST['batch_id']??'')));
        try {
            $settings=mbi()->importer->settings();
            $origin=(string)wp_parse_url((string)($settings['source_url']??''),PHP_URL_HOST);
            $target=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($target===false) { throw new \InvalidArgumentException('invalid_quantity'); }
            $source=esc_url_raw(wp_unslash((string)($_POST['source_url']??'')),['https']);
            if (isset($_POST['source_url'])) {
                $source=trailingslashit($source);
                if ($source==='/' || !Job::source($source,Plugin::hosts())) { throw new \InvalidArgumentException('invalid_manual_url'); }
                $origin=(string)wp_parse_url($source,PHP_URL_HOST);
                $target=1;
            }
            $job=self::seed($id,$target,$origin);
            if ($source!=='') { $job['queue']=[$source]; $job['exhausted']=true; $job['manual_source']=$source; }
            $old=self::get($id);
            if (!$old) {
                $ticket=Store::reserve($id,true);
                if (!$ticket) { wp_die('Já existe um lote em andamento.','',['response'=>409,'back_link'=>true]); }
                try {
                    if (!Store::create($job)) { throw new \RuntimeException('storage'); }
                    self::wake($id);
                } finally { Store::finish($ticket,true); }
            } elseif ($old['target']!==$target || ($old['manual_source']??'')!==$source) { throw new \InvalidArgumentException('conflict'); }
            wp_safe_redirect(admin_url(self::PAGE.'&batch='.$id)); exit;
        } catch (\Throwable $e) { wp_die('Não foi possível registrar o lote. Confira a quantidade e a origem.','',['response'=>400,'back_link'=>true]); }
    }
    public static function control(): void {
        self::guard('mbi2_batch_control');
        $id=sanitize_text_field(wp_unslash((string)($_POST['batch']??'')));
        $job=self::get($id);
        $command=sanitize_key((string)($_POST['command']??''));
        $ticket=null;
        try {
            if (!$job || $job['state']==='published' || $job['state']==='publishing') { throw new \DomainException('not_stoppable'); }
            $next=$job;
            if ($command==='cancel' && !in_array($job['state'],self::TERMINAL,true)) {
                $next['state']='cancelled'; $next['reason']='stopped_by_admin';
            } elseif ($command==='resume' && in_array($job['state'],['cancelled','blocked'],true) && $job['reason']!=='catalogue_exhausted') {
                $ticket=Store::reserve($id,true);
                if (!$ticket) { throw new \DomainException('another_batch'); }
                $next['state']=$job['pending']?'received':'queued'; $next['reason']=''; $next['not_before']=0;
                $next['failures']=0; $next['write_failures']=0; $next['slices']=0; $next['created']=time();
                if ($job['reason']==='catalogue_contents_changed') { $next['page_offset']=0; $next['page_signature']=''; $next['reopen_page']=true; }
            } else { throw new \DomainException('invalid_control'); }
            $next['generation']=wp_generate_uuid4(); $next['attempt']=''; $next['last_event']='';
            if (!self::swap($job,$next)) { throw new \DomainException('race'); }
            if ($command==='resume') { self::wake($id); }
        } catch (\Throwable $e) { wp_die('O lote mudou ou está gravando uma obra. Atualize antes de interromper ou retomar.','',['response'=>409,'back_link'=>true]); }
        finally { if ($ticket) { Store::finish($ticket,true); } }
        wp_safe_redirect(admin_url(self::PAGE.'&batch='.$id)); exit;
    }
    private static function reason(string $code): string {
        $labels=[
            'missing_real_cover'=>'A origem não forneceu uma capa válida.',
            'unproven_reader_page_count'=>'Não foi possível confirmar todas as páginas de um capítulo.',
            'work_chapter_limit'=>'Obra acima de 120 capítulos.',
            'work_image_limit'=>'Obra acima de 2.400 imagens distintas.',
            'work_payload_limit'=>'A lista comprovada ultrapassou o limite de dados da obra.',
            'page_budget_reached'=>'Foi atingido o limite de páginas configurado para este lote.',
            'origin_retry_exhausted'=>'A origem falhou repetidamente para esta candidata.',
            'existing_catalogue_record_preserved'=>'O WordPress preservou um registro existente.'
        ];
        return $labels[$code]??$code;
    }
    private static function summary(array $job): array {
        $labels=['queued'=>'Aguardando rodada','dispatching'=>'Iniciando GitHub Actions','running'=>'Coletando e validando na origem',
            'received'=>'Obra validada; aguardando WordPress','publishing'=>'Gravando uma obra','blocked'=>'Encerrado com vagas',
            'published'=>'Meta atingida','cancelled'=>'Interrompido'];
        $reason=['target_met'=>'Todas as obras solicitadas foram publicadas.','catalogue_exhausted'=>'Não há mais candidatas na janela consultada.',
            'batch_budget_exhausted'=>'O lote antigo atingiu o limite de rodadas ou de tempo.',
            'catalogue_repeated_page'=>'A origem repetiu uma página do catálogo. O painel preservou o progresso.',
            'catalogue_contents_changed'=>'A lista da origem mudou no meio da página. Retome para relê-la.',
            'github_http_403'=>'O GitHub recusou a execução. Confira o acesso ao workflow e a disponibilidade do Actions.',
            'stopped_by_admin'=>'O progresso foi preservado.'];
        $text='Já existentes: '.$job['ignored'].'. Recusadas: '.$job['rejected'].'. Rodadas: '.$job['slices'].'.';
        if ($job['reason']!=='') { $text.=' '.($reason[$job['reason']]??$job['reason']); }
        $cp=$job['checkpoint'];
        $full=count($job['pending']['chapters']??[]);
        $stream=$job['stream_work']??[];
        return ['label'=>$labels[$job['state']]??$job['state'],'active'=>!in_array($job['state'],self::TERMINAL,true),
            'chapters'=>$stream['checked']??($full?:count($cp['chapters']??[])),'total'=>$stream['expected']??($full?:(int)($cp['work']['expected_chapters']??0)),'published'=>$job['published'],'reason'=>$text];
    }
    public static function status(): void {
        if (!current_user_can('manage_options')) { wp_send_json_error(null,403); }
        check_ajax_referer('mbi2_batch_status');
        $job=self::get(sanitize_text_field((string)($_GET['batch']??'')));
        if (!$job) { wp_send_json_error(null,404); }
        nocache_headers(); wp_send_json_success(self::summary($job));
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) { return; }
        $id=sanitize_text_field((string)($_GET['batch']??Store::latest(true)??''));
        $job=self::get($id);
        echo '<div class="wrap"><h1>Lotes via GitHub</h1><p>O GitHub procura e valida as candidatas. A meta conta somente obras publicadas.</p>';
        if (!self::enabled()) { echo '<p>A integração de lotes está desligada. Veja o guia incluído na versão.</p>'; }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mbi2_batch_create');
        echo '<input type="hidden" name="action" value="mbi2_batch_create"><input type="hidden" name="batch_id" value="'.esc_attr(wp_generate_uuid4()).'"><label>Quantidade <input type="number" name="quantity" min="1" value="10" required></label> ';
        submit_button('Importar lote via GitHub','primary','submit',false,self::enabled()?[]:['disabled'=>'disabled']);
        echo '</form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mbi2_batch_create');
        echo '<input type="hidden" name="action" value="mbi2_batch_create"><input type="hidden" name="quantity" value="1"><input type="hidden" name="batch_id" value="'.esc_attr(wp_generate_uuid4()).'"><label>URL de uma obra <input name="source_url" type="url" required placeholder="https://.../manga/obra/"></label> ';
        submit_button('Importar esta obra pelo GitHub','secondary','submit',false,self::enabled()?[]:['disabled'=>'disabled']);
        echo '</form>';
        if ($job) {
            $s=self::summary($job);
            $url=add_query_arg(['action'=>'mbi2_batch_status','batch'=>$id,'_ajax_nonce'=>wp_create_nonce('mbi2_batch_status')],admin_url('admin-ajax.php'));
            echo '<section id="mbi-github-status" data-active="'.($s['active']&&self::enabled()?'1':'0').'" data-url="'.esc_url($url).'" aria-live="polite">';
            echo '<h2 data-field="label">'.esc_html($s['label']).'</h2><p>Obras publicadas: <strong data-field="published">'.(int)$s['published'].'</strong> / '.(int)$job['target'].'</p>';
            echo '<p>Capítulos da obra atual: <span data-field="chapters">'.(int)$s['chapters'].'</span> / <span data-field="total">'.($s['total']?:'a identificar').'</span></p><p data-field="reason">'.esc_html($s['reason']).'</p></section>';
            if (self::enabled() && ($s['active'] || ($job['state']!=='published'&&$job['reason']!=='catalogue_exhausted'))) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('mbi2_batch_control');
                echo '<input type="hidden" name="action" value="mbi2_batch_control"><input type="hidden" name="batch" value="'.esc_attr($id).'">';
                if ($s['active'] && $job['state']!=='publishing') { echo '<button class="button" name="command" value="cancel">Parar lote</button>'; }
                if (!$s['active']) { echo '<button class="button" name="command" value="resume">Retomar lote</button>'; }
                echo '</form>';
            }
            if ($job['reports']) {
                echo '<details><summary>Ocorrências recentes</summary><ul>';
                foreach (array_reverse($job['reports']) as $row) {
                    $candidate=$row['candidate']??'';
                    echo '<li>'.($candidate!==''?'<code>'.esc_html(basename(rtrim($candidate,'/'))).'</code>: ':'').
                        esc_html(self::reason($row['code'])).'</li>';
                }
                echo '</ul></details>';
            }
        }
        echo '</div>';
    }
}
