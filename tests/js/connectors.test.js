/**
 * Tests for the Azure AI Foundry connector module.
 */
import { __experimentalRegisterConnector as mockRegisterConnector } from '@wordpress/connectors';
import { render, act, fireEvent, waitFor } from '@testing-library/react';

const el = window.wp.element.createElement;

let registeredConfig;

describe( 'Azure AI Foundry Connector', () => {
	beforeAll( async () => {
		window.wp.apiFetch.mockResolvedValue( {} );
		await import( '../../src/js/connectors.js' );
		registeredConfig = mockRegisterConnector.mock.calls[ 0 ][ 1 ];
	} );

	beforeEach( () => {
		window.wp.apiFetch.mockReset();
		window.wp.apiFetch.mockResolvedValue( {} );
	} );

	it( 'should register with the expected slug', () => {
		expect( mockRegisterConnector ).toHaveBeenCalled();
		expect( mockRegisterConnector.mock.calls[ 0 ][ 0 ] ).toBe(
			'ai_provider/azure-ai-foundry'
		);
	} );

	it( 'should have the correct name', () => {
		expect( registeredConfig.name ).toBe( 'Azure AI Foundry' );
	} );

	it( 'should have a render function', () => {
		expect( typeof registeredConfig.render ).toBe( 'function' );
	} );

	it( 'should render without errors', async () => {
		window.wp.apiFetch.mockResolvedValue( {
			connectors_ai_azure_ai_foundry_api_key: '',
			connectors_ai_azure_ai_foundry_endpoint: '',
			connectors_ai_azure_ai_foundry_model_name: '',
			connectors_ai_azure_ai_foundry_capabilities: [],
		} );

		const Component = registeredConfig.render;

		let container;
		await act( async () => {
			const result = render(
				el( Component, {
					slug: 'ai_provider/azure-ai-foundry',
					name: 'Azure AI Foundry',
					description: 'Test description',
				} )
			);
			container = result.container;
		} );

		expect( container ).toBeTruthy();
	} );

	it( 'should show Set Up button when not connected', async () => {
		window.wp.apiFetch.mockResolvedValue( {
			connectors_ai_azure_ai_foundry_api_key: '',
			connectors_ai_azure_ai_foundry_endpoint: '',
			connectors_ai_azure_ai_foundry_model_name: '',
			connectors_ai_azure_ai_foundry_capabilities: [],
		} );

		const Component = registeredConfig.render;

		let container;
		await act( async () => {
			const result = render(
				el( Component, {
					slug: 'ai_provider/azure-ai-foundry',
					name: 'Azure AI Foundry',
					description: 'Test',
				} )
			);
			container = result.container;
		} );

		const button = container.querySelector( 'button' );
		expect( button ).toBeTruthy();
		expect( button.textContent ).toBe( 'Set Up' );
	} );

	it( 'should show Edit button when connected', async () => {
		window.wp.apiFetch.mockResolvedValue( {
			connectors_ai_azure_ai_foundry_api_key: '••••••••••••abcd',
			connectors_ai_azure_ai_foundry_endpoint:
				'https://test.services.ai.azure.com',
			connectors_ai_azure_ai_foundry_model_name: 'gpt-4o',
			connectors_ai_azure_ai_foundry_capabilities: [ 'text_generation', 'chat_history' ],
		} );

		const Component = registeredConfig.render;

		let container;
		await act( async () => {
			const result = render(
				el( Component, {
					slug: 'ai_provider/azure-ai-foundry',
					name: 'Azure AI Foundry',
					description: 'Test',
				} )
			);
			container = result.container;
		} );

		const button = container.querySelector( 'button' );
		expect( button ).toBeTruthy();
		expect( button.textContent ).toBe( 'Edit' );
	} );

	it( 'should save API key and endpoint when connecting', async () => {
		window.wp.apiFetch
			.mockResolvedValueOnce( {
				connectors_ai_azure_ai_foundry_api_key: '',
				connectors_ai_azure_ai_foundry_endpoint: '',
				connectors_ai_azure_ai_foundry_model_name: '',
				connectors_ai_azure_ai_foundry_capabilities: [],
			} )
			.mockResolvedValueOnce( {
				connectors_ai_azure_ai_foundry_api_key: '••••••••••••abcd',
			} )
			.mockResolvedValueOnce( {
				connectors_ai_azure_ai_foundry_endpoint: 'https://test.services.ai.azure.com',
			} )
			.mockResolvedValueOnce( {
				model_name: 'gpt-4.1',
				capabilities: [ 'text_generation', 'chat_history' ],
			} );

		const Component = registeredConfig.render;

		let container;
		await act( async () => {
			const result = render(
				el( Component, {
					slug: 'ai_provider/azure-ai-foundry',
					name: 'Azure AI Foundry',
					description: 'Test',
				} )
			);
			container = result.container;
		} );

		const buttons = container.querySelectorAll( 'button' );
		await act( async () => {
			fireEvent.click( buttons[ 0 ] );
		} );

		const inputs = container.querySelectorAll( 'input' );
		await act( async () => {
			fireEvent.change( inputs[ 0 ], { target: { value: 'test-api-key-abcd' } } );
			fireEvent.change( inputs[ 1 ], { target: { value: 'https://test.services.ai.azure.com' } } );
		} );

		const connectButton = Array.from( container.querySelectorAll( 'button' ) ).find(
			( button ) => button.textContent === 'Connect & Detect'
		);

		await act( async () => {
			fireEvent.click( connectButton );
		} );

		await waitFor( () => {
			expect( window.wp.apiFetch ).toHaveBeenCalledWith( {
				path: '/wp/v2/settings?_fields=connectors_ai_azure_ai_foundry_api_key',
				method: 'POST',
				data: {
					connectors_ai_azure_ai_foundry_api_key: 'test-api-key-abcd',
				},
			} );
			expect( window.wp.apiFetch ).toHaveBeenCalledWith( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: {
					connectors_ai_azure_ai_foundry_endpoint: 'https://test.services.ai.azure.com',
				},
			} );
			expect( window.wp.apiFetch ).toHaveBeenCalledWith( {
				path: '/azure-ai-foundry/v1/detect',
				method: 'POST',
				data: {
					endpoint: 'https://test.services.ai.azure.com',
					api_key: 'test-api-key-abcd',
				},
			} );
		} );
	} );
} );
