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
     * Generates a structured text result through WordPress AI Client.
     *
     * @param array{provider:string,model:string} $route Resolved provider route.
     * @param array<string, mixed>                $payload OpenAI-compatible request payload.
     * @return array<string, mixed>|WP_REST_Response
     */
    public static function generate_text_result(array $route, array $payload)
    {
        $registry = self::registry();
        if (!$registry || !method_exists($registry, 'getProviderModel')) {
            return OpenAiResponse::error('server_error', 'WordPress AI Client provider registry is not available.', 500);
        }

        self::bind_provider_api_key($registry, $route['provider']);

        try {
            $declarations = self::normalize_tools($payload['tools'] ?? []);
            if ('none' === self::normalize_tool_choice($payload['tool_choice'] ?? null)) {
                $declarations = [];
            }
            self::validate_parallel_tool_calls($payload);
            $model = $registry->getProviderModel($route['provider'], $route['model'], self::model_config_from_payload($payload));
            $prompt = wp_ai_client_prompt(self::normalize_messages($payload['messages']))->using_model($model);
            if ([] !== $declarations) {
                $prompt = $prompt->using_function_declarations(...$declarations);
            }
            $result = $prompt->generate_text_result();
        } catch (\InvalidArgumentException $e) {
            return OpenAiResponse::error('invalid_request_error', $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return OpenAiResponse::error('server_error', $e->getMessage(), 500);
        }

        if ($result instanceof WP_Error) {
            return OpenAiResponse::from_wp_error($result);
        }

        try {
            return self::completion_from_result($result);
        } catch (\InvalidArgumentException $e) {
            return OpenAiResponse::error('server_error', $e->getMessage(), 500);
        }
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
        $system_instruction = self::system_instruction_from_messages($payload['messages'] ?? []);
        if ('' !== $system_instruction) {
            $config['systemInstruction'] = $system_instruction;
        }
        foreach (['max_tokens', 'temperature', 'top_p', 'stop', 'presence_penalty', 'frequency_penalty'] as $key) {
            if (array_key_exists($key, $payload)) {
                $config[$key] = $payload[$key];
            }
        }

        return $class::fromArray($config);
    }

    /**
     * Collects OpenAI system messages for the AI Client model instruction.
     *
     * @param mixed $messages OpenAI messages payload.
     * @return string
     */
    private static function system_instruction_from_messages($messages): string
    {
        if (!is_array($messages)) {
            return '';
        }

        $instructions = [];
        foreach ($messages as $message) {
            if (!is_array($message) || 'system' !== ($message['role'] ?? null)) {
                continue;
            }
            $content = self::message_content_to_text($message['content'] ?? '');
            if ('' !== $content) {
                $instructions[] = $content;
            }
        }
        return implode("\n\n", $instructions);
    }

    /**
     * Converts OpenAI function declarations to AI Client DTOs.
     *
     * @param mixed $tools OpenAI tools payload.
     * @return list<object>
     */
    private static function normalize_tools($tools): array
    {
        if (!is_array($tools)) {
            throw new \InvalidArgumentException('tools must be an array.');
        }

        $declarations = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || 'function' !== ($tool['type'] ?? null) || !isset($tool['function']) || !is_array($tool['function'])) {
                throw new \InvalidArgumentException('Each tool must have type "function" and a function object.');
            }
            $function = $tool['function'];
            if (!is_string($function['name'] ?? null) || '' === $function['name']) {
                throw new \InvalidArgumentException('Function tools require a non-empty name.');
            }
            if (isset($function['description']) && !is_string($function['description'])) {
                throw new \InvalidArgumentException('Function tool descriptions must be strings.');
            }
            $parameters = $function['parameters'] ?? null;
            if (null !== $parameters) {
                if (!is_array($parameters) || ([] !== $parameters && array_keys($parameters) === range(0, count($parameters) - 1))) {
                    throw new \InvalidArgumentException('Function tool parameters must be a JSON object.');
                }
                if ([] === $parameters) {
                    $parameters = ['type' => 'object'];
                } elseif (isset($parameters['properties']) && [] === $parameters['properties']) {
                    unset($parameters['properties']);
                }
            }
            $declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
                $function['name'],
                $function['description'] ?? '',
                $parameters
            );
        }

        return $declarations;
    }

    /**
     * Validates the OpenAI tool choice values supported by WordPress AI Client.
     *
     * @param mixed $tool_choice OpenAI tool choice.
     * @return string
     */
    private static function normalize_tool_choice($tool_choice): string
    {
        if (null === $tool_choice || 'auto' === $tool_choice) {
            return 'auto';
        }
        if ('none' === $tool_choice) {
            return 'none';
        }
        throw new \InvalidArgumentException('tool_choice supports only "auto" and "none".');
    }

    /**
     * Rejects parallel-call controls that WordPress AI Client cannot enforce.
     *
     * @param array<string, mixed> $payload Request payload.
     * @return void
     */
    private static function validate_parallel_tool_calls(array $payload): void
    {
        if (array_key_exists('parallel_tool_calls', $payload) && true !== $payload['parallel_tool_calls']) {
            throw new \InvalidArgumentException('parallel_tool_calls supports only true.');
        }
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
        $call_names = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                throw new \InvalidArgumentException('Each message must be an object.');
            }

            $role = $message['role'] ?? null;
            if (!is_string($role) || !in_array($role, ['system', 'user', 'assistant', 'model', 'tool'], true)) {
                throw new \InvalidArgumentException('Each message requires a supported role.');
            }
            if ('system' === $role) {
                if (isset($message['tool_calls'])) {
                    throw new \InvalidArgumentException('Only assistant messages may contain tool_calls.');
                }
                continue;
            }
            if ('tool' === $role) {
                $id = $message['tool_call_id'] ?? null;
                if (!is_string($id) || '' === $id) {
                    throw new \InvalidArgumentException('Tool messages require tool_call_id.');
                }
                $name = is_string($message['name'] ?? null) ? $message['name'] : ($call_names[$id] ?? null);
                if (!is_string($name) || '' === $name) {
                    throw new \InvalidArgumentException('Tool messages must correspond to a preceding assistant tool call.');
                }
                $normalized[] = new \WordPress\AiClient\Messages\DTO\Message(
                    \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
                    [new \WordPress\AiClient\Messages\DTO\MessagePart(
                        new \WordPress\AiClient\Tools\DTO\FunctionResponse($id, $name, self::decode_tool_response($message['content'] ?? ''))
                    )]
                );
                continue;
            }

            $content = self::message_content_to_text($message['content'] ?? '');
            if ('assistant' === $role || 'model' === $role) {
                if ('' !== $content) {
                    $normalized[] = new \WordPress\AiClient\Messages\DTO\Message(
                        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model(),
                        [new \WordPress\AiClient\Messages\DTO\MessagePart($content)]
                    );
                }
                foreach (self::normalize_tool_calls($message['tool_calls'] ?? []) as $call) {
                    $call_names[$call->getId()] = $call->getName();
                    $normalized[] = new \WordPress\AiClient\Messages\DTO\Message(
                        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model(),
                        [new \WordPress\AiClient\Messages\DTO\MessagePart($call)]
                    );
                }
                continue;
            }
            if (isset($message['tool_calls'])) {
                throw new \InvalidArgumentException('Only assistant messages may contain tool_calls.');
            }
            if ('' === $content) {
                continue;
            }

            $normalized[] = new \WordPress\AiClient\Messages\DTO\Message(
                \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
                [new \WordPress\AiClient\Messages\DTO\MessagePart($content)]
            );
        }

        return $normalized;
    }

    /**
     * Converts OpenAI assistant tool calls to AI Client DTOs.
     *
     * @param mixed $tool_calls OpenAI tool calls payload.
     * @return list<object>
     */
    private static function normalize_tool_calls($tool_calls): array
    {
        if (!is_array($tool_calls)) {
            throw new \InvalidArgumentException('assistant tool_calls must be an array.');
        }
        $calls = [];
        foreach ($tool_calls as $tool_call) {
            if (!is_array($tool_call) || 'function' !== ($tool_call['type'] ?? null) || !is_string($tool_call['id'] ?? null) || '' === $tool_call['id'] || !is_array($tool_call['function'] ?? null)) {
                throw new \InvalidArgumentException('Assistant tool calls require id, type "function", and a function object.');
            }
            $function = $tool_call['function'];
            if (!is_string($function['name'] ?? null) || '' === $function['name'] || !is_string($function['arguments'] ?? null)) {
                throw new \InvalidArgumentException('Assistant function calls require a name and JSON string arguments.');
            }
            $args = json_decode($function['arguments'], true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new \InvalidArgumentException('Assistant function arguments must be valid JSON.');
            }
            $calls[] = new \WordPress\AiClient\Tools\DTO\FunctionCall($tool_call['id'], $function['name'], $args);
        }
        return $calls;
    }

    /**
     * Decodes JSON tool output while preserving non-JSON text.
     *
     * @param mixed $content Tool output.
     * @return mixed
     */
    private static function decode_tool_response($content)
    {
        if (!is_string($content)) {
            return $content;
        }
        $decoded = json_decode($content, true);
        return JSON_ERROR_NONE === json_last_error() ? $decoded : $content;
    }

    /**
     * Converts an AI Client result into an OpenAI completion choice.
     *
     * @param object $result AI Client generative result.
     * @return array<string, mixed>
     */
    private static function completion_from_result($result): array
    {
        if (!is_object($result) || !method_exists($result, 'getCandidates')) {
            throw new \InvalidArgumentException('Text generation did not return a structured result.');
        }
        $candidates = $result->getCandidates();
        if (!is_array($candidates) || [] === $candidates) {
            throw new \InvalidArgumentException('Text generation result did not include a candidate.');
        }
        $content = [];
        $tool_calls = [];
        $finish_reason = 'stop';
        foreach ($candidates as $candidate) {
            if (!is_object($candidate) || !method_exists($candidate, 'getMessage')) {
                throw new \InvalidArgumentException('Text generation result included an invalid candidate.');
            }
            $message = $candidate->getMessage();
            if (!is_object($message) || !method_exists($message, 'getParts')) {
                throw new \InvalidArgumentException('Text generation candidate did not include a message.');
            }
            foreach ($message->getParts() as $part) {
                if (method_exists($part, 'getText') && null !== $part->getText()) {
                    $content[] = $part->getText();
                }
                if (method_exists($part, 'getFunctionCall') && null !== $part->getFunctionCall()) {
                    $call = $part->getFunctionCall();
                    if (!is_string($call->getName()) || '' === $call->getName()) {
                        throw new \InvalidArgumentException('Provider function calls require a non-empty name.');
                    }
                    $args = $call->getArgs();
                    if (null === $args || (is_array($args) && [] === $args)) {
                        $args = new \stdClass();
                    }
                    $arguments = wp_json_encode($args);
                    if (!is_string($arguments)) {
                        throw new \InvalidArgumentException('Function call arguments could not be JSON encoded.');
                    }
                    $tool_calls[] = [
                        'id' => $call->getId() ?: 'call_' . wp_generate_uuid4(),
                        'type' => 'function',
                        'function' => ['name' => $call->getName(), 'arguments' => $arguments],
                    ];
                }
            }
            $finish_reason = self::finish_reason($candidate);
        }
        $finish_reason = [] !== $tool_calls ? 'tool_calls' : $finish_reason;
        $openai_message = ['role' => 'assistant', 'content' => [] === $content ? null : implode('', $content)];
        if ([] !== $tool_calls) {
            $openai_message['tool_calls'] = $tool_calls;
        }
        return ['index' => 0, 'message' => $openai_message, 'finish_reason' => $finish_reason];
    }

    /**
     * Maps AI Client finish reasons to OpenAI finish reasons.
     *
     * @param object $candidate AI Client candidate.
     * @return string
     */
    private static function finish_reason(object $candidate): string
    {
        if (!method_exists($candidate, 'getFinishReason')) {
            return 'stop';
        }
        $reason = $candidate->getFinishReason();
        $value = is_object($reason) && isset($reason->value) ? $reason->value : (string) $reason;
        return in_array($value, ['stop', 'length', 'content_filter'], true) ? $value : 'stop';
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
