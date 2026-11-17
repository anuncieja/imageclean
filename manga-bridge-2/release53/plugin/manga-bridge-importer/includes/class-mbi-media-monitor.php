<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Daily media-only refresh. Every request has bounded work; no editorial writes. */
final class MBI_Media_Monitor {
 private const OPT = 'mbi_media_daily';
 private const LOCK = 'mbi_media_daily_lock';
 private MBI_Media_Repair $media;
 private MBI_DB $db;
 public function __construct( MBI_Media_Repair $media, MBI_DB $db ) {
  $this->media = $media; $this->db = $db;
  add_action( 'init', array( $this, 'schedule' ), 25 );
  add_action( 'mbi_media_daily_scan', array( $this, 'daily' ) );
  add_action( 'mbi_media_daily_continue', array( $this, 'background' ) );
  add_action( 'admin_menu', array( $this, 'menu' ) );
  add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
  add_action( 'wp_ajax_mbi_media_daily', array( $this, 'ajax' ) );
  add_action( 'admin_post_mbi_media_daily', array( $this, 'ajax' ) );
  add_action( 'admin_post_mbi_media_report', array( $this, 'report' ) );
 }
 public function state(): array { if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::OPT, 'options' ); } return (array) get_option( self::OPT, array() ); }
 public function schedule(): void {
  // Earlier releases enabled a catalogue-wide daily sweep without an opt-in.
  // Stop only that automatic work once; explicit repairs remain untouched.
  if ( add_option( 'mbi_daily_policy51', 1, '', false ) ) {
   update_option( 'mbi_media_daily_enabled', 0, false );
   delete_option( 'mbi_media_daily_deferred' );
   wp_clear_scheduled_hook( 'mbi_media_daily_scan' );
   $s = $this->state();
   if ( ! empty( $s['active'] ) && 'daily' === ( $s['mode'] ?? '' ) ) {
    $s['active'] = false; $s['message'] = 'Checagem diária automática interrompida pela atualização para reduzir carga. Reative apenas se desejar.';
    update_option( self::OPT, $s, false ); wp_clear_scheduled_hook( 'mbi_media_daily_continue' );
   }
  }
  add_option( 'mbi_media_daily_enabled', 0, '', false );
  if ( get_option( 'mbi_media_daily_enabled', 0 ) && ! wp_next_scheduled( 'mbi_media_daily_scan' ) ) { wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'mbi_media_daily_scan' ); }
 }
 public function menu(): void { add_submenu_page( 'edit.php?post_type=mbi_manga', 'Reparo rápido / checagem diária', 'Reparo rápido / diário', 'manage_options', 'mbi-media-daily', array( $this, 'render' ) ); }
 public function assets( string $hook ): void {
  if ( ! str_contains( $hook, 'mbi-media-daily' ) ) { return; }
  wp_enqueue_script( 'mbi-media-daily', MBI_URL . 'assets/media-daily.js', array(), MBI_VERSION, true );
  wp_localize_script( 'mbi-media-daily', 'MBIMediaDaily', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'mbi_media_daily' ) ) );
 }
 public function render(): void {
  if ( ! current_user_can( 'manage_options' ) ) { return; }
  ?><div class="wrap" id="mbi-media-daily"><h1>Reparo rápido e checagem diária — v<?php echo esc_html( MBI_VERSION ); ?></h1>
  <div style="background:white;padding:24px;max-width:1000px"><p>Use a revisão completa abaixo para conferir todas as páginas publicadas e corrigir endereços quebrados com a lista atual da origem. O relatório informa o que passou e o que precisa de revisão. Os controles secundários de sincronização e checagem diária trabalham por amostras.</p>
  <div style="border:2px solid #2271b1;padding:16px;margin-bottom:20px"><h2>Revisão de imagens — capas ou capítulos</h2><p>Confere imagens na origem sem fazer GET interno ao Worker. Entrega pública deve ser conferida no navegador em Mangás → Reparar imagens. Se falhar, relê a origem e procura o capítulo pelo número exato na lista atual do mangá, incluindo nomes como “Caso 2”. Só substitui a lista do capítulo após validar todos os novos endereços. Falhas ficam no relatório; nenhum post é apagado. Cada rodada testa até duas imagens com leitura limitada, sem armazenar arquivos na hospedagem.</p><p><label>Escopo: <select id="mbi-final-scope"><option value="chapters">Somente capítulos publicados</option><option value="covers">Somente capas publicadas</option><option value="failures">Somente falhas registradas</option><option value="all">Todas as obras e capítulos (revisão longa)</option></select></label> <label>Validação: <select id="mbi-final-depth"><option value="sample">Primeira e última imagem (amostra)</option><option value="full">Todas as imagens originais (revisão longa)</option></select></label> <button type="button" class="button button-primary" data-daily="finalize">INICIAR REVISÃO DO ESCOPO SELECIONADO</button></p><p>Pause importações em execução antes de iniciar. Ao fechar o painel, o agendador continua o lote. Para problemas no leitor, escolha Somente capítulos. Para um capítulo específico, use Reparar imagens e informe a URL. A verificação da origem não comprova entrega via Worker no navegador. Progresso e pendências ficam abaixo.</p><p><button type="button" class="button" id="mbi-report-download">Baixar relatório desta revisão (CSV)</button> <button type="button" class="button" id="mbi-report-cancel" hidden>Cancelar download</button> <span id="mbi-report-status" role="status"></span></p></div>
  <p><button class="button button-primary" data-daily="retry">RECUPERAR FALHAS DA ÚLTIMA VARREDURA</button></p><p>Recuperação direcionada: usa os erros da última varredura, consulta a lista atual uma vez por mangá e associa capítulos por número exato. Não procura em outros sites, substitui listas antigas incorretas quando a origem comprova a sequência completa de páginas numeradas e não remove publicações. Casos sem correspondência ficam no log para revisão.</p>
  <p><button class="button" data-daily="start">Sincronizar somente endereços (sem validação completa)</button> <button class="button" data-daily="stop">Parar este reparo</button> <button class="button" data-daily="resume">Continuar</button></p>
  <p><button class="button" data-daily="enable">Ativar checagem diária</button> <button class="button" data-daily="disable">Desativar checagem diária</button></p>
  <p id="mbi-daily-schedule"></p><progress id="mbi-daily-progress" max="100" value="0" style="width:100%"></progress><p id="mbi-daily-status" role="status" aria-live="polite"></p><p id="mbi-daily-current"></p>
  <p>O reparo começa pelas capas e depois percorre os capítulos publicados. O painel acelera os ciclos enquanto estiver aberto; ao fechar, o agendador continua em pequenos lotes. A checagem diária fica desativada após esta atualização. Se você a ativar, ela confere capas, o capítulo mais recente e um capítulo antigo em rotação por mangá. Valida imagens de amostra nesses registros. Se encontrar links alterados, inclui os demais capítulos daquele mangá no reparo rápido, sem baixar suas imagens. Uma varredura por dia, sem sobreposição. Mangás, capítulos, slugs, SEO e categorias não são publicados, removidos ou alterados por esta função.</p>
  <p>Para executar sem visitas, configure o cron real da hospedagem a cada minuto. Sem isso, o WP-Cron depende do tráfego. Falhas de acesso na origem são preservadas para revisão.</p>
  <p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mbi_manga&page=manga-bridge-errors' ) ); ?>">Consultar e baixar logs de erros</a></p>
  <table class="widefat striped"><thead><tr><th>Registro</th><th>Resultado</th></tr></thead><tbody id="mbi-daily-rows"></tbody></table></div></div><?php
 }
 private function where( string $type, int $max ): string {
  global $wpdb;
  return $wpdb->prepare( "p.post_status='publish' AND p.post_type=%s AND p.ID<=%d AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key IN ('_mbi_origin_withdrawn','_mbi_root_absent')) AND (p.post_type='mbi_manga' OR EXISTS (SELECT 1 FROM {$wpdb->posts} parent WHERE parent.ID=p.post_parent AND parent.post_status='publish' AND parent.post_type='mbi_manga' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m2 WHERE m2.post_id=parent.ID AND m2.meta_key='_mbi_origin_withdrawn')))", $type, $max );
 }
 private function busy(): bool {
  if ( MBI_Resource_Guard::queue_priority() ) { return true; }
  foreach ( array( 'mbi_media_repair', 'mbi_cover_refresh', 'mbi_global_diagnostic', 'mbi_source_audit', 'mbi_root_reconcile' ) as $key ) { if ( ! empty( get_option( $key, array() )['active'] ) ) { return true; } }
  global $wpdb;
  return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbi_queue WHERE status='processing'" ) > 0;
 }
 public function start( string $mode = 'manual' ): array {
  $old = $this->state(); if ( ! empty( $old['active'] ) ) { return $old; }
  if ( $this->busy() ) { throw new RuntimeException( 'Outro processo está em execução. Pause-o antes do reparo rápido.' ); }
  global $wpdb;
  $max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
  $total = 0; foreach ( 'chapters' === $mode ? array( 'mbi_chapter' ) : ( 'covers' === $mode ? array( 'mbi_manga' ) : array( 'mbi_manga', 'mbi_chapter' ) ) as $type ) { $total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE " . $this->where( $type, $max ) ); }
  $s = array( 'id' => wp_generate_uuid4(), 'active' => $total > 0, 'mode' => $mode, 'phase' => 'chapters' === $mode ? 'mbi_chapter' : 'mbi_manga', 'cursor' => 0, 'max' => $max, 'total' => $total, 'checked' => 0, 'changed' => 0, 'failed' => 0, 'streak' => 0, 'rows' => array(), 'failure_ids' => array(), 'failure_reasons' => array(), 'started' => time(), 'current' => '', 'message' => $total ? 'Preparando reparo.' : 'Nenhum registro publicado disponível.' );
  if ( 'daily' === $mode ) { $s['targets'] = $this->daily_targets( $max ); $s['sample_targets'] = $s['targets']; $s['position'] = 0; $s['expanded'] = array(); $s['total'] = count( $s['targets'] ); $s['active'] = $s['total'] > 0; }
  update_option( self::OPT, $s, false ); $this->wake(); return $s;
 }
 /** Read only the last sweep's outcomes. Old importer errors are not recovery targets. */
 public function retry_failures(): array {
  $old = $this->state();
  if ( ! empty( $old['active'] ) ) { throw new RuntimeException( 'A varredura ainda está ativa. Pare-a ou aguarde antes de recuperar falhas.' ); }
  if ( $this->busy() ) { throw new RuntimeException( 'Outro processo está em execução. Pause-o antes da recuperação.' ); }
  global $wpdb; $reasons = array();
  if ( array_key_exists( 'failure_ids', $old ) ) {
   foreach ( (array) $old['failure_ids'] as $id ) { $reasons[(int) $id] = $old['failure_reasons'][$id] ?? ''; }
  } elseif ( ! empty( $old['started'] ) ) {
   $logs = $wpdb->get_results( $wpdb->prepare( "SELECT id,level,message,context FROM {$wpdb->prefix}mbi_logs WHERE created_at >= %s AND (message LIKE %s OR message=%s) ORDER BY id DESC LIMIT 50000", gmdate( 'Y-m-d H:i:s', (int) $old['started'] ), 'Reparo rápido/diário:%', 'Reparo rápido: links atualizados.' ) );
   $seen = array();
   foreach ( $logs as $row ) {
    $ctx = json_decode( (string) $row->context, true ); $id = (int) ( $ctx['post_id'] ?? 0 );
    if ( ! $id || isset( $seen[$id] ) ) { continue; } $seen[$id] = true;
    if ( $row->level === 'error' ) { $reasons[$id] = (string) $row->message; }
   }
  }
  foreach ( (array) get_option( 'mbi_media_pending', array() ) as $id => $entry ) { if ( ! isset( $reasons[$id] ) ) { $reasons[(int) $id] = (string) ( $entry['reason'] ?? '' ); } }
  $eligible = array();
  if ( $reasons ) {
   $ids = implode( ',', array_map( 'intval', array_keys( $reasons ) ) );
   // One database read, without loading all image arrays for thousands of posts.
   $records = $wpdb->get_results( "SELECT p.ID,p.post_parent,p.post_type FROM {$wpdb->posts} p LEFT JOIN {$wpdb->posts} parent ON parent.ID=p.post_parent WHERE p.ID IN ($ids) AND p.post_status='publish' AND p.post_type IN ('mbi_manga','mbi_chapter') AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key IN ('_mbi_origin_withdrawn','_mbi_root_absent')) AND (p.post_type='mbi_manga' OR (parent.post_status='publish' AND parent.post_type='mbi_manga' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m2 WHERE m2.post_id=parent.ID AND m2.meta_key='_mbi_origin_withdrawn')))" );
   foreach ( $records as $record ) { $eligible[] = array( 'id' => (int) $record->ID, 'parent' => $record->post_type === 'mbi_chapter' ? (int) $record->post_parent : (int) $record->ID ); }
  }
  usort( $eligible, static fn( $a, $b ) => ( $a['parent'] <=> $b['parent'] ) ?: ( $a['id'] <=> $b['id'] ) );
  $targets = array_column( $eligible, 'id' );
  $s = array( 'id' => wp_generate_uuid4(), 'active' => count( $targets ) > 0, 'mode' => 'recovery', 'targets' => $targets, 'position' => 0, 'max' => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ), 'total' => count( $targets ), 'checked' => 0, 'changed' => 0, 'failed' => 0, 'streak' => 0, 'rows' => array(), 'failure_ids' => array(), 'failure_reasons' => array(), 'input_reasons' => $reasons, 'started' => time(), 'current' => '', 'message' => $targets ? 'Recuperando somente os registros com falha.' : 'Nenhuma falha elegível da última varredura. Logs devem estar disponíveis para versões antigas.', 'map_fetches' => 0, 'remapped' => 0 );
  update_option( self::OPT, $s, false ); $this->wake(); return $s;
 }
 public static function category( string $message ): string {
  if ( str_contains( $message, 'não abre na origem:' ) ) { return 'origem_ou_correspondencia'; }
  if ( str_contains( $message, 'Entrega de imagens falhou:' ) || ( str_contains( $message, 'VALIDACAO_IMAGENS:' ) && str_contains( $message, 'não abre na entrega:' ) ) ) { return 'entrega'; }
  if ( preg_match( '/HTTP (404|410)\b|capa válida \(placeholder|não ofereceu páginas|nenhuma correspondência|número; correspondência ambígua/i', $message ) ) { return 'origem_ou_correspondencia'; }
  if ( preg_match( '/HTTP (403|429|5[0-9]{2})|timed? ?out|timeout|cURL|resolve host|connect|SSL|rede/i', $message ) ) { return 'acesso_temporario'; }
  return 'revisao_estrutura';
 }
 public function finalize( string $scope = 'failures', string $depth = 'full' ): array {
  global $wpdb;
  if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbi_queue WHERE status='processing'" ) > 0 || ( ! get_option('mbi_queue_paused',0) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbi_queue WHERE status='pending'" ) > 0 ) ) { throw new RuntimeException('Pause a importação e aguarde o trabalho atual terminar antes de fechar o reparo.'); }
  // Never interrupt unrelated repair jobs to start this sweep.
  if ( $this->busy() ) { throw new RuntimeException( 'Existe outro reparo/importação em execução. Pare-o antes de iniciar esta revisão.' ); }
  $current = $this->state();
  if ( ! empty( $current['active'] ) ) { $this->stop(); }
  $s = 'chapters' === $scope ? $this->start( 'chapters' ) : ( 'all' === $scope ? $this->start() : ( 'covers' === $scope ? $this->start( 'covers' ) : $this->retry_failures() ) );
  $s['verify_delivery'] = true;$s['verify_all_delivery']=$depth!=='sample';$s['fully_verified']=0;$s['report_id']=$s['id'];update_option('mbi_media_last_report',['report_id'=>$s['report_id'],'started'=>$s['started'],'log_start'=>(int)$wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}mbi_logs")],false);$s['categories']=[];$s['verified']=0;$s['message']=$s['active']?($s['verify_all_delivery']?'Validando todos os endereços na origem, sem GET no Worker.':'Validando imagens originais por amostra, sem GET no Worker.'):'Nenhuma falha publicada elegível. Isso não certifica todas as imagens do catálogo.';if(!$s['active'])$s['finished']=time();update_option(self::OPT,$s,false);return $s;
 }
 public static function csv_cell( $value ): string { $value=(string)$value;return preg_match('/^[=+@\-\t\r]/',$value)?"'".$value:$value; }
 public function report():void {
  if(!current_user_can('manage_options')){wp_die('Acesso negado.');return;}
  check_admin_referer('mbi_media_report');
  wp_safe_redirect(admin_url('edit.php?post_type=mbi_manga&page=mbi-media-daily')); exit;
 }
 /** Indexed ID pages, no LIKE scans or unbounded loop in a download request. */
 public function report_chunk( string $ticket = '', int $cursor = 0 ): array {
  global $wpdb;
  if ( $ticket === '' ) {
   $s=(array)get_option('mbi_media_last_report',array());
   if ( empty($s['report_id']) ) { throw new RuntimeException('Nenhuma revisão disponível.'); }
   $snapshot = array('run'=>$s['report_id'],'started'=>(int)$s['started'],'max'=>(int)$wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}mbi_logs"));
   $cursor=(int)($s['log_start']??0);
   if ( ! array_key_exists('log_start',$s) && $snapshot['max'] > 0 ) {
    $lo=1;$hi=$snapshot['max']+1;$date=gmdate('Y-m-d H:i:s',$snapshot['started']);
    for($n=0;$n<32&&$lo<$hi;$n++){$mid=intdiv($lo+$hi,2);$row=$wpdb->get_row($wpdb->prepare("SELECT id,created_at FROM {$wpdb->prefix}mbi_logs WHERE id>=%d ORDER BY id ASC LIMIT 1",$mid));if(!$row||(string)$row->created_at>=$date){$hi=$mid;}else{$lo=(int)$row->id+1;}}
    $cursor=max(0,$lo-1);
   }
   $ticket=wp_generate_uuid4();set_transient('mbi_report_'.get_current_user_id().'_'.$ticket,$snapshot,HOUR_IN_SECONDS);
  } else { $snapshot = get_transient('mbi_report_'.get_current_user_id().'_'.$ticket); if(!is_array($snapshot)){throw new RuntimeException('Download expirou; inicie novamente.');} }
  $rows=$wpdb->get_results($wpdb->prepare("SELECT id,created_at,LEFT(context,16384) AS context FROM {$wpdb->prefix}mbi_logs WHERE id>%d AND id<=%d ORDER BY id ASC LIMIT 500",$cursor,$snapshot['max']));
  $cells=array();
  foreach($rows as $row){$cursor=(int)$row->id;$c=json_decode((string)$row->context,true);if(($c['run_id']??'')!==$snapshot['run'])continue;$cells[]=array_map(array(self::class,'csv_cell'),array($row->created_at,$c['title']??'',$c['post_id']??'',$c['category']??'',$c['coverage']??'',!empty($c['changed'])?'sim':'não',$c['source']??'',$c['detail']??''));}
  $done=count($rows)<500||$cursor>=$snapshot['max'];if($done)delete_transient('mbi_report_'.get_current_user_id().'_'.$ticket);
  return array('ticket'=>$ticket,'cursor'=>$cursor,'max'=>$snapshot['max'],'done'=>$done,'rows'=>$cells);
 }
 private function network_error( string $message ): bool { return (bool) preg_match( '/HTTP (?:403|429|5[0-9]{2})|timed? ?out|timeout|cURL|resolve host|connect|SSL|rede/i', $message ); }
 /** Persist only the current work map, reused across requests and then discarded. */
 private function recovery_source( int $id, array &$s, string $run, string $token ): string {
  if ( get_post_type( $id ) !== 'mbi_chapter' ) { return ''; }
  $parent = (int) wp_get_post_parent_id( $id ); $source = (string) get_post_meta( $parent, '_mbi_source_url', true );
  $map = (array) ( $s['work_map'] ?? array() );
  if ( ( $map['parent'] ?? 0 ) !== $parent || ( $map['source'] ?? '' ) !== $source ) {
   $s['message'] = 'Consultando a lista atual de ' . get_the_title( $parent ); update_option( self::OPT, $s, false );
   $s['map_fetches'] = ( $s['map_fetches'] ?? 0 ) + 1;
   try { $map = $this->media->recovery_index( $parent ); }
   catch ( Throwable $e ) {
    if ( $this->network_error( $e->getMessage() ) && empty( $s['network_retry'][$id] ) ) { throw $e; }
    $map = array( 'parent' => $parent, 'source' => $source, 'chapters' => array(), 'error' => $e->getMessage() );
   }
   if ( ! $this->allowed( $run, $token ) ) { throw new RuntimeException( 'Recuperação parada.' ); }
   $s['work_map'] = $map; update_option( self::OPT, $s, false );
  }
  $old_url = (string) get_post_meta( $id, '_mbi_source_url', true );
  $number = (string) get_post_meta( $id, '_mbi_chapter_number', true );
  if ( $number === '' && preg_match( '/capitulo-(\d+(?:[.,-]\d+)?)(?:\/|$)/i', $old_url, $m ) ) { $number = str_replace( '-', '.', $m[1] ); }
  $key = MBI_Parser::recovery_number( $number ); $urls = $key !== '' ? (array) ( $map['chapters'][$key] ?? array() ) : array();
  if ( count( $urls ) > 1 ) { throw new RuntimeException( 'Mais de uma URL para o mesmo número; correspondência ambígua. Revisão necessária.' ); }
  if ( count( $urls ) === 1 ) { return $urls[0]; }
  if ( ! empty( $map['error'] ) && $this->network_error( $map['error'] ) ) { throw new RuntimeException( 'Lista da obra indisponível por falha de acesso; preservado para revisão. ' . $map['error'] ); }
  if ( preg_match( '/HTTP (404|410)\b/i', $s['input_reasons'][$id] ?? '' ) ) { throw new RuntimeException( 'Endereço antigo indisponível e nenhuma correspondência segura na lista da obra. ' . ( $map['error'] ?? 'Pode ter sido removido, renomeado ou não estar na lista carregada; revisão necessária.' ) ); }
  return ''; // Network, partial or empty-reader errors can retry the exact original URL.
 }

 /** Two chapter samples per work: newest plus a rotating older chapter. */
 private function daily_targets( int $max ): array {
  global $wpdb;
  $works = array_map( 'intval', $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE " . $this->where( 'mbi_manga', $max ) . ' ORDER BY p.ID' ) );
  $targets = $works;
  foreach ( $works as $work ) {
   $where = $this->where( 'mbi_chapter', $max ) . $wpdb->prepare( ' AND p.post_parent=%d', $work );
   $latest = (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p WHERE $where ORDER BY CAST((SELECT cm.meta_value FROM {$wpdb->postmeta} cm WHERE cm.post_id=p.ID AND cm.meta_key='_mbi_chapter_number' LIMIT 1) AS DECIMAL(20,6)) DESC, p.post_date DESC, p.ID DESC LIMIT 1" );
   $cursor = (int) get_post_meta( $work, '_mbi_daily_sample_cursor', true );
   $older = (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p WHERE $where" . $wpdb->prepare( ' AND p.ID>%d AND p.ID<>%d ORDER BY p.ID ASC LIMIT 1', $cursor, $latest ) );
   if ( ! $older ) { $older = (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p WHERE $where" . $wpdb->prepare( ' AND p.ID<>%d ORDER BY p.ID ASC LIMIT 1', $latest ) ); }
   if ( $latest ) { $targets[] = $latest; }
   if ( $older ) { $targets[] = $older; }
  }
  return array_values( array_unique( $targets ) );
 }
 private function expand_daily( array &$s, int $id ): void {
  if ( ( $s['mode'] ?? '' ) !== 'daily' || ! isset( $s['targets'] ) ) { return; }
  $parent = get_post_type( $id ) === 'mbi_manga' ? $id : (int) wp_get_post_parent_id( $id );
  if ( ! $parent || in_array( $parent, $s['expanded'], true ) ) { return; }
  global $wpdb;
  $children = array_map( 'intval', $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE " . $this->where( 'mbi_chapter', $s['max'] ) . $wpdb->prepare( ' AND p.post_parent=%d ORDER BY p.ID', $parent ) ) );
  $s['expanded'][] = $parent; $s['targets'] = array_values( array_unique( array_merge( $s['targets'], $children ) ) ); $s['total'] = count( $s['targets'] );
 }
 private function wake( int $delay = 5 ): void {
  $delay = max( $delay, (int) ( MBI_Resource_Guard::status()['wait'] ?? 0 ) );
  if ( ! empty( $this->state()['active'] ) && ! wp_next_scheduled( 'mbi_media_daily_continue' ) ) { wp_schedule_single_event( time() + $delay, 'mbi_media_daily_continue' ); }
 }
 public function daily(): void {
  if ( ! get_option( 'mbi_media_daily_enabled', 0 ) ) { return; }
  try { if ( empty( $this->state()['active'] ) ) { $this->start( 'daily' ); } $this->background(); }
  catch ( Throwable $e ) { $this->db->log( 'warning', 'Checagem diária adiada: ' . $e->getMessage() ); wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'mbi_media_daily_continue' ); update_option( 'mbi_media_daily_deferred', 1, false ); }
 }
 public function background(): void {
  if ( get_option( 'mbi_media_daily_deferred' ) && get_option( 'mbi_media_daily_enabled', 0 ) && empty( $this->state()['active'] ) ) {
   if ( $this->busy() ) { wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'mbi_media_daily_continue' ); return; }
   delete_option( 'mbi_media_daily_deferred' ); $this->start( 'daily' );
  }
  $s = $this->state(); if ( empty( $s['active'] ) ) { return; }
  try { $this->tick( $s['id'] ); } catch ( Throwable $e ) { $this->db->log( 'error', 'Ciclo de imagens interrompido: ' . $e->getMessage() ); }
  $this->wake( 10 );
 }
 private function purge(): void {
  if ( empty( $this->state()['changed'] ) ) { return; }
  try { do_action( 'litespeed_purge_all' ); if ( function_exists( 'wpo_cache_flush' ) ) { wpo_cache_flush(); } } catch ( Throwable $e ) { $this->db->log( 'warning', 'Reparo terminado: limpeza de cache pendente.' ); }
 }
 public function stop(): array {
  $s = $this->state(); $s['active'] = false; $s['id'] = wp_generate_uuid4(); $s['message'] = 'Parado. Links e catálogo preservados.';
  update_option( self::OPT, $s, false ); delete_option( 'mbi_media_daily_deferred' ); wp_clear_scheduled_hook( 'mbi_media_daily_continue' ); $this->purge(); return $s;
 }
 private function allowed( string $run, string $token ): bool {
  $s = $this->state(); if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::LOCK, 'options' ); } $lock = (array) get_option( self::LOCK, array() );
  return ! empty( $s['active'] ) && ( $s['id'] ?? '' ) === $run && ( $lock['token'] ?? '' ) === $token && ( $lock['until'] ?? 0 ) > time();
 }
 public function tick( string $run ): array {
		if ( ! MBI_Resource_Guard::begin( 'checagem de imagens', true ) ) { $s = $this->state(); $s['message'] = MBI_Resource_Guard::reason(); $s['next'] = max( (int) ( $s['next'] ?? 0 ), time() + max( 10, (int) ( MBI_Resource_Guard::status()['wait'] ?? 0 ) ) ); return $s; }
		try { return $this->tick_bounded( $run ); } finally { MBI_Resource_Guard::end(); }
	}

	private function tick_bounded( string $run ): array {
  $s = $this->state(); if ( empty( $s['active'] ) || ( $s['id'] ?? '' ) !== $run ) { return $s; }
  if ( ( $s['next'] ?? 0 ) > time() ) { return $s; }
  if ( $this->busy() ) { $s['message'] = 'Aguardando outro processo terminar.'; update_option( self::OPT, $s, false ); return $s; }
  $prior = (array) get_option( self::LOCK, array() );
  if ( $prior && ( $prior['until'] ?? 0 ) > time() ) { return $s; }
  if ( $prior ) { delete_option( self::LOCK ); }
  $token = wp_generate_uuid4(); if ( ! add_option( self::LOCK, array( 'token' => $token, 'until' => time() + 90 ), '', false ) ) { return $this->state(); }
  $deadline = microtime( true ) + max( 1, MBI_Resource_Guard::remaining() );
  try {
   for ( $step = 0; $step < 1 && microtime( true ) < $deadline; $step++ ) {
    if ( ! $this->allowed( $run, $token ) ) { return $this->state(); }
    global $wpdb;
    if ( isset( $s['targets'] ) ) {
     $id = 0;
     while ( isset( $s['targets'][$s['position']] ) ) {
      $candidate = (int) $s['targets'][$s['position']];
      $eligible = (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p WHERE " . $this->where( get_post_type( $candidate ) ?: 'invalid', $s['max'] ) . $wpdb->prepare( ' AND p.ID=%d LIMIT 1', $candidate ) );
      if ( $eligible ) { $id = $candidate; break; } $s['position']++;
     }
    } else {
     $id = (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p WHERE " . $this->where( $s['phase'], $s['max'] ) . $wpdb->prepare( ' AND p.ID>%d ORDER BY p.ID ASC LIMIT 1', $s['cursor'] ) );
    }
    if ( ! $id ) { if ( ! isset( $s['targets'] ) && 'mbi_manga' === $s['phase'] && ( $s['mode'] ?? '' ) !== 'covers' ) { $s['phase'] = 'mbi_chapter'; $s['cursor'] = 0; update_option( self::OPT, $s, false ); continue; } $s['active'] = false; $s['message'] = ! empty( $s['verify_delivery'] ) ? ( $s['failed'] ? 'Fechamento concluído com pendências identificadas. Nenhum lote ficou ativo. Consulte o relatório.' : ( ! empty( $s['verify_all_delivery'] ) ? 'Fechamento concluído; imagens originais dos registros foram verificadas, não a entrega do Worker.' : 'Fechamento concluído; amostras da origem verificadas, não a entrega do Worker.' ) . ' Nenhum lote ficou ativo.' ) : 'Concluído. Falhas foram preservadas para revisão.'; $s['finished'] = time(); $s['excluded'] = max( 0, $s['total'] - $s['checked'] ); update_option( self::OPT, $s, false ); wp_clear_scheduled_hook( 'mbi_media_daily_continue' ); $this->purge(); break; }
    if ( ! empty( $s['full_pending'] ) && (int) $s['full_pending']['post_id'] !== $id ) { unset( $s['full_pending'] ); }
    $attempts = ( $s['inflight'] ?? 0 ) === $id ? (int) ( $s['attempts'] ?? 0 ) + 1 : 1;
    $s['inflight'] = $id; $s['attempts'] = $attempts; $s['current'] = get_the_title( $id ); $s['pulse'] = time(); $s['message'] = 'Consultando a página original.'; update_option( self::OPT, $s, false );
    $changed = ! empty( $s['full_pending']['changed'] ); $sample_ok = false; $category = ''; $closing = ! empty( $s['verify_delivery'] );
    try {
     if ( $attempts > 2 ) { throw new RuntimeException( 'Duas execuções interrompidas; registro pulado para evitar travamento.' ); }
     if ( empty( $s['full_pending'] ) ) {
     $closing = ! empty( $s['verify_delivery'] );
     $verify = $closing || ( $s['mode'] ?? '' ) === 'daily' && in_array( $id, $s['sample_targets'] ?? array(), true );
     $previous_source = (string) get_post_meta( $id, '_mbi_source_url', true );
     $sample_ok = $closing && empty( get_post_meta( $id, '_mbi_repair_candidate', true ) ) && (int) ( $s['force_refresh_id'] ?? 0 ) !== $id && $this->media->validate_delivery_sample( $id, fn() => $this->allowed( $run, $token ) );
     $replacement = ! $sample_ok && ( $s['mode'] ?? '' ) === 'recovery' ? $this->recovery_source( $id, $s, $run, $token ) : '';
     try { if ( ! $sample_ok ) { $changed = $this->media->refresh_automatic( $id, fn() => $this->allowed( $run, $token ), $verify, $replacement, $closing ); } }
     catch ( Throwable $source_error ) {
      if ( $replacement || get_post_type( $id ) !== 'mbi_chapter' || ! preg_match( '/HTTP (404|410)\b/', $source_error->getMessage() ) || ! $this->allowed( $run, $token ) ) { throw $source_error; }
      $s['input_reasons'][$id] = $source_error->getMessage(); $replacement = $this->recovery_source( $id, $s, $run, $token );
      if ( ! $replacement || $replacement === $previous_source ) { throw $source_error; }
      $changed = $this->media->refresh_automatic( $id, fn() => $this->allowed( $run, $token ), $verify, $replacement, $closing );
     }
     if ( $replacement && $replacement !== $previous_source && $changed ) { $s['remapped'] = ( $s['remapped'] ?? 0 ) + 1; }
     $detail = $changed ? 'Endereços sincronizados com a página original (sem baixar imagens).' : 'Endereços já atuais na página original (sem baixar imagens).';
     if ( $verify ) { $detail .= $closing ? ' Origem verificada por amostra; Worker público ainda não testado.' : ' Imagem original verificada por amostra.'; $s['verified'] = ( $s['verified'] ?? 0 ) + 1; }
     }
     if ( $closing && ! empty( $s['verify_all_delivery'] ) && ! ( ! $sample_ok && (int) get_post_meta( $id, '_mbi_repair_origin_verified_at', true ) >= (int) $s['started'] ) ) {
      if ( empty( $s['full_pending'] ) ) { $s['full_pending'] = $this->media->full_delivery_pending( $id ); $s['full_pending']['changed'] = $changed; $s['full_pending']['source_checked'] = ! $sample_ok; unset( $s['force_refresh_id'] ); }
      if ( ! $this->media->validate_delivery_batch( $id, $s['full_pending'], fn() => $this->allowed( $run, $token ) ) ) { unset( $s['inflight'], $s['attempts'] ); $s['image_total']=$s['full_pending']['total'];$s['image_checked']=$s['full_pending']['boundary']+$s['full_pending']['cursor'];$s['message'] = 'Validando imagem ' . $s['image_checked'] . '/' . $s['image_total'] . ' do registro atual.'; update_option( self::OPT, $s, false ); break; }
      $detail = ( $changed ? 'Endereços atualizados. ' : 'Endereços preservados. ' ) . 'Todas as imagens originais verificadas nesta consulta, incluindo páginas intermediárias; Worker não testado.'; $s['fully_verified'] = ( $s['fully_verified'] ?? 0 ) + 1; unset( $s['full_pending'] );
     } elseif ( $closing && ! empty( $s['verify_all_delivery'] ) ) { $s['fully_verified'] = ( $s['fully_verified'] ?? 0 ) + 1; $detail = ( $changed ? 'Endereços atualizados. ' : 'Endereços preservados. ' ) . 'Imagens originais verificadas antes da gravação; Worker não testado.'; } elseif ( $closing ) { $detail .= ' Verificação por amostra da origem; entrega pública não certificada.'; }
     if ( $closing ) { $pending = (array) get_option( 'mbi_media_pending', array() ); unset( $pending[$id] ); update_option( 'mbi_media_pending', $pending, false ); }  $s['streak'] = 0; $s['config_streak'] = 0;
    } catch ( MBI_Resource_Wait $e ) {
     if ( ! $this->allowed( $run, $token ) ) { return $this->state(); }
     $s['next'] = time() + $e->delay; $s['message'] = $e->getMessage(); $candidate = (array) get_post_meta( $id, '_mbi_repair_candidate', true ); if ( $candidate ) { $s['image_total'] = count( $candidate['urls'] ); $s['image_checked'] = (int) $candidate['cursor']; } unset( $s['inflight'], $s['attempts'] ); update_option( self::OPT, $s, false ); break;
    } catch ( Throwable $e ) {
     if ( ! $this->allowed( $run, $token ) ) { return $this->state(); }
     $category = self::category( $e->getMessage() );
      // Existing published chapters remain unchanged after failed maintenance checks.
      // Only the normal importer publication gate may invalidate its delivery receipt.
     // Healthy boundary images do not prove that older middle URLs are current.
     // Re-read the exact source once; a bad source or delivery then ends explicitly.
     if ( $closing && ! empty( $s['full_pending'] ) && empty( $s['full_pending']['source_checked'] ) && $category !== 'entrega' ) {
      $s['force_refresh_id'] = $id; unset( $s['full_pending'], $s['inflight'], $s['attempts'], $s['image_total'], $s['image_checked'] ); $s['message'] = 'Imagem intermediária falhou; consultando novamente a página original antes de concluir este registro.'; update_option( self::OPT, $s, false ); break;
     }
     $detail = ( $changed ? 'Endereços atualizados; problema na validação: ' : 'Preservado: ' ) . $e->getMessage();
     if ( ( $s['mode'] ?? '' ) === 'recovery' && $category !== 'entrega' && $this->network_error( $e->getMessage() ) && empty( $s['network_retry'][$id] ) && empty( $s['work_map']['error'] ) && $attempts <= 2 ) {
      $s['network_retry'][$id] = 1; $s['next'] = time() + 3; $s['message'] = 'Falha de conexão. Uma nova tentativa em 3 segundos; depois seguirá para o próximo.';
      unset( $s['inflight'], $s['attempts'] ); update_option( self::OPT, $s, false ); break;
     }
     unset( $s['full_pending'], $s['force_refresh_id'] );
     if ( $closing ) { $s['categories'][$category] = ( $s['categories'][$category] ?? 0 ) + 1; $pending = (array) get_option( 'mbi_media_pending', array() ); $pending[$id] = array( 'reason' => $e->getMessage(), 'category' => $category, 'at' => time() ); if ( count( $pending ) <= 20000 ) { update_option( 'mbi_media_pending', $pending, false ); } }
     $s['failed']++; $s['failure_ids'][] = $id; $s['failure_reasons'][$id] = $e->getMessage();
     $network = $this->network_error( $e->getMessage() );
     $s['streak'] = $network ? $s['streak'] + 1 : 0;
     // Persistent configuration errors should not be repeated across 20k records.
     $s['config_streak'] = $category === 'entrega' ? (int) ( $s['config_streak'] ?? 0 ) + 1 : 0;
     $this->db->log( 'error', 'Reparo rápido/diário: ' . $detail, array( 'post_id' => $id, 'source' => get_post_meta( $id, '_mbi_source_url', true ) ) );
    }
    if ( ! $this->allowed( $run, $token ) ) { return $this->state(); }
    if ( $closing ) { $success_category = ! empty( $s['verify_all_delivery'] ) ? 'originais_todos_verificados' : 'originais_amostra_verificada'; $this->db->log( $category ? 'warning' : 'info', 'Fechamento de recuperação: ' . ( $category ?: $success_category ), array( 'run_id' => $s['report_id'], 'post_id' => $id, 'title' => get_the_title( $id ), 'source' => get_post_meta( $id, '_mbi_source_url', true ), 'changed' => $changed, 'coverage' => ! empty( $s['verify_all_delivery'] ) && ! $category ? 'originais_todos_sem_certificar_worker' : 'originais_amostra_ou_interrompida', 'category' => $category ?: $success_category, 'detail' => $detail ) ); }
    $s['checked']++; if ( $changed ) { $this->expand_daily( $s, $id ); }
    if ( isset( $s['targets'] ) ) { $s['position']++; if ( ( $s['mode'] ?? '' ) === 'daily' && 'mbi_chapter' === get_post_type( $id ) ) { update_post_meta( (int) wp_get_post_parent_id( $id ), '_mbi_daily_sample_cursor', $id ); } }
    if ( $changed ) { $s['changed']++; $this->db->log( 'info', 'Reparo rápido: links atualizados.', array( 'post_id' => $id ) ); }
    $s['cursor'] = $id; unset( $s['inflight'], $s['attempts'], $s['image_total'], $s['image_checked'] ); $s['rows'][] = array( 'title' => get_the_title( $id ), 'detail' => $detail ); $s['rows'] = array_slice( $s['rows'], -40 ); $s['message'] = $detail;
    if ( ( $s['config_streak'] ?? 0 ) >= 3 ) {
     $s['active'] = false;
     $s['message'] = 'Pausado após três falhas consecutivas de entrega/configuração; verifique o Worker no navegador antes de continuar.';
     update_option( self::OPT, $s, false ); wp_clear_scheduled_hook( 'mbi_media_daily_continue' ); break;
    }
    if ( $s['streak'] >= 3 || MBI_Resource_Guard::wait_seconds() > 0 ) { $s['next'] = time() + max( 30, MBI_Resource_Guard::wait_seconds() ); $s['message'] = 'Origem com falhas consecutivas; intervalo de proteção compartilhado. Próximo registro será checado depois.'; }
    update_option( self::OPT, $s, false );
    if ( $s['streak'] >= 3 ) { break; }
    if ( function_exists( 'memory_get_usage' ) && memory_get_usage( true ) > 128 * 1024 * 1024 ) { break; }
   }
   return $this->state();
  } finally { if ( ( get_option( self::LOCK, array() )['token'] ?? '' ) === $token ) { delete_option( self::LOCK ); } }
 }
 public function ajax(): void {
  if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 ); return; }
  check_ajax_referer( 'mbi_media_daily', 'nonce' );
  try {
   $cmd = sanitize_key( $_POST['command'] ?? 'status' );
   if ( 'report_chunk' === $cmd ) { wp_send_json_success( $this->report_chunk( sanitize_text_field( $_POST['ticket'] ?? '' ), absint( $_POST['cursor'] ?? 0 ) ) ); }
  if ( 'finalize' === $cmd ) { $s = $this->finalize( sanitize_key( $_POST['scope'] ?? 'failures' ), sanitize_key( $_POST['depth'] ?? 'full' ) ); }
   elseif ( 'retry' === $cmd ) { $s = $this->retry_failures(); }
   elseif ( 'start' === $cmd ) { $s = $this->start(); }
   elseif ( 'tick' === $cmd ) { $s = $this->tick( sanitize_text_field( $_POST['run_id'] ?? '' ) ); $this->wake(); }
   elseif ( 'stop' === $cmd ) { $s = $this->stop(); }
   elseif ( 'resume' === $cmd ) { $s = $this->state(); if ( ! empty( $s['total'] ) && ( $s['checked'] ?? 0 ) < $s['total'] && empty( $s['active'] ) ) { $s['active'] = true; $s['id'] = wp_generate_uuid4(); unset( $s['next'] ); update_option( self::OPT, $s, false ); $this->wake(); } }
   elseif ( 'enable' === $cmd ) { update_option( 'mbi_media_daily_enabled', 1, false ); $this->schedule(); $s = $this->state(); }
   elseif ( 'disable' === $cmd ) { update_option( 'mbi_media_daily_enabled', 0, false ); delete_option( 'mbi_media_daily_deferred' ); wp_clear_scheduled_hook( 'mbi_media_daily_scan' ); $s = $this->state(); }
   else { $s = $this->state(); }
   $s['daily_enabled'] = (bool) get_option( 'mbi_media_daily_enabled', 0 ); $s['next_daily'] = wp_next_scheduled( 'mbi_media_daily_scan' ) ?: 0; $s['now'] = time();
   unset( $s['targets'], $s['sample_targets'], $s['expanded'], $s['work_map'], $s['input_reasons'], $s['failure_ids'], $s['failure_reasons'], $s['network_retry'], $s['full_pending'] );
   wp_send_json_success( $s );
  } catch ( Throwable $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 400 ); }
 }
}
