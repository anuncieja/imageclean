<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Public delivery is checked in the administrator's browser, never by a blocked Worker GET. */
final class MBI_Browser_Delivery {
 private MBI_Importer $importer;
 public function __construct( MBI_Importer $importer ) {
  $this->importer = $importer;
  add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
  add_action( 'wp_ajax_mbi_browser_delivery', array( $this, 'ajax' ) );
 }
 public function assets(): void {
  if ( defined( 'MBI_VERSION' ) && version_compare( MBI_VERSION, '1.7.36', '>=' ) ) { return; } // Autonomous verification is handled by the scheduled worker.
  if ( ! current_user_can( 'manage_options' ) ) { return; }
  $page = sanitize_key( $_GET['page'] ?? '' );
  if ( ! in_array( $page, array( 'manga-bridge-importer', 'manga-bridge-updates' ), true ) ) { return; }
  wp_enqueue_script( 'mbi-browser-delivery', MBI_URL . 'assets/browser-delivery.js', array(), MBI_VERSION, true );
  wp_localize_script( 'mbi-browser-delivery', 'MBIBrowserDelivery', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'mbi_browser_delivery' ) ) );
 }
 public static function fingerprint( int $id, array $c ): string {
  return hash( 'sha256', serialize( array( $id, $c['key'] ?? '', $c['created'] ?? 0, $c['base'] ?? '', $c['generation'] ?? '', $c['urls'] ?? array(), $c['delivery'] ?? array() ) ) );
 }
 public static function needs_browser( array $c ): bool {
  return (array) ( $c['urls'] ?? array() ) !== (array) ( $c['delivery'] ?? array() );
 }
 public static function approved( int $id, array $c ): bool {
  $r = (array) ( $c['browser_approval'] ?? array() );
  return ! empty( $r['at'] ) && $r['at'] >= time() - DAY_IN_SECONDS
   && ( $r['fingerprint'] ?? '' ) === self::fingerprint( $id, $c )
   && (int) ( $r['count'] ?? 0 ) === count( (array) ( $c['delivery'] ?? array() ) );
 }
 private function eligible( int $id, array $c ): bool {
  return ! $this->importer->is_paused() && get_post_type( $id ) === 'mbi_chapter'
   && ! in_array( get_post_status( $id ), array( 'trash', 'auto-draft' ), true )
   && ! empty( $c['awaiting_browser'] ) && empty( $c['browser_error'] ) && empty( $c['failed'] )
   && ! self::approved( $id, $c ) && ! empty( $c['urls'] )
   && count( $c['urls'] ) === count( $c['delivery'] ?? array() )
   && ! get_post_meta( $id, '_mbi_root_absent', true ) && ! get_post_meta( $id, '_mbi_origin_withdrawn', true )
   && (int) wp_get_post_parent_id( $id ) === (int) ( $c['manga_id'] ?? 0 )
   && ( $c['base'] ?? '' ) === MBI_Chapter_Integrity::snapshot( $id )
   && MBI_Chapter_Integrity::delivery( $c['urls'] ) === $c['delivery']
   && ( ! get_option( 'mbi_generation_enforced', false ) || ( $c['generation'] ?? '' ) === get_option( 'mbi_process_generation', '' ) );
 }
 public function challenge( int $id, array $c ): array {
  if ( ! $this->eligible( $id, $c ) ) { throw new RuntimeException( 'Capítulo parado, alterado ou já validado.' ); }
  $fingerprint = self::fingerprint( $id, $c );
  $p = (array) ( $c['browser_progress'] ?? array() );
  if ( ( $p['fingerprint'] ?? '' ) !== $fingerprint || ( $p['at'] ?? 0 ) < time() - DAY_IN_SECONDS ) { $p = array( 'fingerprint' => $fingerprint, 'cursor' => 0, 'at' => time() ); }
  if ( empty( $p['challenge'] ) ) { $p['challenge'] = wp_generate_uuid4(); }
  $urls = array_slice( $c['delivery'], (int) $p['cursor'], 8 );
  $c['browser_progress'] = $p;
  update_post_meta( $id, '_mbi_delivery_candidate', wp_slash( $c ) );
  return array( 'id' => $id, 'title' => get_the_title( $id ), 'challenge' => $p['challenge'], 'urls' => $urls, 'checked' => (int) $p['cursor'], 'total' => count( $c['delivery'] ) );
 }
 public function accept( int $id, string $challenge, array $sizes, array $failures ): array {
  $c = (array) get_post_meta( $id, '_mbi_delivery_candidate', true );
  if ( ! $this->eligible( $id, $c ) ) { throw new RuntimeException( 'Execução parada ou capítulo alterado; resultado descartado.' ); }
  $p = (array) ( $c['browser_progress'] ?? array() );
  if ( empty( $p['challenge'] ) || ! hash_equals( $p['challenge'], $challenge ) || ( $p['fingerprint'] ?? '' ) !== self::fingerprint( $id, $c ) ) { throw new RuntimeException( 'Resultado antigo ou inválido; nenhuma publicação.' ); }
  $count = min( 8, count( $c['delivery'] ) - (int) $p['cursor'] );
  if ( $count < 1 || count( $sizes ) !== $count ) { throw new RuntimeException( 'Quantidade de imagens não confirmada.' ); }
  $bad = -1; foreach ( $sizes as $i => $size ) { if ( ! is_array( $size ) || (int) ( $size['width'] ?? 0 ) < 40 || (int) ( $size['height'] ?? 0 ) < 40 ) { $bad = $i; break; } }
  if ( $failures || $bad >= 0 ) {
   $i = $bad >= 0 ? $bad : min( $count - 1, absint( $failures[0]['index'] ?? 0 ) );
   $c['browser_error'] = 'VALIDACAO_IMAGENS: página ' . ( (int) $p['cursor'] + $i + 1 ) . '/' . count( $c['delivery'] ) . ' não carregou no navegador pelo endereço do leitor. Publicação bloqueada.';
   $c['failed'] = true;
  } else {
   $p['cursor'] += $count; $p['at'] = time(); unset( $p['challenge'] );
   $c['browser_progress'] = $p;
   if ( $p['cursor'] === count( $c['delivery'] ) ) { $c['browser_approval'] = array( 'at' => time(), 'count' => $p['cursor'], 'fingerprint' => self::fingerprint( $id, $c ) ); }
  }
  // Check generation, pause and list again immediately before persisting.
  $live = (array) get_post_meta( $id, '_mbi_delivery_candidate', true );
  if ( ! $this->eligible( $id, $live ) || self::fingerprint( $id, $live ) !== self::fingerprint( $id, $c ) ) { throw new RuntimeException( 'Execução mudou durante a validação; resultado descartado.' ); }
  update_post_meta( $id, '_mbi_delivery_candidate', wp_slash( $c ) );
  if ( ! empty( $c['browser_error'] ) || self::approved( $id, $c ) ) {
   global $wpdb;
   $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}mbi_queue SET available_at=%s WHERE status='pending' AND job_type='chapter' AND manga_id=%d", current_time( 'mysql', true ), (int) $c['manga_id'] ) );
   if ( ! wp_next_scheduled( 'mbi_queue_wakeup' ) ) { wp_schedule_single_event( time(), 'mbi_queue_wakeup' ); }
  }
  return array( 'checked' => (int) ( $c['browser_progress']['cursor'] ?? 0 ), 'total' => count( $c['delivery'] ), 'done' => self::approved( $id, $c ), 'error' => $c['browser_error'] ?? '' );
 }
 public function ajax(): void {
  if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Acesso negado.' ), 403 ); }
  check_ajax_referer( 'mbi_browser_delivery', 'nonce' );
  if ( defined( 'MBI_VERSION' ) && version_compare( MBI_VERSION, '1.7.36', '>=' ) ) { wp_send_json_success( array( 'idle' => true, 'autonomous' => true ) ); } // Do not race with Cron from a previously open tab.
  try {
   $command = sanitize_key( $_POST['command'] ?? 'next' );
   if ( $command === 'result' ) {
    $sizes = json_decode( (string) wp_unslash( $_POST['sizes'] ?? '[]' ), true );
    $failures = json_decode( (string) wp_unslash( $_POST['failures'] ?? '[]' ), true );
    if ( ! is_array( $sizes ) || ! is_array( $failures ) ) { throw new RuntimeException( 'Resultado inválido.' ); }
    wp_send_json_success( $this->accept( absint( $_POST['id'] ?? 0 ), sanitize_text_field( $_POST['challenge'] ?? '' ), $sizes, $failures ) );
   }
   if ( $command === 'pump' ) { $this->importer->process_queue(); wp_send_json_success( array() ); }
   if ( $this->importer->is_paused() ) { wp_send_json_success( array( 'paused' => true ) ); }
   global $wpdb;
   $ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_mbi_delivery_candidate' ORDER BY meta_id DESC LIMIT 30" );
   foreach ( $ids as $id ) { $c = (array) get_post_meta( (int) $id, '_mbi_delivery_candidate', true ); if ( $this->eligible( (int) $id, $c ) ) { wp_send_json_success( $this->challenge( (int) $id, $c ) ); } }
   wp_send_json_success( array( 'idle' => true ) );
  } catch ( Throwable $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 400 ); }
 }
}
