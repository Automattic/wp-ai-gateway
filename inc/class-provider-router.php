<?php
/**
 * Provider/model route resolution.
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

use WP_REST_Response;

/**
 * Resolves OpenAI-compatible model names into WordPress AI Client provider routes.
 */
final class ProviderRouter
{
    /**
     * Configures the site-default provider/model route.
     *
     * @param string $provider Provider ID.
     * @param string $model Model ID.
     * @return void
     */
    public static function configure(string $provider, string $model): void
    {
        update_option(OPTION_PROVIDER, sanitize_key($provider), false);
        update_option(OPTION_MODEL, sanitize_text_field($model), false);
    }

    /**
     * Returns the configured provider ID.
     *
     * @return string
     */
    public static function configured_provider(): string
    {
        $provider = get_option(OPTION_PROVIDER, '');
        return is_string($provider) ? sanitize_key($provider) : '';
    }

    /**
     * Returns the configured model ID.
     *
     * @return string
     */
    public static function configured_model(): string
    {
        $model = get_option(OPTION_MODEL, '');
        return is_string($model) ? sanitize_text_field($model) : '';
    }

    /**
     * Returns the configured provider/model route.
     *
     * @return array{provider:string,model:string}|WP_REST_Response
     */
    public static function configured_route()
    {
        $provider = self::configured_provider();
        $model = self::configured_model();

        if ('' === $provider || '' === $model) {
            return OpenAiResponse::error('server_error', 'Gateway provider and model are not configured.', 500);
        }

        return [
            'provider' => $provider,
            'model' => $model,
        ];
    }

    /**
     * Resolves a requested OpenAI-compatible model ID.
     *
     * @param string $requested_model Requested model.
     * @return array{provider:string,model:string}|WP_REST_Response
     */
    public static function route_for_requested_model(string $requested_model)
    {
        if (MODEL_SITE_DEFAULT === $requested_model || '' === $requested_model) {
            return self::configured_route();
        }

        $delimiter = strpos($requested_model, ':');
        if (false === $delimiter) {
            return self::configured_route();
        }

        return [
            'provider' => sanitize_key(substr($requested_model, 0, $delimiter)),
            'model' => sanitize_text_field(substr($requested_model, $delimiter + 1)),
        ];
    }

    /**
     * Normalizes an external model ID for policy checks.
     *
     * Unqualified names route to site-default and therefore share its policy.
     *
     * @param string $requested_model Requested model.
     * @return string
     */
    public static function policy_model_id(string $requested_model): string
    {
        $requested_model = trim($requested_model);
        if ('' === $requested_model || false === strpos($requested_model, ':')) {
            return MODEL_SITE_DEFAULT;
        }

        $delimiter = strpos($requested_model, ':');
        return sanitize_key(substr($requested_model, 0, $delimiter)) . ':' . sanitize_text_field(substr($requested_model, $delimiter + 1));
    }

    /**
     * Builds a provider-qualified model alias for external clients.
     *
     * @param string $provider Provider ID.
     * @param string $model Model ID.
     * @return string
     */
    public static function model_alias(string $provider, string $model): string
    {
        return sanitize_key($provider) . ':' . $model;
    }
}
