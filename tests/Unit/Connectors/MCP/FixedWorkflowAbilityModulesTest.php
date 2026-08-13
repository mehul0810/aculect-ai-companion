<?php
/**
 * Tests for extracted fixed workflow modules.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\Modules\FixedWorkflowAbilityModules;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Freezes the extracted provider contract and duplicate-ID boundary.
 */
final class FixedWorkflowAbilityModulesTest extends TestCase {

	public function test_provider_projection_matches_frozen_contract(): void {
		$projection = array();
		foreach ( ( new FixedWorkflowAbilityModules() )->all() as $module ) {
			$projection[] = array(
				'id'          => $module->id(),
				'title'       => $module->title(),
				'description' => $module->description(),
				'group'       => $module->group(),
				'scopes'      => $module->required_scopes(),
				'read_only'   => $module->is_read_only(),
				'schema'      => $module->input_schema(),
			);
		}

		self::assertSame(
			// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine -- Frozen ordered ID projection is more readable grouped by workflow family.
			array(
				'workflow.route_request', 'workflow_session.start', 'workflow_session.get', 'workflow_session.update',
				'workflow_loop.create', 'workflow_loop.get', 'workflow_loop.run_next', 'workflow_loop.run_batch', 'workflow_loop.pause', 'workflow_loop.cancel',
				'workflow_guides.list', 'workflow_guides.get', 'content_workflow.prepare_post', 'content_workflow.create_draft', 'content_workflow.update_post',
				'content_media.search_cc0_images', 'content_media.apply_image', 'seo_workflow.update_rankmath', 'site_workflow.audit',
			),
			// phpcs:enable
			array_column( $projection, 'id' )
		);
		self::assertSame(
			'5a7b569af44959f4fa263d260b3162475c5652963fbe278ddb9e13bb1bee53da',
			hash( 'sha256', wp_json_encode( $projection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) )
		);
	}

	public function test_duplicate_ids_fail_closed(): void {
		$factory = new AbilityModuleFactory();
		$module  = $factory->create( 'duplicate.id', 'Duplicate', 'Duplicate test module.', 'Tests', 'content:read', true, array( 'type' => 'object' ), static fn (): array => array() );

		$this->expectException( RuntimeException::class );
		( new FixedWorkflowAbilityModules( $factory ) )->key_by_id( array( $module, $module ) );
	}

	public function test_write_modules_keep_shared_safety_controls(): void {
		$modules = ( new FixedWorkflowAbilityModules() )->all();
		foreach ( $modules as $module ) {
			if ( $module->is_read_only() ) {
				continue;
			}

			self::assertArrayHasKey( 'dry_run', $module->input_schema()['properties'] );
			self::assertArrayHasKey( 'confirmation_token', $module->input_schema()['properties'] );
			self::assertArrayHasKey( 'idempotency_key', $module->input_schema()['properties'] );
		}
	}
}
