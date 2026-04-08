<?php
/**
 * Unit tests for ConnectorSettings.
 */

declare(strict_types=1);

namespace AzureAiFoundry\Tests\Unit\Settings;

use AzureAiFoundry\Settings\ConnectorSettings;
use AzureAiFoundry\Tests\TestCase;
use Brain\Monkey\Functions;

class ConnectorSettingsTest extends TestCase {

	/**
	 * Test that mask_api_key returns empty string for empty input.
	 */
	public function test_mask_api_key_returns_empty_for_empty_string(): void {
		$result = ConnectorSettings::mask_api_key( '' );

		$this->assertSame( '', $result );
	}

	/**
	 * Test that mask_api_key returns short keys unchanged.
	 */
	public function test_mask_api_key_returns_short_keys_unchanged(): void {
		$this->assertSame( 'abc', ConnectorSettings::mask_api_key( 'abc' ) );
		$this->assertSame( 'abcd', ConnectorSettings::mask_api_key( 'abcd' ) );
	}

	/**
	 * Test that mask_api_key masks longer keys correctly.
	 */
	public function test_mask_api_key_masks_longer_keys(): void {
		$key    = 'sk-1234567890abcdef';
		$result = ConnectorSettings::mask_api_key( $key );

		// Should end with last 4 characters.
		$this->assertStringEndsWith( 'cdef', $result );

		// Should contain bullet characters.
		$this->assertStringContainsString( "\u{2022}", $result );

		// Masked portion should be at most 16 bullets.
		$masked_length    = strlen( $key ) - 4;
		$expected_bullets = min( $masked_length, 16 );
		$this->assertSame( $expected_bullets + 4, mb_strlen( $result ) );
	}

	/**
	 * Test that mask_api_key handles non-string input.
	 */
	public function test_mask_api_key_handles_non_string_input(): void {
		$this->assertSame( '', ConnectorSettings::mask_api_key( null ) );
		$this->assertSame( '', ConnectorSettings::mask_api_key( 123 ) );
		$this->assertSame( '', ConnectorSettings::mask_api_key( [] ) );
	}

	/**
	 * Test that sanitize_capabilities filters invalid capabilities.
	 */
	public function test_sanitize_capabilities_filters_invalid_values(): void {
		$input = [ 'text_generation', 'invalid_capability', 'chat_history' ];

		$result = ConnectorSettings::sanitize_capabilities( $input );

		$this->assertSame( [ 'text_generation', 'chat_history' ], $result );
	}

	/**
	 * Test that sanitize_capabilities returns empty array for non-array input.
	 */
	public function test_sanitize_capabilities_returns_empty_for_non_array(): void {
		$this->assertSame( [], ConnectorSettings::sanitize_capabilities( 'text_generation' ) );
		$this->assertSame( [], ConnectorSettings::sanitize_capabilities( null ) );
		$this->assertSame( [], ConnectorSettings::sanitize_capabilities( 123 ) );
	}

	/**
	 * Test that sanitize_capabilities preserves valid capabilities.
	 */
	public function test_sanitize_capabilities_preserves_valid_values(): void {
		$input = [
			'text_generation',
			'chat_history',
			'image_generation',
			'embedding_generation',
			'text_to_speech_conversion',
		];

		$result = ConnectorSettings::sanitize_capabilities( $input );

		$this->assertSame( $input, $result );
	}

	/**
	 * Test that sanitize_capabilities re-indexes array.
	 */
	public function test_sanitize_capabilities_reindexes_array(): void {
		$input = [
			0 => 'invalid',
			1 => 'text_generation',
			5 => 'chat_history',
		];

		$result = ConnectorSettings::sanitize_capabilities( $input );

		$this->assertSame( [ 0 => 'text_generation', 1 => 'chat_history' ], $result );
	}

	/**
	 * Test that register calls register_setting for all options.
	 */
	public function test_register_registers_all_settings(): void {
		Functions\expect( 'register_setting' )
			->times( 4 )
			->andReturnFirstArg();

		Functions\expect( 'add_filter' )
			->once()
			->with(
				'option_' . ConnectorSettings::OPTION_API_KEY,
				[ ConnectorSettings::class, 'mask_api_key' ]
			);

		// Mock translation function.
		Functions\when( '__' )->returnArg();

		ConnectorSettings::register();
	}

	/**
	 * Test get_real_api_key retrieves unmasked value.
	 */
	public function test_get_real_api_key_returns_unmasked_value(): void {
		$api_key = 'sk-secret-api-key-12345';

		Functions\expect( 'remove_filter' )
			->once()
			->with(
				'option_' . ConnectorSettings::OPTION_API_KEY,
				[ ConnectorSettings::class, 'mask_api_key' ]
			);

		Functions\expect( 'get_option' )
			->once()
			->with( ConnectorSettings::OPTION_API_KEY, '' )
			->andReturn( $api_key );

		Functions\expect( 'add_filter' )
			->once()
			->with(
				'option_' . ConnectorSettings::OPTION_API_KEY,
				[ ConnectorSettings::class, 'mask_api_key' ]
			);

		$result = ConnectorSettings::get_real_api_key();

		$this->assertSame( $api_key, $result );
	}

	/**
	 * Test option constants have correct values.
	 */
	public function test_option_constants_have_expected_values(): void {
		$this->assertSame( 'connectors_ai_azure_ai_foundry_api_key', ConnectorSettings::OPTION_API_KEY );
		$this->assertSame( 'connectors_ai_azure_ai_foundry_endpoint', ConnectorSettings::OPTION_ENDPOINT );
		$this->assertSame( 'connectors_ai_azure_ai_foundry_model_name', ConnectorSettings::OPTION_MODEL_NAME );
		$this->assertSame( 'connectors_ai_azure_ai_foundry_capabilities', ConnectorSettings::OPTION_CAPABILITIES );
	}
}
