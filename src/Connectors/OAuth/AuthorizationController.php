<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Exception;
use Aculect\AICompanion\Connectors\Helpers;
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

	private const NONCE_ACTION = 'aculect_ai_companion_oauth_authorize';
	private const OAUTH_PARAMS = array(
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
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'authorize' ),
					'permission_callback' => '__return_true',
				),
			)
		);
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

		$context = $this->authorization_context( $params, false, $request );

		$consent_url = $this->admin_consent_url( $params );
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
		$params  = $this->query_params();
		$context = $this->authorization_context( $params, true );

		$this->render_consent_markup( $context['params'], $context['client'], $context['resource'] );
	}

	/**
	 * Process an approve or deny decision from the consent screen.
	 */
	public function handle_admin_consent(): void {
		$params = $this->posted_params();

		if ( ! is_user_logged_in() ) {
			( new Logger() )->info(
				'authorize.login_redirect',
				'OAuth consent submission required WordPress login.',
				$this->log_context( $params ),
				null,
				302
			);
			wp_safe_redirect( wp_login_url( $this->admin_consent_url( $params ) ), 302, 'Aculect AI Companion OAuth' );
			exit;
		}

		$nonce = $this->posted_nonce();
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			( new Logger() )->warning(
				'consent.invalid_nonce',
				'OAuth consent submission failed nonce validation.',
				$this->log_context( $params, null, 'invalid_nonce' ),
				null,
				400
			);
			$this->render_error( 'Invalid request', 'The authorization request failed a security check.', 400 );
		}

		$context  = $this->authorization_context( $params, false );
		$decision = $this->posted_decision();
		if ( ! in_array( $decision, array( 'approve', 'deny' ), true ) ) {
			( new Logger() )->warning(
				'consent.invalid_decision',
				'OAuth consent submission included an invalid decision.',
				$this->log_context( $params, $context['client'], 'invalid_decision' ),
				null,
				400
			);
			$this->render_error( 'Invalid request', 'The authorization decision was not valid.', 400 );
		}

		$this->handle_decision( $context['params'], $context['client'], $context['resource'], $decision );
	}

	/**
	 * Complete or deny the authorization request and redirect back to the client.
	 *
	 * @param array<string, string> $params   Validated authorization parameters.
	 * @param ClientEntity          $client   Registered OAuth client.
	 * @param string                $resource MCP resource URL.
	 * @param string                $decision User decision.
	 */
	private function handle_decision( array $params, ClientEntity $client, string $resource, string $decision ): never {
		$redirect_uri = esc_url_raw( (string) ( $params['redirect_uri'] ?? '' ) );
		$state        = sanitize_text_field( (string) ( $params['state'] ?? '' ) );

		if ( 'approve' !== $decision ) {
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

			( new Logger() )->info(
				'consent.approved',
				'OAuth consent request was approved.',
				$this->log_context( $params, $client ),
				null,
				302
			);
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth redirect URI is validated against the registered client before redirecting.
			wp_redirect( $location, 302, 'Aculect AI Companion OAuth' );
			exit;
		} catch ( OAuthServerException $exception ) {
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
	 * @param array<string, string> $params   Authorization parameters.
	 * @param ClientEntity          $client   Registered OAuth client.
	 * @param string                $resource MCP resource URL.
	 */
	private function render_consent_markup( array $params, ClientEntity $client, string $resource ): void {
		$current_user = wp_get_current_user();
		$site_name    = get_bloginfo( 'name' );
		$scope        = $this->scope_from_params( $params );
		$actions      = $this->scope_summary( $scope );

		nocache_headers();
		?>
<div class="aculect-ai-companion-oauth-page aculect-ai-companion-oauth-page--admin">
	<div class="aculect-ai-companion-oauth-card" role="main" aria-labelledby="aculect-ai-companion-oauth-title">
		<div class="aculect-ai-companion-oauth-brand">Aculect AI Companion</div>
			<h1 id="aculect-ai-companion-oauth-title" class="aculect-ai-companion-oauth-title"><?php echo esc_html__( 'Approve AI assistant access', 'aculect-ai-companion' ); ?></h1>
			<p class="aculect-ai-companion-oauth-copy">
				<?php echo esc_html( $client->getName() ); ?> <?php echo esc_html__( 'wants to connect to this WordPress site through Aculect AI Companion.', 'aculect-ai-companion' ); ?>
			</p>
			<dl class="aculect-ai-companion-oauth-details">
				<div class="aculect-ai-companion-oauth-detail"><dt><?php echo esc_html__( 'Site', 'aculect-ai-companion' ); ?></dt><dd><?php echo esc_html( $site_name ); ?></dd></div>
				<div class="aculect-ai-companion-oauth-detail"><dt><?php echo esc_html__( 'WordPress User', 'aculect-ai-companion' ); ?></dt><dd><?php echo esc_html( $current_user->display_name ); ?></dd></div>
				<div class="aculect-ai-companion-oauth-detail"><dt><?php echo esc_html__( 'Allowed actions', 'aculect-ai-companion' ); ?></dt><dd><?php echo esc_html( $actions ); ?></dd></div>
				<div class="aculect-ai-companion-oauth-detail"><dt><?php echo esc_html__( 'Connection URL', 'aculect-ai-companion' ); ?></dt><dd><code><?php echo esc_html( $resource ); ?></code></dd></div>
			</dl>
		<form class="aculect-ai-companion-oauth-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aculect_ai_companion_oauth_consent">
			<?php foreach ( $this->persisted_params( $params ) as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<button class="aculect-ai-companion-oauth-button aculect-ai-companion-oauth-button--secondary" type="submit" name="decision" value="deny"><?php echo esc_html__( 'Deny', 'aculect-ai-companion' ); ?></button>
			<button class="aculect-ai-companion-oauth-button aculect-ai-companion-oauth-button--primary" type="submit" name="decision" value="approve"><?php echo esc_html__( 'Approve', 'aculect-ai-companion' ); ?></button>
		</form>
	</div>
</div>
		<?php
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
	 * Collect OAuth query parameters from the admin consent URL.
	 *
	 * @return array<string, string>
	 */
	private function query_params(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public OAuth query parameters are allowlisted and sanitized by params_from_array().
		return $this->params_from_array( $_GET, true );
	}

	/**
	 * Collect OAuth POST parameters from the consent form.
	 *
	 * @return array<string, string>
	 */
	private function posted_params(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth POST parameters are allowlisted and sanitized by params_from_array(); nonce is verified before action.
		return $this->params_from_array( $_POST, true );
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
	 * @param array<string, string> $params Authorization parameters.
	 * @return string
	 */
	private function admin_consent_url( array $params ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'aculect-ai-companion',
					'view' => 'oauth-consent',
				),
				$this->persisted_params( $params )
			),
			admin_url( 'options-general.php' )
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
}
