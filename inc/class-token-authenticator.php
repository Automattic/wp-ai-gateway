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
    private const CLIENT_ID_LENGTH = 16;
    private const SECRET_LENGTH = 48;

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
     * Generates an independently revocable client credential.
     *
     * @param string       $label Human-readable client label.
     * @param int          $expires_in Lifetime in seconds; zero means no expiry.
     * @param list<string> $allowed_models Allowed external model IDs.
     * @param int          $user_id WordPress user to establish after authentication.
     * @return array{token:string,client:array<string,mixed>}
     */
    public static function generate_client_token(string $label, int $expires_in = 0, array $allowed_models = [MODEL_SITE_DEFAULT], int $user_id = 0): array
    {
        if ($user_id > 0 && function_exists('get_userdata') && false === get_userdata($user_id)) {
            throw new \InvalidArgumentException('Gateway client user does not exist.');
        }

        $lock = self::acquire_clients_lock();
        try {
            $clients = self::clients();

            do {
                $client_id = strtolower(wp_generate_password(self::CLIENT_ID_LENGTH, false, false));
            } while (isset($clients[$client_id]));

            $token = 'wpag_' . $client_id . '_' . wp_generate_password(self::SECRET_LENGTH, false, false);
            $now = time();
            $client = [
                'id' => $client_id,
                'label' => self::sanitize_label($label),
                'token_hash' => hash('sha256', $token),
                'created_at' => $now,
                'expires_at' => $expires_in > 0 ? $now + $expires_in : 0,
                'revoked_at' => 0,
                'last_used_at' => 0,
                'allowed_models' => self::sanitize_allowed_models($allowed_models),
                'user_id' => max(0, $user_id),
            ];

            $clients[$client_id] = $client;
            update_option(OPTION_CLIENTS, $clients, false);

            return [
                'token' => $token,
                'client' => self::public_client($client),
            ];
        } finally {
            self::release_clients_lock($lock);
        }
    }

    /**
     * Returns sanitized client credential records keyed by client ID.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function clients(): array
    {
        $stored = get_option(OPTION_CLIENTS, []);
        if (!is_array($stored)) {
            return [];
        }

        $clients = [];
        foreach ($stored as $client_id => $record) {
            $client = self::sanitize_client($client_id, $record);
            if (null !== $client) {
                $clients[$client_id] = $client;
            }
        }

        return $clients;
    }

    /**
     * Returns client metadata without credential hashes.
     *
     * @return list<array<string,mixed>>
     */
    public static function public_clients(): array
    {
        return array_values(array_map([self::class, 'public_client'], self::clients()));
    }

    /**
     * Revokes one client without affecting other credentials.
     *
     * @param string $client_id Client ID.
     * @return bool Whether the client existed.
     */
    public static function revoke_client(string $client_id): bool
    {
        $lock = self::acquire_clients_lock();
        try {
            $clients = self::clients();
            $client_id = sanitize_key($client_id);
            if (!isset($clients[$client_id])) {
                return false;
            }

            $revoked_at = time();
            update_option(OPTION_CLIENT_REVOKED_PREFIX . $client_id, $revoked_at, false);
            $clients[$client_id]['revoked_at'] = $revoked_at;
            update_option(OPTION_CLIENTS, $clients, false);
            return true;
        } finally {
            self::release_clients_lock($lock);
        }
    }

    /**
     * Rotates one client credential without changing its principal or policy.
     *
     * @param string   $client_id Client ID.
     * @param int|null $expires_in New lifetime, or null to preserve the current expiry.
     * @return array{token:string,client:array<string,mixed>}|null
     */
    public static function rotate_client(string $client_id, ?int $expires_in = null): ?array
    {
        $lock = self::acquire_clients_lock();
        try {
            $clients = self::clients();
            $client_id = sanitize_key($client_id);
            if (!isset($clients[$client_id]) || $clients[$client_id]['revoked_at'] > 0 || self::revoked_at($client_id) > 0) {
                return null;
            }

            $token = 'wpag_' . $client_id . '_' . wp_generate_password(self::SECRET_LENGTH, false, false);
            $now = time();
            $clients[$client_id]['token_hash'] = hash('sha256', $token);
            $clients[$client_id]['created_at'] = $now;
            if (null !== $expires_in) {
                $clients[$client_id]['expires_at'] = $expires_in > 0 ? $now + $expires_in : 0;
            }
            update_option(OPTION_CLIENTS, $clients, false);
            delete_option(OPTION_CLIENT_LAST_USED_PREFIX . $client_id);

            return [
                'token' => $token,
                'client' => self::public_client($clients[$client_id]),
            ];
        } finally {
            self::release_clients_lock($lock);
        }
    }

    /**
     * Returns whether a gateway token hash is configured.
     *
     * @return bool
     */
    public static function token_hash_exists(): bool
    {
        return '' !== self::sanitize_token_hash(get_option(OPTION_TOKEN_HASH, '')) || [] !== self::clients();
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
     * @return array<string,mixed>|WP_REST_Response Authenticated principal or error.
     */
    public static function authorize(?WP_REST_Request $request = null)
    {
        if (current_user_can('manage_options')) {
            return self::principal('wordpress-user-' . self::current_user_id(), 'WordPress administrator', ['*'], self::current_user_id(), 'wordpress');
        }

        if (!$request instanceof WP_REST_Request) {
            return OpenAiResponse::error('authentication_error', 'Missing bearer token.', 401);
        }

        $token = self::bearer_token($request);
        if ('' === $token) {
            return OpenAiResponse::error('authentication_error', 'Missing bearer token.', 401);
        }

        $principal = self::authenticate_client_token($token);
        if (is_array($principal)) {
            self::establish_principal($principal, $request);
            return $principal;
        }

        $hash = self::sanitize_token_hash(get_option(OPTION_TOKEN_HASH, ''));
        if ('' !== $hash && hash_equals($hash, hash('sha256', $token))) {
            $principal = self::principal('legacy', 'Legacy site token', ['*'], 0, 'legacy');
            self::establish_principal($principal, $request);
            return $principal;
        }

        return OpenAiResponse::error('authentication_error', 'Invalid bearer token.', 403);
    }

    /**
     * Checks whether a principal may use an external model ID.
     *
     * @param array<string,mixed> $principal Authenticated principal.
     * @param string              $requested_model External model ID.
     * @return bool
     */
    public static function model_allowed(array $principal, string $requested_model): bool
    {
        $allowed = self::sanitize_allowed_models($principal['allowed_models'] ?? []);
        if (in_array('*', $allowed, true)) {
            return true;
        }

        $requested_model = ProviderRouter::policy_model_id($requested_model);
        return in_array($requested_model, $allowed, true);
    }

    /**
     * Authenticates a scoped client token.
     *
     * @param string $token Bearer token.
     * @return array<string,mixed>|null
     */
    private static function authenticate_client_token(string $token): ?array
    {
        if (!preg_match('/^wpag_([a-z0-9]{16})_([a-zA-Z0-9]{48})$/', $token, $matches)) {
            return null;
        }

        $clients = self::clients();
        $client_id = $matches[1];
        $client = $clients[$client_id] ?? null;
        if (!is_array($client) || !hash_equals($client['token_hash'], hash('sha256', $token))) {
            return null;
        }

        $now = time();
        if ($client['revoked_at'] > 0 || self::revoked_at($client_id) > 0 || ($client['expires_at'] > 0 && $client['expires_at'] <= $now)) {
            return null;
        }

        if ($client['user_id'] > 0 && function_exists('get_userdata') && false === get_userdata($client['user_id'])) {
            return null;
        }

        $last_used_at = max(0, (int) get_option(OPTION_CLIENT_LAST_USED_PREFIX . $client_id, 0));
        if ($last_used_at < $now - 60) {
            update_option(OPTION_CLIENT_LAST_USED_PREFIX . $client_id, $now, false);
        }

        return self::principal($client_id, $client['label'], $client['allowed_models'], $client['user_id'], 'client');
    }

    /**
     * Establishes the client-bound WordPress user and exposes an audit hook.
     *
     * @param array<string,mixed> $principal Authenticated principal.
     * @param WP_REST_Request     $request REST request.
     * @return void
     */
    private static function establish_principal(array $principal, WP_REST_Request $request): void
    {
        if ($principal['user_id'] > 0 && function_exists('wp_set_current_user')) {
            wp_set_current_user($principal['user_id']);
        }

        do_action('wp_ai_gateway_client_authenticated', $principal, $request);
    }

    /**
     * Builds a non-secret authenticated principal.
     *
     * @param string       $id Principal ID.
     * @param string       $label Principal label.
     * @param list<string> $allowed_models Model policy.
     * @param int          $user_id WordPress user ID.
     * @param string       $type Principal type.
     * @return array<string,mixed>
     */
    private static function principal(string $id, string $label, array $allowed_models, int $user_id, string $type): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'allowed_models' => self::sanitize_allowed_models($allowed_models),
            'user_id' => max(0, $user_id),
            'type' => $type,
        ];
    }

    /**
     * Sanitizes one stored client.
     *
     * @param mixed $client_id Client ID.
     * @param mixed $record Stored record.
     * @return array<string,mixed>|null
     */
    private static function sanitize_client($client_id, $record): ?array
    {
        if (!is_string($client_id) || !preg_match('/^[a-z0-9]{16}$/', $client_id) || !is_array($record)) {
            return null;
        }

        $hash = self::sanitize_token_hash($record['token_hash'] ?? '');
        if ('' === $hash) {
            return null;
        }

        $revoked_at = self::revoked_at($client_id);

        return [
            'id' => $client_id,
            'label' => self::sanitize_label($record['label'] ?? ''),
            'token_hash' => $hash,
            'created_at' => max(0, (int) ($record['created_at'] ?? 0)),
            'expires_at' => max(0, (int) ($record['expires_at'] ?? 0)),
            'revoked_at' => max($revoked_at, max(0, (int) ($record['revoked_at'] ?? 0))),
            'last_used_at' => max(0, (int) ($record['last_used_at'] ?? 0)),
            'allowed_models' => self::sanitize_allowed_models($record['allowed_models'] ?? []),
            'user_id' => max(0, (int) ($record['user_id'] ?? 0)),
        ];
    }

    /**
     * Removes credential material from a client record.
     *
     * @param array<string,mixed> $client Client record.
     * @return array<string,mixed>
     */
    private static function public_client(array $client): array
    {
        unset($client['token_hash']);
        $client['last_used_at'] = max(0, (int) get_option(OPTION_CLIENT_LAST_USED_PREFIX . $client['id'], $client['last_used_at'] ?? 0));
        return $client;
    }

    /**
     * Sanitizes a client label.
     *
     * @param mixed $label Client label.
     * @return string
     */
    private static function sanitize_label($label): string
    {
        $label = is_string($label) ? sanitize_text_field($label) : '';
        return '' !== $label ? $label : 'Gateway client';
    }

    /**
     * Sanitizes model policy values.
     *
     * @param mixed $models Model IDs.
     * @return list<string>
     */
    private static function sanitize_allowed_models($models): array
    {
        if (!is_array($models)) {
            return [MODEL_SITE_DEFAULT];
        }

        $sanitized = [];
        foreach ($models as $model) {
            if (!is_string($model)) {
                continue;
            }
            $model = trim($model);
            if ('*' === $model) {
                $sanitized[] = '*';
                continue;
            }
            $model = ProviderRouter::policy_model_id($model);
            if ('' !== $model) {
                $sanitized[] = $model;
            }
        }

        return [] !== $sanitized ? array_values(array_unique($sanitized)) : [MODEL_SITE_DEFAULT];
    }

    /**
     * Returns the current WordPress user ID when available.
     *
     * @return int
     */
    private static function current_user_id(): int
    {
        return function_exists('get_current_user_id') ? max(0, (int) get_current_user_id()) : 0;
    }

    /**
     * Returns the immutable revocation tombstone for a client.
     *
     * @param string $client_id Client ID.
     * @return int
     */
    private static function revoked_at(string $client_id): int
    {
        return max(0, (int) get_option(OPTION_CLIENT_REVOKED_PREFIX . $client_id, 0));
    }

    /**
     * Acquires the mutation lock for the shared client registry.
     *
     * @return string Lock owner token.
     */
    private static function acquire_clients_lock(): string
    {
        $owner = wp_generate_uuid4();
        for ($attempt = 0; $attempt < 500; ++$attempt) {
            if (add_option(OPTION_CLIENTS_LOCK, ['owner' => $owner, 'created_at' => time()], '', false)) {
                return $owner;
            }

            usleep(10000);
        }

        throw new \RuntimeException('Gateway client registry is busy.');
    }

    /**
     * Releases a client registry lock still owned by this operation.
     *
     * @param string $owner Lock owner token.
     * @return void
     */
    private static function release_clients_lock(string $owner): void
    {
        $current = get_option(OPTION_CLIENTS_LOCK, []);
        if (is_array($current) && hash_equals($owner, (string) ($current['owner'] ?? ''))) {
            delete_option(OPTION_CLIENTS_LOCK);
        }
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
