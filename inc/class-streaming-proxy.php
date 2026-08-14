<?php
/**
 * Raw streaming proxy for site-mediated OpenAI-compatible requests.
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Authenticates a gateway request and carries upstream response bytes downstream.
 */
final class StreamingProxy
{
    /**
     * Streams a chat completion as start, chunk, and end events.
     *
     * @param WP_REST_Request $request Gateway request.
     * @param callable        $emit Receives an event name and event data.
     * @return array{status:int,headers:array<string,string>}|WP_REST_Response|WP_Error
     */
    public static function stream_openai_request(WP_REST_Request $request, string $path, callable $emit)
    {
        if (!in_array($path, ['/chat/completions', '/responses'], true)) {
            return OpenAiResponse::error('invalid_request_error', 'Unsupported streaming route.', 404);
        }
        $principal = TokenAuthenticator::authorize($request);
        if ($principal instanceof WP_REST_Response) {
            return $principal;
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return OpenAiResponse::error('invalid_request_error', 'Request body must be JSON.', 400);
        }
        if ('/chat/completions' === $path && (!is_array($payload['messages'] ?? null) || [] === $payload['messages'])) {
            return OpenAiResponse::error('invalid_request_error', 'Request body must include messages.', 400);
        }
        if ('/responses' === $path && !array_key_exists('input', $payload)) {
            return OpenAiResponse::error('invalid_request_error', 'Request body must include input.', 400);
        }
        if (false === ($payload['stream'] ?? true)) {
            return OpenAiResponse::error('invalid_request_error', 'Streaming dispatch requires stream=true.', 400);
        }

        $requested_model = is_string($payload['model'] ?? null) ? $payload['model'] : MODEL_SITE_DEFAULT;
        if (!TokenAuthenticator::model_allowed($principal, $requested_model)) {
            return OpenAiResponse::error('permission_error', 'The authenticated client is not allowed to use this model.', 403);
        }

        $route = ProviderRouter::route_for_requested_model($requested_model);
        if ($route instanceof WP_REST_Response || $route instanceof WP_Error) {
            return $route;
        }

        $url = apply_filters('wp_ai_gateway_stream_upstream_url', '', $route, $payload, $path);
        if (!self::valid_upstream_url($url)) {
            return OpenAiResponse::error('server_error', 'Streaming upstream URL is not configured.', 500);
        }

        $headers = [
            'Accept' => 'text/event-stream',
            'Content-Type' => 'application/json',
        ];
        $api_key = AiClientBridge::provider_api_key($route['provider']);
        if ('' !== $api_key) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }
        $headers = apply_filters('wp_ai_gateway_stream_upstream_headers', $headers, $route, $principal, $path);
        if (!is_array($headers)) {
            return OpenAiResponse::error('server_error', 'Streaming upstream headers are invalid.', 500);
        }

        $payload['model'] = $route['model'];
        $payload['stream'] = true;
        $payload = apply_filters('wp_ai_gateway_stream_upstream_payload', $payload, $route, $principal, $path);
        if (!is_array($payload)) {
            return OpenAiResponse::error('server_error', 'Streaming upstream payload is invalid.', 500);
        }
        $body = wp_json_encode($payload);
        if (!is_string($body)) {
            return OpenAiResponse::error('server_error', 'Streaming request could not be encoded.', 500);
        }

        $ended = false;
        $guarded_emit = static function (string $event, $data) use ($emit, &$ended): void {
            if ('end' === $event) {
                $ended = true;
            }
            $emit($event, $data);
        };

        $transport = apply_filters('wp_ai_gateway_stream_transport', null, $url, $route);
        if (is_callable($transport)) {
            $result = $transport($url, $headers, $body, $guarded_emit);
        } else {
            $result = self::stream_with_curl($url, $headers, $body, $guarded_emit);
        }

        if (!$ended) {
            $guarded_emit(
                'end',
                $result instanceof WP_Error
                    ? ['error' => ['code' => $result->get_error_code(), 'message' => $result->get_error_message()]]
                    : null
            );
        }

        return $result;
    }

    /**
     * Streams an upstream response without interpreting body chunks.
     *
     * @param string               $url Upstream URL.
     * @param array<string,string> $headers Upstream headers.
     * @param string               $body Encoded request body.
     * @param callable             $emit Event callback.
     * @return array{status:int,headers:array<string,string>}|WP_Error
     */
    private static function stream_with_curl(string $url, array $headers, string $body, callable $emit)
    {
        if (!function_exists('curl_init')) {
            return new WP_Error('wp_ai_gateway_stream_transport_unavailable', 'The cURL extension is required for streaming.');
        }

        $status = 0;
        $response_headers = [];
        $started = false;
        $handle = curl_init($url);
        if (false === $handle) {
            return new WP_Error('wp_ai_gateway_stream_transport_unavailable', 'Could not initialize the streaming transport.');
        }

        $header_lines = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $header_lines[] = $name . ': ' . (string) $value;
            }
        }

        curl_setopt_array(
            $handle,
            [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $header_lines,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$status, &$response_headers, &$started, $emit): int {
                    unset($curl);
                    $length = strlen($line);
                    $trimmed = trim($line);
                    if (0 === stripos($trimmed, 'HTTP/')) {
                        $parts = preg_split('/\s+/', $trimmed);
                        $status = isset($parts[1]) ? (int) $parts[1] : 0;
                        $response_headers = [];
                        $started = false;
                    } elseif ('' === $trimmed && !$started && $status >= 200) {
                        $emit('start', ['status' => $status, 'headers' => $response_headers]);
                        $started = true;
                    } elseif (false !== strpos($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $name = strtolower(trim($name));
                        if (in_array($name, ['content-type', 'openai-request-id', 'x-request-id'], true)) {
                            $response_headers[$name] = trim($value);
                        }
                    }
                    return $length;
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$started, &$status, &$response_headers, $emit): int {
                    unset($curl);
                    if (!$started) {
                        $emit('start', ['status' => $status, 'headers' => $response_headers]);
                        $started = true;
                    }
                    $emit('chunk', $chunk);
                    return strlen($chunk);
                },
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 0,
            ]
        );

        try {
            $completed = curl_exec($handle);
            $error = curl_error($handle);
            $error_number = curl_errno($handle);
        } finally {
            curl_close($handle);
        }

        if (false === $completed) {
            return new WP_Error('wp_ai_gateway_stream_transport_error', $error, ['curl_errno' => $error_number]);
        }
        if (!$started) {
            $emit('start', ['status' => $status, 'headers' => $response_headers]);
        }
        return ['status' => $status, 'headers' => $response_headers];
    }

    /**
     * Restricts streaming destinations to credential-free HTTPS URLs.
     *
     * @param mixed $url Candidate URL.
     * @return bool
     */
    private static function valid_upstream_url($url): bool
    {
        if (!is_string($url) || '' === $url) {
            return false;
        }

        $parts = parse_url($url);
        return is_array($parts)
            && 'https' === strtolower((string) ($parts['scheme'] ?? ''))
            && '' !== ($parts['host'] ?? '')
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
