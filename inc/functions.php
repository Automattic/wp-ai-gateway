<?php
/**
 * Public integration functions.
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

use Chubes4\WpAiGateway\RestController;
use Chubes4\WpAiGateway\StreamingProxy;
use Chubes4\WpAiGateway\TokenAuthenticator;

if (!function_exists('wp_ai_gateway_issue_runtime_credential')) {
    /**
     * Issues a short-lived, user-bound, site-default-only credential.
     *
     * @return array{token:string,client:array<string,mixed>}
     */
    function wp_ai_gateway_issue_runtime_credential(int $user_id, int $expires_in = 300, string $label = 'Agent runtime'): array
    {
        if ($user_id < 1 || $expires_in < 1 || $expires_in > 3600) {
            throw new InvalidArgumentException('Runtime credentials require a user and a lifetime between 1 and 3600 seconds.');
        }

        return TokenAuthenticator::generate_client_token(
            $label,
            $expires_in,
            [Chubes4\WpAiGateway\MODEL_SITE_DEFAULT],
            $user_id
        );
    }
}

if (!function_exists('wp_ai_gateway_stream_openai_request')) {
    /**
     * Streams an OpenAI-compatible chat request through the site-owned route.
     *
     * The callback receives start metadata, each exact upstream body chunk, and
     * an end event. The caller owns transport framing around those events.
     *
     * @param array<string,mixed> $payload JSON request payload.
     * @param callable            $emit Receives (string $event, mixed $data).
     * @param string              $path OpenAI streaming route.
     * @return array{status:int,headers:array<string,string>}|WP_REST_Response|WP_Error
     */
    function wp_ai_gateway_stream_openai_request(array $payload, string $token, callable $emit, string $path = '/chat/completions')
    {
        $body = wp_json_encode($payload);
        if (!is_string($body)) {
            return Chubes4\WpAiGateway\OpenAiResponse::error('invalid_request_error', 'Request body could not be encoded.', 400);
        }

        $path = '/' . ltrim($path, '/');
        $request = new WP_REST_Request('POST', '/wp-ai-gateway/v1' . $path);
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body($body);

        return StreamingProxy::stream_openai_request($request, $path, $emit);
    }
}

if (!function_exists('wp_ai_gateway_revoke_runtime_credential')) {
    /** Revokes a runtime credential by its non-secret client ID. */
    function wp_ai_gateway_revoke_runtime_credential(string $client_id): bool
    {
        return TokenAuthenticator::revoke_client($client_id);
    }
}

if (!function_exists('wp_ai_gateway_stream_for_user')) {
    /**
     * Streams a site-owned mediated request for a WordPress user.
     * The temporary credential is never returned to the caller.
     *
     * @param array<string,mixed> $payload
     */
    function wp_ai_gateway_stream_for_user(int $user_id, array $payload, callable $emit, string $path = '/chat/completions')
    {
        $credential = wp_ai_gateway_issue_runtime_credential($user_id, 300, 'Site-mediated runtime');
        try {
            return wp_ai_gateway_stream_openai_request($payload, $credential['token'], $emit, $path);
        } finally {
            wp_ai_gateway_revoke_runtime_credential((string) $credential['client']['id']);
        }
    }
}

if (!function_exists('wp_ai_gateway_dispatch_openai_request')) {
    /**
     * Dispatches an OpenAI-compatible request inside the current WordPress process.
     *
     * This lets trusted site-owned control planes mediate requests without exposing
     * a proxy-private WordPress site to the workload network.
     *
     * @param array<string,mixed>|null $payload JSON request payload.
     * @return WP_REST_Response|WP_Error
     */
    function wp_ai_gateway_dispatch_openai_request(string $method, string $path, ?array $payload, string $token)
    {
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        $handlers = [
            'GET /models' => [RestController::class, 'handle_models'],
            'POST /chat/completions' => [RestController::class, 'handle_chat_completions'],
            'POST /embeddings' => [RestController::class, 'handle_embeddings'],
        ];
        $handler = $handlers[$method . ' ' . $path] ?? null;
        if (null === $handler) {
            return new WP_Error('wp_ai_gateway_route_unsupported', 'Unsupported gateway route.');
        }

        $request = new WP_REST_Request($method, '/wp-ai-gateway/v1' . $path);
        $request->set_header('Authorization', 'Bearer ' . $token);
        if (null !== $payload) {
            $body = wp_json_encode($payload);
            if (!is_string($body)) {
                return Chubes4\WpAiGateway\OpenAiResponse::error('invalid_request_error', 'Request body could not be encoded.', 400);
            }
            $request->set_header('Content-Type', 'application/json');
            $request->set_body($body);
        }

        return call_user_func($handler, $request);
    }
}
