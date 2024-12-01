<?php
namespace OpenIDWhitelist;

if (!defined('ABSPATH')) exit;

class EmailWhitelist {
    private static $instance = null;
    private $available_roles = [
        'subscriber' => 'Standard-Benutzer',
        'author' => 'Autor',
        'administrator' => 'Administrator'
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('network_admin_menu', [$this, 'add_network_menu']);
        add_action('admin_menu', [$this, 'add_site_menu']);
        add_filter('openid-connect-generic-user-creation-test', [$this, 'check_whitelist'], 10, 2);
        add_filter('openid-connect-generic-user-creation', [$this, 'assign_role'], 10, 2);
        add_action('login_message', [$this, 'login_message']);
        add_action('openid-connect-generic-user-create-failed', [$this, 'error_message']);
        add_action('admin_init', [$this, 'check_dependencies']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    private function get_option_names($role) {
        return [
            'whitelist' => "whitelist_{$role}_entries",
            'enabled' => "whitelist_{$role}_enabled"
        ];
    }

    public function check_dependencies() {
        if (!is_plugin_active('openid-connect-generic/openid-connect-generic.php')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . 
                    esc_html__('OpenID Connect Email Whitelist benötigt das Plugin "OpenID Connect Generic" um zu funktionieren.', 'openid-connect-whitelist') . 
                    '</p></div>';
            });
        }
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'email-whitelist') === false) {
            return;
        }

        wp_enqueue_style(
            'openid-whitelist-admin',
            OPENID_WHITELIST_PLUGIN_URL . 'assets/css/admin.css',
            [],
            OPENID_WHITELIST_VERSION
        );

        wp_enqueue_script(
            'openid-whitelist-admin',
            OPENID_WHITELIST_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            OPENID_WHITELIST_VERSION,
            true
        );
    }

    public function add_network_menu() {
        add_submenu_page(
            'users.php',
            __('Network Email Whitelist', 'openid-connect-whitelist'),
            __('Network Email Whitelist', 'openid-connect-whitelist'),
            'manage_network',
            'network-email-whitelist',
            [$this, 'render_network_settings_page']
        );
    }

    public function add_site_menu() {
        if (!is_multisite() || !get_site_option('whitelist_network_only', false)) {
            add_users_page(
                __('Email Whitelist', 'openid-connect-whitelist'),
                __('Email Whitelist', 'openid-connect-whitelist'),
                'manage_options',
                'email-whitelist',
                [$this, 'render_site_settings_page']
            );
        }
    }

    public function render_network_settings_page() {
        if (!current_user_can('manage_network')) {
            wp_die(__('Sie haben nicht die erforderlichen Rechte um diese Seite aufzurufen.', 'openid-connect-whitelist'));
        }

        if (isset($_POST['submit'])) {
            check_admin_referer('whitelist_network_settings');
            $this->save_network_settings();
        }

        $network_only = get_site_option('whitelist_network_only', false);
        $allow_local_override = get_site_option('allow_local_override', true);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Network Email & Username Whitelist', 'openid-connect-whitelist'); ?></h1>
            <?php settings_errors('whitelist_messages'); ?>
            
            <form method="post" action="">
                <?php wp_nonce_field('whitelist_network_settings'); ?>
                
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Globale Einstellungen', 'openid-connect-whitelist'); ?></th>
                        <td>
                            <fieldset>
                                <label for="whitelist_network_only">
                                    <input type="checkbox" id="whitelist_network_only" 
                                           name="whitelist_network_only" 
                                           <?php checked($network_only); ?>>
                                    <?php echo esc_html__('Nur netzwerkweite Whitelist verwenden', 'openid-connect-whitelist'); ?>
                                </label>
                                <br>
                                <label for="allow_local_override">
                                    <input type="checkbox" id="allow_local_override" 
                                           name="allow_local_override" 
                                           <?php checked($allow_local_override); ?>>
                                    <?php echo esc_html__('Lokale Whitelists bleiben aktiv wenn netzwerkweite deaktiviert', 'openid-connect-whitelist'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <?php foreach ($this->available_roles as $role => $role_name): 
                    $options = $this->get_option_names($role);
                    $whitelist = get_site_option($options['whitelist'], '');
                    $enabled = get_site_option($options['enabled'], false);
                ?>
                    <h2><?php echo esc_html($role_name); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Einstellungen', 'openid-connect-whitelist'); ?></th>
                            <td>
                                <fieldset>
                                    <label for="<?php echo esc_attr($options['enabled']); ?>">
                                        <input type="checkbox" 
                                               id="<?php echo esc_attr($options['enabled']); ?>"
                                               name="<?php echo esc_attr($options['enabled']); ?>" 
                                               <?php checked($enabled); ?>>
                                        <?php echo esc_html(sprintf(__('Whitelist für %s aktivieren', 'openid-connect-whitelist'), $role_name)); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($options['whitelist']); ?>">
                                    <?php echo esc_html__('Erlaubte Einträge', 'openid-connect-whitelist'); ?>
                                </label>
                            </th>
                            <td>
                                <p class="description">
                                    <?php echo esc_html__('Eine E-Mail-Adresse oder Benutzername pro Zeile:', 'openid-connect-whitelist'); ?>
                                </p>
                                <textarea id="<?php echo esc_attr($options['whitelist']); ?>"
                                          name="<?php echo esc_attr($options['whitelist']); ?>" 
                                          rows="10" 
                                          class="large-text code"><?php 
                                    echo esc_textarea($whitelist); 
                                ?></textarea>
                            </td>
                        </tr>
                    </table>
                <?php endforeach; ?>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function save_network_settings() {
        // Speichere globale Einstellungen
        update_site_option('whitelist_network_only', isset($_POST['whitelist_network_only']));
        update_site_option('allow_local_override', isset($_POST['allow_local_override']));

        // Speichere Einstellungen für jede Rolle
        foreach ($this->available_roles as $role => $role_name) {
            $options = $this->get_option_names($role);
            
            $whitelist = isset($_POST[$options['whitelist']]) ? 
                        sanitize_textarea_field($_POST[$options['whitelist']]) : '';
            update_site_option($options['whitelist'], $whitelist);
            
            update_site_option($options['enabled'], isset($_POST[$options['enabled']]));
        }
        
        add_settings_error(
            'whitelist_messages',
            'whitelist_updated',
            __('Einstellungen gespeichert.', 'openid-connect-whitelist'),
            'updated'
        );
    }

    public function render_site_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sie haben nicht die erforderlichen Rechte um diese Seite aufzurufen.', 'openid-connect-whitelist'));
        }

        if (isset($_POST['submit'])) {
            check_admin_referer('whitelist_settings');
            $this->save_site_settings();
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Email & Username Whitelist', 'openid-connect-whitelist'); ?></h1>
            <?php settings_errors('whitelist_messages'); ?>
            
            <form method="post" action="">
                <?php wp_nonce_field('whitelist_settings'); ?>
                
                <?php foreach ($this->available_roles as $role => $role_name): 
                    $options = $this->get_option_names($role);
                    $whitelist = get_option($options['whitelist'], '');
                    $enabled = get_option($options['enabled'], false);
                ?>
                    <h2><?php echo esc_html($role_name); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Einstellungen', 'openid-connect-whitelist'); ?></th>
                            <td>
                                <fieldset>
                                    <label for="<?php echo esc_attr($options['enabled']); ?>">
                                        <input type="checkbox" 
                                               id="<?php echo esc_attr($options['enabled']); ?>"
                                               name="<?php echo esc_attr($options['enabled']); ?>" 
                                               <?php checked($enabled); ?>>
                                        <?php echo esc_html(sprintf(__('Whitelist für %s aktivieren', 'openid-connect-whitelist'), $role_name)); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($options['whitelist']); ?>">
                                    <?php echo esc_html__('Erlaubte Einträge', 'openid-connect-whitelist'); ?>
                                </label>
                            </th>
                            <td>
                                <p class="description">
                                    <?php echo esc_html__('Eine E-Mail-Adresse oder Benutzername pro Zeile:', 'openid-connect-whitelist'); ?>
                                </p>
                                <textarea id="<?php echo esc_attr($options['whitelist']); ?>"
                                          name="<?php echo esc_attr($options['whitelist']); ?>" 
                                          rows="10" 
                                          class="large-text code"><?php 
                                    echo esc_textarea($whitelist); 
                                ?></textarea>
                            </td>
                        </tr>
                    </table>
                <?php endforeach; ?>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function save_site_settings() {
        foreach ($this->available_roles as $role => $role_name) {
            $options = $this->get_option_names($role);
            
            $whitelist = isset($_POST[$options['whitelist']]) ? 
                        sanitize_textarea_field($_POST[$options['whitelist']]) : '';
            update_option($options['whitelist'], $whitelist);
            
            update_option($options['enabled'], isset($_POST[$options['enabled']]));
        }
        
        add_settings_error(
            'whitelist_messages',
            'whitelist_updated',
            __('Einstellungen gespeichert.', 'openid-connect-whitelist'),
            'updated'
        );
    }

    public function check_whitelist($can_register, $user_data) {
        if (!isset($user_data->email) || !isset($user_data->login)) {
            return false;
        }

        $network_only = get_site_option('whitelist_network_only', false);
        $allow_local_override = get_site_option('allow_local_override', true);
        
        // Überprüfe jede Rolle
        foreach ($this->available_roles as $role => $role_name) {
            $options = $this->get_option_names($role);
            $allowed_entries = [];
            
            // Prüfe Network Whitelist
            if (get_site_option($options['enabled'], false)) {
                $network_whitelist = get_site_option($options['whitelist'], '');
                $allowed_entries = array_merge(
                    $allowed_entries, 
                    array_map('trim', explode("\n", $network_whitelist))
                );
            }

            // Prüfe lokale Whitelist wenn erlaubt
            if (!$network_only && $allow_local_override && get_option($options['enabled'], false)) {
                $site_whitelist = get_option($options['whitelist'], '');
                $allowed_entries = array_merge(
                    $allowed_entries, 
                    array_map('trim', explode("\n", $site_whitelist))
                );
            }

            $allowed_entries = array_filter(array_unique($allowed_entries));
            
            // Prüfe ob Email oder Username in der Liste ist
            if (in_array($user_data->email, $allowed_entries) || 
                in_array($user_data->login, $allowed_entries)) {
                // Speichere die Rolle für spätere Zuweisung
                update_user_meta(0, '_pending_role_' . $user_data->login, $role);
                return $can_register;
            }
        }

        return false;
    }

    public function assign_role($user, $user_data) {
        if ($user instanceof \WP_User) {
            $pending_role = get_user_meta(0, '_pending_role_' . $user_data->login, true);
            if ($pending_role && array_key_exists($pending_role, $this->available_roles)) {
                $user->set_role($pending_role);
            }
            delete_user_meta(0, '_pending_role_' . $user_data->login);
        }
        return $user;
    }

    public function login_message($message) {
        $network_enabled = false;
        $site_enabled = false;

        // Prüfe, ob irgendeine Whitelist aktiviert ist
        foreach ($this->available_roles as $role => $role_name) {
            $options = $this->get_option_names($role);
            if (get_site_option($options['enabled'], false)) {
                $network_enabled = true;
            }
            if (get_option($options['enabled'], false)) {
                $site_enabled = true;
            }
        }

        if ($network_enabled || $site_enabled) {
            $custom_message = '<p class="message">' . 
                esc_html__('Hinweis: Diese WordPress-Instanz ist nur für autorisierte Mitglieder zugänglich.', 'openid-connect-whitelist') .
                '</p>';

            // Füge die Nachricht zum bestehenden Message-String hinzu oder erstelle einen neuen
            if (!empty($message)) {
                return $message . $custom_message;
            }
            return $custom_message;
        }

        return $message;
    }

    public function error_message() {
        wp_die(
            esc_html__('Sie sind nicht berechtigt, sich an dieser WordPress-Instanz zu registrieren. Bitte kontaktieren Sie den Administrator.', 'openid-connect-whitelist'),
            esc_html__('Zugriff verweigert', 'openid-connect-whitelist'),
            [
                'response' => 403,
                'back_link' => true
            ]
        );
    }

    /**
     * Hilfsmethode zum Bereinigen der Whitelist-Einträge
     *
     * @param string $whitelist_text Roher Whitelist-Text
     * @return array Bereinigte und eindeutige Einträge
     */
    private function sanitize_whitelist_entries($whitelist_text) {
        $entries = explode("\n", $whitelist_text);
        $entries = array_map('trim', $entries);
        $entries = array_filter($entries);
        $entries = array_unique($entries);
        
        // Entferne ungültige Email-Adressen und Benutzernamen
        return array_filter($entries, function($entry) {
            // Prüfe ob es eine gültige Email ist
            if (is_email($entry)) {
                return true;
            }
            
            // Prüfe ob es ein gültiger Benutzername ist
            // Verwendet WordPress' sanitize_user Funktion zur Validierung
            return sanitize_user($entry, true) === $entry;
        });
    }

    /**
     * Hilfsmethode zur Protokollierung
     *
     * @param string $message Nachricht die protokolliert werden soll
     * @param mixed $data Zusätzliche Daten für die Protokollierung
     */
    private function log($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log(sprintf(
                '[OpenID Whitelist] %s | Data: %s',
                $message,
                print_r($data, true)
            ));
        }
    }

    /**
     * Verhindert das Klonen der Instanz
     */
    private function __clone() {}

    /**
     * Verhindert das Deserialisieren der Instanz
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}
