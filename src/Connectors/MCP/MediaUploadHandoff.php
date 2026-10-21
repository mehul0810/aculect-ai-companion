<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Admin\McpImageUploadPage;

/**
 * Builds the credential-free human handoff to WordPress's native media uploader.
 */
final class MediaUploadHandoff {

	/**
	 * Return a validated upload URL and truthful instructions for the human.
	 *
	 * @return array<string, string>
	 */
	public function result(): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			return array(
				'error'   => 'forbidden',
				'message' => 'The connected WordPress user cannot upload media.',
			);
		}
		if ( ! McpAppsNegotiation::enabled() ) {
			return array(
				'error'   => 'handoff_disabled',
				'message' => 'The site administrator has not enabled the WordPress image-upload handoff.',
			);
		}

		$url = admin_url( 'admin.php?page=' . McpImageUploadPage::PAGE_SLUG );
		if ( ! is_string( $url ) || ! $this->is_secure_admin_url( $url ) ) {
			return array(
				'error'   => 'handoff_unavailable',
				'message' => 'A secure WordPress Media Library upload handoff is unavailable.',
			);
		}

		return array(
			'mode'         => 'human_wordpress_upload',
			'url'          => $url,
			'instructions' => 'Open this link in the same-site WordPress admin, choose and upload the image there, then provide the attachment ID shown by WordPress in a separate message. WordPress handles its normal login, capability, and nonce checks. No file bytes are sent through this tool or an Aculect relay.',
		);
	}

	/**
	 * Accept only credential-free HTTPS URLs on the configured WordPress site host.
	 *
	 * @param string $url Candidate admin URL.
	 */
	private function is_secure_admin_url( string $url ): bool {
		$parts      = wp_parse_url( $url );
		$site_parts = wp_parse_url( site_url( '/' ) );

		return is_array( $parts )
			&& is_array( $site_parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& strtolower( (string) $parts['host'] ) === strtolower( (string) ( $site_parts['host'] ?? '' ) )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] );
	}
}
