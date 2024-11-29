<?php
/*
Plugin Name: OpenID Connect Email Whitelist
Description: Beschränkt die Registrierung über OpenID Connect auf bestimmte E-Mail-Adressen
Version: 1.0.0
Author: Joachim happel
Network: true
*/

if (!defined('ABSPATH')) exit;

class OpenID_Email_Whitelist {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('network_admin_menu', [$this, 'add_network_menu']);
        add_action('admin_menu', [$this, 'add_site_menu']);
        add_filter('openid-connect-generic-user-creation-test', [$this, 'check_email_whitelist'], 10, 2);
        add_action('login_message', [$this, 'login_message']);
        add_action('openid-connect-generic-user-create-failed', [$this, 'error_message']);
        add_action('admin_init', [$this, 'check_dependencies']);
    }

    public function check_dependencies() {
        if (!is_plugin_active('openid-connect-generic/openid-connect-generic.php')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>OpenID Connect Email Whitelist benötigt das Plugin "OpenID Connect Generic" um zu funktionieren.</p></div>';
            });
        }
    }

    public function add_network_menu() {
        add_submenu_page(
            'users.php',
            'Network Email Whitelist',
            'Network Email Whitelist',
            'manage_network',
            'network-email-whitelist',
            [$this, 'network_admin_page']
        );
    }

    public function add_site_menu() {
        if (!is_multisite() || !get_site_option('whitelist_network_only', false)) {
            add_users_page(
                'Email Whitelist',
                'Email Whitelist',
                'manage_options',
                'email-whitelist',
                [$this, 'admin_page']
            );
        }
    }

    public function network_admin_page() {
        if (!current_user_can('manage_network')) {
            wp_die(__('Sie haben nicht die erforderlichen Rechte um diese Seite aufzurufen.'));
        }

        if (isset($_POST['submit']) && check_admin_referer('whitelist_network_settings')) {
            $this->save_network_settings();
        }

        $this->render_network_settings_page();
    }

    private function save_network_settings() {
        update_site_option('whitelist_emails', sanitize_textarea_field($_POST['whitelist_emails'] ?? ''));
        update_site_option('whitelist_enabled', isset($_POST['whitelist_enabled']));
        update_site_option('whitelist_network_only', isset($_POST['whitelist_network_only']));
        update_site_option('allow_local_override', isset($_POST['allow_local_override']));
        
        add_settings_error(
            'whitelist_messages',
            'whitelist_updated',
            'Einstellungen gespeichert.',
            'updated'
        );
    }

    private function render_network_settings_page() {
        $whitelist = get_site_option('whitelist_emails', '');
        $enabled = get_site_option('whitelist_enabled', false);
        $network_only = get_site_option('whitelist_network_only', false);
        $allow_local_override = get_site_option('allow_local_override', true);
        ?>
        <div class="wrap">
            <h2>Network Email Whitelist</h2>
            <?php settings_errors('whitelist_messages'); ?>
            
            <form method="post">
                <?php wp_nonce_field('whitelist_network_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Einstellungen</th>
                        <td>
                            <label>
                                <input type="checkbox" name="whitelist_enabled" <?php checked($enabled); ?>>
                                Whitelist-Beschränkung netzwerkweit aktivieren
                            </label><br>
                            <label>
                                <input type="checkbox" name="whitelist_network_only" <?php checked($network_only); ?>>
                                Nur netzwerkweite Whitelist verwenden
                            </label><br>
                            <label>
                                <input type="checkbox" name="allow_local_override" <?php checked($allow_local_override); ?>>
                                Lokale Whitelists bleiben aktiv wenn netzwerkweite deaktiviert
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Erlaubte E-Mail-Adressen</th>
                        <td>
                            <p class="description">Eine E-Mail-Adresse pro Zeile eingeben:</p>
                            <textarea name="whitelist_emails" rows="10" cols="50" class="large-text"><?php 
                                echo esc_textarea($whitelist); 
                            ?></textarea>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sie haben nicht die erforderlichen Rechte um diese Seite aufzurufen.'));
        }

        if (isset($_POST['submit']) && check_admin_referer('whitelist_settings')) {
            $this->save_site_settings();
        }

        $this->render_site_settings_page();
    }

    private function save_site_settings() {
        update_option('whitelist_emails', sanitize_textarea_field($_POST['whitelist_emails'] ?? ''));
        update_option('whitelist_enabled', isset($_POST['whitelist_enabled']));
        
        add_settings_error(
            'whitelist_messages',
            'whitelist_updated',
            'Einstellungen gespeichert.',
            'updated'
        );
    }

    private function render_site_settings_page() {
        $whitelist = get_option('whitelist_emails', '');
        $enabled = get_option('whitelist_enabled', false);
        ?>
        <div class="wrap">
            <h2>Email Whitelist</h2>
            <?php settings_errors('whitelist_messages'); ?>
            
            <form method="post">
                <?php wp_nonce_field('whitelist_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Einstellungen</th>
                        <td>
                            <label>
                                <input type="checkbox" name="whitelist_enabled" <?php checked($enabled); ?>>
                                Whitelist-Beschränkung für diese Site aktivieren
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Erlaubte E-Mail-Adressen</th>
                        <td>
                            <p class="description">Eine E-Mail-Adresse pro Zeile eingeben:</p>
                            <textarea name="whitelist_emails" rows="10" cols="50" class="large-text"><?php 
                                echo esc_textarea($whitelist); 
                            ?></textarea>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function check_email_whitelist($can_register, $user_data) {
        if (!isset($user_data->email)) {
            return false;
        }

        $network_enabled = get_site_option('whitelist_enabled', false);
        $site_enabled = get_option('whitelist_enabled', false);
        $network_only = get_site_option('whitelist_network_only', false);
        $allow_local_override = get_site_option('allow_local_override', true);

        if (!$network_enabled && !($allow_local_override && $site_enabled)) {
            return $can_register;
        }

        $allowed_emails = [];
        
        if ($network_enabled) {
            $network_whitelist = get_site_option('whitelist_emails', '');
            $allowed_emails = array_merge($allowed_emails, array_map('trim', explode("\n", $network_whitelist)));
        }

        if (!$network_only && ($network_enabled || ($allow_local_override && $site_enabled))) {
            $site_whitelist = get_option('whitelist_emails', '');
            $allowed_emails = array_merge($allowed_emails, array_map('trim', explode("\n", $site_whitelist)));
        }

        $allowed_emails = array_filter(array_unique($allowed_emails));
        return in_array($user_data->email, $allowed_emails) ? $can_register : false;
    }

    public function login_message($message) {
        $network_enabled = get_site_option('whitelist_enabled', false);
        $site_enabled = get_option('whitelist_enabled', false);
        
        if ($network_enabled || $site_enabled) {
            $message .= '<p class="message">Hinweis: Diese WordPress-Instanz ist nur für autorisierte Mitglieder zugänglich.</p>';
        }
        return $message;
    }

    public function error_message() {
        wp_die(
            'Sie sind nicht berechtigt, sich an dieser WordPress-Instanz zu registrieren. Bitte kontaktieren Sie den Administrator.',
            'Zugriff verweigert',
            ['response' => 403]
        );
    }
}

// Plugin initialisieren
function openid_email_whitelist_init() {
    OpenID_Email_Whitelist::get_instance();
}
add_action('plugins_loaded', 'openid_email_whitelist_init');

// Aktivierungshook
register_activation_hook(__FILE__, function() {
    if (!is_plugin_active('openid-connect-generic/openid-connect-generic.php')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            'OpenID Connect Email Whitelist benötigt das Plugin "OpenID Connect Generic". Bitte installieren und aktivieren Sie es zuerst.',
            'Plugin Aktivierungsfehler',
            ['back_link' => true]
        );
    }
});
