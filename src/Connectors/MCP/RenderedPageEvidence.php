<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Projects a bounded HTML document into non-executable public-page evidence.
 */
final class RenderedPageEvidence {

	/**
	 * Extract selected public head and document fields, never raw HTML or forms.
	 *
	 * @param string $html Bounded response body.
	 * @return array<string,mixed>
	 */
	public function extract( string $html ): array {
		if ( ! class_exists( DOMDocument::class ) || '' === trim( $html ) || strlen( $html ) > 524288 ) {
			return array(
				'status'  => 'error',
				'error'   => 'html_parser_unavailable',
				'message' => 'A nonempty HTML page and the PHP DOM extension are required.',
			);
		}
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$loaded = $document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $loaded ) {
			return array(
				'status'  => 'error',
				'error'   => 'html_parse_failed',
				'message' => 'The response could not be parsed as HTML.',
			);
		}
		$xpath    = new DOMXPath( $document );
		$excluded = $xpath->query( '//script|//style|//form|//template|//noscript' );
		if ( false !== $excluded ) {
			foreach ( $excluded as $node ) {
				$node->parentNode?->removeChild( $node );
			}
		}
		return array(
			'title'       => $this->first_text( $xpath, '//head/title', 500 ),
			'description' => $this->first_attribute( $xpath, '//head/meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]', 'content', 1000 ),
			'robots'      => $this->first_attribute( $xpath, '//head/meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]', 'content', 500 ),
			'canonical'   => $this->safe_link( $this->first_attribute( $xpath, '//head/link[@rel="canonical"]', 'href', 2000 ) ),
			'headings'    => $this->elements( $xpath, '//body//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][not(ancestor::template or ancestor::form or ancestor::script or ancestor::style)]', false ),
			'links'       => $this->elements( $xpath, '//body//a[@href][not(ancestor::template or ancestor::form or ancestor::script or ancestor::style)]', true ),
		);
	}

	/**
	 * Return at most fifty selected elements and an explicit truncation flag.
	 *
	 * @param DOMXPath $xpath Document query engine.
	 * @param string   $query Fixed selector.
	 * @param bool     $links Whether to include link URLs.
	 * @return array<string,mixed>
	 */
	private function elements( DOMXPath $xpath, string $query, bool $links ): array {
		$nodes = $xpath->query( $query );
		$items = array();
		if ( false === $nodes ) {
			return array(
				'items'     => $items,
				'truncated' => false,
			);
		}
		foreach ( $nodes as $node ) {
			if ( count( $items ) >= 50 ) {
				break;
			}
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$item = array( 'text' => $this->text( $node->textContent, 500 ) );
			if ( $links ) {
				$item['url'] = $this->safe_link( $node->getAttribute( 'href' ) );
			} else {
				$item['level'] = (int) substr( $node->tagName, 1 );
			}
			$items[] = $item;
		}
		return array(
			'items'     => $items,
			'truncated' => $nodes->length > 50,
		);
	}

	/**
	 * Read one element's bounded text.
	 *
	 * @param DOMXPath $xpath Query engine.
	 * @param string   $query Fixed selector.
	 * @param int      $limit Character cap.
	 */
	private function first_text( DOMXPath $xpath, string $query, int $limit ): string {
		$nodes = $xpath->query( $query );
		return false === $nodes ? '' : $this->text( $nodes->item( 0 )?->textContent ?? '', $limit );
	}

	/**
	 * Read one allowlisted attribute.
	 *
	 * @param DOMXPath $xpath Query engine.
	 * @param string   $query Fixed selector.
	 * @param string   $attribute Attribute name.
	 * @param int      $limit Character cap.
	 */
	private function first_attribute( DOMXPath $xpath, string $query, string $attribute, int $limit ): string {
		$nodes = $xpath->query( $query );
		$node  = false === $nodes ? null : $nodes->item( 0 );
		return $node instanceof DOMElement ? $this->text( $node->getAttribute( $attribute ), $limit ) : '';
	}

	/**
	 * Normalize content into bounded plain text.
	 *
	 * @param string $value Raw text.
	 * @param int    $limit Character cap.
	 */
	private function text( string $value, int $limit ): string {
		return mb_substr( sanitize_text_field( $value ), 0, $limit );
	}

	/**
	 * Omit executable and credential-bearing links from evidence.
	 *
	 * @param string $url Public link.
	 */
	private function safe_link( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( strlen( $url ) > 2000 || false === $parts || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}
}
