# Building an AI Provider Plugin for WordPress 7: Azure AI Foundry Deep-Dive

> **Reference implementation**: This guide walks through the **Azure AI Foundry Connector** plugin as a complete, real-world example of integrating a custom AI provider with WordPress 7.0.

---

## Quick Links

- [Architecture Overview](#architecture-overview)
- [1. Provider Registration (PHP)](#1-provider-registration-php)
- [2. Settings and Configuration](#2-settings-and-configuration)
- [3. Connectors Page UI (JavaScript)](#3-connectors-page-ui-javascript)
- [4. Authentication](#4-authentication)
- [5. Testing](#5-testing)
- [6. Common Pitfalls](#6-common-pitfalls)

---

## Architecture Overview

WordPress 7.0 introduces two systems that work together:

```
┌─────────────────────────────────────────────────────────────┐
│                    WP Admin UI                              │
│      Settings → Connectors page (React, script modules)     │
│          ↕ REST API (/wp/v2/settings)                       │
├─────────────────────────────────────────────────────────────┤
│                    PHP Backend                              │
│      register_setting('connectors', ...)                    │
│      AiClient::defaultRegistry()->registerProvider()        │
│      ProviderInterface → Models → HTTP requests             │
└─────────────────────────────────────────────────────────────┘
```

| Layer               | Purpose |
|---------------------|---------|
| **AI Client SDK**   | PHP library at `wp-includes/php-ai-client/` — defines providers, models, capabilities, and the registry |
| **Connectors Page** | React admin page at Settings → Connectors — UI for API keys and provider settings |

**Your plugin bridges both:** register a PHP provider with the AI Client and register a JS connector with the Connectors page.

---

## 1. Provider Registration (PHP)

### 1.1 Plugin Bootstrap

Create the main plugin file with early provider registration:

```php
<?php
// azure-ai-foundry.php
namespace AzureAiFoundry;

use WordPress\AiClient\AiClient;
use AzureAiFoundry\Provider\AzureAiFoundryProvider;
use AzureAiFoundry\Settings\ConnectorSettings;

define( 'AZURE_AI_FOUNDRY_VERSION', '1.0.0' );
define( 'AZURE_AI_FOUNDRY_FILE', __FILE__ );

require_once __DIR__ . '/src/autoload.php';

// Register provider early (priority 5)
function register_provider(): void {
    if ( ! class_exists( AiClient::class ) ) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ( ! $registry->hasProvider( AzureAiFoundryProvider::class ) ) {
        $registry->registerProvider( AzureAiFoundryProvider::class );
    }
}
add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );
```

**Key points:**
- Register at `init` priority 5 (before core connector binding at priority 20)
- Guard against missing `AiClient` class for backward compatibility
- Use `hasProvider()` to avoid duplicate registration

### 1.2 Provider Class

The provider extends `AbstractApiProvider` and implements four factory methods:

```php
<?php
// src/Provider/AzureAiFoundryProvider.php
namespace AzureAiFoundry\Provider;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;

class AzureAiFoundryProvider extends AbstractApiProvider {

    /**
     * Base URL from settings.
     */
    protected static function baseUrl(): string {
        return rtrim( SettingsManager::instance()->get_endpoint(), '/' );
    }

    /**
     * Provider identity — appears in the registry and Connectors page.
     */
    protected static function createProviderMetadata(): ProviderMetadata {
        return new ProviderMetadata(
            'azure-ai-foundry',                          // Unique slug
            __( 'Azure AI Foundry', 'azure-ai-foundry' ),// Display name
            ProviderTypeEnum::cloud(),                   // cloud | server | client
            'https://ai.azure.com/',                     // Where users get API keys
            RequestAuthenticationMethod::apiKey()        // Auth method
        );
    }

    /**
     * Route to the correct model class based on capability.
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

        return new AzureAiFoundryTextGenerationModel( $model_metadata, $provider_metadata );
    }

    // ... createProviderAvailability() and createModelMetadataDirectory()
}
```

### 1.3 Model Metadata Directory

The directory tells the SDK which models your provider offers:

```php
<?php
// src/Metadata/AzureAiFoundryModelMetadataDirectory.php
class AzureAiFoundryModelMetadataDirectory implements ModelMetadataDirectoryInterface {

    private ?array $cached = null;

    public function listModelMetadata(): array {
        if ( null !== $this->cached ) {
            return $this->cached;
        }

        // 1. Check for configured model name
        $model_name = SettingsManager::instance()->get_model_name();
        if ( ! empty( $model_name ) ) {
            $this->cached = $this->buildModelsFromConfig( $model_name );
            return $this->cached;
        }

        // 2. Try to discover model from /info endpoint
        $discovered = $this->discoverModel();
        if ( $discovered ) {
            $this->cached = $discovered;
            return $this->cached;
        }

        // 3. Fall back to generic entry
        $this->cached = $this->buildGenericModel();
        return $this->cached;
    }
}
```

### 1.4 Model Class (OpenAI-Compatible)

If your API follows the OpenAI chat format, extend the provided base class:

```php
<?php
// src/Models/AzureAiFoundryTextGenerationModel.php
namespace AzureAiFoundry\Models;

use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * OpenAI-compatible text generation model.
 *
 * The base class handles:
 * - Parameter building (temperature, max_tokens, etc.)
 * - Message formatting (system/user/assistant roles)
 * - Response parsing and streaming
 * - Tool/function calls
 */
class AzureAiFoundryTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {
    // Nothing to override — the base class handles everything.
    // Request URL: {baseUrl}/chat/completions?api-version=...
}
```

---

## 2. Settings and Configuration

### 2.1 Register Settings

All settings must use the `'connectors'` group and `'show_in_rest' => true`:

```php
<?php
// src/Settings/ConnectorSettings.php
class ConnectorSettings {

    public const string OPTION_API_KEY      = 'connectors_ai_azure_ai_foundry_api_key';
    public const string OPTION_ENDPOINT     = 'connectors_ai_azure_ai_foundry_endpoint';
    public const string OPTION_MODEL_NAME   = 'connectors_ai_azure_ai_foundry_model_name';
    public const string OPTION_CAPABILITIES = 'connectors_ai_azure_ai_foundry_capabilities';

    public static function register(): void {
        // API Key
        register_setting( 'connectors', self::OPTION_API_KEY, [
            'type'              => 'string',
            'label'             => __( 'Azure AI Foundry API Key', 'azure-ai-foundry' ),
            'default'           => '',
            'show_in_rest'      => true,  // Required for JS UI
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        // Mask the key in REST responses
        add_filter( 'option_' . self::OPTION_API_KEY, [ __CLASS__, 'mask_api_key' ] );

        // Endpoint URL
        register_setting( 'connectors', self::OPTION_ENDPOINT, [
            'type'              => 'string',
            'default'           => '',
            'show_in_rest'      => true,
            'sanitize_callback' => 'esc_url_raw',
        ] );

        // ... additional settings
    }

    /**
     * Mask API key: bullets + last 4 chars.
     */
    public static function mask_api_key( mixed $key ): string {
        if ( ! is_string( $key ) || strlen( $key ) <= 4 ) {
            return is_string( $key ) ? $key : '';
        }
        return str_repeat( '•', min( strlen( $key ) - 4, 16 ) ) . substr( $key, -4 );
    }

    /**
     * Read the real (unmasked) API key.
     */
    public static function get_real_api_key(): string {
        remove_filter( 'option_' . self::OPTION_API_KEY, [ __CLASS__, 'mask_api_key' ] );
        $value = get_option( self::OPTION_API_KEY, '' );
        add_filter( 'option_' . self::OPTION_API_KEY, [ __CLASS__, 'mask_api_key' ] );
        return (string) $value;
    }
}
```

### 2.2 Environment Variable Fallback

Support configuration via environment variables or `wp-config.php` constants:

```php
<?php
// src/Settings/SettingsManager.php
class SettingsManager {

    public function get_real_api_key(): string {
        $key = ConnectorSettings::get_real_api_key();
        if ( ! empty( $key ) ) {
            return $key;
        }
        return $this->resolve_env( 'AZURE_AI_FOUNDRY_API_KEY' );
    }

    public function resolve_env( string $name ): string {
        // Check environment variable
        $value = getenv( $name );
        if ( false !== $value && '' !== $value ) {
            return (string) $value;
        }

        // Check wp-config.php constant
        if ( defined( $name ) ) {
            $const = constant( $name );
            if ( is_string( $const ) && '' !== $const ) {
                return $const;
            }
        }

        return '';
    }
}
```

---

## 3. Connectors Page UI (JavaScript)

### 3.1 Script Modules vs Classic Scripts

> **This is the single most common pitfall.**

WordPress 7.0 uses two script systems:

| System | Examples | How to Use |
|--------|----------|------------|
| **Script Modules** | `@wordpress/connectors` | `import { ... } from '...'` |
| **Classic Scripts** | `api-fetch`, `element`, `i18n`, `components` | `window.wp.apiFetch`, etc. |

**Only `@wordpress/connectors` is a script module.** Everything else must be accessed via `window.wp.*` globals.

If you declare a classic package as a script module dependency, **your module silently fails to load** — no error in the console.

### 3.2 Register and Enqueue the Module

```php
// In your main plugin file

function register_connector_module(): void {
    wp_register_script_module(
        'azure-ai-foundry/connectors',
        plugins_url( 'build/connectors.js', AZURE_AI_FOUNDRY_FILE ),
        [
            [
                'id'     => '@wordpress/connectors',  // Only script module dep
                'import' => 'dynamic',
            ],
            // Do NOT add @wordpress/api-fetch, @wordpress/element, etc.
        ],
        AZURE_AI_FOUNDRY_VERSION
    );
}
add_action( 'init', __NAMESPACE__ . '\\register_connector_module' );

// Enqueue on both possible Connectors page hooks
function enqueue_connector_module(): void {
    wp_enqueue_script_module( 'azure-ai-foundry/connectors' );
}
add_action( 'options-connectors-wp-admin_init', __NAMESPACE__ . '\\enqueue_connector_module' );
add_action( 'connectors-wp-admin_init', __NAMESPACE__ . '\\enqueue_connector_module' );
```

### 3.3 Webpack Configuration

Output ESM for script modules:

```js
// webpack.config.js
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

// Remove DependencyExtractionWebpackPlugin — not used for script modules
const plugins = defaultConfig.plugins.filter(
    ( p ) => p.constructor.name !== 'DependencyExtractionWebpackPlugin'
);

module.exports = {
    ...defaultConfig,
    entry: {
        connectors: path.resolve( process.cwd(), 'src/js', 'connectors.js' ),
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve( process.cwd(), 'build' ),
        module: true,
        chunkFormat: 'module',
        library: { type: 'module' },
    },
    experiments: { ...defaultConfig.experiments, outputModule: true },
    externalsType: 'module',
    externals: {
        '@wordpress/connectors': '@wordpress/connectors',
    },
    plugins,
};
```

### 3.4 Connector Component

```javascript
// src/js/connectors.js

// Script module import (the only ES module)
import {
    __experimentalRegisterConnector as registerConnector,
    __experimentalConnectorItem as ConnectorItem,
} from '@wordpress/connectors';

// Classic scripts via window globals
const apiFetch = window.wp.apiFetch;
const { useState, useEffect, useCallback, createElement } = window.wp.element;
const { __ } = window.wp.i18n;
const { Button, TextControl } = window.wp.components;

const el = createElement;

// Option names must match PHP register_setting() calls
const API_KEY_OPTION  = 'connectors_ai_azure_ai_foundry_api_key';
const ENDPOINT_OPTION = 'connectors_ai_azure_ai_foundry_endpoint';

/**
 * Custom hook for settings management.
 */
function useAzureSettings() {
    const [ isLoading, setIsLoading ] = useState( true );
    const [ apiKey, setApiKey ]       = useState( '' );
    const [ endpoint, setEndpoint ]   = useState( '' );

    // Load settings via REST API
    const loadSettings = useCallback( async () => {
        try {
            const data = await apiFetch( {
                path: `/wp/v2/settings?_fields=${ API_KEY_OPTION },${ ENDPOINT_OPTION }`,
            } );
            setApiKey( data[ API_KEY_OPTION ] || '' );
            setEndpoint( data[ ENDPOINT_OPTION ] || '' );
        } finally {
            setIsLoading( false );
        }
    }, [] );

    useEffect( () => { loadSettings(); }, [ loadSettings ] );

    // Save API key
    const saveApiKey = useCallback( async ( newKey ) => {
        const result = await apiFetch( {
            path: `/wp/v2/settings?_fields=${ API_KEY_OPTION }`,
            method: 'POST',
            data: { [ API_KEY_OPTION ]: newKey },
        } );
        setApiKey( result[ API_KEY_OPTION ] || '' );
    }, [] );

    return { isLoading, apiKey, endpoint, setEndpoint, saveApiKey };
}

/**
 * Connector component.
 */
function AzureAiFoundryConnector( { slug, name, description, logo } ) {
    const { isLoading, apiKey, endpoint, setEndpoint, saveApiKey } = useAzureSettings();
    const [ isExpanded, setIsExpanded ] = useState( false );

    const isConnected = ! isLoading && apiKey !== '';

    if ( isLoading ) {
        return el( ConnectorItem, {
            logo: logo || el( AzureIcon ),
            name,
            description,
            actionArea: el( 'span', { className: 'spinner is-active' } ),
        } );
    }

    const buttonLabel = isConnected
        ? __( 'Edit', 'azure-ai-foundry' )
        : __( 'Set Up', 'azure-ai-foundry' );

    return el( ConnectorItem, {
        logo: logo || el( AzureIcon ),
        name,
        description,
        actionArea: el( Button, {
            variant: isConnected ? 'tertiary' : 'secondary',
            onClick: () => setIsExpanded( ! isExpanded ),
        }, buttonLabel ),
    },
        isExpanded && el( 'div', null,
            el( TextControl, {
                label: __( 'API Key', 'azure-ai-foundry' ),
                value: apiKey,
                onChange: saveApiKey,
            } ),
            el( TextControl, {
                label: __( 'Endpoint URL', 'azure-ai-foundry' ),
                value: endpoint,
                onChange: setEndpoint,
            } )
        )
    );
}

// Register with the slug format: {type}/{id}
registerConnector( 'ai_provider/azure-ai-foundry', {
    name: __( 'Azure AI Foundry', 'azure-ai-foundry' ),
    description: __( 'Connect to Azure AI Foundry Model Inference API.', 'azure-ai-foundry' ),
    render: AzureAiFoundryConnector,
} );
```

### 3.5 Prevent Core from Overriding Your Connector

Starting in RC1, WordPress auto-creates connectors for every registered AI provider. Unregister to prevent conflicts:

```php
/**
 * Unregister so core doesn't manage our API key (no double-masking, no validation issues).
 */
function unregister_from_connector_registry( \WP_Connector_Registry $registry ): void {
    if ( $registry->is_registered( 'azure-ai-foundry' ) ) {
        $registry->unregister( 'azure-ai-foundry' );
    }
}
add_action( 'wp_connectors_init', __NAMESPACE__ . '\\unregister_from_connector_registry' );
```

---

## 4. Authentication

### 4.1 Custom Authentication Header

Azure uses `api-key` instead of `Authorization: Bearer`. Extend the SDK's authentication class:

```php
<?php
// src/Http/AzureAiFoundryRequestAuthentication.php
namespace AzureAiFoundry\Http;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

/**
 * Must extend ApiKeyRequestAuthentication (not just implement the interface)
 * because the ProviderRegistry validates instanceof the class returned by
 * RequestAuthenticationMethod::apiKey()->getImplementationClass().
 */
class AzureAiFoundryRequestAuthentication extends ApiKeyRequestAuthentication {

    public function authenticateRequest( Request $request ): Request {
        return $request->withHeader( 'api-key', $this->getApiKey() );
    }
}
```

### 4.2 Wire Up Authentication

Set authentication **after** core connector key binding (priority 20):

```php
function setup_authentication(): void {
    if ( ! class_exists( AiClient::class ) ) {
        return;
    }

    $api_key = SettingsManager::instance()->get_real_api_key();

    if ( ! empty( $api_key ) ) {
        AiClient::defaultRegistry()->setProviderRequestAuthentication(
            'azure-ai-foundry',  // Must match provider slug
            new Http\AzureAiFoundryRequestAuthentication( $api_key )
        );
    }
}
add_action( 'init', __NAMESPACE__ . '\\setup_authentication', 30 );
```

---

## 5. Testing

### 5.1 Vitest Configuration

```js
// vitest.config.js
import { defineConfig } from 'vitest/config';
import path from 'path';

export default defineConfig( {
    resolve: {
        alias: {
            '@wordpress/connectors': path.resolve(
                __dirname,
                'tests/js/__mocks__/@wordpress/connectors.js'
            ),
        },
    },
    test: {
        globals: true,
        environment: 'jsdom',
        include: [ 'tests/js/**/*.test.js' ],
        setupFiles: [ 'tests/js/setup-globals.js' ],
    },
} );
```

### 5.2 Mock the Connectors Module

```js
// tests/js/__mocks__/@wordpress/connectors.js
export const __experimentalRegisterConnector = vi.fn();
export const __experimentalConnectorItem = ( { children } ) => children ?? null;
export const __experimentalDefaultConnectorSettings = ( { children } ) => children ?? null;
```

### 5.3 Setup Globals

```js
// tests/js/setup-globals.js
import React from 'react';

window.wp = {
    apiFetch: vi.fn( () => Promise.resolve( {} ) ),
    element: {
        useState: React.useState,
        useEffect: React.useEffect,
        useCallback: React.useCallback,
        createElement: React.createElement,
    },
    i18n: { __: vi.fn( ( str ) => str ) },
    components: {
        Button( { children, ...props } ) {
            return React.createElement( 'button', props, children );
        },
        TextControl( props ) {
            return React.createElement( 'input', {
                type: 'text',
                value: props.value || '',
                onChange: ( e ) => props.onChange?.( e.target.value ),
            } );
        },
    },
};
```

### 5.4 Test the AI Client Integration

```php
// Test in WP-CLI or custom page:
use WordPress\AiClient\AiClient;

$result = AiClient::prompt( 'Explain gravity in one sentence.' )
    ->usingProvider( 'azure-ai-foundry' )
    ->generateTextResult();

echo $result->getText();
```

---

## 6. Common Pitfalls

### Provider not appearing on Connectors page?

1. Check your provider ID matches `/^[a-z0-9_-]+$/` (lowercase, digits, underscores, hyphens)
2. Verify your script module is registered (check Browser DevTools → Network for `<script type="importmap">`)
3. Confirm you're hooking **both** `options-connectors-wp-admin_init` and `connectors-wp-admin_init`

### Custom UI replaced by generic API-key input?

Core auto-registration is overwriting your connector. Unregister from the connector registry:

```php
add_action( 'wp_connectors_init', function( $registry ) {
    if ( $registry->is_registered( 'azure-ai-foundry' ) ) {
        $registry->unregister( 'azure-ai-foundry' );
    }
} );
```

### API key disappears after saving?

RC1 validates keys on save by calling `isProviderConfigured()`. If your provider needs additional config (endpoint URL, etc.) before validation works, the key is silently reverted. **Fix:** Unregister from the connector registry.

### API key shows as all bullets (no last 4 chars)?

Double-masking: both core and your `option_` filter masked the key. **Fix:** Unregister from the connector registry, or remove your `option_` filter.

### "No models found that support text_generation for this prompt"?

1. Your `listModelMetadata()` returns an empty array (check API key/endpoint)
2. A caller used an option your model doesn't declare (e.g., `->usingWebSearch()`)
3. Missing `outputModalities` option in your `SupportedOption` list
4. Missing multimodal `inputModalities` when caller uses `->with_file()`

---

## API Reference

### Provider ID Format

Provider IDs must match `/^[a-z0-9_-]+$/`:
- Lowercase letters, digits, underscores, hyphens
- Used in `ProviderMetadata`, `registerConnector()`, `setProviderRequestAuthentication()`, and `usingProvider()`

### Capabilities

```php
CapabilityEnum::textGeneration()          // GPT-style text
CapabilityEnum::imageGeneration()         // DALL·E-style images
CapabilityEnum::embeddingGeneration()     // Vector embeddings
CapabilityEnum::textToSpeechConversion()  // TTS
CapabilityEnum::chatHistory()             // Multi-turn conversation
```

### Provider Types

```php
ProviderTypeEnum::cloud()   // Remote API (most common)
ProviderTypeEnum::server()  // Self-hosted (Ollama, llama.cpp)
ProviderTypeEnum::client()  // Browser-based (WebLLM)
```

---

## WordPress Version Compatibility

| Version | Notable Changes |
|---------|-----------------|
| Beta 3  | Connectors page moved to `options-connectors.php`. Hook both variants. |
| Beta 6  | Core binds connector API keys at `init` priority 20. Use priority 30 for custom auth. |
| RC1     | `ConnectorItem` prop renamed `icon` → `logo`. Core validates keys on save. Unregister from connector registry for custom UI. |
| RC2     | Provider ID now accepts hyphens (`/^[a-z0-9_-]+$/`). |

---

## Further Reading

- [Azure AI Foundry Model Inference API](https://learn.microsoft.com/en-us/rest/api/aifoundry/modelinference/)
- [WordPress AI Client SDK](https://developer.wordpress.org/reference/functions/wp_ai_client_prompt/)
- [Make/Core blog](https://make.wordpress.org/core/) — WordPress development updates
