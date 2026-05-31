<?php
/**
 * Gateway bearer-token authentication.
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Mints, stores, and validates gateway client tokens.
 */
final class TokenAuthenticator
{
    /**
     * Generates and stores a one-time gateway token.
     *
     * @return string Plaintext token to show once.
     */
    public static function generate_token(): string
    {
        $token = 'wpag_' . wp_generate_password(48, false, false);
        update_option(OPTION_TOKEN_HASH, hash('sha256', $token), false);

        return $token;
    }

    /**
     * Returns whether a gateway token hash is configured.
     *
     * @return bool
     */
    public static function token_hash_exists(): bool
    {
        return '' !== self::sanitize_token_hash(get_option(OPTION_TOKEN_HASH, ''));
    }

    /**
     * Sanitizes a stored gateway token hash.
     *
     * @param mixed $hash Token hash.
     * @return string
     */
    public static function sanitize_token_hash($hash): string
    {
        if (!is_string($hash)) {
            return '';
        }

        $hash = strtolower(trim($hash));
        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
    }

    /**
     * Checks gateway access and returns OpenAI-shaped REST errors.
     *
     * @param WP_REST_Request|null $request REST request.
     * @return true|WP_REST_Response
     */
    public static function authorize(?WP_REST_Request $request = null)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (!$request instanceof WP_REST_Request) {
            return OpenAiResponse::error('authentication_error', 'Missing bearer token.', 401);
        }

        $token = self::bearer_token($request);
        if ('' === $token) {
            return OpenAiResponse::error('authentication_error', 'Missing bearer token.', 401);
        }

        $hash = self::sanitize_token_hash(get_option(OPTION_TOKEN_HASH, ''));
        if ('' === $hash) {
            return OpenAiResponse::error('authentication_error', 'Gateway token is not configured.', 403);
        }

        if (!hash_equals($hash, hash('sha256', $token))) {
            return OpenAiResponse::error('authentication_error', 'Invalid bearer token.', 403);
        }

        return true;
    }

    /**
     * Extracts a bearer token from the request.
     *
     * @param WP_REST_Request $request REST request.
     * @return string
     */
    private static function bearer_token(WP_REST_Request $request): string
    {
        $header = $request->get_header('authorization');
        if (!is_string($header) || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return '';
        }

        return trim($matches[1]);
    }
}
