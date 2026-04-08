<?php
/**
 * REST endpoint to auto-detect capabilities from an Azure endpoint.
 *
 * Probes the configured Azure endpoint (AI Foundry or Azure OpenAI) and
 * returns the detected model name and capability strings.
 */

namespace AzureAiFoundry\Rest;

use AzureAiFoundry\Settings\ConnectorSettings;
use AzureAiFoundry\Provider\AzureAiFoundryProvider;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class DetectCapabilitiesController {

	public const NAMESPACE = 'azure-ai-foundry/v1';
	public const ROUTE     = '/detect';

	/**
	 * Register the route.
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE ,
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
				'args'                => [
					'endpoint' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					],
					'api_key'  => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Only administrators can detect capabilities.
	 */
	public static function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Probe the Azure endpoint and return detected capabilities.
	 *
	 * Saves detected model_name and capabilities directly to the database.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$endpoint    = rtrim( $request->get_param( 'endpoint' ), '/' );
		$api_key     = $request->get_param( 'api_key' );
		$api_version = AzureAiFoundryProvider::MODEL_INFERENCE_API_VERSION;

		// If the stored key is masked, read the real one.
		if ( str_starts_with( $api_key, "\u{2022}" ) ) {
			$api_key = ConnectorSettings::get_real_api_key();
		}

		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Endpoint and API key are required.', 'ai-provider-for-azure-ai-foundry' ),
				[ 'status' => 400 ]
			);
		}

		// Extract the resource root (scheme + host) for OpenAI-compatible probing.
		$parsed        = wp_parse_url( $endpoint );
		$resource_root = ( $parsed[ 'scheme' ] ?? 'https' ) . '://' . ( $parsed[ 'host' ] ?? '' );

		// 1. Try AI Foundry Model Inference /models/info (single-model endpoints).
		$result = self::probe_ai_foundry( $resource_root . '/models', $api_key, $api_version );

		// 2. Fall back to OpenAI-compatible models list at the resource root.
		if ( null === $result ) {
			$result = self::probe_openai_models( $resource_root, $api_key );
		}

		if ( null === $result ) {
			return new WP_Error(
				'detection_failed',
				__( 'Could not detect capabilities from the endpoint. Verify the URL and API key.', 'ai-provider-for-azure-ai-foundry' ),
				[ 'status' => 422 ]
			);
		}

		// Save detected results directly to the database.
		if ( ! empty( $result[ 'model_name' ] ) ) {
			update_option( ConnectorSettings::OPTION_MODEL_NAME, $result[ 'model_name' ] );
		}
		if ( ! empty( $result[ 'capabilities' ] ) ) {
			update_option( ConnectorSettings::OPTION_CAPABILITIES, $result[ 'capabilities' ] );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Probe the AI Foundry Model Inference /info endpoint.
	 *
	 * GET {endpoint}/info?api-version={version}
	 * Returns: { model_name, model_type, model_provider_name }
	 *
	 * @return array{model_name: string, capabilities: list<string>}|null
	 */
	private static function probe_ai_foundry( string $endpoint, string $api_key, string $api_version ): ?array {
		$url = $endpoint . '/info?api-version=' . rawurlencode( $api_version );

		$response = wp_remote_get( $url, [
			'headers' => [
				'api-key'      => $api_key,
				'Content-Type' => 'application/json',
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body[ 'model_name' ] ) ) {
			return null;
		}

		$capabilities = self::map_model_type( $body[ 'model_type' ] ?? '' );

		return [
			'model_name'   => sanitize_text_field( $body[ 'model_name' ] ),
			'capabilities' => $capabilities,
			'source'       => 'ai-foundry-info',
		];
	}

	/**
	 * Probe models via the OpenAI-compatible /openai/models endpoint.
	 *
	 * Works for Azure AI Services (*.services.ai.azure.com),
	 * Azure OpenAI (*.openai.azure.com), and Cognitive Services
	 * (*.cognitiveservices.azure.com) resources.
	 *
	 * Tries multiple API versions since different resource types
	 * support different versions.
	 *
	 * After collecting candidates from the catalog, each is verified
	 * by probing the actual deployment endpoint. Only deployments that
	 * respond (not 404 DeploymentNotFound) are included.
	 *
	 * @return array{model_name: string, capabilities: list<string>, source: string}|null
	 */
	private static function probe_openai_models( string $resource_root, string $api_key ): ?array {
		$api_versions = [ '2024-10-21', '2024-06-01', '2024-02-01', '2023-05-15' ];

		$body = null;
		foreach ( $api_versions as $ver ) {
			$url = $resource_root . '/openai/models?api-version=' . $ver;

			$response = wp_remote_get( $url, [
				'headers' => [
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
				],
				'timeout' => 15,
			] );

			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				break;
			}
		}

		if ( ! is_array( $body ) || empty( $body[ 'data' ] ) || ! is_array( $body[ 'data' ] ) ) {
			return null;
		}

		// Collect capability flags and candidate deployment names from catalog.
		$catalog_capabilities = [];
		$image_candidates     = [];
		$tts_candidates       = [];

		foreach ( $body[ 'data' ] as $model ) {
			if ( ! is_array( $model ) ) {
				continue;
			}

			$id = $model[ 'id' ] ?? '';
			if ( '' === $id ) {
				continue;
			}

			$caps = $model[ 'capabilities' ] ?? [];
			if ( ! is_array( $caps ) ) {
				continue;
			}

			$is_true  = static fn( $v ): bool => $v === true || $v === 'true';
			$id_lower = strtolower( $id );

			// Image model candidates.
			if (
				str_starts_with( $id_lower, 'gpt-image' )
				|| str_starts_with( $id_lower, 'dall-e' )
				|| $is_true( $caps[ 'image_generation' ] ?? false )
			) {
				$image_candidates[] = $id;
			}

			// TTS model candidates.
			if (
				str_starts_with( $id_lower, 'tts' )
				|| $is_true( $caps[ 'audio_text_to_speech' ] ?? false )
			) {
				$tts_candidates[] = $id;
			}

			// Track catalog-level capabilities for reference.
			if ( $is_true( $caps[ 'chat_completion' ] ?? false ) ) {
				$catalog_capabilities[ 'text_generation' ] = true;
				$catalog_capabilities[ 'chat_history' ]    = true;
			}
			if ( $is_true( $caps[ 'completion' ] ?? false ) ) {
				$catalog_capabilities[ 'text_generation' ] = true;
			}
			if ( $is_true( $caps[ 'embeddings' ] ?? false ) ) {
				$catalog_capabilities[ 'embedding_generation' ] = true;
			}
		}

		// ── Probe actual deployments ────────────────────────────
		// Only deployments that respond become confirmed.
		$confirmed_deployments  = [];
		$confirmed_capabilities = [];

		// 1. Probe text deployments (common names) — collect ALL that respond.
		if ( ! empty( $catalog_capabilities[ 'text_generation' ] ) ) {
			$text_deployments = self::probe_text_deployments( $resource_root, $api_key );
			foreach ( $text_deployments as $text ) {
				$confirmed_deployments[]                   = $text;
				$confirmed_capabilities[ 'text_generation' ] = true;
				$confirmed_capabilities[ 'chat_history' ]    = true;
			}
		}

		// 2. Probe ALL image deployment candidates.
		$image_candidates = array_unique( $image_candidates );
		foreach ( $image_candidates as $candidate ) {
			if ( self::probe_deployment_exists( $resource_root, $api_key, $candidate ) ) {
				$confirmed_deployments[]                    = $candidate;
				$confirmed_capabilities[ 'image_generation' ] = true;
			}
		}

		// 3. Probe ALL TTS deployment candidates.
		$tts_candidates = array_unique( $tts_candidates );
		foreach ( $tts_candidates as $candidate ) {
			if ( self::probe_deployment_exists( $resource_root, $api_key, $candidate ) ) {
				$confirmed_deployments[]                             = $candidate;
				$confirmed_capabilities[ 'text_to_speech_conversion' ] = true;
			}
		}

		// Include embedding capability if catalog reports it (no deployment probe needed;
		// embeddings are served via the Model Inference API, not a named deployment).
		if ( ! empty( $catalog_capabilities[ 'embedding_generation' ] ) ) {
			$confirmed_capabilities[ 'embedding_generation' ] = true;
		}

		if ( empty( $confirmed_capabilities ) ) {
			return null;
		}

		return [
			'model_name'   => implode( ', ', array_unique( $confirmed_deployments ) ),
			'capabilities' => array_keys( $confirmed_capabilities ),
			'source'       => 'openai-models',
		];
	}

	/**
	 * Probe for text model deployments by testing common names.
	 *
	 * Sends a minimal chat/completions request to check if each deployment exists.
	 * Returns all working deployment names.
	 *
	 * @return list<string> Working deployment names.
	 */
	private static function probe_text_deployments( string $resource_root, string $api_key ): array {
		$candidates  = [ 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano', 'gpt-4o', 'gpt-4o-mini', 'gpt-4' ];
		$api_version = AzureAiFoundryProvider::OPENAI_API_VERSION_DEFAULT;
		$found       = [];

		foreach ( $candidates as $name ) {
			$url = $resource_root
				. '/openai/deployments/' . rawurlencode( $name )
				. '/chat/completions?api-version=' . rawurlencode( $api_version );

			$response = wp_remote_post( $url, [
				'headers' => [
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode( [
					'messages'   => [ [ 'role' => 'user', 'content' => 'hi' ] ],
					'max_tokens' => 1,
				] ),
				'timeout' => 10,
			] );

			$code = wp_remote_retrieve_response_code( $response );
			if ( ! is_wp_error( $response ) && $code >= 200 && $code < 300 ) {
				$found[] = $name;
			}
		}

		return $found;
	}

	/**
	 * Check if a named deployment exists by POSTing to its chat/completions endpoint.
	 *
	 * Azure OpenAI returns different errors depending on whether the deployment exists:
	 *  - 404 DeploymentNotFound → not deployed
	 *  - 200, 400, 405, 429, etc. → deployed (exists, may reject the request body)
	 *
	 * We POST a minimal chat body. Even non-chat deployments (image, TTS) return
	 * a 400 "not supported" rather than 404 when the deployment exists.
	 *
	 * @param string $resource_root Base URL (scheme + host).
	 * @param string $api_key       API key.
	 * @param string $deployment    Deployment name to check.
	 * @return bool True if the deployment exists.
	 */
	private static function probe_deployment_exists( string $resource_root, string $api_key, string $deployment ): bool {
		$api_version = AzureAiFoundryProvider::OPENAI_API_VERSION_DEFAULT;
		$url         = $resource_root
			. '/openai/deployments/' . rawurlencode( $deployment )
			. '/chat/completions?api-version=' . rawurlencode( $api_version );

		$response = wp_remote_post( $url, [
			'headers' => [
				'api-key'      => $api_key,
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode( [
				'messages'   => [ [ 'role' => 'user', 'content' => 'test' ] ],
				'max_tokens' => 1,
			] ),
			'timeout' => 8,
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		// 404 means DeploymentNotFound — definitely not deployed.
		if ( 404 === $code ) {
			return false;
		}

		// Any other response (200, 400, 405, 429, etc.) means the deployment exists.
		return true;
	}

	/**
	 * Map an AI Foundry model_type string to capability values.
	 *
	 * @return list<string>
	 */
	private static function map_model_type( string $model_type ): array {
		return match ( $model_type ) {
			'chat-completion' => [ 'text_generation', 'chat_history' ],
			'embeddings'      => [ 'embedding_generation' ],
			default           => [ 'text_generation', 'chat_history' ],
		};
	}
}
