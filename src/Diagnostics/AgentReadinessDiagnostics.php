<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

use Aculect\AICompanion\Connectors\Helpers;
use WP_Error;

/**
 * Runs bounded, read-only checks of plugin and site agent-readiness surfaces.
 *
 * Stored results intentionally contain no response bodies, headers, or URLs.
 */
final class AgentReadinessDiagnostics {

	public const OPTION_LAST_RESULT = 'aculect_ai_companion_agent_readiness';

	private AgentReadinessHttpProbe $http_probe;

	/**
	 * Create the readiness checker.
	 *
	 * @param callable(string,array<string,mixed>):(array<string,mixed>|WP_Error)|null $http_request Bounded HTTP adapter.
	 */
	public function __construct( ?callable $http_request = null ) {
		$this->http_probe = new AgentReadinessHttpProbe( $http_request );
	}

	/**
	 * Run checks and persist the redacted result.
	 *
	 * @return array<string,mixed>
	 */
	public function run(): array {
		$this->http_probe->reset();
		$items  = array_merge(
			$this->plugin_surface_checks(),
			$this->site_surface_checks(),
			$this->not_applicable_checks()
		);
		$result = array(
			'ranAt'   => gmdate( 'Y-m-d H:i:s' ),
			'summary' => $this->summary( $items ),
			'items'   => $items,
		);

		update_option( self::OPTION_LAST_RESULT, $result, false );
		return $result;
	}

	/**
	 * Return saved redacted result or the initial empty state.
	 *
	 * @return array<string,mixed>
	 */
	public function last_result(): array {
		$result = get_option( self::OPTION_LAST_RESULT, array() );
		return is_array( $result ) && isset( $result['items'], $result['summary'] )
			? $result
			: array(
				'ranAt'   => '',
				'summary' => 'not_run',
				'items'   => array(),
			);
	}

	/**
	 * Inspect plugin-owned public endpoints without treating generated metadata as reachability proof.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function plugin_surface_checks(): array {
		$resource = $this->http_probe->request( Helpers::protected_resource_metadata_url(), 'GET', array(), Helpers::mcp_resource() );
		$auth     = $this->http_probe->request( Helpers::authorization_metadata_url(), 'GET', array(), Helpers::authorization_server_issuer() );
		// An unauthenticated MCP request is logged when optional diagnostics logging is enabled.
		$mcp = LogSettings::is_enabled() ? null : $this->http_probe->request( Helpers::mcp_resource(), 'POST', array(), Helpers::mcp_resource() );

		return array(
			$this->metadata_item( 'oauth_discovery', $resource, 'OAuth Protected Resource Metadata', 'resource' ),
			$this->metadata_item( 'oauth_authorization_metadata', $auth, 'OAuth Authorization Server Metadata', 'authorization' ),
			$this->mcp_challenge_item( $mcp ),
			$this->item( 'mcp_server_card', 'owner_decision_required', 'MCP Server Card discovery is deferred pending an owner decision; no endpoint is assumed.' ),
			$this->item( 'webmcp', 'warning', 'WebMCP is a progressive browser enhancement. Public script delivery and browser registration are not proven by this server-side check.' ),
			$this->item( 'skills_index', 'owner_decision_required', 'A public Skills Index is not enabled; its 0.9.0 contract remains an owner decision.' ),
		);
	}

	/**
	 * Observe site-owned discovery and content surfaces without changing them.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function site_surface_checks(): array {
		$home         = $this->http_probe->request( home_url( '/' ) );
		$robots       = $this->http_probe->request( home_url( '/robots.txt' ) );
		$sitemap      = $this->http_probe->request( home_url( '/wp-sitemap.xml' ) );
		$llms         = $this->http_probe->request( home_url( '/llms.txt' ) );
		$markdown     = $this->http_probe->request( home_url( '/' ), 'GET', array( 'Accept' => 'text/markdown' ) );
		$robotsBody   = is_array( $robots ) && isset( $robots['body'] ) ? (string) $robots['body'] : '';
		$robotsCode   = $this->http_status( $robots );
		$robots_valid = 200 === $robotsCode && ! $this->unexpected_media_type( $robots, array( 'text/plain' ) ) && empty( $robots['edge_challenge'] );
		$sitemapRef   = str_contains( strtolower( $robotsBody ), 'sitemap:' );

		return array(
			$this->availability_item( 'robots_txt', $robots, 'robots.txt', array( 'text/plain' ) ),
			$this->item(
				'ai_crawler_directives',
				$this->externally_blocked( $robots ) ? 'externally_blocked' : ( ! $robots_valid ? 'warning' : ( preg_match( '/(?:GPTBot|ClaudeBot|Google-Extended|PerplexityBot|Amazonbot)/i', $robotsBody ) ? 'owner_decision_required' : 'warning' ) ),
				$this->externally_blocked( $robots ) ? 'AI crawler directives could not be inspected because an edge or network challenge was returned.' : ( ! $robots_valid ? 'robots.txt did not return an inspectable text response.' : ( preg_match( '/(?:GPTBot|ClaudeBot|Google-Extended|PerplexityBot|Amazonbot)/i', $robotsBody ) ? 'robots.txt contains one or more named AI crawler directives; review their policy separately.' : 'No named AI crawler directives were found. This is informational and does not imply a recommended policy.' ) )
			),
			$this->item(
				'sitemap',
				200 === $this->http_status( $sitemap ) && ! $this->unexpected_media_type( $sitemap, array( 'application/xml', 'text/xml', 'application/rss+xml' ) ) ? 'pass' : ( $this->externally_blocked( $sitemap ) || ! empty( $sitemap['edge_challenge'] ) ? 'externally_blocked' : 'warning' ),
				'The conventional WordPress sitemap endpoint was checked; robots.txt advertisement is not reachability proof.',
				array(
					'advertisedByRobots' => $sitemapRef,
					'httpStatus'         => $this->http_status( $sitemap ),
				)
			),
			$this->content_signals_item( $home ),
			$this->link_header_item( $home ),
			$this->availability_item( 'llms_txt', $llms, 'llms.txt', array( 'text/plain', 'text/markdown' ) ),
			$this->markdown_item( $markdown ),
		);
	}

	/**
	 * Explicitly mark unrelated protocol families as outside plugin scope.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function not_applicable_checks(): array {
		return array(
			$this->item( 'a2a_agent_card', 'not_applicable', 'A2A Agent Card is not implemented or controlled by this plugin.' ),
			$this->item( 'web_bot_auth', 'not_applicable', 'Web Bot Auth is not implemented or controlled by this plugin.' ),
			$this->item( 'dns_aid', 'not_applicable', 'DNS-AID is not implemented or controlled by this plugin.' ),
			$this->item( 'commerce_protocols', 'not_applicable', 'Commerce protocols are not implemented or controlled by this plugin.' ),
		);
	}

	/**
	 * Validate the expected OAuth discovery JSON shape while retaining only booleans.
	 *
	 * @param string              $id       Item ID.
	 * @param array<string,mixed> $probe    Probe result.
	 * @param string              $label    Display label.
	 * @param string              $metadata Metadata document type.
	 * @return array<string,mixed>
	 */
	private function metadata_item( string $id, array $probe, string $label, string $metadata ): array {
		$body   = json_decode( (string) ( $probe['body'] ?? '' ), true );
		$json   = str_contains( (string) ( $probe['content_type'] ?? '' ), 'application/json' ) && is_array( $body );
		$valid  = $json && $this->valid_metadata_shape( $body, $metadata );
		$status = $this->http_status( $probe );
		return $this->item(
			$id,
			0 === $status || ! empty( $probe['edge_challenge'] ) ? 'externally_blocked' : ( $status >= 200 && $status < 300 && $valid ? 'pass' : 'fail' ),
			0 === $status || ! empty( $probe['edge_challenge'] ) ? $label . ' could not be verified because an edge or network challenge was observed.' : ( $status >= 200 && $status < 300 && $valid ? $label . ' returned the expected public JSON fields.' : $label . ' returned an HTTP response without the expected JSON type or fields.' ),
			array(
				'httpStatus'      => $status,
				'jsonContentType' => $json,
				'expectedShape'   => $valid,
			)
		);
	}

	/**
	 * Check required OAuth discovery fields without exporting their values.
	 *
	 * @param array<string,mixed> $body     Decoded response body.
	 * @param string              $metadata Metadata document type.
	 */
	private function valid_metadata_shape( array $body, string $metadata ): bool {
		if ( 'resource' === $metadata ) {
			return ( $body['resource'] ?? null ) === Helpers::mcp_resource()
				&& is_array( $body['authorization_servers'] ?? null )
				&& array() !== $body['authorization_servers'];
		}
		return is_string( $body['issuer'] ?? null )
			&& is_string( $body['authorization_endpoint'] ?? null )
			&& is_string( $body['token_endpoint'] ?? null )
			&& is_string( $body['registration_endpoint'] ?? null )
			&& in_array( 'S256', (array) ( $body['code_challenge_methods_supported'] ?? array() ), true );
	}

	/**
	 * Verify the unauthenticated MCP challenge without including response headers.
	 *
	 * @param array<string,mixed> $probe Probe result.
	 * @return array<string,mixed>
	 */
	private function mcp_challenge_item( ?array $probe ): array {
		if ( null === $probe ) {
			return $this->item( 'mcp_authentication_challenge', 'warning', 'The live challenge check was skipped because optional diagnostic logging is enabled; the unauthenticated probe would add a log event.' );
		}
		$status    = $this->http_status( $probe );
		$challenge = ! empty( $probe['bearer_challenge_valid'] );
		return $this->item(
			'mcp_authentication_challenge',
			0 === $status || ! empty( $probe['edge_challenge'] ) ? 'externally_blocked' : ( 401 === $status && $challenge ? 'pass' : 'fail' ),
			0 === $status || ! empty( $probe['edge_challenge'] ) ? 'The unauthenticated MCP challenge could not be observed because an edge or network challenge was returned.' : ( 401 === $status && $challenge ? 'The public MCP endpoint returned the expected Bearer resource-metadata challenge.' : 'The public MCP endpoint did not return the expected Bearer resource-metadata challenge.' ),
			array(
				'httpStatus'        => $status,
				'expectedChallenge' => $challenge,
			)
		);
	}

	/**
	 * Summarize availability of site-owned text files without returning content or URL.
	 *
	 * @param string              $id     Item ID.
	 * @param array<string,mixed> $probe  Probe result.
	 * @param string              $label  Display label.
	 * @param string[]            $expected_media_types Expected media types.
	 * @return array<string,mixed>
	 */
	private function availability_item( string $id, array $probe, string $label, array $expected_media_types ): array {
		$status = $this->http_status( $probe );
		$valid  = 200 === $status && ! $this->unexpected_media_type( $probe, $expected_media_types ) && empty( $probe['edge_challenge'] );
		return $this->item(
			$id,
			$valid ? 'pass' : ( $this->externally_blocked( $probe ) || ! empty( $probe['edge_challenge'] ) ? 'externally_blocked' : 'warning' ),
			$valid ? $label . ' is publicly available with the expected text media type.' : ( $this->externally_blocked( $probe ) || ! empty( $probe['edge_challenge'] ) ? $label . ' could not be verified due to an edge or loopback restriction.' : $label . ' is unavailable or did not use the expected media type.' ),
			array( 'httpStatus' => $status )
		);
	}

	/**
	 * Confirm a response media type matches one of the expected types.
	 *
	 * @param array<string,mixed> $probe    Probe result.
	 * @param string[]            $expected Expected media types.
	 */
	private function unexpected_media_type( array $probe, array $expected ): bool {
		$content_type = strtolower( (string) ( $probe['content_type'] ?? '' ) );
		foreach ( $expected as $media_type ) {
			if ( str_contains( $content_type, $media_type ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Report whether a response declares any Link header without exposing its value.
	 *
	 * @param array<string,mixed> $probe Probe result.
	 * @return array<string,mixed>
	 */
	private function link_header_item( array $probe ): array {
		$present = ! empty( $probe['link_header_present'] );
		return $this->item( 'link_headers', $this->externally_blocked( $probe ) ? 'externally_blocked' : ( $present ? 'owner_decision_required' : 'warning' ), $this->externally_blocked( $probe ) ? 'Link headers could not be inspected because the public homepage was unreachable.' : ( $present ? 'The public homepage emits Link headers; their targets are omitted and require site-owner review.' : 'The public homepage did not emit a Link header.' ), array( 'present' => $present ) );
	}

	/**
	 * Report Markdown content negotiation from the response media type only.
	 *
	 * @param array<string,mixed> $probe Probe result.
	 * @return array<string,mixed>
	 */
	private function markdown_item( array $probe ): array {
		$markdown = ! empty( $probe['markdown_media_type'] );
		$valid    = $this->http_status( $probe ) >= 200 && $this->http_status( $probe ) < 300 && $markdown && empty( $probe['edge_challenge'] );
		return $this->item(
			'markdown_negotiation',
			$this->externally_blocked( $probe ) || ! empty( $probe['edge_challenge'] ) ? 'externally_blocked' : ( $valid ? 'pass' : 'warning' ),
			$this->externally_blocked( $probe ) || ! empty( $probe['edge_challenge'] ) ? 'Markdown negotiation could not be checked because the homepage was blocked by an edge or network challenge.' : ( $valid ? 'The homepage returned a successful Markdown response for Accept: text/markdown.' : 'The homepage did not return a successful Markdown media type for Accept: text/markdown.' ),
			array(
				'markdownMediaType' => $markdown,
				'httpStatus'        => $this->http_status( $probe ),
			)
		);
	}

	/**
	 * Observe the Content Signals header without retaining its policy value.
	 *
	 * @param array<string,mixed> $probe Probe result.
	 * @return array<string,mixed>
	 */
	private function content_signals_item( array $probe ): array {
		$seen = ! empty( $probe['content_signal_seen'] );
		return $this->item(
			'content_signals',
			$this->externally_blocked( $probe ) ? 'externally_blocked' : ( $seen ? 'owner_decision_required' : 'warning' ),
			$this->externally_blocked( $probe ) ? 'Content Signals could not be observed because the public homepage was unreachable.' : ( $seen ? 'A Content-Signal header is present; its policy value is not stored and requires site-owner review.' : 'No Content-Signal header was observed on the public homepage.' ),
			array( 'headerPresent' => $seen )
		);
	}

	/**
	 * Build one bounded result item.
	 *
	 * @param string              $id       Item ID.
	 * @param string              $status   Result status.
	 * @param string              $message  Safe summary.
	 * @param array<string,mixed> $evidence Safe scalar evidence only.
	 * @return array<string,mixed>
	 */
	private function item( string $id, string $status, string $message, array $evidence = array() ): array {
		return array(
			'id'       => $id,
			'status'   => $status,
			'message'  => $message,
			'evidence' => $evidence,
		);
	}

	/**
	 * Return the summarized HTTP code or zero when blocked.
	 *
	 * @param array<string,mixed> $probe Probe result.
	 */
	private function http_status( array $probe ): int {
		return ! empty( $probe['blocked'] ) ? 0 : (int) ( $probe['status'] ?? 0 );
	}

	/**
	 * Determine whether a request failed before an origin status was observed.
	 *
	 * @param array<string,mixed> $probe Probe result.
	 */
	private function externally_blocked( array $probe ): bool {
		return ! empty( $probe['blocked'] ) || ! empty( $probe['edge_challenge'] ) || 0 === (int) ( $probe['status'] ?? 0 );
	}

	/**
	 * Roll up failures and owner decisions without treating informational warnings as failures.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 */
	private function summary( array $items ): string {
		$statuses = array_column( $items, 'status' );
		if ( in_array( 'fail', $statuses, true ) ) {
			return 'fail';
		}
		if ( in_array( 'externally_blocked', $statuses, true ) ) {
			return 'externally_blocked';
		}
		if ( in_array( 'owner_decision_required', $statuses, true ) ) {
			return 'owner_decision_required';
		}
		if ( in_array( 'warning', $statuses, true ) ) {
			return 'warning';
		}
		return 'pass';
	}
}
