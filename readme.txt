=== Azure AI Foundry Connector ===
Contributors: suspended
Tags: ai, azure, foundry, ai-provider, connectors
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to Azure AI Foundry for text generation, image generation, embeddings, and more.

== Description ==

Azure AI Foundry Connector registers an AI provider with the WordPress 7.0 AI Client, enabling text generation, image generation, embeddings, and text-to-speech via the [Azure AI Foundry Model Inference API](https://learn.microsoft.com/en-us/rest/api/aifoundry/modelinference/).

= Features =

* **AI Client integration** — usable via `wp_ai_client_prompt()` and the Settings → Connectors page.
* **OpenAI-compatible** — uses the Azure AI Foundry `/chat/completions` endpoint.
* **Capability detection** — auto-detects deployed models and capabilities (text generation, chat history, image generation, embeddings, text-to-speech) by probing the Azure endpoint.
* **Multiple endpoint types** — supports Azure AI Services, Azure OpenAI, and Cognitive Services endpoints.
* **Auto-detection** — discovers all deployed models via POST-based probing. No manual model name or API version configuration needed.
* **Custom authentication** — sends the `api-key` header required by Azure.
* **Endpoint validation** — validates Azure endpoint URLs with inline error messages.
* **Environment variable fallback** — every setting can be overridden via environment variables or `wp-config.php` constants.
* **Connectors page UI** — custom connector with fields for API key and endpoint URL. Detected deployments and capabilities displayed as read-only chips.

= Requirements =

* WordPress 7.0 or later
* PHP 8.3+
* An Azure AI Foundry resource with a deployed model

== Installation ==

1. Upload the `azure-ai-foundry` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Settings → Connectors**.
4. Find "Azure AI Foundry" and click **Set Up**.
5. Enter your API Key and Endpoint URL.
6. Click **Connect & Detect** — the plugin probes your endpoint, discovers deployed models, and saves the configuration automatically.

== Frequently Asked Questions ==

= Where do I get an API key? =

Sign in to [Azure AI Foundry](https://ai.azure.com/) and create or select a project. Deploy a model and copy the API key from the endpoint details.

= What is the Endpoint URL? =

The plugin supports three Azure endpoint types:

* `https://{resource}.services.ai.azure.com/api/projects/PROJECT-NAME` (Azure AI Services)
* `https://{resource}.openai.azure.com` (Azure OpenAI)
* `https://{resource}.cognitiveservices.azure.com` (Cognitive Services)

You can find your endpoint in the Azure AI Foundry portal under your model deployment.

= Do I need to specify a model name? =

No. The plugin auto-detects all deployed models by probing your Azure endpoint. Model names and capabilities are saved automatically when you click "Connect & Detect".

= Can I configure settings without the admin UI? =

Yes. Set any of these environment variables or define them as constants in `wp-config.php`:

* `AZURE_AI_FOUNDRY_API_KEY`
* `AZURE_AI_FOUNDRY_ENDPOINT`
* `AZURE_AI_FOUNDRY_MODEL` (comma-separated deployment names)
* `AZURE_AI_FOUNDRY_CAPABILITIES` (comma-separated, e.g. `text_generation,chat_history`)

= What capabilities are supported? =

The plugin supports five capabilities: `text_generation`, `chat_history`, `image_generation`, `embedding_generation`, and `text_to_speech_conversion`. By default, `text_generation` and `chat_history` are enabled. Use "Connect & Detect" to discover capabilities automatically.

= What API version is used? =

The plugin uses `2025-04-01-preview` for the Azure OpenAI surface. This is hardcoded and not user-configurable.

== Changelog ==

= 1.0.0 =
* Added embedding generation model — supports text-embedding-ada-002, text-embedding-3-small/large deployments.
* Added text-to-speech model — supports tts-1 and tts-1-hd deployments.
* Auto-detection now resolves separate deployment names for text, image, embedding, and TTS models.
* Added audio input modality for text generation models (vision + audio support).
* Added topK supported option for text generation models.
* Added AI client conflict detection — warns when the "AI Experiments" plugin overrides the built-in AI client.
* Fixed TTS response parsing — uses raw body instead of JSON-decoded data to correctly capture audio binary output.

= 0.3.3 =
* Fixed the Connect & Detect flow so it saves the API key and endpoint before deployment detection.
* Fixed WordPress AI plugin compatibility so configured Azure AI Foundry credentials are recognized as a valid AI Connector.

= 0.3.2 =
* Escaped output in exception message (WordPress Plugin Check compliance).
* Added direct file access protection to autoload.php and ConnectorSettings.php.

= 0.3.1 =
* Fixed connector description to list all supported capabilities instead of only text generation.

= 0.3.0 =
* Simplified settings to API Key and Endpoint URL only — model names and capabilities are auto-detected.
* "Connect & Detect" probes deployments via POST and saves detected configuration directly.
* Detects all deployed models (text and image), not just the first match.
* Added candidateCount support for title generation.
* Removed API Version and Model Name input fields.
* Removed capability checkboxes (auto-detected as read-only chips).

= 0.2.0 =
* Capabilities setting with 5 options: text generation, chat history, image generation, embedding generation, text-to-speech.
* "Detect from Endpoint" button auto-detects capabilities from Azure endpoint.
* Endpoint URL validation with inline error message.
* Capabilities displayed as read-only chips.
* Save Settings button shows loading spinner and success/error feedback.

= 0.1.0 =
* Initial release.
* AI Client provider for Azure AI Foundry Model Inference API.
* Text generation via OpenAI-compatible chat/completions endpoint.
* Auto-detection of deployed model via /info endpoint.
* Custom api-key header authentication.
* Connectors page UI with API key, endpoint, model name, and API version fields.
* Environment variable and wp-config.php constant fallbacks.
* API key masking in REST API responses.
