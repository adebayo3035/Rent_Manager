/**
 * Payment reference generator — method-aware.
 *
 * Format: PREFIX-YYYYMMDD-RANDOM
 *   BNK-20261014-8F3A2C
 *   CSH-20261014-4D9E1B
 *   CHQ-20261014-A72C54
 *   CRD-20261014-3E5F81
 *   WLT-20261014-6B2D47
 *   OTH-20261014-1A9C3E
 */
const PaymentRefs = (() => {
    const PREFIXES = {
        bank_transfer: 'BNK',
        cash:          'CSH',
        cheque:        'CHQ',
        card:          'CRD',
        wallet_offset: 'WLT',
        other:         'OTH',
    };

    const HEX = '0123456789ABCDEF';

    function randomHex(len) {
        let out = '';
        const bytes = new Uint8Array(len / 2);
        crypto.getRandomValues(bytes);
        for (let i = 0; i < bytes.length; i++) {
            out += HEX[bytes[i] >> 4] + HEX[bytes[i] & 0x0f];
        }
        return out;
    }

    function today() {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}${m}${day}`;
    }

    return {
        /**
         * Generate a fresh reference for the given method.
         */
        generate(method) {
            const m = String(method || '').toLowerCase();
            const prefix = PREFIXES[m] || 'PMT';
            return `${prefix}-${today()}-${randomHex(6)}`;
        },

        /**
         * Validate a reference on the frontend.
         * Returns { valid: boolean, message: string }
         */
        validate(ref, method) {
            if (!ref || typeof ref !== 'string') {
                return { valid: false, message: 'Reference is required' };
            }
            const trimmed = ref.trim().toUpperCase();

            // Format: PREFIX-YYYYMMDD-HEX(6)
            const match = trimmed.match(/^([A-Z]{3})-(\d{8})-([0-9A-F]{6})$/);
            if (!match) {
                return {
                    valid: false,
                    message: 'Reference must be in format XXX-YYYYMMDD-XXXXXX',
                };
            }

            // Prefix must match method
            const expectedPrefix = PREFIXES[String(method).toLowerCase()];
            if (expectedPrefix && match[1] !== expectedPrefix) {
                return {
                    valid: false,
                    message: `Reference prefix ${match[1]} does not match method ${method}`,
                };
            }

            return { valid: true, message: '' };
        },

        /**
         * Get the prefix for a given method (for UI hints).
         */
        prefixFor(method) {
            return PREFIXES[String(method).toLowerCase()] || 'PMT';
        },
    };
})();

window.PaymentRefs = PaymentRefs;