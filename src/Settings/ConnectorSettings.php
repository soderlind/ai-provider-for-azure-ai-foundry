<?php
/**
 * Connector Settings for the Connectors page.
 *
 * All settings registered with the 'connectors' group and show_in_rest = true
 * are automatically available via the REST API at GET /wp/v2/settings.
 */

namespace AzureAiFoundry\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConnectorSettings {

	// Option names — prefixed with connectors_ai_ to follow WP convention.
	public const OPTION_API_KEY      = 'connectors_ai_azure_ai_foundry_api_key';
	public const OPTION_ENDPOINT     = 'connectors_ai_azure_ai_foundry_endpoint';
	public const OPTION_MODEL_NAME   = 'connectors_ai_azure_ai_foundry_model_name';
	public const OPTION_CAPABILITIES = 'connectors_ai_azure_ai_foundry_capabilities';

	private const ALLOWED_CAPABILITIES = [
		'text_generation',
		'chat_history',
		'image_generation',
		'embedding_generation',
		'text_to_speech_conversion',
	];

	/**
	 * Register settings on init.
	 */
	public static function register(): void {

		// ── API Key ─────────────────────────────────────────────
		register_setting(
			'connectors',
			self::OPTION_API_KEY,
			[
				'type'              => 'string',
				'label'             => __( 'Azure AI Foundry API Key', 'ai-provider-for-azure-ai-foundry' ),
				'description'       => __( 'API key for Azure AI Foundry Model Inference.', 'ai-provider-for-azure-ai-foundry' ),
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			]
		);
		add_filter(
			'option_' . self::OPTION_API_KEY,
			[ __CLASS__, 'mask_api_key' ]
		);

		// ── Endpoint URL ────────────────────────────────────────
		register_setting(
			'connectors',
			self::OPTION_ENDPOINT,
			[
				'type'              => 'string',
				'label'             => __( 'Endpoint URL', 'ai-provider-for-azure-ai-foundry' ),
				'description'       => __( 'Azure AI resource URL (e.g. https://my-resource.services.ai.azure.com).', 'ai-provider-for-azure-ai-foundry' ),
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
			]
		);

		// ── Model Name (auto-detected, not user-editable) ──────
		register_setting(
			'connectors',
			self::OPTION_MODEL_NAME,
			[
				'type'              => 'string',
				'label'             => __( 'Model Name', 'ai-provider-for-azure-ai-foundry' ),
				'description'       => __( 'Auto-detected deployment names.', 'ai-provider-for-azure-ai-foundry' ),
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			]
		);

		// ── Capabilities ────────────────────────────────────────
		register_setting(
			'connectors',
			self::OPTION_CAPABILITIES,
			[
				'type'              => 'array',
				'label'             => __( 'Capabilities', 'ai-provider-for-azure-ai-foundry' ),
				'description'       => __( 'Capabilities supported by the Azure AI Foundry deployment.', 'ai-provider-for-azure-ai-foundry' ),
				'default'           => [ 'text_generation', 'chat_history' ],
				'show_in_rest'      => [
					'schema' => [
						'type'  => 'array',
						'items' => [
							'type' => 'string',
							'enum' => self::ALLOWED_CAPABILITIES,
						],
					],
				],
				'sanitize_callback' => [ __CLASS__, 'sanitize_capabilities' ],
			]
		);
	}

	/**
	 * Sanitize the capabilities array.
	 */
	public static function sanitize_capabilities( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		return array_values( array_intersect( $value, self::ALLOWED_CAPABILITIES ) );
	}

	/**
	 * Mask an API key: show bullet characters + last 4 chars.
	 *
	 * @param mixed $key The API key value.
	 * @return string Masked key.
	 */
	public static function mask_api_key( mixed $key ): string {
		if ( ! is_string( $key ) || strlen( $key ) <= 4 ) {
			return is_string( $key ) ? $key : '';
		}
		return str_repeat( "\u{2022}", min( strlen( $key ) - 4, 16 ) )
			 . substr( $key, -4 );
	}

	/**
	 * Read the real (unmasked) API key from the database.
	 */
	public static function get_real_api_key(): string {
		remove_filter( 'option_' . self::OPTION_API_KEY, [ __CLASS__, 'mask_api_key' ] );
		$value = get_option( self::OPTION_API_KEY, '' );
		add_filter( 'option_' . self::OPTION_API_KEY, [ __CLASS__, 'mask_api_key' ] );

		return (string) $value;
	}
}
