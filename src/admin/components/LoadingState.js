import { Spinner } from '@wordpress/components';
import { __ } from "@wordpress/i18n";

export default function LoadingState({
    message = __(
        'Loading…',
        'marques-de-france-connector-for-woocommerce'
    ),
    className = 'mdf-loading',
    style = {},
    spinnerStyle = {},
}) {
    return (
        <div
            className={className}
            style={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 8,
                textAlign: 'center',
                ...style,
            }}
        >
            <Spinner
                style={{ width: 18, height: 18, ...spinnerStyle }}
                aria-label={message}
            />
            <span>{message}</span>
        </div>
    );
}
