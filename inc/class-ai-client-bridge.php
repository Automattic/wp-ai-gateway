<?php
/**
 * WordPress AI Client integration.
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

use WP_Error;
use WP_REST_Response;

/**
 * Bridges OpenAI-compatible requests to WordPress AI Client.
 */
final class AiClientBridge
{
    /**
     * Returns the WordPress AI Client registry when available.
     *
     * @return object|null
     */
    public static function registry(): ?object
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
     * Generates text through WordPress AI Client for a resolved provider route.
     *
     * @param array{provider:string,model:string} $route Resolved provider route.
     * @param array<string, mixed>                $payload OpenAI-compatible request payload.
     * @return string|WP_REST_Response
     */
    public static function generate_text(array $route, array $payload)
    {
        $registry = self::registry();
        if (!$registry || !method_exists($registry, 'getProviderModel')) {
            return OpenAiResponse::error('server_error', 'WordPress AI Client provider registry is not available.', 500);
        }

        self::bind_provider_api_key($registry, $route['provider']);

        try {
            $model = $registry->getProviderModel($route['provider'], $route['model'], self::model_config_from_payload($payload));
            $text = wp_ai_client_prompt(self::normalize_messages($payload['messages']))
                ->using_model($model)
                ->generate_text();
        } catch (\Throwable $e) {
            return OpenAiResponse::error('server_error', $e->getMessage(), 500);
        }

        if ($text instanceof WP_Error) {
            return OpenAiResponse::from_wp_error($text);
        }

        return is_string($text) ? $text : (string) $text;
    }

    /**
     * Returns provider model IDs for the OpenAI-compatible /models surface.
     *
     * @param string $provider_id Provider ID.
     * @return list<string>
     */
    public static function provider_models(string $provider_id): array
    {
        $registry = self::registry();
        if (!$registry || !method_exists($registry, 'getProviderClassName')) {
            return [];
        }

        self::bind_provider_api_key($registry, $provider_id);

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
     * Binds a provider API key to a registry when one is available.
     *
     * @param object $registry WordPress AI Client provider registry.
     * @param string $provider Provider ID.
     * @return void
     */
    private static function bind_provider_api_key(object $registry, string $provider): void
    {
        $class = '\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication';
        $key = self::resolve_provider_api_key($provider);

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
    private static function resolve_provider_api_key(string $provider): string
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
     * Converts request payload generation settings to ModelConfig.
     *
     * @param array<string, mixed> $payload Request payload.
     * @return object|null
     */
    private static function model_config_from_payload(array $payload): ?object
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
    private static function normalize_messages(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = is_string($message['role'] ?? null) ? $message['role'] : 'user';
            $content = self::message_content_to_text($message['content'] ?? '');
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
    private static function message_content_to_text($content): string
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
}
