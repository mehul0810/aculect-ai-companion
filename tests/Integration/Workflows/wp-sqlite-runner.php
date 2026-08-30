<?php
/**
 * WordPress SQLite integration proof for durable workflow execution.
 *
 * @package Aculect\AICompanion\Tests\Integration\Workflows
 */

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Database\RunInstaller;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Execution\WorkflowApprovalTokenStore;
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

// Prove the server-issued workflow approval boundary against the real
// WP_SQLite_DB adapter. The adapter translates the MySQL-shaped atomic claim
// and cleanup queries; this catches dialect regressions that lightweight tests
// cannot observe.
$approval_fixture_path = dirname( __DIR__, 3 ) . '/tests/fixtures/workflows/definitions/ordered-multi-step-v1.json';
$approval_fixture_json = file_get_contents( $approval_fixture_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned proof fixture.
if ( ! is_string( $approval_fixture_json ) ) {
	throw new RuntimeException( 'Could not load the approval proof fixture.' );
}

$approval_definition = WorkflowDefinition::from_json( $approval_fixture_json );
$approval_input      = WorkflowInputContract::from_json( '{"brief":"SQLite approval proof"}' );
$approval_plan       = ( new WorkflowPlanBuilder() )->build( $approval_definition, $approval_input );
$approval_auth       = array(
	'user_id'   => 7,
	'client_id' => 'sqlite-proof',
	'provider'  => 'mcp',
);
$approval_store      = new WorkflowApprovalTokenStore();
$approval_reflector  = new ReflectionMethod( WorkflowApprovalTokenStore::class, 'key' );
$approval_reflector->setAccessible( true );
$claim_reflector = new ReflectionMethod( WorkflowApprovalTokenStore::class, 'consumed_key' );
$claim_reflector->setAccessible( true );

$approval_token = $approval_store->issue( 'sqlite-approval-proof', $approval_plan, $approval_auth );
if ( ! $approval_store->consume( $approval_token, 'sqlite-approval-proof', $approval_plan, $approval_auth ) ) {
	throw new RuntimeException( 'SQLite approval token was not consumed.' );
}
if ( $approval_store->consume( $approval_token, 'sqlite-approval-proof', $approval_plan, $approval_auth ) ) {
	throw new RuntimeException( 'SQLite approval token replay unexpectedly succeeded.' );
}

$expired_token          = $approval_store->issue( 'sqlite-expiry-proof', $approval_plan, $approval_auth );
$expired_key            = (string) $approval_reflector->invoke( $approval_store, $expired_token );
$expired_timeout_option = '_transient_timeout_' . $expired_key;
update_option( $expired_timeout_option, time() - 1, false );
wp_cache_delete( $expired_timeout_option, 'options' );
wp_cache_delete( '_transient_' . $expired_key, 'options' );
if ( $approval_store->consume( $expired_token, 'sqlite-expiry-proof', $approval_plan, $approval_auth ) ) {
	throw new RuntimeException( 'Expired SQLite approval token unexpectedly succeeded.' );
}

$cleanup_token = $approval_store->issue( 'sqlite-cleanup-proof', $approval_plan, $approval_auth );
if ( ! $approval_store->consume( $cleanup_token, 'sqlite-cleanup-proof', $approval_plan, $approval_auth ) ) {
	throw new RuntimeException( 'SQLite cleanup proof token was not consumed.' );
}
$cleanup_claim_key = (string) $claim_reflector->invoke( $approval_store, $cleanup_token );
update_option( $cleanup_claim_key, (string) ( time() - 1 ), false );
$approval_store->issue( 'sqlite-cleanup-trigger', $approval_plan, $approval_auth );
$cleanup_claims = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $wpdb->options, $cleanup_claim_key ) );
if ( 0 !== $cleanup_claims ) {
	throw new RuntimeException( 'Expired SQLite approval claim was not pruned.' );
}

// A storage failure must deny the claim. A pre-transient filter keeps the
// server-issued record available while the claim target is deliberately made
// unavailable, exercising the real adapter's query-failure path.
$failure_token          = $approval_store->issue( 'sqlite-failure-proof', $approval_plan, $approval_auth );
$failure_key            = (string) $approval_reflector->invoke( $approval_store, $failure_token );
$failure_payload        = get_transient( $failure_key );
$original_options_table = $wpdb->options;
$failure_filter         = static function () use ( $failure_payload ): mixed {
	return $failure_payload;
};
add_filter( 'pre_transient_' . $failure_key, $failure_filter, 10, 3 );
$wpdb->options  = 'wp_aculect_missing_options';
$failure_result = $approval_store->consume( $failure_token, 'sqlite-failure-proof', $approval_plan, $approval_auth );
$wpdb->options  = $original_options_table;
remove_filter( 'pre_transient_' . $failure_key, $failure_filter, 10 );
if ( $failure_result ) {
	throw new RuntimeException( 'SQLite approval query failure unexpectedly succeeded.' );
}

echo 'PASS WP_SQLite_DB workflow runner and transaction proof (' . get_class( $wpdb ) . ')' . PHP_EOL;
