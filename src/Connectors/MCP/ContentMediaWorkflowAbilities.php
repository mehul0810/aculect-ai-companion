<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Smooth media-to-content workflows for assistant clients.
 */
final class ContentMediaWorkflowAbilities extends AbstractAbilityService {

	private const SOURCE_TYPES = array( 'attachment_id', 'url', 'generated_url', 'image_data', 'data_url', 'search_cc0' );
	private const TARGETS      = array( 'featured_image', 'insert_block' );
	private const BLOCK_TYPES  = array( 'image', 'gallery', 'cover', 'media_text' );
	private const PLACEMENTS   = array( 'append', 'prepend', 'after_first_paragraph', 'after_heading' );

	/**
	 * Search CC0 image candidates without changing WordPress content.
	 *
	 * @param array<string, mixed> $args Search arguments.
	 * @return array<string, mixed>
	 */
	public function search_cc0_images( array $args ): array {
		return ( new OpenverseImageProvider() )->search( $args );
	}

	/**
	 * Resolve an image source, upload/import if needed, and apply it to content.
	 *
	 * @param array<string, mixed> $args Workflow arguments.
	 * @return array<string, mixed>
	 */
	public function apply_image( array $args ): array {
		$post_id = absint( $args['post_id'] ?? $args['id'] ?? 0 );
		if ( 0 >= $post_id ) {
			return $this->workflow_error( 'invalid_post_id', 'Provide an existing post_id.' );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return $this->workflow_error( 'not_found', 'Content item not found.' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->workflow_error( 'forbidden', 'You do not have permission to update this content item.' );
		}

		$source_type = $this->source_type( $args );
		$target      = $this->target( $args );
		$block_type  = $this->block_type( $args );
		$placement   = $this->placement( $args );

		if ( $this->is_dry_run( $args ) ) {
			return $this->dry_run( $post, $source_type, $target, $block_type, $placement, $args );
		}

		$image = $this->resolve_image( $source_type, $post_id, $args );
		if ( isset( $image['error'] ) ) {
			return $this->workflow_error(
				(string) $image['error'],
				(string) ( $image['message'] ?? 'Image source could not be resolved.' ),
				$image
			);
		}

		$attachment_id = absint( $image['attachment_id'] ?? $image['id'] ?? 0 );
		$validation    = $this->validated_image_attachment_id( $attachment_id );
		if ( is_array( $validation ) ) {
			return $this->workflow_error(
				(string) $validation['error'],
				(string) ( $validation['message'] ?? 'Image attachment is invalid.' ),
				array( 'image' => $image )
			);
		}

		$apply = $this->apply_target( $post, $validation, $target, $block_type, $placement, $args );
		if ( isset( $apply['error'] ) ) {
			return $this->workflow_error(
				(string) $apply['error'],
				(string) ( $apply['message'] ?? 'Image could not be applied to content.' ),
				array(
					'image'       => $image,
					'apply_error' => $apply,
				)
			);
		}

		$this->store_source_metadata( $validation, $source_type, $image, $args );

		return array(
			'status'        => 'success',
			'workflow'      => 'content_media_apply_image',
			'post_id'       => (int) $post->ID,
			'post_type'     => $post->post_type,
			'target'        => $target,
			'block_type'    => 'insert_block' === $target ? $block_type : '',
			'placement'     => 'insert_block' === $target ? $placement : '',
			'attachment_id' => $validation,
			'image'         => $image,
			'content'       => $apply,
			'edit_url'      => $this->edit_url( (int) $post->ID ),
			'next_actions'  => array( 'Review the updated post in the WordPress editor before publishing.' ),
		);
	}

	/**
	 * Build a dry-run plan and validate local post/block changes when possible.
	 *
	 * @param \WP_Post             $post        Target post.
	 * @param string               $source_type Source type.
	 * @param string               $target      Target action.
	 * @param string               $block_type  Block type.
	 * @param string               $placement   Placement.
	 * @param array<string, mixed> $args        Original arguments.
	 * @return array<string, mixed>
	 */
	private function dry_run( \WP_Post $post, string $source_type, string $target, string $block_type, string $placement, array $args ): array {
		$image      = array();
		$warnings   = array();
		$candidates = array();

		if ( 'search_cc0' === $source_type ) {
			$search = $this->search_cc0_images( $args );
			if ( isset( $search['error'] ) ) {
				return $this->workflow_error( (string) $search['error'], (string) $search['message'], $search );
			}
			$candidates = (array) ( $search['items'] ?? array() );
			$image      = $candidates[0] ?? array();
			$warnings   = array_merge( $warnings, (array) ( $search['warnings'] ?? array() ) );
		} elseif ( 'attachment_id' === $source_type ) {
			$attachment_id = $this->attachment_id_arg( $args );
			$validation    = $this->validated_image_attachment_id( $attachment_id );
			if ( is_array( $validation ) ) {
				return $this->workflow_error( (string) $validation['error'], (string) $validation['message'], $validation );
			}
			$image = $this->image_from_attachment( $validation );
		} elseif ( in_array( $source_type, array( 'image_data', 'data_url' ), true ) ) {
			$upload_args            = $this->upload_args_from_data( (int) $post->ID, $args );
			$upload_args['dry_run'] = true;
			$upload_preview         = ( new MediaAbilities() )->upload_image_data( $upload_args );
			if ( isset( $upload_preview['error'] ) ) {
				return $this->workflow_error( (string) $upload_preview['error'], (string) $upload_preview['message'], $upload_preview );
			}
			$image    = $this->planned_external_source( $source_type, $args );
			$warnings = array_merge( $warnings, (array) ( $upload_preview['warnings'] ?? array() ) );
		} else {
			$upload_args            = $this->upload_args_from_url( (int) $post->ID, $args );
			$upload_args['dry_run'] = true;
			$upload_preview         = ( new MediaAbilities() )->upload_media( $upload_args );
			if ( isset( $upload_preview['error'] ) ) {
				return $this->workflow_error( (string) $upload_preview['error'], (string) $upload_preview['message'], $upload_preview );
			}
			$image    = $this->planned_external_source( $source_type, $args );
			$warnings = array_merge( $warnings, (array) ( $upload_preview['warnings'] ?? array() ) );
		}

		$changes = array(
			$this->change( 'source_type', null, $source_type ),
			$this->change( 'target', null, $target ),
		);

		if ( 'featured_image' === $target && isset( $image['attachment_id'] ) ) {
			$preview = ( new ContentAbilities() )->update_item(
				array(
					'id'             => (int) $post->ID,
					'featured_media' => absint( $image['attachment_id'] ),
					'dry_run'        => true,
				)
			);

			if ( isset( $preview['error'] ) ) {
				return $this->workflow_error( (string) $preview['error'], (string) $preview['message'], $preview );
			}
			$changes = array_merge( $changes, (array) ( $preview['changes'] ?? array() ) );
		} elseif ( 'insert_block' === $target && isset( $image['attachment_id'] ) ) {
			$block   = $this->image_block_markup( absint( $image['attachment_id'] ), $block_type, $args );
			$content = $this->content_with_inserted_block( (string) $post->post_content, $block, $placement, $args );
			if ( isset( $content['error'] ) ) {
				return $this->workflow_error( (string) $content['error'], (string) $content['message'], $content );
			}

			$preview = ( new ContentAbilities() )->update_item(
				array(
					'id'      => (int) $post->ID,
					'content' => (string) $content['content'],
					'dry_run' => true,
				)
			);

			if ( isset( $preview['error'] ) ) {
				return $this->workflow_error( (string) $preview['error'], (string) $preview['message'], $preview );
			}
			$changes = array_merge( $changes, (array) ( $preview['changes'] ?? array() ) );
		} elseif ( 'insert_block' === $target ) {
			$changes[]  = $this->change( 'block_type', null, $block_type );
			$changes[]  = $this->change( 'placement', null, $placement );
			$warnings[] = 'Block validation will run after the image is imported and has a real WordPress attachment ID.';
		}

		return array_merge(
			$this->preview_response(
				'content_media.apply_image',
				$args,
				array(
					'type' => $post->post_type,
					'id'   => (int) $post->ID,
				),
				$changes,
				array_values( array_unique( $warnings ) )
			),
			array(
				'workflow'   => 'content_media_apply_image',
				'image'      => $image,
				'candidates' => array_slice( $candidates, 0, 5 ),
			)
		);
	}

	/**
	 * Resolve image input into an attachment.
	 *
	 * @param string               $source_type Source type.
	 * @param int                  $post_id     Parent post ID.
	 * @param array<string, mixed> $args        Original arguments.
	 * @return array<string, mixed>
	 */
	private function resolve_image( string $source_type, int $post_id, array $args ): array {
		if ( 'attachment_id' === $source_type ) {
			$attachment_id = $this->attachment_id_arg( $args );
			$validation    = $this->validated_image_attachment_id( $attachment_id );
			if ( is_array( $validation ) ) {
				return $validation;
			}

			return $this->image_from_attachment( $validation );
		}

		if ( 'search_cc0' === $source_type ) {
			$search = $this->search_cc0_images( $args );
			if ( isset( $search['error'] ) ) {
				return $search;
			}

			$candidate = $this->selected_candidate( (array) ( $search['items'] ?? array() ), $args );
			if ( array() === $candidate ) {
				return $this->error( 'image_candidate_not_found', 'No CC0 image candidate matched the request.' );
			}

			$upload_args = $this->upload_args_from_candidate( $candidate, $post_id, $args );
			$upload      = ( new MediaAbilities() )->upload_media( $upload_args );
			if ( isset( $upload['error'] ) ) {
				return $upload;
			}

			return array_merge(
				$this->image_from_upload( $upload ),
				array(
					'source_type' => $source_type,
					'provider'    => 'openverse',
					'query'       => sanitize_text_field( (string) ( $args['query'] ?? $args['topic'] ?? '' ) ),
					'candidate'   => $candidate,
				)
			);
		}

		if ( in_array( $source_type, array( 'image_data', 'data_url' ), true ) ) {
			$upload = ( new MediaAbilities() )->upload_image_data( $this->upload_args_from_data( $post_id, $args ) );
			if ( isset( $upload['error'] ) ) {
				return $upload;
			}

			return array_merge(
				$this->image_from_upload( $upload ),
				array( 'source_type' => $source_type )
			);
		}

		$upload = ( new MediaAbilities() )->upload_media( $this->upload_args_from_url( $post_id, $args ) );
		if ( isset( $upload['error'] ) ) {
			return $upload;
		}

		return array_merge(
			$this->image_from_upload( $upload ),
			array( 'source_type' => $source_type )
		);
	}

	/**
	 * Apply the resolved image to the requested content target.
	 *
	 * @param \WP_Post             $post          Target post.
	 * @param int                  $attachment_id Image attachment ID.
	 * @param string               $target        Target action.
	 * @param string               $block_type    Block type.
	 * @param string               $placement     Placement.
	 * @param array<string, mixed> $args          Original arguments.
	 * @return array<string, mixed>
	 */
	private function apply_target( \WP_Post $post, int $attachment_id, string $target, string $block_type, string $placement, array $args ): array {
		if ( 'featured_image' === $target ) {
			$result = ( new ContentAbilities() )->update_item(
				array(
					'id'             => (int) $post->ID,
					'featured_media' => $attachment_id,
				)
			);

			return isset( $result['error'] ) ? $result : array(
				'status' => 'updated',
				'fields' => $result,
			);
		}

		$block   = $this->image_block_markup( $attachment_id, $block_type, $args );
		$content = $this->content_with_inserted_block( (string) $post->post_content, $block, $placement, $args );
		if ( isset( $content['error'] ) ) {
			return $content;
		}

		$result = ( new ContentAbilities() )->update_item(
			array(
				'id'      => (int) $post->ID,
				'content' => (string) $content['content'],
			)
		);

		return isset( $result['error'] ) ? $result : array(
			'status'    => 'updated',
			'placement' => $placement,
			'block'     => $block,
			'fields'    => $result,
		);
	}

	/**
	 * Return selected candidate by ID, index, or first result.
	 *
	 * @param array<int, mixed>    $items Search items.
	 * @param array<string, mixed> $args  Original arguments.
	 * @return array<string, mixed>
	 */
	private function selected_candidate( array $items, array $args ): array {
		$selected_id = sanitize_text_field( (string) ( $args['selected_result_id'] ?? $args['candidate_id'] ?? '' ) );
		if ( '' !== $selected_id ) {
			foreach ( $items as $item ) {
				if ( is_array( $item ) && hash_equals( (string) ( $item['id'] ?? '' ), $selected_id ) ) {
					return $item;
				}
			}
		}

		$index = max( 0, absint( $args['selected_index'] ?? 0 ) );
		if ( isset( $items[ $index ] ) && is_array( $items[ $index ] ) ) {
			return $items[ $index ];
		}

		return isset( $items[0] ) && is_array( $items[0] ) ? $items[0] : array();
	}

	/**
	 * Build upload arguments from an Openverse candidate.
	 *
	 * @param array<string, mixed> $candidate Candidate.
	 * @param int                  $post_id Parent post ID.
	 * @param array<string, mixed> $args Original arguments.
	 * @return array<string, mixed>
	 */
	private function upload_args_from_candidate( array $candidate, int $post_id, array $args ): array {
		return array(
			'url'         => (string) ( $candidate['download_url'] ?? '' ),
			'title'       => sanitize_text_field( (string) ( $args['title'] ?? $candidate['title'] ?? 'Imported CC0 image' ) ),
			'alt_text'    => sanitize_text_field( (string) ( $args['alt_text'] ?? $candidate['suggested_alt_text'] ?? $candidate['title'] ?? '' ) ),
			'caption'     => wp_kses_post( (string) ( $args['caption'] ?? $candidate['attribution_text'] ?? '' ) ),
			'description' => wp_kses_post( (string) ( $args['description'] ?? $candidate['landing_url'] ?? '' ) ),
			'post_id'     => $post_id,
		);
	}

	/**
	 * Build upload arguments from a URL source.
	 *
	 * @param int                  $post_id Parent post ID.
	 * @param array<string, mixed> $args Original arguments.
	 * @return array<string, mixed>
	 */
	private function upload_args_from_url( int $post_id, array $args ): array {
		return array(
			'url'         => esc_url_raw( (string) ( $args['url'] ?? $args['image_url'] ?? '' ) ),
			'title'       => sanitize_text_field( (string) ( $args['title'] ?? '' ) ),
			'alt_text'    => sanitize_text_field( (string) ( $args['alt_text'] ?? '' ) ),
			'caption'     => wp_kses_post( (string) ( $args['caption'] ?? '' ) ),
			'description' => wp_kses_post( (string) ( $args['description'] ?? '' ) ),
			'post_id'     => $post_id,
		);
	}

	/**
	 * Build upload arguments from image data source.
	 *
	 * @param int                  $post_id Parent post ID.
	 * @param array<string, mixed> $args Original arguments.
	 * @return array<string, mixed>
	 */
	private function upload_args_from_data( int $post_id, array $args ): array {
		return array(
			'data_url'    => (string) ( $args['data_url'] ?? '' ),
			'data_base64' => (string) ( $args['data_base64'] ?? $args['image_base64'] ?? '' ),
			'mime_type'   => sanitize_text_field( (string) ( $args['mime_type'] ?? '' ) ),
			'filename'    => sanitize_file_name( (string) ( $args['filename'] ?? '' ) ),
			'title'       => sanitize_text_field( (string) ( $args['title'] ?? '' ) ),
			'alt_text'    => sanitize_text_field( (string) ( $args['alt_text'] ?? '' ) ),
			'caption'     => wp_kses_post( (string) ( $args['caption'] ?? '' ) ),
			'description' => wp_kses_post( (string) ( $args['description'] ?? '' ) ),
			'post_id'     => $post_id,
		);
	}

	/**
	 * Return image metadata from an upload result.
	 *
	 * @param array<string, mixed> $upload Upload result.
	 * @return array<string, mixed>
	 */
	private function image_from_upload( array $upload ): array {
		return array(
			'attachment_id' => absint( $upload['id'] ?? 0 ),
			'title'         => sanitize_text_field( (string) ( $upload['title'] ?? '' ) ),
			'source_url'    => esc_url_raw( (string) ( $upload['source_url'] ?? '' ) ),
			'alt_text'      => sanitize_text_field( (string) ( $upload['alt_text'] ?? '' ) ),
			'mime_type'     => sanitize_text_field( (string) ( $upload['mime_type'] ?? '' ) ),
		);
	}

	/**
	 * Return image metadata from an existing attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	private function image_from_attachment( int $attachment_id ): array {
		$attachment = get_post( $attachment_id );
		return array(
			'attachment_id' => $attachment_id,
			'title'         => $attachment instanceof \WP_Post ? get_the_title( $attachment ) : '',
			'source_url'    => (string) wp_get_attachment_url( $attachment_id ),
			'alt_text'      => sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
			'mime_type'     => $attachment instanceof \WP_Post ? (string) $attachment->post_mime_type : '',
		);
	}

	/**
	 * Return dry-run metadata for external sources that will be uploaded later.
	 *
	 * @param string               $source_type Source type.
	 * @param array<string, mixed> $args Original arguments.
	 * @return array<string, mixed>
	 */
	private function planned_external_source( string $source_type, array $args ): array {
		$url = in_array( $source_type, array( 'url', 'generated_url' ), true )
			? esc_url_raw( (string) ( $args['url'] ?? $args['image_url'] ?? '' ) )
			: '';

		return array(
			'source_type' => $source_type,
			'source_url'  => $url,
			'title'       => sanitize_text_field( (string) ( $args['title'] ?? '' ) ),
			'alt_text'    => sanitize_text_field( (string) ( $args['alt_text'] ?? '' ) ),
		);
	}

	/**
	 * Build safe serialized core media block markup.
	 *
	 * @param int                  $attachment_id Image attachment ID.
	 * @param string               $block_type    Block type.
	 * @param array<string, mixed> $args          Original arguments.
	 */
	private function image_block_markup( int $attachment_id, string $block_type, array $args ): string {
		$url = (string) wp_get_attachment_url( $attachment_id );
		$alt = sanitize_text_field( (string) ( $args['alt_text'] ?? get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) );
		if ( '' === $alt ) {
			$alt = sanitize_text_field( (string) get_the_title( $attachment_id ) );
		}

		return match ( $block_type ) {
			'gallery' => $this->gallery_block_markup( $attachment_id, $url, $alt ),
			'cover' => $this->cover_block_markup( $attachment_id, $url, $alt ),
			'media_text' => $this->media_text_block_markup( $attachment_id, $url, $alt, (string) ( $args['block_text'] ?? '' ) ),
			default => $this->image_single_block_markup( $attachment_id, $url, $alt ),
		};
	}

	/**
	 * Build a core/image block.
	 *
	 * @param int    $attachment_id Image attachment ID.
	 * @param string $url           Image source URL.
	 * @param string $alt           Image alt text.
	 */
	private function image_single_block_markup( int $attachment_id, string $url, string $alt ): string {
		$attrs = wp_json_encode(
			array(
				'id'              => $attachment_id,
				'sizeSlug'        => 'large',
				'linkDestination' => 'none',
			),
			JSON_UNESCAPED_SLASHES
		);

		return sprintf(
			'<!-- wp:image %1$s --><figure class="wp-block-image size-large"><img src="%2$s" alt="%3$s" class="wp-image-%4$d"/></figure><!-- /wp:image -->',
			false === $attrs ? '{}' : $attrs,
			esc_url( $url ),
			esc_attr( $alt ),
			$attachment_id
		);
	}

	/**
	 * Build a single-image core/gallery block.
	 *
	 * @param int    $attachment_id Image attachment ID.
	 * @param string $url           Image source URL.
	 * @param string $alt           Image alt text.
	 */
	private function gallery_block_markup( int $attachment_id, string $url, string $alt ): string {
		return sprintf(
			'<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped">%s</figure><!-- /wp:gallery -->',
			$this->image_single_block_markup( $attachment_id, $url, $alt )
		);
	}

	/**
	 * Build a core/cover block.
	 *
	 * @param int    $attachment_id Image attachment ID.
	 * @param string $url           Image source URL.
	 * @param string $alt           Image alt text.
	 */
	private function cover_block_markup( int $attachment_id, string $url, string $alt ): string {
		$attrs = wp_json_encode(
			array(
				'url'      => $url,
				'id'       => $attachment_id,
				'dimRatio' => 40,
			),
			JSON_UNESCAPED_SLASHES
		);

		return sprintf(
			'<!-- wp:cover %1$s --><div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-40 has-background-dim"></span><img class="wp-block-cover__image-background wp-image-%2$d" alt="%3$s" src="%4$s" data-object-fit="cover"/><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->',
			false === $attrs ? '{}' : $attrs,
			$attachment_id,
			esc_attr( $alt ),
			esc_url( $url )
		);
	}

	/**
	 * Build a core/media-text block.
	 *
	 * @param int    $attachment_id Image attachment ID.
	 * @param string $url           Image source URL.
	 * @param string $alt           Image alt text.
	 * @param string $text          Optional media text body.
	 */
	private function media_text_block_markup( int $attachment_id, string $url, string $alt, string $text ): string {
		$attrs = wp_json_encode(
			array(
				'mediaId'   => $attachment_id,
				'mediaType' => 'image',
			),
			JSON_UNESCAPED_SLASHES
		);
		$text  = '' === trim( $text ) ? '' : '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';

		return sprintf(
			'<!-- wp:media-text %1$s --><div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="%2$s" alt="%3$s" class="wp-image-%4$d size-full"/></figure><div class="wp-block-media-text__content">%5$s</div></div><!-- /wp:media-text -->',
			false === $attrs ? '{}' : $attrs,
			esc_url( $url ),
			esc_attr( $alt ),
			$attachment_id,
			$text
		);
	}

	/**
	 * Insert block markup into existing serialized post content.
	 *
	 * @param string               $content Existing content.
	 * @param string               $block   Block markup.
	 * @param string               $placement Placement.
	 * @param array<string, mixed> $args Original arguments.
	 * @return array<string, mixed>
	 */
	private function content_with_inserted_block( string $content, string $block, string $placement, array $args ): array {
		$content = trim( $content );

		if ( '' === $content ) {
			return array( 'content' => $block );
		}

		if ( 'prepend' === $placement ) {
			return array( 'content' => $block . "\n\n" . $content );
		}

		if ( 'append' === $placement ) {
			return array( 'content' => $content . "\n\n" . $block );
		}

		if ( 'after_first_paragraph' === $placement ) {
			$updated = preg_replace( '/(<!--\s+wp:paragraph\b.*?<!--\s+\/wp:paragraph\s+-->)/is', '$1' . "\n\n" . $block, $content, 1, $count );
			if ( is_string( $updated ) && $count > 0 ) {
				return array( 'content' => $updated );
			}

			return $this->error( 'placement_not_found', 'No paragraph block was found for after_first_paragraph placement.' );
		}

		$section_id = sanitize_title( (string) ( $args['section_id'] ?? $args['after_heading'] ?? '' ) );
		if ( '' === $section_id ) {
			return $this->error( 'invalid_section_id', 'Provide section_id or after_heading when placement is after_heading.' );
		}

		return $this->insert_after_heading( $content, $block, $section_id );
	}

	/**
	 * Insert block after a matching heading block.
	 *
	 * @param string $content Existing content.
	 * @param string $block Block markup.
	 * @param string $section_id Target section ID.
	 * @return array<string, mixed>
	 */
	private function insert_after_heading( string $content, string $block, string $section_id ): array {
		$matched = preg_match_all( '/<!--\s+wp:heading(?:\s+(\{.*?\}))?\s+-->.*?<!--\s+\/wp:heading\s+-->/is', $content, $matches, PREG_OFFSET_CAPTURE );
		if ( false === $matched || 0 === $matched ) {
			return $this->error( 'placement_not_found', 'No heading block was found for after_heading placement.' );
		}

		foreach ( $matches[0] as $index => $match ) {
			$heading = (string) $match[0];
			$start   = (int) $match[1];
			$id      = $this->heading_section_id( $heading, (string) ( $matches[1][ $index ][0] ?? '' ) );
			if ( $section_id !== $id ) {
				continue;
			}

			$end = $start + strlen( $heading );
			return array(
				'content' => substr( $content, 0, $end ) . "\n\n" . $block . substr( $content, $end ),
			);
		}

		return $this->error( 'placement_not_found', 'The requested heading section was not found.' );
	}

	/**
	 * Return a stable section ID for one heading block.
	 *
	 * @param string $heading    Serialized heading block.
	 * @param string $json_attrs Heading JSON attributes.
	 */
	private function heading_section_id( string $heading, string $json_attrs ): string {
		if ( '' !== trim( $json_attrs ) ) {
			$attrs = json_decode( $json_attrs, true );
			if ( is_array( $attrs ) && isset( $attrs['anchor'] ) && is_scalar( $attrs['anchor'] ) ) {
				return sanitize_title( (string) $attrs['anchor'] );
			}
		}

		if ( preg_match( '/<h[1-6][^>]*\sid=[\'"]([^\'"]+)[\'"]/i', $heading, $matches ) ) {
			return sanitize_title( (string) $matches[1] );
		}

		return sanitize_title( wp_strip_all_tags( $heading ) );
	}

	/**
	 * Persist non-sensitive media provenance.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param string               $source_type Source type.
	 * @param array<string, mixed> $image Image metadata.
	 * @param array<string, mixed> $args Original args.
	 */
	private function store_source_metadata( int $attachment_id, string $source_type, array $image, array $args ): void {
		if ( 0 >= $attachment_id ) {
			return;
		}

		update_post_meta(
			$attachment_id,
			'_aculect_ai_companion_media_source',
			array(
				'source_type' => $source_type,
				'provider'    => sanitize_key( (string) ( $image['provider'] ?? '' ) ),
				'query'       => sanitize_text_field( (string) ( $image['query'] ?? $args['query'] ?? $args['topic'] ?? '' ) ),
				'license'     => sanitize_text_field( (string) ( $image['candidate']['license'] ?? '' ) ),
				'landing_url' => esc_url_raw( (string) ( $image['candidate']['landing_url'] ?? '' ) ),
			)
		);
	}

	/**
	 * Return normalized source type.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private function source_type( array $args ): string {
		$source_type = sanitize_key( (string) ( $args['source_type'] ?? '' ) );
		if ( '' === $source_type ) {
			$source_type = isset( $args['attachment_id'] ) ? 'attachment_id' : ( isset( $args['data_url'] ) || isset( $args['data_base64'] ) || isset( $args['image_base64'] ) ? 'image_data' : 'url' );
		}

		return in_array( $source_type, self::SOURCE_TYPES, true ) ? $source_type : 'url';
	}

	/**
	 * Return normalized target action.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private function target( array $args ): string {
		$target = sanitize_key( (string) ( $args['target'] ?? 'featured_image' ) );
		return in_array( $target, self::TARGETS, true ) ? $target : 'featured_image';
	}

	/**
	 * Return normalized block type.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private function block_type( array $args ): string {
		$block_type = sanitize_key( (string) ( $args['block_type'] ?? 'image' ) );
		return in_array( $block_type, self::BLOCK_TYPES, true ) ? $block_type : 'image';
	}

	/**
	 * Return normalized placement.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private function placement( array $args ): string {
		$placement = sanitize_key( (string) ( $args['placement'] ?? 'append' ) );
		return in_array( $placement, self::PLACEMENTS, true ) ? $placement : 'append';
	}

	/**
	 * Return attachment ID from accepted aliases.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private function attachment_id_arg( array $args ): int {
		return absint( $args['attachment_id'] ?? $args['media_id'] ?? $args['image_id'] ?? 0 );
	}

	/**
	 * Return a workflow-shaped error.
	 *
	 * @param string               $code    Error code.
	 * @param string               $message Error message.
	 * @param array<string, mixed> $details Extra details.
	 * @return array<string, mixed>
	 */
	private function workflow_error( string $code, string $message, array $details = array() ): array {
		return array_merge(
			array(
				'status'   => 'error',
				'workflow' => 'content_media_apply_image',
				'error'    => $code,
				'message'  => $message,
			),
			$details
		);
	}

	/**
	 * Return post edit URL when available.
	 *
	 * @param int $post_id Post ID.
	 */
	private function edit_url( int $post_id ): string {
		if ( $post_id <= 0 || ! function_exists( 'get_edit_post_link' ) ) {
			return '';
		}

		return (string) get_edit_post_link( $post_id, 'raw' );
	}
}
