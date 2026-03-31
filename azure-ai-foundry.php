<?php
/**
 * Plugin Name: Azure AI Foundry Connector
 * Plugin URI:  https://github.com/soderlind/azure-ai-foundry
 * Description: Connect WordPress to Azure AI Foundry Model Inference API for text generation, embeddings, and more.
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Version: 0.3.3
 * Author: Per Søderlind
 * Author URI: https://soderlind.no/
 * License: GPL-2.0-or-later
 * Text Domain: azure-ai-foundry
 */

namespace AzureAiFoundry;

use WordPress\AiClient\AiClient;
use AzureAiFoundry\Provider\AzureAiFoundryProvider;
use AzureAiFoundry\Settings\ConnectorSettings;
use AzureAiFoundry\Settings\SettingsManager;
use AzureAiFoundry\Rest\DetectCapabilitiesController;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

define( 'AZURE_AI_FOUNDRY_VERSION', '0.3.3' );
define( 'AZURE_AI_FOUNDRY_FILE', __FILE__ );
define( 'AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_ID', 'azure_ai_foundry_status' );
define( 'AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_OPTION', 'connectors_ai_azure_ai_foundry_status_api_key' );

require_once __DIR__ . '/src/autoload.php';

// Composer autoloader (provides yahnis-elsts/plugin-update-checker).
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// GitHub Updater — automatic updates from GitHub releases.
if ( ! class_exists( \Soderlind\WordPress\GitHubUpdater::class ) ) {
	require_once __DIR__ . '/class-github-plugin-updater.php';
}
\Soderlind\WordPress\GitHubUpdater::init(
	github_url:  'https://github.com/soderlind/azure-ai-foundry',
	plugin_file: __FILE__,
	plugin_slug: 'azure-ai-foundry',
	name_regex:  '/azure-ai-foundry\.zip/',
	branch:      'main',
);

/**
 * Register the provider with the AI Client registry early.
 */
function register_provider(): void {
	if ( ! class_exists( AiClient::class) ) {
		return;
	}

	$registry = AiClient::defaultRegistry();

	if ( ! $registry->hasProvider( AzureAiFoundryProvider::class) ) {
		$registry->registerProvider( AzureAiFoundryProvider::class);
	}
}
add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );

/**
 * Configure authentication after WP loads credentials.
 *
 * Runs at priority 30, after core connector key binding (priority 20).
 */
function setup_authentication(): void {
	if ( ! class_exists( AiClient::class) ) {
		return;
	}

	$api_key = ConnectorSettings::get_real_api_key();

	if ( empty( $api_key ) ) {
		$env_key = SettingsManager::instance()->resolve_env( 'AZURE_AI_FOUNDRY_API_KEY' );
		if ( '' !== $env_key ) {
			$api_key = $env_key;
		}
	}

	if ( ! empty( $api_key ) ) {
		AiClient::defaultRegistry()->setProviderRequestAuthentication(
			'azure-ai-foundry',
			new Http\AzureAiFoundryRequestAuthentication( $api_key )
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\\setup_authentication', 30 );

/**
 * Register connector settings.
 */
add_action( 'init', [ ConnectorSettings::class, 'register' ] );

/**
 * Register the detect-capabilities REST endpoint.
 */
add_action( 'rest_api_init', [ DetectCapabilitiesController::class, 'register' ] );

/**
 * Register the connector JS module.
 *
 * Only @wordpress/connectors is a script module dependency.
 * Classic-script packages are accessed via window.wp.* globals.
 */
function register_connector_module(): void {
	wp_register_script_module(
		'azure-ai-foundry/connectors',
		plugins_url( 'build/connectors.js', AZURE_AI_FOUNDRY_FILE ),
		[
			[
				'id'     => '@wordpress/connectors',
				'import' => 'dynamic',
			],
		],
		AZURE_AI_FOUNDRY_VERSION
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_connector_module' );

/**
 * Enqueue on the Connectors page only (hook both page variants).
 */
function enqueue_connector_module(): void {
	wp_enqueue_script_module( 'azure-ai-foundry/connectors' );
}
add_action( 'options-connectors-wp-admin_init', __NAMESPACE__ . '\\enqueue_connector_module' );
add_action( 'connectors-wp-admin_init', __NAMESPACE__ . '\\enqueue_connector_module' );

/**
 * Remove the internal AI-plugin compatibility connector from the UI payload.
 *
 * The connector remains in the server-side registry so wp_get_connectors()
 * can see it, but it should never be shown in Settings -> Connectors.
 *
 * @param array $data Script module data.
 * @return array
 */
function filter_connector_script_data( array $data ): array {
	if ( isset( $data['connectors'][ AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_ID ] ) ) {
		unset( $data['connectors'][ AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_ID ] );
	}

	return $data;
}
add_filter( 'script_module_data_options-connectors-wp-admin', __NAMESPACE__ . '\\filter_connector_script_data' );
add_filter( 'script_module_data_connectors-wp-admin', __NAMESPACE__ . '\\filter_connector_script_data' );

/**
 * Unregister from the connector registry so core does not manage our API key.
 *
 * This prevents double-masking, failed key validation (our provider needs an
 * endpoint URL before it can validate), and duplicate setting registration.
 *
 * @param \WP_Connector_Registry $registry Connector registry instance.
 */
function unregister_from_connector_registry( \WP_Connector_Registry $registry ): void {
	if ( $registry->is_registered( 'azure-ai-foundry' ) ) {
		$registry->unregister( 'azure-ai-foundry' );
	}

	if ( ! $registry->is_registered( AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_ID ) ) {
		$registry->register(
			AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_ID,
			[
				'name'           => __( 'Azure AI Foundry Status', 'azure-ai-foundry' ),
				'description'    => __( 'Internal compatibility connector for AI plugin detection.', 'azure-ai-foundry' ),
				'type'           => 'ai_provider',
				'authentication' => [
					'method' => 'api_key',
				],
			]
		);
	}
}
add_action( 'wp_connectors_init', __NAMESPACE__ . '\\unregister_from_connector_registry' );

/**
 * Sync an internal sentinel option so the AI plugin sees a configured connector.
 *
 * The AI plugin checks wp_get_connectors() for ai_provider entries with a
 * non-empty API-key option. Because this provider unregisters its visible
 * connector to keep a custom UI, we expose a hidden compatibility connector
 * instead and toggle its generated option based on real configuration.
 */
function sync_ai_plugin_credential_sentinel(): void {
	$has_api_key = '' !== SettingsManager::instance()->get_real_api_key();
	$has_endpoint = '' !== SettingsManager::instance()->get_endpoint();
	$current = get_option( AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_OPTION, '' );

	if ( $has_api_key && $has_endpoint ) {
		if ( '1' !== $current ) {
			update_option( AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_OPTION, '1' );
		}
		return;
	}

	if ( '' !== $current ) {
		delete_option( AZURE_AI_FOUNDRY_AI_PLUGIN_SENTINEL_OPTION );
	}
}
add_action( 'init', __NAMESPACE__ . '\\sync_ai_plugin_credential_sentinel', 35 );
