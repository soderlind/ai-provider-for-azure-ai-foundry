<?php
/**
 * Azure AI Foundry Embedding Model.
 *
 * Uses the Azure OpenAI API surface for embedding generation:
 *   POST {resource}/openai/deployments/{deployment}/embeddings
 *        ?api-version={configured_version}
 *
 * Note: The WP AI Client does not yet provide an EmbeddingGenerationModelInterface.
 * Once the upstream library adds the interface, this class should implement it.
 */

namespace AzureAiFoundry\Models;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use AzureAiFoundry\Provider\AzureAiFoundryProvider;

class AzureAiFoundryEmbeddingModel extends AbstractApiBasedModel {

	/**
	 * Get the deployment name from model metadata.
	 */
	protected function getDeploymentId(): string {
		return $this->metadata()->getId();
	}

	/**
	 * Generate embeddings for the provided input.
	 *
	 * @param string|array<string> $input The text(s) to generate embeddings for.
	 * @return GenerativeAiResult The generation result containing embeddings.
	 */
	final public function generateEmbeddingResult( $input ): GenerativeAiResult {
		$http_transporter = $this->getHttpTransporter();
		$params           = $this->prepareEmbeddingParams( $input );

		$resource_root = AzureAiFoundryProvider::resourceRootUrl();
		$deployment    = $this->getDeploymentId();
		$api_version   = AzureAiFoundryProvider::openAiApiVersion();

		$url = $resource_root
			. '/openai/deployments/' . rawurlencode( $deployment )
			. '/embeddings'
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
	 * Prepare the parameters for the embeddings request.
	 *
	 * @param string|array<string> $input The text(s) to embed.
	 * @return array The request parameters.
	 */
	protected function prepareEmbeddingParams( $input ): array {
		$params = [
			'input' => $input,
		];

		$config = $this->getConfig();

		$output_mime = $config->getOutputMimeType();
		if ( 'application/base64' === $output_mime ) {
			$params['encoding_format'] = 'base64';
		} else {
			$params['encoding_format'] = 'float';
		}

		return $params;
	}

	/**
	 * Parse the API response into a GenerativeAiResult.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response The API response.
	 * @return GenerativeAiResult The parsed result.
	 */
	protected function parseResponseToGenerativeAiResult( $response ): GenerativeAiResult {
		$data = $response->getData();

		$embeddings = [];

		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			foreach ( $data['data'] as $embedding_data ) {
				$embeddings[] = [
					'index'     => $embedding_data['index'] ?? 0,
					'embedding' => $embedding_data['embedding'] ?? [],
				];
			}
		}

		$prompt_tokens     = 0;
		$completion_tokens = 0;
		$total_tokens      = 0;
		if ( isset( $data['usage'] ) ) {
			$prompt_tokens = $data['usage']['prompt_tokens'] ?? 0;
			$total_tokens  = $data['usage']['total_tokens'] ?? 0;
		}

		$embedding_json = wp_json_encode( $embeddings );
		$message        = new Message(
			MessageRoleEnum::model(),
			[ new MessagePart( $embedding_json ?: '[]' ) ]
		);
		$candidate = new Candidate( $message, FinishReasonEnum::stop() );

		return new GenerativeAiResult(
			'',
			[ $candidate ],
			new TokenUsage( $prompt_tokens, $completion_tokens, $total_tokens ),
			$this->providerMetadata(),
			$this->metadata(),
			[
				'embeddings' => $embeddings,
				'model'      => $data['model'] ?? '',
			]
		);
	}
}
