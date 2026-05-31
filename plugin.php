<?php
/**
 * Plugin Name: WP AI Gateway
 * Plugin URI: https://github.com/chubes4/wp-ai-gateway
 * Description: OpenAI-compatible AI gateway for WordPress, backed by the WordPress AI Client.
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Version: 0.1.0
 * Author: Chris Huber
 * Author URI: https://github.com/chubes4
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: wp-ai-gateway
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

if (!defined('ABSPATH')) {
    return;
}

define(__NAMESPACE__ . '\PLUGIN_FILE', __FILE__);
define(__NAMESPACE__ . '\PLUGIN_DIR', __DIR__);

require_once __DIR__ . '/inc/constants.php';
require_once __DIR__ . '/inc/class-openai-response.php';
require_once __DIR__ . '/inc/class-token-authenticator.php';
require_once __DIR__ . '/inc/class-provider-router.php';
require_once __DIR__ . '/inc/class-ai-client-bridge.php';
require_once __DIR__ . '/inc/class-rest-controller.php';
require_once __DIR__ . '/inc/class-settings-page.php';
require_once __DIR__ . '/inc/class-cli-command.php';
require_once __DIR__ . '/inc/class-plugin.php';

Plugin::bootstrap();
