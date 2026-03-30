<?php
/**
 * Azure AI Foundry Image Generation Model.
 *
 * The Model Inference API (*.services.ai.azure.com/models) does NOT support
 * /images/generations. Image generation must go through the Azure OpenAI API
 * surface on the same resource:
 *
 *   POST {resource}/openai/deployments/{deployment}/images/generations
 *        ?api-version={configured_version}
 *
 * The deployment name comes from model metadata, which the metadata directory
 * resolves from the comma-separated model list (e.g. extracting "gpt-image-1"
 * from "dall-e-3-3.0, gpt-image-1, gpt-4.1").
 */

namespace AzureAiFoundry\Models;

use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use AzureAiFoundry\Provider\AzureAiFoundryProvider;

class AzureAiFoundryImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel {

	/**
	 * Resolve the deployment name to use in the Azure OpenAI URL.
	 *
	 * Uses the model metadata ID which is set to the actual image model
	 * deployment name (e.g. "gpt-image-1") by the metadata directory.
	 *
	 * @return string The deployment name.
	 */
	protected function getDeploymentId(): string {
		return $this->metadata()->getId();
	}

	/**
	 * Prepares the parameters for the image generation API request.
	 *
	 * Removes parameters that Azure OpenAI deployments reject:
	 * - response_format: gpt-image-1 rejects it with 400.
	 * - model: Azure routes via deployment URL, not a body param.
	 *
	 * @param array $prompt The prompt messages.
	 * @return array The parameters for the API request.
	 */
	protected function prepareGenerateImageParams( array $prompt ): array {
		$params = parent::prepareGenerateImageParams( $prompt );

		unset( $params['model'], $params['response_format'] );

		return $params;
	}

	/**
	 * Create a request targeting the Azure OpenAI deployment endpoint.
	 *
	 * The Model Inference API does not expose /images/generations, so we
	 * route through the Azure OpenAI API surface instead:
	 *   {resource_root}/openai/deployments/{deployment}/{path}?api-version=...
	 *
	 * @param HttpMethodEnum $method  The HTTP method.
	 * @param string         $path    The API path (e.g. 'images/generations').
	 * @param array          $headers Optional headers.
	 * @param mixed          $data    Optional request data.
	 * @return Request The request object.
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = [], $data = null ): Request {
		$resource_root = AzureAiFoundryProvider::resourceRootUrl();
		$deployment    = $this->getDeploymentId();
		$api_version   = AzureAiFoundryProvider::openAiApiVersion();

		$url = $resource_root
			. '/openai/deployments/' . rawurlencode( $deployment )
			. '/' . ltrim( $path, '/' )
			. '?api-version=' . rawurlencode( $api_version );

		return new Request(
			$method,
			$url,
			$headers,
			$data,
			$this->getRequestOptions()
		);
	}
}
