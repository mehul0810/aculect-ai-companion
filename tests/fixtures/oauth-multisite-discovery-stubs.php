<?php
/**
 * Synthetic two-site WordPress discovery context for focused unit tests.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

require_once __DIR__ . '/oauth-discovery-wp-network.php';
require_once __DIR__ . '/oauth-discovery-wp-site.php';

function get_network(): \WP_Network {
	return new \WP_Network();
}

function get_site_by_path( string $domain, string $path ): ?\WP_Site {
	$GLOBALS['aculect_oauth_discovery_site_lookup'][] = array( $domain, $path );
	if ( 'example.com' !== $domain ) {
		return null;
	}
	if ( '/second' === $path || str_starts_with( $path, '/second/' ) ) {
		$site           = new \WP_Site( 2, (int) ( $GLOBALS['aculect_oauth_discovery_second_network'] ?? 1 ) );
		$flags          = $GLOBALS['aculect_oauth_discovery_site_flags'] ?? array();
		$site->deleted  = (int) ( $flags['deleted'] ?? 0 );
		$site->archived = (int) ( $flags['archived'] ?? 0 );
		$site->spam     = (int) ( $flags['spam'] ?? 0 );
		return $site;
	}
	return new \WP_Site( 1 );
}

function switch_to_blog( int $blog_id ): void {
	$GLOBALS['aculect_oauth_discovery_saved_context'] = array(
		'blog_id'  => $GLOBALS['aculect_ai_companion_test_blog_id'] ?? 1,
		'home_url' => $GLOBALS['aculect_ai_companion_test_home_url'] ?? 'https://example.com',
		'options'  => $GLOBALS['aculect_ai_companion_test_options'] ?? array(),
	);
	$GLOBALS['aculect_ai_companion_test_blog_id']     = $blog_id;
	$GLOBALS['aculect_ai_companion_test_home_url']    = 'https://example.com/second';
	$GLOBALS['aculect_ai_companion_test_options']     = array(
		'active_plugins' => (bool) ( $GLOBALS['aculect_oauth_discovery_second_active'] ?? true )
			? array( plugin_basename( ACULECT_AI_COMPANION_PLUGIN_FILE ) )
			: array(),
	);
}

function restore_current_blog(): void {
	$context                                       = $GLOBALS['aculect_oauth_discovery_saved_context'] ?? array();
	$GLOBALS['aculect_ai_companion_test_blog_id']  = $context['blog_id'] ?? 1;
	$GLOBALS['aculect_ai_companion_test_home_url'] = $context['home_url'] ?? 'https://example.com';
	$GLOBALS['aculect_ai_companion_test_options']  = $context['options'] ?? array();
	unset( $GLOBALS['aculect_oauth_discovery_saved_context'] );
}
