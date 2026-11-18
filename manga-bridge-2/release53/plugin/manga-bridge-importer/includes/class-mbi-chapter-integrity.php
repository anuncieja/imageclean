<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Availability receipts are bound to the exact chapter, list and delivery configuration. */
final class MBI_Chapter_Integrity {
 public static function delivery( array $urls ): array {
  $settings = (array) get_option( 'mbi_settings', array() );
  return array_map( static fn( $url ) => MBI_Image_Proxy::protect( (string) $url, $settings, false ), $urls );
 }
 /**
  * Verify every opaque delivery URL against the original using the current
  * AES-256-GCM key, entirely locally. Never GET the public Worker from PHP.
  */
 public static function signed_proxy_links_valid( array $sources, array $delivery ): bool {
  if ( ! $sources || count( $sources ) !== count( $delivery ) || count( $sources ) > 5000 ) { return false; }
  $settings = (array) get_option( 'mbi_settings', array() );
  if ( ! MBI_Image_Proxy::ready( $settings ) ) { return false; }
  foreach ( $sources as $i => $source ) {
   $normal = MBI_Image_Proxy::normalize_source_url( (string) $source );
   $signed = (string) ( $delivery[$i] ?? '' );
   if ( ! $normal || ! MBI_Image_Proxy::valid_browser_url( $normal ) || ! MBI_Image_Proxy::is_proxy_url( $signed, $settings )
    || MBI_Image_Proxy::unprotect( $signed, $settings, false ) !== $normal ) { return false; }
  }
  return true;
 }
 public static function snapshot( int $id, bool $fresh = true ): string {
  if ( $fresh ) { wp_cache_delete( $id, 'post_meta' ); wp_cache_delete( $id, 'posts' ); }
  $values = array( (int) wp_get_post_parent_id( $id ) );
  foreach ( array( '_mbi_source_url', '_mbi_chapter_number', '_mbi_expected_pages', '_mbi_source_image_urls', '_mbi_chapter_page_urls' ) as $key ) { $values[] = get_post_meta( $id, $key, true ); }
  $values[] = self::delivery( (array) get_post_meta( $id, '_mbi_source_image_urls', true ) );
  return hash( 'sha256', serialize( $values ) );
 }
 public static function valid( int $id ): bool {
  wp_cache_delete( $id, 'post_meta' ); wp_cache_delete( $id, 'posts' );
  $r = (array) get_post_meta( $id, '_mbi_delivery_receipt', true );
  $source = (array) get_post_meta( $id, '_mbi_source_image_urls', true );
  $stored = (array) get_post_meta( $id, '_mbi_chapter_page_urls', true );
  $expected = (int) get_post_meta( $id, '_mbi_expected_pages', true );
  $autonomous = ( $r['coverage'] ?? '' ) === 'server_origin_signed_proxy' && self::signed_proxy_links_valid( $source, $stored );
  return $expected > 0 && count( $source ) === $expected && count( $stored ) === $expected
   && $stored === self::delivery( $source ) && ( ( $r['version'] ?? 0 ) === 2 || ( ( $r['version'] ?? 0 ) === 1 && get_post_status( $id ) === 'publish' ) )
   && ( get_post_status( $id ) === 'publish' || $source === $stored || ( $r['coverage'] ?? '' ) === 'browser_all_images' || $autonomous )
   && (int) ( $r['count'] ?? 0 ) === $expected && ( $r['snapshot'] ?? '' ) === self::snapshot( $id, false );
 }
 public static function verified( int $id, string $coverage = 'server_image_addresses' ): void {
  $count = (int) get_post_meta( $id, '_mbi_expected_pages', true );
  update_post_meta( $id, '_mbi_delivery_receipt', array( 'version' => 2, 'count' => $count, 'snapshot' => self::snapshot( $id ), 'at' => time(), 'coverage' => $coverage ) );
  update_post_meta( $id, '_mbi_delivery_health', 'verified' );
  delete_post_meta( $id, '_mbi_delivery_error' );
  if ( self::valid( $id ) ) { update_post_meta( $id, '_mbi_chapter_complete', 1 ); }
 }
 public static function failed( int $id, string $message ): void {
  update_post_meta( $id, '_mbi_delivery_health', 'failed' );
  update_post_meta( $id, '_mbi_delivery_error', array( 'message' => $message, 'at' => time() ) );
  delete_post_meta( $id, '_mbi_delivery_receipt' );
  delete_post_meta( $id, '_mbi_chapter_complete' );
 }
 /** Sequential bounded validation; checkpoint every four images and before any yield. */
 public static function check( int $id, array &$candidate, callable $allowed, callable $checkpoint, bool $origin_only = false ): bool {
  $urls = (array) ( $candidate['delivery'] ?? array() );
  $source = (array) ( $candidate['urls'] ?? array() );
  if ( ! $urls || count( $source ) !== count( $urls ) || count( $urls ) > 5000 ) { throw new RuntimeException( 'VALIDACAO_IMAGENS: lista vazia ou inconsistente; publicação bloqueada.' ); }
  if ( self::delivery( $source ) !== $urls ) { throw new RuntimeException( 'Configuração de entrega mudou durante a validação; consulte a origem novamente.' ); }
  if ( ! $origin_only && MBI_Browser_Delivery::needs_browser( $candidate ) ) {
   if ( ! $allowed() ) { throw new MBI_Resource_Wait( 'Validação pausada.', 5 ); }
   if ( ! empty( $candidate['browser_error'] ) ) { throw new RuntimeException( $candidate['browser_error'] ); }
   if ( MBI_Browser_Delivery::approved( $id, $candidate ) ) { $candidate['cursor'] = count( $urls ); return true; }
   $candidate['awaiting_browser'] = true; $checkpoint( $candidate );
   throw new MBI_Resource_Wait( 'Aguardando validação real das imagens. Abra Mangás → Importador ou Novos capítulos e mantenha o painel aberto; publicação ainda bloqueada.', 15 );
  }
  $saved_cursor = (int) $candidate['cursor'];
  try {
  for ( $n = 0; $n < 24 && (int) $candidate['cursor'] < count( $urls ); $n++ ) {
   if ( ! $allowed( false ) ) { throw new MBI_Resource_Wait( 'Validação interrompida pelo administrador; conteúdo preservado.', 5 ); }
   if ( MBI_Resource_Guard::remaining() < 5 || MBI_Resource_Guard::requests_remaining() < 1 || ! MBI_Resource_Guard::has_memory() ) { break; }
   $i = (int) $candidate['cursor'];
   $probe_url = $origin_only ? (string) $source[$i] : (string) $urls[$i];
   // Two bounded transient waits before counting this page against the job's
   // permanent attempt budget. A slow host should not exhaust a whole manga
   // simply because a single GET exceeded the old fixed four-second timeout.
   $timeouts = (int) ( (int) ( $candidate['timeout_page'] ?? -1 ) === $i ? ( $candidate['timeout_count'] ?? 0 ) : 0 );
   $probe_timeout = min( $timeouts > 0 ? 9 : 6, max( 2, (int) floor( MBI_Resource_Guard::remaining() - 3 ) ) );
   if ( $probe_timeout < 3 ) { break; }
   $result = MBI_Media_Repair::probe( $probe_url, $probe_timeout, 65536 );
   if ( ! $allowed( false ) ) { throw new MBI_Resource_Wait( 'Validação interrompida pelo administrador; conteúdo preservado.', 5 ); }
   $width = (int) ( $result['width'] ?? 0 ); $height = (int) ( $result['height'] ?? 0 );
   $dimensions_valid = min( $width, $height ) > 0 && max( $width, $height ) >= 40;
   if ( empty( $result['ok'] ) || ! $dimensions_valid ) {
    if ( ! empty( $result['ok'] ) && ! $dimensions_valid ) { $result['message'] = sprintf( 'Imagem decodificável, mas dimensões insuficientes (%dx%d); revisão necessária', $width, $height ); }
    $message = 'VALIDACAO_IMAGENS: página ' . ( $i + 1 ) . '/' . count( $urls ) . ( $origin_only ? ' não abre na origem: ' : ' não abre na entrega: ' ) . $result['message'] . '. URL: ' . $probe_url;
    if ( $origin_only && preg_match( '/(?:cURL error 28|timed?\s*out|tempo limite|tempo esgotado)/iu', (string) $result['message'] ) ) {
     $candidate['timeout_page'] = $i;
     $candidate['timeout_count'] = $timeouts + 1;
     $candidate['error'] = $message;
     $checkpoint( $candidate );
     if ( $candidate['timeout_count'] <= 2 ) {
      throw new MBI_Resource_Wait( sprintf( 'Origem lenta: imagem %d/%d. Tentativa automática de rede %d/2 antes de contabilizar falha; capítulo preservado.', $i + 1, count( $urls ), $candidate['timeout_count'] ), 45 );
     }
    }
    if ( ! $origin_only && $urls[$i] !== $source[$i] && preg_match( '/HTTP (?:401|403|404|410)\b/', $result['message'] ) && MBI_Resource_Guard::remaining() >= 5 ) {
     $origin = MBI_Media_Repair::probe( (string) $source[$i], 4, 65536 );
     if ( ! $allowed() ) { throw new MBI_Resource_Wait( 'Validação interrompida.', 5 ); }
     $message .= ! empty( $origin['ok'] ) ? ' Imagem original válida; entrega/Worker precisa aceitar o host ' . wp_parse_url( (string) $source[$i], PHP_URL_HOST ) . '.' : ' Origem também não validada: ' . $origin['message'] . '.';
    }
    if ( ! $origin_only && ( get_post_status( $id ) !== 'publish' || (array) get_post_meta( $id, '_mbi_chapter_page_urls', true ) === $urls ) ) { self::failed( $id, $message ); }
    // Transient transport failures retain the candidate/cursor for bounded retries.
    if ( ! preg_match( '/HTTP (?:408|425|429|5\d\d)\b|cURL\s*error|timed?\s*out|timeout|tempo\s*(?:limite|esgotado)|erro de rede|Could not resolve host|getaddrinfo|connection\s+(?:reset|refused|failed)/iu', (string) $result['message'] ) ) { $candidate['failed'] = true; } else { unset( $candidate['failed'] ); }
    $candidate['error'] = $message; $checkpoint( $candidate );
    throw new RuntimeException( $message );
   }
   $candidate['cursor'] = $i + 1; unset( $candidate['error'], $candidate['failed'], $candidate['timeout_count'], $candidate['timeout_page'] );
   if ( (int) $candidate['cursor'] - $saved_cursor >= 4 ) { $checkpoint( $candidate ); $saved_cursor = (int) $candidate['cursor']; }
  }
  } finally {
   // A timeout or pause cannot erase pages already validated. The callback checks
   // generation, administrator stop, metadata snapshot and configuration again.
   if ( (int) $candidate['cursor'] !== $saved_cursor && $allowed() ) { $checkpoint( $candidate ); }
  }
  if ( (int) $candidate['cursor'] === count( $urls ) && ! $allowed() ) { throw new MBI_Resource_Wait( 'Validação pausada antes da conclusão.', 5 ); }
  return (int) $candidate['cursor'] === count( $urls );
 }
 /** Imported drafts cannot bypass validation through the editor or another publish path. */
 public static function gate( array $data, array $postarr ): array {
  $id = (int) ( $postarr['ID'] ?? 0 );
  if ( $id && ( $data['post_type'] ?? '' ) === 'mbi_chapter' && ( $data['post_status'] ?? '' ) === 'publish'
   && get_post_status( $id ) !== 'publish' && get_post_meta( $id, '_mbi_source_url', true ) && ! self::valid( $id ) ) {
   $data['post_status'] = 'draft';
  }
  return $data;
 }
}
