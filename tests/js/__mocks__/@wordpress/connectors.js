import React from 'react';

export const __experimentalRegisterConnector = vi.fn();
export const __experimentalConnectorItem = ( { children, actionArea } ) =>
	React.createElement( 'div', null, actionArea, children );
export const __experimentalDefaultConnectorSettings = ( { children } ) =>
	children ?? null;
