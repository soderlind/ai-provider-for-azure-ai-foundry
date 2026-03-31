/**
 * Azure AI Foundry — Connector for the WP 7 Connectors page.
 *
 * ESM script module. Only @wordpress/connectors is a real module import.
 * Classic-script packages are accessed via window.wp.* globals.
 */

// ── Script module import ────────────────────────────────────────
import {
	__experimentalRegisterConnector as registerConnector,
	__experimentalConnectorItem as ConnectorItem,
} from '@wordpress/connectors';

// ── Classic scripts — window globals ────────────────────────────
const apiFetch = window.wp.apiFetch;
const { useState, useEffect, useCallback, createElement } = window.wp.element;
const { __ } = window.wp.i18n;
const { Button, TextControl } = window.wp.components;

const el = createElement;

// Option names must match the PHP register_setting() calls.
const API_KEY_OPTION      = 'connectors_ai_azure_ai_foundry_api_key';
const ENDPOINT_OPTION     = 'connectors_ai_azure_ai_foundry_endpoint';
const MODEL_NAME_OPTION   = 'connectors_ai_azure_ai_foundry_model_name';
const CAPABILITIES_OPTION = 'connectors_ai_azure_ai_foundry_capabilities';

const ALL_OPTIONS = [
	API_KEY_OPTION,
	ENDPOINT_OPTION,
	MODEL_NAME_OPTION,
	CAPABILITIES_OPTION,
].join( ',' );

const CAPABILITY_LABELS = {
	text_generation: __( 'Text Generation', 'azure-ai-foundry' ),
	chat_history: __( 'Chat History', 'azure-ai-foundry' ),
	image_generation: __( 'Image Generation', 'azure-ai-foundry' ),
	embedding_generation: __( 'Embedding Generation', 'azure-ai-foundry' ),
	text_to_speech_conversion: __( 'Text-to-Speech', 'azure-ai-foundry' ),
};

/**
 * Check whether a URL looks like a valid Azure AI endpoint.
 */
const AZURE_ENDPOINT_RE = /\.(services\.ai\.azure\.com|openai\.azure\.com|cognitiveservices\.azure\.com)(\/|$)/i;
function isValidAzureEndpoint( url ) {
	return ! url || AZURE_ENDPOINT_RE.test( url );
}

/**
 * Hook: load & save settings via the WP REST Settings endpoint.
 */
function useAzureSettings() {
	const [ isLoading, setIsLoading ]         = useState( true );
	const [ apiKey, setApiKey ]               = useState( '' );
	const [ endpoint, setEndpoint ]           = useState( '' );
	const [ modelName, setModelName ]         = useState( '' );
	const [ capabilities, setCapabilities ]   = useState( [] );

	const isConnected = ! isLoading && apiKey !== '';

	// Load settings on mount.
	const loadSettings = useCallback( async () => {
		try {
			const data = await apiFetch( {
				path: `/wp/v2/settings?_fields=${ ALL_OPTIONS }`,
			} );
			setApiKey( data[ API_KEY_OPTION ] || '' );
			setEndpoint( data[ ENDPOINT_OPTION ] || '' );
			setModelName( data[ MODEL_NAME_OPTION ] || '' );
			setCapabilities( data[ CAPABILITIES_OPTION ]?.length ? data[ CAPABILITIES_OPTION ] : [] );
		} catch {
			// Settings might not be accessible.
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		loadSettings();
	}, [ loadSettings ] );

	// Save the API key.
	const saveApiKey = useCallback( async ( newKey ) => {
		const result = await apiFetch( {
			path: `/wp/v2/settings?_fields=${ API_KEY_OPTION }`,
			method: 'POST',
			data: { [ API_KEY_OPTION ]: newKey },
		} );
		const returned = result[ API_KEY_OPTION ] || '';
		if ( returned === apiKey && newKey !== '' ) {
			throw new Error( __( 'Could not save the API key.', 'azure-ai-foundry' ) );
		}
		setApiKey( returned );
	}, [ apiKey ] );

	// Remove the API key.
	const removeApiKey = useCallback( async () => {
		await apiFetch( {
			path: `/wp/v2/settings?_fields=${ API_KEY_OPTION }`,
			method: 'POST',
			data: { [ API_KEY_OPTION ]: '' },
		} );
		setApiKey( '' );
		setModelName( '' );
		setCapabilities( [] );
	}, [] );

	// Save endpoint URL.
	const saveEndpoint = useCallback( async ( url ) => {
		const result = await apiFetch( {
			path: '/wp/v2/settings',
			method: 'POST',
			data: { [ ENDPOINT_OPTION ]: url },
		} );
		if ( result[ ENDPOINT_OPTION ] !== undefined ) {
			setEndpoint( result[ ENDPOINT_OPTION ] );
		}
	}, [] );

	// Auto-detect deployments and capabilities from the Azure endpoint.
	// The detect endpoint saves model_name + capabilities directly.
	const detectDeployments = useCallback( async ( detectEndpoint, detectApiKey ) => {
		const result = await apiFetch( {
			path: '/azure-ai-foundry/v1/detect',
			method: 'POST',
			data: {
				endpoint: detectEndpoint,
				api_key: detectApiKey,
			},
		} );

		// Update local state from the saved values.
		if ( result.model_name ) {
			setModelName( result.model_name );
		}
		if ( result.capabilities?.length ) {
			setCapabilities( result.capabilities );
		}

		return result;
	}, [] );

	return {
		isLoading,
		isConnected,
		apiKey,
		setApiKey,
		endpoint,
		modelName,
		capabilities,
		setEndpoint,
		saveApiKey,
		removeApiKey,
		saveEndpoint,
		detectDeployments,
	};
}

/**
 * The render component passed to registerConnector().
 */
function AzureAiFoundryConnector( { slug, name, description, logo } ) {
	const {
		isLoading,
		isConnected,
		apiKey,
		setApiKey,
		endpoint,
		modelName,
		capabilities,
		setEndpoint,
		saveApiKey,
		removeApiKey,
		saveEndpoint,
		detectDeployments,
	} = useAzureSettings();

	const [ isExpanded, setIsExpanded ] = useState( false );
	const [ isDetecting, setIsDetecting ] = useState( false );
	const [ statusMessage, setStatusMessage ] = useState( '' );
	const [ statusType, setStatusType ] = useState( '' ); // 'success' | 'error'
	const [ isReplacingApiKey, setIsReplacingApiKey ] = useState( false );

	const hasSavedApiKey = apiKey.startsWith( '•' );
	const canUseStoredApiKey = hasSavedApiKey && ! isReplacingApiKey;
	const canConnect = endpoint && isValidAzureEndpoint( endpoint ) && ( apiKey || canUseStoredApiKey );

	const handleConnect = async () => {
		setIsDetecting( true );
		setStatusMessage( '' );
		try {
			if ( ! canUseStoredApiKey && apiKey ) {
				await saveApiKey( apiKey );
			}
			await saveEndpoint( endpoint );
			const result = await detectDeployments( endpoint, apiKey );
			setIsReplacingApiKey( false );
			const capCount = result.capabilities?.length || 0;
			const models = result.model_name || '';
			setStatusMessage(
				capCount
					? __( 'Connected — detected ' + capCount + ' capability(s) and ' + models.split( ',' ).length + ' deployment(s).', 'azure-ai-foundry' )
					: __( 'Connected but no capabilities detected.', 'azure-ai-foundry' )
			);
			setStatusType( capCount ? 'success' : 'error' );
		} catch ( e ) {
			setStatusMessage(
				e.message || __( 'Detection failed. Check endpoint and API key.', 'azure-ai-foundry' )
			);
			setStatusType( 'error' );
		} finally {
			setIsDetecting( false );
		}
	};

	const handleRedetect = async () => {
		setIsDetecting( true );
		setStatusMessage( '' );
		try {
			const result = await detectDeployments( endpoint, apiKey );
			const capCount = result.capabilities?.length || 0;
			setStatusMessage(
				capCount
					? __( 'Refreshed — detected ' + capCount + ' capability(s).', 'azure-ai-foundry' )
					: __( 'No capabilities detected.', 'azure-ai-foundry' )
			);
			setStatusType( capCount ? 'success' : 'error' );
		} catch ( e ) {
			setStatusMessage(
				e.message || __( 'Detection failed.', 'azure-ai-foundry' )
			);
			setStatusType( 'error' );
		} finally {
			setIsDetecting( false );
		}
	};

	// Loading state.
	if ( isLoading ) {
		return el( ConnectorItem, {
			logo: logo || el( CloudIcon ),
			name,
			description,
			actionArea: el( 'span', { className: 'spinner is-active' } ),
		} );
	}

	const buttonLabel = isConnected
		? __( 'Edit', 'azure-ai-foundry' )
		: __( 'Set Up', 'azure-ai-foundry' );

	const actionButton = el( Button, {
		variant: isConnected ? 'tertiary' : 'secondary',
		size: isConnected ? undefined : 'compact',
		onClick: () => setIsExpanded( ! isExpanded ),
		'aria-expanded': isExpanded,
	}, buttonLabel );

	// Deployments list from comma-separated model names.
	const deploymentNames = modelName ? modelName.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) : [];

	// Settings panel (shown when expanded).
	const settingsPanel = isExpanded && el( 'div', null,
		// ── API Key ─────────────────────────────────────────
		el( 'h3', null, __( 'API Key', 'azure-ai-foundry' ) ),
		el( TextControl, {
			label: __( 'API Key', 'azure-ai-foundry' ),
			value: apiKey,
			onChange: ( value ) => {
				setStatusMessage( '' );
				setApiKey( value );
			},
			placeholder: 'YOUR_API_KEY',
			help: hasSavedApiKey && ! isReplacingApiKey
				? __( 'Your API key is stored securely. You can replace it if needed.', 'azure-ai-foundry' )
				: __( 'Get your API key from Azure AI Foundry.', 'azure-ai-foundry' ),
			disabled: isDetecting || ( hasSavedApiKey && ! isReplacingApiKey ),
			__next40pxDefaultSize: true,
		} ),
		hasSavedApiKey && ! isReplacingApiKey && el( Button, {
			variant: 'link',
			isDestructive: true,
			onClick: async () => {
				await removeApiKey();
				setIsReplacingApiKey( true );
				setStatusMessage( '' );
			},
		}, __( 'Remove and replace', 'azure-ai-foundry' ) ),

		el( 'hr' ),

		// ── Endpoint URL ────────────────────────────────────
		el( TextControl, {
			label: __( 'Endpoint URL', 'azure-ai-foundry' ),
			value: endpoint,
			onChange: setEndpoint,
			placeholder: 'https://my-resource.services.ai.azure.com',
			help: isValidAzureEndpoint( endpoint )
				? __( 'Your Azure AI resource URL. Deployments and capabilities are auto-detected.', 'azure-ai-foundry' )
				: __( 'URL must be an Azure endpoint (*.services.ai.azure.com, *.openai.azure.com, or *.cognitiveservices.azure.com).', 'azure-ai-foundry' ),
			__next40pxDefaultSize: true,
			className: isValidAzureEndpoint( endpoint ) ? '' : 'has-error',
		} ),
		! isValidAzureEndpoint( endpoint ) && el( 'style', null,
			'.has-error .components-base-control__help { color: #cc1818; }'
		),

		// ── Connect / Refresh button ────────────────────────
		el( 'div', {
			style: { marginTop: 12, display: 'flex', alignItems: 'center', gap: 12 },
		},
			el( Button, {
				variant: 'primary',
				__next40pxDefaultSize: true,
				onClick: handleConnect,
				isBusy: isDetecting,
				disabled: isDetecting || ! canConnect,
			}, deploymentNames.length
				? __( 'Save & Re-detect', 'azure-ai-foundry' )
				: __( 'Connect & Detect', 'azure-ai-foundry' )
			),
			statusMessage && el( 'span', {
				style: {
					fontSize: '13px',
					color: statusType === 'error' ? '#cc1818' : '#00a32a',
				},
			}, statusMessage ),
		),

		// ── Detected info (read-only) ───────────────────────
		( deploymentNames.length > 0 || capabilities.length > 0 ) && el( 'div', {
			style: {
				marginTop: 20,
				padding: '16px 20px',
				background: '#f6f7f7',
				borderRadius: 8,
				border: '1px solid #e0e0e0',
			},
		},
			el( 'div', {
				style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
			},
				el( 'span', { style: { fontWeight: 600, fontSize: '13px' } },
					__( 'Detected Configuration', 'azure-ai-foundry' )
				),
				el( Button, {
					variant: 'tertiary',
					size: 'compact',
					onClick: handleRedetect,
					isBusy: isDetecting,
					disabled: isDetecting || ! endpoint || ! apiKey,
				}, __( 'Refresh', 'azure-ai-foundry' ) ),
			),

			// Deployments.
			deploymentNames.length > 0 && el( 'div', { style: { marginBottom: 10 } },
				el( 'span', { style: { fontSize: '12px', color: '#757575', display: 'block', marginBottom: 4 } },
					__( 'Deployments', 'azure-ai-foundry' )
				),
				el( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: 6 } },
					...deploymentNames.map( ( dep ) =>
						el( 'code', {
							key: dep,
							style: {
								padding: '2px 8px',
								borderRadius: '4px',
								background: '#e8e8e8',
								fontSize: '12px',
							},
						}, dep )
					),
				),
			),

			// Capabilities.
			capabilities.length > 0 && el( 'div', null,
				el( 'span', { style: { fontSize: '12px', color: '#757575', display: 'block', marginBottom: 4 } },
					__( 'Capabilities', 'azure-ai-foundry' )
				),
				el( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: 6 } },
					...capabilities.map( ( cap ) =>
						el( 'span', {
							key: cap,
							style: {
								display: 'inline-flex',
								alignItems: 'center',
								padding: '3px 10px',
								borderRadius: '12px',
								background: '#e1f0e8',
								color: '#1e4620',
								fontSize: '12px',
								lineHeight: '18px',
							},
						}, CAPABILITY_LABELS[ cap ] || cap )
					),
				),
			),
		),
	);

	return el( ConnectorItem, {
		logo: logo || el( CloudIcon ),
		name,
		description,
		actionArea: actionButton,
	}, settingsPanel );
}

/**
 * Cloud icon (24 × 24) from @wordpress/icons.
 */
function CloudIcon() {
	return el( 'svg', {
		width: 40,
		height: 40,
		viewBox: '0 0 24 24',
		xmlns: 'http://www.w3.org/2000/svg',
		'aria-hidden': 'true',
	},
		el( 'path', {
			fill: '#0078D4',
			d: 'M17.3 10.1c0-2.5-2.1-4.4-4.8-4.4-2.2 0-4.1 1.4-4.6 3.3h-.2C5.7 9 4 10.7 4 12.8c0 2.1 1.7 3.8 3.7 3.8h9c1.8 0 3.2-1.5 3.2-3.3.1-1.6-1.1-2.9-2.6-3.2zm-.5 5.1h-9c-1.2 0-2.2-1.1-2.2-2.3s1-2.4 2.2-2.4h1.3l.3-1.1c.4-1.3 1.7-2.2 3.2-2.2 1.8 0 3.3 1.3 3.3 2.9v1.3l1.3.2c.8.1 1.4.9 1.4 1.8-.1 1-.9 1.8-1.8 1.8z',
		} ),
	);
}

// ── Register ────────────────────────────────────────────────────
// Slug format: {type}/{id} — matches the AI Client provider slug.
registerConnector( 'ai_provider/azure-ai-foundry', {
	name: __( 'Azure AI Foundry', 'azure-ai-foundry' ),
	description: __( 'Connect to Azure AI Foundry Model Inference API for text generation, image generation, embeddings, text-to-speech, and more.', 'azure-ai-foundry' ),
	render: AzureAiFoundryConnector,
} );
