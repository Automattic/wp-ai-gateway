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
     * Generates and stores a gateway bearer token.
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
            'ai_client_available' => null !== $registry,
            'registered_providers' => $registered_providers,
            'provider_registered' => '' !== $provider && in_array($provider, $registered_providers, true),
            'endpoints' => [
                'models' => rest_url(REST_NAMESPACE . '/models'),
                'chat_completions' => rest_url(REST_NAMESPACE . '/chat/completions'),
            ],
        ];
    }
}
