<?php
/**
 * Plugin Name: OpenID Connect Email & Username Whitelist
 * Plugin URI: https://your-website.com/plugin
 * Description: Beschränkt die Registrierung über OpenID Connect auf bestimmte E-Mail-Adressen und Benutzernamen mit Rollenzuweisung
 * Version: 1.1.0
 * Author: Joachim Happel
 * Author URI: https://your-website.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: openid-connect-whitelist
 * Domain Path: /languages
 * Network: true
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('OPENID_WHITELIST_VERSION', '1.1.0');
define('OPENID_WHITELIST_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OPENID_WHITELIST_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader für Klassen
spl_autoload_register(function ($class) {
    $prefix = 'OpenIDWhitelist\\';
    $base_dir = OPENID_WHITELIST_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . str_replace('\\', '/', strtolower($relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Aktivierung und Deaktivierung
register_activation_hook(__FILE__, ['OpenIDWhitelist\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['OpenIDWhitelist\Activator', 'deactivate']);

// Plugin initialisieren
add_action('plugins_loaded', function() {
    load_plugin_textdomain(
        'openid-connect-whitelist',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
    OpenIDWhitelist\EmailWhitelist::get_instance();
});
