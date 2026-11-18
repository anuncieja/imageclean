<?php
declare(strict_types=1);
namespace MangaBridge2;

final class Plugin {
    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_mbi2_create', [self::class, 'create']);
        add_action('admin_post_mbi2_control', [self::class, 'control']);
        add_action('mbi2_dispatch', [self::class, 'dispatch']);
        add_action('mbi2_watchdog', [self::class, 'watchdog'], 10, 3);
    }
    public static function enabled(): bool {
        return defined('MBI2_EXTERNAL_ENABLED') && MBI2_EXTERNAL_ENABLED === true &&
            in_array(wp_get_environment_type(), ['local', 'development', 'staging'], true);
    }
    public static function hosts(): array { return ['mangalivre.blog', 'www.mangalivre.blog', 'mangalivre.to', 'www.mangalivre.to']; }
    public static function routes(): void {
        register_rest_route('mbi2/v1', '/jobs/(?P<id>[a-f0-9-]{36})', [
            'methods' => 'GET', 'callback' => [self::class, 'get'], 'permission_callback' => [self::class, 'auth']
        ]);
        register_rest_route('mbi2/v1', '/jobs/(?P<id>[a-f0-9-]{36})/events', [
            'methods' => 'POST', 'callback' => [self::class, 'event'], 'permission_callback' => [self::class, 'auth']
        ]);
    }
    public static function auth(\WP_REST_Request $request) {
        if (!self::enabled()) { return new \WP_Error('mbi2_disabled', 'Transporte de staging desativado.', ['status' => 503]); }
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
            if ($next !== $job && !Store::swap($job, $next)) { throw new \DomainException('revision_conflict'); }
            if ($next['state'] === 'waiting_origin') { self::wake($next['id'], 60); }
            if ($next['state'] === 'running') { self::watch($next); }
            return new \WP_REST_Response(['id' => $next['id'], 'state' => $next['state'], 'revision' => $next['revision'], 'published' => 0]);
        } catch (\DomainException $e) { return new \WP_Error('mbi2_conflict', $e->getMessage(), ['status' => 409]); }
        catch (\InvalidArgumentException | \JsonException $e) { return new \WP_Error('mbi2_input', 'Evento inválido.', ['status' => 400]); }
        catch (\Throwable $e) { return new \WP_Error('mbi2_storage', 'Não foi possível gravar o evento.', ['status' => 500]); }
    }
    public static function menu(): void {
        add_management_page('Manga Bridge — Staging', 'Manga Bridge — Staging', 'manage_options', 'mbi2-staging', [self::class, 'render']);
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) { return; }
        $id = sanitize_text_field((string) ($_GET['job'] ?? ''));
        $job = Job::uuid($id) ? Store::read($id) : null;
        echo '<div class="wrap"><h1>Manga Bridge — comunicação de staging</h1><p>Esta extensão é isolada: não publica nem altera o catálogo. O importador atual permanece ativo.</p>';
        if (!self::enabled()) { echo '<div class="notice notice-warning"><p>Configure o transporte em staging antes de criar pedidos. Produção é bloqueada nesta versão.</p></div>'; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mbi2_create'); echo '<input type="hidden" name="request_id" value="' . esc_attr(wp_generate_uuid4()) . '">'; echo '<input type="hidden" name="action" value="mbi2_create"><label>URL da obra <input required type="url" name="source_url" size="75"></label> ';
        submit_button('Coletar em staging', 'primary', 'submit', false, self::enabled() ? [] : ['disabled' => 'disabled']);
        echo '</form>';
        if ($job) {
            echo '<p>Pedido: <code>' . esc_html($job['id']) . '</code></p><p>Estado: <strong>' . esc_html($job['state']) . '</strong>; revisão ' . (int) $job['revision'] . '; publicações: 0 (staging).</p>';
            echo '<p>Motivo: ' . esc_html($job['reason']) . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mbi2_control'); echo '<input type="hidden" name="action" value="mbi2_control"><input type="hidden" name="job" value="' . esc_attr($id) . '">';
            echo '<button class="button" name="command" value="cancel">Parar</button> <button class="button" name="command" value="resume">Retomar</button></form>';
        }
        echo '<p>O cron continua sem painel aberto. Não há scraping no carregamento desta tela. Use atualizar do navegador para consultar o estado.</p></div>';
    }
    private static function guard(string $nonce): void {
        if (!current_user_can('manage_options')) { wp_die('Acesso negado.', '', ['response' => 403]); }
        check_admin_referer($nonce);
        if (!self::enabled()) { wp_die('Disponível somente em staging com configuração explícita.', '', ['response' => 403]); }
    }
    private static function wake(string $id, int $delay = 2): void {
        if (!wp_next_scheduled('mbi2_dispatch', [$id])) { wp_schedule_single_event(time() + $delay, 'mbi2_dispatch', [$id]); }
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
        if (Store::swap($job, $next) && $next['state'] === 'waiting_origin') { self::wake($id, 60); }
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
                wp_safe_redirect(admin_url('tools.php?page=mbi2-staging&job=' . $id)); exit;
            }
            $job = Job::create($id, wp_generate_uuid4(), $url, self::hosts(), time());
            if (!Store::create($job)) { throw new \RuntimeException('duplicate_id'); }
            self::wake($id);
            wp_safe_redirect(admin_url('tools.php?page=mbi2-staging&job=' . $id)); exit;
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
            if (!Store::swap($job, $next)) { throw new \DomainException('conflict'); }
            if ($action === 'resume') { self::wake($id); }
            wp_safe_redirect(admin_url('tools.php?page=mbi2-staging&job=' . $id)); exit;
        } catch (\Throwable $e) { wp_die('Controle recusado. Atualize o estado do pedido e tente novamente.', '', ['response' => 409]); }
    }
    /** Fixed GitHub endpoint; incoming payloads cannot choose callback hosts or repository. */
    public static function dispatch(string $id): void {
        if (!self::enabled()) { return; }
        $job = Store::read($id);
        if (!$job || !in_array($job['state'], ['queued', 'waiting_origin'], true)) { return; }
        if ($job['state'] === 'queued' && $job['reason'] === 'github_dispatched') { return; }
        if ($job['attempts'] >= 4) { $next = $job; $next['state'] = 'blocked'; $next['reason'] = 'run_budget_exhausted'; $next['revision']++; Store::swap($job, $next); return; }
        if (!defined('MBI2_GITHUB_TOKEN') || !defined('MBI2_WORKFLOW_REGISTERED') || MBI2_WORKFLOW_REGISTERED !== true) {
            $next = $job; $next['state'] = 'blocked'; $next['reason'] = 'github_configuration_required'; $next['revision']++;
            Store::swap($job, $next); return;
        }
        $next = $job; $next['state'] = 'dispatching'; $next['revision']++; $next['updated_at'] = time();
        if (!Store::swap($job, $next)) { return; }
        // Install the watchdog before the HTTP call; interrupted dispatches also expire.
        $args = [$next['id'], $next['generation'], $next['attempt']];
        if (!wp_next_scheduled('mbi2_watchdog', $args)) {
            wp_schedule_single_event(time() + 300, 'mbi2_watchdog', $args);
        }
        $response = wp_remote_post('https://api.github.com/repos/anuncieja/imageclean/actions/workflows/manga-bridge-executor.yml/dispatches', [
            'timeout' => 10, 'redirection' => 0,
            'headers' => ['Authorization' => 'Bearer ' . MBI2_GITHUB_TOKEN, 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['ref' => 'manga-bridge-2-transport', 'inputs' => ['job_id' => $id], 'return_run_details' => true])
        ]);
        $latest = Store::read($id);
        if (!$latest || $latest['generation'] !== $job['generation'] || $latest['state'] !== 'dispatching') { return; }
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        $result = $code === 200 ? json_decode(wp_remote_retrieve_body($response), true) : null;
        $run_id = is_array($result) ? ($result['workflow_run_id'] ?? 0) : 0;
        $accepted = $code === 204 || ($code === 200 && is_int($run_id) && $run_id > 0);
        $done = $latest; $done['state'] = $accepted ? 'queued' : 'blocked';
        $done['reason'] = $accepted ? 'github_dispatched' : 'github_dispatch_failed'; $done['revision']++;
        if ($accepted && is_int($run_id) && $run_id > 0) { $done['github_run_id'] = $run_id; }
        Store::swap($latest, $done);
        // No automatic retry after an ambiguous dispatch: avoid duplicate paid runs.
    }
}
