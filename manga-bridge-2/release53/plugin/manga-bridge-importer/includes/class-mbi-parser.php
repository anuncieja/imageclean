<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A source loader contract failure must preserve the queue, not reject every work. */
final class MBI_Source_Compatibility_Exception extends RuntimeException {}

final class MBI_Parser {
	private ?DOMDocument $dom = null;
	private ?DOMXPath $xpath = null;
	private string $base_url = '';

	/** Free the previous DOM before allocating another full source document. */
	public function release(): void { $this->xpath = null; $this->dom = null; }

	public function load( string $html, string $base_url ): void {
		if ( ! class_exists( 'DOMDocument' ) ) {
			throw new RuntimeException( 'A extensão PHP DOM/XML não está ativa no servidor.' );
		}

		$this->release();
		$this->base_url = $base_url;
		$this->dom      = new DOMDocument();
		$previous       = libxml_use_internal_errors( true );
		$encoded        = function_exists( 'mb_encode_numericentity' )
			? mb_encode_numericentity( $html, array( 0x80, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' )
			: '<?xml encoding="UTF-8">' . $html;
		$this->dom->loadHTML( $encoded, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$this->xpath = new DOMXPath( $this->dom );
	}

	public function parse_catalog(): array {
		$this->assert_loaded();
		$manga_urls     = array();
		$pagination     = array();
		$max_page       = 1;
		$source_host    = wp_parse_url( $this->base_url, PHP_URL_HOST );

		foreach ( $this->xpath->query( '//a[@href]' ) as $anchor ) {
			$href = $this->absolute_url( $anchor->getAttribute( 'href' ) );
			if ( ! $href || wp_parse_url( $href, PHP_URL_HOST ) !== $source_host ) {
				continue;
			}

			$path = (string) wp_parse_url( $href, PHP_URL_PATH );
			if ( preg_match( '#^/manga/[^/]+/?$#i', $path ) ) {
				$manga_urls[ untrailingslashit( $href ) . '/' ] = true;
			}

			if ( preg_match( '#^/manga/page/(\d+)/?$#i', $path, $match ) ) {
				$page = (int) $match[1];
				$max_page = max( $max_page, $page );
				$pagination[ untrailingslashit( $href ) . '/' ] = true;
			}
		}

		return array(
			'manga_urls'     => array_keys( $manga_urls ),
			'pagination_urls'=> array_keys( $pagination ),
			'max_page'       => $max_page,
		);
	}

	/** Only a complete explicit root list can authorize removing local chapters. */
	public function authoritative_chapter_urls(): array {
		$this->assert_loaded();
		$count_nodes = $this->xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," manga-chapters-count ")]');
		if ($count_nodes->length !== 1 || !preg_match('/([0-9][0-9.,]*)\s*cap[ií]tulo/iu', $count_nodes->item(0)->textContent, $m)) throw new RuntimeException('Origem sem contagem explícita confiável; catálogo preservado.');
		$count = (int)preg_replace('/[^0-9]/','',$m[1]);
		$urls = array();
		foreach ($this->xpath->query('//ul[contains(concat(" ",normalize-space(@class)," ")," chapters-list ")]//a[contains(concat(" ",normalize-space(@class)," ")," chapter-link ")][@href]') as $a) {
			$u=$this->absolute_url($a->getAttribute('href'));
			if(wp_parse_url($u,PHP_URL_HOST)!==wp_parse_url($this->base_url,PHP_URL_HOST)) throw new RuntimeException('Lista aponta para outra origem; preservada.');
			if(!preg_match('#^/capitulo/[^/]+/?$#i',(string)wp_parse_url($u,PHP_URL_PATH))) throw new RuntimeException('Link de capítulo não reconhecido; preservado.');
			$urls[untrailingslashit($u).'/']=true;
		}
		if(!$count || count($urls)!==$count) throw new RuntimeException('Lista da origem vazia, parcial ou paginada; catálogo preservado.');
		$urls=array_keys($urls);sort($urls,SORT_STRING);return $urls;
	}


	/**
	 * v1.7.37: leitura NÃO destrutiva de capítulos para detectar novidades.
	 * Uma página paginada/parcial não autoriza remoções nem prova ausência de novos
	 * capítulos; porém URLs válidas e explicitamente listadas podem ser candidatos.
	 * A verificação autoritativa usada para reconciliação continua estrita.
	 */
	public function update_chapter_snapshot( string $work_url = '' ): array {
		$this->assert_loaded();
		$base_host = strtolower( (string) wp_parse_url( $this->base_url, PHP_URL_HOST ) );
		$work_slug = basename( trim( (string) wp_parse_url( $work_url ?: $this->base_url, PHP_URL_PATH ), '/' ) );
		$work_slug = rawurldecode( $work_slug );
		$count_nodes = $this->xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," manga-chapters-count ")]' );
		$reported = 0;
		if ( $count_nodes->length === 1 && preg_match( '/([0-9][0-9.,]*)\s*cap[ií]tulo/iu', $count_nodes->item( 0 )->textContent, $match ) ) {
			$reported = (int) preg_replace( '/[^0-9]/', '', $match[1] );
		}
		// Apenas links dentro da lista de capítulos pertencente à obra.
		// Nunca varrer todos os anchors: banners e recomendações são falsos positivos.
		$nodes = $this->xpath->query(
			'//ul[contains(concat(" ",normalize-space(@class)," ")," chapters-list ")]//a[@href]'
			. ' | //*[contains(concat(" ",normalize-space(@class)," ")," manga-chapters ")]//a[@href]'
			. ' | //*[contains(concat(" ",normalize-space(@class)," ")," wp-manga-chapter ")]//a[@href]'
		);
		$urls = array();
		foreach ( $nodes as $anchor ) {
			$href = $this->absolute_url( $anchor->getAttribute( 'href' ) );
			if ( ! $href || strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) ) !== $base_host ) { continue; }
			if ( strtolower( (string) wp_parse_url( $href, PHP_URL_SCHEME ) ) !== 'https' ) { continue; }
			$path = (string) wp_parse_url( $href, PHP_URL_PATH );
			$valid = (bool) preg_match( '#^/capitulo/[^/]+/?$#i', $path );
			if ( ! $valid && $work_slug !== '' ) {
				$valid = (bool) preg_match( '#^/manga/' . preg_quote( $work_slug, '#' ) . '/capitulo-[^/]+/?$#i', rawurldecode( $path ) );
			}
			if ( ! $valid ) { continue; }
			$urls[ untrailingslashit( $href ) . '/' ] = true;
			if ( count( $urls ) >= 10000 ) { break; }
		}
		if ( ! $urls ) {
			throw new RuntimeException( 'Nenhum link de capítulo confiável encontrado na lista da origem; catálogo preservado.' );
		}
		$listed = count( $urls );
		return array(
			'chapter_urls'    => array_keys( $urls ),
			'reported_count'  => $reported,
			'listed_count'    => $listed,
			'partial'         => $reported < 1 || $listed !== $reported,
		);
	}

	/** Explicit pagination confined to the same work. Never follow catalogue links. */
	public function import_chapter_inventory( string $work_url ): array {
		$snapshot = $this->update_chapter_snapshot( $work_url );
		$host = strtolower( (string) wp_parse_url( $work_url, PHP_URL_HOST ) );
		$path = rtrim( (string) wp_parse_url( $work_url, PHP_URL_PATH ), '/' );
		$pages = array();
		foreach ( $this->xpath->query( '//a[@href]' ) as $a ) {
			$u = $this->absolute_url( $a->getAttribute( 'href' ) );
			if ( strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) !== $host || wp_parse_url( $u, PHP_URL_SCHEME ) !== 'https' ) { continue; }
			$p = rtrim( (string) wp_parse_url( $u, PHP_URL_PATH ), '/' );
			$query = (string) wp_parse_url( $u, PHP_URL_QUERY );
			if ( preg_match( '#^' . preg_quote( $path, '#' ) . '/page/[0-9]+$#', $p ) || ( $p === $path && preg_match( '/(?:^|&)(?:page|paged|chapter_page|chapters_page|cpage)=[0-9]+(?:&|$)/', $query ) ) ) {
				$pages[ preg_replace( '/#.*$/', '', $u ) ] = true;
			}
		}
		$snapshot['pagination_urls'] = array_keys( $pages );
		return $snapshot;
	}

	/** Metadata used by the supplied chapter-loading.min.js; never execute source JS. */
	public function slimeread_loader(): array {
		$nodes = $this->xpath->query( '//*[@id="sr-load-chapters"]' );
		if ( $nodes->length === 0 ) { return array(); }
		if ( $nodes->length !== 1 ) { throw new MBI_Source_Compatibility_Exception( 'Mais de um carregador de capítulos na página; revisão da origem necessária.' ); }
		$button = $nodes->item( 0 ); $config = null;
		foreach ( $this->xpath->query( '//script' ) as $script ) {
			$config = $this->chapter_loader_config( $script );
			if ( $config !== null ) { break; }
		}
		if ( ! is_array( $config ) || empty( $config['rest'] ) || ! is_string( $config['rest'] ) || ( isset( $config['nonce'] ) && ! is_string( $config['nonce'] ) ) ) { throw new MBI_Source_Compatibility_Exception( 'Carregador de capítulos encontrado sem configuração REST reconhecida.' ); }
		$rest = $this->absolute_url( (string) $config['rest'] );
		if ( wp_parse_url( $rest, PHP_URL_SCHEME ) !== 'https' || strtolower( (string) wp_parse_url( $rest, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( $this->base_url, PHP_URL_HOST ) ) ) { throw new MBI_Source_Compatibility_Exception( 'REST de capítulos fora da origem autorizada.' ); }
		$id = $button->getAttribute( 'data-manga' ); $offset = $button->getAttribute( 'data-offset' ); $total = $button->getAttribute( 'data-total' );
		if ( ! ctype_digit( $id ) || ! ctype_digit( $offset ) || ! ctype_digit( $total ) || (int) $id < 1 || (int) $total < 1 || (int) $offset > (int) $total ) { throw new MBI_Source_Compatibility_Exception( 'Metadados inválidos do carregador de capítulos.' ); }
		if ( isset( $config['viewId'] ) && ( ! is_scalar( $config['viewId'] ) || (string) $config['viewId'] !== $id ) ) { throw new MBI_Source_Compatibility_Exception( 'Identificador REST difere da obra do carregador.' ); }
		$query = array(); parse_str( (string) wp_parse_url( $rest, PHP_URL_QUERY ), $query );
		if ( isset( $query['rest_route'] ) ) {
			if ( ! is_string( $query['rest_route'] ) || rtrim( $query['rest_route'], '/' ) !== '/slimeread/v1' ) { throw new MBI_Source_Compatibility_Exception( 'Rota REST de capítulos não reconhecida.' ); }
			$rest = add_query_arg( 'rest_route', '/slimeread/v1/chapters/' . $id, $rest );
		} else {
			if ( rtrim( (string) wp_parse_url( $rest, PHP_URL_PATH ), '/' ) !== '/wp-json/slimeread/v1' || wp_parse_url( $rest, PHP_URL_QUERY ) ) { throw new MBI_Source_Compatibility_Exception( 'Caminho REST de capítulos não reconhecido.' ); }
			$rest = rtrim( $rest, '/' ) . '/chapters/' . $id;
		}
		return array( 'endpoint' => $rest, 'offset' => (int) $offset, 'total' => (int) $total, 'nonce' => (string) ( $config['nonce'] ?? '' ) );
	}

	/** Read inert metadata only. No remote script fetch, eval or JavaScript execution. */
	private function chapter_loader_config( DOMElement $script ): ?array {
		$src = trim( $script->getAttribute( 'src' ) );
		$text = $script->textContent;
		if ( $src !== '' ) {
			if ( strlen( $src ) > 524288 || ! preg_match( '#^data:(?:text|application)/(?:javascript|ecmascript)(?:;charset=[a-z0-9._-]+)?(;base64)?,(.*)$#is', $src, $uri ) ) { return null; }
			$payload = rawurldecode( $uri[2] );
			$text = ! empty( $uri[1] ) ? base64_decode( $payload, true ) : $payload;
		}
		if ( ! is_string( $text ) || strlen( $text ) > 262144 || ! preg_match( '/\b(?:(?:var|let|const)\s+srPerformance|window\s*\.\s*srPerformance)\s*=\s*\{/', $text, $match, PREG_OFFSET_CAPTURE ) ) { return null; }
		// Locate one balanced JSON object; braces inside strings are not delimiters.
		$start = $match[0][1] + strlen( $match[0][0] ) - 1;
		$depth = 0; $quoted = false; $escaped = false;
		for ( $i = $start, $length = strlen( $text ); $i < $length; $i++ ) {
			$ch = $text[$i];
			if ( $quoted ) {
				if ( $escaped ) { $escaped = false; }
				elseif ( $ch === '\\' ) { $escaped = true; }
				elseif ( $ch === '"' ) { $quoted = false; }
				continue;
			}
			if ( $ch === '"' ) { $quoted = true; }
			elseif ( $ch === '{' ) { $depth++; }
			elseif ( $ch === '}' && --$depth === 0 ) {
				$value = json_decode( substr( $text, $start, $i - $start + 1 ), true, 16 );
				return is_array( $value ) ? $value : null;
			}
		}
		return null;
	}

	public function parse_manga( string $source_url, bool $for_import = false ): array {
		$this->assert_loaded();
		$title       = $this->text_by_xpath( '//h1[contains(concat(" ", normalize-space(@class), " "), " manga-title ")]' );
		$title       = $title ?: $this->meta( 'property', 'og:title' );
		$description = $this->meta( 'name', 'description' );
		$cover       = $this->image_by_xpath( '//img[contains(concat(" ", normalize-space(@class), " "), " manga-cover-image ")]' );
		$cover       = $cover ?: $this->image_by_xpath( '//div[contains(concat(" ", normalize-space(@class), " "), " manga-cover ")]//img[1]' );
		// Madara/WP-Manga (mangalivre.to e clones): a capa costuma ficar em summary_image.
		$cover       = $cover ?: $this->image_by_xpath( '//*[contains(concat(" ", normalize-space(@class), " "), " summary_image ")]//img[1]' );
		$cover       = $cover ?: $this->image_by_xpath( '//*[contains(concat(" ", normalize-space(@class), " "), " tab-thumb ")]//img[1]' );
		$cover       = $cover ?: $this->image_by_xpath( '//*[contains(concat(" ", normalize-space(@class), " "), " c-tabs-item__content ")]//img[1]' );
		$cover       = $cover ?: $this->meta( 'property', 'og:image' );
		$cover       = $cover ?: $this->meta( 'name', 'twitter:image' );
		$cover       = $this->absolute_url( $cover );
		if ( ! $this->is_content_image( $cover ) ) {
			$cover = '';
		}
		$body_text   = $this->body_text();

		if ( $title ) {
			$title = preg_replace( '/\s*[|–-]\s*Manga\s*Livre.*$/iu', '', $title );
		}
		if ( ! $title ) {
			$title = $this->best_heading();
		}

		$sinopsis = $this->text_by_xpath( '//div[contains(concat(" ", normalize-space(@class), " "), " manga-synopsis ")]//div[contains(concat(" ", normalize-space(@class), " "), " synopsis-content ")]' );
		$sinopsis = $sinopsis ?: $this->section_after_heading( 'Sinopse' );
		$sinopsis = $sinopsis ?: $this->section_after_heading( 'Descrição' );
		$sinopsis = $sinopsis ?: $this->section_after_heading( 'Descricao' );
		$sinopsis_length = function_exists( 'mb_strlen' ) ? mb_strlen( $sinopsis ) : strlen( $sinopsis );
		if ( $sinopsis_length < 40 ) {
			$sinopsis = $description;
		}

		$chapter_urls = array();
		$genres       = array();
		$types        = array();

		foreach ( $this->xpath->query( '//a[@href]' ) as $anchor ) {
			$href = $this->absolute_url( $anchor->getAttribute( 'href' ) );
			if ( ! $href ) {
				continue;
			}

			$path = (string) wp_parse_url( $href, PHP_URL_PATH );
			$text = $this->clean_text( $anchor->textContent );
			if ( preg_match( '#^/capitulo/[^/]+/?$#i', $path ) || preg_match( '#^/manga/[^/]+/capitulo-[^/]+/?$#i', $path ) ) {
				$chapter_urls[ untrailingslashit( $href ) . '/' ] = true;
			}
			if ( $text && preg_match( '#/(genero|genre|categoria)/#i', $path ) ) {
				$genres[ $text ] = true;
			}
			if ( $text && preg_match( '#/(tipo|type)/#i', $path ) ) {
				$type_label = preg_match( '/^manga$/iu', $text ) ? 'Mangá' : $text;
				$types[ $type_label ] = true;
			}
		}

		foreach ( $this->xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " manga-tag ")]' ) as $tag ) {
			$label = $this->clean_text( $tag->textContent );
			if ( ! $label ) {
				continue;
			}
			if ( preg_match( '/^(mang[aá]|manhwa|manhua|webtoon)$/iu', $label ) ) {
				$types[ preg_match( '/^mang[aá]$/iu', $label ) ? 'Mangá' : ucfirst( strtolower( $label ) ) ] = true;
			} else {
				$genres[ $label ] = true;
			}
		}

		$structured_type = $this->structured_field( 'Tipo' );
		if ( $structured_type && preg_match( '/^(mang[aá]|manhwa|manhua|webtoon|novel|light novel)$/iu', $structured_type ) ) {
			$type_label = preg_match( '/^mang[aá]$/iu', $structured_type ) ? 'Mangá' : ucfirst( strtolower( $structured_type ) );
			$types[ $type_label ] = true;
		}

		$status  = $this->structured_field( 'Status' ) ?: $this->regex_value( $body_text, '/Status\s*:?\s*(Em Lançamento|Em Andamento|Completo|Finalizado|Conclu[ií]do|Pausado)/iu' );
		$year    = $this->structured_field( 'Ano' );
		$year    = $year ?: $this->structured_field( 'Lançamento' );
		$year    = $year ?: $this->structured_field( 'Lancamento' );
		if ( $year && preg_match( '/(19|20)\d{2}/', $year, $year_match ) ) {
			$year = $year_match[0];
		}
		$year    = $year ?: $this->regex_value( $body_text, '/(?:Ano|Lançamento|Lancamento)\s*:?\s*((?:19|20)\d{2})/iu' );
		$author  = $this->structured_field( 'Autor' ) ?: $this->structured_field( 'Autor(es)' );
		$author  = $author ?: $this->regex_value( $body_text, '/Autor(?:\(es\))?\s*:?\s*(.{1,180}?)(?=\s+(?:Artista|Ano|Lançamento|Lancamento|Status|Tipo|Gênero|Genero|Categorias|Capítulos|Capitulos)\s*:|$)/iu' );
		$artist  = $this->structured_field( 'Artista' ) ?: $this->regex_value( $body_text, '/Artista\s*:\s*(.{1,180}?)(?=\s+(?:Autor|Ano|Status|Tipo|Gênero|Genero|Capítulos|Capitulos)\s*:|$)/iu' );
		$rating  = $this->text_by_xpath( '//*[contains(concat(" ", normalize-space(@class), " "), " manga-rating ")]//span[1]' );
		$rating  = $rating ?: $this->regex_value( $body_text, '/(?:Nota|Avalia(?:ç|c)ão)\s*:?\s*([0-9]+(?:[.,][0-9]+)?)/iu' );
		$readers = $this->regex_value( $body_text, '/([0-9.]+)\s+leitores/iu' );
		$views   = $this->regex_value( $body_text, '/([0-9.]+)\s+visualiza(?:ç|c)(?:ões|oes)/iu' );

		if ( ! $status && preg_match( '/\b(Em Lançamento|Em Andamento|Completo|Finalizado|Conclu[ií]do|Pausado)\b/iu', $body_text, $match ) ) {
			$status = $match[1];
		}

		if ( preg_match( '/^Conclu[ií]do$/iu', $status ) ) { $status = 'Finalizado'; }

		if ( ! $types ) {
			foreach ( array( 'MANGA' => 'Mangá', 'MANHWA' => 'Manhwa', 'MANHUA' => 'Manhua', 'WEBTOON' => 'Webtoon' ) as $candidate => $label ) {
				if ( preg_match( '/\b' . preg_quote( $candidate, '/' ) . '\b/iu', $body_text ) ) {
					$types[ $label ] = true;
					break;
				}
			}
		}

		if ( ! $genres ) {
			$known_genres = array(
				'Ação', 'Adulto', 'Animais', 'Aventura', 'Boys Love', 'Comédia', 'Crime', 'Drama',
				'Ecchi', 'Escolar', 'Esportes', 'Fantasia', 'Ficção Científica', 'Girls Love', 'Harém',
				'Histórico', 'Horror', 'Isekai', 'Josei', 'Magia', 'Mistério', 'Psicológico', 'Romance',
				'Seinen', 'Shoujo', 'Shounen', 'Slice of Life', 'Sobrenatural', 'Suspense', 'Tragédia',
				'4-Koma', 'Action', 'Adventure', 'Animals', 'Comedy', 'Fantasy', 'Monster Girls',
				'School Life', 'Sci-Fi', 'Supernatural', 'Thriller',
			);
			foreach ( $known_genres as $candidate ) {
				if ( preg_match( '/(?:^|[\s,|])' . preg_quote( $candidate, '/' ) . '(?:$|[\s,|])/iu', $body_text ) ) {
					$genres[ $candidate ] = true;
				}
			}
		}

		if ($this->xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," manga-chapters-count ")]')->length) {
			$confirmed = array_fill_keys($for_import ? $this->update_chapter_snapshot()['chapter_urls'] : $this->authoritative_chapter_urls(), true);
			$chapter_urls = array_intersect_key($chapter_urls, $confirmed);
		}
		$data = array(
			'source_url'   => $source_url,
			'title'        => $this->clean_text( $title ),
			'synopsis'     => wp_kses_post( wpautop( $this->clean_text( $sinopsis ) ) ),
			'cover_url'    => $this->absolute_url( $cover ),
			'status'       => $this->clean_text( $status ),
			'year'         => $this->clean_text( $year ),
			'author'       => $this->clean_text( $author ),
			'artist'       => $this->clean_text( $artist ),
			'rating'       => $rating ? (float) str_replace( ',', '.', $rating ) : 0,
			'readers'      => $this->number( $readers ),
			'views'        => $this->number( $views ),
			'genres'       => array_keys( $genres ),
			'types'        => array_keys( $types ),
			'chapter_urls' => array_keys( $chapter_urls ),
		);

		$data['source_hash'] = md5( wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $data;
	}

	/** A chapter belongs to this work only inside its chapter list or exact URL prefix. */
	public function recovery_chapter_index( string $source_url ): array {
		$this->assert_loaded(); $map = array();
		$host = strtolower( (string) wp_parse_url( $source_url, PHP_URL_HOST ) );
		$slug = basename( trim( (string) wp_parse_url( $source_url, PHP_URL_PATH ), '/' ) );
		foreach ( $this->xpath->query( '//a[@href]' ) as $anchor ) {
			$url = $this->absolute_url( $anchor->getAttribute( 'href' ) );
			if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) { continue; }
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( ! preg_match( '#^/(?:capitulo/[^/]+|manga/[^/]+/capitulo-[^/]+)/?$#i', $path ) ) { continue; }
			$scoped = false; $node = $anchor->parentNode;
			while ( $node instanceof DOMElement ) {
				$classes = ' ' . $node->getAttribute( 'class' ) . ' ';
				if ( preg_match( '/(?:chapter-list|chapters-list|manga-chapters|wp-manga-chapter|chapters-wrapper|chapter-item)/i', $classes ) || in_array( $node->getAttribute( 'id' ), array( 'chapter-list', 'chapters-list', 'manga-chapters' ), true ) ) { $scoped = true; break; }
				$node = $node->parentNode;
			}
			$own_prefix = preg_match( '#^/capitulo/' . preg_quote( $slug, '#' ) . '-capitulo-#i', $path ) || str_starts_with( $path, '/manga/' . $slug . '/capitulo-' );
			if ( ! $scoped && ! $own_prefix ) { continue; }
			$text = $this->clean_text( $anchor->textContent );
			$number = ''; if ( preg_match( '/(?:cap[ií]tulo|chapter|cap\.|caso|case)\s*#?\s*(\d+(?:[.,]\d+)?)/iu', $text, $m ) ) { $number = $m[1]; }
			if ( preg_match( '/(?:capitulo|caso|case)-(\d+(?:[.,-]\d+)?)(?:\/|$)/i', $path, $m ) ) {
				$from_url = str_replace( '-', '.', $m[1] );
				if ( $number !== '' && self::recovery_number( $number ) !== self::recovery_number( $from_url ) ) { continue; }
				$number = $from_url;
			}
			$key = self::recovery_number( $number );
			if ( $key === '' ) { continue; }
			$map[$key][] = untrailingslashit( $url ) . '/';
		}
		foreach ( $map as $key => $urls ) { $map[$key] = array_values( array_unique( $urls ) ); }
		return $map;
	}
	public static function recovery_number( string $number ): string {
		$number = trim( str_replace( ',', '.', $number ) );
		if ( ! preg_match( '/^\d+(?:\.\d+)?$/D', $number ) ) { return ''; }
		$parts = explode( '.', $number ); $whole = ltrim( $parts[0], '0' ); $whole = $whole === '' ? '0' : $whole;
		$fraction = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
		return $whole . ( $fraction !== '' ? '.' . $fraction : '' );
	}

	public function parse_chapter( string $source_url, bool $validate_network = true, bool $strict_reader = false ): array {
		$this->assert_loaded();
		$title  = $this->meta( 'property', 'og:title' ) ?: $this->best_heading();
		$images = array();
		$fallback = array();

		// Leitores Madara/WP-Manga (como mangalivre.to) usam contêineres próprios.
		// Priorizar esses nós evita capturar capa, widgets e imagens do rodapé como páginas.
		$reader_query = '//*[contains(concat(" ", normalize-space(@class), " "), " reading-content ") or contains(concat(" ", normalize-space(@class), " "), " chapter-content ") or contains(concat(" ", normalize-space(@class), " "), " page-break ") or @id="readerarea"]//img';
		foreach ( $this->xpath->query( $reader_query ) as $image ) {
			if ( ! $image instanceof DOMElement ) {
				continue;
			}
			$url = $this->image_url_from_element( $image );
			if ( $this->is_content_image( $url, $validate_network ) ) {
				$images[ $url ] = true;
			}
		}

		$has_reader_images = ! empty( $images );
		foreach ( $this->xpath->query( '//img' ) as $image ) {
			if ( $strict_reader && $has_reader_images ) { continue; }
			if ( ! $image instanceof DOMElement ) {
				continue;
			}
			$url = $this->image_url_from_element( $image );

			if ( ! $this->is_content_image( $url, $validate_network ) ) {
				continue;
			}

			$alt       = $this->clean_text( $image->getAttribute( 'alt' ) );
			$class     = strtolower( $image->getAttribute( 'class' ) );
			$parent    = $image->parentNode instanceof DOMElement ? strtolower( $image->parentNode->getAttribute( 'class' ) ) : '';
			$is_reader = preg_match( '/p[aá]gina\s*\d+/iu', $alt ) || preg_match( '/reader|reading|chapter-image|manga-page|page-break|reading-content|chapter-content/i', $class . ' ' . $parent ) || ( ! $strict_reader && str_contains( strtolower( $url ), '/wp-content/uploads/wp-manga/data/' ) );

			$fallback[ $url ] = true;
			if ( $is_reader ) {
				$images[ $url ] = true;
			}
		}

		if ( ! $images && ! $strict_reader ) {
			$images = $fallback;
		}

		$number = '';
		$path   = (string) wp_parse_url( $source_url, PHP_URL_PATH );
		if ( preg_match( '/(?:^|\/|-)(?:capitulo|caso|case)-([0-9]+(?:[.,-][0-9]+)?)(?:-|\/|$)/iu', $path, $match ) ) {
			$number = str_replace( array( ',', '-' ), '.', $match[1] );
		} elseif ( preg_match( '/(?:cap[ií]tulo|chapter|caso|case)\s*(?:n[º°o.]\s*)?[:#-]?\s*([0-9]+(?:[.,][0-9]+)?)/iu', $title, $match ) ) {
			$number = str_replace( ',', '.', $match[1] );
		}
		// Some valid readers put the number in the chapter H1 rather than og:title.
		// Accept only a chapter-labeled heading, never unrelated page numbers.
		if ( $number === '' ) {
			foreach ( $this->xpath->query( '//h1 | //*[contains(concat(" ",normalize-space(@class)," ")," chapter-title ")]' ) as $heading ) {
				if ( preg_match( '/(?:cap[ií]tulo|chapter|caso|case)\s*(?:n[º°o.]\s*)?[:#-]?\s*([0-9]+(?:[.,][0-9]+)?)/iu', $this->clean_text( $heading->textContent ), $match ) ) {
					$number = str_replace( ',', '.', $match[1] );
					break;
				}
			}
		}
		// A chapter-specific URL may end in '-30' without the word 'capitulo'.
		// This is a candidate only: import_chapter cross-checks the work's source list.
		if ( $number === '' && preg_match( '#^/(?:capitulo|chapter)/[^/]+[-_](\d{1,5}(?:[.,]\d{1,3})?)/?$#iu', $path, $match ) ) {
			$number = str_replace( ',', '.', $match[1] );
		}

		return array(
			'source_url' => $source_url,
			'title'      => $this->clean_text( $title ),
			'number'     => $this->clean_text( $number ),
			'image_urls' => $this->complete_reader_pages() ?: array_keys( $images ),
		);
	}

	/** Evidence of an explicitly empty reader, bound to both the work and chapter URLs. */
	public function confirmed_empty_reader( string $work, string $chapter ): bool {
		$this->assert_loaded();
		$same = static fn( string $a, string $b ): bool => $a !== '' && untrailingslashit( $a ) === untrailingslashit( $b );
		$canonical = $this->xpath->query( '//link[@rel="canonical"]/@href' );
		if ( $canonical->length !== 1 || ! $same( $this->absolute_url( $canonical->item(0)->nodeValue ), $chapter ) ) { return false; }
		$work_links = $this->xpath->query( '//h1[contains(concat(" ",normalize-space(@class)," ")," manga-title ")]//a[@href]' );
		if ( $work_links->length !== 1 || ! $same( $this->absolute_url( $work_links->item(0)->getAttribute( 'href' ) ), $work ) ) { return false; }
		$reader = $this->xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," chapter-content ")]//*[contains(concat(" ",normalize-space(@class)," ")," chapter-text-content ")]' );
		if ( $reader->length !== 1 || trim( $reader->item(0)->textContent ) !== '' || $this->xpath->query( './/img | .//script | .//iframe | .//*[@data-src]', $reader->item(0) )->length ) { return false; }
		return $this->xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," chapter-content ")]//img | //*[contains(concat(" ",normalize-space(@class)," ")," chapter-image-container ")]' )->length === 0;
	}
	/** Read the work heading without language badges or other nested interface text. */
	public function work_heading(): string {
		$this->assert_loaded(); $nodes = $this->xpath->query( '//h1[contains(concat(" ",normalize-space(@class)," ")," manga-title ")]' );
		if ( $nodes->length !== 1 ) { return ''; }
		$parts = array();
		foreach ( $nodes->item(0)->childNodes as $node ) {
			if ( $node instanceof DOMElement && ( str_contains( ' ' . $node->getAttribute( 'class' ) . ' ', ' manga-language-flag ' ) || $node->tagName === 'img' ) ) { continue; }
			$parts[] = $node->textContent;
		}
		return $this->clean_text( implode( ' ', $parts ) );
	}

	public function has_numbered_reader(): bool {
		$this->assert_loaded();
		return $this->xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," chapter-image-container ") and @data-page]' )->length > 0;
	}

	/** Proof of a complete numbered reader, excluding flags, menus and donation widgets. */
	public function complete_reader_pages(): array {
		$this->assert_loaded(); $pages = array(); $total = 0;
		foreach ( $this->xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," chapter-image-container ") and @data-page]' ) as $node ) {
			$n = (int) $node->getAttribute( 'data-page' ); $imgs = $this->xpath->query( './/img[contains(concat(" ",normalize-space(@class)," ")," chapter-image ")]', $node );
			if ( $n < 1 || $imgs->length !== 1 || isset( $pages[$n] ) ) { return array(); }
			$url = $this->image_url_from_element( $imgs->item( 0 ) ); if ( ! $this->is_content_image( $url, false ) ) { return array(); }
			$counter = $this->xpath->query( './/*[contains(concat(" ",normalize-space(@class)," ")," page-number ")]', $node );
			if ( $counter->length !== 1 || ! preg_match( '~^\s*(\d+)\s*/\s*(\d+)\s*$~', $counter->item(0)->textContent, $m ) || (int) $m[1] !== $n || ( $total && $total !== (int) $m[2] ) ) { return array(); }
			$total = (int) $m[2]; $pages[$n] = $url;
		}
		ksort( $pages ); if ( ! $total || count( $pages ) !== $total || array_keys( $pages ) !== range( 1, $total ) ) { return array(); }
		return array_values( $pages );
	}

	private function meta( string $attribute, string $value ): string {
		$query = sprintf( '//meta[translate(@%1$s,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="%2$s"]/@content', $attribute, strtolower( $value ) );
		$nodes = $this->xpath->query( $query );
		return $nodes && $nodes->length ? $this->clean_text( $nodes->item( 0 )->nodeValue ) : '';
	}

	private function text_by_xpath( string $query ): string {
		$nodes = $this->xpath->query( $query );
		return $nodes && $nodes->length ? $this->clean_text( $nodes->item( 0 )->textContent ) : '';
	}

	private function image_by_xpath( string $query ): string {
		$nodes = $this->xpath->query( $query );
		if ( ! $nodes || ! $nodes->length ) {
			return '';
		}
		foreach ( $nodes as $image ) {
			if ( ! $image instanceof DOMElement ) {
				continue;
			}
			$url = $this->image_url_from_element( $image );
			if ( $this->is_content_image( $url ) ) {
				return $url;
			}
		}
		return '';
	}

	/** Alternative URLs of the cover itself, never cards/recommendations/chapter pages. */
	public function cover_image_candidates( bool $validate_network = true ): array {
		$this->assert_loaded();
		$urls = array();
		$query = '//img[contains(concat(" ",normalize-space(@class)," ")," manga-cover-image ")] | //*[contains(concat(" ",normalize-space(@class)," ")," manga-cover ") or contains(concat(" ",normalize-space(@class)," ")," summary_image ")]//img';
		foreach ( $this->xpath->query( $query ) as $image ) {
			foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'data-url', 'data-cfsrc', 'src' ) as $key ) {
				$url = $this->absolute_url( $image->getAttribute( $key ) );
				if ( $this->is_content_image( $url, $validate_network ) ) { $urls[] = $url; }
			}
			foreach ( array( 'data-srcset', 'srcset' ) as $key ) {
				$entries = explode( ',', $image->getAttribute( $key ) );
				foreach ( array_reverse( $entries ) as $entry ) {
					$parts = preg_split( '/\s+/', trim( $entry ) );
					$url = $this->absolute_url( (string) ( $parts[0] ?? '' ) );
					if ( $this->is_content_image( $url, $validate_network ) ) { $urls[] = $url; }
				}
			}
		}
		foreach ( array( $this->meta( 'property', 'og:image' ), $this->meta( 'name', 'twitter:image' ) ) as $value ) {
			$url = $this->absolute_url( $value );
			if ( $this->is_content_image( $url, $validate_network ) ) { $urls[] = $url; }
		}
		return array_values( array_unique( $urls ) );
	}

	/** Extrai a melhor URL de um <img>, inclusive lazy/srcset usados pelo Madara. */
	private function image_url_from_element( DOMElement $image ): string {
		foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'data-url', 'data-cfsrc', 'data-pagespeed-lazy-src', 'src' ) as $attribute ) {
			if ( ! $image->hasAttribute( $attribute ) ) {
				continue;
			}
			$value = trim( (string) $image->getAttribute( $attribute ) );
			if ( '' === $value || str_starts_with( $value, 'data:' ) ) {
				continue;
			}
			$url = $this->absolute_url( $value );
			if ( $url && ! self::looks_like_placeholder_url( $url ) ) {
				return $url;
			}
		}

		foreach ( array( 'data-srcset', 'data-lazy-srcset', 'srcset' ) as $attribute ) {
			if ( ! $image->hasAttribute( $attribute ) ) {
				continue;
			}
			$candidates = array();
			foreach ( explode( ',', (string) $image->getAttribute( $attribute ) ) as $entry ) {
				$entry = trim( $entry );
				if ( '' === $entry ) {
					continue;
				}
				$parts = preg_split( '/\s+/', $entry );
				$candidate = $this->absolute_url( (string) ( $parts[0] ?? '' ) );
				if ( $candidate && ! self::looks_like_placeholder_url( $candidate ) ) {
					$candidates[] = $candidate;
				}
			}
			if ( $candidates ) {
				return (string) end( $candidates );
			}
		}
		return '';
	}

	private function best_heading(): string {
		foreach ( array( '//main//h1[normalize-space()]', '//article//h1[normalize-space()]', '//h1[normalize-space()]' ) as $query ) {
			foreach ( $this->xpath->query( $query ) as $heading ) {
				$text = $this->clean_text( $heading->textContent );
				if ( $text && ! preg_match( '/^Manga Livre$/iu', $text ) ) {
					return $text;
				}
			}
		}
		return '';
	}

	private function section_after_heading( string $heading_text ): string {
		foreach ( $this->xpath->query( '//h1|//h2|//h3|//h4' ) as $heading ) {
			$heading_value = $this->clean_text( $heading->textContent );
			$heading_pos   = function_exists( 'mb_stripos' ) ? mb_stripos( $heading_value, $heading_text ) : stripos( $heading_value, $heading_text );
			if ( false === $heading_pos ) {
				continue;
			}

			$parts = array();
			$node  = $heading->nextSibling;
			while ( $node ) {
				if ( $node instanceof DOMElement && preg_match( '/^h[1-4]$/i', $node->tagName ) ) {
					break;
				}
				$text = $this->clean_text( $node->textContent ?? '' );
				if ( $text ) {
					$parts[] = $text;
				}
				if ( count( $parts ) >= 5 ) {
					break;
				}
				$node = $node->nextSibling;
			}
			return implode( "\n\n", $parts );
		}
		return '';
	}

	/**
	 * Extrai campos curtos por rótulo antes de recorrer ao texto global da página.
	 * Isso evita que um rótulo como "Autor:" capture CSS/JS ou blocos inteiros do layout.
	 */
	private function structured_field( string $label ): string {
		$quoted = preg_quote( $label, '/' );
		foreach ( $this->xpath->query( '//dt|//dd|//th|//td|//li|//p|//span|//strong|//b' ) as $node ) {
			$text = $this->clean_text( $node->textContent ?? '' );
			if ( preg_match( '/^' . $quoted . '\s*:\s*(.+)$/iu', $text, $match ) ) {
				$value = $this->clean_text( $match[1] );
				if ( $this->plausible_scalar( $value ) ) {
					return $value;
				}
			}

			if ( ! preg_match( '/^' . $quoted . '\s*:?$/iu', $text ) ) {
				continue;
			}
			$sibling = $node->nextSibling;
			while ( $sibling && ! ( $sibling instanceof DOMElement ) ) {
				$sibling = $sibling->nextSibling;
			}
			if ( $sibling ) {
				$value = $this->clean_text( $sibling->textContent ?? '' );
				if ( $this->plausible_scalar( $value ) ) {
					return $value;
				}
			}
		}
		return '';
	}

	private function plausible_scalar( string $value ): bool {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		if ( ! $value || $length > 220 ) {
			return false;
		}
		return ! preg_match( '/(?:<\s*(?:script|style|iframe)|\bfunction\s*\(|@media\b|javascript:|document\.|window\.|(?:background|display|position|width|height|transform|overflow|border|box-shadow|font-size)\s*:|[.#][a-z0-9_-]+\s*\{)/iu', $value );
	}

	private function body_text(): string {
		$nodes = $this->xpath->query( '//body' );
		if ( ! $nodes || ! $nodes->length ) {
			return '';
		}
		$body = $nodes->item( 0 )->cloneNode( true );
		if ( $body instanceof DOMElement ) {
			$remove = array();
			foreach ( $body->getElementsByTagName( '*' ) as $element ) {
				if ( in_array( strtolower( $element->nodeName ), array( 'script', 'style', 'noscript', 'template', 'svg' ), true ) ) {
					$remove[] = $element;
				}
			}
			foreach ( array_reverse( $remove ) as $element ) {
				if ( $element->parentNode ) {
					$element->parentNode->removeChild( $element );
				}
			}
		}
		return $this->clean_text( $body->textContent ?? '' );
	}

	private function regex_value( string $text, string $pattern ): string {
		return preg_match( $pattern, $text, $match ) ? $this->clean_text( $match[1] ) : '';
	}

	private function number( string $value ): int {
		return (int) preg_replace( '/\D+/', '', $value );
	}

	public static function looks_like_placeholder_url( string $url ): bool {
		if ( ! $url ) {
			return true;
		}
		$decoded = strtolower( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$base    = strtolower( basename( $decoded ) );
		return (bool) preg_match( '/(?:placeholder|no[-_ ]?image|no[-_ ]?cover|sem[-_ ]?capa|default[-_ ]?(?:image|cover|thumb)?|dummy|blank|transparent|(?:^|[\/_-])lazy(?:load|loading)?[._-]|lazy[-_ ]?placeholder|image[-_ ]?not[-_ ]?found)/i', $base . ' ' . $decoded );
	}

	/** v1.6.13: identifica assets de interface/emoji que nunca devem virar página de capítulo. */
	public static function looks_like_non_content_asset_url( string $url ): bool {
		if ( ! $url ) {
			return true;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = strtolower( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( preg_match( '/(?:^|\.)(?:flagcdn\.com|tenor\.com|giphy\.com|media\.giphy\.com|gravatar\.com)$/i', $host ) ) {
			return true;
		}
		if ( preg_match( '/logo|avatar|emoji|favicon|flag|icon|banner|tenor|discord|donat|sprite|tracking|pixel/i', $path ) ) {
			return true;
		}
		return false;
	}

	private function is_content_image( string $url, bool $validate_network = true ): bool {
		if ( ! $url || ( $validate_network ? ! wp_http_validate_url( $url ) : ! MBI_Image_Proxy::valid_browser_url( $url ) ) || self::looks_like_placeholder_url( $url ) || self::looks_like_non_content_asset_url( $url ) ) {
			return false;
		}
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		// Aceita formatos modernos e também endpoints de mídia sem extensão quando o caminho indica uploads/leitor.
		if ( preg_match( '/\.(?:jpe?g|png|webp|gif|avif|jxl)(?:$|[?#])/i', $url ) ) {
			return true;
		}
		return (bool) preg_match( '#/(?:wp-content/uploads|wp-manga/data|manga/data|reader|chapter|capitulo)/#i', $path );
	}

	private function absolute_url( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( ! $url || str_starts_with( $url, 'data:' ) || str_starts_with( $url, 'javascript:' ) ) {
			return '';
		}
		if ( str_starts_with( $url, '//' ) ) {
			return self::normalize_media_host( 'https:' . $url );
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return self::normalize_media_host( $url );
		}
		if ( str_starts_with( $url, '/' ) ) {
			$parts = wp_parse_url( $this->base_url );
			return esc_url_raw( $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $url );
		}
		// Resolve relative paths according to the document URL, preserving host/port.
		if ( class_exists( 'WP_Http' ) ) {
			return esc_url_raw( WP_Http::make_absolute_url( $url, $this->base_url ) );
		}
		$parts = wp_parse_url( $this->base_url );
		$path = (string) ( $parts['path'] ?? '/' );
		$directory = str_ends_with( $path, '/' ) ? $path : substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
		$segments = array();
		foreach ( explode( '/', $directory . $url ) as $segment ) {
			if ( '..' === $segment ) { array_pop( $segments ); }
			elseif ( '.' !== $segment && '' !== $segment ) { $segments[] = $segment; }
		}
		return esc_url_raw( $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/' . implode( '/', $segments ) );
	}

	/** Normalize international media hosts without changing paths or opaque tokens. */
	public static function normalize_media_host( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( preg_match( '/[^\x00-\x7F]/', $host ) ) {
			$ascii = function_exists( 'idn_to_ascii' ) ? idn_to_ascii( $host, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 1 ) : false;
			if ( ! $ascii && 'livre.超高清.online' === $host ) { $ascii = 'livre.xn--c5wx58dnej.online'; }
			if ( ! $ascii ) { return ''; }
			$url = preg_replace( '~^(https?://)' . preg_quote( $host, '~' ) . '(?=[:/]|$)~i', '$1' . $ascii, $url );
		}
		return esc_url_raw( $url );
	}

	private function clean_text( ?string $text ): string {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[\x{00A0}\s]+/u', ' ', $text );
		return trim( wp_strip_all_tags( $text ) );
	}

	private function assert_loaded(): void {
		if ( ! $this->dom || ! $this->xpath ) {
			throw new LogicException( 'O HTML precisa ser carregado antes da análise.' );
		}
	}
}
