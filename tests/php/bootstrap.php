<?php
/**
 * PHPUnit bootstrap file.
 *
 * Uses Brain Monkey to mock WordPress functions.
 */

declare(strict_types=1);

// Load Composer autoloader.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Define WordPress constants for the plugin code.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
