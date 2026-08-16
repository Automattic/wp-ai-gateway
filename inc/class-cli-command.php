<?php
/**
 * WP-CLI command.
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

/**
 * WP-CLI command for gateway setup.
 */
final class CliCommand
{
    /**
     * Generates and rotates the trusted legacy site token.
     *
     * ## EXAMPLES
     *
     *     wp ai-gateway token
     *     wp ai-gateway token --porcelain
     *
     * @param list<string>         $args Command arguments.
     * @param array<string,string> $assoc_args Command options.
     * @return void
     */
    public function token(array $args = [], array $assoc_args = []): void
    {
        unset($args);

        $token = TokenAuthenticator::generate_token();

        if (isset($assoc_args['porcelain'])) {
            \WP_CLI::line($token);
            return;
        }

        \WP_CLI::success('Gateway token generated. Store it now; it will not be shown again.');
        \WP_CLI::line($token);
    }

    /**
     * Generates a short-lived, user-bound, site-default-only runtime token.
     *
     * ## OPTIONS
     *
     * --user=<id>
     * : WordPress user whose provider context the runtime uses.
     *
     * [--label=<label>]
     * : Human-readable runtime label.
     *
     * [--expires-in=<seconds>]
     * : Credential lifetime in seconds. Defaults to 3600.
     *
     * [--porcelain]
     * : Print only the one-time token.
     *
     * @param list<string>         $args Command arguments.
     * @param array<string,string> $assoc_args Command options.
     * @return void
     * @subcommand runtime-token
     */
    public function runtime_token(array $args = [], array $assoc_args = []): void
    {
        unset($args);
        $user_id = isset($assoc_args['user']) ? max(0, (int) $assoc_args['user']) : 0;
        $expires_in = isset($assoc_args['expires-in']) ? max(1, (int) $assoc_args['expires-in']) : 3600;
        if ($user_id < 1 || (function_exists('get_userdata') && false === get_userdata($user_id))) {
            \WP_CLI::error('A valid --user=<id> is required.');
        }

        $created = TokenAuthenticator::generate_client_token(
            $assoc_args['label'] ?? 'Hosted runtime',
            $expires_in,
            [MODEL_SITE_DEFAULT],
            $user_id
        );

        if (isset($assoc_args['porcelain'])) {
            \WP_CLI::line($created['token']);
            return;
        }

        \WP_CLI::success(sprintf('Gateway runtime client %s generated for %d seconds.', $created['client']['id'], $expires_in));
        \WP_CLI::line($created['token']);
    }

    /**
     * Lists gateway clients without exposing credential hashes.
     *
     * @param list<string>         $args Command arguments.
     * @param array<string,string> $assoc_args Command options.
     * @return void
     */
    public function clients(array $args = [], array $assoc_args = []): void
    {
        unset($args);
        $format = $assoc_args['format'] ?? 'table';
        $clients = TokenAuthenticator::public_clients();

        if ('json' === $format) {
            \WP_CLI::line((string) wp_json_encode($clients));
            return;
        }

        $clients = array_map(
            static function (array $client): array {
                $client['allowed_models'] = implode(',', $client['allowed_models']);
                return $client;
            },
            $clients
        );

        \WP_CLI\Utils\format_items($format, $clients, ['id', 'label', 'created_at', 'expires_at', 'revoked_at', 'last_used_at', 'allowed_models', 'user_id']);
    }

    /**
     * Revokes one gateway client.
     *
     * ## OPTIONS
     *
     * <client-id>
     * : Client ID returned by the token command.
     *
     * @param list<string> $args Command arguments.
     * @return void
     */
    public function revoke(array $args): void
    {
        if (!isset($args[0]) || !TokenAuthenticator::revoke_client($args[0])) {
            \WP_CLI::error('Gateway client not found.');
        }

        \WP_CLI::success('Gateway client revoked.');
    }

    /**
     * Rotates one scoped client credential.
     *
     * ## OPTIONS
     *
     * <client-id>
     * : Existing client ID.
     *
     * [--expires-in=<seconds>]
     * : New lifetime. Omit to preserve the existing expiry.
     *
     * [--porcelain]
     * : Print only the replacement token.
     *
     * @param list<string>         $args Command arguments.
     * @param array<string,string> $assoc_args Command options.
     * @return void
     */
    public function rotate(array $args, array $assoc_args = []): void
    {
        $expires_in = isset($assoc_args['expires-in']) ? max(1, (int) $assoc_args['expires-in']) : null;
        $rotated = isset($args[0]) ? TokenAuthenticator::rotate_client($args[0], $expires_in) : null;
        if (null === $rotated) {
            \WP_CLI::error('Gateway client not found.');
        }

        if (!isset($assoc_args['porcelain'])) {
            \WP_CLI::success('Gateway client credential rotated.');
        }
        \WP_CLI::line($rotated['token']);
    }

    /**
     * Configures the site-default provider/model route.
     *
     * ## OPTIONS
     *
     * <provider>
     * : Provider ID, for example example-provider.
     *
     * <model>
     * : Provider model ID, for example example-model.
     *
     * ## EXAMPLES
     *
     *     wp ai-gateway configure example-provider example-model
     *
     * @param list<string> $args Command arguments.
     * @return void
     */
    public function configure(array $args): void
    {
        if (count($args) < 2) {
            \WP_CLI::error('Usage: wp ai-gateway configure <provider> <model>');
        }

        [$provider, $model] = $args;
        ProviderRouter::configure($provider, $model);

        \WP_CLI::success(sprintf('Gateway site-default route set to %s / %s.', $provider, $model));
    }

    /**
     * Reports gateway setup status without exposing token values.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Supports table, json, yaml, count.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp ai-gateway status --format=json
     *
     * @param list<string>         $args Command arguments.
     * @param array<string,string> $assoc_args Command options.
     * @return void
     */
    public function status(array $args, array $assoc_args): void
    {
        unset($args);

        $format = $assoc_args['format'] ?? 'table';
        $status = self::gateway_status();

        if ('json' === $format) {
            \WP_CLI::line((string) wp_json_encode($status));
            return;
        }

        $row = [];
        foreach ($status as $key => $value) {
            if (is_array($value)) {
                $value = wp_json_encode($value);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $row[$key] = (string) $value;
        }

        if (function_exists('WP_CLI\\Utils\\format_items')) {
            \WP_CLI\Utils\format_items($format, [$row], array_keys($row));
            return;
        }

        foreach ($row as $key => $value) {
            \WP_CLI::line($key . ': ' . $value);
        }
    }

    /**
     * Returns machine-readable gateway status without exposing secrets.
     *
     * @return array<string, mixed>
     */
    public static function gateway_status(): array
    {
        $provider = ProviderRouter::configured_provider();
        $model = ProviderRouter::configured_model();
        $registry = AiClientBridge::registry();
        $registered_providers = [];

        if ($registry && method_exists($registry, 'getRegisteredProviderIds')) {
            foreach ($registry->getRegisteredProviderIds() as $provider_id) {
                $registered_providers[] = (string) $provider_id;
            }
        }

        return [
            'configured' => '' !== $provider && '' !== $model,
            'provider' => $provider,
            'model' => $model,
            'token_hash_exists' => TokenAuthenticator::token_hash_exists(),
            'client_count' => count(TokenAuthenticator::clients()),
            'ai_client_available' => null !== $registry,
            'registered_providers' => $registered_providers,
            'provider_registered' => '' !== $provider && in_array($provider, $registered_providers, true),
            'endpoints' => [
                'models' => rest_url(REST_NAMESPACE . '/models'),
                'responses' => rest_url(REST_NAMESPACE . '/responses'),
                'embeddings' => rest_url(REST_NAMESPACE . '/embeddings'),
            ],
        ];
    }
}
