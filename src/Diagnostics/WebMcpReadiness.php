<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

use Aculect\AICompanion\WebMCP\WebMcpAssets;

/**
 * Reports WebMCP configuration and bounded homepage delivery evidence.
 */
final class WebMcpReadiness {

	/**
	 * Build redacted evidence from the existing homepage probe.
	 *
	 * @param array<string,mixed> $probe Homepage probe result.
	 * @return array<string,mixed>
	 */
	public function item( array $probe ): array {
		$enabled     = WebMcpAssets::is_enabled();
		$asset_local = is_file( dirname( __DIR__, 2 ) . '/assets/js/webmcp.js' );
		$http_status = (int) ( $probe['status'] ?? 0 );
		$blocked     = 0 === $http_status || in_array( $http_status, array( 403, 429 ), true ) || ! empty( $probe['edge_challenge'] );
		$observed    = $enabled && $asset_local && ! $blocked && $this->observed_script( $probe );
		$status      = ! $enabled ? 'not_applicable' : ( ! $asset_local ? 'fail' : ( $blocked ? 'externally_blocked' : 'warning' ) );
		$message     = ! $enabled
			? 'WebMCP is disabled by the site filter; browser registration is not applicable and remains unverified.'
			: ( ! $asset_local
				? 'WebMCP is enabled, but its script asset is missing locally.'
				: ( $blocked
					? 'WebMCP is enabled and its local asset exists, but the public homepage could not be inspected because an edge or network block was observed.'
					: ( $observed
						? 'WebMCP is enabled and its executable script tag was observed on the public homepage. Native browser registration remains unverified.'
						: 'WebMCP is enabled and its script asset exists locally, but executable script delivery was not observed on the public homepage. Native browser registration remains unverified.' ) ) );

		return array(
			'id'       => 'webmcp',
			'status'   => $status,
			'message'  => $message,
			'evidence' => array(
				'featureEnabled'                    => $enabled,
				'assetPresentLocally'               => $asset_local,
				'publicScriptObserved'              => $observed,
				'nativeBrowserRegistrationVerified' => false,
			),
		);
	}

	/**
	 * Observe a matching executable script without retaining HTML or URLs.
	 *
	 * @param array<string,mixed> $probe Homepage probe result.
	 */
	private function observed_script( array $probe ): bool {
		$body = substr( (string) ( $probe['body'] ?? '' ), 0, 65536 );
		if ( 200 !== (int) ( $probe['status'] ?? 0 )
			|| ! str_contains( (string) ( $probe['content_type'] ?? '' ), 'text/html' )
			|| ! empty( $probe['edge_challenge'] )
			|| ! class_exists( '\\WP_HTML_Tag_Processor' )
			|| '' === $body
		) {
			return false;
		}

		$processor = new \WP_HTML_Tag_Processor( $body );
		$expected  = wp_parse_url( ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/js/webmcp.js' );
		if ( ! is_array( $expected ) || empty( $expected['scheme'] ) || empty( $expected['host'] ) || empty( $expected['path'] ) ) {
			return false;
		}

		$template_depth = 0;
		$noscript_depth = 0;
		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag       = strtolower( (string) $processor->get_tag() );
			$is_closer = $processor->is_tag_closer();
			if ( 'template' === $tag ) {
				$template_depth = max( 0, $template_depth + ( $is_closer ? -1 : 1 ) );
				continue;
			}
			if ( 'noscript' === $tag ) {
				$noscript_depth = max( 0, $noscript_depth + ( $is_closer ? -1 : 1 ) );
				continue;
			}
			if ( 'script' !== $tag || $is_closer || $template_depth > 0 || $noscript_depth > 0 ) {
				continue;
			}

			$src = $processor->get_attribute( 'src' );
			if ( ! is_string( $src ) ) {
				continue;
			}

			$source     = wp_parse_url( $src );
			$source     = is_array( $source ) ? $this->resolve_source( $source, $expected ) : array();
			$type       = strtolower( trim( (string) $processor->get_attribute( 'type' ) ) );
			$mime       = strtok( $type, ';' );
			$executable = '' === $type || 'module' === $type || in_array( trim( (string) $mime ), array( 'text/javascript', 'application/javascript', 'application/ecmascript', 'text/ecmascript' ), true );
			if ( $executable && $this->same_asset( $source, $expected ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve only absolute or root-relative script sources against the expected asset origin.
	 *
	 * @param array<string,mixed> $source   Parsed candidate source.
	 * @param array<string,mixed> $expected Parsed expected asset URL.
	 * @return array<string,mixed>
	 */
	private function resolve_source( array $source, array $expected ): array {
		if ( isset( $source['user'] ) || isset( $source['pass'] ) ) {
			return array();
		}
		if ( isset( $source['host'] ) ) {
			if ( empty( $source['scheme'] ) ) {
				$source['scheme'] = $expected['scheme'];
			}
			return $source;
		}
		if ( isset( $source['path'] ) && str_starts_with( $source['path'], '/' ) ) {
			$source['scheme'] = $expected['scheme'];
			$source['host']   = $expected['host'];
			$source['port']   = $expected['port'] ?? null;
			return $source;
		}
		return array();
	}

	/**
	 * Compare a candidate script URL to the configured asset by origin and path.
	 *
	 * @param array<string,mixed> $candidate Candidate URL.
	 * @param array<string,mixed> $expected  Expected URL.
	 */
	private function same_asset( array $candidate, array $expected ): bool {
		$expected_port  = $expected['port'] ?? ( 'https' === strtolower( $expected['scheme'] ) ? 443 : 80 );
		$candidate_port = $candidate['port'] ?? ( 'https' === strtolower( (string) ( $candidate['scheme'] ?? '' ) ) ? 443 : 80 );
		return strtolower( (string) ( $candidate['scheme'] ?? '' ) ) === strtolower( $expected['scheme'] )
			&& strtolower( (string) ( $candidate['host'] ?? '' ) ) === strtolower( $expected['host'] )
			&& $candidate_port === $expected_port
			&& ( $candidate['path'] ?? null ) === $expected['path'];
	}
}
