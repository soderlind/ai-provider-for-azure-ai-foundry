<?php
/**
 * Azure AI Foundry Provider.
 *
 * Registers the provider with the WordPress AI Client SDK.
 *
 * Two API surfaces on the same *.services.ai.azure.com resource:
 *
 * Model Inference API (text, embeddings, info):
 *   Base:  https://{resource}.services.ai.azure.com/models
 *   Chat:  POST /chat/completions?api-version=2024-05-01-preview  (fixed)
 *   Embed: POST /embeddings?api-version=2024-05-01-preview        (fixed)
 *   Info:  GET  /info?api-version=2024-05-01-preview               (fixed)
 *
 * Azure OpenAI API (image generation):
 *   Base:  https://{resource}.services.ai.azure.com/openai/deployments/{model}
 *   Image: POST /images/generations?api-version={user-configured}
 */

namespace AzureAiFoundry\Provider;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use AzureAiFoundry\Metadata\AzureAiFoundryModelMetadataDirectory;
use AzureAiFoundry\Models\AzureAiFoundryTextGenerationModel;
use AzureAiFoundry\Models\AzureAiFoundryImageGenerationModel;
use AzureAiFoundry\Models\AzureAiFoundryEmbeddingModel;
use AzureAiFoundry\Models\AzureAiFoundryTextToSpeechModel;
use AzureAiFoundry\Settings\SettingsManager;

class AzureAiFoundryProvider extends AbstractApiProvider {

	/**
	 * API version for the Model Inference API surface.
	 *
	 * This is the only version supported by /models/* endpoints.
	 */
	public const string MODEL_INFERENCE_API_VERSION = '2024-05-01-preview';

	/**
	 * Default API version for the Azure OpenAI API surface.
	 *
	 * Used for /openai/deployments/* endpoints (image generation, etc.).
	 */
	public const string OPENAI_API_VERSION_DEFAULT = '2025-04-01-preview';

	/**
	 * Base URL for the Azure AI Foundry Model Inference API.
	 *
	 * The user-configured endpoint already includes `/models` where needed.
	 * Example: https://my-resource.services.ai.azure.com/models
	 */
	protected static function baseUrl(): string {
		$endpoint = SettingsManager::instance()->get_endpoint();
		return rtrim( $endpoint, '/' );
	}

	/**
	 * Build a fully qualified URL for an API path (Model Inference API).
	 *
	 * @param string      $path        Relative path (e.g. 'chat/completions').
	 * @param string|null $api_version Optional API version override.
	 * @return string Full URL with api-version query parameter.
	 */
	public static function apiUrl( string $path, ?string $api_version = null ): string {
		$base        = static::baseUrl();
		$api_version ??= self::MODEL_INFERENCE_API_VERSION;

		return $base . '/' . ltrim( $path, '/' ) . '?api-version=' . rawurlencode( $api_version );
	}

	/**
	 * Get the API version for the Azure OpenAI API surface.
	 *
	 * Returns the hardcoded default — API versions are managed by the plugin,
	 * not user-configurable.
	 *
	 * @return string e.g. '2025-04-01-preview'
	 */
	public static function openAiApiVersion(): string {
		return self::OPENAI_API_VERSION_DEFAULT;
	}

	/**
	 * Derive the resource root URL (scheme + host) from the configured endpoint.
	 *
	 * Strips any path (e.g. /models) so the caller can build Azure OpenAI
	 * deployment URLs: {root}/openai/deployments/{model}/{path}
	 *
	 * @return string e.g. https://my-resource.services.ai.azure.com
	 */
	public static function resourceRootUrl(): string {
		$base   = static::baseUrl();
		$parsed = wp_parse_url( $base );

		return ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );
	}

	/**
	 * Create a model instance based on its capabilities.
	 *
	 * Routes to the appropriate model class based on the primary capability.
	 */
	protected static function createModel(
		ModelMetadata $model_metadata,
		ProviderMetadata $provider_metadata
	): ModelInterface {
		$capabilities = $model_metadata->getSupportedCapabilities();

		foreach ( $capabilities as $capability ) {
			if ( $capability->isImageGeneration() ) {
				return new AzureAiFoundryImageGenerationModel( $model_metadata, $provider_metadata );
			}
			if ( $capability->isEmbeddingGeneration() ) {
				return new AzureAiFoundryEmbeddingModel( $model_metadata, $provider_metadata );
			}
			if ( $capability->isTextToSpeechConversion() ) {
				return new AzureAiFoundryTextToSpeechModel( $model_metadata, $provider_metadata );
			}
		}

		// Default: text generation (also handles chat history).
		return new AzureAiFoundryTextGenerationModel( $model_metadata, $provider_metadata );
	}

	/**
	 * Metadata that identifies this provider in the registry.
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			'azure-ai-foundry',
			__( 'Azure AI Foundry', 'azure-ai-foundry' ),
			ProviderTypeEnum::cloud(),
			'https://ai.azure.com/',
			RequestAuthenticationMethod::apiKey()
		);
	}

	/**
	 * How the SDK checks if the provider is reachable.
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new ListModelsApiBasedProviderAvailability(
			static::modelMetadataDirectory()
		);
	}

	/**
	 * Factory for the model metadata directory.
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new AzureAiFoundryModelMetadataDirectory();
	}
}
