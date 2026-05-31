<?php
/**
 * Plugin bootstrap.
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

/**
 * Wires WordPress hooks to the gateway components.
 */
final class Plugin
{
    /**
     * Registers plugin hooks.
     *
     * @return void
     */
    public static function bootstrap(): void
    {
        add_action('rest_api_init', [RestController::class, 'register_routes']);
        add_action('admin_menu', [SettingsPage::class, 'register_menu']);
        add_action('admin_init', [SettingsPage::class, 'register_settings']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('ai-gateway', CliCommand::class);
        }
    }
}
