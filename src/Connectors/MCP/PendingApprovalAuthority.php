<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\Database\Installer;
use Aculect\AICompanion\Connectors\OAuth\IssuerBinding;

/**
 * Revalidate a pending human decision against current connection and policy state.
 */
final class PendingApprovalAuthority {

	/**
	 * Bind the approval to the same policy on preview, decision and execution.
	 *
	 * @param string                 $tool Internal ability ID.
	 * @param string                 $risk_level Risk computed from the exact operation at preview.
	 * @param AbilitiesRegistry|null $registry Request registry, or fresh current metadata.
	 * @param ToolSafety|null        $safety Request confirmation policy, or current stored policy.
	 */
	public static function fingerprint( string $tool, string $risk_level, ?AbilitiesRegistry $registry = null, ?ToolSafety $safety = null ): string {
		$registry = $registry ?? new AbilitiesRegistry();
		$groups   = ( $safety ?? new ToolSafety() )->confirmation_groups();
		$scopes   = $registry->required_scopes( $tool );
		sort( $groups );
		sort( $scopes );
		$policy = array(
			'version'             => 'wordpress-human-approval.v2-exact-preview-values',
			'tool'                => $tool,
			'risk_level'          => $risk_level,
			'confirmation_groups' => array_values( $groups ),
			'required_scopes'     => array_values( $scopes ),
			'enabled_abilities'   => $registry->enabled_ids(),
			'role_policy_enabled' => RoleAbilitiesPolicy::is_editing_enabled(),
			'role_policies'       => get_option( RoleAbilitiesPolicy::OPTION_ROLE_ABILITIES, array() ),
			'capability'          => 'edit_post',
		);
		return hash( 'sha256', (string) wp_json_encode( $policy ) );
	}

	/**
	 * Check the current actor, original connection, scope and policy before review.
	 *
	 * The encrypted token hash is a lookup identity, never bearer material. Reads
	 * bypass cache and do not touch usage, issue, refresh, or reactivate tokens.
	 *
	 * @param array<string, mixed> $request Authenticated pending payload.
	 */
	public function allows( array $request ): bool {
		$user_id = get_current_user_id();
		$tool    = (string) ( $request['tool_name'] ?? '' );
		$hash    = (string) ( $request['token_hash'] ?? '' );
		if ( 0 >= $user_id || (int) ( $request['user_id'] ?? 0 ) !== $user_id || get_current_blog_id() !== (int) ( $request['blog_id'] ?? 0 ) || 'content_workflow.update_post' !== $tool || 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) || AccessLockdown::is_paused() || UserAccessControl::is_paused( $user_id ) ) {
			return false;
		}
		$connection = $this->connection( $hash );
		if ( ! is_array( $connection ) || '0' !== (string) $connection['revoked'] || strtotime( (string) $connection['expires_at'] ) <= time() || (int) $connection['user_id'] !== $user_id || Helpers::mcp_resource() !== Helpers::normalize_resource( (string) $connection['resource'] ) ) {
			return false;
		}
		$client_hash = hash_hmac( 'sha256', (string) $connection['client_id'], wp_salt( 'auth' ) );
		if ( ! hash_equals( (string) $request['client_hash'], $client_hash ) || ! hash_equals( (string) $request['policy_fingerprint'], self::fingerprint( $tool, (string) ( $request['policy_risk_level'] ?? '' ) ) ) ) {
			return false;
		}
		$scopes = json_decode( (string) $connection['scopes'], true );
		if ( ! is_array( $scopes ) || ! current_user_can( (string) $request['capability'], ...$request['capability_args'] ) ) {
			return false;
		}
		$modules = ( new McpToolAvailability() )->tool_modules_for_user( $user_id, null, null, $scopes );
		return isset( $modules[ $tool ] ) && PendingApprovalNativeAuthority::allows( $request );
	}

	/**
	 * Read the live token under the current site's issuer and active client.
	 *
	 * @param string $token_hash SHA-256 lookup identity from the encrypted payload.
	 * @return array<string, mixed>|null
	 */
	private function connection( string $token_hash ): ?array {
		global $wpdb;
		$tables = Installer::table_names();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Approval authorization needs authoritative revocation and scope state.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT access_tokens.user_id, access_tokens.client_id, access_tokens.scopes, access_tokens.resource, access_tokens.revoked, access_tokens.expires_at FROM %i access_tokens INNER JOIN %i clients ON clients.client_id = access_tokens.client_id WHERE access_tokens.token_hash = %s AND clients.issuer_hash = %s AND clients.revoked = 0 LIMIT 1',
				$tables['access_tokens'],
				$tables['clients'],
				$token_hash,
				IssuerBinding::hash()
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}
}
