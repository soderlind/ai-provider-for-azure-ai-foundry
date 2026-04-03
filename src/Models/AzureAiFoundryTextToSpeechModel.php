<?php
/**
 * Azure AI Foundry Text-to-Speech Model.
 *
 * Uses the Azure OpenAI API surface for TTS:
 *   POST {resource}/openai/deployments/{deployment}/audio/speech
 *        ?api-version={configured_version}
 *
 * Supports TTS models such as tts-1 and tts-1-hd.
 */

namespace AzureAiFoundry\Models;

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use AzureAiFoundry\Provider\AzureAiFoundryProvider;

class AzureAiFoundryTextToSpeechModel extends AbstractApiBasedModel implements TextToSpeechConversionModelInterface {

	const DEFAULT_VOICE           = 'alloy';
	const DEFAULT_RESPONSE_FORMAT = 'mp3';
	const AVAILABLE_VOICES        = [ 'alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer' ];

	/**
	 * Get the deployment name from model metadata.
	 */
	protected function getDeploymentId(): string {
		return $this->metadata()->getId();
	}

	/**
	 * Convert text to speech.
	 *
	 * @param array $prompt Array of messages containing the text to convert.
	 * @return GenerativeAiResult Result containing generated speech audio.
	 */
	final public function convertTextToSpeechResult( array $prompt ): GenerativeAiResult {
		$http_transporter = $this->getHttpTransporter();
		$text             = $this->extractTextFromPrompt( $prompt );
		$params           = $this->prepareTtsParams( $text );

		$resource_root = AzureAiFoundryProvider::resourceRootUrl();
		$deployment    = $this->getDeploymentId();
		$api_version   = AzureAiFoundryProvider::openAiApiVersion();

		$url = $resource_root
			. '/openai/deployments/' . rawurlencode( $deployment )
			. '/audio/speech'
			. '?api-version=' . rawurlencode( $api_version );

		$request = new Request(
			HttpMethodEnum::POST(),
			$url,
			[ 'Content-Type' => 'application/json' ],
			$params,
			$this->getRequestOptions()
		);

		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $http_transporter->send( $request );

		ResponseUtil::throwIfNotSuccessful( $response );

		return $this->parseResponseToGenerativeAiResult( $response );
	}

	/**
	 * Extract text content from the prompt messages.
	 *
	 * @param array $prompt The prompt messages.
	 * @return string The concatenated text content.
	 */
	protected function extractTextFromPrompt( array $prompt ): string {
		$texts = [];

		foreach ( $prompt as $item ) {
			if ( is_string( $item ) ) {
				$texts[] = $item;
			} elseif ( is_object( $item ) && method_exists( $item, 'getParts' ) ) {
				foreach ( $item->getParts() as $part ) {
					if ( method_exists( $part, 'getText' ) ) {
						$text = $part->getText();
						if ( ! empty( $text ) ) {
							$texts[] = $text;
						}
					}
				}
			} elseif ( is_array( $item ) && isset( $item['content'] ) ) {
				$texts[] = $item['content'];
			}
		}

		return implode( ' ', $texts );
	}

	/**
	 * Prepare the parameters for the TTS request.
	 *
	 * @param string $text The text to convert to speech.
	 * @return array The request parameters.
	 */
	protected function prepareTtsParams( string $text ): array {
		$config = $this->getConfig();

		$params = [
			'model' => $this->metadata()->getId(),
			'input' => $text,
			'voice' => self::DEFAULT_VOICE,
		];

		$voice = $config->getOutputSpeechVoice();
		if ( ! empty( $voice ) && in_array( $voice, self::AVAILABLE_VOICES, true ) ) {
			$params['voice'] = $voice;
		}

		$custom = $config->getCustomOptions();

		if ( isset( $custom['response_format'] ) ) {
			$params['response_format'] = sanitize_text_field( $custom['response_format'] );
		} else {
			$params['response_format'] = self::DEFAULT_RESPONSE_FORMAT;
		}

		if ( isset( $custom['speed'] ) ) {
			$speed = (float) $custom['speed'];
			if ( $speed >= 0.25 && $speed <= 4.0 ) {
				$params['speed'] = $speed;
			}
		}

		return $params;
	}

	/**
	 * Parse the API response into a GenerativeAiResult.
	 *
	 * The TTS API returns raw audio bytes, not JSON.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response The API response.
	 * @return GenerativeAiResult The parsed result.
	 */
	protected function parseResponseToGenerativeAiResult( $response ): GenerativeAiResult {
		// The audio/speech endpoint returns raw audio binary, not JSON.
		// Use getBody() instead of getData() to get raw bytes.
		$body = $response->getBody();

		$audio_data = '';
		if ( is_string( $body ) && '' !== $body ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding audio binary for transport.
			$audio_data = base64_encode( $body );
		}

		$data_uri  = 'data:audio/' . self::DEFAULT_RESPONSE_FORMAT . ';base64,' . $audio_data;
		$file      = new File( $data_uri, 'audio/' . self::DEFAULT_RESPONSE_FORMAT );
		$message   = new Message(
			MessageRoleEnum::model(),
			[ new MessagePart( $file ) ]
		);
		$candidate = new Candidate( $message, FinishReasonEnum::stop() );

		return new GenerativeAiResult(
			'',
			[ $candidate ],
			new TokenUsage( 0, 0, 0 ),
			$this->providerMetadata(),
			$this->metadata(),
			[
				'response_format' => self::DEFAULT_RESPONSE_FORMAT,
			]
		);
	}
}
