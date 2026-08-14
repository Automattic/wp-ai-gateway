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
     * Returns provider model metadata for the OpenAI-compatible /models surface.
     *
     * @param string $provider_id Provider ID.
     * @return list<array{id:string,capabilities:list<string>,metadata:array<string,mixed>}>
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

        $models_data = [];
        foreach ($models as $model) {
            if (is_object($model) && method_exists($model, 'getId')) {
                $capabilities = self::model_capabilities($model);
                $models_data[] = [
                    'id' => (string) $model->getId(),
                    'capabilities' => $capabilities,
                    'metadata' => [
                        'provider' => sanitize_key($provider_id),
                        'model' => (string) $model->getId(),
                        'retrieval' => [
                            'embedding' => in_array('embedding_generation', $capabilities, true),
                        ],
                        'policy' => [
                            'retention' => null,
                            'no_training' => null,
                            'region' => null,
                        ],
                    ],
                ];
            }
        }

        return $models_data;
    }

    /**
     * Generates embeddings through WordPress AI Client for a resolved provider route.
     *
     * @param array{provider:string,model:string} $route Resolved provider route.
     * @param array<string, mixed>                $payload OpenAI-compatible request payload.
     * @return array{embeddings:list<list<float|int>>,usage:array<string,mixed>|null,request_id:string|null}|WP_REST_Response
     */
    public static function generate_embeddings(array $route, array $payload)
    {
        $registry = self::registry();
        if (!$registry || !method_exists($registry, 'getProviderModel')) {
            return OpenAiResponse::error('server_error', 'WordPress AI Client provider registry is not available.', 500);
        }

        self::bind_provider_api_key($registry, $route['provider']);

        try {
            $model = $registry->getProviderModel($route['provider'], $route['model'], self::model_config_from_payload($payload));
            $prompt = wp_ai_client_prompt(self::normalize_embedding_input($payload['input']));
            $prompt = $prompt->using_model($model);

            if (!method_exists($prompt, 'generateEmbeddingResult')) {
                return OpenAiResponse::error(
                    'unsupported_capability',
                    'Embedding generation requires upstream WordPress AI Client embedding result support.',
                    501
                );
            }

            $result = $prompt->generateEmbeddingResult();
        } catch (\Throwable $e) {
            return OpenAiResponse::error('server_error', $e->getMessage(), 500);
        }

        return self::embedding_result_to_array($result);
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
        $key = self::provider_api_key($provider);

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
    public static function provider_api_key(string $provider): string
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
     * Normalizes OpenAI embedding input into user messages.
     *
     * @param mixed $input OpenAI embedding input.
     * @return list<object>
     */
    private static function normalize_embedding_input($input): array
    {
        $items = is_array($input) ? $input : [$input];
        $messages = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $item = implode(' ', array_map('strval', $item));
            }

            if (!is_scalar($item)) {
                continue;
            }

            $text = trim((string) $item);
            if ('' === $text) {
                continue;
            }

            $messages[] = new \WordPress\AiClient\Messages\DTO\Message(
                \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
                [new \WordPress\AiClient\Messages\DTO\MessagePart($text)]
            );
        }

        return $messages;
    }

    /**
     * Extracts model capabilities from WordPress AI Client metadata.
     *
     * @param object $model Model metadata object.
     * @return list<string>
     */
    private static function model_capabilities(object $model): array
    {
        if (!method_exists($model, 'getSupportedCapabilities')) {
            return [];
        }

        $capabilities = [];
        foreach ($model->getSupportedCapabilities() as $capability) {
            if (is_object($capability) && isset($capability->value) && is_string($capability->value)) {
                $capabilities[] = $capability->value;
            } elseif (is_scalar($capability)) {
                $capabilities[] = (string) $capability;
            }
        }

        return array_values(array_unique($capabilities));
    }

    /**
     * Converts a future WordPress AI Client embedding result into OpenAI-compatible data.
     *
     * @param mixed $result WordPress AI Client embedding result.
     * @return array{embeddings:list<list<float|int>>,usage:array<string,mixed>|null,request_id:string|null}|WP_REST_Response
     */
    private static function embedding_result_to_array($result)
    {
        $data = is_object($result) && method_exists($result, 'toArray') ? $result->toArray() : $result;
        if (!is_array($data)) {
            return OpenAiResponse::error('server_error', 'Embedding result must be array-like.', 500);
        }

        $embeddings = $data['embeddings'] ?? ($data['additionalData']['embeddings'] ?? null);
        if (!is_array($embeddings)) {
            return OpenAiResponse::error('server_error', 'Embedding result did not include embeddings.', 500);
        }

        return [
            'embeddings' => self::normalize_embedding_vectors($embeddings),
            'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : (is_array($data['tokenUsage'] ?? null) ? $data['tokenUsage'] : null),
            'request_id' => is_string($data['id'] ?? null) ? $data['id'] : null,
        ];
    }

    /**
     * Normalizes embedding vectors into numeric lists.
     *
     * @param array<mixed> $embeddings Embedding vectors.
     * @return list<list<float|int>>
     */
    private static function normalize_embedding_vectors(array $embeddings): array
    {
        $vectors = [];
        foreach ($embeddings as $embedding) {
            if (!is_array($embedding)) {
                continue;
            }

            $vector = [];
            foreach ($embedding as $value) {
                if (is_int($value) || is_float($value)) {
                    $vector[] = $value;
                } elseif (is_numeric($value)) {
                    $vector[] = (float) $value;
                }
            }
            $vectors[] = $vector;
        }

        return $vectors;
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
