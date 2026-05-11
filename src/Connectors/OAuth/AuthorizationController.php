<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth;

use Exception;
use Quark\Connectors\Helpers;
use Quark\Connectors\OAuth\Entities\ClientEntity;
use Quark\Connectors\OAuth\Entities\UserEntity;
use Quark\Connectors\OAuth\Repositories\ClientRepository;
use Quark\Connectors\OAuth\Server\AuthorizationServerFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use WP_REST_Request;
use WP_REST_Server;

final class AuthorizationController {

	private const NONCE_ACTION = 'quark_oauth_authorize';

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

	public function authorize( WP_REST_Request $request ): void {
		$params = $this->params( $request );
		$this->authorization_context( $params, false );

		$consent_url = $this->admin_consent_url( $params );
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $consent_url ), 302, 'Quark OAuth' );
			exit;
		}

		wp_safe_redirect( $consent_url, 302, 'Quark OAuth' );
		exit;
	}

	public function render_admin_consent(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth consent GET parameters are validated below before rendering.
		$params  = $this->params_from_array( wp_unslash( $_GET ) );
		$context = $this->authorization_context( $params, true );

		$this->render_consent_markup( $context['params'], $context['client'], $context['resource'] );
	}

	public function handle_admin_consent(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth POST parameters are nonce-checked below before authorization is completed.
		$params = $this->params_from_array( wp_unslash( $_POST ) );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->admin_consent_url( $params ) ), 302, 'Quark OAuth' );
			exit;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			$this->render_error( 'Invalid request', 'The authorization request failed a security check.', 400 );
		}

		$context  = $this->authorization_context( $params, false );
		$decision = sanitize_key( (string) ( $_POST['decision'] ?? '' ) );

		$this->handle_decision( $context['params'], $context['client'], $context['resource'], $decision );
	}

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
				$this->render_error( 'Authorization failed', 'Quark could not complete the OAuth authorization request.', 500 );
			}

			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth redirect URI is validated against the registered client before redirecting.
			wp_redirect( $location, 302, 'Quark OAuth' );
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

	private function render_consent_markup( array $params, ClientEntity $client, string $resource ): void {
		$current_user = wp_get_current_user();
		$site_name    = get_bloginfo( 'name' );
		$scope        = $this->scope_from_params( $params );

		nocache_headers();
		?>
<div class="quark-oauth-page quark-oauth-page--admin">
	<div class="quark-oauth-card" role="main" aria-labelledby="quark-oauth-title">
		<div class="quark-oauth-brand">Quark</div>
		<h1 id="quark-oauth-title" class="quark-oauth-title"><?php echo esc_html__( 'Approve AI assistant access', 'quark' ); ?></h1>
		<p class="quark-oauth-copy">
			<?php echo esc_html( $client->getName() ); ?> <?php echo esc_html__( 'wants to connect to this WordPress site through Quark MCP.', 'quark' ); ?>
		</p>
		<dl class="quark-oauth-details">
			<div class="quark-oauth-detail"><dt><?php echo esc_html__( 'Site', 'quark' ); ?></dt><dd><?php echo esc_html( $site_name ); ?></dd></div>
			<div class="quark-oauth-detail"><dt><?php echo esc_html__( 'WordPress User', 'quark' ); ?></dt><dd><?php echo esc_html( $current_user->display_name ); ?></dd></div>
			<div class="quark-oauth-detail"><dt><?php echo esc_html__( 'Scopes', 'quark' ); ?></dt><dd><code><?php echo esc_html( $scope ); ?></code></dd></div>
			<div class="quark-oauth-detail"><dt><?php echo esc_html__( 'Resource', 'quark' ); ?></dt><dd><code><?php echo esc_html( $resource ); ?></code></dd></div>
		</dl>
		<form class="quark-oauth-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="quark_oauth_consent">
			<?php foreach ( $this->persisted_params( $params ) as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<button class="quark-oauth-button quark-oauth-button--secondary" type="submit" name="decision" value="deny"><?php echo esc_html__( 'Deny', 'quark' ); ?></button>
			<button class="quark-oauth-button quark-oauth-button--primary" type="submit" name="decision" value="approve"><?php echo esc_html__( 'Approve', 'quark' ); ?></button>
		</form>
	</div>
</div>
		<?php
	}

	private function render_error( string $title, string $message, int $status ): never {
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		wp_register_style( 'quark-oauth-consent', QUARK_PLUGIN_URL . 'assets/css/oauth-consent.css', array(), QUARK_VERSION );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html( $title ); ?></title>
		<?php wp_print_styles( 'quark-oauth-consent' ); ?>
</head>
<body class="quark-oauth-page">
	<main class="quark-oauth-card" role="main">
		<h1 class="quark-oauth-title"><?php echo esc_html( $title ); ?></h1>
		<p class="quark-oauth-copy"><?php echo esc_html( $message ); ?></p>
	</main>
</body>
</html>
		<?php
		exit;
	}

	private function render_admin_error( string $title, string $message, int $status ): never {
		wp_die(
			esc_html( $message ),
			esc_html( $title ),
			array(
				'response' => absint( $status ),
			)
		);
	}

	private function redirect_to_client( string $redirect_uri, array $params ): never {
		$params = array_filter( $params, static fn( $value ): bool => '' !== (string) $value );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth redirect URI is validated against the registered client before redirecting.
		wp_redirect( add_query_arg( $params, $redirect_uri ), 302, 'Quark OAuth' );
		exit;
	}

	private function authorization_context( array $params, bool $admin_context ): array {
		$resource = $this->resource_from_params( $params );

		if ( Helpers::mcp_resource() !== $resource ) {
			$this->fail( 'Invalid resource', 'The requested OAuth resource does not match this Quark MCP server.', 400, $admin_context );
		}

		if ( 'S256' !== (string) ( $params['code_challenge_method'] ?? '' ) ) {
			$this->fail( 'PKCE required', 'Quark requires PKCE with the S256 code challenge method.', 400, $admin_context );
		}

		$client = ( new ClientRepository() )->getClientEntity( (string) ( $params['client_id'] ?? '' ) );
		if ( ! $client instanceof ClientEntity ) {
			$this->fail( 'Unknown application', 'The application requesting access is not registered with this site.', 400, $admin_context );
		}

		$redirect_uri = esc_url_raw( (string) ( $params['redirect_uri'] ?? '' ) );
		if ( ! $this->redirect_uri_allowed( $client, $redirect_uri ) ) {
			$this->fail( 'Invalid redirect URI', 'The redirect URI is not allowed for this OAuth client.', 400, $admin_context );
		}

		return array(
			'params'   => $params,
			'client'   => $client,
			'resource' => $resource,
		);
	}

	private function fail( string $title, string $message, int $status, bool $admin_context ): never {
		if ( $admin_context ) {
			$this->render_admin_error( $title, $message, $status );
		}

		$this->render_error( $title, $message, $status );
	}

	private function params( WP_REST_Request $request ): array {
		$params = array_merge( $request->get_query_params(), $request->get_body_params() );
		return $this->params_from_array( $params );
	}

	private function params_from_array( array $params ): array {
		return array_map( static fn( $value ): string => is_scalar( $value ) ? (string) $value : '', $params );
	}

	private function resource_from_params( array $params ): string {
		$resource = (string) ( $params['resource'] ?? '' );
		return '' === $resource ? Helpers::mcp_resource() : Helpers::normalize_resource( $resource );
	}

	private function scope_from_params( array $params ): string {
		$scope = trim( (string) ( $params['scope'] ?? '' ) );
		return '' === $scope ? 'content:read' : preg_replace( '/\s+/', ' ', $scope );
	}

	private function redirect_uri_allowed( ClientEntity $client, string $redirect_uri ): bool {
		if ( '' === $redirect_uri || ! Helpers::is_allowed_redirect_uri( $redirect_uri ) ) {
			return false;
		}

		$allowed = $client->getRedirectUri();
		$allowed = is_array( $allowed ) ? $allowed : array( $allowed );
		return in_array( $redirect_uri, $allowed, true );
	}

	private function admin_consent_url( array $params ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'quark',
					'view' => 'oauth-consent',
				),
				$this->persisted_params( $params )
			),
			admin_url( 'options-general.php' )
		);
	}

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
