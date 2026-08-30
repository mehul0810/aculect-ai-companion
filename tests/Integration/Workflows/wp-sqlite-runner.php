<?php
/**
 * WordPress SQLite integration proof for durable workflow execution.
 *
 * @package Aculect\AICompanion\Tests\Integration\Workflows
 */

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Database\RunInstaller;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStore;
use Aculect\AICompanion\Workflows\Execution\WorkflowStepState;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The SQLite transaction boundary is the subject under proof.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only bounded proof output.

global $wpdb;
if ( ! class_exists( 'WP_SQLite_DB' ) || ! $wpdb instanceof \WP_SQLite_DB ) {
	throw new RuntimeException( 'The WordPress SQLite integration adapter is not active.' );
}

if ( ! RunInstaller::install() ) {
	throw new RuntimeException( 'RunInstaller failed on WP_SQLite_DB.' );
}

$fixture_path = dirname( __DIR__, 3 ) . '/tests/fixtures/workflows/definitions/proposal-only-v1.json';
$fixture_json = file_get_contents( $fixture_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned proof fixture.
if ( ! is_string( $fixture_json ) ) {
	throw new RuntimeException( 'Could not load the workflow proof fixture.' );
}

$definition = WorkflowDefinition::from_json( $fixture_json );
$input      = WorkflowInputContract::from_json( '{"post_id":9}' );
$plan       = ( new WorkflowPlanBuilder() )->build( $definition, $input );
$store      = new WorkflowRunStore( null, static fn (): int => time() );

$run = $store->create( 'sqlite-run-proof', 'proposal_only_fixture', 1, $definition->checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
if ( WorkflowRunState::PREPARED !== $run->state() ) {
	throw new RuntimeException( 'SQLite parent row did not persist.' );
}

$running = $store->transition( $run->run_id(), WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 );
if ( null === $running || WorkflowRunState::RUNNING !== $running->state() ) {
	throw new RuntimeException( 'SQLite lifecycle transition failed.' );
}

$claimed = $store->claim_step( $run->run_id(), 'read_content', 7 );
if ( null === $claimed || WorkflowStepState::RUNNING !== $claimed->state() ) {
	throw new RuntimeException( 'SQLite step claim failed.' );
}

$completed = $store->complete_step( $run->run_id(), 'read_content', $claimed->fence(), WorkflowAdapterResult::success( array( 'ok' => true ) ), 7 );
if ( null === $completed || WorkflowStepState::COMPLETED !== $completed->state() ) {
	throw new RuntimeException( 'SQLite step completion failed.' );
}

$finished = $store->transition( $run->run_id(), WorkflowRunState::RUNNING, 2, WorkflowRunState::COMPLETED, 7, 'completed' );
if ( null === $finished || WorkflowRunState::COMPLETED !== $finished->state() ) {
	throw new RuntimeException( 'SQLite terminal transition failed.' );
}

$tables = RunInstaller::table_names();
if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
	throw new RuntimeException( 'SQLite transaction did not start.' );
}
$inserted = $wpdb->insert(
	$tables['runs'],
	array(
		'run_id'              => 'sqlite-rollback-proof',
		'workflow_id'         => 'rollback_workflow',
		'workflow_version'    => 1,
		'definition_checksum' => str_repeat( 'a', 64 ),
		'plan_hash'           => str_repeat( 'b', 64 ),
		'input_hash'          => str_repeat( 'c', 64 ),
		'input_ciphertext'    => 'v1:proof',
		'state'               => 'prepared',
		'state_version'       => 1,
		'created_by'          => 1,
		'updated_by'          => 1,
	),
	array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
);
if ( false === $inserted ) {
	$wpdb->query( 'ROLLBACK' );
	throw new RuntimeException( 'SQLite rollback parent insert failed.' );
}

$invalid = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (not_a_real_column) VALUES (1)', $tables['steps'] ) );
if ( false !== $invalid || false === $wpdb->query( 'ROLLBACK' ) ) {
	throw new RuntimeException( 'SQLite injected failure or rollback did not fail closed.' );
}

$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %s', $tables['runs'], 'sqlite-rollback-proof' ) );
if ( 0 !== $remaining ) {
	throw new RuntimeException( 'SQLite rollback left a durable workflow row behind.' );
}

echo 'PASS WP_SQLite_DB workflow runner and transaction proof (' . get_class( $wpdb ) . ')' . PHP_EOL;
