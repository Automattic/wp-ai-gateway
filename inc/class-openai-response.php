<?php
/**
 * OpenAI-compatible response helpers.
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

use WP_Error;
use WP_REST_Response;

/**
 * Builds OpenAI-compatible REST payloads.
 */
final class OpenAiResponse
{
    /**
     * Creates an OpenAI-compatible chat completion response.
     *
     * @param string               $id Completion ID.
     * @param string               $model Requested model ID.
     * @param array<string, mixed> $choice Generated choice.
     * @return WP_REST_Response
     */
    public static function chat_completion(string $id, string $model, array $choice): WP_REST_Response
    {
        return new WP_REST_Response([
            'id' => $id,
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [$choice],
            'usage' => null,
        ]);
    }

    /**
     * Creates a REST response that will be served as Server-Sent Events.
     *
     * @param string               $id Completion ID.
     * @param string               $model Requested model ID.
     * @param array<string, mixed> $choice Generated choice.
     * @return WP_REST_Response
     */
    public static function chat_completion_stream(string $id, string $model, array $choice): WP_REST_Response
    {
        $created = time();
        $chunks = [[
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => ['role' => 'assistant'],
                'finish_reason' => null,
            ]],
        ]];

        $message = $choice['message'];
        if (is_string($message['content'] ?? null) && '' !== $message['content']) {
            $chunks[] = self::chat_completion_chunk($id, $model, $created, ['content' => $message['content']], null);
        }
        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            $tool_calls = [];
            foreach (array_values($message['tool_calls']) as $index => $tool_call) {
                $tool_call['index'] = $index;
                $tool_calls[] = $tool_call;
            }
            $chunks[] = self::chat_completion_chunk($id, $model, $created, ['tool_calls' => $tool_calls], null);
        }
        $chunks[] = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => new \stdClass(),
                'finish_reason' => $choice['finish_reason'],
            ]],
        ];

        $response = new WP_REST_Response(['chunks' => $chunks]);
        $response->header('Content-Type', 'text/event-stream; charset=utf-8');
        $response->header('Cache-Control', 'no-cache');
        $response->header('X-WP-AI-Gateway-SSE', '1');
        return $response;
    }

    /**
     * Creates one OpenAI-compatible stream chunk.
     *
     * @param string               $id Completion ID.
     * @param string               $model Requested model ID.
     * @param int                  $created Creation timestamp.
     * @param array<string, mixed> $delta Assistant delta.
     * @param string|null          $finish_reason Finish reason.
     * @return array<string, mixed>
     */
    private static function chat_completion_chunk(string $id, string $model, int $created, array $delta, ?string $finish_reason): array
    {
        return [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => $delta,
                'finish_reason' => $finish_reason,
            ]],
        ];
    }
    /**
     * Creates an OpenAI-compatible error response.
     *
     * @param string $type Error type.
     * @param string $message Error message.
     * @param int    $status HTTP status.
     * @return WP_REST_Response
     */
    public static function error(string $type, string $message, int $status): WP_REST_Response
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
     * Converts a WP_Error to an OpenAI-compatible response.
     *
     * @param WP_Error $error WordPress error.
     * @return WP_REST_Response
     */
    public static function from_wp_error(WP_Error $error): WP_REST_Response
    {
        $status = 500;
        $data = $error->get_error_data();
        if (is_array($data) && isset($data['status']) && is_numeric($data['status'])) {
            $status = (int) $data['status'];
        }

        return self::error((string) $error->get_error_code(), $error->get_error_message(), $status);
    }

    /**
     * Creates a model payload in OpenAI's list shape.
     *
     * @param string               $id Model ID.
     * @param string               $owned_by Owner label.
     * @param list<string>         $capabilities Provider-neutral model capabilities.
     * @param array<string, mixed> $gateway_metadata Gateway routing and retrieval metadata.
     * @return array<string, mixed>
     */
    public static function model_payload(string $id, string $owned_by, array $capabilities = [], array $gateway_metadata = []): array
    {
        return [
            'id' => $id,
            'object' => 'model',
            'created' => 0,
            'owned_by' => $owned_by,
            'capabilities' => array_values(array_unique($capabilities)),
            'gateway_metadata' => $gateway_metadata,
        ];
    }

    /**
     * Creates an OpenAI-compatible embedding response.
     *
     * @param string                     $model Requested model ID.
     * @param list<list<float|int>>      $embeddings Embedding vectors.
     * @param array<string, mixed>|null  $usage Usage metadata.
     * @param string|null                $request_id Upstream request/result identifier.
     * @return WP_REST_Response
     */
    public static function embedding_payload(string $model, array $embeddings, ?array $usage, ?string $request_id): WP_REST_Response
    {
        $data = [];
        foreach ($embeddings as $index => $embedding) {
            $data[] = [
                'object' => 'embedding',
                'embedding' => $embedding,
                'index' => $index,
            ];
        }

        return new WP_REST_Response(
            [
                'object' => 'list',
                'data' => $data,
                'model' => $model,
                'usage' => $usage,
                'gateway_metadata' => [
                    'request_id' => $request_id,
                ],
            ]
        );
    }

    /**
     * De-duplicates OpenAI model payloads by ID.
     *
     * @param list<array<string, mixed>> $models Model payloads.
     * @return array<string, array<string, mixed>>
     */
    public static function unique_models(array $models): array
    {
        $unique = [];
        foreach ($models as $model) {
            if (isset($model['id']) && is_string($model['id'])) {
                $unique[$model['id']] = $model;
            }
        }

        return $unique;
    }
}
