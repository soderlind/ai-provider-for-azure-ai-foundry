# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-04-03

### Added

- Embedding generation model (`AzureAiFoundryEmbeddingModel`) — supports text-embedding-ada-002, text-embedding-3-small/large deployments.
- Text-to-speech model (`AzureAiFoundryTextToSpeechModel`) — supports tts-1 and tts-1-hd deployments via the `TextToSpeechConversionModelInterface`.
- Auto-detection now resolves separate deployment names for text, image, embedding, and TTS model types.
- Audio input modality for text generation models (GPT-4o audio support).
- `topK` supported option for text generation models.
- AI client library conflict detection — warns when the "AI Experiments" plugin ships an outdated `php-ai-client` that overrides WordPress 7.0's built-in version.

### Fixed

- TTS response parsing — uses `getBody()` instead of `getData()` to capture raw audio binary (the SDK's `getData()` JSON-decodes the response, returning `null` for binary audio).

## [0.3.3] - 2026-03-31

### Fixed

- Unified the connector save flow so `Connect & Detect` persists the API key and endpoint before probing deployments.
- Added compatibility with the WordPress AI plugin's connector detection so configured Azure AI Foundry credentials are recognized as valid.

## [0.3.2] - 2026-03-31

### Fixed

- Escaped output in exception message (WordPress Plugin Check compliance).
- Added direct file access protection to `autoload.php` and `ConnectorSettings.php`.

## [0.3.1] - 2026-03-30

### Fixed

- Connector description now lists all supported capabilities (text generation, image generation, embeddings, text-to-speech) instead of only text generation.

## [0.3.0] - 2026-03-30

### Changed

- Simplified settings to only API Key and Endpoint URL — model name and API version are now auto-detected.
- "Connect & Detect" button probes Azure deployments via POST and saves detected configuration directly.
- Detect all deployed models (text and image), not just the first match.
- Hardcoded API versions per Azure API surface (no longer user-configurable).
- Connector UI shows read-only "Detected Configuration" panel with deployments and capability chips.

### Added

- `candidateCount` support in model metadata, enabling title generation.
- POST-based deployment probing (GET returns 404 on `*.services.ai.azure.com` even for existing deployments).

### Removed

- API Version setting (hardcoded to `2025-04-01-preview` for OpenAI surface).
- Model Name input field (auto-detected from endpoint).
- Capability checkboxes (auto-detected and displayed as read-only chips).

## [0.2.0] - 2026-03-30

### Added

- Capabilities setting with 5 options: text generation, chat history, image generation, embedding generation, text-to-speech.
- "Detect from Endpoint" button that auto-detects capabilities from the Azure endpoint.
- REST endpoint `POST /azure-ai-foundry/v1/detect` for capability detection.
- Support for Azure AI Services, Azure OpenAI, and Cognitive Services endpoints.
- Multi-API-version probing for OpenAI-compatible `/openai/models` endpoint.
- Model name heuristics for DALL-E (image) and TTS models.
- Endpoint URL validation — rejects non-Azure URLs with inline error message.
- Capabilities displayed as read-only chips instead of checkboxes.

### Changed

- Default capabilities set to `['text_generation', 'chat_history']` (PHP and JS aligned).
- Model metadata directory now builds capabilities and supported options from user settings.
- Save Settings button shows loading spinner, disables during save, and displays success/error feedback.
- Save button disabled when endpoint URL fails validation.

### Fixed

- Settings save appearing to do nothing (missing visual feedback).
- TextControl deprecation warning (`__next40pxDefaultSize`).

## [0.1.0] - 2026-03-29

### Added

- Initial release.
- AI Client provider for Azure AI Foundry Model Inference API.
- Text generation via OpenAI-compatible `/chat/completions` endpoint.
- Auto-detection of deployed model via `/info` endpoint.
- Custom `api-key` header authentication.
- Connectors page UI with fields for API key, endpoint URL, model name, and API version.
- Environment variable and `wp-config.php` constant fallbacks for all settings.
- API key masking in REST API responses.
- Vitest test suite for the connector JS module.
- GitHub Updater integration for automatic updates from GitHub releases.
- GitHub Actions workflows for release zip builds.
- Requires PHP 8.3+ (typed constants, `str_starts_with`, null coalescing assignment).
