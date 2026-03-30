<?php
/**
 * Settings Manager — a convenience singleton that reads option values
 * and falls back to environment variables and defaults.
 */

namespace AzureAiFoundry\Settings;

class SettingsManager {

	private static ?self $instance = null;

	private function __construct() {}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Get the API endpoint URL.
	 *
	 * Fallback chain: option → AZURE_AI_FOUNDRY_ENDPOINT env → ''.
	 */
	public function get_endpoint(): string {
		return $this->resolve(
			ConnectorSettings::OPTION_ENDPOINT,
			'AZURE_AI_FOUNDRY_ENDPOINT',
			''
		);
	}

	/**
	 * Get the real (unmasked) API key.
	 *
	 * Fallback chain: option → AZURE_AI_FOUNDRY_API_KEY env → ''.
	 */
	public function get_real_api_key(): string {
		$key = ConnectorSettings::get_real_api_key();
		if ( ! empty( $key ) ) {
			return $key;
		}
		return $this->resolve_env( 'AZURE_AI_FOUNDRY_API_KEY' );
	}

	/**
	 * Get the configured model name.
	 *
	 * Fallback chain: option → AZURE_AI_FOUNDRY_MODEL env → ''.
	 */
	public function get_model_name(): string {
		return $this->resolve(
			ConnectorSettings::OPTION_MODEL_NAME,
			'AZURE_AI_FOUNDRY_MODEL',
			''
		);
	}

	/**
	 * Get configured capabilities.
	 *
	 * Fallback chain: option → AZURE_AI_FOUNDRY_CAPABILITIES env (comma-separated) → defaults.
	 *
	 * @return list<string>
	 */
	public function get_capabilities(): array {
		$value = get_option( ConnectorSettings::OPTION_CAPABILITIES, [] );

		if ( empty( $value ) ) {
			$env = $this->resolve_env( 'AZURE_AI_FOUNDRY_CAPABILITIES' );
			if ( '' !== $env ) {
				$value = array_map( 'trim', explode( ',', $env ) );
				$value = ConnectorSettings::sanitize_capabilities( $value );
			}
		}

		if ( empty( $value ) ) {
			$value = [ 'text_generation', 'chat_history' ];
		}

		return $value;
	}

	/**
	 * Resolve an option value with environment variable fallback.
	 *
	 * @param string $option_name  WordPress option name.
	 * @param string $env_name     Environment variable name.
	 * @param string $default      Default value.
	 * @return string Resolved value.
	 */
	private function resolve( string $option_name, string $env_name, string $default ): string {
		$value = get_option( $option_name, '' );
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}

		$env = $this->resolve_env( $env_name );
		if ( '' !== $env ) {
			return $env;
		}

		return $default;
	}

	/**
	 * Read an environment variable safely.
	 *
	 * @param string $name Environment variable name.
	 * @return string The value, or '' if not set.
	 */
	public function resolve_env( string $name ): string {
		$value = getenv( $name );
		if ( false !== $value && '' !== $value ) {
			return (string) $value;
		}

		// Also check for constants defined in wp-config.php.
		if ( defined( $name ) ) {
			$const = constant( $name );
			if ( is_string( $const ) && '' !== $const ) {
				return $const;
			}
		}

		return '';
	}
}
