<?php
namespace OpenIDWhitelist;

class Activator {
    public static function activate() {
        if (!is_plugin_active('openid-connect-generic/openid-connect-generic.php')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                __('OpenID Connect Email Whitelist benötigt das Plugin "OpenID Connect Generic". Bitte installieren und aktivieren Sie es zuerst.', 'openid-connect-whitelist'),
                __('Plugin Aktivierungsfehler', 'openid-connect-whitelist'),
                ['back_link' => true]
            );
        }

        // Standardeinstellungen für Network
        if (is_multisite()) {
            $network_defaults = [
                'whitelist_network_only' => false,
                'allow_local_override' => true
            ];

            foreach ($network_defaults as $option => $value) {
                add_site_option($option, $value);
            }
        }

        // Erstelle notwendige Datenbankeinträge für jede Rolle
        $roles = ['subscriber', 'author', 'administrator'];
        foreach ($roles as $role) {
            $options = [
                "whitelist_{$role}_entries" => '',
                "whitelist_{$role}_enabled" => false
            ];

            if (is_multisite()) {
                foreach ($options as $option => $value) {
                    add_site_option($option, $value);
                }
            }
            
            foreach ($options as $option => $value) {
                add_option($option, $value);
            }
        }

        flush_rewrite_rules();
    }

    public static function deactivate() {
        // Optional: Aufräumen bei Deaktivierung

        /*
        if (is_multisite()) {
            delete_site_option('whitelist_network_only');
            delete_site_option('allow_local_override');
            
            $roles = ['subscriber', 'author', 'administrator'];
            foreach ($roles as $role) {
                delete_site_option("whitelist_{$role}_entries");
                delete_site_option("whitelist_{$role}_enabled");
            }
        }

        $roles = ['subscriber', 'author', 'administrator'];
        foreach ($roles as $role) {
            delete_option("whitelist_{$role}_entries");
            delete_option("whitelist_{$role}_enabled");
        }
        */

        flush_rewrite_rules();
    }
}
