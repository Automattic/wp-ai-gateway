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
    function register_rest_route(string $namespace, string $route, array $args): void { $GLOBALS['wp_ai_gateway_routes'][$namespace . $route] = $args; }
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
        private $tokenUsage = null;
        public function __construct(FakeCandidate ...$candidates) { $this->candidates = $candidates; }
        public function getCandidates(): array { return $this->candidates; }
        public function withTokenUsage($usage): self { $this->tokenUsage = $usage; return $this; }
        public function getTokenUsage() { return $this->tokenUsage; }
    }
    class FakeTokenUsage
    {
        public function __construct(private int $prompt, private int $completion, private int $total) {}
        public function getPromptTokens(): int { return $this->prompt; }
        public function getCompletionTokens(): int { return $this->completion; }
        public function getTotalTokens(): int { return $this->total; }
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
    $GLOBALS['wp_ai_gateway_fake_result'] = (new WpAiGatewaySmoke\FakeResult(new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart('ok from fake provider')])))->withTokenUsage(new WpAiGatewaySmoke\FakeTokenUsage(11, 7, 18));

    Chubes4\WpAiGateway\RestController::register_routes();
    assert_true(isset($GLOBALS['wp_ai_gateway_routes']['wp-ai-gateway/v1/responses']), 'Responses route must be registered.');
    assert_true(!isset($GLOBALS['wp_ai_gateway_routes']['wp-ai-gateway/v1/chat/completions']), 'Chat completions route must not be registered.');
    assert_true(isset($GLOBALS['wp_ai_gateway_routes']['wp-ai-gateway/v1/embeddings']), 'Embeddings route must remain capability-specific.');

    $missing = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request());
    assert_true(401 === $missing->get_status(), 'Missing token should return 401.');
    update_option(Chubes4\WpAiGateway\OPTION_TOKEN_HASH, hash('sha256', 'valid-token'), false);
    update_option(Chubes4\WpAiGateway\OPTION_PROVIDER, 'example-provider', false);
    update_option(Chubes4\WpAiGateway\OPTION_MODEL, 'example-model', false);

    $models = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer valid-token']));
    assert_true(200 === $models->get_status() && 'site-default' === $models->get_data()['data'][0]['id'], 'Models should expose the routed alias.');
    assert_true(in_array('embedding_generation', $models->get_data()['data'][1]['capabilities'], true), 'Models should expose provider capabilities.');

    $text = Chubes4\WpAiGateway\RestController::handle_responses(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        ['model' => 'site-default', 'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'hello']]]]]
    ));
    assert_true(200 === $text->get_status(), 'Text-only Responses request should succeed.');
    assert_true('ok from fake provider' === $text->get_data()['output'][0]['content'][0]['text'], 'Responses output should preserve provider text.');
    assert_true(['input_tokens' => 11, 'output_tokens' => 7, 'total_tokens' => 18] === $text->get_data()['usage'], 'Responses output should preserve provider token usage.');

    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(
        new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart('Checking now.')]),
        new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart(null, new WordPress\AiClient\Tools\DTO\FunctionCall('call_bash', 'bash', ['command' => 'wp option get blogname']))], 'tool_calls')
    );
    $tool = Chubes4\WpAiGateway\RestController::handle_responses(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        [
            'model' => 'site-default',
            'input' => 'Read the title.',
            'tools' => [['type' => 'function', 'name' => 'bash', 'description' => 'Run a command', 'parameters' => ['type' => 'object']]],
            'stream' => true,
        ]
    ));
    $tool_events = $tool->get_data()['events'];
    assert_true(null === $tool_events[count($tool_events) - 1]['data']['response']['usage'], 'Responses output should keep usage null when the provider omits it.');
    assert_true('bash' === $GLOBALS['wp_ai_gateway_declarations'][0]->name, 'Responses tools must become AI Client declarations.');
    ob_start();
    $served = Chubes4\WpAiGateway\RestController::serve_stream(false, $tool, new WP_REST_Request(), new class { public function send_header(string $name, string $value): void {} });
    $sse = ob_get_clean();
    assert_true($served && false !== strpos($sse, 'event: response.output_text.delta'), 'Responses SSE must preserve progress text.');
    assert_true(false !== strpos($sse, 'event: response.function_call_arguments.delta'), 'Responses SSE must emit function calls.');
    assert_true(false !== strpos($sse, '"call_id":"call_bash"'), 'Responses SSE must preserve function call IDs.');
    assert_true(false !== strpos($sse, 'event: response.completed'), 'Responses SSE must terminate with response.completed.');

    $GLOBALS['wp_ai_gateway_fake_result'] = new WpAiGatewaySmoke\FakeResult(new WpAiGatewaySmoke\FakeCandidate([new WpAiGatewaySmoke\FakePart('North Star')]));
    $followup = Chubes4\WpAiGateway\RestController::handle_responses(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        [
            'model' => 'site-default',
            'instructions' => 'Use tools and verify changes.',
            'input' => [
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Read the title.']]],
                ['type' => 'function_call', 'call_id' => 'call_bash', 'name' => 'bash', 'arguments' => '{"command":"wp option get blogname"}'],
                ['type' => 'function_call_output', 'call_id' => 'call_bash', 'output' => 'North Star'],
            ],
        ]
    ));
    assert_true('Use tools and verify changes.' === $GLOBALS['wp_ai_gateway_model_config']['systemInstruction'], 'Responses instructions must become the provider system instruction.');
    assert_true(3 === count($GLOBALS['wp_ai_gateway_messages']), 'Function call history and output must remain standalone AI Client messages.');
    assert_true('bash' === $GLOBALS['wp_ai_gateway_messages'][1]->parts[0]->content->getName(), 'Function call history must preserve its name.');
    assert_true('North Star' === $GLOBALS['wp_ai_gateway_messages'][2]->parts[0]->content->response, 'Function output must reach the provider-neutral response DTO.');
    assert_true('North Star' === $followup->get_data()['output'][0]['content'][0]['text'], 'Tool follow-up should produce final Responses text.');

    $bad_tool = Chubes4\WpAiGateway\RestController::handle_responses(new WP_REST_Request(['Authorization' => 'Bearer valid-token'], ['input' => 'bad', 'tools' => [['type' => 'code']]]));
    assert_true(400 === $bad_tool->get_status(), 'Unsupported capabilities must fail deterministically.');

    $runtime = Chubes4\WpAiGateway\TokenAuthenticator::generate_client_token('Runtime', 3600, ['site-default'], 7);
    $runtime_response = Chubes4\WpAiGateway\RestController::handle_responses(new WP_REST_Request(['Authorization' => 'Bearer ' . $runtime['token']], ['model' => 'site-default', 'input' => 'hello']));
    assert_true(200 === $runtime_response->get_status() && 7 === $GLOBALS['wp_ai_gateway_current_user_id'], 'Scoped credentials should bind Responses requests to their user.');
    $denied = Chubes4\WpAiGateway\RestController::handle_responses(new WP_REST_Request(['Authorization' => 'Bearer ' . $runtime['token']], ['model' => 'example-provider:example-model', 'input' => 'hello']));
    assert_true(403 === $denied->get_status(), 'Scoped credentials must enforce model policy.');

    $mediated = wp_ai_gateway_issue_runtime_credential(7, 300, 'Mediated runtime');
    $mediated_response = wp_ai_gateway_dispatch_openai_request('POST', '/responses', ['model' => 'site-default', 'input' => 'hello'], $mediated['token']);
    assert_true(200 === $mediated_response->get_status(), 'Site-owned control planes should dispatch Responses in-process.');
    assert_true(wp_ai_gateway_revoke_runtime_credential($mediated['client']['id']), 'Runtime credentials should be revocable.');
    assert_true(403 === wp_ai_gateway_dispatch_openai_request('GET', '/models', null, $mediated['token'])->get_status(), 'Revoked credentials must fail authentication.');

    $embedding = Chubes4\WpAiGateway\RestController::handle_embeddings(new WP_REST_Request(
        ['Authorization' => 'Bearer valid-token'],
        ['model' => 'example-provider:example-model', 'input' => 'hello']
    ));
    assert_true(200 === $embedding->get_status() && 'embedding' === $embedding->get_data()['data'][0]['object'], 'Embedding-capable providers should remain available through /embeddings.');

    $status = Chubes4\WpAiGateway\CliCommand::gateway_status();
    assert_true(true === $status['configured'] && !array_key_exists('token', $status), 'Status should report configuration without secrets.');
    echo "wp-ai-gateway smoke tests passed\n";
}
