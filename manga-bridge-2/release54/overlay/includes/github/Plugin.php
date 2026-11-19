<?php
declare(strict_types=1);
namespace MangaBridge2;

final class Plugin {
    private const PAGE = 'edit.php?post_type=mbi_manga&page=mbi-github';
    private const REPO = 'anuncieja/imageclean';
    private const SITE = 'https://lermangaonline.com.br';

    public static function configuration(): string {
        if (!defined('MBI2_EXTERNAL_ENABLED') || MBI2_EXTERNAL_ENABLED !== true) { return 'disabled'; }
        if (wp_get_environment_type() === 'production' && untrailingslashit(home_url('/')) !== self::SITE) {
            return 'unexpected_site';
        }
        if (!defined('MBI2_EXTERNAL_PUBLISH') || MBI2_EXTERNAL_PUBLISH !== true
            || !defined('MBI2_WORKFLOW_REGISTERED') || MBI2_WORKFLOW_REGISTERED !== true
            || !defined('MBI2_GITHUB_TOKEN') || !is_string(MBI2_GITHUB_TOKEN)
            || !preg_match('/^[A-Za-z0-9_]{20,255}$/D', MBI2_GITHUB_TOKEN)) { return 'configuration_required'; }
        try { Protocol::key(defined('MBI2_HMAC_KEY_B64') ? (string) MBI2_HMAC_KEY_B64 : ''); }
        catch (\InvalidArgumentException $e) { return 'invalid_key'; }
        return '';
    }

    private static function url(string $id = ''): string {
        return admin_url(self::PAGE . ($id !== '' ? '&job=' . $id : ''));
    }

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_mbi2_create', [self::class, 'create']);
        add_action('admin_post_mbi2_control', [self::class, 'control']);
        add_action('mbi2_dispatch', [self::class, 'dispatch'], 10, 2);
        add_action('mbi2_publish', [self::class, 'publish']);
        add_action('mbi2_watchdog', [self::class, 'watchdog'], 10, 3);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_mbi2_status', [self::class, 'status']);
    }
    public static function enabled(): bool {
        return self::configuration() === '';
    }
    public static function hosts(): array { return ['mangalivre.blog', 'www.mangalivre.blog', 'mangalivre.to', 'www.mangalivre.to']; }
    public static function routes(): void {
        if (!self::enabled()) { return; }
        register_rest_route('mbi2/v1', '/jobs/(?P<id>[a-f0-9-]{36})', [
            'methods' => 'GET', 'callback' => [self::class, 'get'], 'permission_callback' => [self::class, 'auth']
        ]);
        register_rest_route('mbi2/v1', '/jobs/(?P<id>[a-f0-9-]{36})/events', [
            'methods' => 'POST', 'callback' => [self::class, 'event'], 'permission_callback' => [self::class, 'auth']
        ]);
    }
    public static function auth(\WP_REST_Request $request) {
        if (!self::enabled()) { return new \WP_Error('mbi2_disabled', 'Motor GitHub desativado.', ['status' => 503]); }
        try {
            $key = Protocol::key(defined('MBI2_HMAC_KEY_B64') ? (string) MBI2_HMAC_KEY_B64 : '');
            $route = 'jobs/' . (string) $request['id'] . ($request->get_method() === 'POST' ? '/events' : '');
            $ok = Protocol::verify($key, $request->get_method(), $route, [
                'timestamp' => $request->get_header('x-mbi2-timestamp'),
                'nonce' => $request->get_header('x-mbi2-nonce'),
                'signature' => $request->get_header('x-mbi2-signature')
            ], $request->get_body(), time());
            if ($ok) { return true; }
        } catch (\Throwable $e) {}
        return new \WP_Error('mbi2_auth', 'Assinatura inválida ou expirada.', ['status' => 401]);
    }
    public static function get(\WP_REST_Request $request) {
        try {
            $job = Store::read((string) $request['id']);
            if (!$job) { return new \WP_Error('mbi2_missing', 'Pedido inexistente.', ['status' => 404]); }
            $response = new \WP_REST_Response($job);
            $response->header('Cache-Control', 'no-store');
            return $response;
        } catch (\Throwable $e) { return new \WP_Error('mbi2_record', 'Registro indisponível.', ['status' => 500]); }
    }
    public static function event(\WP_REST_Request $request) {
        try {
            $raw = $request->get_body();
            if (strlen($raw) > Protocol::MAX_BODY) { throw new \InvalidArgumentException('body_too_large'); }
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) { throw new \InvalidArgumentException('invalid_event'); }
            $job = Store::read((string) $request['id']);
            if (!$job) { return new \WP_Error('mbi2_missing', 'Pedido inexistente.', ['status' => 404]); }
            $next = Job::event($job, $data, $raw, time());
            if ($next !== $job && $next['state'] === 'ready' && !empty($next['publish_on_ready'])) {
                if (!Publisher::enabled()) { throw new \DomainException('publisher_disabled'); }
                Publisher::manifest($next, $next['checkpoint']);
                $next['ready_at'] = time();
            }
            if ($next !== $job && !Store::swap($job, $next)) { throw new \DomainException('revision_conflict'); }
            if ($next['state'] === 'waiting_origin') { self::queue($next, 60); }
            if ($next['state'] === 'running') { self::watch($next); }
            if ($next['state'] === 'ready' && !empty($next['publish_on_ready'])) { self::schedulePublish($next['id']); }
            return new \WP_REST_Response(['id' => $next['id'], 'state' => $next['state'], 'revision' => $next['revision'], 'published' => $next['published']]);
        } catch (\DomainException $e) { return new \WP_Error('mbi2_conflict', $e->getMessage(), ['status' => 409]); }
        catch (\InvalidArgumentException | \JsonException $e) { return new \WP_Error('mbi2_input', 'Evento inválido.', ['status' => 400]); }
        catch (\Throwable $e) { return new \WP_Error('mbi2_storage', 'Não foi possível gravar o evento.', ['status' => 500]); }
    }
    public static function menu(): void {
        add_submenu_page('edit.php?post_type=mbi_manga', 'Importação por URL via GitHub', 'Importar via GitHub', 'manage_options', 'mbi-github', [self::class, 'render']);
    }
    public static function assets(string $hook): void {
        if (($_GET['page'] ?? '') !== 'mbi-github') { return; }
        wp_enqueue_script('mbi-github', MBI_URL . 'assets/github-status.js', [], MBI_VERSION, true);
    }
    public static function status(): void {
        if (!current_user_can('manage_options')) { wp_send_json_error(null,403); }
        check_ajax_referer('mbi2_status');
        $id = sanitize_text_field(wp_unslash((string) ($_GET['job'] ?? '')));
        $job = Job::uuid($id) ? Store::read($id) : null;
        if (!$job) { wp_send_json_error(null,404); }
        nocache_headers();
        wp_send_json_success(self::summary($job));
    }
    private static function summary(array $job): array {
        $state = $job['state'];
        $terminal = in_array($state,['blocked','cancelled','published'],true);
        $labels = ['queued'=>'Aguardando o cron ou o GitHub','dispatching'=>'Enviando ao GitHub','running'=>'Coletando e validando no GitHub',
            'waiting_origin'=>'Falha temporária. Nova tentativa agendada','ready'=>'Verificação concluída. Aguardando publicação',
            'publishing'=>'Publicando no WordPress','published'=>'Publicado','blocked'=>'Não publicado','cancelled'=>'Interrompido'];
        $reasons = ['github_configuration_required'=>'A configuração do GitHub está incompleta.',
            'workflow_not_claimed'=>'O GitHub não iniciou o pedido. Confira o workflow e seus segredos.',
            'run_budget_exhausted'=>'O limite de tentativas foi atingido.',
            'existing_catalogue_record_preserved'=>'Já há um registro dessa obra ou de um capítulo. O registro foi preservado.',
            'pilot_chapter_limit'=>'Esta importação aceita até 20 capítulos.',
            'pilot_image_limit'=>'Esta importação aceita até 250 imagens distintas.',
            'unproven_reader_page_count'=>'A origem não mostrou uma contagem de páginas que permitisse confirmar a integridade.',
            'source_structure_error'=>'O formato retornado pela origem não pôde ser interpretado.',
            'publication_budget_expired'=>'A publicação ficou aguardando o servidor por tempo demais.',
            'publication_paused'=>'O processamento no WordPress está pausado.',
            'network_or_resource_wait'=>'A origem não respondeu dentro do limite ou a rodada atingiu seu orçamento.',
            'wordpress_confirmed_published'=>'O WordPress confirmou que a obra está publicada.'];
        return ['state'=>$state,'active'=>!$terminal,'label'=>$labels[$state]??$state,
            'reason'=>$reasons[$job['reason']]??$job['reason'],
            'chapters'=>count($job['checkpoint']['chapters']??[]),
            'total'=>(int)($job['checkpoint']['work']['expected_chapters']??0),
            'published'=>(int)$job['published'], 'post_id'=>(int)($job['post_id']??0),
            'run_id'=>(int)($job['github_run_id']??0)];
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) { return; }
        $id = sanitize_text_field(wp_unslash((string) ($_GET['job'] ?? Store::latest() ?? '')));
        $job = Job::uuid($id) ? Store::read($id) : null;
        $error = self::configuration();
        echo '<div class="wrap"><h1>Importar via GitHub</h1><p>O GitHub coleta capítulos e verifica as imagens. O WordPress publica a obra quando todas as verificações terminam.</p>';
        if ($error !== '') {
            echo '<div class="notice notice-warning"><p>Motor desligado. Configure a integração conforme o guia desta versão. Código: <code>' . esc_html($error) . '</code>.</p></div>';
        }
        echo '<p>Importação por URL de obra inédita, com até 20 capítulos e 250 imagens distintas. Use as telas existentes para lotes e para aprovar capítulos novos.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mbi2_create'); echo '<input type="hidden" name="request_id" value="' . esc_attr(wp_generate_uuid4()) . '"><input type="hidden" name="action" value="mbi2_create"><label>URL da obra <input required type="url" name="source_url" size="65"></label> ';
        submit_button('Importar e publicar via GitHub', 'primary', 'submit', false, Publisher::enabled() ? [] : ['disabled'=>'disabled']);
        echo '</form>';
        if ($job) {
            $status = self::summary($job);
            echo '<div id="mbi-github-status" data-url="' . esc_url(add_query_arg(['action'=>'mbi2_status','job'=>$id,'_ajax_nonce'=>wp_create_nonce('mbi2_status')],admin_url('admin-ajax.php'))) . '" data-active="' . ($status['active'] && $error === '' ? '1' : '0') . '" aria-live="polite">';
            echo '<p>Pedido: <code>' . esc_html($id) . '</code></p><p>Estado: <strong data-field="label">' . esc_html($status['label']) . '</strong></p>';
            echo '<p>Capítulos verificados: <span data-field="chapters">' . $status['chapters'] . '</span> / <span data-field="total">' . ($status['total'] ?: 'a identificar') . '</span>. Obras publicadas: <strong data-field="published">' . $status['published'] . '</strong> / 1.</p><p data-field="reason">' . esc_html($status['reason']) . '</p>';
            if ($status['post_id']) { echo '<p><a href="' . esc_url(get_permalink($status['post_id'])) . '">Abrir obra publicada</a></p>'; }
            if ($status['run_id']) { echo '<p><a href="' . esc_url('https://github.com/' . self::REPO . '/actions/runs/' . $status['run_id']) . '">Ver execução no GitHub</a></p>'; }
            echo '</div>';
            if ($error === '' && !in_array($job['state'], ['published','publishing'],true)) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('mbi2_control'); echo '<input type="hidden" name="action" value="mbi2_control"><input type="hidden" name="job" value="' . esc_attr($id) . '">';
                if ($status['active']) { echo '<button class="button" name="command" value="cancel">Parar</button> '; }
                if (in_array($job['state'], ['blocked','waiting_origin','cancelled'],true)) { echo '<button class="button" name="command" value="resume">Retomar</button>'; }
                echo '</form>';
            }
        }
        echo '<p>Você pode fechar esta tela. Um cron do servidor mantém o processamento em andamento.</p></div>';
    }
    private static function guard(string $nonce): void {
        if (!current_user_can('manage_options')) { wp_die('Acesso negado.', '', ['response'=>403]); }
        check_admin_referer($nonce);
        if (!Publisher::enabled()) { wp_die('Motor GitHub desligado ou configuração incompleta.', '', ['response'=>403]); }
    }
    private static function wake(array $job, int $delay = 2): bool {
        $args = [$job['id'],$job['generation']];
        return (bool)wp_next_scheduled('mbi2_dispatch', $args) || wp_schedule_single_event(time()+$delay,'mbi2_dispatch',$args) === true;
    }
    private static function queue(array $job, int $delay = 2): void {
        if (!self::wake($job,$delay)) {
            $failed = $job;
            $failed['state'] = 'blocked'; $failed['reason'] = 'wordpress_cron_schedule_failed'; $failed['revision']++;
            Store::swap($job,$failed);
        }
    }
    private static function watch(array $job): void {
        $args = [$job['id'], $job['generation'], $job['attempt']];
        if (!wp_next_scheduled('mbi2_watchdog', $args)) {
            wp_schedule_single_event(max(time() + 5, (int) $job['lease_until'] + 5), 'mbi2_watchdog', $args);
        }
    }
    public static function watchdog(string $id, string $generation, string $attempt): void {
        if (!self::enabled()) { return; }
        $job = Store::read($id);
        if (!$job || $job['generation'] !== $generation || $job['attempt'] !== $attempt) { return; }
        if ($job['state'] === 'running' && $job['lease_until'] > time()) { self::watch($job); return; }
        $next = $job;
        if ($job['state'] === 'running') {
            $next['state'] = $job['attempts'] >= 4 ? 'blocked' : 'waiting_origin';
            $next['reason'] = $job['attempts'] >= 4 ? 'run_budget_exhausted' : 'executor_lease_expired';
        } elseif ($job['state'] === 'dispatching' || ($job['state'] === 'queued' && $job['reason'] === 'github_dispatched')) {
            $next['state'] = 'blocked'; $next['reason'] = 'workflow_not_claimed';
        } else { return; }
        $next['revision']++; $next['lease_until'] = 0; $next['updated_at'] = time();
        if (Store::swap($job, $next) && $next['state'] === 'waiting_origin') { self::queue($next, 60); }
    }
    public static function create(): void {
        self::guard('mbi2_create');
        try {
            $url = esc_url_raw(wp_unslash((string) ($_POST['source_url'] ?? '')));
            $id = sanitize_text_field(wp_unslash((string) ($_POST['request_id'] ?? '')));
            if (!Job::uuid($id)) { throw new \InvalidArgumentException('invalid_id'); }
            $existing = Store::read($id);
            if ($existing) {
                if ($existing['source_url'] !== $url) { throw new \DomainException('id_conflict'); }
                wp_safe_redirect(self::url($id)); exit;
            }
            $job = Job::create($id, wp_generate_uuid4(), $url, self::hosts(), time());
            $job['publish_on_ready'] = true;
            $ticket = Store::reserve($id);
            if (!$ticket) { wp_die('Já há um pedido em andamento. Acompanhe-o em Mangás → Importar via GitHub.', '', ['response'=>409,'back_link'=>true]); }
            try {
                if (!Store::create($job)) { throw new \RuntimeException('duplicate_id'); }
                self::queue($job);
            } finally { Store::finish($ticket); }
            wp_safe_redirect(self::url($id)); exit;
        } catch (\Throwable $e) { wp_die('Não foi possível criar o pedido; confira a URL e a configuração.', '', ['response' => 400]); }
    }
    public static function control(): void {
        self::guard('mbi2_control');
        $id = sanitize_text_field(wp_unslash((string) ($_POST['job'] ?? '')));
        try {
            $job = Store::read($id);
            if (!$job) { throw new \DomainException('not_found'); }
            $action = sanitize_key(wp_unslash((string) ($_POST['command'] ?? '')));
            $next = Job::admin($job, $action, wp_generate_uuid4(), time());
            if ($action === 'resume' && $job['state'] !== 'waiting_origin') {
                $ticket = Store::reserve($id);
                if (!$ticket) { throw new \DomainException('another_job_active'); }
            }
            try {
                if (!Store::swap($job, $next)) { throw new \DomainException('conflict'); }
                if ($action === 'resume') { self::queue($next); }
            } finally { if (isset($ticket)) { Store::finish($ticket); } }
            wp_safe_redirect(self::url($id)); exit;
        } catch (\Throwable $e) { wp_die('Controle recusado. Atualize o estado do pedido e tente novamente.', '', ['response' => 409]); }
    }
    private static function schedulePublish(string $id, int $delay = 2): void {
        $args = [$id];
        if (!wp_next_scheduled('mbi2_publish', $args)) {
            wp_schedule_single_event(time() + $delay, 'mbi2_publish', $args);
        }
    }

    /** Claim the final WordPress write independently of the GitHub run. */
    public static function publish(string $id): void {
        if (!Publisher::enabled()) { return; }
        $job = Store::read($id);
        if (!$job || empty($job['publish_on_ready']) || !in_array($job['state'], ['ready','publishing'], true)) { return; }
        if ($job['state'] === 'publishing' && ($job['publish_until'] ?? 0) > time()) {
            self::schedulePublish($id, max(5, $job['publish_until'] - time() + 5)); return;
        }
        if (($job['ready_at'] ?? 0) < time()-900 || ($job['publish_attempts'] ?? 0) >= 3) {
            $next = $job; $next['revision']++; $next['updated_at'] = time();
            $next['state'] = 'blocked'; $next['reason'] = 'publication_budget_expired'; Store::swap($job,$next); return;
        }
        if (!\MBI_Resource_Guard::begin('publicação GitHub', false)) { self::schedulePublish($id, 30); return; }
        try {
            if (mbi()->importer->is_paused()) { self::schedulePublish($id, 30); return; }
            $next = $job; $next['revision']++; $next['updated_at'] = time();
            $next['state'] = 'publishing'; $next['publish_until'] = time()+90;
            $next['publish_attempts'] = ($job['publish_attempts'] ?? 0)+1;
            if (!Store::swap($job, $next)) { return; }
            // A crashed PHP request can restart the same job and reuse its drafts.
            self::schedulePublish($id, 100);
            $done=$next; $done['revision']++; $done['updated_at']=time(); $done['publish_until']=0;
            try {
                $manifest=Publisher::manifest($next,$next['checkpoint']);
                $postId=Publisher::publish($next,$manifest);
                $done['state']='published'; $done['published']=1; $done['post_id']=$postId; $done['reason']='wordpress_confirmed_published';
            } catch (\DomainException | \InvalidArgumentException $e) {
                $done['state']='blocked'; $done['reason']=$e->getMessage();
            } catch (\Throwable $e) {
                $done['state']='ready'; $done['reason']='wordpress_write_retry';
            }
            Store::swap($next,$done);
        } finally { \MBI_Resource_Guard::end(); }
    }

    /** Fixed GitHub endpoint; incoming payloads cannot choose callback hosts or repository. */
    public static function dispatch(string $id, string $generation = ''): void {
        if (!self::enabled()) { return; }
        $job = Store::read($id);
        if (!$job || $job['generation'] !== $generation || !in_array($job['state'], ['queued', 'waiting_origin'], true)) { return; }
        if ($job['state'] === 'queued' && $job['reason'] === 'github_dispatched') { return; }
        if ($job['attempts'] >= 4) { $next = $job; $next['state'] = 'blocked'; $next['reason'] = 'run_budget_exhausted'; $next['revision']++; Store::swap($job, $next); return; }
        $next = $job; $next['state'] = 'dispatching'; $next['revision']++; $next['updated_at'] = time();
        if (!Store::swap($job, $next)) { return; }
        // Install the watchdog before the HTTP call; interrupted dispatches also expire.
        $args = [$next['id'], $next['generation'], $next['attempt']];
        if (!wp_next_scheduled('mbi2_watchdog', $args)) {
            wp_schedule_single_event(time() + 300, 'mbi2_watchdog', $args);
        }
        $workflow = wp_get_environment_type() === 'production' ? 'manga-bridge-production.yml' : 'manga-bridge-executor.yml';
        $inputs = ['job_id'=>$id];
        if (wp_get_environment_type() === 'production') { $inputs['generation'] = $generation; }
        try {
            $response = wp_remote_post('https://api.github.com/repos/' . self::REPO . '/actions/workflows/' . $workflow . '/dispatches', [
                'timeout'=>10, 'redirection'=>0,
                'headers'=>['Authorization'=>'Bearer ' . MBI2_GITHUB_TOKEN, 'Accept'=>'application/vnd.github+json', 'X-GitHub-Api-Version'=>'2022-11-28', 'Content-Type'=>'application/json'],
                'body'=>wp_json_encode(['ref'=>'main','inputs'=>$inputs])
            ]);
        } catch (\Throwable $error) { $response = new \WP_Error('mbi2_transport'); }
        $latest = Store::read($id);
        if (!$latest || $latest['generation'] !== $job['generation'] || $latest['state'] !== 'dispatching') { return; }
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        $result = $code === 200 ? json_decode(wp_remote_retrieve_body($response), true) : null;
        $run_id = is_array($result) ? ($result['workflow_run_id'] ?? 0) : 0;
        $accepted = $code === 204 || ($code === 200 && is_int($run_id) && $run_id > 0);
        $done = $latest; $done['state'] = $accepted ? 'queued' : 'blocked';
        $done['reason'] = $accepted ? 'github_dispatched' : ('github_dispatch_http_' . $code); $done['revision']++;
        if ($accepted && is_int($run_id) && $run_id > 0) { $done['github_run_id'] = $run_id; }
        Store::swap($latest, $done);
        // No automatic retry after an ambiguous dispatch: avoid duplicate paid runs.
    }
}
