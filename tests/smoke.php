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
    function apply_filters(string $hook, $value) { unset($hook); return $value; }
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
    assert_true(3 === $status['client_count'], 'Status should report client count without secrets.');
    assert_true(!array_key_exists('token', $status), 'Status must not expose token values.');
    assert_true(false === strpos(serialize(Chubes4\WpAiGateway\TokenAuthenticator::public_clients()), 'token_hash'), 'Client listing must not expose token hashes.');

    $replacement_legacy = Chubes4\WpAiGateway\TokenAuthenticator::generate_token();
    $old_legacy_response = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer valid-token']));
    $new_legacy_response = Chubes4\WpAiGateway\RestController::handle_models(new WP_REST_Request(['Authorization' => 'Bearer ' . $replacement_legacy]));
    assert_true(403 === $old_legacy_response->get_status(), 'Legacy token generation should invalidate the prior singleton token.');
    assert_true(200 === $new_legacy_response->get_status(), 'Replacement legacy token should authenticate.');

    echo "wp-ai-gateway smoke tests passed\n";
}
