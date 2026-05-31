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
            '/chat/completions',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle_chat_completions'],
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

        $models = [
            OpenAiResponse::model_payload(MODEL_SITE_DEFAULT, 'wordpress'),
        ];

        $registry = AiClientBridge::registry();
        if ($registry && method_exists($registry, 'getRegisteredProviderIds')) {
            foreach ($registry->getRegisteredProviderIds() as $provider_id) {
                $provider_models = AiClientBridge::provider_models((string) $provider_id);
                foreach ($provider_models as $model_id) {
                    $models[] = OpenAiResponse::model_payload(ProviderRouter::model_alias((string) $provider_id, $model_id), 'wordpress');
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
     * Handles OpenAI-compatible chat completions.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_chat_completions(WP_REST_Request $request)
    {
        $authorized = TokenAuthenticator::authorize($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return OpenAiResponse::error('invalid_request_error', 'Request body must be JSON.', 400);
        }

        $messages = $payload['messages'] ?? null;
        if (!is_array($messages) || [] === $messages) {
            return OpenAiResponse::error('invalid_request_error', 'Request body must include messages.', 400);
        }

        $route = ProviderRouter::route_for_requested_model(is_string($payload['model'] ?? null) ? $payload['model'] : MODEL_SITE_DEFAULT);
        if ($route instanceof WP_REST_Response || $route instanceof WP_Error) {
            return $route;
        }

        $text = AiClientBridge::generate_text($route, $payload);
        if ($text instanceof WP_REST_Response) {
            return $text;
        }

        return new WP_REST_Response(
            [
                'id' => 'chatcmpl-' . wp_generate_uuid4(),
                'object' => 'chat.completion',
                'created' => time(),
                'model' => $payload['model'] ?? MODEL_SITE_DEFAULT,
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => $text,
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => null,
            ]
        );
    }
}
