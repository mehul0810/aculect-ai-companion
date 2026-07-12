<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Structured CC0 image discovery backed by Openverse.
 */
final class OpenverseImageProvider extends AbstractAbilityService {

	private const DEFAULT_ENDPOINT = 'https://api.openverse.org/v1/images/';
	private const DEFAULT_TIMEOUT  = 8;
	private const MAX_RESULTS      = 10;

	/**
	 * Search CC0 image candidates.
	 *
	 * @param array<string, mixed> $args Search arguments.
	 * @return array<string, mixed>
	 */
	public function search( array $args ): array {
		if ( ! current_user_can( 'upload_files' ) && ! current_user_can( 'edit_posts' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to search media candidates.' );
		}

		$query = sanitize_text_field( (string) ( $args['query'] ?? $args['topic'] ?? '' ) );
		if ( '' === $query ) {
			return $this->error( 'invalid_query', 'Provide a topic or search query.' );
		}

		$per_page = max( 1, min( self::MAX_RESULTS, (int) ( $args['per_page'] ?? $args['limit'] ?? 5 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$endpoint = esc_url_raw(
			(string) apply_filters(
				'aculect_ai_companion_openverse_images_endpoint',
				self::DEFAULT_ENDPOINT
			)
		);

		if ( ! $this->is_http_url( $endpoint ) ) {
			return $this->error( 'invalid_provider_endpoint', 'The configured Openverse endpoint must be a public HTTP or HTTPS URL.' );
		}

		$url      = add_query_arg(
			array(
				'q'         => $query,
				'license'   => 'cc0',
				'mature'    => 'false',
				'page'      => $page,
				'page_size' => $per_page,
			),
			$endpoint
		);
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => (int) apply_filters( 'aculect_ai_companion_openverse_search_timeout', self::DEFAULT_TIMEOUT ),
				'redirection'         => 2,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 262144,
				'user-agent'          => 'Aculect AI Companion/' . ACULECT_AI_COMPANION_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error( (string) $response->get_error_code(), $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return $this->error( 'provider_request_failed', 'Openverse image search did not return a successful response.' );
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $payload ) ) {
			return $this->error( 'provider_invalid_response', 'Openverse image search returned invalid JSON.' );
		}

		$results = is_array( $payload['results'] ?? null ) ? $payload['results'] : array();
		$items   = array_values(
			array_filter(
				array_map( array( $this, 'map_result' ), $results ),
				static fn( array $item ): bool => '' !== (string) ( $item['download_url'] ?? '' )
			)
		);

		return array(
			'status'   => 'ready',
			'provider' => 'openverse',
			'license'  => 'cc0',
			'query'    => $query,
			'items'    => $items,
			'total'    => isset( $payload['result_count'] ) ? absint( $payload['result_count'] ) : count( $items ),
			'page'     => $page,
			'per_page' => $per_page,
			'next'     => is_string( $payload['next'] ?? null ) ? esc_url_raw( (string) $payload['next'] ) : '',
			'warnings' => array(
				'Review the selected image for relevance before applying it to published content.',
				'Openverse metadata is imported for attribution and provenance, but site owners remain responsible for final license review.',
			),
		);
	}

	/**
	 * Convert one provider result into a bounded image candidate.
	 *
	 * @param mixed $result Raw provider result.
	 * @return array<string, mixed>
	 */
	private function map_result( mixed $result ): array {
		if ( ! is_array( $result ) ) {
			return array();
		}

		$download_url = esc_url_raw( (string) ( $result['url'] ?? '' ) );
		if ( ! $this->is_http_url( $download_url ) ) {
			$download_url = '';
		}

		$landing_url = esc_url_raw( (string) ( $result['foreign_landing_url'] ?? $result['foreign_landing_page_url'] ?? '' ) );
		$thumbnail   = esc_url_raw( (string) ( $result['thumbnail'] ?? '' ) );
		$title       = sanitize_text_field( (string) ( $result['title'] ?? '' ) );
		$creator     = sanitize_text_field( (string) ( $result['creator'] ?? '' ) );
		$source      = sanitize_text_field( (string) ( $result['source'] ?? '' ) );
		$license     = strtolower( sanitize_text_field( (string) ( $result['license'] ?? 'cc0' ) ) );

		return array(
			'id'                 => sanitize_text_field( (string) ( $result['id'] ?? md5( $download_url ) ) ),
			'title'              => '' === $title ? 'CC0 image candidate' : $title,
			'creator'            => $creator,
			'creator_url'        => esc_url_raw( (string) ( $result['creator_url'] ?? '' ) ),
			'source'             => $source,
			'license'            => '' === $license ? 'cc0' : $license,
			'license_version'    => sanitize_text_field( (string) ( $result['license_version'] ?? '' ) ),
			'license_url'        => esc_url_raw( (string) ( $result['license_url'] ?? '' ) ),
			'landing_url'        => $landing_url,
			'download_url'       => $download_url,
			'thumbnail_url'      => $thumbnail,
			'width'              => absint( $result['width'] ?? 0 ),
			'height'             => absint( $result['height'] ?? 0 ),
			'attribution_text'   => $this->attribution_text( $title, $creator, $source, $landing_url ),
			'suggested_alt_text' => $this->suggested_alt_text( $title, $creator ),
		);
	}

	/**
	 * Return compact attribution text for an imported image.
	 *
	 * @param string $title       Image title.
	 * @param string $creator     Image creator.
	 * @param string $source      Image source.
	 * @param string $landing_url Source landing URL.
	 */
	private function attribution_text( string $title, string $creator, string $source, string $landing_url ): string {
		$parts = array_filter(
			array(
				'' === $title ? '' : $title,
				'' === $creator ? '' : 'by ' . $creator,
				'' === $source ? '' : 'via ' . $source,
				'' === $landing_url ? '' : $landing_url,
			)
		);

		return sanitize_text_field( implode( ' ', $parts ) );
	}

	/**
	 * Return concise fallback alt text from provider metadata.
	 *
	 * @param string $title   Image title.
	 * @param string $creator Image creator.
	 */
	private function suggested_alt_text( string $title, string $creator ): string {
		$text = '' === $title ? 'Related image' : $title;
		if ( '' !== $creator ) {
			$text .= ' by ' . $creator;
		}

		return sanitize_text_field( $text );
	}

	/**
	 * Validate URL shape for read-only discovery; upload still performs SSRF DNS checks.
	 *
	 * @param string $url Candidate URL.
	 */
	private function is_http_url( string $url ): bool {
		if ( '' === $url || false === wp_http_validate_url( $url ) ) {
			return false;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );

		return '' !== $host && in_array( $scheme, array( 'http', 'https' ), true );
	}
}
