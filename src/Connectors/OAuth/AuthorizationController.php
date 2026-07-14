<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Exception;
use Aculect\AICompanion\Activity\ActivityLogger;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Connectors\MCP\RoleConnectionEntryPoint;
use Aculect\AICompanion\Connectors\MCP\UserAccessControl;
use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use Aculect\AICompanion\Connectors\OAuth\Entities\UserEntity;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Connectors\OAuth\Server\AuthorizationServerFactory;
use Aculect\AICompanion\Diagnostics\Logger;
use Aculect\AICompanion\Diagnostics\LogSanitizer;
use League\OAuth2\Server\Exception\OAuthServerException;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Handles the redirect-based OAuth authorization and consent flow.
 */
final class AuthorizationController {

	private const NONCE_ACTION          = 'aculect_ai_companion_oauth_authorize';
	private const CONSENT_REQUEST_PARAM = 'request_token';
	private const CONSENT_REQUEST_TTL   = 600;
	private const OAUTH_PARAMS          = array(
		'response_type',
		'client_id',
		'redirect_uri',
		'scope',
		'state',
		'code_challenge',
		'code_challenge_method',
		'resource',
	);

	/**
	 * Register the authorization endpoint.
	 */
	public function register_routes(): void {
		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/oauth/authorize',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'authorize' ),
					'permission_callback' => array( $this, 'check_authorize_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'authorize' ),
					'permission_callback' => array( $this, 'check_authorize_permission' ),
				),
			)
		);
	}

	/**
	 * Permit public access to the OAuth authorization endpoint.
	 *
	 * RFC 6749 authorization endpoints are entered by browser redirect before
	 * authentication; the user authenticates through the WordPress login and
	 * consent screens this controller redirects to. Request parameters are
	 * allowlisted and validated by the authorization server before any action.
	 *
	 * @return true
	 */
	public function check_authorize_permission(): bool {
		return true;
	}

	/**
	 * Validate an authorization request and redirect to WordPress consent.
	 *
	 * @param WP_REST_Request $request Authorization request.
	 */
	public function authorize( WP_REST_Request $request ): void {
		$params = $this->params( $request );
		$this->authorize_with_params( $params, $request );
	}

	/**
	 * Validate a root-route authorization request and redirect to WordPress consent.
	 *
	 * WordPress REST cookie authentication requires a REST nonce, so browser
	 * OAuth redirects use the root route to preserve normal logged-in cookies.
	 *
	 * @param array<string, mixed> $query_params Raw query parameters.
	 */
	public function authorize_from_query_params( array $query_params ): void {
		$params = $this->params_from_array( $query_params, true );
		$this->authorize_with_params( $params );
	}

	/**
	 * Validate sanitized authorization params and redirect to WordPress consent.
	 *
	 * @param array<string, string> $params  Sanitized authorization parameters.
	 * @param WP_REST_Request|null  $request Optional REST request for logging.
	 */
	private function authorize_with_params( array $params, ?WP_REST_Request $request = null ): void {
		( new Logger() )->info(
			'authorize.received',
			'OAuth authorization request received.',
			$this->log_context( $params ),
			$request
		);

		$context     = $this->authorization_context( $params, false, $request );
		$consent_url = $this->admin_consent_url(
			$this->store_consent_request( $context['params'] )
		);
		if ( ! is_user_logged_in() ) {
			( new Logger() )->info(
				'authorize.login_redirect',
				'OAuth authorization request redirected to WordPress login.',
				$this->log_context( $params, $context['client'] ),
				$request,
				302
			);
			wp_safe_redirect( wp_login_url( $consent_url ), 302, 'Aculect AI Companion OAuth' );
			exit;
		}

		( new Logger() )->info(
			'authorize.consent_redirect',
			'OAuth authorization request redirected to consent.',
			$this->log_context( $params, $context['client'] ),
			$request,
			302
		);
		wp_safe_redirect( $consent_url, 302, 'Aculect AI Companion OAuth' );
		exit;
	}

	/**
	 * Render the admin-hosted OAuth consent screen.
	 */
	public function render_admin_consent(): void {
		$context = $this->consent_request_context( $this->query_request_token(), true );

		$this->render_consent_markup( $context['request_token'], $context['params'], $context['client'], $context['resource'] );
	}

	/**
	 * Process an approve or deny decision from the consent screen.
	 */
	public function handle_admin_consent(): void {
		$request_token = $this->posted_request_token();

		if ( ! is_user_logged_in() ) {
			( new Logger() )->info(
				'authorize.login_redirect',
				'OAuth consent submission required WordPress login.',
				array(),
				null,
				302
			);
			wp_safe_redirect( wp_login_url( $this->admin_consent_url( $request_token ) ), 302, 'Aculect AI Companion OAuth' );
			exit;
		}

		$nonce = $this->posted_nonce();
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$params = $this->params_for_request_token( $request_token );
			( new Logger() )->warning(
				'consent.invalid_nonce',
				'OAuth consent submission failed nonce validation.',
				$this->log_context( $params, null, 'invalid_nonce' ),
				null,
				400
			);
			$this->render_error( 'Invalid request', 'The authorization request failed a security check.', 400 );
		}

		$context  = $this->consent_request_context( $request_token, false );
		$decision = $this->posted_decision();
		if ( ! in_array( $decision, array( 'approve', 'deny' ), true ) ) {
			( new Logger() )->warning(
				'consent.invalid_decision',
				'OAuth consent submission included an invalid decision.',
				$this->log_context( $context['params'], $context['client'], 'invalid_decision' ),
				null,
				400
			);
			$this->render_error( 'Invalid request', 'The authorization decision was not valid.', 400 );
		}

		$this->handle_decision( $request_token, $context['params'], $context['client'], $context['resource'], $decision );
	}

	/**
	 * Complete or deny the authorization request and redirect back to the client.
	 *
	 * @param string                $request_token Stored consent request token.
	 * @param array<string, string> $params        Validated authorization parameters.
	 * @param ClientEntity          $client        Registered OAuth client.
	 * @param string                $resource      MCP resource URL.
	 * @param string                $decision      User decision.
	 */
	private function handle_decision( string $request_token, array $params, ClientEntity $client, string $resource, string $decision ): never {
		$redirect_uri = esc_url_raw( (string) ( $params['redirect_uri'] ?? '' ) );
		$state        = sanitize_text_field( (string) ( $params['state'] ?? '' ) );

		if ( 'approve' !== $decision ) {
			$this->delete_consent_request( $request_token );
			$this->record_timeline_event(
				'oauth_consent_approval',
				array(
					'status'     => 'blocked',
					'blocked_by' => 'access_denied',
					'error_code' => 'access_denied',
					'method'     => 'oauth/authorize',
				),
				$this->timeline_auth_context( $params, $client )
			);
			( new Logger() )->info(
				'consent.denied',
				'OAuth consent request was denied.',
				$this->log_context( $params, $client, 'access_denied' ),
				null,
				302
			);
			$this->redirect_to_client(
				$redirect_uri,
				array(
					'error'             => 'access_denied',
					'error_description' => 'The user denied the authorization request.',
					'state'             => $state,
				)
			);
		}

		try {
			RequestContext::set_resource( $resource );
			$query = array(
				'response_type'         => 'code',
				'client_id'             => $client->getIdentifier(),
				'redirect_uri'          => $redirect_uri,
				'scope'                 => $this->scope_from_params( $params ),
				'state'                 => $state,
				'code_challenge'        => sanitize_text_field( (string) ( $params['code_challenge'] ?? '' ) ),
				'code_challenge_method' => 'S256',
			);

			$auth_request = AuthorizationServerFactory::create()->validateAuthorizationRequest(
				Psr7Bridge::server_request( 'GET', Helpers::authorization_endpoint(), $query )
			);
			$auth_request->setUser( new UserEntity( get_current_user_id() ) );
			$auth_request->setAuthorizationApproved( true );

			$response = AuthorizationServerFactory::create()->completeAuthorizationRequest(
				$auth_request,
				Psr7Bridge::response()
			);
			$location = $response->getHeaderLine( 'Location' );
			if ( '' === $location ) {
				( new Logger() )->error(
					'consent.failed',
					'OAuth consent approval failed without a redirect location.',
					$this->log_context( $params, $client, 'missing_redirect_location' ),
					null,
					500
				);
				$this->render_error( 'Connection approval failed', 'Aculect AI Companion could not complete the approval request.', 500 );
			}

			$this->delete_consent_request( $request_token );
			( new Logger() )->info(
				'consent.approved',
				'OAuth consent request was approved.',
				$this->log_context( $params, $client ),
				null,
				302
			);
			$this->record_timeline_event(
				'oauth_consent_approval',
				array(
					'status'         => 'success',
					'method'         => 'oauth/authorize',
					'target_summary' => 'scope:' . str_replace( ' ', ',', $this->scope_from_params( $params ) ),
				),
				$this->timeline_auth_context( $params, $client )
			);
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth redirect URI is validated against the registered client before redirecting.
			wp_redirect( $location, 302, 'Aculect AI Companion OAuth' );
			exit;
		} catch ( OAuthServerException $exception ) {
			$this->record_timeline_event(
				'error',
				array(
					'status'     => 'error',
					'method'     => 'oauth/authorize',
					'error_code' => $exception->getErrorType(),
				),
				$this->timeline_auth_context( $params, $client )
			);
			( new Logger() )->warning(
				'consent.oauth_error',
				'OAuth server rejected the consent approval.',
				$this->log_context( $params, $client, $exception->getErrorType() ),
				null,
				$exception->getHttpStatusCode()
			);
			$this->redirect_to_client(
				$redirect_uri,
				array(
					'error'             => $exception->getErrorType(),
					'error_description' => $exception->getMessage(),
					'state'             => $state,
				)
			);
		} catch ( Exception $exception ) {
			unset( $exception );
			$this->record_timeline_event(
				'error',
				array(
					'status'     => 'error',
					'method'     => 'oauth/authorize',
					'error_code' => 'server_error',
				),
				$this->timeline_auth_context( $params, $client )
			);
			( new Logger() )->error(
				'consent.failed',
				'OAuth consent approval failed.',
				$this->log_context( $params, $client, 'server_error' ),
				null,
				500
			);
			$this->render_error( 'Authorization failed', $this->server_error_description(), 500 );
		} finally {
			RequestContext::reset();
		}
	}

	/**
	 * Return a generic server-error description safe for OAuth browser output.
	 */
	private function server_error_description(): string {
		return 'Aculect AI Companion could not complete the authorization request. Try reconnecting the client.';
	}

	/**
	 * Render the consent form details and hidden OAuth request parameters.
	 *
	 * @param string                $request_token Stored consent request token.
	 * @param array<string, string> $params        Authorization parameters.
	 * @param ClientEntity          $client        Registered OAuth client.
	 * @param string                $resource      MCP resource URL.
	 */
	private function render_consent_markup( string $request_token, array $params, ClientEntity $client, string $resource ): void {
		unset( $resource );

		$site_name = get_bloginfo( 'name' );
		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$scope     = $this->scope_from_params( $params );
		$actions   = $this->scope_summary( $scope );
		$assistant = $this->client_label( $client );

		nocache_headers();
		?>
<div class="aculect-ai-companion-oauth-page aculect-ai-companion-oauth-page--admin">
	<div class="aculect-ai-companion-oauth-card" role="main" aria-labelledby="aculect-ai-companion-oauth-title">
		<div class="aculect-ai-companion-oauth-brand">Aculect AI Companion</div>
			<div class="aculect-ai-companion-oauth-eyebrow"><?php echo esc_html__( 'Connection request', 'aculect-ai-companion' ); ?></div>
			<h1 id="aculect-ai-companion-oauth-title" class="aculect-ai-companion-oauth-title"><?php echo esc_html__( 'Review connection request', 'aculect-ai-companion' ); ?></h1>
			<p class="aculect-ai-companion-oauth-copy">
				<?php echo esc_html( $assistant ); ?> <?php echo esc_html__( 'is requesting permission to connect to your WordPress site through Aculect AI Companion.', 'aculect-ai-companion' ); ?>
			</p>
			<div class="aculect-ai-companion-oauth-panels">
				<section class="aculect-ai-companion-oauth-panel">
					<h2><?php echo esc_html__( 'Connection details', 'aculect-ai-companion' ); ?></h2>
					<dl class="aculect-ai-companion-oauth-details">
						<div class="aculect-ai-companion-oauth-detail"><dt><?php echo esc_html__( 'Assistant', 'aculect-ai-companion' ); ?></dt><dd><?php echo esc_html( $assistant ); ?></dd></div>
						<div class="aculect-ai-companion-oauth-detail"><dt><?php echo esc_html__( 'Account', 'aculect-ai-companion' ); ?></dt><dd><?php echo esc_html__( 'Detected during authorization', 'aculect-ai-companion' ); ?></dd></div>
						<div class="aculect-ai-companion-oauth-detail"><dt><?php echo esc_html__( 'Site', 'aculect-ai-companion' ); ?></dt><dd><?php echo esc_html( '' !== $site_host ? $site_host : $site_name ); ?></dd></div>
						<div class="aculect-ai-companion-oauth-detail"><dt><?php echo esc_html__( 'Connection method', 'aculect-ai-companion' ); ?></dt><dd><?php echo esc_html__( 'Secure OAuth', 'aculect-ai-companion' ); ?></dd></div>
						<div class="aculect-ai-companion-oauth-detail"><dt><?php echo esc_html__( 'Requested', 'aculect-ai-companion' ); ?></dt><dd><?php echo esc_html__( 'Just now', 'aculect-ai-companion' ); ?></dd></div>
						<div class="aculect-ai-companion-oauth-detail"><dt><?php echo esc_html__( 'Status', 'aculect-ai-companion' ); ?></dt><dd><span class="aculect-ai-companion-oauth-status"><?php echo esc_html__( 'Waiting for approval', 'aculect-ai-companion' ); ?></span></dd></div>
					</dl>
				</section>
				<section class="aculect-ai-companion-oauth-panel">
					<?php /* translators: %s: AI assistant name, for example ChatGPT. */ ?>
					<h2><?php echo esc_html( sprintf( __( 'What %s will be able to do', 'aculect-ai-companion' ), $assistant ) ); ?></h2>
					<p><?php echo esc_html__( 'After connection, the assistant can use approved Aculect abilities based on the WordPress user and site settings.', 'aculect-ai-companion' ); ?></p>
					<p class="aculect-ai-companion-oauth-scope"><?php echo esc_html( $actions ); ?></p>
					<ul class="aculect-ai-companion-oauth-capabilities">
						<li><strong><?php echo esc_html__( 'Manage content', 'aculect-ai-companion' ); ?></strong><span><?php echo esc_html__( 'Create and update posts, pages, and custom content.', 'aculect-ai-companion' ); ?></span></li>
						<li><strong><?php echo esc_html__( 'Manage media', 'aculect-ai-companion' ); ?></strong><span><?php echo esc_html__( 'Upload, organize, and manage media files.', 'aculect-ai-companion' ); ?></span></li>
						<li><strong><?php echo esc_html__( 'Moderate comments', 'aculect-ai-companion' ); ?></strong><span><?php echo esc_html__( 'Review, reply to, and manage comments.', 'aculect-ai-companion' ); ?></span></li>
						<li><strong><?php echo esc_html__( 'Use custom tools', 'aculect-ai-companion' ); ?></strong><span><?php echo esc_html__( 'Use site-specific tools exposed by Aculect.', 'aculect-ai-companion' ); ?></span></li>
					</ul>
				</section>
				<section class="aculect-ai-companion-oauth-panel">
					<h2><?php echo esc_html__( 'Security and privacy', 'aculect-ai-companion' ); ?></h2>
					<ul class="aculect-ai-companion-oauth-security-list">
						<li><strong><?php echo esc_html__( 'Secure OAuth authentication', 'aculect-ai-companion' ); ?></strong><span><?php echo esc_html__( 'The connection is authorized through WordPress.', 'aculect-ai-companion' ); ?></span></li>
						<li><strong><?php echo esc_html__( 'No passwords shared', 'aculect-ai-companion' ); ?></strong><span><?php echo esc_html__( 'Your WordPress credentials are never shared with the AI assistant.', 'aculect-ai-companion' ); ?></span></li>
						<li><strong><?php echo esc_html__( 'Respects WordPress permissions', 'aculect-ai-companion' ); ?></strong><span><?php echo esc_html__( 'Access is limited by user role, capabilities, and Aculect settings.', 'aculect-ai-companion' ); ?></span></li>
						<li><strong><?php echo esc_html__( 'Access can be revoked anytime', 'aculect-ai-companion' ); ?></strong><span><?php echo esc_html__( 'You can remove the connection from the Connections tab.', 'aculect-ai-companion' ); ?></span></li>
						<li><strong><?php echo esc_html__( 'All actions are logged', 'aculect-ai-companion' ); ?></strong><span><?php echo esc_html__( 'Aculect records activity for visibility and auditability.', 'aculect-ai-companion' ); ?></span></li>
					</ul>
					<p class="aculect-ai-companion-oauth-notice"><?php echo esc_html__( 'You can manage or revoke this connection at any time from the Connections page.', 'aculect-ai-companion' ); ?></p>
				</section>
			</div>
		<form class="aculect-ai-companion-oauth-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aculect_ai_companion_oauth_consent">
			<input type="hidden" name="<?php echo esc_attr( self::CONSENT_REQUEST_PARAM ); ?>" value="<?php echo esc_attr( $request_token ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<button class="aculect-ai-companion-oauth-button aculect-ai-companion-oauth-button--secondary" type="submit" name="decision" value="deny"><?php echo esc_html__( 'Deny', 'aculect-ai-companion' ); ?></button>
			<button class="aculect-ai-companion-oauth-button aculect-ai-companion-oauth-button--primary" type="submit" name="decision" value="approve"><?php echo esc_html__( 'Approve connection', 'aculect-ai-companion' ); ?></button>
		</form>
	</div>
</div>
		<?php
	}

	/**
	 * Return a user-facing assistant label for consent screens.
	 *
	 * @param ClientEntity $client Registered OAuth client.
	 */
	private function client_label( ClientEntity $client ): string {
		$labels = array(
			'chatgpt' => 'ChatGPT',
			'claude'  => 'Claude',
			'codex'   => 'Codex',
			'cursor'  => 'Cursor',
			'gemini'  => 'Gemini',
			'mcp'     => __( 'AI assistant', 'aculect-ai-companion' ),
		);

		$provider = sanitize_key( $client->getProvider() );
		if ( isset( $labels[ $provider ] ) ) {
			return (string) $labels[ $provider ];
		}

		$name = trim( (string) $client->getName() );

		return '' !== $name ? $name : __( 'AI assistant', 'aculect-ai-companion' );
	}

	/**
	 * Convert approved protocol scopes into user-facing action labels.
	 *
	 * @param string $scope Space-delimited scope string from the request.
	 */
	private function scope_summary( string $scope ): string {
		$labels = array();
		$scopes = preg_split( '/\s+/', trim( $scope ) );

		foreach ( is_array( $scopes ) ? $scopes : array() as $item ) {
			if ( 'content:read' === $item ) {
				$labels[] = __( 'Read site content and safe site information', 'aculect-ai-companion' );
			}

			if ( 'content:draft' === $item ) {
				$labels[] = __( 'Create and update content, terms, comments, and media', 'aculect-ai-companion' );
			}
		}

		if ( array() === $labels ) {
			return __( 'Use approved Aculect AI Companion actions', 'aculect-ai-companion' );
		}

		return implode( ', ', array_unique( $labels ) );
	}

	/**
	 * Render a standalone OAuth error page.
	 *
	 * @param string $title   Error title.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 */
	private function render_error( string $title, string $message, int $status ): never {
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		wp_register_style( 'aculect-ai-companion-oauth-consent', ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/css/oauth-consent.css', array(), ACULECT_AI_COMPANION_VERSION );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html( $title ); ?></title>
		<?php wp_print_styles( 'aculect-ai-companion-oauth-consent' ); ?>
</head>
<body class="aculect-ai-companion-oauth-page">
	<main class="aculect-ai-companion-oauth-card" role="main">
		<h1 class="aculect-ai-companion-oauth-title"><?php echo esc_html( $title ); ?></h1>
		<p class="aculect-ai-companion-oauth-copy"><?php echo esc_html( $message ); ?></p>
	</main>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Render an admin-context OAuth validation error.
	 *
	 * @param string $title   Error title.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 */
	private function render_admin_error( string $title, string $message, int $status ): never {
		wp_die(
			esc_html( $message ),
			esc_html( $title ),
			array(
				'response' => absint( $status ),
			)
		);
	}

	/**
	 * Redirect back to a validated OAuth client redirect URI.
	 *
	 * @param string                $redirect_uri Registered redirect URI.
	 * @param array<string, string> $params       Response query parameters.
	 */
	private function redirect_to_client( string $redirect_uri, array $params ): never {
		$params = array_filter( $params, static fn( $value ): bool => '' !== (string) $value );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth redirect URI is validated against the registered client before redirecting.
		wp_redirect( add_query_arg( $params, $redirect_uri ), 302, 'Aculect AI Companion OAuth' );
		exit;
	}

	/**
	 * Validate request parameters and return the consent context.
	 *
	 * @param array<string, string> $params        Authorization parameters.
	 * @param bool                  $admin_context Whether errors render inside wp-admin.
	 * @param WP_REST_Request|null  $request       Optional REST request.
	 * @return array{params: array<string, string>, client: ClientEntity, resource: string}
	 */
	private function authorization_context( array $params, bool $admin_context, ?WP_REST_Request $request = null ): array {
		$resource = $this->resource_from_params( $params );

		if ( 'code' !== (string) ( $params['response_type'] ?? '' ) ) {
			( new Logger() )->warning(
				'authorize.invalid_response_type',
				'OAuth authorization request used an invalid response type.',
				$this->log_context( $params, null, 'invalid_response_type' ),
				$request,
				400
			);
			$this->fail( 'Invalid response type', 'Aculect AI Companion only supports the OAuth authorization code flow.', 400, $admin_context );
		}

		if ( Helpers::mcp_resource() !== $resource ) {
			( new Logger() )->warning(
				'authorize.invalid_resource',
				'OAuth authorization request used an invalid resource.',
				$this->log_context( $params, null, 'invalid_target' ),
				$request,
				400
			);
			$this->fail( 'Invalid connection URL', 'The requested connection URL does not match this WordPress site.', 400, $admin_context );
		}

		if ( ! $this->valid_code_challenge( (string) ( $params['code_challenge'] ?? '' ) ) || 'S256' !== (string) ( $params['code_challenge_method'] ?? '' ) ) {
			( new Logger() )->warning(
				'authorize.invalid_pkce',
				'OAuth authorization request did not use PKCE S256.',
				$this->log_context( $params, null, 'invalid_pkce' ),
				$request,
				400
			);
			$this->fail( 'PKCE required', 'Aculect AI Companion requires PKCE with the S256 code challenge method.', 400, $admin_context );
		}

		if ( ! $this->scope_tokens_supported( $this->scope_tokens_from_params( $params ) ) ) {
			( new Logger() )->warning(
				'authorize.invalid_scope',
				'OAuth authorization request included unsupported scopes.',
				$this->log_context( $params, null, 'invalid_scope' ),
				$request,
				400
			);
			$this->fail( 'Invalid scope', 'The requested OAuth scope is not supported by Aculect AI Companion.', 400, $admin_context );
		}

		$client = ( new ClientRepository() )->getClientEntity( (string) ( $params['client_id'] ?? '' ) );
		if ( ! $client instanceof ClientEntity ) {
			( new Logger() )->warning(
				'authorize.invalid_client',
				'OAuth authorization request referenced an unknown client.',
				$this->log_context( $params, null, 'invalid_client' ),
				$request,
				400
			);
			$this->fail( 'Unknown application', 'The application requesting access is not registered with this site.', 400, $admin_context );
		}

		$redirect_uri = esc_url_raw( (string) ( $params['redirect_uri'] ?? '' ) );
		if ( ! $this->redirect_uri_allowed( $client, $redirect_uri ) ) {
			( new Logger() )->warning(
				'authorize.invalid_redirect_uri',
				'OAuth authorization request used a redirect URI that is not registered for the client.',
				$this->log_context( $params, $client, 'invalid_redirect_uri' ),
				$request,
				400
			);
			$this->fail( 'Invalid return URL', 'The return URL is not allowed for this AI assistant.', 400, $admin_context );
		}

		return array(
			'params'   => $params,
			'client'   => $client,
			'resource' => $resource,
		);
	}

	/**
	 * Render an OAuth validation failure in the correct context.
	 *
	 * @param string $title         Error title.
	 * @param string $message       Error message.
	 * @param int    $status        HTTP status code.
	 * @param bool   $admin_context Whether errors render inside wp-admin.
	 */
	private function fail( string $title, string $message, int $status, bool $admin_context ): never {
		if ( $admin_context ) {
			$this->render_admin_error( $title, $message, $status );
		}

		$this->render_error( $title, $message, $status );
	}

	/**
	 * Collect authorization parameters from a REST request.
	 *
	 * @param WP_REST_Request $request Authorization request.
	 * @return array<string, string>
	 */
	private function params( WP_REST_Request $request ): array {
		$params = array_merge( $request->get_query_params(), $request->get_body_params() );
		return $this->params_from_array( $params );
	}

	/**
	 * Return the sanitized consent request token from GET data.
	 */
	private function query_request_token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public OAuth routing token is validated against transient state before use.
		if ( ! isset( $_GET[ self::CONSENT_REQUEST_PARAM ] ) || ! is_scalar( $_GET[ self::CONSENT_REQUEST_PARAM ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public OAuth routing token is validated against transient state before use.
		return $this->sanitize_request_token( wp_unslash( (string) $_GET[ self::CONSENT_REQUEST_PARAM ] ) );
	}

	/**
	 * Return the sanitized consent request token from POST data.
	 */
	private function posted_request_token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public OAuth routing token is validated against transient state before action.
		if ( ! isset( $_POST[ self::CONSENT_REQUEST_PARAM ] ) || ! is_scalar( $_POST[ self::CONSENT_REQUEST_PARAM ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public OAuth routing token is validated against transient state before action.
		return $this->sanitize_request_token( wp_unslash( (string) $_POST[ self::CONSENT_REQUEST_PARAM ] ) );
	}

	/**
	 * Return the sanitized consent nonce from POST data.
	 */
	private function posted_nonce(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This reads the nonce so it can be verified immediately by the caller.
		if ( ! isset( $_POST['_wpnonce'] ) || ! is_scalar( $_POST['_wpnonce'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This reads the nonce so it can be verified immediately by the caller.
		return sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) );
	}

	/**
	 * Return the sanitized approve/deny decision from POST data.
	 */
	private function posted_decision(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Consent decision is sanitized and validated before any action is taken.
		if ( ! isset( $_POST['decision'] ) || ! is_scalar( $_POST['decision'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Consent decision is sanitized and validated before any action is taken.
		return sanitize_key( wp_unslash( (string) $_POST['decision'] ) );
	}

	/**
	 * Allowlist and sanitize OAuth request parameters.
	 *
	 * @param array<string, mixed> $params Raw parameters.
	 * @param bool                 $unslash Whether parameter values came from slashed superglobals.
	 * @return array<string, string>
	 */
	private function params_from_array( array $params, bool $unslash = false ): array {
		$output = array();

		foreach ( self::OAUTH_PARAMS as $key ) {
			if ( ! array_key_exists( $key, $params ) || ! is_scalar( $params[ $key ] ) ) {
				continue;
			}

			$value = $unslash ? wp_unslash( (string) $params[ $key ] ) : (string) $params[ $key ];
			$value = $this->sanitize_oauth_param( $key, $value );

			if ( '' !== $value ) {
				$output[ $key ] = $value;
			}
		}

		return $output;
	}

	/**
	 * Sanitize an OAuth parameter according to its protocol context.
	 *
	 * @param string $key   OAuth parameter name.
	 * @param string $value Raw parameter value.
	 */
	private function sanitize_oauth_param( string $key, string $value ): string {
		return match ( $key ) {
			'response_type' => sanitize_key( $value ),
			'client_id' => $this->sanitize_limited_text( $value, 160 ),
			'redirect_uri', 'resource' => $this->sanitize_url_param( $value ),
			'scope' => $this->sanitize_scope( $value ),
			'state' => $this->sanitize_limited_text( $value, 512 ),
			'code_challenge' => substr( preg_replace( '/[^A-Za-z0-9_-]/', '', sanitize_text_field( $value ) ) ?? '', 0, 128 ),
			'code_challenge_method' => strtoupper( sanitize_text_field( $value ) ),
			default => '',
		};
	}

	/**
	 * Sanitize bounded opaque OAuth text.
	 *
	 * @param string $value Raw text value.
	 * @param int    $limit Maximum stored length.
	 */
	private function sanitize_limited_text( string $value, int $limit ): string {
		return substr( sanitize_text_field( $value ), 0, $limit );
	}

	/**
	 * Sanitize a URL parameter while preserving invalid input for later validation failure.
	 *
	 * @param string $value Raw URL.
	 */
	private function sanitize_url_param( string $value ): string {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return '';
		}

		$url = esc_url_raw( $value );
		return '' === $url ? $value : $url;
	}

	/**
	 * Sanitize a space-delimited OAuth scope string.
	 *
	 * @param string $scope Raw scope value.
	 */
	private function sanitize_scope( string $scope ): string {
		$scope = sanitize_text_field( $scope );
		$scope = preg_replace( '/[^A-Za-z0-9:_\-. ]/', '', $scope ) ?? '';
		$scope = preg_replace( '/\s+/', ' ', trim( $scope ) ) ?? '';

		return substr( $scope, 0, 500 );
	}

	/**
	 * Sanitize an opaque consent request token.
	 *
	 * @param string $token Raw request token.
	 */
	private function sanitize_request_token( string $token ): string {
		return substr( preg_replace( '/[^a-f0-9]/', '', strtolower( $token ) ) ?? '', 0, 64 );
	}

	/**
	 * Resolve the requested OAuth resource.
	 *
	 * @param array<string, string> $params Authorization parameters.
	 * @return string
	 */
	private function resource_from_params( array $params ): string {
		$resource = (string) ( $params['resource'] ?? '' );
		return '' === $resource ? Helpers::mcp_resource() : Helpers::normalize_resource( $resource );
	}

	/**
	 * Resolve requested scopes, defaulting to read-only content access.
	 *
	 * @param array<string, string> $params Authorization parameters.
	 * @return string
	 */
	private function scope_from_params( array $params ): string {
		return implode( ' ', $this->scope_tokens_from_params( $params ) );
	}

	/**
	 * Return normalized requested scope tokens, defaulting to read access.
	 *
	 * @param array<string, string> $params Authorization parameters.
	 * @return string[]
	 */
	private function scope_tokens_from_params( array $params ): array {
		$scope = trim( (string) ( $params['scope'] ?? '' ) );
		if ( '' === $scope ) {
			return array( 'content:read' );
		}

		$tokens = preg_split( '/\s+/', $scope );
		return array_values( array_filter( is_array( $tokens ) ? array_map( 'strval', $tokens ) : array() ) );
	}

	/**
	 * Verify the redirect URI matches the registered client.
	 *
	 * @param ClientEntity $client       OAuth client.
	 * @param string       $redirect_uri Redirect URI from the request.
	 * @return bool
	 */
	private function redirect_uri_allowed( ClientEntity $client, string $redirect_uri ): bool {
		if ( '' === $redirect_uri || ! Helpers::is_allowed_redirect_uri( $redirect_uri ) ) {
			return false;
		}

		$allowed = $client->getRedirectUri();
		$allowed = is_array( $allowed ) ? $allowed : array( $allowed );
		return in_array( $redirect_uri, $allowed, true );
	}

	/**
	 * Validate the PKCE S256 code challenge shape.
	 *
	 * @param string $code_challenge Sanitized code challenge.
	 */
	private function valid_code_challenge( string $code_challenge ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $code_challenge );
	}

	/**
	 * Validate requested scopes against supported connector scopes.
	 *
	 * @param string[] $scopes Requested scope tokens.
	 */
	private function scope_tokens_supported( array $scopes ): bool {
		$supported = Helpers::supported_scopes();
		foreach ( $scopes as $scope ) {
			if ( ! in_array( $scope, $supported, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build the wp-admin consent URL for login redirects and rendering.
	 *
	 * @param string $request_token Stored consent request token.
	 * @return string
	 */
	private function admin_consent_url( string $request_token ): string {
		return add_query_arg(
			array(
				'page'                      => 'aculect-ai-companion-oauth-consent',
				self::CONSENT_REQUEST_PARAM => $request_token,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Return only OAuth parameters that must survive login and consent.
	 *
	 * @param array<string, string> $params Authorization parameters.
	 * @return array<string, string>
	 */
	private function persisted_params( array $params ): array {
		$allowed = array(
			'response_type',
			'client_id',
			'redirect_uri',
			'scope',
			'state',
			'code_challenge',
			'code_challenge_method',
			'resource',
		);
		$output  = array();

		foreach ( $allowed as $key ) {
			if ( isset( $params[ $key ] ) && '' !== (string) $params[ $key ] ) {
				$output[ $key ] = (string) $params[ $key ];
			}
		}

		return $output;
	}

	/**
	 * Persist a short-lived consent request and return its opaque token.
	 *
	 * @param array<string, string> $params Authorization parameters.
	 */
	private function store_consent_request( array $params ): string {
		$token      = bin2hex( random_bytes( 16 ) );
		$expires_at = time() + self::CONSENT_REQUEST_TTL;
		set_transient(
			$this->consent_request_transient_key( $token ),
			array(
				'params'     => $this->persisted_params( $params ),
				'user_id'    => get_current_user_id(),
				'expires_at' => $expires_at,
			),
			self::CONSENT_REQUEST_TTL
		);

		return $token;
	}

	/**
	 * Resolve a stored consent request or null when missing, malformed, or expired.
	 *
	 * @param string   $request_token Consent request token.
	 * @param int|null $now           Optional current UNIX timestamp for tests.
	 * @return array{params: array<string, string>, user_id: int, expires_at: int}|null
	 */
	private function load_consent_request( string $request_token, ?int $now = null ): ?array {
		if ( '' === $request_token ) {
			return null;
		}

		$stored = get_transient( $this->consent_request_transient_key( $request_token ) );
		if ( ! is_array( $stored ) || ! isset( $stored['params'] ) || ! is_array( $stored['params'] ) ) {
			return null;
		}

		$params      = $this->params_from_array( $stored['params'] );
		$user_id     = absint( $stored['user_id'] ?? 0 );
		$expires_at  = (int) ( $stored['expires_at'] ?? 0 );
		$current_now = $now ?? time();

		if ( array() === $params || $expires_at <= $current_now ) {
			$this->delete_consent_request( $request_token );
			return null;
		}

		return array(
			'params'     => $params,
			'user_id'    => $user_id,
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Delete a stored consent request.
	 *
	 * @param string $request_token Consent request token.
	 */
	private function delete_consent_request( string $request_token ): void {
		if ( '' === $request_token ) {
			return;
		}

		delete_transient( $this->consent_request_transient_key( $request_token ) );
	}

	/**
	 * Return stored OAuth params for a consent request token when available.
	 *
	 * @param string $request_token Consent request token.
	 * @return array<string, string>
	 */
	private function params_for_request_token( string $request_token ): array {
		$request = $this->load_consent_request( $request_token );

		return is_array( $request ) ? $request['params'] : array();
	}

	/**
	 * Validate stored consent state and the current user's ability to approve it.
	 *
	 * @param string $request_token Consent request token.
	 * @param bool   $admin_context Whether errors render inside wp-admin.
	 * @return array{request_token: string, params: array<string, string>, client: ClientEntity, resource: string}
	 */
	private function consent_request_context( string $request_token, bool $admin_context ): array {
		$stored = $this->load_consent_request( $request_token );
		if ( ! is_array( $stored ) ) {
			$this->fail( 'Expired request', 'The authorization request is missing or has expired. Start the connection again.', 400, $admin_context );
		}

		$context = $this->authorization_context( $stored['params'], $admin_context );
		$this->assert_current_user_can_consent( $context['params'], $context['client'], $stored['user_id'], $admin_context );

		if ( $stored['user_id'] <= 0 && get_current_user_id() > 0 ) {
			set_transient(
				$this->consent_request_transient_key( $request_token ),
				array(
					'params'     => $context['params'],
					'user_id'    => get_current_user_id(),
					'expires_at' => $stored['expires_at'],
				),
				max( 1, $stored['expires_at'] - time() )
			);
		}

		return array(
			'request_token' => $request_token,
			'params'        => $context['params'],
			'client'        => $context['client'],
			'resource'      => $context['resource'],
		);
	}

	/**
	 * Deny consent requests the current user is not allowed to approve.
	 *
	 * @param array<string, string> $params        Authorization parameters.
	 * @param ClientEntity          $client        Registered OAuth client.
	 * @param int                   $bound_user_id Bound WordPress user ID, when present.
	 * @param bool                  $admin_context Whether errors render inside wp-admin.
	 */
	private function assert_current_user_can_consent( array $params, ClientEntity $client, int $bound_user_id, bool $admin_context ): void {
		$current_user_id = get_current_user_id();

		if ( $current_user_id <= 0 ) {
			$this->fail( 'Login required', 'Sign in to continue the authorization request.', 401, $admin_context );
		}

		if ( $bound_user_id > 0 && $bound_user_id !== $current_user_id ) {
			$this->fail( 'Invalid request', 'This authorization request belongs to a different WordPress user.', 403, $admin_context );
		}

		if ( null !== $client->getUserId() && $client->getUserId() !== $current_user_id ) {
			$this->fail( 'Invalid request', 'This authorization request is not available for your WordPress account.', 403, $admin_context );
		}

		if ( UserAccessControl::is_paused( $current_user_id ) ) {
			$this->fail( 'Access paused', 'AI assistant access is paused for this WordPress account.', 403, $admin_context );
		}

		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! RoleConnectionEntryPoint::is_enabled() || ! RoleConnectionEntryPoint::current_user_allowed() ) {
			$this->fail( 'Insufficient permissions', 'Your WordPress role is not allowed to approve AI assistant connections on this site.', 403, $admin_context );
		}

		if ( ! $this->requested_scopes_allowed_for_current_user( $this->scope_tokens_from_params( $params ) ) ) {
			$this->fail( 'Insufficient scope', 'Your WordPress account cannot approve every requested OAuth scope.', 403, $admin_context );
		}
	}

	/**
	 * Return whether every requested scope is available to the current user.
	 *
	 * @param string[] $requested_scopes Requested scope tokens.
	 */
	private function requested_scopes_allowed_for_current_user( array $requested_scopes ): bool {
		$allowed_scopes = $this->allowed_scopes_for_user( get_current_user_id() );

		foreach ( $requested_scopes as $scope ) {
			if ( ! in_array( $scope, $allowed_scopes, true ) ) {
				return false;
			}
		}

		return array() !== $requested_scopes;
	}

	/**
	 * Return OAuth scopes backed by modules available to one WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return list<string>
	 */
	private function allowed_scopes_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$modules = ( new McpToolAvailability() )->ability_modules_for_user(
			$user_id,
			new AbilitiesRegistry(),
			Helpers::supported_scopes()
		);
		$scopes  = array();

		foreach ( $modules as $module ) {
			$scopes = array_merge( $scopes, $module->required_scopes() );
		}

		return array_values( array_unique( array_map( 'strval', $scopes ) ) );
	}

	/**
	 * Return the transient key used to store one consent request.
	 *
	 * @param string $request_token Consent request token.
	 */
	private function consent_request_transient_key( string $request_token ): string {
		return 'aculect_ai_companion_oauth_consent_' . $request_token;
	}

	/**
	 * Build sanitized diagnostic context for OAuth authorization events.
	 *
	 * @param array<string, string> $params     Authorization parameters.
	 * @param ClientEntity|null     $client     Optional registered client.
	 * @param string|null           $error_code Optional error code.
	 * @return array<string, mixed>
	 */
	private function log_context( array $params, ?ClientEntity $client = null, ?string $error_code = null ): array {
		$sanitizer    = new LogSanitizer();
		$redirect_uri = (string) ( $params['redirect_uri'] ?? '' );
		$context      = array(
			'provider'         => $client instanceof ClientEntity ? $client->getProvider() : '',
			'response_type'    => (string) ( $params['response_type'] ?? '' ),
			'scope'            => $this->scope_from_params( $params ),
			'resource'         => $sanitizer->sanitize_url( $this->resource_from_params( $params ) ),
			'redirect_uri'     => '' === $redirect_uri ? '' : $sanitizer->sanitize_url( $redirect_uri ),
			'pkce_method_seen' => (string) ( $params['code_challenge_method'] ?? '' ),
			'user_logged_in'   => is_user_logged_in(),
		);

		if ( null !== $error_code ) {
			$context['error_code'] = $error_code;
		}

		return $context;
	}

	/**
	 * Build timeline grouping context for OAuth authorization events.
	 *
	 * @param array<string, string> $params Authorization parameters.
	 * @param ClientEntity          $client Registered OAuth client.
	 * @return array<string, mixed>
	 */
	private function timeline_auth_context( array $params, ClientEntity $client ): array {
		unset( $params );

		return array(
			'provider'    => $client->getProvider(),
			'client_id'   => $client->getIdentifier(),
			'client_name' => $client->getName(),
			'user_id'     => get_current_user_id(),
		);
	}

	/**
	 * Record an OAuth timeline event without making consent completion depend on logging.
	 *
	 * @param string               $event    Timeline event type.
	 * @param array<string, mixed> $metadata Support-safe metadata.
	 * @param array<string, mixed> $auth     OAuth client context.
	 */
	private function record_timeline_event( string $event, array $metadata, array $auth ): void {
		try {
			( new ActivityLogger() )->record_timeline_event( $event, $metadata, $auth );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}
}
