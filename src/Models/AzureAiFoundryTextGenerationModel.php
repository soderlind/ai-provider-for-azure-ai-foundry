<?php
/**
 * Azure AI Foundry Text Generation Model.
 *
 * The Azure AI Foundry Model Inference API uses an OpenAI-compatible
 * chat/completions format. We extend the SDK's abstract class and
 * override only createRequest() to target the Foundry endpoint.
 */

namespace AzureAiFoundry\Models;

use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use AzureAiFoundry\Provider\AzureAiFoundryProvider;

class AzureAiFoundryTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {

	/**
	 * Override the request to target an Azure OpenAI deployment.
	 *
	 * Routes through the Azure OpenAI API surface:
	 *   {resource_root}/openai/deployments/{deployment}/{path}?api-version=...
	 *
	 * The deployment name comes from model metadata (resolved by the
	 * metadata directory from the comma-separated model list).
	 *
	 * @param HttpMethodEnum $method  The HTTP method.
	 * @param string         $path    The API path (e.g. 'chat/completions').
	 * @param array          $headers Optional headers.
	 * @param mixed          $data    Optional request data.
	 * @return Request The request object.
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = [], $data = null ): Request {
		$resource_root = AzureAiFoundryProvider::resourceRootUrl();
		$deployment    = $this->metadata()->getId();
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
