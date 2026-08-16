<?php
/**
 * REST API controller.
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and handles the OpenAI-compatible REST surface.
 */
final class RestController
{
    /**
     * Registers REST routes.
     *
     * @return void
     */
    public static function register_routes(): void
    {
        register_rest_route(
            REST_NAMESPACE,
            '/models',
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handle_models'],
                'permission_callback' => [self::class, 'permission_callback'],
            ]
        );

        register_rest_route(
            REST_NAMESPACE,
            '/responses',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle_responses'],
                'permission_callback' => [self::class, 'permission_callback'],
            ]
        );

        register_rest_route(
            REST_NAMESPACE,
            '/embeddings',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle_embeddings'],
                'permission_callback' => [self::class, 'permission_callback'],
            ]
        );
    }

    /**
     * REST permissions are handled inside callbacks so errors stay OpenAI-shaped.
     *
     * @param WP_REST_Request $request REST request.
     * @return true
     */
    public static function permission_callback(WP_REST_Request $request): bool
    {
        unset($request);
        return true;
    }

    /**
     * Handles OpenAI-compatible model listing.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_models(WP_REST_Request $request)
    {
        $authorized = TokenAuthenticator::authorize($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        $configured = ProviderRouter::configured_route();
        if ($configured instanceof WP_REST_Response || $configured instanceof WP_Error) {
            return $configured;
        }

        $models = [];
        if (TokenAuthenticator::model_allowed($authorized, MODEL_SITE_DEFAULT)) {
            $models[] = OpenAiResponse::model_payload(MODEL_SITE_DEFAULT, 'wordpress');
        }

        $registry = AiClientBridge::registry();
        if ($registry && method_exists($registry, 'getRegisteredProviderIds')) {
            foreach ($registry->getRegisteredProviderIds() as $provider_id) {
                $provider_models = AiClientBridge::provider_models((string) $provider_id);
                foreach ($provider_models as $model) {
                    $alias = ProviderRouter::model_alias((string) $provider_id, $model['id']);
                    if (!TokenAuthenticator::model_allowed($authorized, $alias)) {
                        continue;
                    }
                    $models[] = OpenAiResponse::model_payload(
                        $alias,
                        'wordpress',
                        $model['capabilities'],
                        $model['metadata']
                    );
                }
            }
        }

        return new WP_REST_Response(
            [
                'object' => 'list',
                'data' => array_values(OpenAiResponse::unique_models($models)),
            ]
        );
    }

    /**
     * Handles provider-neutral generation through the Responses API.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_responses(WP_REST_Request $request)
    {
        $authorized = TokenAuthenticator::authorize($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return OpenAiResponse::error('invalid_request_error', 'Request body must be JSON.', 400);
        }

        if (!array_key_exists('input', $payload)) {
            return OpenAiResponse::error('invalid_request_error', 'Request body must include input.', 400);
        }

        $requested_model = is_string($payload['model'] ?? null) ? $payload['model'] : MODEL_SITE_DEFAULT;
        if (!TokenAuthenticator::model_allowed($authorized, $requested_model)) {
            return OpenAiResponse::error('permission_error', 'The authenticated client is not allowed to use this model.', 403);
        }

        $route = ProviderRouter::route_for_requested_model($requested_model);
        if ($route instanceof WP_REST_Response || $route instanceof WP_Error) {
            return $route;
        }

        $generation = AiClientBridge::generate_response_result($route, $payload);
        if ($generation instanceof WP_REST_Response) {
            return $generation;
        }

        $id = 'resp_' . wp_generate_uuid4();
        $model = is_string($payload['model'] ?? null) ? $payload['model'] : MODEL_SITE_DEFAULT;
        if (true === ($payload['stream'] ?? false)) {
            return OpenAiResponse::response_stream($id, $model, $generation);
        }

        return OpenAiResponse::response($id, $model, $generation);
    }

    /**
     * Sends gateway stream responses without REST JSON encoding.
     *
     * @param bool            $served Whether another handler served the request.
     * @param WP_REST_Response $response REST response.
     * @param WP_REST_Request $request REST request.
     * @param object          $server REST server.
     * @return bool
     */
    public static function serve_stream(bool $served, $response, WP_REST_Request $request, $server): bool
    {
        unset($request);
        if ($served || !is_object($response) || !method_exists($response, 'get_headers') || '1' !== ($response->get_headers()['X-WP-AI-Gateway-SSE'] ?? '')) {
            return $served;
        }

        $data = $response->get_data();
        if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
            return $served;
        }

        if (is_object($server) && method_exists($server, 'send_header')) {
            $server->send_header('Content-Type', 'text/event-stream; charset=utf-8');
            $server->send_header('Cache-Control', 'no-cache');
        }
        foreach ($data['events'] as $event) {
            echo 'event: ' . $event['event'] . "\n";
            echo 'data: ' . wp_json_encode($event['data']) . "\n\n";
        }
        echo "data: [DONE]\n\n";
        return true;
    }

    /**
     * Handles OpenAI-compatible embeddings.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_embeddings(WP_REST_Request $request)
    {
        $authorized = TokenAuthenticator::authorize($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return OpenAiResponse::error('invalid_request_error', 'Request body must be JSON.', 400);
        }

        if (!array_key_exists('input', $payload)) {
            return OpenAiResponse::error('invalid_request_error', 'Request body must include input.', 400);
        }

        $requested_model = is_string($payload['model'] ?? null) ? $payload['model'] : MODEL_SITE_DEFAULT;
        if (!TokenAuthenticator::model_allowed($authorized, $requested_model)) {
            return OpenAiResponse::error('permission_error', 'The authenticated client is not allowed to use this model.', 403);
        }

        $route = ProviderRouter::route_for_requested_model($requested_model);
        if ($route instanceof WP_REST_Response || $route instanceof WP_Error) {
            return $route;
        }

        $embeddings = AiClientBridge::generate_embeddings($route, $payload);
        if ($embeddings instanceof WP_REST_Response) {
            return $embeddings;
        }

        return OpenAiResponse::embedding_payload(
            is_string($payload['model'] ?? null) ? $payload['model'] : MODEL_SITE_DEFAULT,
            $embeddings['embeddings'],
            $embeddings['usage'],
            $embeddings['request_id']
        );
    }
}
