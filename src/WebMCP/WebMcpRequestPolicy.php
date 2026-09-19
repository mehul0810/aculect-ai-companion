<?php

declare(strict_types=1);

namespace Aculect\AICompanion\WebMCP;

/**
 * Decides whether a request represents genuinely public page content.
 */
final class WebMcpRequestPolicy {

	/**
	 * Return whether WebMCP may expose the current request.
	 *
	 * @param array{admin:bool,json:bool,feed:bool,password:bool,logged_in:bool,preview:bool,singular_status:?string} $context Request context.
	 */
	public function allows( array $context ): bool {
		if ( $context['admin'] || $context['json'] || $context['feed'] || $context['password'] || $context['logged_in'] || $context['preview'] ) {
			return false;
		}

		return null === $context['singular_status'] || 'publish' === $context['singular_status'];
	}
}
