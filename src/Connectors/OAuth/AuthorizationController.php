<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Exception;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use Aculect\AICompanion\Connectors\OAuth\Entities\UserEntity;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Connectors\OAuth\Server\AuthorizationServerFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Handles the redirect-based OAuth authorization and consent flow.
 */
final class AuthorizationController {

	private const NONCE_ACTION = 'aculect_ai_companion_oauth_authorize';

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
		$this->authorization_context( $params, false );

		$consent_url = $this->admin_consent_url( $params );
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $consent_url ), 302, 'Aculect AI Companion OAuth' );
			exit;
		}

		wp_safe_redirect( $consent_url, 302, 'Aculect AI Companion OAuth' );
		exit;
	}

	/**
	 * Render the admin-hosted OAuth consent screen.
	 */
	public function render_admin_consent(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth consent GET parameters are validated below before rendering.
		$params  = $this->params_from_array( wp_unslash( $_GET ) );
		$context = $this->authorization_context( $params, true );

		$this->render_consent_markup( $context['params'], $context['client'], $context['resource'] );
	}

	/**
	 * Process an approve or deny decision from the consent screen.
	 */
	public function handle_admin_consent(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth POST parameters are nonce-checked below before authorization is completed.
		$params = $this->params_from_array( wp_unslash( $_POST ) );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->admin_consent_url( $params ) ), 302, 'Aculect AI Companion OAuth' );
			exit;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			$this->render_error( 'Invalid request', 'The authorization request failed a security check.', 400 );
		}

		$context  = $this->authorization_context( $params, false );
		$decision = sanitize_key( (string) ( $_POST['decision'] ?? '' ) );

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
				$this->render_error( 'Connection approval failed', 'Aculect AI Companion could not complete the approval request.', 500 );
			}

			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth redirect URI is validated against the registered client before redirecting.
			wp_redirect( $location, 302, 'Aculect AI Companion OAuth' );
			exit;
		} catch ( OAuthServerException $exception ) {
			$this->redirect_to_client(
				$redirect_uri,
				array(
					'error'             => $exception->getErrorType(),
					'error_description' => $exception->getMessage(),
					'state'             => $state,
				)
			);
		} catch ( Exception $exception ) {
			$this->render_error( 'Authorization failed', $exception->getMessage(), 500 );
		} finally {
			RequestContext::reset();
		}
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
	 * @return array{params: array<string, string>, client: ClientEntity, resource: string}
	 */
	private function authorization_context( array $params, bool $admin_context ): array {
		$resource = $this->resource_from_params( $params );

		if ( Helpers::mcp_resource() !== $resource ) {
			$this->fail( 'Invalid connection URL', 'The requested connection URL does not match this WordPress site.', 400, $admin_context );
		}

		if ( 'S256' !== (string) ( $params['code_challenge_method'] ?? '' ) ) {
			$this->fail( 'PKCE required', 'Aculect AI Companion requires PKCE with the S256 code challenge method.', 400, $admin_context );
		}

		$client = ( new ClientRepository() )->getClientEntity( (string) ( $params['client_id'] ?? '' ) );
		if ( ! $client instanceof ClientEntity ) {
			$this->fail( 'Unknown application', 'The application requesting access is not registered with this site.', 400, $admin_context );
		}

		$redirect_uri = esc_url_raw( (string) ( $params['redirect_uri'] ?? '' ) );
		if ( ! $this->redirect_uri_allowed( $client, $redirect_uri ) ) {
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
	 * Coerce request parameters to scalar strings.
	 *
	 * @param array<string, mixed> $params Raw parameters.
	 * @return array<string, string>
	 */
	private function params_from_array( array $params ): array {
		return array_map( static fn( $value ): string => is_scalar( $value ) ? (string) $value : '', $params );
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
		$scope = trim( (string) ( $params['scope'] ?? '' ) );
		return '' === $scope ? 'content:read' : preg_replace( '/\s+/', ' ', $scope );
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
}
