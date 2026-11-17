<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A yield is not a defective source or an unsuccessful publication. */
final class MBI_Resource_Wait extends RuntimeException {
	public int $delay;
	public function __construct( string $message, int $delay = 30 ) {
		parent::__construct( $message ); $this->delay = max( 2, $delay );
	}
}

/** One bounded work lane across cron, browser requests and all repair engines. */
final class MBI_Resource_Guard {
	private const LOCK = 'mbi_resource_lease';
	private static array $lease = array();
	private static float $deadline = 0;
	private static float $budget = 0;
	private static int $requests = 0;
	private static string $reserve = '';
	private static ?object $job = null;
	private static ?MBI_DB $db = null;
	private static string $generation = '';
	private static string $reason = '';
	private static string $proxy_host51 = '';
	private static int $proxy_blocked51 = 0;
	public static function network_usage51(): array { return array( 'http' => self::$requests, 'worker_blocked' => self::$proxy_blocked51 ); }

	public static function boot( MBI_DB $db ): void {
		self::$db = $db;
		add_filter( 'http_request_args', array( self::class, 'http_args' ), 999, 2 );
		add_filter( 'pre_http_request', array( self::class, 'before_http' ), 999, 3 );
		add_action( 'http_api_debug', array( self::class, 'http_result' ), 1000, 5 );
		register_shutdown_function( array( self::class, 'shutdown' ) );
	}

	/** Read directly: an object cache must not hide another request's lease. */
	private static function read( string $key ): array {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $key ) );
		$value = $raw ? maybe_unserialize( $raw ) : array();
		return is_array( $value ) ? $value : array();
	}
	private static function invalidate( string $key ): void {
		wp_cache_delete( $key, 'options' ); wp_cache_delete( 'notoptions', 'options' );
	}
	public static function pressure(): array { return self::read( 'mbi_resource_pressure' ); }
	public static function wait_seconds(): int { return max( 0, (int) ( self::pressure()['until'] ?? 0 ) - time() ); }
	public static function status(): array {
		$s = self::read( self::LOCK ); $p = self::pressure();
		return array( 'lane' => ( $s['until'] ?? 0 ) > time() ? ( $s['lane'] ?? '' ) : '', 'wait' => self::wait_seconds(), 'reason' => $p['reason'] ?? '', 'last_fatal' => self::read( 'mbi_resource_last_fatal' ) );
	}
	public static function reason(): string { return self::$reason; }
	public static function remaining(): float { return max( 0, self::$deadline - microtime( true ) ); }
	public static function requests_remaining(): int {
		$limit = ( self::$lease['lane'] ?? '' ) === 'publicação' ? 24 : 6;
		return self::$lease ? max( 0, $limit - self::$requests ) : 0;
	}
	public static function has_memory(): bool {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		return $limit <= 0 || $limit - memory_get_usage( true ) >= 32 * 1024 * 1024;
	}
	public static function queue_priority(): bool {
		global $wpdb;
		if ( get_option( 'mbi_queue_paused', false ) ) { return false; }
		$manual = (array) get_option( 'mbi_manual_update_batch', array() );
		if ( ! empty( $manual['active'] ) && ! empty( $manual['preparing'] ) ) { return true; }
		return (bool) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}mbi_queue WHERE status IN ('pending','processing') LIMIT 1" );
	}
	public static function begin( string $lane, bool $background = false ): bool {
		self::$reason = '';
		if ( self::$lease ) { self::$reason = 'Outra etapa já está executando nesta requisição.'; return false; }
		if ( self::wait_seconds() > 0 ) { self::$reason = 'Aguardando a rede recuperar; conteúdo preservado.'; return false; }
		if ( ! self::has_memory() ) { self::$reason = 'Rodada adiada para preservar 32 MB de memória livre.'; return false; }
		if ( $background && self::queue_priority() ) { self::$reason = 'Aguardando a publicação de capítulos, que tem prioridade.'; return false; }
		$now = time(); $lease = array( 'token' => wp_generate_uuid4(), 'lane' => $lane, 'until' => $now + 90 );
		if ( ! add_option( self::LOCK, $lease, '', false ) ) {
			global $wpdb;
			$old = self::read( self::LOCK );
			if ( ( $old['until'] ?? 0 ) > $now ) { self::$reason = 'Aguardando a rodada em execução: ' . ( $old['lane'] ?? '' ); return false; }
			// Compare-and-swap: two expired-lease contenders cannot both win.
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", maybe_serialize( $lease ), self::LOCK, maybe_serialize( $old ) ) );
			self::invalidate( self::LOCK );
			if ( 1 !== $changed ) { self::$reason = 'Outra rodada assumiu o processamento.'; return false; }
		}
		self::$lease = $lease;
		$settings = (array) get_option( 'mbi_settings', array() );
		self::$proxy_host51 = strtolower( (string) wp_parse_url( (string) ( $settings['image_proxy_base_url'] ?? 'https://img.lermangaonline.com.br' ), PHP_URL_HOST ) );
		self::$proxy_blocked51 = 0;
		$php = (int) ini_get( 'max_execution_time' );
		// Release the shared work lane frequently; unfinished validation uses checkpoints.
		$budget = 20; // Soft work budget; pending chapters resume from checkpoints.
		self::$budget = $php > 0 ? max( 1, min( $budget, $php - 5 ) ) : $budget;
		self::$deadline = microtime( true ) + self::$budget;
		self::$requests = 0; self::$reserve = str_repeat( 'r', 262144 );
		return true;
	}
	public static function end(): void {
		if ( ! self::$lease ) { return; }
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", self::LOCK, maybe_serialize( self::$lease ) ) );
		self::invalidate( self::LOCK );
		self::$lease = array(); self::$reserve = ''; self::$job = null;
	}
	public static function track( ?object $job ): void {
		self::$job = $job; wp_cache_delete( 'mbi_process_generation', 'options' ); self::$generation = (string) get_option( 'mbi_process_generation', '' );
	}
	public static function http_args( array $args, string $url ): array {
		if ( ! self::$lease ) { return $args; }
		$cap = in_array( self::$lease['lane'] ?? '', array( 'publicação', 'reparo de imagens' ), true ) ? 15 : 6;
		$requested = min( (float) ( $args['timeout'] ?? 5 ), $cap );
		$accept = (string) ( $args['headers']['Accept'] ?? '' );
		if ( preg_match( '/text\/html|application\/json/i', $accept ) ) {
			// Do not start HTML/REST with a leftover 2-8 second timeout. Reserve
			// a complete request window first, still respecting the PHP hard limit.
			$requested = min( (float) ( $args['timeout'] ?? 5 ), 15, max( 0.5, self::$budget - 3 ) );
			if ( self::remaining() < $requested + 2 ) {
				throw new MBI_Resource_Wait( 'Rodada limitada para liberar recursos; nova leitura terá sua janela completa.', 3 );
			}
		}
		$args['timeout'] = min( $requested, max( 0.5, self::remaining() - 2 ) );
		$args['redirection'] = min( (int) ( $args['redirection'] ?? 5 ), 2 );
		return $args;
	}
	public static function before_http( $pre, array $args, string $url ) {
		if ( ! self::$lease || false !== $pre ) { return $pre; }
		// Imports validate source bytes and signed tokens locally. They must never
		// spend the public Worker quota, even if legacy metadata contains proxy URLs.
		if ( 'diagnóstico' !== ( self::$lease['lane'] ?? '' ) && self::$proxy_host51 && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === self::$proxy_host51 ) {
			self::$proxy_blocked51++;
			return new WP_Error( 'mbi_public_worker_blocked', 'Chamada ao Worker público bloqueada durante importação. Valide o endereço original da imagem.' );
		}
		$owner = self::read( self::LOCK );
		if ( ( $owner['token'] ?? '' ) !== self::$lease['token'] || ( $owner['until'] ?? 0 ) <= time() ) { throw new MBI_Resource_Wait( 'Rodada antiga perdeu a autorização de processamento.', 3 ); }
		if ( self::$job ) { wp_cache_delete( 'mbi_queue_paused', 'options' ); if ( get_option( 'mbi_queue_paused', false ) ) { throw new MBI_Resource_Wait( 'Processamento pausado pelo administrador.', 30 ); } }
		if ( self::wait_seconds() > 0 ) { throw new MBI_Resource_Wait( 'Rede em espera compartilhada; nenhuma nova conexão foi aberta.', self::wait_seconds() ); }
		if ( self::remaining() < 2 || self::requests_remaining() < 1 || ! self::has_memory() ) { throw new MBI_Resource_Wait( 'Rodada limitada para liberar recursos; tarefa preservada.', 3 ); }
		self::$requests++;
		return $pre;
	}
	public static function http_result( $response, string $context, string $transport, array $args, string $url ): void {
		if ( ! self::$lease || $context !== 'response' ) { return; }
		$error = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response );
		$streak_key = 'mbi_timeout_guard49_' . md5( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 400 ) { delete_transient( $streak_key ); }
		if ( is_wp_error( $response ) && preg_match( '/cURL error 28|timed?\s*out/iu', $error ) ) {
			$streak = (array) get_transient( $streak_key );
			$count = ( $streak['at'] ?? 0 ) > time() - 90 ? (int) ( $streak['count'] ?? 0 ) + 1 : 1;
			set_transient( $streak_key, array( 'count' => $count, 'at' => time() ), 90 );
			// One URL timeout defers that job, not all engines. Three consecutive
			// timeouts still trigger shared protection. DNS/429/5xx stay immediate.
			if ( $count < 3 ) { return; }
		}

		if ( ! preg_match( '/getaddrinfo|cURL error 6|Could not resolve host|timed?\s*out|HTTP (?:408|429|500|502|503|504|52[0-4])\b/iu', $error ) ) { return; }
		$delay = preg_match( '/getaddrinfo|cURL error 6|Could not resolve host/iu', $error ) ? 120 : 30;
		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 429 ) {
			$retry = (string) wp_remote_retrieve_header( $response, 'retry-after' );
			$delay = max( 120, ctype_digit( $retry ) ? (int) $retry : max( 0, (int) strtotime( $retry ) - time() ) );
		}
		$p = self::pressure();
		update_option( 'mbi_resource_pressure', array( 'until' => max( $p['until'] ?? 0, time() + $delay ), 'reason' => sanitize_text_field( $error ), 'lane' => self::$lease['lane'] ), false );
	}
	/** Reserve memory first, then capture the original fatal and preserve the claim. */
	public static function shutdown(): void {
		$e = error_get_last();
		if ( ! self::$lease || ! $e || ! in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) { self::end(); return; }
		self::$reserve = '';
		$ctx = array( 'lane' => self::$lease['lane'], 'job_id' => self::$job->id ?? 0, 'url' => self::$job->payload['url'] ?? '', 'error' => substr( $e['message'], 0, 1500 ), 'file' => basename( $e['file'] ), 'line' => $e['line'], 'memory' => memory_get_usage( true ), 'peak_memory' => memory_get_peak_usage( true ), 'memory_limit' => ini_get( 'memory_limit' ), 'max_execution_time' => ini_get( 'max_execution_time' ), 'at' => gmdate( 'c' ) );
		try {
			wp_cache_delete( 'mbi_process_generation', 'options' );
			update_option( 'mbi_resource_last_fatal', $ctx, false );
			update_option( 'mbi_resource_pressure', array( 'until' => time() + 300, 'reason' => 'Erro fatal registrado; processamento pausado para revisão.', 'lane' => self::$lease['lane'] ), false );
			update_option( 'mbi_queue_paused', 1, false );
			if ( self::$db && self::$job && self::$generation === (string) get_option( 'mbi_process_generation', '' ) ) { self::$db->defer_interrupted( self::$job, $ctx['error'] ); }
			if ( self::$db ) { self::$db->log( 'error', 'Erro fatal durante processamento; publicação não confirmada. Tarefa preservada.', $ctx ); }
		} catch ( Throwable $ignored ) { /* The hosting error log remains the fallback. */ }
		self::end();
	}
}
