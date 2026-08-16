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
    /** Builds a completed Responses API document. */
    public static function response(string $id, string $model, array $generation): WP_REST_Response
    {
        return new WP_REST_Response(self::response_payload($id, $model, $generation));
    }

    /** Builds Responses API SSE events from a provider-neutral generation result. */
    public static function response_stream(string $id, string $model, array $generation): WP_REST_Response
    {
        $events = [];
        $sequence = 0;
        $created = time();
        $events[] = self::event('response.created', [
            'type' => 'response.created',
            'sequence_number' => $sequence++,
            'response' => ['id' => $id, 'object' => 'response', 'created_at' => $created, 'status' => 'in_progress', 'model' => $model, 'output' => []],
        ]);

        $output_index = 0;
        if (is_string($generation['content'] ?? null) && '' !== $generation['content']) {
            $item_id = 'msg_' . wp_generate_uuid4();
            $events[] = self::event('response.output_item.added', [
                'type' => 'response.output_item.added', 'sequence_number' => $sequence++, 'output_index' => $output_index,
                'item' => ['id' => $item_id, 'type' => 'message', 'role' => 'assistant', 'status' => 'in_progress', 'content' => []],
            ]);
            $events[] = self::event('response.output_text.delta', [
                'type' => 'response.output_text.delta', 'sequence_number' => $sequence++, 'output_index' => $output_index,
                'item_id' => $item_id, 'content_index' => 0, 'delta' => $generation['content'], 'logprobs' => [],
            ]);
            $events[] = self::event('response.output_item.done', [
                'type' => 'response.output_item.done', 'sequence_number' => $sequence++, 'output_index' => $output_index++,
                'item' => ['id' => $item_id, 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $generation['content'], 'annotations' => []]]],
            ]);
        }

        foreach ($generation['tool_calls'] ?? [] as $tool_call) {
            $item_id = 'fc_' . wp_generate_uuid4();
            $call_id = $tool_call['id'];
            $name = $tool_call['function']['name'];
            $arguments = $tool_call['function']['arguments'];
            $events[] = self::event('response.output_item.added', [
                'type' => 'response.output_item.added', 'sequence_number' => $sequence++, 'output_index' => $output_index,
                'item' => ['id' => $item_id, 'type' => 'function_call', 'call_id' => $call_id, 'name' => $name, 'arguments' => '', 'status' => 'in_progress'],
            ]);
            $events[] = self::event('response.function_call_arguments.delta', [
                'type' => 'response.function_call_arguments.delta', 'sequence_number' => $sequence++, 'output_index' => $output_index,
                'item_id' => $item_id, 'delta' => $arguments,
            ]);
            $events[] = self::event('response.function_call_arguments.done', [
                'type' => 'response.function_call_arguments.done', 'sequence_number' => $sequence++, 'output_index' => $output_index,
                'item_id' => $item_id, 'arguments' => $arguments,
            ]);
            $events[] = self::event('response.output_item.done', [
                'type' => 'response.output_item.done', 'sequence_number' => $sequence++, 'output_index' => $output_index++,
                'item' => ['id' => $item_id, 'type' => 'function_call', 'call_id' => $call_id, 'name' => $name, 'arguments' => $arguments, 'status' => 'completed'],
            ]);
        }

        $events[] = self::event('response.completed', [
            'type' => 'response.completed', 'sequence_number' => $sequence,
            'response' => self::response_payload($id, $model, $generation),
        ]);
        $response = new WP_REST_Response(['events' => $events]);
        $response->header('Content-Type', 'text/event-stream; charset=utf-8');
        $response->header('Cache-Control', 'no-cache');
        $response->header('X-WP-AI-Gateway-SSE', '1');
        return $response;
    }

    private static function response_payload(string $id, string $model, array $generation): array
    {
        $output = [];
        if (is_string($generation['content'] ?? null) && '' !== $generation['content']) {
            $output[] = ['id' => 'msg_' . wp_generate_uuid4(), 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $generation['content'], 'annotations' => []]]];
        }
        foreach ($generation['tool_calls'] ?? [] as $tool_call) {
            $output[] = ['id' => 'fc_' . wp_generate_uuid4(), 'type' => 'function_call', 'call_id' => $tool_call['id'], 'name' => $tool_call['function']['name'], 'arguments' => $tool_call['function']['arguments'], 'status' => 'completed'];
        }
        return ['id' => $id, 'object' => 'response', 'created_at' => time(), 'status' => 'completed', 'model' => $model, 'output' => $output, 'usage' => null, 'error' => null, 'incomplete_details' => null];
    }

    private static function event(string $name, array $data): array
    {
        return ['event' => $name, 'data' => $data];
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
