<?php
/**
 * Editor-authorized access to existing registered scalar post fields.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Uses native metadata APIs without arbitrary field or option access. */
final class RegisteredFieldAbilities extends AbstractAbilityService {

	private readonly RegisteredFieldPolicy $policy;

	public function __construct() {
		$this->policy = new RegisteredFieldPolicy();
	}

	/**
	 * List safe field definitions, never values.
	 *
	 * @param array<string, mixed> $args Target and pagination.
	 * @return array<string, mixed>
	 */
	public function list_fields( array $args ): array {
		try {
			$post = $this->post( $args );
			if ( is_array( $post ) ) {
				return $post;
			}
			$page     = $args['page'] ?? 1;
			$per_page = $args['per_page'] ?? 25;
			if ( ! is_int( $page ) || $page < 1 || $page > 5000 || ! is_int( $per_page ) || $per_page < 1 || $per_page > 50 ) {
				return $this->error( 'invalid_pagination', 'Use a page from 1 to 5000 and a page size from 1 to 50.' );
			}
			$items = array();
			foreach ( $this->policy->schemas( $post->post_type ) as $key => $schema ) {
				if ( current_user_can( 'edit_post_meta', $post->ID, $key ) ) {
					$items[] = array(
						'key'    => $key,
						'schema' => $schema,
					);
				}
			}
			return array(
				'post_id'  => $post->ID,
				'items'    => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
				'total'    => count( $items ),
				'page'     => $page,
				'per_page' => $per_page,
			);
		} catch ( \Throwable ) {
			return $this->error( 'field_unavailable', 'Registered fields could not be inspected safely.' );
		}
	}

	/**
	 * Read one field and its optimistic-concurrency state.
	 *
	 * @param array<string, mixed> $args Target field.
	 * @return array<string, mixed>
	 */
	public function read_field( array $args ): array {
		try {
			$field = $this->field( $args );
			return isset( $field['error'] ) ? $field : $this->public_field( $field );
		} catch ( \Throwable ) {
			return $this->error( 'field_unavailable', 'The registered field could not be read safely.' );
		}
	}

	/**
	 * Update exactly one existing field after state and schema checks.
	 *
	 * @param array<string, mixed> $args Target, value and expected state.
	 * @return array<string, mixed>
	 */
	public function update_field( array $args ): array {
		try {
			$field = $this->field( $args );
			if ( isset( $field['error'] ) ) {
				return $field;
			}
			$expected = $args['expected_state'] ?? null;
			if ( ! is_string( $expected ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) || ! hash_equals( $field['expected_state'], $expected ) ) {
				return $this->error( 'stale_field_state', 'Read the field again before updating its exact current state.' );
			}
			if ( ! $field['exists'] || ! empty( $field['schema']['readonly'] ) ) {
				return $this->error( 'field_not_writable', 'Only existing writable scalar fields can be updated.' );
			}
			$value = $this->write_value( $args['value'] ?? null, $field );
			if ( isset( $value['error'] ) ) {
				return $value;
			}
			if ( $this->is_dry_run( $args ) ) {
				return $this->preview_response(
					'content_fields.update_field',
					$args,
					array(
						'post_id'        => $field['post_id'],
						'key'            => $field['key'],
						'expected_state' => $expected,
					),
					array( $this->change( 'value', $field['value'], $value['value'] ) )
				);
			}
			if ( $field['value'] === $value['value'] ) {
				return array(
					'success' => true,
					'changed' => false,
					'field'   => $this->public_field( $field ),
				);
			}
			// Revalidate after registered sanitizers, which are third-party callbacks.
			$current = $this->field( $args );
			if ( isset( $current['error'] ) || ! hash_equals( $expected, $current['expected_state'] ) ) {
				return $this->error( 'stale_field_state', 'The field changed during validation. Read it again.' );
			}
		} catch ( \Throwable ) {
			return $this->error( 'field_unavailable', 'The field update could not be validated safely.' );
		}
		return $this->persist( $args, $field, $value['value'] );
	}

	/**
	 * Resolve an editor-accessible, REST-enabled public post.
	 *
	 * @param array<string, mixed> $args Target arguments.
	 * @return \WP_Post|array<string, mixed>
	 */
	private function post( array $args ): \WP_Post|array {
		$id = $args['post_id'] ?? null;
		if ( ! is_int( $id ) || $id < 1 ) {
			return $this->error( 'invalid_post', 'Provide a positive post ID.' );
		}
		if ( ! current_user_can( 'read_post', $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return $this->error( 'forbidden', 'Reading and editing this post are required for registered field access.' );
		}
		$post = get_post( $id );
		$type = $post instanceof \WP_Post ? get_post_type_object( $post->post_type ) : null;
		if ( ! $post instanceof \WP_Post || ! $type instanceof \WP_Post_Type || ! $this->is_supported_post_type( $type ) || ! $type->public || ! $type->show_in_rest || ! post_type_supports( $post->post_type, 'custom-fields' ) ) {
			return $this->error( 'unsupported_post', 'The post must support registered custom fields through REST.' );
		}
		return $post;
	}

	/**
	 * Resolve field state without returning raw stored rows to clients.
	 *
	 * @param array<string, mixed> $args Target field.
	 * @return array<string, mixed>
	 */
	private function field( array $args ): array {
		$post = $this->post( $args );
		if ( is_array( $post ) ) {
			return $post;
		}
		$key = $args['key'] ?? null;
		if ( ! is_string( $key ) || ! $this->policy->valid_key( $key ) || ! current_user_can( 'edit_post_meta', $post->ID, $key ) ) {
			return $this->error( 'field_forbidden', 'This field is not available for the connected editor.' );
		}
		$schema = $this->policy->schemas( $post->post_type )[ $key ] ?? null;
		if ( null === $schema ) {
			return $this->error( 'unsupported_field', 'Only editor-visible registered single scalar fields are supported.' );
		}
		// Registered defaults are not persisted rows and must not imply existence.
		$rows = metadata_exists( 'post', $post->ID, $key ) ? get_post_meta( $post->ID, $key, false ) : array();
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) > 1 ) {
			return $this->error( 'ambiguous_field_state', 'The field does not have a single unambiguous storage value.' );
		}
		$value = array() === $rows ? array() : $this->policy->normalize( $rows[0], $schema );
		if ( isset( $value['error'] ) ) {
			return $this->error( 'invalid_field_value', 'The stored field value does not satisfy its scalar schema.' );
		}
		$encoded = wp_json_encode(
			array(
				'post_id' => $post->ID,
				'key'     => $key,
				'schema'  => $schema,
				'rows'    => $rows,
			)
		);
		if ( ! is_string( $encoded ) ) {
			return $this->error( 'invalid_field_state', 'The field state could not be encoded safely.' );
		}
		$state = hash( 'sha256', $encoded );
		return array_merge(
			array(
				'post_id'        => $post->ID,
				'key'            => $key,
				'schema'         => $schema,
				'exists'         => array() !== $rows,
				'expected_state' => $state,
				'rows'           => $rows,
				'post_type'      => $post->post_type,
			),
			$value
		);
	}

	/**
	 * Honor both REST and native metadata sanitizers before mutation.
	 *
	 * @param mixed                $value Candidate value.
	 * @param array<string, mixed> $field Resolved field.
	 * @return array<string, mixed>
	 */
	private function write_value( mixed $value, array $field ): array {
		$valid = $this->policy->validate( $value, $field['schema'] );
		if ( isset( $valid['error'] ) ) {
			return $this->error( 'invalid_field_value', 'The value does not satisfy the registered scalar schema.' );
		}
		$value = rest_sanitize_value_from_schema( $value, $field['schema'], 'value' );
		$value = sanitize_meta( $field['key'], $value, 'post', $field['post_type'] );
		$valid = $this->policy->validate( $value, $field['schema'] );
		return isset( $valid['error'] ) ? $this->error( 'invalid_field_value', 'The sanitized value does not satisfy the registered scalar schema.' ) : $valid;
	}

	/**
	 * Persist once and verify; never delete or compensate after uncertain writes.
	 *
	 * @param array<string, mixed> $args  Original request.
	 * @param array<string, mixed> $field Original state.
	 * @param mixed                $value Validated value.
	 * @return array<string, mixed>
	 */
	private function persist( array $args, array $field, mixed $value ): array {
		try {
			$updated = update_post_meta( $field['post_id'], $field['key'], wp_slash( $value ), $field['rows'][0] );
			if ( is_int( $updated ) ) {
				// A concurrent deletion caused native metadata insertion, not an update.
				return $this->uncertain_write();
			}
			$after = $this->field( $args );
			if ( ! isset( $after['error'] ) && $after['exists'] && $after['value'] === $value && $after['schema'] === $field['schema'] ) {
				return array(
					'success' => true,
					'changed' => true,
					'field'   => $this->public_field( $after ),
				);
			}
			if ( false === $updated && ! isset( $after['error'] ) && hash_equals( $field['expected_state'], $after['expected_state'] ) ) {
				return $this->error( 'field_update_failed', 'The field was not changed.' );
			}
		} catch ( \Throwable ) {
			// Native hooks may throw after persistence; the gateway must not retry.
			return $this->uncertain_write();
		}
		return $this->uncertain_write();
	}

	/**
	 * Return a terminal result when persistence cannot be verified.
	 *
	 * @return array<string, mixed>
	 */
	private function uncertain_write(): array {
		return array(
			'error'         => 'partial_write',
			'terminal'      => true,
			'status'        => 'partial_write',
			'partial_write' => true,
			'message'       => 'The field write could not be verified. Inspect it manually before any further update; do not retry automatically.',
		);
	}

	/**
	 * Remove private concurrency inputs from response fields.
	 *
	 * @param array<string, mixed> $field Internal field state.
	 * @return array<string, mixed>
	 */
	private function public_field( array $field ): array {
		unset( $field['rows'], $field['post_type'] );
		return $field;
	}
}
