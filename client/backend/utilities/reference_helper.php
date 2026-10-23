<?php
// client/backend/utilities/reference_helper.php
// Deterministic reference generation for client top-ups.
// The reference is derived from the client_code so that both the
// "show bank details" step and the "submit top-up" step produce the
// same value WITHOUT ever trusting the client to send it back.

/**
 * Derive the top-up reference for a client.
 *
 * Deterministic: same client_code → same reference. Format: TPU-<CLIENT_CODE>.
 * This is the value the client should include in their bank transfer
 * description, and the value we store on the wallet_requests row.
 *
 * @param string $clientCode
 * @return string
 */
function clientTopupReference(string $clientCode): string
{
    // Sanitize to keep the reference URL/statement-safe.
    // client_code is already restricted by the schema, but belt-and-braces.
    $safeCode = preg_replace('/[^A-Za-z0-9\-_]/', '', $clientCode);
    return 'TPU-' . strtoupper($safeCode);
}

/**
 * Validate that a client-supplied reference (if any) matches the
 * server-derived one. Returns true if they match OR if the client
 * didn't supply one (in which case the server value is authoritative).
 *
 * You will normally NOT call this from topup_initiate.php because we
 * simply ignore the client-supplied reference entirely. It's provided
 * for callers that want to explicitly reject mismatches (e.g. for
 * debugging or stricter endpoints).
 */
function clientTopupReferenceMatches(string $clientCode, string $clientSupplied): bool
{
    $clientSupplied = trim($clientSupplied);
    if ($clientSupplied === '') {
        return true; // client didn't send one — server value wins
    }
    return hash_equals(clientTopupReference($clientCode), $clientSupplied);
}