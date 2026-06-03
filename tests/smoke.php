<?php
declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__);
    define('WP_CLI', false);

    $GLOBALS['wp_ai_gateway_options'] = [];
    $GLOBALS['wp_ai_gateway_current_user_can'] = false;

    class WP_Error
    {
        private string $code;
        private string $message;
        private $data;

        public function __construct(string $code, string $message = '', $data = null)
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data()
        {
            return $this->data;
        }
    }

    class WP_REST_Response
    {
        private $data;
        private int $status;

        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }

    class WP_REST_Request
    {
        private array $headers;
        private $json;

        public function __construct(array $headers = [], $json = null)
        {
            $this->headers = array_change_key_case($headers, CASE_LOWER);
            $this->json = $json;
        }

        public function get_header(string $name)
        {
            return $this->headers[strtolower($name)] ?? '';
        }

        public function get_json_params()
        {
            return $this->json;
        }
    }

    function add_action(): void {}
    function register_rest_route(): void {}
    function add_options_page(): void {}
    function register_setting(): void {}
    function current_user_can(): bool { return (bool) $GLOBALS['wp_ai_gateway_current_user_can']; }
    function get_option(string $name, $default = '') { return $GLOBALS['wp_ai_gateway_options'][$name] ?? $default; }
    function update_option(string $name, $value, bool $autoload = true): bool { unset($autoload); $GLOBALS['wp_ai_gateway_options'][$name] = $value; return true; }
    function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? ''; }
    function sanitize_text_field(string $text): string { return trim(strip_tags($text)); }
    function apply_filters(string $hook, $value) { unset($hook); return $value; }
    function rest_url(string $path): string { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
    function wp_json_encode($value): string { return json_encode($value, JSON_UNESCAPED_SLASHES); }
    function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000000'; }
    function wp_supports_ai(): bool { return true; }
    function wp_ai_client_prompt(array $messages): FakePrompt { return new FakePrompt($messages); }

    class FakePrompt
    {
        private array $messages;
        private $model;

        public function __construct(array $messages)
        {
            $this->messages = $messages;
        }

        public function using_model($model): self
        {
            $this->model = $model;
            return $this;
        }

        public function generate_text(): string
        {
            if (!$this->model || [] === $this->messages) {
                throw new RuntimeException('Prompt was not configured.');
            }
            return 'ok from fake provider';
        }

        public function generateEmbeddingResult(): FakeEmbeddingResult
        {
            if (!$this->model || [] === $this->messages) {
                throw new RuntimeException('Prompt was not configured.');
            }
            return new FakeEmbeddingResult();
        }
    }

    class FakeEmbeddingResult
    {
        public function toArray(): array
        {
            return [
                'id' => 'embd-fake-request',
                'embeddings' => [[0.1, 0.2, 0.3]],
                'tokenUsage' => [
                    'promptTokens' => 3,
                    'totalTokens' => 3,
                ],
            ];
        }
    }

    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

namespace WordPress\AiClient {
    class AiClient
    {
        public static $registry;

        public static function defaultRegistry(): object
        {
            return self::$registry;
        }
    }
}

namespace WordPress\AiClient\Messages\DTO {
    class Message
    {
        public function __construct($role, array $parts) { unset($role, $parts); }
    }

    class MessagePart
    {
        public function __construct(string $content) { unset($content); }
    }
}

namespace WordPress\AiClient\Messages\Enums {
    class MessageRoleEnum
    {
        public static function model(): self { return new self(); }
        public static function user(): self { return new self(); }
    }
}

namespace WordPress\AiClient\Providers\Models\DTO {
    class ModelConfig
    {
        public static function fromArray(array $config): self { unset($config); return new self(); }
    }
}

namespace WordPress\AiClient\Providers\Http\DTO {
    class ApiKeyRequestAuthentication
    {
        public function __construct(string $apiKey) { unset($apiKey); }
    }
}

namespace WpAiGatewaySmoke {
    class FakeModel {}

    class FakeModelMetadata
    {
        public function getId(): string { return 'gpt-5.5'; }
        public function getSupportedCapabilities(): array { return ['text_generation', 'embedding_generation']; }
    }

    class FakeModelMetadataDirectory
    {
        public function listModelMetadata(): array { return [new FakeModelMetadata()]; }
    }

    class FakeProvider
    {
        public static function modelMetadataDirectory(): FakeModelMetadataDirectory
        {
            return new FakeModelMetadataDirectory();
        }
    }

    class FakeRegistry
    {
        public bool $apiKeyAuthenticationBound = false;

        public function getRegisteredProviderIds(): array { return ['example-provider']; }
        public function getProviderClassName(string $provider): string { unset($provider); return FakeProvider::class; }
        public function getProviderModel(string $provider, string $model, $config = null): FakeModel { unset($provider, $model, $config); return new FakeModel(); }
        public function setProviderRequestAuthentication(string $provider, $authentication): void { unset($provider, $authentication); $this->apiKeyAuthenticationBound = true; }
    }
}

namespace {
    require dirname(__DIR__) . '/plugin.php';

    $registry = new WpAiGatewaySmoke\FakeRegistry();
    WordPress\AiClient\AiClient::$registry = $registry;

    $missing = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request());
    assert_true($missing instanceof WP_REST_Response, 'Missing token should return REST response.');
    assert_true(401 === $missing->get_status(), 'Missing token should return 401.');
    assert_true(isset($missing->get_data()['error']['type']), 'Missing token should be OpenAI-shaped.');

    update_option(Chubes4\WpAiGateway\OPTION_TOKEN_HASH, hash('sha256', 'valid-token'), false);
    $invalid = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer invalid-token']));
    assert_true($invalid instanceof WP_REST_Response, 'Invalid token should return REST response.');
    assert_true(403 === $invalid->get_status(), 'Invalid token should return 403.');

    $unconfigured = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        ['model' => 'site-default', 'messages' => [['role' => 'user', 'content' => 'hello']]]
    ));
    assert_true($unconfigured instanceof WP_REST_Response, 'Unconfigured route should return REST response.');
    assert_true(500 === $unconfigured->get_status(), 'Unconfigured route should return 500.');
    assert_true(isset($unconfigured->get_data()['error']['message']), 'Unconfigured route should be OpenAI-shaped.');

    update_option(Chubes4\WpAiGateway\OPTION_PROVIDER, 'example-provider', false);
    update_option(Chubes4\WpAiGateway\OPTION_MODEL, 'example-model', false);
    $models = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer valid-token']));
    assert_true(200 === $models->get_status(), 'Configured /models should return 200.');
    assert_true('site-default' === $models->get_data()['data'][0]['id'], '/models should include site-default.');
    assert_true(in_array('embedding_generation', $models->get_data()['data'][1]['capabilities'], true), '/models should expose embedding capability metadata.');
    assert_true(true === $models->get_data()['data'][1]['gateway_metadata']['retrieval']['embedding'], '/models should expose retrieval embedding metadata.');

    $chat = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        ['model' => 'site-default', 'messages' => [['role' => 'user', 'content' => 'hello']]]
    ));
    assert_true(200 === $chat->get_status(), 'site-default path without API key should return 200 with fake provider.');
    assert_true(false === $registry->apiKeyAuthenticationBound, 'Provider-supplied auth path should not inject API-key auth.');

    $provider_qualified_chat = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        ['model' => 'example-provider:example-model', 'messages' => [['role' => 'user', 'content' => 'hello']]]
    ));
    assert_true(200 === $provider_qualified_chat->get_status(), 'Provider-qualified model path should return 200 with fake provider.');

    $embedding = Chubes4\WpAiGateway\RestController::handle_embeddings(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        ['model' => 'example-provider:example-model', 'input' => 'hello']
    ));
    assert_true(200 === $embedding->get_status(), 'Provider-qualified embedding path should return 200 with fake provider.');
    assert_true('embedding' === $embedding->get_data()['data'][0]['object'], 'Embedding response should include OpenAI embedding objects.');
    assert_true('embd-fake-request' === $embedding->get_data()['gateway_metadata']['request_id'], 'Embedding response should expose request metadata.');
    assert_true(3 === $embedding->get_data()['usage']['totalTokens'], 'Embedding response should expose usage metadata.');

    $status = Chubes4\WpAiGateway\CliCommand::gateway_status();
    assert_true(true === $status['configured'], 'Status should report configured route.');
    assert_true(true === $status['token_hash_exists'], 'Status should report token hash exists.');
    assert_true(!array_key_exists('token', $status), 'Status must not expose token values.');

    echo "wp-ai-gateway smoke tests passed\n";
}
