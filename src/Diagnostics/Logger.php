<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

use WP_REST_Request;

/**
 * Writes opt-in diagnostic events for AI connection flows.
 */
final class Logger {

	private const OPTION_LAST_PRUNED_AT = 'aculect_ai_companion_log_last_pruned_at';
	private const PRUNE_INTERVAL        = 3600;

	private LogSinkInterface $sink;
	private LogSanitizer $sanitizer;

	public function __construct( ?LogSinkInterface $sink = null, ?LogSanitizer $sanitizer = null ) {
		$this->sink      = $sink ?? new LogRepository();
		$this->sanitizer = $sanitizer ?? new LogSanitizer();
	}

	/**
	 * Write one diagnostic event if logging is enabled.
	 *
	 * @param string               $event   Event name.
	 * @param string               $level   Log level.
	 * @param string               $message Human-readable diagnostic summary.
	 * @param array<string, mixed> $context Sanitized-by-default event context.
	 * @param WP_REST_Request|null $request Optional REST request.
	 * @param int|null             $status  Optional HTTP status.
	 */
	public function log( string $event, string $level = 'info', string $message = '', array $context = array(), ?WP_REST_Request $request = null, ?int $status = null ): bool {
		if ( ! LogSettings::is_enabled() ) {
			return false;
		}

		$context = $this->sanitizer->sanitize_context( $context );
		$entry   = array_merge(
			$this->request_metadata( $request ),
			array(
				'event'       => $event,
				'level'       => $level,
				'provider'    => $context['provider'] ?? null,
				'http_status' => $status,
				'error_code'  => $context['error_code'] ?? null,
				'message'     => $message,
				'context'     => $context,
			)
		);

		$inserted = $this->sink->insert( $entry );
		if ( $inserted ) {
			$this->maybe_prune();
		}

		return $inserted;
	}

	/**
	 * Write an informational diagnostic event.
	 *
	 * @param string               $event   Event name.
	 * @param string               $message Human-readable diagnostic summary.
	 * @param array<string, mixed> $context Event context.
	 * @param WP_REST_Request|null $request Optional REST request.
	 * @param int|null             $status  Optional HTTP status.
	 */
	public function info( string $event, string $message = '', array $context = array(), ?WP_REST_Request $request = null, ?int $status = null ): bool {
		return $this->log( $event, 'info', $message, $context, $request, $status );
	}

	/**
	 * Write a warning diagnostic event.
	 *
	 * @param string               $event   Event name.
	 * @param string               $message Human-readable diagnostic summary.
	 * @param array<string, mixed> $context Event context.
	 * @param WP_REST_Request|null $request Optional REST request.
	 * @param int|null             $status  Optional HTTP status.
	 */
	public function warning( string $event, string $message = '', array $context = array(), ?WP_REST_Request $request = null, ?int $status = null ): bool {
		return $this->log( $event, 'warning', $message, $context, $request, $status );
	}

	/**
	 * Write an error diagnostic event.
	 *
	 * @param string               $event   Event name.
	 * @param string               $message Human-readable diagnostic summary.
	 * @param array<string, mixed> $context Event context.
	 * @param WP_REST_Request|null $request Optional REST request.
	 * @param int|null             $status  Optional HTTP status.
	 */
	public function error( string $event, string $message = '', array $context = array(), ?WP_REST_Request $request = null, ?int $status = null ): bool {
		return $this->log( $event, 'error', $message, $context, $request, $status );
	}

	/**
	 * Build safe request metadata.
	 *
	 * @param WP_REST_Request|null $request Optional REST request.
	 * @return array<string, string|null>
	 */
	private function request_metadata( ?WP_REST_Request $request ): array {
		if ( $request instanceof WP_REST_Request ) {
			return array(
				'request_method' => $request->get_method(),
				'request_route'  => $request->get_route(),
			);
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$route  = (string) wp_parse_url( $uri, PHP_URL_PATH );

		return array(
			'request_method' => '' === $method ? null : $method,
			'request_route'  => '' === $route ? null : $route,
		);
	}

	/**
	 * Prune logs at most hourly during active logging.
	 */
	private function maybe_prune(): void {
		$last_pruned_at = absint( get_option( self::OPTION_LAST_PRUNED_AT, 0 ) );
		if ( time() - $last_pruned_at < self::PRUNE_INTERVAL ) {
			return;
		}

		$this->sink->prune( LogSettings::retention_days() );
		update_option( self::OPTION_LAST_PRUNED_AT, time(), false );
	}
}
