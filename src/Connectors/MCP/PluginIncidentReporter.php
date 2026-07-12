<?php
/**
 * Create bounded incident reports from MCP clients.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Stores local plugin incident reports and prepares public GitHub drafts without secrets.
 */
final class PluginIncidentReporter {

	private const DEFAULT_REPOSITORY = 'mehul0810/aculect-ai-companion';
	private const OPTION_REPORTS     = 'aculect_ai_companion_incident_reports';
	private const MAX_REPORTS        = 100;
	private const MAX_TITLE_LENGTH   = 120;
	private const MAX_FIELD_LENGTH   = 2000;
	private const MAX_REPORT_BODY    = 12000;
	private const MAX_BODY_LENGTH    = 60000;

	private const CATEGORIES = array(
		'bug',
		'compatibility',
		'workflow_gap',
		'documentation',
		'configuration',
		'client_behavior',
		'usability',
		'enhancement',
	);

	private const SEVERITIES = array(
		'low',
		'medium',
		'high',
		'blocking',
	);

	/**
	 * Create the reporter.
	 *
	 * @param string|null $repository Optional owner/repo override for tests.
	 */
	public function __construct(
		private readonly ?string $repository = null
	) {}

	/**
	 * Store an incident report and prepare a GitHub issue from an MCP client report.
	 *
	 * @param array<string, mixed> $args   Tool arguments.
	 * @param array<string, mixed> $source Authenticated MCP connection context.
	 * @return array<string, mixed>
	 */
	public function report( array $args, array $source = array() ): array {
		$title   = $this->sanitize_single_line( $args['title'] ?? '', self::MAX_TITLE_LENGTH );
		$summary = $this->sanitize_multiline( $args['summary'] ?? $args['issue'] ?? '', self::MAX_FIELD_LENGTH );

		if ( '' === $title && '' !== $summary ) {
			$title = $this->sanitize_single_line( $summary, self::MAX_TITLE_LENGTH );
		}

		if ( '' === $title || '' === $summary ) {
			return array(
				'status'  => 'rejected',
				'error'   => 'title_and_summary_required',
				'message' => 'Plugin incident reports require a title and summary.',
			);
		}

		$repository = $this->repository();
		$report     = $this->incident_report( $args, $source, $title, $summary, $repository );
		$this->save_report( $report );

		return array(
			'status'            => '' === $repository ? 'stored_configuration_required' : 'stored_ready_for_client_submission',
			'message'           => '' === $repository
				? 'Plugin incident report stored locally, but the configured GitHub repository is invalid. Expected owner/repository.'
				: 'Plugin incident report stored locally and public GitHub issue draft prepared. Create the issue in the public GitHub repository if the client has GitHub or browser tools available, or share the prefilled issue URL with the user.',
			'report_id'         => $report['id'],
			'correlation_id'    => $report['correlation_id'],
			'repository'        => $repository,
			'title'             => $title,
			'body'              => $report['github']['body'],
			'issue_url'         => $report['github']['issue_url'],
			'can_create_direct' => false,
			'incident'          => $this->public_report( $report, true ),
			'next_actions'      => array(
				'If this assistant has a GitHub connector or GitHub CLI access, create a new issue in the returned repository using the returned title and body.',
				'If no GitHub tool is available, give the user the prefilled issue_url.',
				'Use the returned report_id for follow-up in the local incident report queue.',
				'Do not add secrets, credentials, OAuth tokens, raw tool arguments, private content, or private site settings to the public issue.',
			),
		);
	}

	/**
	 * List locally stored incident reports.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_reports( array $args = array() ): array {
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 50, absint( $args['per_page'] ?? 20 ) ) );
		$items    = $this->reports();
		$status   = sanitize_key( (string) ( $args['status'] ?? '' ) );

		if ( '' !== $status ) {
			$items = array_values(
				array_filter(
					$items,
					static fn ( array $item ): bool => (string) ( $item['status'] ?? '' ) === $status
				)
			);
		}

		usort(
			$items,
			static fn ( array $a, array $b ): int => strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) )
		);

		$total = count( $items );
		$items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );

		return array(
			'items'    => array_map( fn ( array $report ): array => $this->public_report( $report, false ), $items ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'summary'  => $this->summary( $this->reports() ),
		);
	}

	/**
	 * Return one locally stored incident report.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_report( array $args ): array {
		$id = $this->sanitize_identifier( $args['report_id'] ?? $args['id'] ?? '', 80 );
		if ( '' === $id ) {
			return array(
				'status'  => 'rejected',
				'error'   => 'report_id_required',
				'message' => 'Provide a report_id.',
			);
		}

		foreach ( $this->reports() as $report ) {
			if ( hash_equals( (string) ( $report['id'] ?? '' ), $id ) ) {
				return array(
					'status' => 'found',
					'report' => $this->public_report( $report, true ),
				);
			}
		}

		return array(
			'status'  => 'not_found',
			'error'   => 'incident_report_not_found',
			'message' => 'No incident report exists for that report_id.',
		);
	}

	/**
	 * Return incident report data for the admin app.
	 *
	 * @return array<string, mixed>
	 */
	public function admin_payload(): array {
		return $this->list_reports( array( 'per_page' => 20 ) );
	}

	/**
	 * Return an empty admin payload shape.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_payload(): array {
		return array(
			'items'    => array(),
			'total'    => 0,
			'page'     => 1,
			'per_page' => 20,
			'summary'  => array(
				'total'         => 0,
				'open'          => 0,
				'blocking'      => 0,
				'compatibility' => 0,
				'workflow_gap'  => 0,
			),
		);
	}

	/**
	 * Delete stored incident reports during full plugin cleanup.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_REPORTS );
	}

	/**
	 * Return the configured GitHub repository.
	 */
	private function repository(): string {
		$repository = null !== $this->repository ? $this->repository : self::DEFAULT_REPOSITORY;
		$repository = (string) apply_filters( 'aculect_ai_companion_github_incident_reporter_repository', $repository );
		$repository = trim( $repository );

		return preg_match( '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository ) ? $repository : '';
	}

	/**
	 * Build a stored incident report.
	 *
	 * @param array<string, mixed> $args       Tool arguments.
	 * @param array<string, mixed> $source     Authenticated MCP connection context.
	 * @param string               $title      Public issue title.
	 * @param string               $summary    Public issue summary.
	 * @param string               $repository Public GitHub repository, if valid.
	 * @return array<string, mixed>
	 */
	private function incident_report( array $args, array $source, string $title, string $summary, string $repository ): array {
		$now            = gmdate( 'Y-m-d\TH:i:s\Z' );
		$report_id      = $this->generate_id();
		$correlation_id = $this->sanitize_identifier( $args['correlation_id'] ?? '', 100 );
		$category       = $this->category( $args );
		$severity       = $this->severity( $args );
		$body           = $this->issue_body( $args, $source, $summary, $category, $severity, '' === $correlation_id ? $report_id : $correlation_id );

		return array(
			'id'                    => $report_id,
			'correlation_id'        => '' === $correlation_id ? $report_id : $correlation_id,
			'title'                 => $title,
			'summary'               => $summary,
			'category'              => $category,
			'severity'              => $severity,
			'status'                => 'open',
			'created_at'            => $now,
			'updated_at'            => $now,
			'source'                => $this->sanitize_source( $source, $args ),
			'workflow'              => $this->sanitize_single_line( $args['workflow'] ?? '', 120 ),
			'tool_name'             => $this->sanitize_single_line( $args['tool_name'] ?? '', 120 ),
			'activity_id'           => absint( $args['activity_id'] ?? 0 ),
			'steps_to_reproduce'    => $this->string_list( $args['steps_to_reproduce'] ?? array(), 8 ),
			'recovery_attempts'     => $this->string_list( $args['recovery_attempts'] ?? array(), 8 ),
			'expected_behavior'     => $this->sanitize_multiline( $args['expected_behavior'] ?? '', self::MAX_FIELD_LENGTH ),
			'actual_behavior'       => $this->sanitize_multiline( $args['actual_behavior'] ?? '', self::MAX_FIELD_LENGTH ),
			'evidence'              => $this->sanitize_multiline( $args['evidence'] ?? '', self::MAX_FIELD_LENGTH ),
			'non_sensitive_context' => $this->safe_context( $args, $source ),
			'github'                => array(
				'repository'        => $repository,
				'title'             => $title,
				'body'              => substr( $body, 0, self::MAX_REPORT_BODY ),
				'issue_url'         => '' === $repository ? '' : $this->manual_issue_url( $repository, $title, $body ),
				'can_create_direct' => false,
			),
			'safety'                => array(
				'secrets_included'       => false,
				'raw_arguments_included' => false,
				'raw_content_included'   => false,
				'public_issue_ready'     => '' !== $repository,
			),
		);
	}

	/**
	 * Build a public, redacted GitHub issue body.
	 *
	 * @param array<string, mixed> $args    Tool arguments.
	 * @param array<string, mixed> $source  Authenticated MCP connection context.
	 * @param string               $summary Sanitized summary.
	 * @param string               $category Sanitized incident category.
	 * @param string               $severity Sanitized incident severity.
	 * @param string               $correlation_id Sanitized incident correlation ID.
	 */
	private function issue_body( array $args, array $source, string $summary, string $category, string $severity, string $correlation_id ): string {
		$sections = array(
			'## Summary' . "\n" . $summary,
			'## Context' . "\n" . $this->context_markdown( $args, $source, $category, $severity, $correlation_id ),
		);

		$steps = $this->string_list( $args['steps_to_reproduce'] ?? array(), 8 );
		if ( array() !== $steps ) {
			$sections[] = '## Steps to reproduce' . "\n" . implode(
				"\n",
				array_map(
					static fn( int $index, string $step ): string => ( $index + 1 ) . '. ' . $step,
					array_keys( $steps ),
					$steps
				)
			);
		}

		$recovery = $this->string_list( $args['recovery_attempts'] ?? array(), 8 );
		if ( array() !== $recovery ) {
			$sections[] = '## Recovery attempted' . "\n" . implode(
				"\n",
				array_map(
					static fn( int $index, string $step ): string => ( $index + 1 ) . '. ' . $step,
					array_keys( $recovery ),
					$recovery
				)
			);
		}

		foreach (
			array(
				'expected_behavior' => 'Expected behavior',
				'actual_behavior'   => 'Actual behavior',
				'evidence'          => 'Non-sensitive evidence',
			) as $key => $label
		) {
			$value = $this->sanitize_multiline( $args[ $key ] ?? '', self::MAX_FIELD_LENGTH );
			if ( '' !== $value ) {
				$sections[] = '## ' . $label . "\n" . $value;
			}
		}

		$sections[] = '## Safety' . "\n" . 'Generated by the Aculect AI Companion MCP incident reporter. The report intentionally excludes secrets, credentials, raw OAuth tokens, raw tool arguments, private content, and full private site settings.';

		return substr( implode( "\n\n", $sections ), 0, self::MAX_BODY_LENGTH );
	}

	/**
	 * Build safe context markdown.
	 *
	 * @param array<string, mixed> $args   Tool arguments.
	 * @param array<string, mixed> $source Authenticated MCP connection context.
	 * @param string               $category Sanitized incident category.
	 * @param string               $severity Sanitized incident severity.
	 * @param string               $correlation_id Sanitized incident correlation ID.
	 */
	private function context_markdown( array $args, array $source, string $category, string $severity, string $correlation_id ): string {
		$home_host = '';
		if ( function_exists( 'home_url' ) ) {
			$home_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		}

		$lines = array(
			'- Correlation ID: ' . $this->sanitize_single_line( $correlation_id, 100 ),
			'- Client: ' . $this->sanitize_single_line( $args['client_name'] ?? $source['client_name'] ?? $source['provider'] ?? 'MCP client', 100 ),
			'- Provider: ' . $this->sanitize_single_line( $source['provider'] ?? $args['provider'] ?? '', 80 ),
			'- Workflow: ' . $this->sanitize_single_line( $args['workflow'] ?? '', 120 ),
			'- Tool: ' . $this->sanitize_single_line( $args['tool_name'] ?? '', 120 ),
			'- Category: ' . $category,
			'- Severity: ' . $severity,
			'- Activity ID: ' . absint( $args['activity_id'] ?? 0 ),
			'- Site host: ' . $this->sanitize_single_line( $home_host, 120 ),
			'- Plugin version: ' . ACULECT_AI_COMPANION_VERSION,
			'- WordPress version: ' . ( function_exists( 'get_bloginfo' ) ? $this->sanitize_single_line( get_bloginfo( 'version' ), 30 ) : '' ),
			'- PHP version: ' . PHP_VERSION,
			'- Environment: ' . ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( wp_get_environment_type() ) : 'production' ),
		);

		return implode( "\n", array_filter( $lines, static fn( string $line ): bool => ! str_ends_with( $line, ': ' ) ) );
	}

	/**
	 * Return safe context data for local incident records.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @param array<string, mixed> $source Authenticated source.
	 * @return array<string, mixed>
	 */
	private function safe_context( array $args, array $source ): array {
		return array(
			'argument_keys' => array_values(
				array_filter(
					array_map(
						static fn ( string $key ): string => sanitize_key( $key ),
						array_keys( $args )
					)
				)
			),
			'provider'      => $this->sanitize_single_line( $source['provider'] ?? $args['provider'] ?? '', 80 ),
			'client_id'     => $this->sanitize_single_line( $source['client_id'] ?? '', 100 ),
			'user_id'       => absint( $source['user_id'] ?? 0 ),
			'scopes'        => $this->string_list( $args['oauth_scopes'] ?? array(), 12 ),
		);
	}

	/**
	 * Return a public report shape.
	 *
	 * @param array<string, mixed> $report Stored report.
	 * @param bool                 $include_body Whether to include the GitHub body.
	 * @return array<string, mixed>
	 */
	private function public_report( array $report, bool $include_body ): array {
		$github = is_array( $report['github'] ?? null ) ? $report['github'] : array();
		$public = array(
			'id'                 => (string) ( $report['id'] ?? '' ),
			'correlation_id'     => (string) ( $report['correlation_id'] ?? '' ),
			'title'              => (string) ( $report['title'] ?? '' ),
			'summary'            => (string) ( $report['summary'] ?? '' ),
			'category'           => (string) ( $report['category'] ?? 'bug' ),
			'severity'           => (string) ( $report['severity'] ?? 'medium' ),
			'status'             => (string) ( $report['status'] ?? 'open' ),
			'created_at'         => (string) ( $report['created_at'] ?? '' ),
			'updated_at'         => (string) ( $report['updated_at'] ?? '' ),
			'source'             => is_array( $report['source'] ?? null ) ? $report['source'] : array(),
			'workflow'           => (string) ( $report['workflow'] ?? '' ),
			'tool_name'          => (string) ( $report['tool_name'] ?? '' ),
			'activity_id'        => (int) ( $report['activity_id'] ?? 0 ),
			'steps_to_reproduce' => (array) ( $report['steps_to_reproduce'] ?? array() ),
			'recovery_attempts'  => (array) ( $report['recovery_attempts'] ?? array() ),
			'expected_behavior'  => (string) ( $report['expected_behavior'] ?? '' ),
			'actual_behavior'    => (string) ( $report['actual_behavior'] ?? '' ),
			'evidence'           => (string) ( $report['evidence'] ?? '' ),
			'github'             => array(
				'repository'        => (string) ( $github['repository'] ?? '' ),
				'title'             => (string) ( $github['title'] ?? $report['title'] ?? '' ),
				'issue_url'         => (string) ( $github['issue_url'] ?? '' ),
				'can_create_direct' => false,
			),
			'safety'             => is_array( $report['safety'] ?? null ) ? $report['safety'] : array(),
		);

		if ( $include_body ) {
			$public['github']['body'] = (string) ( $github['body'] ?? '' );
		}

		return $public;
	}

	/**
	 * Return report summary counts.
	 *
	 * @param list<array<string, mixed>> $reports Stored reports.
	 * @return array<string, int>
	 */
	private function summary( array $reports ): array {
		$summary = self::empty_payload()['summary'];
		foreach ( $reports as $report ) {
			++$summary['total'];
			if ( 'open' === (string) ( $report['status'] ?? '' ) ) {
				++$summary['open'];
			}
			if ( 'blocking' === (string) ( $report['severity'] ?? '' ) ) {
				++$summary['blocking'];
			}
			if ( 'compatibility' === (string) ( $report['category'] ?? '' ) ) {
				++$summary['compatibility'];
			}
			if ( 'workflow_gap' === (string) ( $report['category'] ?? '' ) ) {
				++$summary['workflow_gap'];
			}
		}

		return $summary;
	}

	/**
	 * Return stored reports.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function reports(): array {
		$stored = get_option( self::OPTION_REPORTS, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$reports = array();
		foreach ( $stored as $report ) {
			if ( is_array( $report ) ) {
				$normalized = $this->normalize_report( $report );
				if ( array() !== $normalized ) {
					$reports[] = $normalized;
				}
			}
		}

		return $reports;
	}

	/**
	 * Persist a bounded report list.
	 *
	 * @param array<string, mixed> $report New report.
	 */
	private function save_report( array $report ): void {
		$reports   = $this->reports();
		$reports[] = $this->normalize_report( $report );
		$reports   = array_values( array_slice( $reports, -self::MAX_REPORTS ) );
		update_option( self::OPTION_REPORTS, $reports, false );
	}

	/**
	 * Normalize a stored report.
	 *
	 * @param array<string, mixed> $report Stored report.
	 * @return array<string, mixed>
	 */
	private function normalize_report( array $report ): array {
		$id = $this->sanitize_identifier( $report['id'] ?? '', 80 );
		if ( '' === $id ) {
			return array();
		}

		$github = is_array( $report['github'] ?? null ) ? $report['github'] : array();

		return array(
			'id'                    => $id,
			'correlation_id'        => $this->sanitize_identifier( $report['correlation_id'] ?? $id, 100 ),
			'title'                 => $this->sanitize_single_line( $report['title'] ?? '', self::MAX_TITLE_LENGTH ),
			'summary'               => $this->sanitize_multiline( $report['summary'] ?? '', self::MAX_FIELD_LENGTH ),
			'category'              => $this->sanitize_enum( $report['category'] ?? '', self::CATEGORIES, 'bug' ),
			'severity'              => $this->sanitize_enum( $report['severity'] ?? '', self::SEVERITIES, 'medium' ),
			'status'                => $this->sanitize_enum( $report['status'] ?? '', array( 'open', 'dismissed', 'submitted' ), 'open' ),
			'created_at'            => $this->sanitize_single_line( $report['created_at'] ?? '', 40 ),
			'updated_at'            => $this->sanitize_single_line( $report['updated_at'] ?? '', 40 ),
			'source'                => is_array( $report['source'] ?? null ) ? $report['source'] : array(),
			'workflow'              => $this->sanitize_single_line( $report['workflow'] ?? '', 120 ),
			'tool_name'             => $this->sanitize_single_line( $report['tool_name'] ?? '', 120 ),
			'activity_id'           => absint( $report['activity_id'] ?? 0 ),
			'steps_to_reproduce'    => $this->string_list( $report['steps_to_reproduce'] ?? array(), 8 ),
			'recovery_attempts'     => $this->string_list( $report['recovery_attempts'] ?? array(), 8 ),
			'expected_behavior'     => $this->sanitize_multiline( $report['expected_behavior'] ?? '', self::MAX_FIELD_LENGTH ),
			'actual_behavior'       => $this->sanitize_multiline( $report['actual_behavior'] ?? '', self::MAX_FIELD_LENGTH ),
			'evidence'              => $this->sanitize_multiline( $report['evidence'] ?? '', self::MAX_FIELD_LENGTH ),
			'non_sensitive_context' => is_array( $report['non_sensitive_context'] ?? null ) ? $report['non_sensitive_context'] : array(),
			'github'                => array(
				'repository'        => $this->sanitize_single_line( $github['repository'] ?? '', 120 ),
				'title'             => $this->sanitize_single_line( $github['title'] ?? $report['title'] ?? '', self::MAX_TITLE_LENGTH ),
				'body'              => $this->sanitize_multiline( $github['body'] ?? '', self::MAX_REPORT_BODY ),
				'issue_url'         => isset( $github['issue_url'] ) && is_scalar( $github['issue_url'] ) ? esc_url_raw( (string) $github['issue_url'] ) : '',
				'can_create_direct' => false,
			),
			'safety'                => is_array( $report['safety'] ?? null ) ? $report['safety'] : array(),
		);
	}

	/**
	 * Return a manual issue URL when API creation is not configured.
	 *
	 * @param string $repository Repository in owner/name format.
	 * @param string $title      Public issue title.
	 * @param string $body       Public issue body.
	 */
	private function manual_issue_url( string $repository, string $title, string $body ): string {
		return esc_url_raw(
			add_query_arg(
				array(
					'title' => $title,
					'body'  => substr( $body, 0, 4000 ),
				),
				'https://github.com/' . $repository . '/issues/new'
			)
		);
	}

	/**
	 * Return a sanitized category.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function category( array $args ): string {
		$category = sanitize_key( (string) ( $args['category'] ?? $args['severity'] ?? 'bug' ) );

		return $this->sanitize_enum( $category, self::CATEGORIES, 'bug' );
	}

	/**
	 * Return a sanitized severity.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function severity( array $args ): string {
		$severity = sanitize_key( (string) ( $args['impact'] ?? $args['severity_level'] ?? $args['severity'] ?? 'medium' ) );

		return $this->sanitize_enum( $severity, self::SEVERITIES, 'medium' );
	}

	/**
	 * Sanitize source metadata.
	 *
	 * @param array<string, mixed> $source Authenticated source.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function sanitize_source( array $source, array $args ): array {
		return array(
			'provider'    => $this->sanitize_single_line( $source['provider'] ?? $args['provider'] ?? 'mcp', 80 ),
			'client_id'   => $this->sanitize_single_line( $source['client_id'] ?? '', 100 ),
			'client_name' => $this->sanitize_single_line( $source['client_name'] ?? $args['client_name'] ?? '', 120 ),
			'user_id'     => absint( $source['user_id'] ?? 0 ),
		);
	}

	/**
	 * Generate a report ID.
	 */
	private function generate_id(): string {
		$suffix = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 8 ) );

		return 'air_' . substr( preg_replace( '/[^a-zA-Z0-9]/', '', $suffix ) ?? '', 0, 24 );
	}

	/**
	 * Sanitize a simple identifier.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max_length Maximum length.
	 */
	private function sanitize_identifier( mixed $value, int $max_length ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = preg_replace( '/[^a-zA-Z0-9_.:-]/', '', $value ) ?? '';

		return substr( $value, 0, $max_length );
	}

	/**
	 * Sanitize a single-line field.
	 *
	 * @param mixed $value      Raw field value.
	 * @param int   $max_length Maximum returned length.
	 */
	private function sanitize_single_line( mixed $value, int $max_length ): string {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return substr( $value, 0, $max_length );
	}

	/**
	 * Sanitize a multi-line field.
	 *
	 * @param mixed $value      Raw field value.
	 * @param int   $max_length Maximum returned length.
	 */
	private function sanitize_multiline( mixed $value, int $max_length ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;
		$value = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

		return substr( $value, 0, $max_length );
	}

	/**
	 * Sanitize an enum value.
	 *
	 * @param mixed    $value Raw value.
	 * @param string[] $allowed Allowed values.
	 * @param string   $default Default value.
	 */
	private function sanitize_enum( mixed $value, array $allowed, string $default ): string {
		$value = sanitize_key( is_scalar( $value ) ? (string) $value : '' );

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Sanitize a bounded string list.
	 *
	 * @param mixed $value Raw list.
	 * @param int   $limit Maximum number of returned items.
	 * @return list<string>
	 */
	private function string_list( mixed $value, int $limit ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			$item = $this->sanitize_multiline( $item, 500 );
			if ( '' !== $item ) {
				$items[] = $item;
			}

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}
}
