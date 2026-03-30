<?php
namespace Soderlind\WordPress;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Generic WordPress Plugin GitHub Updater
 *
 * A reusable class for handling WordPress plugin updates from GitHub repositories
 * using the plugin-update-checker library.
 *
 * @package Soderlind\WordPress
 * @link    https://github.com/soderlind/wordpress-plugin-github-updater
 * @version 1.0.0
 * @author  Per Soderlind
 * @license GPL-2.0+
 */
class GitHubUpdater {
	/**
	 * Initialize the GitHub update checker.
	 *
	 * @param string $github_url  Full GitHub repository URL.
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @param string $plugin_slug Plugin slug used by WordPress.
	 * @param string $name_regex  Optional regex to filter release assets.
	 * @param string $branch      Branch to track.
	 */
	public static function init(
		string $github_url,
		string $plugin_file,
		string $plugin_slug,
		string $name_regex = '',
		string $branch = 'main'
	): void {
		add_action( 'init', static function () use ( $github_url, $plugin_file, $plugin_slug, $name_regex, $branch ): void {
			try {
				if ( ! class_exists( PucFactory::class ) ) {
					throw new \RuntimeException( 'Missing dependency yahnis-elsts/plugin-update-checker. Run composer install --no-dev.' );
				}

				$checker = PucFactory::buildUpdateChecker( $github_url, $plugin_file, $plugin_slug );
				$checker->setBranch( $branch );

				if ( '' !== $name_regex ) {
					$checker->getVcsApi()->enableReleaseAssets( $name_regex );
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'GitHubUpdater (' . $plugin_slug . '): ' . $e->getMessage() );
				}
			}
		} );
	}
}
