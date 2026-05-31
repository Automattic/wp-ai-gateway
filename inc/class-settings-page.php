<?php
/**
 * Admin settings page.
 *
 * @package Chubes4\WpAiGateway
 */

declare(strict_types=1);

namespace Chubes4\WpAiGateway;

/**
 * Registers and renders gateway settings.
 */
final class SettingsPage
{
    /**
     * Registers the admin settings page.
     *
     * @return void
     */
    public static function register_menu(): void
    {
        add_options_page(
            'WP AI Gateway',
            'WP AI Gateway',
            'manage_options',
            'wp-ai-gateway',
            [self::class, 'render']
        );
    }

    /**
     * Registers gateway settings.
     *
     * @return void
     */
    public static function register_settings(): void
    {
        register_setting('wp_ai_gateway', OPTION_PROVIDER, ['type' => 'string', 'sanitize_callback' => 'sanitize_key']);
        register_setting('wp_ai_gateway', OPTION_MODEL, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('wp_ai_gateway', OPTION_TOKEN_HASH, ['type' => 'string', 'sanitize_callback' => [TokenAuthenticator::class, 'sanitize_token_hash']]);
    }

    /**
     * Renders the admin settings page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1>WP AI Gateway</h1>
            <p>Expose this WordPress site as an OpenAI-compatible gateway backed by the WordPress AI Client.</p>
            <form method="post" action="options.php">
                <?php settings_fields('wp_ai_gateway'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(OPTION_PROVIDER); ?>">Provider</label></th>
                        <td><input name="<?php echo esc_attr(OPTION_PROVIDER); ?>" id="<?php echo esc_attr(OPTION_PROVIDER); ?>" type="text" class="regular-text" value="<?php echo esc_attr(ProviderRouter::configured_provider()); ?>" placeholder="example-provider" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(OPTION_MODEL); ?>">Model</label></th>
                        <td><input name="<?php echo esc_attr(OPTION_MODEL); ?>" id="<?php echo esc_attr(OPTION_MODEL); ?>" type="text" class="regular-text" value="<?php echo esc_attr(ProviderRouter::configured_model()); ?>" placeholder="example-model" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
