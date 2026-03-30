/**
 * Setup window.wp globals for Vitest (classic-script mocks).
 */
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
			return React.createElement(
				'button',
				{
					'data-variant': props.variant,
					'data-size': props.size,
					'data-next40px': props.__next40pxDefaultSize || undefined,
					disabled: props.disabled,
					onClick: props.onClick,
				},
				children
			);
		},
		TextControl( props ) {
			return React.createElement(
				'div',
				null,
				React.createElement( 'label', null, props.label ),
				React.createElement( 'input', {
					type: 'text',
					value: props.value || '',
					onChange: ( e ) => props.onChange?.( e.target.value ),
				} )
			);
		},
	},
};
