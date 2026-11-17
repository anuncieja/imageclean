<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gera URLs opacas para o Worker/CDN sem fazer qualquer requisição de rede.
 *
 * Formato compatível com o Worker lmo-image-proxy:
 *   payload = IV(12) + AES-256-GCM(ciphertext + tag 16)
 *   token   = base64url(payload)
 */
final class MBI_Image_Proxy {
	/**
	 * Canonicaliza hosts internacionais para IDNA ASCII antes de emitir tokens.
	 * Nunca abre conexões externas; a URL original permanece armazenada em meta separado.
	 * Retorna vazio se o host não puder ser convertido com segurança.
	 */
	public static function normalize_source_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) { return ''; }
		if ( class_exists( 'MBI_Parser' ) && method_exists( 'MBI_Parser', 'normalize_media_host' ) ) {
			return MBI_Parser::normalize_media_host( $url );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return ''; }
		$host = (string) $parts['host'];
		if ( preg_match( '/[^\x00-\x7F]/', $host ) ) {
			$ascii = function_exists( 'idn_to_ascii' ) ? idn_to_ascii( $host, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 1 ) : false;
			if ( ! $ascii && 'livre.超高清.online' === strtolower( $host ) ) { $ascii = 'livre.xn--c5wx58dnej.online'; }
			if ( ! $ascii ) { return ''; }
			$url = preg_replace( '~^(https?://)' . preg_quote( $host, '~' ) . '(?=[:/]|$)~i', '$1' . $ascii, $url );
		}
		return esc_url_raw( (string) $url );
	}

	/** Syntax/IP check for stored browser images. Never performs DNS or HTTP. */
	public static function valid_browser_url( string $url ): bool {
		$url = self::normalize_source_url( $url );
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || ! in_array( strtolower( $p['scheme'] ?? '' ), array( 'http', 'https' ), true ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) ) { return false; }
		$host = strtolower( trim( $p['host'], '[]' ) );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || ! str_contains( $host, '.' ) && ! str_contains( $host, ':' ) ) { return false; }
		if ( isset( $p['port'] ) && ! in_array( (int) $p['port'], array( 80, 443 ), true ) ) { return false; }
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) { return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ); }
		return (bool) preg_match( '/^[a-z0-9.-]+$/i', $host ) && ! preg_match( '/^[0-9.]+$/', $host );
	}
	/** Public R2 bucket URLs only: no credentials, ports, private/custom endpoints. */
	public static function public_r2_url( string $url ): bool {
		$p = wp_parse_url( $url );
		return self::valid_browser_url( $url ) && is_array( $p ) && ( $p['scheme'] ?? '' ) === 'https' && ! isset( $p['port'] ) && ! isset( $p['query'] ) && (bool) preg_match( '/^pub-[a-f0-9]{32}\.r2\.dev$/D', strtolower( $p['host'] ?? '' ) );
	}
	/** Public HTTPS URLs from the parser; actual network checks always use WP safe HTTP. */
	public static function direct_public_url( string $url ): bool {
		$p = wp_parse_url( $url );
		return self::valid_browser_url( $url ) && is_array( $p ) && ( $p['scheme'] ?? '' ) === 'https' && ! isset( $p['port'] ) && ! isset( $p['fragment'] );
	}
	public static function base_url( array $settings ): string {
		$base = untrailingslashit( esc_url_raw( (string) ( $settings['image_proxy_base_url'] ?? '' ) ) );
		if ( ! $base || 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) ) {
			return '';
		}
		return $base;
	}

	public static function key_bytes( array $settings ) {
		$encoded = trim( (string) ( $settings['image_proxy_key_b64'] ?? '' ) );
		if ( '' === $encoded ) {
			return false;
		}
		$decoded = base64_decode( $encoded, true );
		return is_string( $decoded ) && 32 === strlen( $decoded ) ? $decoded : false;
	}

	public static function ready( array $settings ): bool {
		return ! empty( $settings['image_proxy_enabled'] )
			&& '' !== self::base_url( $settings )
			&& false !== self::key_bytes( $settings )
			&& function_exists( 'openssl_encrypt' );
	}

	public static function is_proxy_url( string $url, array $settings ): bool {
		$base = self::base_url( $settings );
		if ( ! $base || ! $url ) {
			return false;
		}
		$base_host = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		return $base_host && $base_host === $url_host && str_starts_with( $path, '/i/' );
	}

	/**
	 * Retorna a URL do proxy. Se o proxy estiver desativado, preserva a URL original.
	 * Não realiza fetch, download, HEAD, upload nem qualquer outra chamada externa.
	 */
	public static function protect( string $source_url, array $settings, bool $validate_network = true ): string {
		// Internationalized source domains must be punycoded BEFORE esc_url_raw.
		$source_url = self::normalize_source_url( $source_url );
		if ( ! $source_url || ! self::valid_browser_url( $source_url ) || ( $validate_network && ! wp_http_validate_url( $source_url ) ) ) {
			return '';
		}
		if ( self::is_proxy_url( $source_url, $settings ) ) {
			return $source_url;
		}
		// A repair may have set historical direct-delivery options site-wide. They
		// must NEVER bypass an explicitly enabled Worker/CDN.
		if ( empty( $settings['image_proxy_enabled'] ) ) {
			return $source_url;
		}
		if ( ! self::ready( $settings ) ) {
			return ''; // Fail closed: preserve draft/source metadata, not public origin URLs.
		}

		$key = self::key_bytes( $settings );
		$base = self::base_url( $settings );
		if ( false === $key || ! $base ) {
			return '';
		}
		$iv  = substr( hash_hmac( 'sha256', $source_url, $key, true ), 0, 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $source_url, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return '';
		}
		$token = rtrim( strtr( base64_encode( $iv . $ciphertext . $tag ), '+/', '-_' ), '=' );
		return esc_url_raw( $base . '/i/' . $token );
	}

	/**
	 * Recupera a URL original a partir de uma URL opaca já emitida pelo Worker.
	 * Não realiza nenhuma requisição externa; apenas decodifica e descriptografa
	 * localmente o token AES-256-GCM usando a mesma chave configurada no plugin.
	 */
	public static function unprotect( string $proxy_url, array $settings, bool $validate_network = true ): string {
		$proxy_url = esc_url_raw( trim( $proxy_url ) );
		if ( ! $proxy_url || ! self::is_proxy_url( $proxy_url, $settings ) || ! self::ready( $settings ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$path = (string) wp_parse_url( $proxy_url, PHP_URL_PATH );
		if ( ! preg_match( '#/i/([^/]+)$#', $path, $match ) ) {
			return '';
		}

		$token = strtr( (string) $match[1], '-_', '+/' );
		$padding = strlen( $token ) % 4;
		if ( $padding ) {
			$token .= str_repeat( '=', 4 - $padding );
		}
		$payload = base64_decode( $token, true );
		if ( ! is_string( $payload ) || strlen( $payload ) <= 28 ) {
			return '';
		}

		$key = self::key_bytes( $settings );
		if ( false === $key ) {
			return '';
		}
		$iv         = substr( $payload, 0, 12 );
		$tag        = substr( $payload, -16 );
		$ciphertext = substr( $payload, 12, -16 );
		if ( '' === $ciphertext ) {
			return '';
		}

		$source_url = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			''
		);
		if ( ! is_string( $source_url ) ) {
			return '';
		}
		$source_url = esc_url_raw( trim( $source_url ) );
		return $source_url && ( $validate_network ? wp_http_validate_url( $source_url ) : preg_match( '~^https?://[^/]+/~i', $source_url ) ) ? $source_url : '';
	}

	public static function unprotect_many( array $urls, array $settings ): array {
		$out = array();
		foreach ( $urls as $url ) {
			$source = self::unprotect( (string) $url, $settings );
			if ( $source ) {
				$out[ $source ] = true;
			}
		}
		return array_keys( $out );
	}

	public static function protect_many( array $urls, array $settings ): array {
		$out = array();
		foreach ( $urls as $url ) {
			$url = self::normalize_source_url( (string) $url );
			if ( ! $url || ! self::valid_browser_url( $url ) ) {
				continue;
			}
			$protected = self::protect( $url, $settings, false );
			if ( $protected ) {
				$out[] = $protected;
			}
		}
		return array_values( $out );
	}
}
