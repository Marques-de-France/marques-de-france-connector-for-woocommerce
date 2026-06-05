import { __ } from '@wordpress/i18n';

export default function PendingApproval() {
	return (
		<div
			style={ {
				display: 'flex',
				flexDirection: 'column',
				alignItems: 'center',
				justifyContent: 'center',
				minHeight: '50vh',
				gap: '16px',
				padding: '48px 24px',
				textAlign: 'center',
				boxSizing: 'border-box',
			} }
		>
			<div
				style={ {
					display: 'inline-flex',
					alignItems: 'center',
					gap: '8px',
					padding: '10px 18px',
					borderRadius: '4px',
					backgroundColor: '#fff3cd',
					border: '1px solid #ffc107',
					color: '#856404',
					fontWeight: 600,
					fontSize: '14px',
				} }
			>
				<span>⚠</span>
				{ __(
					'Access not authorized',
					'marques-de-france-connector-for-woocommerce'
				) }
			</div>

			<p
				style={ {
					maxWidth: '480px',
					margin: 0,
					color: '#555',
					fontSize: '14px',
					lineHeight: '1.6',
				} }
			>
				{ __(
					'You are not authorized to use the application because your brand is not listed on our guide Marques de France.',
					'marques-de-france-connector-for-woocommerce'
				) }
			</p>

			<p
				style={ {
					maxWidth: '480px',
					margin: 0,
					color: '#555',
					fontSize: '14px',
					lineHeight: '1.6',
				} }
			>
				{ __(
					'If you have French manufactured products, you can register your brand on',
					'marques-de-france-connector-for-woocommerce'
				) }{ ' ' }
				<a
					href="https://www.marques-de-france.fr/"
					target="_blank"
					rel="noopener noreferrer"
				>
					Marques de France
				</a>
				{ __(
					' now.',
					'marques-de-france-connector-for-woocommerce'
				) }
			</p>
		</div>
	);
}
