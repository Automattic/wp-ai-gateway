<?php
/**
 * Shared constants for WP AI Gateway.
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

const REST_NAMESPACE = 'wp-ai-gateway/v1';
const OPTION_TOKEN_HASH = 'wp_ai_gateway_token_hash';
const OPTION_CLIENTS = 'wp_ai_gateway_clients';
const OPTION_CLIENTS_LOCK = 'wp_ai_gateway_clients_lock';
const OPTION_CLIENT_LAST_USED_PREFIX = 'wp_ai_gateway_client_last_used_';
const OPTION_CLIENT_REVOKED_PREFIX = 'wp_ai_gateway_client_revoked_';
const OPTION_PROVIDER = 'wp_ai_gateway_provider';
const OPTION_MODEL = 'wp_ai_gateway_model';
const MODEL_SITE_DEFAULT = 'site-default';
