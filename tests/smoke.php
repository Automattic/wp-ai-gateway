<?php
declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__);
    define('WP_CLI', false);

    $GLOBALS['wp_ai_gateway_options'] = [];
    $GLOBALS['wp_ai_gateway_current_user_can'] = false;
    $GLOBALS['wp_ai_gateway_current_user_id'] = 0;
    $GLOBALS['wp_ai_gateway_authenticated_principals'] = [];
    $GLOBALS['wp_ai_gateway_password_counter'] = 0;
    $GLOBALS['wp_ai_gateway_filters'] = [];

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
        private array $headers = [];

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
        public function header(string $key, string $value): void { $this->headers[$key] = $value; }
        public function get_headers(): array { return $this->headers; }
    }

    class WP_REST_Request
    {
        private array $headers;
        private $json;
        private string $body = '';

        public function __construct($headers = [], $json = null)
        {
            if (is_string($headers)) {
                $headers = [];
                $json = null;
            }
            $this->headers = array_change_key_case($headers, CASE_LOWER);
            $this->json = $json;
        }

        public function set_header(string $name, string $value): void
        {
            $this->headers[strtolower($name)] = $value;
        }

        public function set_body(string $body): void
        {
            $this->body = $body;
            $this->json = json_decode($body, true);
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
    function add_filter(): void {}
    function do_action(string $hook, ...$args): void {
        if ('wp_ai_gateway_client_authenticated' === $hook) {
            $GLOBALS['wp_ai_gateway_authenticated_principals'][] = $args[0];
        }
    }
    function register_rest_route(): void {}
    function add_options_page(): void {}
    function register_setting(): void {}
    function current_user_can(): bool { return (bool) $GLOBALS['wp_ai_gateway_current_user_can']; }
    function get_current_user_id(): int { return (int) $GLOBALS['wp_ai_gateway_current_user_id']; }
    function wp_set_current_user(int $user_id): void { $GLOBALS['wp_ai_gateway_current_user_id'] = $user_id; }
    function get_option(string $name, $default = '') { return $GLOBALS['wp_ai_gateway_options'][$name] ?? $default; }
    function update_option(string $name, $value, bool $autoload = true): bool { unset($autoload); $GLOBALS['wp_ai_gateway_options'][$name] = $value; return true; }
    function add_option(string $name, $value, string $deprecated = '', bool $autoload = true): bool { unset($deprecated, $autoload); if (array_key_exists($name, $GLOBALS['wp_ai_gateway_options'])) { return false; } $GLOBALS['wp_ai_gateway_options'][$name] = $value; return true; }
    function delete_option(string $name): bool { unset($GLOBALS['wp_ai_gateway_options'][$name]); return true; }
    function get_userdata(int $user_id) { return in_array($user_id, [7, 8], true) ? (object) ['ID' => $user_id] : false; }
    function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? ''; }
    function sanitize_text_field(string $text): string { return trim(strip_tags($text)); }
    function apply_filters(string $hook, $value, ...$args) {
        foreach ($GLOBALS['wp_ai_gateway_filters'][$hook] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }
        return $value;
    }
    function rest_url(string $path): string { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
    function wp_json_encode($value): string { return json_encode($value, JSON_UNESCAPED_SLASHES); }
    function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000000'; }
    function wp_generate_password(int $length): string {
        ++$GLOBALS['wp_ai_gateway_password_counter'];
        return substr(str_repeat(base_convert((string) $GLOBALS['wp_ai_gateway_password_counter'], 10, 36) . 'abcdefghijklmnopqrstuvwxyz', 4), 0, $length);
    }
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

        public function using_function_declarations(...$declarations): self
        {
            $GLOBALS['wp_ai_gateway_declarations'] = $declarations;
            return $this;
        }

        public function generate_text_result(): \WpAiGatewaySmoke\FakeResult
        {
            if (!$this->model || [] === $this->messages) {
                throw new RuntimeException('Prompt was not configured.');
            }
            $GLOBALS['wp_ai_gateway_messages'] = $this->messages;
            return $GLOBALS['wp_ai_gateway_fake_result'];
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
        public $role;
        public array $parts;
        public function __construct($role, array $parts) { $this->role = $role; $this->parts = $parts; }
    }

    class MessagePart
    {
        public $content;
        public function __construct($content) { $this->content = $content; }
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
        public static function fromArray(array $config): self { $GLOBALS['wp_ai_gateway_model_config'] = $config; return new self(); }
    }
}

namespace WordPress\AiClient\Providers\Http\DTO {
    class ApiKeyRequestAuthentication
    {
        public function __construct(string $apiKey) { unset($apiKey); }
    }
}

namespace WordPress\AiClient\Tools\DTO {
    class FunctionDeclaration
    {
        public string $name;
        public string $description;
        public $parameters;
        public function __construct(string $name, string $description, $parameters = null) { $this->name = $name; $this->description = $description; $this->parameters = $parameters; }
    }
    class FunctionCall
    {
        private ?string $id;
        private ?string $name;
        private $args;
        public function __construct(?string $id, ?string $name, $args) { $this->id = $id; $this->name = $name; $this->args = $args; }
        public function getId(): ?string { return $this->id; }
        public function getName(): ?string { return $this->name; }
        public function getArgs() { return $this->args; }
    }
    class FunctionResponse
    {
        public ?string $id;
        public ?string $name;
        public $response;
        public function __construct(?string $id, ?string $name, $response) { $this->id = $id; $this->name = $name; $this->response = $response; }
    }
}

namespace WpAiGatewaySmoke {
    class FakePart
    {
        private ?string $text;
        private $call;
        public function __construct(?string $text = null, $call = null) { $this->text = $text; $this->call = $call; }
        public function getText(): ?string { return $this->text; }
        public function getFunctionCall() { return $this->call; }
    }
    class FakeMessage
    {
        private array $parts;
        public function __construct(array $parts) { $this->parts = $parts; }
        public function getParts(): array { return $this->parts; }
    }
    class FakeFinishReason { public string $value; public function __construct(string $value) { $this->value = $value; } }
    class FakeCandidate
    {
        private FakeMessage $message;
        private FakeFinishReason $reason;
        public function __construct(array $parts, string $reason = 'stop') { $this->message = new FakeMessage($parts); $this->reason = new FakeFinishReason($reason); }
        public function getMessage(): FakeMessage { return $this->message; }
        public function getFinishReason(): FakeFinishReason { return $this->reason; }
    }
    class FakeResult
    {
        private array $candidates;
        public function __construct(FakeCandidate ...$candidates) { $this->candidates = $candidates; }
        public function getCandidates(): array { return $this->candidates; }
    }
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
    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart('ok from fake provider')]));

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
    assert_true('ok from fake provider' === $chat->get_data()['choices'][0]['message']['content'], 'Text chat must preserve existing response behavior.');
    assert_true(false === $registry->apiKeyAuthenticationBound, 'Provider-supplied auth path should not inject API-key auth.');

    $provider_qualified_chat = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        ['model' => 'example-provider:example-model', 'messages' => [['role' => 'user', 'content' => 'hello']]]
    ));
    assert_true(200 === $provider_qualified_chat->get_status(), 'Provider-qualified model path should return 200 with fake provider.');

    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(new WpAiGatewaySmoke\FakeCandidate([
        new WpAiGatewaySmoke\FakePart(null, new WordPress\AiClient\Tools\DTO\FunctionCall('call_weather', 'weather', ['city' => 'Paris'])),
    ], 'tool_calls'));
    $tool_chat = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        ['model' => 'site-default', 'messages' => [['role' => 'user', 'content' => 'Weather?']], 'tools' => [['type' => 'function', 'function' => ['name' => 'weather', 'description' => 'Gets weather', 'parameters' => ['type' => 'object']]]]]
    ));
    assert_true('weather' === $GLOBALS['wp_ai_gateway_declarations'][0]->name, 'Function definitions must become FunctionDeclaration DTOs.');
    assert_true('tool_calls' === $tool_chat->get_data()['choices'][0]['finish_reason'], 'Function calls must finish with tool_calls.');
    assert_true('call_weather' === $tool_chat->get_data()['choices'][0]['message']['tool_calls'][0]['id'], 'Function call IDs must be preserved.');
    assert_true('{"city":"Paris"}' === $tool_chat->get_data()['choices'][0]['message']['tool_calls'][0]['function']['arguments'], 'Function arguments must be JSON encoded.');

    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(
        new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart('I am checking now.')]),
        new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart(null, new WordPress\AiClient\Tools\DTO\FunctionCall('call_bash', 'bash', ['command' => 'wp option update blogname "North Star"']))], 'tool_calls')
    );
    $text_then_tool = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        ['model' => 'site-default', 'messages' => [['role' => 'user', 'content' => 'Change the site title.']], 'tools' => [['type' => 'function', 'function' => ['name' => 'bash', 'parameters' => ['type' => 'object']]]]]
    ));
    assert_true('I am checking now.' === $text_then_tool->get_data()['choices'][0]['message']['content'], 'Text before a function call must be preserved.');
    assert_true('call_bash' === $text_then_tool->get_data()['choices'][0]['message']['tool_calls'][0]['id'], 'Function calls after text candidates must be preserved.');
    assert_true('tool_calls' === $text_then_tool->get_data()['choices'][0]['finish_reason'], 'Any function-call candidate must finish with tool_calls.');

    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart('No arguments needed.')]));
    Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        ['messages' => [['role' => 'user', 'content' => 'No args']], 'tools' => [['type' => 'function', 'function' => ['name' => 'no_args', 'parameters' => ['type' => 'object', 'properties' => []]]]]]
    ));
    assert_true(['type' => 'object'] === $GLOBALS['wp_ai_gateway_declarations'][0]->parameters, 'Empty function properties must remain a JSON object schema upstream.');

    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart(null, new WordPress\AiClient\Tools\DTO\FunctionCall('call_no_args', 'no_args', null))], 'tool_calls'));
    $no_args_call = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(['Authorization' => 'Bearer valid-token'], ['messages' => [['role' => 'user', 'content' => 'No args']]]));
    assert_true('{}' === $no_args_call->get_data()['choices'][0]['message']['tool_calls'][0]['function']['arguments'], 'Null function arguments must become an empty JSON object.');

    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(new WpAiGatewaySmoke\FakeCandidate([
        new WpAiGatewaySmoke\FakePart(null, new WordPress\AiClient\Tools\DTO\FunctionCall('call_one', 'one', [])),
        new WpAiGatewaySmoke\FakePart(null, new WordPress\AiClient\Tools\DTO\FunctionCall('call_two', 'two', ['x' => 2])),
    ], 'tool_calls'));
    $parallel = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(['Authorization' => 'Bearer valid-token'], ['messages' => [['role' => 'user', 'content' => 'call both']]]));
    assert_true(2 === count($parallel->get_data()['choices'][0]['message']['tool_calls']), 'Parallel function calls must be retained.');

    $bad_tool = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(['Authorization' => 'Bearer valid-token'], ['messages' => [['role' => 'user', 'content' => 'bad']], 'tools' => [['type' => 'code']]]));
    assert_true(400 === $bad_tool->get_status(), 'Unsupported tool payloads must return 400.');

    $bad_parameters = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(['Authorization' => 'Bearer valid-token'], ['messages' => [['role' => 'user', 'content' => 'bad']], 'tools' => [['type' => 'function', 'function' => ['name' => 'bad', 'parameters' => ['not-a-schema']]]]]));
    assert_true(400 === $bad_parameters->get_status(), 'Function parameter schemas must be JSON objects.');

    $required_choice = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(['Authorization' => 'Bearer valid-token'], ['messages' => [['role' => 'user', 'content' => 'required']], 'tools' => [['type' => 'function', 'function' => ['name' => 'weather']]], 'tool_choice' => 'required']));
    assert_true(400 === $required_choice->get_status(), 'Unsupported required tool choice must return 400.');

    $disabled_parallel = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(['Authorization' => 'Bearer valid-token'], ['messages' => [['role' => 'user', 'content' => 'one call']], 'parallel_tool_calls' => false]));
    assert_true(400 === $disabled_parallel->get_status(), 'Unsupported parallel tool control must return 400.');

    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart('It is sunny.')]));
    $followup = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(['Authorization' => 'Bearer valid-token'], ['messages' => [
        ['role' => 'system', 'content' => 'Operate the site with tools.'],
        ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'Verify every change.']]],
        ['role' => 'user', 'content' => 'Weather?'],
        ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_weather', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{"city":"Paris"}']]]],
        ['role' => 'tool', 'tool_call_id' => 'call_weather', 'content' => '{"temperature":20}'],
        ['role' => 'assistant', 'content' => 'It is sunny.'],
        ['role' => 'user', 'content' => 'Change the site title.'],
    ]]));
    assert_true("Operate the site with tools.\n\nVerify every change." === $GLOBALS['wp_ai_gateway_model_config']['systemInstruction'], 'System messages must become the provider system instruction in order.');
    assert_true(5 === count($GLOBALS['wp_ai_gateway_messages']), 'System messages must not remain in ordinary chat history.');
    assert_true('Weather?' === $GLOBALS['wp_ai_gateway_messages'][0]->parts[0]->content, 'User history must remain first after system extraction.');
    $function_response = $GLOBALS['wp_ai_gateway_messages'][2]->parts[0]->content;
    assert_true('weather' === $function_response->name && 20 === $function_response->response['temperature'], 'Tool result history must become decoded FunctionResponse parts.');
    assert_true('Change the site title.' === $GLOBALS['wp_ai_gateway_messages'][4]->parts[0]->content, 'Follow-up user messages must remain after tool history.');
    assert_true('It is sunny.' === $followup->get_data()['choices'][0]['message']['content'], 'Tool-result follow-up must preserve final text.');

    $text_stream = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(['Authorization' => 'Bearer valid-token'], ['messages' => [['role' => 'user', 'content' => 'stream']], 'stream' => true]));
    ob_start();
    $served = Chubes4\WpAiGateway\RestController::serve_stream(false, $text_stream, new WP_REST_Request(), new class { public function send_header(string $name, string $value): void {} });
    $sse = ob_get_clean();
    assert_true($served && false !== strpos($sse, '"content":"It is sunny."') && false !== strpos($sse, '"finish_reason":"stop"') && false !== strpos($sse, 'data: [DONE]'), 'Text streaming must emit chunks, finish reason, and DONE.');

    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart(null, new WordPress\AiClient\Tools\DTO\FunctionCall('call_sse', 'weather', []))], 'tool_calls'));
    $tool_stream = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(['Authorization' => 'Bearer valid-token'], ['messages' => [['role' => 'user', 'content' => 'stream tool']], 'stream' => true]));
    ob_start(); Chubes4\WpAiGateway\RestController::serve_stream(false, $tool_stream, new WP_REST_Request(), new class { public function send_header(string $name, string $value): void {} }); $tool_sse = ob_get_clean();
    assert_true(false !== strpos($tool_sse, '"tool_calls"') && false !== strpos($tool_sse, '"index":0') && false !== strpos($tool_sse, '"finish_reason":"tool_calls"') && false !== strpos($tool_sse, 'data: [DONE]'), 'Tool streaming must emit indexed tool chunks, tool_calls, and DONE.');

    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart(null, new WordPress\AiClient\Tools\DTO\FunctionCall('call_invalid', null, []))], 'tool_calls'));
    $invalid_provider_call = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(['Authorization' => 'Bearer valid-token'], ['messages' => [['role' => 'user', 'content' => 'invalid provider call']]]));
    assert_true(500 === $invalid_provider_call->get_status(), 'Malformed provider function calls must return a gateway error.');
    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart('ok from fake provider')]));

    $runtime_one = Chubes4\WpAiGateway\TokenAuthenticator::generate_client_token('Runtime one', 3600, ['site-default'], 7);
    $runtime_two = Chubes4\WpAiGateway\TokenAuthenticator::generate_client_token('Runtime two', 3600, ['example-provider:example-model'], 8);
    assert_true($runtime_one['client']['id'] !== $runtime_two['client']['id'], 'Scoped clients should have independent IDs.');
    assert_true(false === strpos(serialize(get_option(Chubes4\WpAiGateway\OPTION_CLIENTS)), $runtime_one['token']), 'Stored clients must not contain plaintext tokens.');
    assert_true(!array_key_exists('token_hash', $runtime_one['client']), 'Returned client metadata must not expose token hashes.');

    $runtime_one_models = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer ' . $runtime_one['token']]));
    assert_true(200 === $runtime_one_models->get_status(), 'Scoped client should authenticate.');
    assert_true(['site-default'] === array_column($runtime_one_models->get_data()['data'], 'id'), 'site-default-only client should not enumerate provider aliases.');
    assert_true(7 === $GLOBALS['wp_ai_gateway_current_user_id'], 'Scoped client should establish its bound WordPress user.');
    assert_true(get_option(Chubes4\WpAiGateway\OPTION_CLIENT_LAST_USED_PREFIX . $runtime_one['client']['id'], 0) > 0, 'Last-used telemetry should be stored separately from credential policy.');

    $last_principal = end($GLOBALS['wp_ai_gateway_authenticated_principals']);
    assert_true($runtime_one['client']['id'] === $last_principal['id'], 'Authentication hook should expose the non-secret client principal.');
    assert_true(false === strpos(serialize($last_principal), $runtime_one['token']), 'Authentication principal must not expose bearer secrets.');

    $runtime_one_denied = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(
        ['Authorization' => 'Bearer ' . $runtime_one['token']],
        ['model' => 'example-provider:example-model', 'messages' => [['role' => 'user', 'content' => 'hello']]]
    ));
    assert_true(403 === $runtime_one_denied->get_status(), 'site-default-only client should be denied provider-qualified routing.');

    $runtime_two_allowed = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(
        ['Authorization' => 'Bearer ' . $runtime_two['token']],
        ['model' => 'example-provider:example-model', 'messages' => [['role' => 'user', 'content' => 'hello']]]
    ));
    assert_true(200 === $runtime_two_allowed->get_status(), 'Explicitly allowlisted provider-qualified route should succeed.');

    $mediated = wp_ai_gateway_issue_runtime_credential(7, 300, 'Mediated runtime');
    $mediated_response = wp_ai_gateway_dispatch_openai_request(
        'POST',
        '/chat/completions',
        ['model' => 'site-default', 'messages' => [['role' => 'user', 'content' => 'hello']]],
        $mediated['token']
    );
    assert_true(200 === $mediated_response->get_status(), 'Site-owned control planes should dispatch through the gateway in-process.');
    assert_true(7 === $GLOBALS['wp_ai_gateway_current_user_id'], 'Mediated dispatch should establish the credential-bound user.');

    $stream_events = [];
    $upstream_chunks = ["data: {\"id\":", "\"chatcmpl-one\"}\n\n", "data: [DONE]\n\n"];
    $GLOBALS['wp_ai_gateway_filters']['wp_ai_gateway_provider_api_key'][] = static function ($key, string $provider): string {
        unset($key);
        return 'example-provider' === $provider ? 'site-owned-secret' : '';
    };
    $GLOBALS['wp_ai_gateway_filters']['wp_ai_gateway_stream_upstream_url'][] = static function ($url, array $route, array $payload, string $path): string {
        unset($url);
        assert_true('example-provider' === $route['provider'], 'Streaming should use the configured provider route.');
        unset($payload);
        return 'https://provider.example/v1' . $path;
    };
    $GLOBALS['wp_ai_gateway_filters']['wp_ai_gateway_stream_upstream_payload'][] = static function (array $payload): array {
        if (isset($payload['max_tokens'])) {
            $payload['max_completion_tokens'] = $payload['max_tokens'];
            unset($payload['max_tokens']);
        }
        return $payload;
    };
    $GLOBALS['wp_ai_gateway_filters']['wp_ai_gateway_stream_upstream_headers'][] = static function (array $headers, array $route, array $principal, string $path): array {
        unset($route, $principal);
        $headers['X-Gateway-Route'] = $path;
        return $headers;
    };
    $GLOBALS['wp_ai_gateway_filters']['wp_ai_gateway_stream_transport'][] = static function ($transport) use ($upstream_chunks): callable {
        unset($transport);
        return static function (string $url, array $headers, string $body, callable $emit) use ($upstream_chunks): array {
            $payload = json_decode($body, true);
            assert_true('https://provider.example/v1/chat/completions' === $url, 'Streaming should target the site-configured HTTPS URL.');
            assert_true('Bearer site-owned-secret' === $headers['Authorization'], 'Streaming should replace client auth with site-owned provider auth.');
            assert_true('/chat/completions' === $headers['X-Gateway-Route'], 'Streaming headers should receive the selected route.');
            assert_true('example-model' === $payload['model'], 'Streaming should rewrite site-default to the configured upstream model.');
            assert_true(true === $payload['stream'], 'Streaming should force the upstream stream flag.');
            assert_true(128 === $payload['max_completion_tokens'] && !isset($payload['max_tokens']), 'Streaming should apply site-owned upstream payload adaptation.');
            $response = ['status' => 200, 'headers' => ['content-type' => 'text/event-stream']];
            $emit('start', $response);
            foreach ($upstream_chunks as $chunk) {
                $emit('chunk', $chunk);
            }
            $emit('end', null);
            return $response;
        };
    };
    $stream_result = wp_ai_gateway_stream_openai_request(
        ['model' => 'site-default', 'stream' => true, 'max_tokens' => 128, 'messages' => [['role' => 'user', 'content' => 'hello']]],
        $mediated['token'],
        static function (string $event, $data) use (&$stream_events): void {
            $stream_events[] = [$event, $data];
        }
    );
    assert_true(200 === $stream_result['status'], 'Mediated streaming should return upstream status metadata.');
    assert_true(['start', 'chunk', 'chunk', 'chunk', 'end'] === array_column($stream_events, 0), 'Streaming should preserve event order and upstream chunk boundaries.');
    assert_true($upstream_chunks === array_column(array_slice($stream_events, 1, 3), 1), 'Streaming should carry exact upstream bytes without parsing or reconstruction.');
    assert_true(7 === $GLOBALS['wp_ai_gateway_current_user_id'], 'Streaming should establish the credential-bound user.');

    $responses_events = [];
    $responses_chunks = ["event: response.output_text.delta\ndata: {\"delta\":\"North\"}\n\n", "event: response.completed\ndata: {\"response\":{\"id\":\"resp-one\"}}\n\n"];
    $GLOBALS['wp_ai_gateway_filters']['wp_ai_gateway_stream_transport'] = [static function () use ($responses_chunks): callable {
        return static function (string $url, array $headers, string $body, callable $emit) use ($responses_chunks): array {
            $payload = json_decode($body, true);
            assert_true('https://provider.example/v1/responses' === $url, 'Responses streaming should use its selected upstream route.');
            assert_true('/responses' === $headers['X-Gateway-Route'], 'Responses headers should receive the selected route.');
            assert_true('example-model' === $payload['model'] && true === $payload['stream'], 'Responses streaming should apply the configured model and stream flag.');
            assert_true('hello' === $payload['input'], 'Responses streaming should preserve native input payloads.');
            $response = ['status' => 200, 'headers' => ['content-type' => 'text/event-stream']];
            $emit('start', $response);
            foreach ($responses_chunks as $chunk) {
                $emit('chunk', $chunk);
            }
            return $response;
        };
    }];
    $responses_result = wp_ai_gateway_stream_openai_request(
        ['model' => 'site-default', 'stream' => true, 'input' => 'hello'],
        $mediated['token'],
        static function (string $event, $data) use (&$responses_events): void {
            $responses_events[] = [$event, $data];
        },
        '/responses'
    );
    assert_true(200 === $responses_result['status'], 'Native Responses API streaming should return upstream status metadata.');
    assert_true($responses_chunks === array_column(array_slice($responses_events, 1, 2), 1), 'Responses API chunks should cross the gateway unchanged.');
    assert_true('end' === $responses_events[3][0], 'Responses API streaming should terminate exactly once.');

    $disallowed_stream = wp_ai_gateway_stream_openai_request(
        ['model' => 'example-provider:example-model', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'hello']]],
        $mediated['token'],
        static function (): void {}
    );
    assert_true($disallowed_stream instanceof WP_REST_Response && 403 === $disallowed_stream->get_status(), 'Streaming should enforce the credential model policy before contacting upstream.');

    $GLOBALS['wp_ai_gateway_current_user_can'] = true;
    $admin_context_disallowed = wp_ai_gateway_stream_openai_request(
        ['model' => 'example-provider:example-model', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'hello']]],
        $mediated['token'],
        static function (): void {}
    );
    assert_true($admin_context_disallowed instanceof WP_REST_Response && 403 === $admin_context_disallowed->get_status(), 'A supplied scoped bearer token should remain authoritative in an administrator context.');
    $GLOBALS['wp_ai_gateway_current_user_can'] = false;

    $non_streaming_dispatch = wp_ai_gateway_stream_openai_request(
        ['model' => 'site-default', 'stream' => false, 'messages' => [['role' => 'user', 'content' => 'hello']]],
        $mediated['token'],
        static function (): void {}
    );
    assert_true($non_streaming_dispatch instanceof WP_REST_Response && 400 === $non_streaming_dispatch->get_status(), 'Streaming dispatch should reject stream=false before contacting upstream.');

    $partial_failure_events = [];
    $GLOBALS['wp_ai_gateway_filters']['wp_ai_gateway_stream_transport'] = [static function (): callable {
        return static function ($url, $headers, $body, callable $emit): WP_Error {
            unset($url, $headers, $body);
            $emit('start', ['status' => 200, 'headers' => ['content-type' => 'text/event-stream']]);
            $emit('chunk', "data: partial\n\n");
            return new WP_Error('upstream_reset', 'Upstream reset the stream.');
        };
    }];
    $partial_failure = wp_ai_gateway_stream_openai_request(
        ['model' => 'site-default', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'hello']]],
        $mediated['token'],
        static function (string $event, $data) use (&$partial_failure_events): void {
            $partial_failure_events[] = [$event, $data];
        }
    );
    assert_true($partial_failure instanceof WP_Error, 'A partial upstream failure should remain observable to the control plane.');
    assert_true(['start', 'chunk', 'end'] === array_column($partial_failure_events, 0), 'A partial upstream failure should emit one terminal end event.');
    assert_true('upstream_reset' === $partial_failure_events[2][1]['error']['code'], 'The terminal event should identify the upstream failure.');

    $early_failure_events = [];
    $GLOBALS['wp_ai_gateway_filters']['wp_ai_gateway_stream_transport'] = [static function (): callable {
        return static function (): WP_Error {
            return new WP_Error('upstream_unreachable', 'Could not connect to upstream.');
        };
    }];
    $early_failure = wp_ai_gateway_stream_openai_request(
        ['model' => 'site-default', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'hello']]],
        $mediated['token'],
        static function (string $event, $data) use (&$early_failure_events): void {
            $early_failure_events[] = [$event, $data];
        }
    );
    assert_true($early_failure instanceof WP_Error, 'An early upstream connection failure should remain observable to the control plane.');
    assert_true(['end'] === array_column($early_failure_events, 0), 'An early upstream failure should emit one terminal end event.');
    assert_true('upstream_unreachable' === $early_failure_events[0][1]['error']['code'], 'An early terminal event should identify the upstream failure.');

    assert_true(wp_ai_gateway_revoke_runtime_credential($mediated['client']['id']), 'Mediated runtime credential should be revocable.');
    $mediated_revoked = wp_ai_gateway_dispatch_openai_request('GET', '/models', null, $mediated['token']);
    assert_true(403 === $mediated_revoked->get_status(), 'Revoked mediated credential should fail authentication.');
    $revoked_stream = wp_ai_gateway_stream_openai_request(
        ['model' => 'site-default', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'hello']]],
        $mediated['token'],
        static function (): void {}
    );
    assert_true($revoked_stream instanceof WP_REST_Response && 403 === $revoked_stream->get_status(), 'Revoked mediated credential should fail before opening an upstream stream.');

    assert_true(Chubes4\WpAiGateway\TokenAuthenticator::revoke_client($runtime_one['client']['id']), 'One client should be revocable.');
    $runtime_one_revoked = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer ' . $runtime_one['token']]));
    $runtime_two_after_revoke = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(
        ['Authorization' => 'Bearer ' . $runtime_two['token']],
        ['model' => 'example-provider:example-model', 'messages' => [['role' => 'user', 'content' => 'hello']]]
    ));
    assert_true(403 === $runtime_one_revoked->get_status(), 'Revoked client should fail authentication.');
    assert_true(200 === $runtime_two_after_revoke->get_status(), 'Revoking one client must not affect another.');
    $clients_after_revoke = Chubes4\WpAiGateway\TokenAuthenticator::clients();
    assert_true($clients_after_revoke[$runtime_one['client']['id']]['revoked_at'] > 0, 'Authentication telemetry must not overwrite revocation state.');
    assert_true(null === Chubes4\WpAiGateway\TokenAuthenticator::rotate_client($runtime_one['client']['id'], 3600), 'Revoked client rotation must not reactivate the credential.');
    $stale_clients = get_option(Chubes4\WpAiGateway\OPTION_CLIENTS);
    $stale_clients[$runtime_one['client']['id']]['revoked_at'] = 0;
    update_option(Chubes4\WpAiGateway\OPTION_CLIENTS, $stale_clients, false);
    $revoked_after_stale_write = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer ' . $runtime_one['token']]));
    assert_true(403 === $revoked_after_stale_write->get_status(), 'Immutable revocation tombstone must survive a stale registry write.');

    $rotated_two = Chubes4\WpAiGateway\TokenAuthenticator::rotate_client($runtime_two['client']['id'], 7200);
    $old_two_after_rotate = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer ' . $runtime_two['token']]));
    $new_two_after_rotate = Chubes4\WpAiGateway\RestController::handle_chat_completions(new WP_REST_Request(
        ['Authorization' => 'Bearer ' . $rotated_two['token']],
        ['model' => 'example-provider:example-model', 'messages' => [['role' => 'user', 'content' => 'hello']]]
    ));
    assert_true(403 === $old_two_after_rotate->get_status(), 'Rotating one client should invalidate its prior credential.');
    assert_true(200 === $new_two_after_rotate->get_status(), 'Rotated credential should preserve client model policy.');

    $expired = Chubes4\WpAiGateway\TokenAuthenticator::generate_client_token('Expired runtime', 1);
    $stored_clients = get_option(Chubes4\WpAiGateway\OPTION_CLIENTS);
    $stored_clients[$expired['client']['id']]['expires_at'] = time() - 1;
    update_option(Chubes4\WpAiGateway\OPTION_CLIENTS, $stored_clients, false);
    $expired_response = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer ' . $expired['token']]));
    assert_true(403 === $expired_response->get_status(), 'Expired client should fail authentication.');

    $invalid_user_rejected = false;
    try {
        Chubes4\WpAiGateway\TokenAuthenticator::generate_client_token('Missing user', 3600, ['site-default'], 999);
    } catch (InvalidArgumentException $e) {
        $invalid_user_rejected = true;
    }
    assert_true($invalid_user_rejected, 'Client issuance should reject a missing bound user.');

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
    assert_true(4 === $status['client_count'], 'Status should report client count without secrets.');
    assert_true(!array_key_exists('token', $status), 'Status must not expose token values.');
    assert_true(false === strpos(serialize(Chubes4\WpAiGateway\TokenAuthenticator::public_clients()), 'token_hash'), 'Client listing must not expose token hashes.');

    $replacement_legacy = Chubes4\WpAiGateway\TokenAuthenticator::generate_token();
    $old_legacy_response = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer valid-token']));
    $new_legacy_response = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer ' . $replacement_legacy]));
    assert_true(403 === $old_legacy_response->get_status(), 'Legacy token generation should invalidate the prior singleton token.');
    assert_true(200 === $new_legacy_response->get_status(), 'Replacement legacy token should authenticate.');

    echo "wp-ai-gateway smoke tests passed\n";
}
