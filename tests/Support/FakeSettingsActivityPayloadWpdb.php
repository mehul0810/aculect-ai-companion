<?php
/**
 * Activity payload wpdb test double.
 *
 * @package Aculect\AICompanion\Tests\Support
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Support;

/**
 * Minimal wpdb double for ActivityRepository read paths.
 */
final class FakeSettingsActivityPayloadWpdb {

	/**
	 * WordPress table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Return a prepared-query marker.
	 *
	 * @param string $query Query template.
	 * @param mixed  ...$args Query values.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		return $query . ' ' . implode( ' ', array_map( 'strval', $args ) );
	}

	/**
	 * Escape a LIKE value.
	 *
	 * @param string $value Raw value.
	 */
	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	/**
	 * Return the total matching row count.
	 *
	 * @param string $query Prepared query.
	 */
	public function get_var( string $query ): int {
		unset( $query );

		return 120;
	}

	/**
	 * Return the summary row.
	 *
	 * @param string $query  Prepared query.
	 * @param string $output Output format.
	 * @return array<string, string>
	 */
	public function get_row( string $query, string $output ): array {
		unset( $query, $output );

		return array(
			'total'           => '120',
			'successes'       => '118',
			'failures'        => '2',
			'assistants'      => '3',
			'high_risk'       => '1',
			'content_actions' => '100',
			'comment_actions' => '10',
			'media_actions'   => '10',
		);
	}

	/**
	 * Return one Activity row.
	 *
	 * @param string $query  Prepared query.
	 * @param string $output Output format.
	 * @return array<int, array<string, string|null>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $query, $output );

		return array(
			array(
				'id'          => '1',
				'created_at'  => '2026-08-19 00:00:00',
				'provider'    => 'chatgpt',
				'client_id'   => 'client',
				'client_name' => 'ChatGPT',
				'user_id'     => null,
				'action'      => 'content.update_item',
				'target_type' => 'post',
				'target_id'   => '42',
				'status'      => 'success',
				'error_code'  => null,
				'message'     => '',
				'context'     => '{"risk_level":"publish"}',
			),
		);
	}
}
