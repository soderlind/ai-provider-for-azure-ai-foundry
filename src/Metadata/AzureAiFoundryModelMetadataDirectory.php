<?php
/**
 * Azure AI Foundry Model Metadata Directory.
 *
 * Lists available models. Uses a user-configured model name, or falls back
 * to querying the /info endpoint, or a sensible default.
 *
 * The Azure AI Foundry Model Inference API supports multiple model types
 * (chat-completion, embeddings) but this plugin currently focuses on
 * chat completions (text generation).
 */

namespace AzureAiFoundry\Metadata;

use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use AzureAiFoundry\Settings\SettingsManager;

class AzureAiFoundryModelMetadataDirectory implements ModelMetadataDirectoryInterface {

	/** @var list<ModelMetadata>|null */
	private ?array $cached = null;

	/**
	 * List all model metadata entries.
	 *
	 * If a model name is configured in settings, use that. Otherwise try
	 * to query the /info endpoint to discover the deployed model.
	 *
	 * @return list<ModelMetadata>
	 */
	public function listModelMetadata(): array {
		if ( null !== $this->cached ) {
			return $this->cached;
		}

		$settings   = SettingsManager::instance();
		$model_name = $settings->get_model_name();

		if ( ! empty( $model_name ) ) {
			$names          = self::parseModelNames( $model_name );
			$text_name      = self::findDeploymentForType( $names, 'text' ) ?? $names[0];
			$image_name     = self::findDeploymentForType( $names, 'image' );
			$embedding_name = self::findDeploymentForType( $names, 'embedding' );
			$tts_name       = self::findDeploymentForType( $names, 'tts' );

			$this->cached = $this->buildModelsFromCapabilities(
				$text_name,
				$text_name,
				$image_name,
				$embedding_name,
				$tts_name
			);
			return $this->cached;
		}

		// Try to discover the model from the API.
		$discovered = $this->discoverModel();
		if ( ! empty( $discovered ) ) {
			$this->cached = $discovered;
			return $this->cached;
		}

		// Fall back to a generic entry.
		$this->cached = $this->buildModelsFromCapabilities(
			'azure-ai-foundry-model',
			__( 'Azure AI Foundry Model', 'azure-ai-foundry' )
		);

		return $this->cached;
	}

	/**
	 * Check if a model ID exists.
	 */
	public function hasModelMetadata( string $modelId ): bool {
		foreach ( $this->listModelMetadata() as $meta ) {
			if ( $meta->getId() === $modelId ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get metadata for a specific model.
	 *
	 * @throws InvalidArgumentException If the model ID is not found.
	 */
	public function getModelMetadata( string $modelId ): ModelMetadata {
		foreach ( $this->listModelMetadata() as $meta ) {
			if ( $meta->getId() === $modelId ) {
				return $meta;
			}
		}
		throw new InvalidArgumentException( 'Unknown model: ' . esc_html( $modelId ) );
	}

	/**
	 * Try to discover the deployed model via the /info endpoint.
	 *
	 * GET {endpoint}/info?api-version={version}
	 * Returns: { "model_name": "...", "model_type": "chat-completion|embeddings", "model_provider_name": "..." }
	 *
	 * @return list<ModelMetadata>|null
	 */
	private function discoverModel(): ?array {
		$settings    = SettingsManager::instance();
		$endpoint    = $settings->get_endpoint();
		$api_key     = $settings->get_real_api_key();
		$api_version = \AzureAiFoundry\Provider\AzureAiFoundryProvider::MODEL_INFERENCE_API_VERSION;

		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return null;
		}

		$url = rtrim( $endpoint, '/' ) . '/info?api-version=' . rawurlencode( $api_version );

		$response = wp_remote_get( $url, [
			'headers' => [
				'api-key'      => $api_key,
				'Content-Type' => 'application/json',
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['model_name'] ) ) {
			return null;
		}

		$model_id = sanitize_text_field( $body['model_name'] );

		return $this->buildModelsFromCapabilities( $model_id, $model_id );
	}

	/**
	 * Parse a potentially comma-separated model name string into individual names.
	 *
	 * @return list<string>
	 */
	public static function parseModelNames( string $model_name ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $model_name ) ) ) );
	}

	/**
	 * Find the best deployment name for a given capability type.
	 *
	 * Scans a list of model names and returns the one most likely to serve
	 * the requested type based on known prefixes.
	 *
	 * @param list<string> $names Model names.
	 * @param string       $type  'image' or 'text'.
	 * @return string|null The matching name, or null if none found.
	 */
	public static function findDeploymentForType( array $names, string $type ): ?string {
		// Ordered by preference — newer models first.
		$image_prefixes     = [ 'gpt-image', 'dall-e' ];
		$embedding_prefixes = [ 'text-embedding' ];
		$tts_prefixes       = [ 'tts' ];
		$skip_prefixes      = [ 'dall-e', 'gpt-image', 'tts', 'whisper', 'text-embedding' ];

		if ( 'image' === $type ) {
			foreach ( $image_prefixes as $prefix ) {
				foreach ( $names as $name ) {
					if ( str_starts_with( strtolower( $name ), $prefix ) ) {
						return $name;
					}
				}
			}
		} elseif ( 'embedding' === $type ) {
			foreach ( $embedding_prefixes as $prefix ) {
				foreach ( $names as $name ) {
					if ( str_starts_with( strtolower( $name ), $prefix ) ) {
						return $name;
					}
				}
			}
		} elseif ( 'tts' === $type ) {
			foreach ( $tts_prefixes as $prefix ) {
				foreach ( $names as $name ) {
					if ( str_starts_with( strtolower( $name ), $prefix ) ) {
						return $name;
					}
				}
			}
		} elseif ( 'text' === $type ) {
			foreach ( $names as $name ) {
				$lower = strtolower( $name );
				$is_non_text = false;
				foreach ( $skip_prefixes as $prefix ) {
					if ( str_starts_with( $lower, $prefix ) ) {
						$is_non_text = true;
						break;
					}
				}
				if ( ! $is_non_text ) {
					return $name;
				}
			}
		}

		return null;
	}

	/**
	 * Build one or more ModelMetadata entries from user-configured capabilities.
	 *
	 * Each distinct capability type (text, image, embedding, TTS) gets its own
	 * model entry so the provider can route each to the correct model class.
	 *
	 * @param string      $id             Model ID (deployment name) for text.
	 * @param string      $name           Display name.
	 * @param string|null $image_name     Deployment name override for image model.
	 * @param string|null $embedding_name Deployment name override for embedding model.
	 * @param string|null $tts_name       Deployment name override for TTS model.
	 * @return list<ModelMetadata>
	 */
	private function buildModelsFromCapabilities(
		string $id,
		string $name,
		?string $image_name = null,
		?string $embedding_name = null,
		?string $tts_name = null
	): array {
		$cap_strings = SettingsManager::instance()->get_capabilities();

		// Classify capabilities.
		$text_caps      = [];
		$image_caps     = [];
		$embedding_caps = [];
		$tts_caps       = [];
		$has_text       = false;
		$has_image      = false;
		$has_embedding  = false;
		$has_tts        = false;

		foreach ( $cap_strings as $cap ) {
			match ( $cap ) {
				'text_generation' => (function() use ( &$text_caps, &$has_text ) {
					$text_caps[] = CapabilityEnum::textGeneration();
					$has_text = true;
				})(),
				'chat_history' => $text_caps[] = CapabilityEnum::chatHistory(),
				'embedding_generation' => (function() use ( &$embedding_caps, &$has_embedding ) {
					$embedding_caps[] = CapabilityEnum::embeddingGeneration();
					$has_embedding = true;
				})(),
				'text_to_speech_conversion' => (function() use ( &$tts_caps, &$has_tts ) {
					$tts_caps[] = CapabilityEnum::textToSpeechConversion();
					$has_tts = true;
				})(),
				'image_generation' => (function() use ( &$image_caps, &$has_image ) {
					$image_caps[] = CapabilityEnum::imageGeneration();
					$has_image = true;
				})(),
				default => null,
			};
		}

		// Default to text generation + chat history if nothing selected.
		if ( ! $has_text && ! $has_image && ! $has_embedding && ! $has_tts ) {
			$text_caps[] = CapabilityEnum::textGeneration();
			$text_caps[] = CapabilityEnum::chatHistory();
			$has_text = true;
		}

		// Text generation models always support chat history.
		if ( $has_text ) {
			$has_chat = false;
			foreach ( $text_caps as $cap ) {
				if ( $cap->isChatHistory() ) {
					$has_chat = true;
					break;
				}
			}
			if ( ! $has_chat ) {
				$text_caps[] = CapabilityEnum::chatHistory();
			}
		}

		$models = [];

		if ( $has_text ) {
			$models[] = new ModelMetadata(
				$id,
				$name,
				$text_caps,
				$this->buildSupportedOptionsForCapabilities( $text_caps )
			);
		}

		if ( $has_image ) {
			$image_id    = $image_name ?? ( $has_text ? $id . '-image' : $id );
			$image_label = $image_name ?? $name;
			$models[]    = new ModelMetadata(
				$image_id,
				$image_label . ( $has_text ? ' (Image)' : '' ),
				$image_caps,
				$this->buildSupportedOptionsForCapabilities( $image_caps )
			);
		}

		if ( $has_embedding ) {
			$embed_id    = $embedding_name ?? $id . '-embedding';
			$embed_label = $embedding_name ?? $name;
			$models[]    = new ModelMetadata(
				$embed_id,
				$embed_label . ' (Embedding)',
				$embedding_caps,
				$this->buildSupportedOptionsForCapabilities( $embedding_caps )
			);
		}

		if ( $has_tts ) {
			$tts_id    = $tts_name ?? $id . '-tts';
			$tts_label = $tts_name ?? $name;
			$models[]  = new ModelMetadata(
				$tts_id,
				$tts_label . ' (TTS)',
				$tts_caps,
				$this->buildSupportedOptionsForCapabilities( $tts_caps )
			);
		}

		return $models;
	}

	/**
	 * Build supported options based on the active capabilities.
	 *
	 * Including outputModalities is critical — without it the SDK's
	 * PromptBuilder rejects the model.
	 *
	 * @param list<CapabilityEnum> $capabilities
	 * @return list<SupportedOption>
	 */
	private function buildSupportedOptionsForCapabilities( array $capabilities ): array {
		$options = [];

		$has_text      = false;
		$has_image     = false;
		$has_embedding = false;
		$has_tts       = false;
		foreach ( $capabilities as $cap ) {
			if ( $cap->isTextGeneration() ) {
				$has_text = true;
			}
			if ( $cap->isImageGeneration() ) {
				$has_image = true;
			}
			if ( $cap->isEmbeddingGeneration() ) {
				$has_embedding = true;
			}
			if ( $cap->isTextToSpeechConversion() ) {
				$has_tts = true;
			}
		}

		if ( $has_text ) {
			$options[] = new SupportedOption(
				OptionEnum::inputModalities(),
				[
					[ ModalityEnum::text() ],
					[ ModalityEnum::text(), ModalityEnum::image() ],
					[ ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio() ],
					[ ModalityEnum::text(), ModalityEnum::document() ],
					[ ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document() ],
				]
			);
			$options[] = new SupportedOption(
				OptionEnum::outputModalities(),
				[ [ ModalityEnum::text() ] ]
			);
			$options[] = new SupportedOption( OptionEnum::systemInstruction() );
			$options[] = new SupportedOption( OptionEnum::temperature() );
			$options[] = new SupportedOption( OptionEnum::maxTokens() );
			$options[] = new SupportedOption( OptionEnum::topP() );
			$options[] = new SupportedOption( OptionEnum::topK() );
			$options[] = new SupportedOption( OptionEnum::candidateCount() );
			$options[] = new SupportedOption( OptionEnum::stopSequences() );
			$options[] = new SupportedOption( OptionEnum::presencePenalty() );
			$options[] = new SupportedOption( OptionEnum::frequencyPenalty() );
			$options[] = new SupportedOption( OptionEnum::logprobs() );
			$options[] = new SupportedOption( OptionEnum::topLogprobs() );
			$options[] = new SupportedOption( OptionEnum::outputMimeType(), [ 'text/plain', 'application/json' ] );
			$options[] = new SupportedOption( OptionEnum::outputSchema() );
			$options[] = new SupportedOption( OptionEnum::functionDeclarations() );
			$options[] = new SupportedOption( OptionEnum::webSearch() );
			$options[] = new SupportedOption( OptionEnum::customOptions() );
		}

		if ( $has_image && ! $has_text ) {
			$options[] = new SupportedOption(
				OptionEnum::inputModalities(),
				[ [ ModalityEnum::text() ] ]
			);
			$options[] = new SupportedOption(
				OptionEnum::outputModalities(),
				[ [ ModalityEnum::image() ] ]
			);
			$options[] = new SupportedOption( OptionEnum::outputFileType() );
			$options[] = new SupportedOption( OptionEnum::customOptions() );
		}

		if ( $has_embedding ) {
			$options[] = new SupportedOption(
				OptionEnum::inputModalities(),
				[ [ ModalityEnum::text() ] ]
			);
			$options[] = new SupportedOption( OptionEnum::outputMimeType(), [ 'application/json', 'application/base64' ] );
			$options[] = new SupportedOption( OptionEnum::customOptions() );
		}

		if ( $has_tts ) {
			$options[] = new SupportedOption(
				OptionEnum::inputModalities(),
				[ [ ModalityEnum::text() ] ]
			);
			$options[] = new SupportedOption(
				OptionEnum::outputModalities(),
				[ [ ModalityEnum::audio() ] ]
			);
			$options[] = new SupportedOption( OptionEnum::customOptions() );
		}

		return $options;
	}
}
