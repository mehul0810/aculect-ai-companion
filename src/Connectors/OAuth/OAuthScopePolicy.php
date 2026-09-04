<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;

/**
 * Keeps OAuth protocol scopes and consent labels separate from ability policy.
 */
final class OAuthScopePolicy {

	/**
	 * Add protocol-only scopes when the user can approve at least one ability scope.
	 *
	 * @param string[] $scopes Ability-backed scopes.
	 * @return list<string>
	 */
	public function with_protocol_scopes( array $scopes ): array {
		if ( array() !== $scopes && in_array( 'offline_access', Helpers::supported_scopes(), true ) ) {
			$scopes[] = 'offline_access';
		}

		return array_values( array_unique( array_map( 'strval', $scopes ) ) );
	}

	public function consent_summary( string $scope ): string {
		$labels = array();
		$scopes = preg_split( '/\s+/', trim( $scope ) );

		foreach ( is_array( $scopes ) ? $scopes : array() as $item ) {
			$label = $this->label( $item );
			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}

		return array() === $labels
			? __( 'Use approved Aculect AI Companion actions', 'aculect-ai-companion' )
			: implode( ', ', array_unique( $labels ) );
	}

	private function label( string $scope ): string {
		return match ( $scope ) {
			'content:read' => __( 'Read site content and safe site information', 'aculect-ai-companion' ),
			'content:draft' => __( 'Create and update content, terms, comments, and media', 'aculect-ai-companion' ),
			'offline_access' => __( 'Keep this connection active with refresh tokens until it is revoked or expires', 'aculect-ai-companion' ),
			default => '',
		};
	}
}
