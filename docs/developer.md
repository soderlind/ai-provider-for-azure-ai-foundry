# Developer Guide: Using `wp_ai_client_prompt()`

This guide demonstrates how to use the WordPress 7.0 AI Client API with the Azure AI Foundry provider. The examples also apply to any provider registered with `Settings->Connectors`, such as **OpenAI**, **Anthropic**, or **Google Gemini**.

>Obvious note: The provider must have the relevant capabilities (e.g. text generation, image generation) for the requested operation.

---

## Quick Start

```php
// Basic text generation
$text = wp_ai_client_prompt( 'Explain quantum entanglement.' )->generate_text();
```

---

## PromptBuilder Methods

The `wp_ai_client_prompt()` function returns a `PromptBuilder` instance with fluent methods for customizing AI requests.

### Provider Selection

#### `using_provider( string $provider_id )`

Force a specific provider instead of automatic selection:

```php
use WordPress\AiClient\AiClient;

// Using the PromptBuilder helper function
$result = wp_ai_client_prompt( 'Write a haiku about code.' )
    ->using_provider( 'azure-ai-foundry' )
    ->generate_text();

// Or using the AiClient class directly
$result = AiClient::prompt( 'Write a haiku about code.' )
    ->usingProvider( 'azure-ai-foundry' )
    ->generateTextResult();

echo $result->getText();
```

**Provider IDs:**
- `azure-ai-foundry` — Azure AI Foundry (this plugin)
- `openai` — OpenAI
- `anthropic` — Anthropic Claude
- `google` — Google Gemini

### Model Selection

#### `using_model( string $model_id )`

Request a specific model:

```php
$text = wp_ai_client_prompt( 'Summarize this article.' )
    ->using_provider( 'azure-ai-foundry' )
    ->using_model( 'gpt-4.1' )  // Your deployed model name
    ->generate_text();
```

#### `using_model_preference( array $preferences )`

Provide an ordered list of preferred models:

```php
$text = wp_ai_client_prompt( 'Generate a creative story.' )
    ->using_model_preference( [
        [ 'azure-ai-foundry', 'gpt-4.1' ],
        [ 'azure-ai-foundry', 'gpt-4o' ],
        [ 'openai', 'gpt-4-turbo' ],
    ] )
    ->generate_text();
```

The PromptBuilder tries models in order until one succeeds.

---

## Generation Methods

### Text Generation

```php
// Simple text (string)
$text = wp_ai_client_prompt( 'What is machine learning?' )->generate_text();

// Full result object with metadata
$result = wp_ai_client_prompt( 'What is machine learning?' )->generate_text_result();

echo $result->getText();
echo $result->getTokenUsage()->getTotalTokens();
```

### Image Generation

```php
// Generate image (returns File object)
$image = wp_ai_client_prompt( 'A cyberpunk city at sunset' )->generate_image();

// Full result with metadata
$result = wp_ai_client_prompt( 'A cyberpunk city at sunset' )->generate_image_result();

$file = $result->getFile();
echo $file->getMimeType(); // image/png
$data = $file->getDataUri(); // data:image/png;base64,...
```

### Text-to-Speech

```php
// Convert text to speech (returns File object)
$audio = wp_ai_client_prompt( 'Hello, welcome to WordPress.' )->convert_text_to_speech();

// Full result with metadata
$result = wp_ai_client_prompt( 'Hello, welcome to WordPress.' )->convert_text_to_speech_result();

$file = $result->getFile();
echo $file->getMimeType(); // audio/mp3
```

### Embeddings

```php
$result = wp_ai_client_prompt( 'The quick brown fox.' )->generate_embedding_result();

$embeddings = $result->getEmbeddings(); // array of floats
```

---

## Conversation History

Use `with_history()` for multi-turn conversations:

```php
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;

$history = [
    new Message( MessageRoleEnum::user(), 'What is PHP?' ),
    new Message( MessageRoleEnum::model(), 'PHP is a server-side scripting language.' ),
];

$text = wp_ai_client_prompt( 'Can you give me an example?' )
    ->with_history( $history )
    ->generate_text();
// Response continues the conversation context
```

---

## Advanced Options

### System Instructions

```php
$text = wp_ai_client_prompt( 'Write product copy.' )
    ->with_system_instruction( 'You are a marketing copywriter. Be concise and persuasive.' )
    ->generate_text();
```

### Temperature & Sampling

```php
$text = wp_ai_client_prompt( 'Write a creative story.' )
    ->with_temperature( 0.9 )      // Higher = more creative (0.0–2.0)
    ->with_top_p( 0.95 )           // Nucleus sampling
    ->generate_text();
```

### Token Limits

```php
$text = wp_ai_client_prompt( 'Summarize this document.' )
    ->with_max_tokens( 500 )       // Limit response length
    ->generate_text();
```

### File Attachments (Multimodal)

```php
use WordPress\AiClient\Files\DTO\File;

$image_url = 'https://example.com/chart.png';
$file = new File( $image_url, 'image/png' );

$text = wp_ai_client_prompt( 'What does this chart show?' )
    ->with_file( $file )
    ->generate_text();
```

For local files:

```php
$path = '/path/to/image.jpg';
$data = base64_encode( file_get_contents( $path ) );
$data_uri = 'data:image/jpeg;base64,' . $data;

$file = new File( $data_uri, 'image/jpeg' );

$text = wp_ai_client_prompt( 'Describe this image.' )
    ->with_file( $file )
    ->generate_text();
```

---

## Error Handling

Wrap calls in try/catch for production code:

```php
use WordPress\AiClient\Exceptions\AiClientException;

try {
    $text = wp_ai_client_prompt( 'Hello world' )
        ->using_provider( 'azure-ai-foundry' )
        ->generate_text();
} catch ( AiClientException $e ) {
    error_log( 'AI generation failed: ' . $e->getMessage() );
    $text = 'Generation unavailable.';
}
```

Common exceptions:
- `NoModelsFoundException` — No model supports the requested capability
- `ProviderNotConfiguredException` — Missing API key or endpoint
- `RequestAuthenticationException` — Invalid credentials

---

## Using with WP-CLI

Test the provider from the command line:

```bash
# Simple test
wp eval "echo wp_ai_client_prompt('What is WordPress?')->using_provider('azure-ai-foundry')->generate_text();"

# List registered providers
wp eval "print_r( array_keys( WordPress\AiClient\AiClient::defaultRegistry()->getProviders() ) );"
```

---

## Hooks & Filters

### Prioritize Your Provider

Add your models to the preferred list so the AI plugin uses them:

```php
add_filter( 'wpai_preferred_text_models', function( array $preferred ): array {
    // Prepend Azure models
    return array_merge( [
        [ 'azure-ai-foundry', 'gpt-4.1' ],
        [ 'azure-ai-foundry', 'gpt-4o' ],
    ], $preferred );
} );
```

### Custom Model Metadata

Override detected models via filter:

```php
add_filter( 'azure_ai_foundry_model_metadata', function( array $models ): array {
    // Add a custom model
    $models[] = [
        'id'           => 'my-fine-tuned-model',
        'capabilities' => [ 'text_generation', 'chat_history' ],
    ];
    return $models;
} );
```

---

## Complete Example: Chat Interface

```php
namespace MyPlugin;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Exceptions\AiClientException;

class AiChatHandler {

    private array $history = [];

    public function send_message( string $user_input ): string {
        try {
            $result = wp_ai_client_prompt( $user_input )
                ->using_provider( 'azure-ai-foundry' )
                ->with_system_instruction( 'You are a helpful WordPress assistant.' )
                ->with_history( $this->history )
                ->with_temperature( 0.7 )
                ->with_max_tokens( 1000 )
                ->generate_text_result();

            $response = $result->getText();

            // Update history for next turn
            $this->history[] = new Message( MessageRoleEnum::user(), $user_input );
            $this->history[] = new Message( MessageRoleEnum::model(), $response );

            return $response;

        } catch ( AiClientException $e ) {
            return 'Sorry, I could not process your request: ' . $e->getMessage();
        }
    }

    public function reset_conversation(): void {
        $this->history = [];
    }
}

// Usage:
$chat = new AiChatHandler();
echo $chat->send_message( 'How do I create a custom post type?' );
echo $chat->send_message( 'Can you show me the code?' );
```

---

## Capability Reference

| Capability                | Method                        | Azure Model Examples      |
|---------------------------|-------------------------------|---------------------------|
| Text Generation           | `generate_text()`             | gpt-4.1, gpt-4o          |
| Chat History              | `with_history()`              | gpt-4.1, gpt-4o          |
| Image Generation          | `generate_image()`            | gpt-image-1, dall-e-3    |
| Embeddings                | `generate_embedding_result()` | text-embedding-ada-002   |
| Text-to-Speech            | `convert_text_to_speech()`    | tts-1, tts-1-hd          |

---

## Further Reading

- [How to Build an AI Provider Plugin](how-to-add-ai-provider.md)
- [WordPress AI Client SDK](https://developer.wordpress.org/reference/functions/wp_ai_client_prompt/)
- [Azure AI Foundry Model Inference API](https://learn.microsoft.com/en-us/rest/api/aifoundry/modelinference/)
