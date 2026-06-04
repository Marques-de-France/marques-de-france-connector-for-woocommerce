import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';

const { token: initialToken } = window.mdfcforwcAdmin || {};

export default function Settings() {
	const [ token, setToken ] = useState( initialToken || '' );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const handleSave = async ( e ) => {
		e.preventDefault();
		setSaving( true );
		setNotice( null );
		try {
			await apiFetch( {
				path: '/mdfcforwc/v1/admin/settings',
				method: 'POST',
				data: { mdfcforwc_secure_token: token },
			} );
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
							value={ token }
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
