<?php
/**
 * Source-external workflow repair lease proof against WordPress's Options API.
 *
 * @package Aculect\AICompanion\Tests\Integration\Workflows
 */

declare(strict_types=1);

use Aculect\AICompanion\Workflows\Database\RunInstaller;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only bounded proof output.

/**
 * Throw a bounded proof failure.
 *
 * @param bool   $condition Condition that must hold.
 * @param string $message   Failure message.
 * @throws RuntimeException When the condition is false.
 */
function aculect_workflow_options_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$option       = 'aculect_ai_companion_workflow_runs_engine_repair_retry_after';
$lock_option  = 'aculect_ai_companion_workflow_runs_engine_repair_lock';
$now          = time();
$expired      = (string) ( $now - 1 );
$retry_after  = $now + 300;
$reflection   = new ReflectionClass( RunInstaller::class );
$delete_retry = $reflection->getMethod( 'delete_repair_retry_if_value' );
$publish      = $reflection->getMethod( 'publish_repair_failure' );
$delete_retry->setAccessible( true );
$publish->setAccessible( true );

delete_option( $option );
delete_option( $lock_option );

// Simulate the failed ALTER path after an expired lease was observed and
// warmed in the real WordPress options cache. The SQL CAS cleanup must evict
// that stale value so publish_repair_failure can durably add the new backoff.
add_option( $option, $expired, '', false );
aculect_workflow_options_assert( hash_equals( $expired, (string) get_option( $option, '' ) ), 'Could not warm the expired retry option.' );
aculect_workflow_options_assert( true === $delete_retry->invoke( null, $expired ), 'Expired retry cleanup did not delete the exact row.' );
aculect_workflow_options_assert( null === get_option( $option, null ), 'SQL retry cleanup left a stale cached value.' );

$lock_token = bin2hex( random_bytes( 16 ) ) . ':' . ( $now + 300 );
aculect_workflow_options_assert( add_option( $lock_option, $lock_token, '', false ), 'Could not create the repair lease for failure publication.' );
$outcome = (string) $publish->invoke( null, $lock_token, $now );
aculect_workflow_options_assert( 1 === preg_match( '/^failure:[a-f0-9]{32}:\d+$/D', $outcome ), 'Failed ALTER publication did not retain an exact failure outcome.' );
$persisted = get_option( $option, null );
aculect_workflow_options_assert( is_int( $persisted ) || is_string( $persisted ), 'Retry backoff was not persisted through WordPress options.' );
aculect_workflow_options_assert( (int) $persisted >= $retry_after - 1, 'Retry backoff was not durable after failed ALTER publication.' );
delete_option( $lock_option );
delete_option( $option );

// A malformed value follows the same cleanup path and must not prevent a
// fresh bounded backoff from being written.
$malformed = 'malformed-retry-value';
add_option( $option, $malformed, '', false );
aculect_workflow_options_assert( hash_equals( $malformed, (string) get_option( $option, '' ) ), 'Could not warm the malformed retry option.' );
aculect_workflow_options_assert( true === $delete_retry->invoke( null, $malformed ), 'Malformed retry cleanup did not delete the exact row.' );
aculect_workflow_options_assert( null === get_option( $option, null ), 'Malformed retry cleanup left a stale cached value.' );
$malformed_lock = bin2hex( random_bytes( 16 ) ) . ':' . ( $now + 300 );
aculect_workflow_options_assert( add_option( $lock_option, $malformed_lock, '', false ), 'Could not create the malformed-retry repair lease.' );
$malformed_outcome = (string) $publish->invoke( null, $malformed_lock, $now );
aculect_workflow_options_assert( 1 === preg_match( '/^failure:[a-f0-9]{32}:\d+$/D', $malformed_outcome ), 'Malformed retry publication did not retain an exact failure outcome.' );
aculect_workflow_options_assert( (int) get_option( $option, 0 ) >= $retry_after - 1, 'Malformed retry publication did not persist a durable backoff.' );
delete_option( $lock_option );
delete_option( $option );

// A concurrent replacement must win the CAS cleanup and remain the durable
// backoff; the cleanup cannot delete or overwrite an active replacement.
$concurrent_expired = (string) ( $now - 2 );
$concurrent_retry   = $now + 240;
add_option( $option, $concurrent_expired, '', false );
aculect_workflow_options_assert( hash_equals( $concurrent_expired, (string) get_option( $option, '' ) ), 'Could not warm the concurrent retry option.' );
update_option( $option, $concurrent_retry, false );
aculect_workflow_options_assert( false === $delete_retry->invoke( null, $concurrent_expired ), 'CAS cleanup deleted a concurrently replaced retry option.' );
aculect_workflow_options_assert( hash_equals( (string) $concurrent_retry, (string) get_option( $option, 0 ) ), 'Concurrent replacement was not preserved.' );
delete_option( $option );

echo 'PASS WordPress Options API workflow repair lease proof' . PHP_EOL;
