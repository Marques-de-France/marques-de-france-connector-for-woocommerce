import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';

const { token: initialToken, configured } = window.mdfcforwcAdmin || {};

// Sentinel used to display masked dots when a token is already configured
// but the raw value is intentionally not passed to the frontend.
const TOKEN_MASK = '••••••••••••••••••••••••••••••••';

export default function Settings() {
	const [ token, setToken ] = useState( initialToken || '' );
	const [ isMasked, setIsMasked ] = useState(
		configured && ! initialToken
	);
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const handleSave = async ( e ) => {
		e.preventDefault();

		// The raw token is never sent to the browser, so while the field still shows
		// the mask sentinel there is no new value to save. Posting the empty state
		// behind the mask would wipe the stored token, which silently disables the
		// feed's authentication and stops sales syncing to the Hub.
		if ( isMasked ) {
			setNotice( {
				type: 'success',
				message: __(
					'No changes to save.',
					'marques-de-france-connector-for-woocommerce'
				),
			} );
			return;
		}

		const trimmedToken = token.trim();

		if ( '' === trimmedToken ) {
			setNotice( {
				type: 'error',
				message: __(
					'Please enter your secure token.',
					'marques-de-france-connector-for-woocommerce'
				),
			} );
			return;
		}

		setSaving( true );
		setNotice( null );
		try {
			await apiFetch( {
				path: '/mdfcforwc/v1/admin/settings',
				method: 'POST',
				data: { mdfcforwc_secure_token: trimmedToken },
			} );
			// Re-mask so the saved token is not left readable in the field.
			setToken( '' );
			setIsMasked( true );
			setNotice( {
				type: 'success',
				message: __(
					'Settings saved.',
					'marques-de-france-connector-for-woocommerce'
				),
			} );
		} catch {
			setNotice( {
				type: 'error',
				message: __(
					'Failed to save settings.',
					'marques-de-france-connector-for-woocommerce'
				),
			} );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="mdf-page mdf-settings">
			{ /* Connection section */ }
			<div className="mdf-settings__section">
				<h3 className="mdf-settings__section-title">
					{ __(
						'Connection',
						'marques-de-france-connector-for-woocommerce'
					) }
				</h3>

				{ notice && (
					<div
						className={ `notice notice-${ notice.type === 'success' ? 'success' : 'error' } is-dismissible` }
						style={ { margin: '0 0 16px' } }
					>
						<p>{ notice.message }</p>
					</div>
				) }

				<form onSubmit={ handleSave }>
					<div className="mdf-field">
						<label
							className="mdf-field__label"
							htmlFor="mdf-secure-token"
						>
							{ __(
								'Secure Token',
								'marques-de-france-connector-for-woocommerce'
							) }
						</label>
						<input
							id="mdf-secure-token"
							type="password"
							className="mdf-input mdf-secure-token"
							style={{ width: '100%' }}
							value={ isMasked ? TOKEN_MASK : token }
							onFocus={ () => {
								if ( isMasked ) {
									setIsMasked( false );
									setToken( '' );
								}
							} }
							onBlur={ () => {
								// Left untouched after focusing: restore the mask so the
								// stored token is not mistaken for a cleared field.
								if ( configured && '' === token.trim() ) {
									setIsMasked( true );
								}
							} }
							onChange={ ( e ) => setToken( e.target.value ) }
							autoComplete="new-password"
						/>
						<p className="mdf-field__desc">
							{ __(
								'The token provided by Marques de France when your store was registered.',
								'marques-de-france-connector-for-woocommerce'
							) }
						</p>
					</div>
					<Button variant="primary" type="submit" isBusy={ saving }>
						{ __(
							'Save settings',
							'marques-de-france-connector-for-woocommerce'
						) }
					</Button>
				</form>
			</div>

		</div>
	);
}
