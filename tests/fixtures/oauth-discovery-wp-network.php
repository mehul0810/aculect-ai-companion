<?php
/**
 * Minimal WordPress network stand-in for discovery unit tests.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

class WP_Network {
	public int $id        = 1;
	public string $domain = 'example.com';
}
