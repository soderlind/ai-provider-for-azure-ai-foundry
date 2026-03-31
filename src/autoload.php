<?php
/**
 * Autoloader for the AzureAiFoundry namespace.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'AzureAiFoundry\\';

	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );
