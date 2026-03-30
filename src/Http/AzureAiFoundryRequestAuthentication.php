<?php
/**
 * Azure AI Foundry Request Authentication.
 *
 * Azure AI Foundry Model Inference API uses the `api-key` header
 * instead of the standard `Authorization: Bearer` header.
 *
 * Must extend ApiKeyRequestAuthentication (not just implement the interface)
 * because the ProviderRegistry validates instanceof the class returned by
 * RequestAuthenticationMethod::apiKey()->getImplementationClass().
 */

namespace AzureAiFoundry\Http;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

class AzureAiFoundryRequestAuthentication extends ApiKeyRequestAuthentication {

	/**
	 * Authenticate the request with the Azure `api-key` header.
	 *
	 * @param Request $request The outgoing request.
	 * @return Request The request with authentication header added.
	 */
	public function authenticateRequest( Request $request ): Request {
		return $request->withHeader( 'api-key', $this->getApiKey() );
	}
}
