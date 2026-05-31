<?php
/**
 * Plugin Name: WP AI Gateway
 * Plugin URI: https://github.com/chubes4/wp-ai-gateway
 * Description: OpenAI-compatible AI gateway for WordPress, backed by the WordPress AI Client.
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Version: 0.1.0
 * Author: Chris Huber
 * Author URI: https://github.com/chubes4
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: wp-ai-gateway
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    return;
}

const REST_NAMESPACE = 'wp-ai-gateway/v1';
const OPTION_TOKEN_HASH = 'wp_ai_gateway_token_hash';
const OPTION_PROVIDER = 'wp_ai_gateway_provider';
const OPTION_MODEL = 'wp_ai_gateway_model';
const MODEL_SITE_DEFAULT = 'site-default';

add_action('rest_api_init', __NAMESPACE__ . '\register_rest_routes');
add_action('admin_menu', __NAMESPACE__ . '\register_settings_page');
add_action('admin_init', __NAMESPACE__ . '\register_settings');

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('ai-gateway', __NAMESPACE__ . '\CliCommand');
}

/**
 * Registers the OpenAI-compatible REST surface.
 *
 * @return void
 */
function register_rest_routes(): void
{
    register_rest_route(
        REST_NAMESPACE,
        '/models',
        [
            'methods' => 'GET',
            'callback' => __NAMESPACE__ . '\handle_models',
            'permission_callback' => __NAMESPACE__ . '\permission_callback',
        ]
    );

    register_rest_route(
        REST_NAMESPACE,
        '/chat/completions',
        [
            'methods' => 'POST',
            'callback' => __NAMESPACE__ . '\handle_chat_completions',
            'permission_callback' => __NAMESPACE__ . '\permission_callback',
        ]
    );
}

/**
 * Checks gateway access.
 *
 * @param WP_REST_Request $request REST request.
 * @return true|WP_Error
 */
function permission_callback(WP_REST_Request $request)
{
    unset($request);
    return true;
}

/**
 * Handles OpenAI-compatible model listing.
 *
 * @return WP_REST_Response|WP_Error
 */
function handle_models(WP_REST_Request $request)
{
    $authorized = authorize_gateway_request($request);
    if ($authorized instanceof WP_REST_Response) {
        return $authorized;
    }

    $configured = configured_route();
    if ($configured instanceof WP_Error || $configured instanceof WP_REST_Response) {
        return $configured;
    }

    $models = [
        openai_model_payload(MODEL_SITE_DEFAULT, 'wordpress'),
    ];

    $registry = ai_registry();
    if ($registry && method_exists($registry, 'getRegisteredProviderIds')) {
        foreach ($registry->getRegisteredProviderIds() as $provider_id) {
            $provider_models = provider_models((string) $provider_id);
            foreach ($provider_models as $model_id) {
                $models[] = openai_model_payload(model_alias((string) $provider_id, $model_id), 'wordpress');
            }
        }
    }

    return new WP_REST_Response(
        [
            'object' => 'list',
            'data' => array_values(unique_models($models)),
        ]
    );
}

/**
 * Handles OpenAI-compatible chat completions.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function handle_chat_completions(WP_REST_Request $request)
{
    $authorized = authorize_gateway_request($request);
    if ($authorized instanceof WP_REST_Response) {
        return $authorized;
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        return openai_error('invalid_request_error', 'Request body must be JSON.', 400);
    }

    $messages = $payload['messages'] ?? null;
    if (!is_array($messages) || [] === $messages) {
        return openai_error('invalid_request_error', 'Request body must include messages.', 400);
    }

    $route = route_for_requested_model(is_string($payload['model'] ?? null) ? $payload['model'] : MODEL_SITE_DEFAULT);
    if ($route instanceof WP_Error || $route instanceof WP_REST_Response) {
        return $route;
    }

    $registry = ai_registry();
    if (!$registry || !method_exists($registry, 'getProviderModel')) {
        return openai_error('server_error', 'WordPress AI Client provider registry is not available.', 500);
    }

    bind_provider_api_key($registry, $route['provider']);

    try {
        $model = $registry->getProviderModel($route['provider'], $route['model'], model_config_from_payload($payload));
        $text = wp_ai_client_prompt(normalize_messages($messages))
            ->using_model($model)
            ->generate_text();
    } catch (\Throwable $e) {
        return openai_error('server_error', $e->getMessage(), 500);
    }

    if ($text instanceof WP_Error) {
        return wp_error_to_openai_error($text);
    }

    $content = is_string($text) ? $text : (string) $text;

    return new WP_REST_Response(
        [
            'id' => 'chatcmpl-' . wp_generate_uuid4(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $payload['model'] ?? MODEL_SITE_DEFAULT,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => null,
        ]
    );
}

/**
 * Registers the admin settings page.
 *
 * @return void
 */
function register_settings_page(): void
{
    add_options_page(
        'WP AI Gateway',
        'WP AI Gateway',
        'manage_options',
        'wp-ai-gateway',
        __NAMESPACE__ . '\render_settings_page'
    );
}

/**
 * Registers gateway settings.
 *
 * @return void
 */
function register_settings(): void
{
    register_setting('wp_ai_gateway', OPTION_PROVIDER, ['type' => 'string', 'sanitize_callback' => 'sanitize_key']);
    register_setting('wp_ai_gateway', OPTION_MODEL, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
    register_setting('wp_ai_gateway', OPTION_TOKEN_HASH, ['type' => 'string', 'sanitize_callback' => __NAMESPACE__ . '\sanitize_token_hash']);
}

/**
 * Sanitizes a stored gateway token hash.
 *
 * @param mixed $hash Token hash.
 * @return string
 */
function sanitize_token_hash($hash): string
{
    if (!is_string($hash)) {
        return '';
    }

    $hash = strtolower(trim($hash));
    return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
}

/**
 * Renders the admin settings page.
 *
 * @return void
 */
function render_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    ?>
    <div class="wrap">
        <h1>WP AI Gateway</h1>
        <p>Expose this WordPress site as an OpenAI-compatible gateway backed by the WordPress AI Client.</p>
        <form method="post" action="options.php">
            <?php settings_fields('wp_ai_gateway'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(OPTION_PROVIDER); ?>">Provider</label></th>
                    <td><input name="<?php echo esc_attr(OPTION_PROVIDER); ?>" id="<?php echo esc_attr(OPTION_PROVIDER); ?>" type="text" class="regular-text" value="<?php echo esc_attr(configured_provider()); ?>" placeholder="opencode" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(OPTION_MODEL); ?>">Model</label></th>
                    <td><input name="<?php echo esc_attr(OPTION_MODEL); ?>" id="<?php echo esc_attr(OPTION_MODEL); ?>" type="text" class="regular-text" value="<?php echo esc_attr(configured_model()); ?>" placeholder="opencode-go/kimi-k2.6" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Returns the configured provider/model route.
 *
 * @return array{provider:string,model:string}|WP_REST_Response
 */
function configured_route()
{
    $provider = configured_provider();
    $model = configured_model();

    if ('' === $provider || '' === $model) {
        return openai_error('server_error', 'Gateway provider and model are not configured.', 500);
    }

    return [
        'provider' => $provider,
        'model' => $model,
    ];
}

/**
 * Returns machine-readable gateway status without exposing secrets.
 *
 * @return array<string, mixed>
 */
function gateway_status(): array
{
    $provider = configured_provider();
    $model = configured_model();
    $registry = ai_registry();
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
        'token_hash_exists' => token_hash_exists(),
        'ai_client_available' => null !== $registry,
        'registered_providers' => $registered_providers,
        'provider_registered' => '' !== $provider && in_array($provider, $registered_providers, true),
        'endpoints' => [
            'models' => rest_url(REST_NAMESPACE . '/models'),
            'chat_completions' => rest_url(REST_NAMESPACE . '/chat/completions'),
        ],
    ];
}

/**
 * Resolves the route for a requested OpenAI-compatible model ID.
 *
 * @param string $requested_model Requested model.
 * @return array{provider:string,model:string}|WP_REST_Response
 */
function route_for_requested_model(string $requested_model)
{
    if (MODEL_SITE_DEFAULT === $requested_model || '' === $requested_model) {
        return configured_route();
    }

    $delimiter = strpos($requested_model, ':');
    if (false === $delimiter) {
        return configured_route();
    }

    return [
        'provider' => sanitize_key(substr($requested_model, 0, $delimiter)),
        'model' => sanitize_text_field(substr($requested_model, $delimiter + 1)),
    ];
}

/**
 * Returns the configured provider ID.
 *
 * @return string
 */
function configured_provider(): string
{
    $provider = get_option(OPTION_PROVIDER, '');
    return is_string($provider) ? sanitize_key($provider) : '';
}

/**
 * Returns the configured model ID.
 *
 * @return string
 */
function configured_model(): string
{
    $model = get_option(OPTION_MODEL, '');
    return is_string($model) ? sanitize_text_field($model) : '';
}

/**
 * Returns whether a gateway token hash is configured.
 *
 * @return bool
 */
function token_hash_exists(): bool
{
    return '' !== sanitize_token_hash(get_option(OPTION_TOKEN_HASH, ''));
}

/**
 * Returns the WordPress AI Client registry when available.
 *
 * @return object|null
 */
function ai_registry(): ?object
{
    if (!function_exists('wp_supports_ai') || !wp_supports_ai()) {
        return null;
    }

    $class = '\WordPress\AiClient\AiClient';
    if (!class_exists($class)) {
        return null;
    }

    try {
        return $class::defaultRegistry();
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Binds a provider API key to a registry when one is available.
 *
 * @param object $registry WordPress AI Client provider registry.
 * @param string $provider Provider ID.
 * @return void
 */
function bind_provider_api_key(object $registry, string $provider): void
{
    $class = '\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication';
    $key = resolve_provider_api_key($provider);

    if ('' === $key || !class_exists($class) || !method_exists($registry, 'setProviderRequestAuthentication')) {
        return;
    }

    try {
        $registry->setProviderRequestAuthentication($provider, new $class($key));
    } catch (\Throwable $e) {
        // Providers with custom or provider-supplied auth should continue without gateway-injected API keys.
    }
}

/**
 * Resolves a provider API key from filters, environment, constants, or Connectors-style options.
 *
 * @param string $provider Provider ID.
 * @return string
 */
function resolve_provider_api_key(string $provider): string
{
    $provider = sanitize_key($provider);
    $filtered = apply_filters('wp_ai_gateway_provider_api_key', '', $provider);
    if (is_string($filtered) && '' !== $filtered) {
        return $filtered;
    }

    $constant = strtoupper(str_replace(['-', ' '], '_', $provider)) . '_API_KEY';
    $env = getenv($constant);
    if (is_string($env) && '' !== $env) {
        return $env;
    }

    if (defined($constant)) {
        $value = constant($constant);
        if (is_scalar($value) && '' !== (string) $value) {
            return (string) $value;
        }
    }

    $option = get_option('connectors_ai_' . str_replace('-', '_', $provider) . '_api_key', '');
    return is_string($option) ? $option : '';
}

/**
 * Returns provider model IDs for the OpenAI-compatible /models surface.
 *
 * @param string $provider_id Provider ID.
 * @return list<string>
 */
function provider_models(string $provider_id): array
{
    $registry = ai_registry();
    if (!$registry || !method_exists($registry, 'getProviderClassName')) {
        return [];
    }

    bind_provider_api_key($registry, $provider_id);

    try {
        $provider_class = $registry->getProviderClassName($provider_id);
        $models = $provider_class::modelMetadataDirectory()->listModelMetadata();
    } catch (\Throwable $e) {
        return [];
    }

    $ids = [];
    foreach ($models as $model) {
        if (is_object($model) && method_exists($model, 'getId')) {
            $ids[] = (string) $model->getId();
        }
    }

    return $ids;
}

/**
 * Builds a provider-qualified model alias for external clients.
 *
 * @param string $provider Provider ID.
 * @param string $model Model ID.
 * @return string
 */
function model_alias(string $provider, string $model): string
{
    return sanitize_key($provider) . ':' . $model;
}

/**
 * Creates a model payload in OpenAI's list shape.
 *
 * @param string $id Model ID.
 * @param string $owned_by Owner label.
 * @return array<string, mixed>
 */
function openai_model_payload(string $id, string $owned_by): array
{
    return [
        'id' => $id,
        'object' => 'model',
        'created' => 0,
        'owned_by' => $owned_by,
    ];
}

/**
 * De-duplicates OpenAI model payloads by ID.
 *
 * @param list<array<string, mixed>> $models Model payloads.
 * @return array<string, array<string, mixed>>
 */
function unique_models(array $models): array
{
    $unique = [];
    foreach ($models as $model) {
        if (isset($model['id']) && is_string($model['id'])) {
            $unique[$model['id']] = $model;
        }
    }

    return $unique;
}

/**
 * Converts request payload generation settings to ModelConfig.
 *
 * @param array<string, mixed> $payload Request payload.
 * @return object|null
 */
function model_config_from_payload(array $payload): ?object
{
    $class = '\WordPress\AiClient\Providers\Models\DTO\ModelConfig';
    if (!class_exists($class) || !method_exists($class, 'fromArray')) {
        return null;
    }

    $config = [];
    foreach (['max_tokens', 'temperature', 'top_p', 'stop', 'presence_penalty', 'frequency_penalty'] as $key) {
        if (array_key_exists($key, $payload)) {
            $config[$key] = $payload[$key];
        }
    }

    return $class::fromArray($config);
}

/**
 * Normalizes OpenAI messages for wp_ai_client_prompt().
 *
 * @param list<mixed> $messages OpenAI messages.
 * @return list<object>
 */
function normalize_messages(array $messages): array
{
    $normalized = [];

    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }

        $role = is_string($message['role'] ?? null) ? $message['role'] : 'user';
        $content = message_content_to_text($message['content'] ?? '');
        if ('' === $content) {
            continue;
        }

        $normalized[] = new \WordPress\AiClient\Messages\DTO\Message(
            'assistant' === $role || 'model' === $role
                ? \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model()
                : \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
            [new \WordPress\AiClient\Messages\DTO\MessagePart($content)]
        );
    }

    return $normalized;
}

/**
 * Converts OpenAI message content into text for the MVP gateway.
 *
 * @param mixed $content Message content.
 * @return string
 */
function message_content_to_text($content): string
{
    if (is_string($content)) {
        return $content;
    }

    if (!is_array($content)) {
        return '';
    }

    $parts = [];
    foreach ($content as $part) {
        if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
            $parts[] = $part['text'];
        }
    }

    return implode("\n", $parts);
}

/**
 * Extracts a bearer token from the request.
 *
 * @param WP_REST_Request $request REST request.
 * @return string
 */
function bearer_token(WP_REST_Request $request): string
{
    $header = $request->get_header('authorization');
    if (!is_string($header) || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return '';
    }

    return trim($matches[1]);
}

/**
 * Checks gateway access and returns OpenAI-shaped errors for REST endpoints.
 *
 * @param WP_REST_Request|null $request REST request.
 * @return true|WP_REST_Response
 */
function authorize_gateway_request(?WP_REST_Request $request = null)
{
    if (current_user_can('manage_options')) {
        return true;
    }

    if (!$request instanceof WP_REST_Request) {
        return openai_error('authentication_error', 'Missing bearer token.', 401);
    }

    $token = bearer_token($request);
    if ('' === $token) {
        return openai_error('authentication_error', 'Missing bearer token.', 401);
    }

    $hash = sanitize_token_hash(get_option(OPTION_TOKEN_HASH, ''));
    if ('' === $hash) {
        return openai_error('authentication_error', 'Gateway token is not configured.', 403);
    }

    if (!hash_equals($hash, hash('sha256', $token))) {
        return openai_error('authentication_error', 'Invalid bearer token.', 403);
    }

    return true;
}

/**
 * Converts a WP_Error to an OpenAI-compatible error response.
 *
 * @param WP_Error $error WordPress error.
 * @return WP_REST_Response
 */
function wp_error_to_openai_error(WP_Error $error): WP_REST_Response
{
    $status = 500;
    $data = $error->get_error_data();
    if (is_array($data) && isset($data['status']) && is_numeric($data['status'])) {
        $status = (int) $data['status'];
    }

    return openai_error((string) $error->get_error_code(), $error->get_error_message(), $status);
}

/**
 * Creates an OpenAI-compatible error response.
 *
 * @param string $type Error type.
 * @param string $message Error message.
 * @param int    $status HTTP status.
 * @return WP_REST_Response
 */
function openai_error(string $type, string $message, int $status): WP_REST_Response
{
    return new WP_REST_Response(
        [
            'error' => [
                'message' => $message,
                'type' => $type,
                'param' => null,
                'code' => $type,
            ],
        ],
        $status
    );
}

/**
 * WP-CLI command for gateway setup.
 */
class CliCommand
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

        $token = 'wpag_' . wp_generate_password(48, false, false);
        update_option(OPTION_TOKEN_HASH, hash('sha256', $token), false);

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
     * : Provider ID, for example opencode.
     *
     * <model>
     * : Provider model ID, for example opencode-go/kimi-k2.6.
     *
     * ## EXAMPLES
     *
     *     wp ai-gateway configure opencode opencode-go/kimi-k2.6
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
        update_option(OPTION_PROVIDER, sanitize_key($provider), false);
        update_option(OPTION_MODEL, sanitize_text_field($model), false);

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
        $format = $assoc_args['format'] ?? 'table';
        $status = gateway_status();

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
}
