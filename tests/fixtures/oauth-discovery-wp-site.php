<?php
/**
 * Minimal WordPress site stand-in for discovery unit tests.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

class WP_Site {
	public int $blog_id;
	public int $site_id;
	public int $deleted  = 0;
	public int $archived = 0;
	public int $spam     = 0;

	public function __construct( int $blog_id, int $site_id = 1 ) {
		$this->blog_id = $blog_id;
		$this->site_id = $site_id;
	}
}
