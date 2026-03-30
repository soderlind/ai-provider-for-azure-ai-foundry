# Azure AI Foundry Connector for WordPress

Connect WordPress 7.0+ to [Azure AI Foundry](https://learn.microsoft.com/en-us/rest/api/aifoundry/modelinference/) for text generation, image generation, embeddings, and more.

<img width="100%" alt="Screenshot 2026-03-30 at 12 34 57" src="https://github.com/user-attachments/assets/95721087-13c0-4f18-ad10-311ef89a2832" />

## Features

- **AI Client integration** — registers as a WordPress 7.0 AI provider, usable via `wp_ai_client_prompt()` and Settings → Connectors.
- **OpenAI-compatible** — uses the Azure AI Foundry `/chat/completions` endpoint which follows the OpenAI chat format.
- **Capability detection** — auto-detects deployed models and capabilities (text generation, chat history, image generation, embeddings, text-to-speech) by probing the Azure endpoint.
- **Multiple endpoint types** — supports Azure AI Services (`.services.ai.azure.com`), Azure OpenAI (`.openai.azure.com`), and Cognitive Services (`.cognitiveservices.azure.com`).
- **Auto-detection** — discovers all deployed models via POST-based probing. No manual model name or API version configuration needed.
- **Custom authentication** — sends the `api-key` header required by Azure (instead of `Authorization: Bearer`).
- **Endpoint validation** — validates Azure endpoint URLs and shows inline errors for invalid URLs.
- **Environment variable fallback** — every setting can be overridden via environment variables or `wp-config.php` constants.
- **Connectors page UI** — custom React-based connector on the Settings → Connectors page with fields for API key and endpoint URL. Detected deployments and capabilities displayed as read-only chips.

## Requirements

- WordPress 7.0 or later
- PHP 8.3+
- An [Azure AI Foundry](https://ai.azure.com/) resource with a deployed model

## Installation

1. Upload the `azure-ai-foundry` folder to `wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Go to **Settings → Connectors** and configure:
   - **API Key** — your Azure AI Foundry API key.
   - **Endpoint URL** — e.g. `https://my-resource.services.ai.azure.com/models`.
4. Click **Connect & Detect** — the plugin probes your endpoint, discovers deployed models, and saves the configuration automatically.

## Configuration via Environment Variables

Settings can also be provided via environment variables or constants in `wp-config.php`:

| Setting      | Environment Variable              | wp-config.php Constant            |
|--------------|-----------------------------------|-----------------------------------|
| API Key      | `AZURE_AI_FOUNDRY_API_KEY`        | `AZURE_AI_FOUNDRY_API_KEY`        |
| Endpoint     | `AZURE_AI_FOUNDRY_ENDPOINT`       | `AZURE_AI_FOUNDRY_ENDPOINT`       |
| Model Names  | `AZURE_AI_FOUNDRY_MODEL`          | `AZURE_AI_FOUNDRY_MODEL`          |
| Capabilities | `AZURE_AI_FOUNDRY_CAPABILITIES`   | `AZURE_AI_FOUNDRY_CAPABILITIES`   |

Model names and capabilities are normally auto-detected. Use these overrides only when you need to pin specific values. Model names accept comma-separated deployment names, e.g. `gpt-4.1,gpt-image-1`. Capabilities accept a comma-separated string, e.g. `text_generation,chat_history,image_generation`.

## Usage

Once configured, the provider is available to any code using the WordPress AI Client:

```php
use WordPress\AiClient\AiClient;

$result = AiClient::prompt( 'Explain gravity in one sentence.' )
    ->usingProvider( 'azure-ai-foundry' )
    ->generateTextResult();

echo $result->getText();
```

## Development

### Build

```bash
npm install
npm run build       # Production build
npm run start       # Watch mode
```

### Test

```bash
npm run test        # Run Vitest tests
npm run test:watch  # Interactive watch mode
```

### Plugin Structure

```
azure-ai-foundry/
├── azure-ai-foundry.php              ← Main plugin file
├── src/
│   ├── autoload.php                  ← PSR-4 autoloader
│   ├── Provider/                     ← AI Client provider
│   ├── Models/                       ← Text generation model
│   ├── Metadata/                     ← Model metadata & capabilities
│   ├── Http/                         ← api-key authentication
│   ├── Rest/                         ← REST API (capability detection)
│   ├── Settings/                     ← Connector settings + manager
│   └── js/connectors.js             ← Connectors page UI (source)
├── build/connectors.js               ← Compiled ESM module
├── tests/js/                         ← Vitest tests
├── webpack.config.js                 ← ESM output config
└── vitest.config.js                  ← Test config
```

## License

GPL-2.0-or-later
