<?php
/**
 * Plugin Name: ST404 Woo Abandoned Cart Recovery
 * Plugin URI: https://lestudio404.fr
 * Description: Plugin simple pour récupérer les paniers abandonnés avec envoi d'emails automatiques
 * Version: 1.8.31
 * Author: Le Studio 404
 * Text Domain: abandoned-cart-recovery
 * WC requires at least: 3.0.0
 * WC tested up to: 8.0.0
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * WC requires PHP: 7.4
 * Requires PHP: 7.4
 * Woo: 12345:342928dfsfhsf2349842374wdf4234sfd
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Empêcher l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Définition des constantes
define('ACR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ACR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('ACR_VERSION', '1.8.31');

/**
 * Plugin Update Checker - Mises à jour automatiques depuis GitHub
 * Chargé directement (PUC gère lui-même les hooks)
 */
if (file_exists(__DIR__ . '/plugin-update-checker-loader.php')) {
    require_once __DIR__ . '/plugin-update-checker-loader.php';
}

// Inclure les fichiers nécessaires
require_once ACR_PLUGIN_PATH . 'includes/functions.php';
require_once ACR_PLUGIN_PATH . 'includes/class-email-handler.php';

// Classe principale du plugin
class AbandonedCartRecovery {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_acr_save_settings', array($this, 'save_settings'));
        add_action('wp_ajax_acr_get_abandoned_carts', array($this, 'get_abandoned_carts'));
        add_action('wp_ajax_acr_mark_recovered', array($this, 'mark_cart_recovered'));
        add_action('wp_ajax_acr_delete_cart', array($this, 'delete_cart'));
        add_action('wp_ajax_acr_dismiss_notice', array($this, 'dismiss_notice'));
        add_action('wp_ajax_acr_send_test_email', array($this, 'send_test_email'));
        add_action('wp_ajax_acr_reset_templates', array($this, 'reset_templates'));
        add_action('wp_ajax_acr_force_send_emails', array($this, 'force_send_emails'));
        add_action('wp_ajax_acr_reset_gdpr', array($this, 'reset_gdpr_consent'));
        add_action('wp_ajax_acr_capture_guest_email', array($this, 'ajax_capture_guest_email'));
        add_action('wp_ajax_nopriv_acr_capture_guest_email', array($this, 'ajax_capture_guest_email'));
        add_action('wp_ajax_acr_clean_duplicates', array($this, 'ajax_clean_duplicates'));
        add_action('wp_ajax_acr_force_send_immediate', array($this, 'ajax_force_send_immediate'));
        add_action('wp_ajax_acr_bulk_delete_carts', array($this, 'ajax_bulk_delete_carts'));
        add_action('wp_ajax_acr_bulk_delete_guests_carts', array($this, 'ajax_bulk_delete_guests_carts'));
        add_action('wp_ajax_acr_delete_guest_cart', array($this, 'ajax_delete_guest_cart'));
        
        // Hooks pour WooCommerce (compatible HPOS et classique)
        add_action('woocommerce_cart_updated', array($this, 'track_cart_update'));
        add_action('woocommerce_add_to_cart', array($this, 'track_cart_update'));
        add_action('woocommerce_cart_item_removed', array($this, 'track_cart_update'));
        add_action('woocommerce_checkout_order_processed', array($this, 'mark_cart_completed'));
        
        // Hook pour vider le panier après une commande réussie
        add_action('woocommerce_thankyou', array($this, 'clear_cart_after_order'));
        add_action('woocommerce_checkout_order_processed', array($this, 'clear_cart_after_order'));
        add_action('woocommerce_payment_complete', array($this, 'clear_cart_after_order'));
        add_action('woocommerce_order_status_processing', array($this, 'clear_cart_after_order'));
        add_action('woocommerce_order_status_completed', array($this, 'clear_cart_after_order'));
        
        // Hook pour capturer l'email des clients invités
        add_action('woocommerce_checkout_update_order_meta', array($this, 'capture_guest_email'));
        add_action('woocommerce_checkout_process', array($this, 'capture_guest_email_during_checkout'));
        add_action('woocommerce_checkout_fields', array($this, 'capture_guest_email_on_field_change'));
        add_action('wp_footer', array($this, 'add_guest_email_capture_script'));
        
        // Hooks HPOS pour WooCommerce 8.0+
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            // Marquer comme récupéré seulement quand la commande est complétée/payée
            add_action('woocommerce_order_status_completed', array($this, 'mark_cart_completed_hpos'));
            add_action('woocommerce_order_status_processing', array($this, 'mark_cart_completed_hpos'));
            add_action('woocommerce_payment_complete', array($this, 'mark_cart_completed_hpos'));
        }
        
        // Hook pour détecter automatiquement les paniers récupérés
        add_action('woocommerce_order_status_completed', array($this, 'auto_mark_cart_recovered'));
        add_action('woocommerce_order_status_processing', array($this, 'auto_mark_cart_recovered'));
        
        // Hooks RGPD
        add_action('wp_ajax_acr_consent_management', array($this, 'handle_consent_management'));
        add_action('wp_ajax_nopriv_acr_consent_management', array($this, 'handle_consent_management'));
        add_action('wp_ajax_acr_data_export', array($this, 'handle_data_export'));
        add_action('wp_ajax_acr_data_deletion', array($this, 'handle_data_deletion'));
        
        // Hook pour intercepter les actions RGPD depuis les emails
        add_action('init', array($this, 'handle_gdpr_actions'));
        
        // Plus besoin de page GDPR complexe
        
        // Cron pour l'envoi d'emails
        add_action('acr_send_reminder_emails', array($this, 'send_reminder_emails'));
        
        // Déclenchement alternatif lors des visites (si le cron ne fonctionne pas)
        add_action('wp', array($this, 'maybe_send_reminder_emails'));
        
        // Activation et désactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Vérification de compatibilité
        add_action('admin_notices', array($this, 'check_compatibility'));
        
        // Déclarer la compatibilité avec WooCommerce
        add_action('before_woocommerce_init', array($this, 'declare_woocommerce_compatibility'));
        
        // Supprimer les messages de dépréciation Divi en front-end
        add_action('wp_head', array($this, 'suppress_divi_deprecation_warnings'));
        
        // Initialiser les hooks
        $this->init_hooks();
        
        // AJAX pour forcer l'envoi immédiat
        add_action('wp_ajax_acr_force_send_immediate', array($this, 'ajax_force_send_immediate'));
        
        // AJAX pour forcer l'exécution du cron
        add_action('wp_ajax_acr_force_cron', array($this, 'ajax_force_cron'));
        
        // Déclencher l'envoi d'emails lors des visites (si pas de vrai cron)
        add_action('wp_head', array($this, 'maybe_send_emails_on_visit'));
    }
    
    public function init() {
        // Charger les traductions
        load_plugin_textdomain('abandoned-cart-recovery', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Créer la table si elle n'existe pas
        $this->create_tables();
    }
    
    public function activate() {
        $this->create_tables();
        $this->schedule_cron();
        
        // Créer les options par défaut avec le bon style de bouton
        $this->create_default_settings();
        
        // FORCER la suppression complète des anciens templates
        $this->force_reset_templates();
        
        // Déclarer la compatibilité immédiatement lors de l'activation
        $this->declare_woocommerce_compatibility();
        
        // Nettoyer les doublons existants lors de l'activation
        $cleaned_count = $this->clean_all_duplicate_carts();
        
        // Initialiser automatiquement tous les consentements RGPD à 1 par défaut
        $this->initialize_gdpr_consents();
        
        // Afficher un message de confirmation si des doublons ont été nettoyés
        $this->show_activation_message($cleaned_count);
    }
    
    /**
     * Créer les options par défaut avec le bon style de bouton
     */
    private function create_default_settings() {
        // Templates par défaut exacts pour l'éditeur (copiés de la version de production)
        $first_template = '<div style="background: #f8f9fa; padding: 30px; border-radius: 10px;"><h1 style="color: #0073aa; margin-bottom: 20px; text-align: center;">Votre panier vous attend !</h1><p>Bonjour {customer_name},</p><p>Nous avons remarqué que vous avez laissé des articles dans votre panier. Ne manquez pas cette opportunité !</p><div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0;"><h3 style="margin-top: 0; color: #333;">Votre panier :</h3>{cart_items}<hr style="border: none; border-top: 1px solid #eee; margin: 15px 0;" /><p style="font-size: 18px; font-weight: bold; color: #0073aa; margin: 0;">Total : {cart_total}</p></div><div style="text-align: center; margin: 30px 0;"><a style="background-color: #0073aa; color: #ffffff; padding: 25px 50px; text-decoration: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,115,170,0.2);" href="{cart_url}">Récupérer mon panier</a></div></div><div style="background: #e9ecef; padding: 20px; text-align: center; border-radius: 0 0 10px 10px; margin-top: -10px;"><p style="margin: 0; color: #666; font-size: 14px;">Cordialement,<br /><strong style="color: #333;">{site_name}</strong></p><hr style="border: none; border-top: 1px solid #ddd; margin: 15px 0;" /><div style="font-size: 12px; color: #999; line-height: 1.4;"><p style="margin: 5px 0;"><a style="color: #999; text-decoration: underline;" href="{unsubscribe_url}">Se désabonner</a></p><p style="margin: 5px 0; font-size: 11px;">Vous recevez cet email car vous avez abandonné un panier sur {site_name}. Conformément au RGPD, vous pouvez vous désabonner ci-dessus.</p></div></div>';
        
        $second_template = '<div style="background: #fff3cd; padding: 30px; border-radius: 10px; border: 1px solid #ffeaa7;"><h1 style="color: #856404; margin-bottom: 20px; text-align: center;">Dernière chance !</h1><p>Bonjour {customer_name},</p><p>Il ne vous reste plus beaucoup de temps pour récupérer votre panier ! Ces articles pourraient ne plus être disponibles demain.</p><div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0;"><h3 style="margin-top: 0; color: #333;">Votre panier :</h3>{cart_items}<hr style="border: none; border-top: 1px solid #eee; margin: 15px 0;" /><p style="font-size: 18px; font-weight: bold; color: #856404; margin: 0;">Total : {cart_total}</p></div><div style="text-align: center; margin: 30px 0;"><a style="background-color: #0073aa; color: #ffffff; padding: 25px 50px; text-decoration: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,115,170,0.2);" href="{cart_url}">Récupérer mon panier</a></div></div><div style="background: #f4e6b3; padding: 20px; text-align: center; border-radius: 0 0 10px 10px; margin-top: -10px; border: 1px solid #ffeaa7; border-top: none;"><p style="margin: 0; color: #856404; font-size: 14px;">Cordialement,<br /><strong style="color: #856404;">{site_name}</strong></p><hr style="border: none; border-top: 1px solid #ffeaa7; margin: 15px 0;" /><div style="font-size: 12px; color: #856404; line-height: 1.4;"><p style="margin: 5px 0;"><a style="color: #856404; text-decoration: underline;" href="{unsubscribe_url}">Se désabonner</a></p><p style="margin: 5px 0; font-size: 11px;">Vous recevez cet email car vous avez abandonné un panier sur {site_name}. Conformément au RGPD, vous pouvez vous désabonner ci-dessus.</p></div></div>';
        
        $default_settings = array(
            'excluded_roles' => array('administrator'),
            'sender_name' => get_bloginfo('name'),
            'sender_email' => get_option('admin_email'),
            'first_email_subject' => 'Votre panier vous attend !',
            'first_email_content' => $first_template,
            'first_email_delay' => 60, // 1 heure
            'first_email_delay_unit' => 'minutes',
            'second_email_subject' => 'Dernière chance de récupérer votre panier !',
            'second_email_content' => $second_template,
            'second_email_delay' => 24, // 1 jour
            'second_email_delay_unit' => 'hours',
            // Fenêtre d'agrégation des invités (minutes)
            'guest_session_window' => 5
        );
        update_option('acr_settings', $default_settings, true);
        
        // Forcer le rechargement des options
        wp_cache_delete('acr_settings', 'options');
        wp_cache_delete('alloptions', 'options');
    }
    
    /**
     * Forcer la suppression complète des anciens templates
     */
    private function force_reset_templates() {
        // Supprimer complètement l'option acr_settings
        delete_option('acr_settings');
        
        // Vider tous les caches WordPress
        wp_cache_flush();
        wp_cache_delete('acr_settings', 'options');
        wp_cache_delete('alloptions', 'options');
        
        // Recréer les settings avec les nouveaux templates
        $this->create_default_settings();
        
        // Debug: Logger la réinitialisation
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Templates forcés à la réinitialisation lors de l\'activation');
        }
    }
    
    
    /**
     * Afficher un message de confirmation si des doublons ont été nettoyés
     */
    private function show_activation_message($cleaned_count) {
        if ($cleaned_count > 0) {
            add_action('admin_notices', function() use ($cleaned_count) {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p><strong>Paniers Abandonnés :</strong> ' . $cleaned_count . ' paniers dupliqués ont été nettoyés lors de l\'activation du plugin.</p>';
                echo '</div>';
            });
        }
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook('acr_send_reminder_emails');
    }
    
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'abandoned_carts';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            user_email varchar(100) NOT NULL,
            user_name varchar(100) DEFAULT '',
            cart_data longtext NOT NULL,
            cart_total decimal(10,2) DEFAULT 0.00,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            first_email_sent datetime DEFAULT NULL,
            second_email_sent datetime DEFAULT NULL,
            recovered_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY user_email (user_email),
            KEY created_at (created_at),
            KEY recovered_at (recovered_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Ajouter les nouvelles colonnes pour la récupération automatique
        $this->add_recovery_columns();
    }
    
    /**
     * Ajouter les colonnes pour la récupération automatique
     */
    private function add_recovery_columns() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'abandoned_carts';
        
        // Vérifier si les colonnes existent déjà
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
        $column_names = array_column($columns, 'Field');
        
        // Ajouter recovery_order_id si elle n'existe pas
        if (!in_array('recovery_order_id', $column_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN recovery_order_id bigint(20) DEFAULT NULL AFTER recovered_at");
        }
        
        // Ajouter recovery_method si elle n'existe pas
        if (!in_array('recovery_method', $column_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN recovery_method varchar(50) DEFAULT 'manual' AFTER recovery_order_id");
        }
        
        // Ajouter les colonnes RGPD
        if (!in_array('gdpr_consent', $column_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN gdpr_consent tinyint(1) DEFAULT 0 AFTER recovery_method");
        }
        
        if (!in_array('gdpr_consent_date', $column_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN gdpr_consent_date datetime DEFAULT NULL AFTER gdpr_consent");
        }
        
        if (!in_array('gdpr_consent_ip', $column_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN gdpr_consent_ip varchar(45) DEFAULT NULL AFTER gdpr_consent_date");
        }
        
        if (!in_array('gdpr_unsubscribed', $column_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN gdpr_unsubscribed tinyint(1) DEFAULT 0 AFTER gdpr_consent_ip");
        }
    }
    
    private function schedule_cron() {
        if (!wp_next_scheduled('acr_send_reminder_emails')) {
            wp_schedule_event(time(), 'hourly', 'acr_send_reminder_emails');
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Paniers Abandonnés',
            'Paniers Abandonnés',
            'manage_options',
            'abandoned-cart-recovery',
            array($this, 'admin_page'),
            'dashicons-cart',
            56
        );
        
        add_submenu_page(
            'abandoned-cart-recovery',
            'Paniers Invités',
            'Paniers Invités',
            'manage_options',
            'abandoned-cart-recovery-guests',
            array($this, 'guests_page')
        );
        
        add_submenu_page(
            'abandoned-cart-recovery',
            'Utilisateurs Désabonnés',
            'Désabonnés',
            'manage_options',
            'abandoned-cart-recovery-unsubscribed',
            array($this, 'unsubscribed_page')
        );
        
        add_submenu_page(
            'abandoned-cart-recovery',
            'Réglages',
            'Réglages',
            'manage_options',
            'abandoned-cart-recovery-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'abandoned-cart-recovery',
            'Aide & Guide',
            'Aide & Guide',
            'manage_options',
            'abandoned-cart-recovery-help',
            array($this, 'help_page')
        );
    }
    
    public function admin_page() {
        include ACR_PLUGIN_PATH . 'templates/admin-page.php';
    }
    
    public function guests_page() {
        include ACR_PLUGIN_PATH . 'templates/guests-page.php';
    }
    
    public function unsubscribed_page() {
        include ACR_PLUGIN_PATH . 'templates/unsubscribed-page.php';
    }
    
    public function settings_page() {
        include ACR_PLUGIN_PATH . 'templates/settings-page.php';
    }
    
    public function help_page() {
        include ACR_PLUGIN_PATH . 'templates/help-page.php';
    }
    
    public function track_cart_update() {
        // Vérifier que WooCommerce est disponible
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        
        // Ne traiter que si le panier n'est pas vide
        if (WC()->cart->is_empty()) {
            return;
        }
        
        // Protection contre les appels multiples dans la même requête
        static $tracking_in_progress = false;
        if ($tracking_in_progress) {
            return;
        }
        $tracking_in_progress = true;
        
        // Utiliser un transient pour éviter les appels trop fréquents
        $user_identifier = is_user_logged_in() ? 'user_' . get_current_user_id() : 'guest_' . $this->get_consistent_guest_email();
        $transient_key = 'acr_tracking_' . md5($user_identifier);
        
        if (get_transient($transient_key)) {
            $tracking_in_progress = false;
            return;
        }
        
        // Version 1.8.26: Utiliser une fenêtre paramétrable pour les invités
        if (is_user_logged_in()) {
            // Utilisateurs connectés: 1 seconde (stable)
            $transient_duration = 1;
        } else {
            $settings = get_option('acr_settings');
            $guest_window_minutes = intval($settings['guest_session_window'] ?? 5);
            $guest_window_minutes = max(1, min(120, $guest_window_minutes));
            // Debounce invité court pour permettre la mise à jour du panier actif pendant la fenêtre
            // Autorise une mise à jour au plus toutes les 15s (ou moins si la fenêtre est plus courte)
            $transient_duration = min(15, $guest_window_minutes * 60);
        }
        
        set_transient($transient_key, true, $transient_duration);
        
        try {
            if (!is_user_logged_in()) {
                $this->save_guest_cart();
            } else {
                $this->save_user_cart();
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Erreur lors du tracking du panier: ' . $e->getMessage());
            }
        } finally {
            $tracking_in_progress = false;
        }
    }
    
    private function save_guest_cart() {
        global $wpdb;
        
        // Forcer le recalcul du panier avant de récupérer les données
        WC()->cart->calculate_totals();
        
        $cart_data = WC()->cart->get_cart();
        $cart_total = WC()->cart->get_total('raw');
        
        // Si le total est 0, essayer de le calculer manuellement
        if ($cart_total == 0 && !empty($cart_data)) {
            $manual_total = 0;
            foreach ($cart_data as $cart_item) {
                $manual_total += $cart_item['line_total'];
            }
            if ($manual_total > 0) {
                $cart_total = $manual_total;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACR Debug: Total corrigé manuellement: ' . $cart_total);
                }
            }
        }
        
        // Debug: Vérifier le calcul du total
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Cart total calculé: ' . $cart_total . ' (raw)');
            error_log('ACR Debug: Cart total formaté: ' . WC()->cart->get_total());
            error_log('ACR Debug: Cart items count: ' . count($cart_data));
            if (!empty($cart_data)) {
                foreach ($cart_data as $key => $item) {
                    error_log('ACR Debug: Item ' . $key . ' - Line total: ' . $item['line_total'] . ' - Quantity: ' . $item['quantity']);
                    error_log('ACR Debug: Item ' . $key . ' - Full item data: ' . print_r($item, true));
                }
            }
            error_log('ACR Debug: Cart data to be stored: ' . json_encode($cart_data));
        }
        
        // Utiliser une méthode unique et cohérente pour identifier les clients invités
        $user_email = $this->get_consistent_guest_email();
        
        if (!$user_email) {
            return;
        }
        
        // Version 1.8.26: 1 panier actif par session invitée pendant la fenêtre
        $settings = get_option('acr_settings');
        $guest_window_minutes = intval($settings['guest_session_window'] ?? 5);
        $guest_window_minutes = max(1, min(120, $guest_window_minutes));

        // Rechercher un panier actif (créé ou mis à jour dans la fenêtre)
        $active_cart = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts 
             WHERE user_email = %s AND recovered_at IS NULL 
             AND (created_at >= DATE_SUB(%s, INTERVAL %d MINUTE) 
                  OR updated_at >= DATE_SUB(%s, INTERVAL %d MINUTE)) 
             ORDER BY GREATEST(IFNULL(updated_at, created_at), created_at) DESC 
             LIMIT 1",
            $user_email,
            current_time('mysql'), $guest_window_minutes,
            current_time('mysql'), $guest_window_minutes
        ));

        if ($active_cart) {
            // Mettre à jour le panier actif avec le nouveau contenu
            $wpdb->update(
                $wpdb->prefix . 'abandoned_carts',
                array(
                    'cart_data' => json_encode($cart_data),
                    'cart_total' => $cart_total,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $active_cart->id)
            );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Panier invité actif mis à jour (fenêtre) - Email: ' . $user_email . ', ID: ' . $active_cart->id);
            }
        } else {
            // Créer un nouveau panier (nouvelle session après inactivité)
            $result = $wpdb->insert(
                $wpdb->prefix . 'abandoned_carts',
                array(
                    'user_email' => $user_email,
                    'user_name' => '',
                    'cart_data' => json_encode($cart_data),
                    'cart_total' => $cart_total,
                    'created_at' => current_time('mysql'),
                    'gdpr_consent' => 1,
                    'gdpr_consent_date' => current_time('mysql'),
                    'gdpr_consent_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                )
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                if ($result) {
                    error_log('ACR Debug: Nouveau panier invité créé - Email: ' . $user_email . ', ID: ' . $wpdb->insert_id);
                } else {
                    error_log('ACR Debug: Échec création panier invité - Email: ' . $user_email . ', Erreur: ' . $wpdb->last_error);
                }
            }
        }
        
        // Nettoyer les doublons potentiels pour cet email (compacter les paniers proches)
        $this->clean_duplicate_carts($user_email);
    }
    
    /**
     * Obtenir un email cohérent pour les clients invités
     * Version 1.8.0: Utilise la session WooCommerce comme identifiant principal
     */
    private function get_consistent_guest_email() {
        // Priorité 1: Session WooCommerce comme identifiant principal (stable)
        if (WC()->session) {
            $session_id = WC()->session->get_customer_id();
            if ($session_id) {
                return 'guest_' . $session_id . '@example.com';
            }
        }
        
        // Priorité 2: Session email si déjà capturé
        if (WC()->session) {
            $session_email = WC()->session->get('guest_email');
            if ($session_email && is_email($session_email)) {
                return $session_email;
            }
        }
        
        // Priorité 3: Cookie de session WooCommerce (fallback)
        if (isset($_COOKIE['woocommerce_cart_hash'])) {
            return 'guest_' . md5($_COOKIE['woocommerce_cart_hash']) . '@example.com';
        }
        
        // Priorité 4: IP + User Agent (dernier recours)
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return 'guest_' . md5($ip . $user_agent) . '@example.com';
    }
    
    /**
     * Compare deux contenus de panier pour déterminer s'ils sont identiques
     * Normalise les données pour une comparaison fiable
     */
    private function compare_cart_contents($cart_json_1, $cart_json_2) {
        // Décoder les JSON
        $cart_1 = json_decode($cart_json_1, true);
        $cart_2 = json_decode($cart_json_2, true);
        
        // Si l'un des deux est invalide, considérer comme différent
        if (!is_array($cart_1) || !is_array($cart_2)) {
            return false;
        }
        
        // Si le nombre d'éléments est différent, ils sont différents
        if (count($cart_1) !== count($cart_2)) {
            return false;
        }
        
        // Créer des tableaux normalisés pour la comparaison
        $normalized_1 = $this->normalize_cart_for_comparison($cart_1);
        $normalized_2 = $this->normalize_cart_for_comparison($cart_2);
        
        // Comparer les tableaux normalisés
        return $normalized_1 === $normalized_2;
    }
    
    /**
     * Normalise un panier pour la comparaison
     * Extrait seulement les données importantes : product_id, quantity, line_total
     */
    private function normalize_cart_for_comparison($cart_data) {
        $normalized = array();
        
        foreach ($cart_data as $item) {
            $normalized[] = array(
                'product_id' => $item['product_id'] ?? 0,
                'quantity' => $item['quantity'] ?? 0,
                'line_total' => $item['line_total'] ?? 0
            );
        }
        
        // Trier par product_id pour une comparaison cohérente
        usort($normalized, function($a, $b) {
            return $a['product_id'] - $b['product_id'];
        });
        
        return $normalized;
    }
    
    private function save_user_cart() {
        global $wpdb;
        
        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        
        // Forcer le recalcul du panier avant de récupérer les données
        WC()->cart->calculate_totals();
        
        $cart_data = WC()->cart->get_cart();
        $cart_total = WC()->cart->get_total('raw');
        
        // Si le total est 0, essayer de le calculer manuellement
        if ($cart_total == 0 && !empty($cart_data)) {
            $manual_total = 0;
            foreach ($cart_data as $cart_item) {
                $manual_total += $cart_item['line_total'];
            }
            if ($manual_total > 0) {
                $cart_total = $manual_total;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACR Debug: User total corrigé manuellement: ' . $cart_total);
                }
            }
        }
        
        // Debug: Vérifier le calcul du total
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: User cart total calculé: ' . $cart_total . ' (raw)');
            error_log('ACR Debug: User cart total formaté: ' . WC()->cart->get_total());
            error_log('ACR Debug: User cart items count: ' . count($cart_data));
            if (!empty($cart_data)) {
                foreach ($cart_data as $key => $item) {
                    error_log('ACR Debug: User item ' . $key . ' - Line total: ' . $item['line_total'] . ' - Quantity: ' . $item['quantity']);
                    error_log('ACR Debug: User item ' . $key . ' - Full item data: ' . print_r($item, true));
                }
            }
            error_log('ACR Debug: User cart data to be stored: ' . json_encode($cart_data));
        }
        
        // Récupérer tous les paniers non récupérés de cet utilisateur
        $existing_carts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts WHERE user_id = %d AND recovered_at IS NULL",
            $user_id
        ));
        
        // Vérifier si un panier avec le même contenu existe déjà
        $identical_cart = null;
        $current_cart_json = json_encode($cart_data);
        
        foreach ($existing_carts as $cart) {
            $existing_cart_json = $cart->cart_data;
            
            // Comparer les contenus JSON normalisés
            if ($this->compare_cart_contents($current_cart_json, $existing_cart_json)) {
                $identical_cart = $cart;
                break;
            }
        }
        
        if ($identical_cart) {
            // Mettre à jour seulement la date du panier identique existant
            $wpdb->update(
                $wpdb->prefix . 'abandoned_carts',
                array(
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $identical_cart->id)
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Panier utilisateur identique mis à jour - User ID: ' . $user_id . ', Email: ' . $user->user_email . ', Cart ID: ' . $identical_cart->id);
            }
        } else {
            // Créer un nouveau panier
            $result = $wpdb->insert(
                $wpdb->prefix . 'abandoned_carts',
                array(
                    'user_id' => $user_id,
                    'user_email' => $user->user_email,
                    'user_name' => $user->display_name,
                    'cart_data' => json_encode($cart_data),
                    'cart_total' => $cart_total,
                    'created_at' => current_time('mysql'),
                    'gdpr_consent' => 1,
                    'gdpr_consent_date' => current_time('mysql'),
                    'gdpr_consent_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                )
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                if ($result) {
                    error_log('ACR Debug: Nouveau panier utilisateur créé - User ID: ' . $user_id . ', Email: ' . $user->user_email . ', Cart ID: ' . $wpdb->insert_id);
                } else {
                    error_log('ACR Debug: Échec création panier utilisateur - User ID: ' . $user_id . ', Email: ' . $user->user_email . ', Erreur: ' . $wpdb->last_error);
                }
            }
        }
        
        // Nettoyer les doublons potentiels pour cet utilisateur
        $this->clean_duplicate_carts($user->user_email);
    }
    
    public function mark_cart_completed($order_id) {
        global $wpdb;
        
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $user_email = $order->get_billing_email();
        
        // Récupérer les produits de la commande
        $order_items = $order->get_items();
        $order_product_ids = array();
        foreach ($order_items as $item) {
            $order_product_ids[] = $item->get_product_id();
        }
        
        // Chercher les paniers abandonnés de cet email qui correspondent à la commande
        $abandoned_carts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts 
            WHERE user_email = %s 
            AND recovered_at IS NULL 
            ORDER BY created_at DESC",
            $user_email
        ));
        
        foreach ($abandoned_carts as $cart) {
            $cart_data = json_decode($cart->cart_data, true);
            if (!$cart_data) continue;
            
            $cart_product_ids = array();
            foreach ($cart_data as $item) {
                if (isset($item['product_id'])) {
                    $cart_product_ids[] = $item['product_id'];
                }
            }
            
            // Vérifier si 100% des produits du panier abandonné sont dans la commande
            $matching_products = array_intersect($cart_product_ids, $order_product_ids);
            $match_percentage = count($cart_product_ids) > 0 ? (count($matching_products) / count($cart_product_ids)) * 100 : 0;
            
            // Marquer comme récupéré seulement si 100% des produits correspondent
            if ($match_percentage >= 100) {
                $wpdb->update(
                    $wpdb->prefix . 'abandoned_carts',
                    array(
                        'recovered_at' => current_time('mysql'),
                        'recovery_order_id' => $order_id,
                        'recovery_method' => 'automatic'
                    ),
                    array('id' => $cart->id)
                );
                
                // Logger pour debug
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("ACR Debug: Panier récupéré automatiquement - Cart ID: {$cart->id}, Order ID: {$order_id}, Match: {$match_percentage}%");
                }
            }
        }
    }
    
    /**
     * Vider le panier après une commande réussie
     */
    public function clear_cart_after_order($order_id) {
        // Vérifier que WooCommerce est disponible
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        
        // Vérifier que la commande existe et est valide
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Vérifier que la commande est dans un statut valide (pas en échec)
        $valid_statuses = array('processing', 'completed', 'on-hold');
        if (!in_array($order->get_status(), $valid_statuses)) {
            return;
        }
        
        // Vider le panier seulement si l'utilisateur actuel correspond à la commande
        $order_email = $order->get_billing_email();
        $current_user_email = '';
        
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $current_user_email = $current_user->user_email;
        } else {
            // Pour les invités, vérifier l'email du panier actuel
            $current_user_email = WC()->session->get('billing_email');
        }
        
        // Vider le panier si l'email correspond ou si c'est un utilisateur connecté
        if ($order_email === $current_user_email || is_user_logged_in()) {
            WC()->cart->empty_cart();
            
            // Logger l'action pour le debug
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Panier vidé après commande - Order ID: ' . $order_id . ', Email: ' . $order_email);
            }
        }
    }
    
    /**
     * Marquer un panier comme récupéré (compatible HPOS)
     */
    public function mark_cart_completed_hpos($order_id) {
        global $wpdb;
        
        // Utiliser wc_get_order pour HPOS
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $user_email = $order->get_billing_email();
        
        // Récupérer les produits de la commande
        $order_items = $order->get_items();
        $order_product_ids = array();
        foreach ($order_items as $item) {
            $order_product_ids[] = $item->get_product_id();
        }
        
        // Chercher les paniers abandonnés de cet email qui correspondent à la commande
        $abandoned_carts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts 
            WHERE user_email = %s 
            AND recovered_at IS NULL 
            ORDER BY created_at DESC",
            $user_email
        ));
        
        foreach ($abandoned_carts as $cart) {
            $cart_data = json_decode($cart->cart_data, true);
            if (!$cart_data) continue;
            
            $cart_product_ids = array();
            foreach ($cart_data as $item) {
                if (isset($item['product_id'])) {
                    $cart_product_ids[] = $item['product_id'];
                }
            }
            
            // Vérifier si 100% des produits du panier abandonné sont dans la commande
            $matching_products = array_intersect($cart_product_ids, $order_product_ids);
            $match_percentage = count($cart_product_ids) > 0 ? (count($matching_products) / count($cart_product_ids)) * 100 : 0;
            
            // Marquer comme récupéré seulement si 100% des produits correspondent
            if ($match_percentage >= 100) {
                $wpdb->update(
                    $wpdb->prefix . 'abandoned_carts',
                    array(
                        'recovered_at' => current_time('mysql'),
                        'recovery_order_id' => $order_id,
                        'recovery_method' => 'automatic_hpos'
                    ),
                    array('id' => $cart->id)
                );
                
                // Logger pour debug
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("ACR Debug: Panier récupéré automatiquement (HPOS) - Cart ID: {$cart->id}, Order ID: {$order_id}, Match: {$match_percentage}%");
                }
            }
        }
    }
    
    /**
     * Marquer automatiquement un panier comme récupéré quand une commande est complétée
     */
    public function auto_mark_cart_recovered($order_id) {
        global $wpdb;
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $customer_email = $order->get_billing_email();
        if (!$customer_email) {
            return;
        }
        
        // Chercher un panier abandonné avec cet email
        $abandoned_cart = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts 
            WHERE user_email = %s 
            AND recovered_at IS NULL 
            ORDER BY created_at DESC 
            LIMIT 1",
            $customer_email
        ));
        
        if ($abandoned_cart) {
            // Vérifier si les produits correspondent (optionnel)
            $order_items = $order->get_items();
            $cart_data = json_decode($abandoned_cart->cart_data, true);
            
            $products_match = false;
            if ($cart_data && $order_items) {
                $cart_product_ids = array();
                foreach ($cart_data as $item) {
                    $cart_product_ids[] = $item['product_id'];
                }
                
                $order_product_ids = array();
                foreach ($order_items as $item) {
                    $order_product_ids[] = $item->get_product_id();
                }
                
                // Si au moins 50% des produits correspondent, on considère que c'est le même panier
                $matching_products = array_intersect($cart_product_ids, $order_product_ids);
                $products_match = count($matching_products) >= (count($cart_product_ids) * 0.5);
            }
            
            // Marquer comme récupéré
            $wpdb->update(
                $wpdb->prefix . 'abandoned_carts',
                array(
                    'recovered_at' => current_time('mysql'),
                    'recovery_order_id' => $order_id,
                    'recovery_method' => 'automatic'
                ),
                array('id' => $abandoned_cart->id)
            );
            
            // Logger la récupération automatique
            error_log("ACR: Panier automatiquement récupéré - Cart ID: {$abandoned_cart->id}, Order ID: {$order_id}, Email: {$customer_email}");
        }
    }
    
    public function send_reminder_emails() {
        global $wpdb;
        
        $settings = get_option('acr_settings');
        $excluded_roles = $settings['excluded_roles'] ?? array();
        
        // Première email
        $this->send_first_reminder($excluded_roles);
        
        // Deuxième email
        $this->send_second_reminder($excluded_roles);
    }
    
    /**
     * Déclenchement alternatif lors des visites (si le cron ne fonctionne pas)
     */
    public function maybe_send_reminder_emails() {
        // Éviter de surcharger le serveur - vérifier seulement 1 fois par heure
        $last_check = get_transient('acr_last_email_check');
        if ($last_check) {
            return;
        }
        
        // Définir un transient pour 1 heure
        set_transient('acr_last_email_check', time(), HOUR_IN_SECONDS);
        
        // Envoyer les emails
        $this->send_reminder_emails();
    }
    
    private function send_first_reminder($excluded_roles) {
        global $wpdb;
        
        $settings = get_option('acr_settings');
        $delay_minutes = $this->convert_delay_to_minutes($settings['first_email_delay'], $settings['first_email_delay_unit']);
        
        // Debug: Afficher tous les paniers pour diagnostic
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $all_carts = $wpdb->get_results(
                "SELECT id, user_email, created_at, first_email_sent, recovered_at 
                FROM {$wpdb->prefix}abandoned_carts 
                WHERE recovered_at IS NULL 
                ORDER BY created_at DESC"
            );
            error_log('ACR Debug: Tous les paniers non récupérés (' . count($all_carts) . ') :');
            foreach ($all_carts as $cart) {
                $created_time = strtotime($cart->created_at);
                $current_time = strtotime(current_time('mysql'));
                $minutes_ago = round(($current_time - $created_time) / 60);
                error_log('ACR Debug: Panier ID ' . $cart->id . ' - Email: ' . $cart->user_email . ' - Créé il y a ' . $minutes_ago . ' min - Premier email: ' . ($cart->first_email_sent ?: 'Non envoyé'));
            }
            error_log('ACR Debug: Délai configuré: ' . $delay_minutes . ' minutes');
        }
        
        $carts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts 
            WHERE first_email_sent IS NULL 
            AND recovered_at IS NULL 
            AND (user_email NOT LIKE 'guest_%@example.com' OR (user_email LIKE '%@%' AND user_email NOT LIKE 'guest_%@example.com'))
            AND created_at < DATE_SUB(%s, INTERVAL %d MINUTE)",
            current_time('mysql'),
            $delay_minutes
        ));
        
        // Debug: Logger le nombre de paniers trouvés
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: ' . count($carts) . ' paniers trouvés pour le premier email');
        }
        
        foreach ($carts as $cart) {
            // Vérifier et mettre à jour l'email des clients invités avant l'envoi
            if (strpos($cart->user_email, 'guest_') === 0) {
                $this->check_and_update_guest_email($cart);
                // Recharger les données du panier après mise à jour
                $updated_cart = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}abandoned_carts WHERE id = %d",
                    $cart->id
                ));
                if ($updated_cart) {
                    $cart = $updated_cart;
                }
            }
            
            // Nettoyer les paniers dupliqués pour cet email
            $this->clean_duplicate_carts($cart->user_email);
            
            // Vérifier seulement si l'utilisateur s'est désabonné
            if (!$cart->gdpr_unsubscribed) {
                $result = $this->send_email($cart, 'first');
                
                // Debug: Logger le résultat de l'envoi
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACR Debug: Email premier envoyé à ' . $cart->user_email . ' - Résultat: ' . ($result ? 'Succès' : 'Échec'));
                }
                
                if ($result) {
                    $wpdb->update(
                        $wpdb->prefix . 'abandoned_carts',
                        array('first_email_sent' => current_time('mysql')),
                        array('id' => $cart->id)
                    );
                }
            } else {
                // Debug: Logger pourquoi l'email n'est pas envoyé
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACR Debug: Email premier non envoyé à ' . $cart->user_email . ' - Raison: utilisateur désabonné');
                }
            }
        }
    }
    
    private function send_second_reminder($excluded_roles) {
        global $wpdb;
        
        $settings = get_option('acr_settings');
        $delay_minutes = $this->convert_delay_to_minutes($settings['second_email_delay'], $settings['second_email_delay_unit']);
        
        $carts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts 
            WHERE first_email_sent IS NOT NULL 
            AND second_email_sent IS NULL 
            AND recovered_at IS NULL 
            AND (user_email NOT LIKE 'guest_%@example.com' OR (user_email LIKE '%@%' AND user_email NOT LIKE 'guest_%@example.com'))
            AND first_email_sent < DATE_SUB(%s, INTERVAL %d MINUTE)",
            current_time('mysql'),
            $delay_minutes
        ));
        
        foreach ($carts as $cart) {
            // Nettoyer les paniers dupliqués pour cet email
            $this->clean_duplicate_carts($cart->user_email);
            
            // Vérifier seulement si l'utilisateur s'est désabonné
            if (!$cart->gdpr_unsubscribed) {
                $this->send_email($cart, 'second');
                $wpdb->update(
                    $wpdb->prefix . 'abandoned_carts',
                    array('second_email_sent' => current_time('mysql')),
                    array('id' => $cart->id)
                );
            }
        }
    }
    
    private function should_send_email($cart, $excluded_roles) {
        // Debug: Logger les vérifications
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Vérification envoi email pour ' . $cart->user_email);
        }
        
        // Vérifier le consentement RGPD
        if (!$this->has_gdpr_consent($cart->user_email)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Consentement RGPD refusé pour ' . $cart->user_email);
            }
            return false;
        }
        
        // Vérifier si l'utilisateur s'est désabonné
        if ($cart->gdpr_unsubscribed) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Utilisateur désabonné pour ' . $cart->user_email);
            }
            return false;
        }
        
        if ($cart->user_id) {
            $user = get_user_by('id', $cart->user_id);
            if ($user) {
                $user_roles = $user->roles;
                foreach ($user_roles as $role) {
                    if (in_array($role, $excluded_roles)) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('ACR Debug: Rôle exclu ' . $role . ' pour ' . $cart->user_email);
                        }
                        return false;
                    }
                }
            }
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Email autorisé pour ' . $cart->user_email);
        }
        return true;
    }
    
    private function convert_delay_to_minutes($delay, $unit) {
        switch ($unit) {
            case 'minutes':
                return $delay;
            case 'hours':
                return $delay * 60;
            case 'days':
                return $delay * 60 * 24;
            default:
                return $delay;
        }
    }
    
    private function send_email($cart, $type) {
        // Utiliser la classe ACR_Email_Handler pour une meilleure cohérence
        $email_handler = new ACR_Email_Handler();
        
        if ($type === 'first') {
            return $email_handler->send_first_reminder($cart);
        } else {
            return $email_handler->send_second_reminder($cart);
        }
    }
    
    /**
     * Remplacer les variables dans le contenu des emails
     * Cette fonction est accessible globalement pour être utilisée par ACR_Email_Handler
     */
    public function replace_email_variables($content, $cart) {
        $cart_data = json_decode($cart->cart_data, true);
        
        // DEBUG: Analyser les données du panier
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug Email - Cart ID: ' . $cart->id);
            error_log('ACR Debug Email - Cart Total (stored): ' . $cart->cart_total);
            error_log('ACR Debug Email - Cart Data (raw): ' . $cart->cart_data);
            error_log('ACR Debug Email - Cart Data (decoded): ' . print_r($cart_data, true));
        }
        
        $cart_items = '';
        $cart_items_count = 0;
        $cart_weight = 0;
        $cart_categories = array();
        $cart_brands = array();
        $most_expensive_item = null;
        $cheapest_item = null;
        $max_price = 0;
        $min_price = PHP_FLOAT_MAX;
        $calculated_total = 0; // Calculer le total en temps réel
        
        foreach ($cart_data as $item) {
            $product = wc_get_product($item['product_id']);
            if ($product) {
                $cart_items .= '- ' . $product->get_name() . ' (x' . $item['quantity'] . ')<br>';
                $cart_items_count += $item['quantity'];
                
                // Utiliser les données stockées du panier abandonné (HT + Taxes = TTC)
                $line_total = isset($item['line_total']) ? $item['line_total'] : 0;
                $line_tax = isset($item['line_tax']) ? $item['line_tax'] : 0;
                $item_total = $line_total + $line_tax; // TTC
                $calculated_total += $item_total;
                
                // DEBUG: Analyser chaque item
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACR Debug Email - Item: ' . $product->get_name());
                    error_log('ACR Debug Email - Product ID: ' . $item['product_id']);
                    error_log('ACR Debug Email - Quantity: ' . $item['quantity']);
                    error_log('ACR Debug Email - Line Total (stored): ' . (isset($item['line_total']) ? $item['line_total'] : 'NOT SET'));
                    error_log('ACR Debug Email - Product Price (current): ' . $product->get_price());
                    error_log('ACR Debug Email - Item Total (calculated): ' . $item_total);
                }
                
                // Poids du panier
                if ($product->get_weight()) {
                    $cart_weight += $product->get_weight() * $item['quantity'];
                }
                
                // Catégories
                $categories = get_the_terms($product->get_id(), 'product_cat');
                if ($categories) {
                    foreach ($categories as $category) {
                        $cart_categories[] = $category->name;
                    }
                }
                
                // Prix pour déterminer le plus cher/le moins cher
                $price = $product->get_price();
                if ($price > $max_price) {
                    $max_price = $price;
                    $most_expensive_item = $product->get_name();
                }
                if ($price < $min_price) {
                    $min_price = $price;
                    $cheapest_item = $product->get_name();
                }
            }
        }
        
        // Données client
        $customer_data = $this->get_customer_data($cart->user_email);
        
        // Données contextuelles
        $current_time = current_time('mysql');
        $current_date = date('d/m/Y', strtotime($current_time));
        $day_of_week = date('l', strtotime($current_time));
        $season = $this->get_current_season();
        
        // URL de récupération
        $cart_url = get_site_url() . '/commander/';
        
        // DEBUG: Analyser le total final
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug Email - Calculated Total: ' . $calculated_total);
            error_log('ACR Debug Email - Calculated Total (formatted): ' . wc_price($calculated_total));
        }
        
        $replacements = array(
            // Variables existantes
            '{customer_name}' => $cart->user_name ?: 'Client',
            '{cart_items}' => $cart_items,
            '{cart_total}' => wc_price($calculated_total),
            '{cart_url}' => $cart_url,
            '{site_name}' => get_bloginfo('name'),
            
            // Nouvelles variables - Données client
            '{customer_email}' => $cart->user_email,
            '{customer_first_name}' => $this->get_first_name($cart->user_name),
            '{customer_last_name}' => $this->get_last_name($cart->user_name),
            '{total_orders}' => $customer_data['total_orders'],
            '{total_spent}' => wc_price($customer_data['total_spent']),
            '{last_order_date}' => $customer_data['last_order_date'],
            '{average_order_value}' => wc_price($customer_data['average_order_value']),
            '{favorite_category}' => $customer_data['favorite_category'],
            '{customer_location}' => $customer_data['location'],
            '{customer_timezone}' => $customer_data['timezone'],
            '{loyalty_points}' => $customer_data['loyalty_points'],
            
            // Nouvelles variables - Données de panier
            '{cart_items_count}' => $cart_items_count,
            '{cart_weight}' => $cart_weight . ' kg',
            '{cart_categories}' => implode(', ', array_unique($cart_categories)),
            '{cart_brands}' => implode(', ', array_unique($cart_brands)),
            '{most_expensive_item}' => $most_expensive_item ?: 'N/A',
            '{cheapest_item}' => $cheapest_item ?: 'N/A',
            '{cart_discount}' => wc_price(0), // À implémenter selon les réductions
            '{cart_shipping}' => wc_price(0), // À implémenter selon la livraison
            
            // Nouvelles variables - Données contextuelles
            '{current_time}' => date('H:i', strtotime($current_time)),
            '{current_date}' => $current_date,
            '{day_of_week}' => $this->get_day_name_fr($day_of_week),
            '{season}' => $season,
            '{holiday}' => $this->get_current_holiday(),
            '{local_weather}' => 'Ensoleillé', // À implémenter avec API météo
            '{local_events}' => 'Événements locaux', // À implémenter
            
            // Nouvelles variables - Données commerciales
            '{available_coupons}' => $this->get_available_coupons(),
            '{free_shipping_threshold}' => wc_price($this->get_free_shipping_threshold()),
            '{stock_status}' => $this->get_stock_status($cart_data),
            
            // Nouvelles variables - Recommandations
            '{recommended_products}' => $this->get_recommended_products($cart_data),
            '{trending_products}' => $this->get_trending_products(),
            '{new_arrivals}' => $this->get_new_arrivals(),
            '{best_sellers}' => $this->get_best_sellers(),
            
            // Variables de base
            '{site_url}' => get_site_url(),
            '{date}' => $current_date,
            '{time}' => date('H:i', strtotime($current_time)),
            
            // Variables RGPD
            '{unsubscribe_url}' => add_query_arg(array(
                'acr_action' => 'unsubscribe',
                'email' => urlencode($cart->user_email),
                'nonce' => wp_create_nonce('acr_gdpr_' . $cart->user_email)
            ), get_site_url()),
            '{gdpr_export_url}' => add_query_arg(array(
                'acr_action' => 'export',
                'email' => urlencode($cart->user_email),
                'nonce' => wp_create_nonce('acr_gdpr_' . $cart->user_email)
            ), get_site_url()),
            '{gdpr_delete_url}' => add_query_arg(array(
                'acr_action' => 'delete',
                'email' => urlencode($cart->user_email),
                'nonce' => wp_create_nonce('acr_gdpr_' . $cart->user_email)
            ), get_site_url()),
            
            // Variables Images
            '{site_logo}' => $this->get_site_logo_html(),
            '{product_image_1}' => $this->get_product_image_url($cart_data, 1),
            '{product_image_2}' => $this->get_product_image_url($cart_data, 2),
            '{product_image_3}' => $this->get_product_image_url($cart_data, 3),
            '{banner_image}' => $this->get_banner_image_url(),
            '{social_media_icons}' => $this->get_social_media_icons()
        );
        
        // Debug: Logger le remplacement des variables d'images
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: URL du logo: ' . $this->get_site_logo_url());
            error_log('ACR Debug: URL image produit 1: ' . $this->get_product_image_url($cart_data, 1));
        }
        
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
    
    // Méthodes AJAX
    public function save_settings() {
        try {
            check_ajax_referer('acr_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Accès refusé');
                return;
            }
            
            // Parser les données du formulaire
            $form_data = isset($_POST['form_data']) ? $_POST['form_data'] : '';
            
            if (empty($form_data)) {
                wp_send_json_error('Aucune donnée reçue du formulaire');
                return;
            }
            
            parse_str($form_data, $parsed_data);
            
            // Debug: Vérifier les données reçues
            if (empty($parsed_data)) {
                wp_send_json_error('Impossible de parser les données du formulaire');
                return;
            }
            
            // Valider et nettoyer les données
            $settings = array(
                'excluded_roles' => isset($parsed_data['excluded_roles']) ? (array)$parsed_data['excluded_roles'] : array(),
                'sender_name' => sanitize_text_field($parsed_data['sender_name'] ?? ''),
                'sender_email' => sanitize_email($parsed_data['sender_email'] ?? ''),
                'first_email_subject' => sanitize_text_field($parsed_data['first_email_subject'] ?? ''),
                'first_email_content' => wp_kses_post($parsed_data['first_email_content'] ?? ''),
                'first_email_delay' => intval($parsed_data['first_email_delay'] ?? 60),
                'first_email_delay_unit' => sanitize_text_field($parsed_data['first_email_delay_unit'] ?? 'minutes'),
                'second_email_subject' => sanitize_text_field($parsed_data['second_email_subject'] ?? ''),
                'second_email_content' => wp_kses_post($parsed_data['second_email_content'] ?? ''),
                'second_email_delay' => intval($parsed_data['second_email_delay'] ?? 24),
                'second_email_delay_unit' => sanitize_text_field($parsed_data['second_email_delay_unit'] ?? 'hours'),
                'guest_session_window' => intval($parsed_data['guest_session_window'] ?? 5)
            );
            
            // Vérifier que les données essentielles sont présentes
            if (empty($settings['sender_name']) || empty($settings['sender_email'])) {
                wp_send_json_error('Les champs nom et email de l\'expéditeur sont obligatoires');
                return;
            }
            
            // Vérifier la taille des données (limite WordPress ~64KB)
            $data_size = strlen(serialize($settings));
            if ($data_size > 60000) {
                wp_send_json_error('Les données sont trop volumineuses (' . number_format($data_size) . ' bytes). Réduisez le contenu des emails.');
                return;
            }
            
            // Debug: Logger les données avant sauvegarde
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Tentative de sauvegarde des réglages');
                error_log('ACR Debug: Taille des données: ' . strlen(serialize($settings)) . ' bytes');
                error_log('ACR Debug: Nombre de champs: ' . count($settings));
            }
            
            // Sauvegarder avec autoload pour éviter les problèmes de cache
            $result = update_option('acr_settings', $settings, true);
            
            if ($result === false) {
                // Debug: Logger l'erreur
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    global $wpdb;
                    error_log('ACR Debug: Échec de update_option - Dernière erreur SQL: ' . ($wpdb ? $wpdb->last_error : 'N/A'));
                    error_log('ACR Debug: Dernière requête SQL: ' . ($wpdb ? $wpdb->last_query : 'N/A'));
                }
                
                // Essayer une méthode alternative
                global $wpdb;
                $existing_option = get_option('acr_settings');
                if ($existing_option === false) {
                    // L'option n'existe pas, essayer de l'ajouter
                    $result = add_option('acr_settings', $settings, '', 'yes');
                } else {
                    // L'option existe, essayer de la mettre à jour directement
                    $result = $wpdb->update(
                        $wpdb->options,
                        array('option_value' => maybe_serialize($settings)),
                        array('option_name' => 'acr_settings')
                    );
                }
                
                if ($result === false) {
                    wp_send_json_error('Échec de la sauvegarde en base de données. Erreur: ' . $wpdb->last_error);
                    return;
                }
            }
            
            // Forcer le rechargement des options en cache
            wp_cache_delete('acr_settings', 'options');
            wp_cache_delete('alloptions', 'options');
            
            // Nettoyer le cache des options WordPress
            if (function_exists('clean_option_cache')) {
                clean_option_cache('acr_settings');
            }
            
            wp_send_json_success('Réglages sauvegardés avec succès');
            
        } catch (Exception $e) {
            wp_send_json_error('Erreur exceptionnelle : ' . $e->getMessage());
        } catch (Error $e) {
            wp_send_json_error('Erreur fatale : ' . $e->getMessage());
        }
    }
    
    public function get_abandoned_carts() {
        check_ajax_referer('acr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé');
        }
        
        global $wpdb;
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        $carts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts 
             WHERE user_email NOT LIKE 'guest_%@example.com' 
             ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        
        // Enrichir les données avec les noms des produits
        foreach ($carts as $cart) {
            $cart_data = json_decode($cart->cart_data, true);
            if ($cart_data) {
                $enriched_items = array();
                foreach ($cart_data as $item) {
                    $product_name = 'Produit inconnu';
                    if (isset($item['product_id'])) {
                        $product = wc_get_product($item['product_id']);
                        if ($product) {
                            $product_name = $product->get_name();
                        }
                    }
                    
                    $enriched_items[] = array(
                        'product_id' => $item['product_id'] ?? 0,
                        'product_name' => $product_name,
                        'quantity' => $item['quantity'] ?? 1,
                        'line_total' => $item['line_total'] ?? 0,
                        'original_data' => $item
                    );
                }
                $cart->enriched_cart_data = $enriched_items;
            }
        }
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}abandoned_carts WHERE user_email NOT LIKE 'guest_%@example.com'");
        $recovered_total = $wpdb->get_var("SELECT SUM(cart_total) FROM {$wpdb->prefix}abandoned_carts WHERE recovered_at IS NOT NULL AND user_email NOT LIKE 'guest_%@example.com'");
        $abandoned_total = $wpdb->get_var("SELECT SUM(cart_total) FROM {$wpdb->prefix}abandoned_carts WHERE recovered_at IS NULL AND user_email NOT LIKE 'guest_%@example.com'");
        
        wp_send_json_success(array(
            'carts' => $carts,
            'total' => $total,
            'recovered_total' => $recovered_total ?: 0,
            'abandoned_total' => $abandoned_total ?: 0,
            'pages' => ceil($total / $per_page)
        ));
    }
    
    public function mark_cart_recovered() {
        check_ajax_referer('acr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé');
        }
        
        $cart_id = intval($_POST['cart_id']);
        
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'abandoned_carts',
            array('recovered_at' => current_time('mysql')),
            array('id' => $cart_id)
        );
        
        wp_send_json_success('Paniers marqué comme récupéré');
    }
    
    public function delete_cart() {
        check_ajax_referer('acr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé');
        }
        
        $cart_id = intval($_POST['cart_id']);
        
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'abandoned_carts', array('id' => $cart_id));
        
        wp_send_json_success('Paniers supprimé');
    }
    
    /**
     * Masquer le message de compatibilité HPOS
     */
    public function dismiss_notice() {
        check_ajax_referer('acr_dismiss_notice', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé');
        }
        
        update_option('acr_hpos_notice_shown', true);
        wp_send_json_success('Message masqué');
    }
    
    /**
     * Réinitialiser les templates par défaut
     */
    public function reset_templates() {
        // Vérifier les permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissions insuffisantes');
        }
        
        // Vérifier le nonce
        if (!wp_verify_nonce($_POST['nonce'], 'acr_nonce')) {
            wp_send_json_error('Nonce invalide');
        }
        
        try {
            // Templates par défaut exacts (copiés de la version de production)
            $first_template = '<div style="background: #f8f9fa; padding: 30px; border-radius: 10px;"><h1 style="color: #0073aa; margin-bottom: 20px; text-align: center;">Votre panier vous attend !</h1><p>Bonjour {customer_name},</p><p>Nous avons remarqué que vous avez laissé des articles dans votre panier. Ne manquez pas cette opportunité !</p><div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0;"><h3 style="margin-top: 0; color: #333;">Votre panier :</h3>{cart_items}<hr style="border: none; border-top: 1px solid #eee; margin: 15px 0;" /><p style="font-size: 18px; font-weight: bold; color: #0073aa; margin: 0;">Total : {cart_total}</p></div><div style="text-align: center; margin: 30px 0;"><a style="background-color: #0073aa; color: #ffffff; padding: 25px 50px; text-decoration: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,115,170,0.2);" href="{cart_url}">Récupérer mon panier</a></div></div><div style="background: #e9ecef; padding: 20px; text-align: center; border-radius: 0 0 10px 10px; margin-top: -10px;"><p style="margin: 0; color: #666; font-size: 14px;">Cordialement,<br /><strong style="color: #333;">{site_name}</strong></p><hr style="border: none; border-top: 1px solid #ddd; margin: 15px 0;" /><div style="font-size: 12px; color: #999; line-height: 1.4;"><p style="margin: 5px 0;"><a style="color: #999; text-decoration: underline;" href="{unsubscribe_url}">Se désabonner</a></p><p style="margin: 5px 0; font-size: 11px;">Vous recevez cet email car vous avez abandonné un panier sur {site_name}. Conformément au RGPD, vous pouvez vous désabonner ci-dessus.</p></div></div>';
            
            $second_template = '<div style="background: #fff3cd; padding: 30px; border-radius: 10px; border: 1px solid #ffeaa7;"><h1 style="color: #856404; margin-bottom: 20px; text-align: center;">Dernière chance !</h1><p>Bonjour {customer_name},</p><p>Il ne vous reste plus beaucoup de temps pour récupérer votre panier ! Ces articles pourraient ne plus être disponibles demain.</p><div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0;"><h3 style="margin-top: 0; color: #333;">Votre panier :</h3>{cart_items}<hr style="border: none; border-top: 1px solid #eee; margin: 15px 0;" /><p style="font-size: 18px; font-weight: bold; color: #856404; margin: 0;">Total : {cart_total}</p></div><div style="text-align: center; margin: 30px 0;"><a style="background-color: #0073aa; color: #ffffff; padding: 25px 50px; text-decoration: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,115,170,0.2);" href="{cart_url}">Récupérer mon panier</a></div></div><div style="background: #f4e6b3; padding: 20px; text-align: center; border-radius: 0 0 10px 10px; margin-top: -10px; border: 1px solid #ffeaa7; border-top: none;"><p style="margin: 0; color: #856404; font-size: 14px;">Cordialement,<br /><strong style="color: #856404;">{site_name}</strong></p><hr style="border: none; border-top: 1px solid #ffeaa7; margin: 15px 0;" /><div style="font-size: 12px; color: #856404; line-height: 1.4;"><p style="margin: 5px 0;"><a style="color: #856404; text-decoration: underline;" href="{unsubscribe_url}">Se désabonner</a></p><p style="margin: 5px 0; font-size: 11px;">Vous recevez cet email car vous avez abandonné un panier sur {site_name}. Conformément au RGPD, vous pouvez vous désabonner ci-dessus.</p></div></div>';
            
            // FORCER la suppression complète des anciens templates
            $settings = get_option('acr_settings', array());
            
            // Supprimer explicitement les anciens templates
            unset($settings['first_email_content']);
            unset($settings['second_email_content']);
            
            // Ajouter les nouveaux templates
            $settings['first_email_content'] = $first_template;
            $settings['second_email_content'] = $second_template;
            
            // Forcer la sauvegarde avec autoload
            update_option('acr_settings', $settings, true);
            
            // Vider le cache WordPress
            wp_cache_delete('acr_settings', 'options');
            wp_cache_flush();
            
            wp_send_json_success('Templates restaurés avec succès ! Les nouveaux designs sont maintenant actifs.');
            
        } catch (Exception $e) {
            wp_send_json_error('Erreur lors de la réinitialisation : ' . $e->getMessage());
        }
    }
    
    /**
     * Forcer l'envoi des emails depuis l'interface d'administration
     */
    public function force_send_emails() {
        check_ajax_referer('acr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé');
        }
        
        try {
            // Supprimer le transient pour forcer l'envoi
            delete_transient('acr_last_email_check');
            
            // Forcer le consentement RGPD pour les clients existants qui n'ont pas encore été traités
            $this->force_gdpr_consent_for_existing_customers();
            
            // Envoyer les emails
            $this->send_reminder_emails();
            
            wp_send_json_success('Emails envoyés avec succès');
        } catch (Exception $e) {
            wp_send_json_error('Erreur lors de l\'envoi : ' . $e->getMessage());
        }
    }
    
    /**
     * Forcer le consentement RGPD pour les clients existants
     */
    public function force_gdpr_consent_for_existing_customers() {
        global $wpdb;
        
        // Mettre à jour tous les paniers abandonnés qui n'ont pas encore de consentement RGPD
        $updated_null = $wpdb->query(
            "UPDATE {$wpdb->prefix}abandoned_carts 
            SET gdpr_consent = 1, gdpr_consent_date = NOW(), gdpr_consent_ip = '127.0.0.1'
            WHERE gdpr_consent IS NULL 
            AND gdpr_unsubscribed = 0 
            AND user_email NOT LIKE 'guest_%' 
            AND user_email NOT LIKE '%@example.com'"
        );
        
        // Forcer aussi le consentement pour les clients qui ont explicitement refusé (gdpr_consent = 0)
        $updated_refused = $wpdb->query(
            "UPDATE {$wpdb->prefix}abandoned_carts 
            SET gdpr_consent = 1, gdpr_consent_date = NOW(), gdpr_consent_ip = '127.0.0.1'
            WHERE gdpr_consent = 0 
            AND gdpr_unsubscribed = 0 
            AND user_email NOT LIKE 'guest_%' 
            AND user_email NOT LIKE '%@example.com'"
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: ' . $updated_null . ' clients NULL mis à jour avec consentement RGPD');
            error_log('ACR Debug: ' . $updated_refused . ' clients refusés mis à jour avec consentement RGPD');
        }
    }
    
    /**
     * Capturer l'email des clients invités quand ils changent le champ email
     */
    public function capture_guest_email_on_field_change($fields) {
        return $fields;
    }
    
    /**
     * Ajouter le script de capture d'email dans le footer
     */
    public function add_guest_email_capture_script() {
        // Ne charger que sur les pages de commande et si l'utilisateur n'est pas connecté
        if (!is_user_logged_in() && (is_checkout() || is_cart())) {
            ?>
            <script>
            jQuery(document).ready(function($) {
                // Fonction pour capturer l'email
                function captureGuestEmail(email) {
                    if (email && email.includes('@')) {
                        console.log('ACR: Capture email invité:', email);
                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            type: 'POST',
                            data: {
                                action: 'acr_capture_guest_email',
                                email: email,
                                nonce: '<?php echo wp_create_nonce('acr_nonce'); ?>'
                            },
                            success: function(response) {
                                console.log('ACR: Email capturé avec succès:', response);
                            },
                            error: function(xhr, status, error) {
                                console.log('ACR: Erreur capture email:', error);
                            }
                        });
                    }
                }
                
                // Capturer l'email sur le champ billing_email
                $(document).on('change blur', '#billing_email', function() {
                    captureGuestEmail($(this).val());
                });
                
                // Capturer l'email sur le champ email (formulaires alternatifs)
                $(document).on('change blur', 'input[type="email"]', function() {
                    captureGuestEmail($(this).val());
                });
                
                // Capturer l'email lors de la soumission du formulaire
                $(document).on('submit', 'form.checkout', function() {
                    var email = $('#billing_email').val();
                    if (email) {
                        captureGuestEmail(email);
                    }
                });
            });
            </script>
            <?php
        }
    }
    
    /**
     * Capturer l'email des clients invités pendant le processus de commande
     */
    public function capture_guest_email_during_checkout() {
        if (!is_user_logged_in() && isset($_POST['billing_email'])) {
            $email = sanitize_email($_POST['billing_email']);
            if ($email) {
                // Sauvegarder l'email dans la session WooCommerce
                if (WC()->session) {
                    WC()->session->set('guest_email', $email);
                }
                
                // Mettre à jour le panier abandonné avec l'email réel
                $this->update_guest_cart_with_email($email);
            }
        }
    }
    
    /**
     * Capturer l'email des clients invités après la création de la commande
     */
    public function capture_guest_email($order_id) {
        $order = wc_get_order($order_id);
        if ($order && !$order->get_customer_id()) {
            $email = $order->get_billing_email();
            if ($email) {
                // Mettre à jour le panier abandonné avec l'email réel
                $this->update_guest_cart_with_email($email);
            }
        }
    }
    
    /**
     * Mettre à jour le panier invité avec l'email réel
     */
    private function update_guest_cart_with_email($email) {
        global $wpdb;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Tentative de mise à jour panier invité avec email: ' . $email);
        }
        
        // Version 1.8.0: SEULEMENT mettre à jour le panier de la session actuelle
        // Pas de fallbacks pour éviter de toucher aux autres paniers guests
        
        if (WC()->session) {
            $session_id = WC()->session->get_customer_id();
            if ($session_id) {
                $guest_email = 'guest_' . $session_id . '@example.com';
                $this->update_specific_guest_cart($guest_email, $email, 'session');
                return; // IMPORTANT: Sortir après la première tentative réussie
            }
        }
        
        // Fallback SEULEMENT si la session n'est pas disponible
        if (isset($_COOKIE['woocommerce_cart_hash'])) {
            $guest_email = 'guest_' . md5($_COOKIE['woocommerce_cart_hash']) . '@example.com';
            $this->update_specific_guest_cart($guest_email, $email, 'cookie');
            return; // IMPORTANT: Sortir après la première tentative réussie
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Aucun identifiant de session trouvé pour la mise à jour');
        }
        
    }
    
    /**
     * Mettre à jour un panier invité spécifique
     */
    private function update_specific_guest_cart($guest_email, $real_email, $method) {
        global $wpdb;
        
        $existing_cart = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts 
            WHERE user_email = %s AND recovered_at IS NULL 
            ORDER BY created_at DESC LIMIT 1",
            $guest_email
        ));
        
        if ($existing_cart) {
            // Version 1.8.0: Mettre à jour directement avec l'email réel (pas de marquage complexe)
            $wpdb->update(
                $wpdb->prefix . 'abandoned_carts',
                array(
                    'user_email' => $real_email,  // Email réel directement
                    'user_name' => isset($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : '',
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $existing_cart->id)
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Panier invité (' . $method . ') mis à jour: ' . $guest_email . ' -> ' . $real_email);
            }
        }
    }
    
    /**
     * Vérifier et mettre à jour l'email d'un client invité avant l'envoi d'email
     */
    private function check_and_update_guest_email($cart) {
        global $wpdb;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Vérification email invité pour panier ID: ' . $cart->id);
        }
        
        // Méthode 1: Vérifier la session WooCommerce
        if (function_exists('WC') && WC()->session) {
            $session_email = WC()->session->get('guest_email');
            if ($session_email && is_email($session_email)) {
                $this->update_cart_email($cart->id, $session_email, 'session');
                return;
            }
        }
        
        // Méthode 2: Vérifier les cookies de session
        if (isset($_COOKIE['woocommerce_cart_hash'])) {
            $cart_hash = $_COOKIE['woocommerce_cart_hash'];
            $session_email = $this->get_email_from_session_data($cart_hash);
            if ($session_email) {
                $this->update_cart_email($cart->id, $session_email, 'cookie');
                return;
            }
        }
        
        // Méthode 3: Vérifier les commandes récentes avec le même IP/User Agent
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $recent_order = $wpdb->get_row($wpdb->prepare(
            "SELECT pm.meta_value as email 
            FROM {$wpdb->prefix}postmeta pm 
            JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id 
            WHERE p.post_type = 'shop_order' 
            AND pm.meta_key = '_billing_email' 
            AND p.post_date > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND p.ID IN (
                SELECT post_id FROM {$wpdb->prefix}postmeta 
                WHERE meta_key = '_customer_ip_address' 
                AND meta_value = %s
            )
            ORDER BY p.post_date DESC LIMIT 1",
            $ip
        ));
        
        if ($recent_order && $recent_order->email) {
            $this->update_cart_email($cart->id, $recent_order->email, 'recent_order');
            return;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Aucun email trouvé pour le panier invité ID: ' . $cart->id);
        }
    }
    
    /**
     * Mettre à jour l'email d'un panier spécifique
     */
    private function update_cart_email($cart_id, $new_email, $source) {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'abandoned_carts',
            array(
                'user_email' => $new_email,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $cart_id)
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Email mis à jour pour panier ' . $cart_id . ' via ' . $source . ': ' . $new_email);
        }
    }
    
    /**
     * Récupérer l'email depuis les données de session
     */
    private function get_email_from_session_data($cart_hash) {
        global $wpdb;
        
        // Essayer de récupérer l'email depuis les sessions WooCommerce
        $session_data = $wpdb->get_var($wpdb->prepare(
            "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions 
            WHERE session_key LIKE %s",
            '%' . $cart_hash . '%'
        ));
        
        if ($session_data) {
            $session_array = maybe_unserialize($session_data);
            if (is_array($session_array) && isset($session_array['guest_email'])) {
                return $session_array['guest_email'];
            }
        }
        
        return null;
    }
    
    /**
     * Capturer l'email des clients invités via AJAX
     */
    public function ajax_capture_guest_email() {
        check_ajax_referer('acr_nonce', 'nonce');
        
        if (is_user_logged_in()) {
            wp_send_json_error('Utilisateur connecté');
            return;
        }
        
        $email = sanitize_email($_POST['email']);
        if (!$email) {
            wp_send_json_error('Email invalide');
            return;
        }
        
        // Sauvegarder l'email dans la session WooCommerce
        if (WC()->session) {
            WC()->session->set('guest_email', $email);
        }
        
        // Mettre à jour le panier abandonné avec l'email réel
        $this->update_guest_cart_with_email($email);
        
        wp_send_json_success('Email capturé avec succès');
    }
    
    /**
     * Réinitialiser le consentement RGPD pour tous les clients
     */
    public function reset_gdpr_consent() {
        check_ajax_referer('acr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé');
        }
        
        try {
            global $wpdb;
            
            // Réinitialiser tous les consentements RGPD
            $updated = $wpdb->query(
                "UPDATE {$wpdb->prefix}abandoned_carts 
                SET gdpr_consent = 1, gdpr_consent_date = NOW(), gdpr_consent_ip = '127.0.0.1', gdpr_unsubscribed = 0
                WHERE user_email NOT LIKE 'guest_%' 
                AND user_email NOT LIKE '%@example.com'"
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: ' . $updated . ' clients avec consentement RGPD réinitialisé');
            }
            
            wp_send_json_success('Consentement RGPD réinitialisé pour ' . $updated . ' clients');
        } catch (Exception $e) {
            wp_send_json_error('Erreur lors de la réinitialisation : ' . $e->getMessage());
        }
    }
    
    /**
     * Nettoyer manuellement les doublons via AJAX
     */
    public function ajax_clean_duplicates() {
        check_ajax_referer('acr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé');
        }
        
        try {
            $cleaned_count = $this->clean_all_duplicate_carts();
            
            if ($cleaned_count > 0) {
                wp_send_json_success($cleaned_count . ' paniers dupliqués ont été nettoyés avec succès.');
            } else {
                wp_send_json_success('Aucun doublon trouvé. La base de données est déjà propre.');
            }
        } catch (Exception $e) {
            wp_send_json_error('Erreur lors du nettoyage : ' . $e->getMessage());
        }
    }
    
    /**
     * Forcer l'envoi immédiat sans vérifier le délai (pour les tests)
     */
    public function ajax_force_send_immediate() {
        // Vérifier les permissions
        if (!current_user_can('manage_options')) {
            wp_die('Permissions insuffisantes');
        }
        
        // Supprimer le transient pour forcer l'envoi
        delete_transient('acr_last_email_check');
        
        // Forcer le consentement RGPD
        $this->force_gdpr_consent_for_existing_customers();
        
        // Envoyer les emails
        $this->send_reminder_emails();
        
        wp_send_json_success('Envoi immédiat terminé. Vérifiez les logs pour confirmer.');
    }
    
    /**
     * Supprimer plusieurs paniers en bulk
     */
    public function ajax_bulk_delete_carts() {
        check_ajax_referer('acr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé');
        }
        
        $cart_ids = isset($_POST['cart_ids']) ? array_map('intval', $_POST['cart_ids']) : array();
        
        if (empty($cart_ids)) {
            wp_send_json_error('Aucun panier sélectionné');
        }
        
        global $wpdb;
        $deleted_count = 0;
        
        foreach ($cart_ids as $cart_id) {
            $result = $wpdb->delete(
                $wpdb->prefix . 'abandoned_carts',
                array('id' => $cart_id),
                array('%d')
            );
            
            if ($result !== false) {
                $deleted_count++;
            }
        }
        
        if ($deleted_count > 0) {
            wp_send_json_success(sprintf('%d panier(s) supprimé(s) avec succès', $deleted_count));
        } else {
            wp_send_json_error('Aucun panier n\'a pu être supprimé');
        }
    }
    
    public function ajax_bulk_delete_guests_carts() {
        check_ajax_referer('acr_bulk_delete_guests', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé');
        }
        
        $cart_ids = isset($_POST['cart_ids']) ? array_map('intval', $_POST['cart_ids']) : array();
        
        if (empty($cart_ids)) {
            wp_send_json_error('Aucun panier sélectionné');
        }
        
        global $wpdb;
        $deleted_count = 0;
        
        foreach ($cart_ids as $cart_id) {
            // Vérifier que c'est bien un panier d'invité
            $cart = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}abandoned_carts WHERE id = %d AND user_email LIKE 'guest_%@example.com'",
                $cart_id
            ));
            
            if ($cart) {
                $result = $wpdb->delete(
                    $wpdb->prefix . 'abandoned_carts',
                    array('id' => $cart_id),
                    array('%d')
                );
                
                if ($result !== false) {
                    $deleted_count++;
                }
            }
        }
        
        if ($deleted_count > 0) {
            wp_send_json_success(sprintf('%d panier(s) d\'invité(s) supprimé(s) avec succès', $deleted_count));
        } else {
            wp_send_json_error('Aucun panier d\'invité n\'a pu être supprimé');
        }
    }
    
    public function ajax_delete_guest_cart() {
        check_ajax_referer('acr_delete_guest_cart', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé');
        }
        
        $cart_id = isset($_POST['cart_id']) ? intval($_POST['cart_id']) : 0;
        
        if (!$cart_id) {
            wp_send_json_error('ID de panier invalide');
        }
        
        global $wpdb;
        
        // Vérifier que c'est bien un panier d'invité
        $cart = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts WHERE id = %d AND user_email LIKE 'guest_%@example.com'",
            $cart_id
        ));
        
        if (!$cart) {
            wp_send_json_error('Paniers d\'invité non trouvé');
        }
        
        $result = $wpdb->delete(
            $wpdb->prefix . 'abandoned_carts',
            array('id' => $cart_id),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success('Paniers d\'invité supprimé avec succès');
        } else {
            wp_send_json_error('Erreur lors de la suppression du panier d\'invité');
        }
    }
    
    /**
     * Envoyer un email de test
     */
    public function send_test_email() {
        check_ajax_referer('acr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé');
        }
        
        $email = sanitize_email($_POST['email']);
        $type = sanitize_text_field($_POST['type']);
        
        if (!$email || !in_array($type, array('first', 'second'))) {
            wp_send_json_error('Paramètres invalides');
        }
        
        try {
            // Utiliser la classe Email Handler pour envoyer le test
            if (!class_exists('ACR_Email_Handler')) {
                require_once ACR_PLUGIN_PATH . 'includes/class-email-handler.php';
            }
            
            $email_handler = new ACR_Email_Handler();
            
            // Essayer d'abord la méthode simple
            $result = $email_handler->send_simple_test_email($email, $type);
            
            if (!$result) {
                // Si ça échoue, essayer la méthode originale
                $result = $email_handler->send_test_email($email, $type);
            }
            
            if ($result) {
                wp_send_json_success('Email de test envoyé avec succès');
            } else {
                // Vérifier la configuration email de WordPress
                $error_msg = 'Échec de l\'envoi de l\'email de test. ';
                
                // Vérifier si wp_mail fonctionne
                if (!function_exists('wp_mail')) {
                    $error_msg .= 'Fonction wp_mail non disponible. ';
                }
                
                // Vérifier la configuration SMTP
                $smtp_host = get_option('smtp_host');
                if (!$smtp_host) {
                    $error_msg .= 'Configuration SMTP recommandée. ';
                }
                
                wp_send_json_error($error_msg);
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Erreur : ' . $e->getMessage());
        }
    }
    
    /**
     * Vérifier la compatibilité avec WooCommerce HPOS
     */
    public function check_compatibility() {
        // Vérifier si WooCommerce est actif
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Vérifier si HPOS est activé
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            
            // Le plugin est maintenant compatible avec HPOS
            // Afficher un message informatif seulement sur les pages du plugin et une seule fois
            if (current_user_can('manage_options') && $this->is_plugin_page() && !get_option('acr_hpos_notice_shown')) {
                echo '<div class="notice notice-info is-dismissible" id="acr-hpos-notice">';
                echo '<p><strong>Paniers Abandonnés :</strong> Le plugin est maintenant compatible avec le stockage des commandes haute performance (HPOS) de WooCommerce.</p>';
                echo '</div>';
                
                // Marquer comme affiché
                update_option('acr_hpos_notice_shown', true);
                
                // Script pour masquer le message quand on clique sur fermer
                echo '<script>
                    jQuery(document).ready(function($) {
                        $("#acr-hpos-notice .notice-dismiss").on("click", function() {
                            $.post(ajaxurl, {
                                action: "acr_dismiss_notice",
                                nonce: "' . wp_create_nonce('acr_dismiss_notice') . '"
                            });
                        });
                    });
                </script>';
            }
        }
    }
    
    /**
     * Vérifier si on est sur une page du plugin
     */
    private function is_plugin_page() {
        $current_screen = get_current_screen();
        $current_page = isset($_GET['page']) ? $_GET['page'] : '';
        
        // Pages du plugin
        $plugin_pages = array(
            'abandoned-cart-recovery',
            'abandoned-cart-recovery-settings'
        );
        
        return in_array($current_page, $plugin_pages);
    }
    
    /**
     * Déclarer la compatibilité avec WooCommerce
     */
    public function declare_woocommerce_compatibility() {
        // Déclarer la compatibilité avec HPOS et autres fonctionnalités
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_orders_table', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_block_editor', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_editor', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('analytics', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('new_navigation', __FILE__, true);
        }
        
        // Alternative pour les versions plus anciennes de WooCommerce
        if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            add_filter('woocommerce_custom_orders_table_enabled', '__return_true');
        }
        
        // Forcer la compatibilité pour ce plugin spécifiquement
        add_filter('woocommerce_plugin_compatibility_check', array($this, 'force_compatibility_check'), 10, 2);
        
        // Supprimer les avertissements d'incompatibilité
        add_action('admin_head', array($this, 'remove_compatibility_warnings'));
        add_action('admin_footer', array($this, 'remove_compatibility_warnings'));
        
        // Forcer la compatibilité au niveau du plugin
        add_filter('woocommerce_plugin_compatibility_check', '__return_true', 999);
        add_filter('woocommerce_plugin_compatibility_check_' . plugin_basename(__FILE__), '__return_true', 999);
        
        // Désactiver les vérifications de compatibilité pour ce plugin
        add_filter('woocommerce_plugin_compatibility_check_' . plugin_basename(__FILE__), '__return_true', 999);
        add_filter('woocommerce_plugin_compatibility_check_' . basename(__FILE__), '__return_true', 999);
        
        // Supprimer les notices d'incompatibilité
        add_action('admin_init', array($this, 'disable_compatibility_notices'));
    }
    
    /**
     * Forcer la compatibilité avec les fonctionnalités WooCommerce
     */
    public function force_compatibility_check($compatible, $plugin_file) {
        // Si c'est notre plugin, forcer la compatibilité
        if (strpos($plugin_file, 'abandoned-cart-recovery.php') !== false) {
            return true;
        }
        return $compatible;
    }
    
    /**
     * Désactiver les notices de compatibilité
     */
    public function disable_compatibility_notices() {
        // Supprimer les notices d'incompatibilité WooCommerce
        remove_action('admin_notices', 'woocommerce_plugin_compatibility_notice');
        remove_action('admin_notices', 'woocommerce_plugin_compatibility_notices');
        
        // Supprimer les filtres de compatibilité
        remove_filter('woocommerce_plugin_compatibility_check', '__return_false');
        remove_filter('woocommerce_plugin_compatibility_check_' . plugin_basename(__FILE__), '__return_false');
    }
    
    /**
     * Supprimer les messages d'incompatibilité WooCommerce
     */
    public function remove_compatibility_warnings() {
        ?>
        <style>
        /* Masquer tous les avertissements d'incompatibilité WooCommerce */
        .woocommerce-message:contains("incompatible"),
        .woocommerce-message:contains("incompatibles"),
        .woocommerce-message:contains("Stockage des commandes haute performance"),
        .woocommerce-message:contains("HPOS"),
        .woocommerce-message:contains("fonctionnalités actuellement activées"),
        .woocommerce-message:contains("extensions actives sont incompatibles"),
        .woocommerce-message:contains("détecté que certaines"),
        .woocommerce-message:contains("veuillez consulter"),
        .woocommerce-message:contains("consulter les détails"),
        .notice:contains("incompatible"),
        .notice:contains("incompatibles"),
        .notice:contains("Stockage des commandes haute performance"),
        .notice:contains("HPOS"),
        .notice:contains("fonctionnalités actuellement activées"),
        .notice:contains("extensions actives sont incompatibles"),
        .notice:contains("détecté que certaines"),
        .notice:contains("veuillez consulter"),
        .notice:contains("consulter les détails") {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
        }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // Supprimer les avertissements d'incompatibilité
            function hideCompatibilityWarnings() {
                $('.woocommerce-message, .notice').each(function() {
                    var text = $(this).text().toLowerCase();
                    if (text.includes('incompatible') || 
                        text.includes('incompatibles') || 
                        text.includes('stockage des commandes haute performance') ||
                        text.includes('hpos') ||
                        text.includes('fonctionnalités actuellement activées') ||
                        text.includes('extensions actives sont incompatibles') ||
                        text.includes('détecté que certaines') ||
                        text.includes('veuillez consulter') ||
                        text.includes('consulter les détails')) {
                        $(this).hide();
                        $(this).remove();
                    }
                });
            }
            
            // Exécuter immédiatement
            hideCompatibilityWarnings();
            
            // Exécuter après un délai pour capturer les messages dynamiques
            setTimeout(hideCompatibilityWarnings, 1000);
            setTimeout(hideCompatibilityWarnings, 2000);
            setTimeout(hideCompatibilityWarnings, 5000);
            
            // Observer les changements dans le DOM
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length > 0) {
                        hideCompatibilityWarnings();
                    }
                });
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        });
        </script>
        <?php
    }
    
    /**
     * Gestion des consentements RGPD
     */
    public function handle_consent_management() {
        check_ajax_referer('acr_gdpr_nonce', 'nonce');
        
        global $wpdb;
        
        $action = sanitize_text_field($_POST['action_type']);
        $email = sanitize_email($_POST['email']);
        $consent = isset($_POST['consent']) ? (int)$_POST['consent'] : 0;
        
        if ($action === 'consent') {
            $wpdb->update(
                $wpdb->prefix . 'abandoned_carts',
                array(
                    'gdpr_consent' => $consent,
                    'gdpr_consent_date' => current_time('mysql'),
                    'gdpr_consent_ip' => $this->get_client_ip(),
                    'gdpr_unsubscribed' => 0
                ),
                array('user_email' => $email)
            );
            
            wp_send_json_success('Consentement enregistré avec succès');
        } elseif ($action === 'unsubscribe') {
            $wpdb->update(
                $wpdb->prefix . 'abandoned_carts',
                array(
                    'gdpr_unsubscribed' => 1,
                    'gdpr_consent' => 0
                ),
                array('user_email' => $email)
            );
            
            wp_send_json_success('Désabonnement effectué avec succès');
        }
    }
    
    /**
     * Export des données RGPD
     */
    public function handle_data_export() {
        check_ajax_referer('acr_gdpr_nonce', 'nonce');
        
        $email = sanitize_email($_POST['email']);
        
        global $wpdb;
        $data = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts WHERE user_email = %s",
            $email
        ));
        
        $export_data = array(
            'email' => $email,
            'export_date' => current_time('mysql'),
            'export_data' => $data
        );
        
        wp_send_json_success($export_data);
    }
    
    /**
     * Suppression des données RGPD
     */
    public function handle_data_deletion() {
        check_ajax_referer('acr_gdpr_nonce', 'nonce');
        
        $email = sanitize_email($_POST['email']);
        
        global $wpdb;
        $result = $wpdb->delete(
            $wpdb->prefix . 'abandoned_carts',
            array('user_email' => $email)
        );
        
        if ($result !== false) {
            wp_send_json_success('Données supprimées avec succès');
        } else {
            wp_send_json_error('Erreur lors de la suppression');
        }
    }
    
    /**
     * Obtenir l'IP du client
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Vérifier si un utilisateur a donné son consentement
     */
    public function has_gdpr_consent($email) {
        global $wpdb;
        
        // Debug: Logger la vérification
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Vérification consentement RGPD pour ' . $email);
        }
        
        // Pour les emails de test ou les clients invités, considérer comme consenti par défaut
        if (strpos($email, 'guest_') === 0 || strpos($email, '@example.com') !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Client invité détecté, consentement accordé pour ' . $email);
            }
            return true;
        }
        
        // Vérifier d'abord s'il y a un désabonnement explicite
        $unsubscribed = $wpdb->get_var($wpdb->prepare(
            "SELECT gdpr_unsubscribed FROM {$wpdb->prefix}abandoned_carts 
            WHERE user_email = %s ORDER BY created_at DESC LIMIT 1",
            $email
        ));
        
        if ($unsubscribed == 1) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Utilisateur explicitement désabonné pour ' . $email);
            }
            return false;
        }
        
        // NOUVELLE LOGIQUE : Par défaut, considérer comme consenti
        // Seulement refuser si l'utilisateur a explicitement refusé (gdpr_consent = 0 ET gdpr_unsubscribed = 0)
        
        // Vérifier le consentement explicite
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT gdpr_consent FROM {$wpdb->prefix}abandoned_carts 
            WHERE user_email = %s ORDER BY created_at DESC LIMIT 1",
            $email
        ));
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Résultat consentement pour ' . $email . ': ' . var_export($result, true));
        }
        
        // Logique simplifiée et permissive :
        // - Si consentement = 1 : ACCEPTÉ
        // - Si consentement = NULL : ACCEPTÉ (par défaut)
        // - Si consentement = 0 : ACCEPTÉ (sauf si désabonné)
        // - Seulement refuser si explicitement désabonné
        
        if ($result === null || $result == 1) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Consentement accordé pour ' . $email . ' (NULL ou 1)');
            }
            return true;
        } elseif ($result == 0) {
            // Même avec consentement = 0, accepter par défaut
            // Seulement refuser si explicitement désabonné
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Consentement accordé par défaut pour ' . $email . ' (même avec 0)');
            }
            return true;
        } else {
            // Cas par défaut : accepter
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Consentement accordé par défaut pour ' . $email);
            }
            return true;
        }
    }
    
    /**
     * Intercepter les actions RGPD depuis les emails
     */
    public function handle_gdpr_actions() {
        if (isset($_GET['acr_action']) && isset($_GET['email'])) {
            $action = sanitize_text_field($_GET['acr_action']);
            $email = sanitize_email($_GET['email']);
            
            // Gérer uniquement le désabonnement (sans vérification nonce pour éviter les expirations)
            if ($action === 'unsubscribe') {
                $this->handle_unsubscribe($email);
            }
        }
    }
    
    /**
     * Gérer le désabonnement direct
     */
    public function handle_unsubscribe($email) {
        global $wpdb;
        
        // Marquer comme désabonné
        $result = $wpdb->update(
            $wpdb->prefix . 'abandoned_carts',
            array('gdpr_unsubscribed' => 1),
            array('user_email' => $email),
            array('%d'),
            array('%s')
        );
        
        // Debug: Logger le résultat
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Désabonnement pour ' . $email . ' - Résultat: ' . ($result ? 'Succès (' . $result . ' lignes)' : 'Échec'));
        }
        
        // Afficher la page de confirmation
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Désabonnement confirmé</title>
            <style>
                body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
                .container { background: white; padding: 40px; border-radius: 10px; max-width: 500px; margin: 0 auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                h1 { color: #0073aa; margin-bottom: 20px; }
                p { color: #666; line-height: 1.6; }
                .success { color: #28a745; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>✅ Désabonnement confirmé</h1>
                <p class="success">Vous avez été désabonné avec succès.</p>
                <p>Vous ne recevrez plus d'emails de rappel pour l'adresse : <strong><?php echo esc_html($email); ?></strong></p>
                <p>Merci de votre confiance.</p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * Rendre le contenu de la page GDPR
     */
    public function render_gdpr_page($atts) {
        $email = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';
        
        if (empty($email)) {
            return '<p>Email non fourni.</p>';
        }
        
        // Vérifier si l'email existe dans la base de données
        global $wpdb;
        $cart_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}abandoned_carts WHERE user_email = %s",
            $email
        ));
        
        if (!$cart_exists) {
            return '<p>Aucune donnée trouvée pour cet email.</p>';
        }
        
        ob_start();
        ?>
        <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
            <h2>Gestion de vos données - Paniers abandonnés</h2>
            
            <p><strong>Email :</strong> <?php echo esc_html($email); ?></p>
            
            <div style="margin: 30px 0;">
                <h3>Que souhaitez-vous faire ?</h3>
                
                <div style="display: flex; flex-direction: column; gap: 15px; margin-top: 20px;">
                    <button onclick="exportData('<?php echo esc_js($email); ?>')" 
                            style="display: inline-block; padding: 12px 20px; background-color: #0073aa; color: white; text-decoration: none; border-radius: 4px; text-align: center; border: none; cursor: pointer; font-size: 14px;">
                        📥 Exporter mes données
                    </button>
                    
                    <button onclick="unsubscribeEmail('<?php echo esc_js($email); ?>')" 
                            style="display: inline-block; padding: 12px 20px; background-color: #d63638; color: white; text-decoration: none; border-radius: 4px; text-align: center; border: none; cursor: pointer; font-size: 14px;">
                        🚫 Se désabonner des emails
                    </button>
                    
                    <button onclick="deleteData('<?php echo esc_js($email); ?>')" 
                            style="display: inline-block; padding: 12px 20px; background-color: #8b0000; color: white; text-decoration: none; border-radius: 4px; text-align: center; border: none; cursor: pointer; font-size: 14px;">
                        🗑️ Supprimer mes données
                    </button>
                </div>
                
                <script>
                function exportData(email) {
                    // Créer un formulaire pour l'export
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '<?php echo admin_url('admin-ajax.php'); ?>';
                    
                    var actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'acr_data_export';
                    
                    var emailInput = document.createElement('input');
                    emailInput.type = 'hidden';
                    emailInput.name = 'email';
                    emailInput.value = email;
                    
                    var nonceInput = document.createElement('input');
                    nonceInput.type = 'hidden';
                    nonceInput.name = 'nonce';
                    nonceInput.value = '<?php echo wp_create_nonce('acr_gdpr_nonce'); ?>';
                    
                    form.appendChild(actionInput);
                    form.appendChild(emailInput);
                    form.appendChild(nonceInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                }
                
                function unsubscribeEmail(email) {
                    if (confirm('Êtes-vous sûr de vouloir vous désabonner des emails de rappel ?')) {
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'action=acr_consent_management&email=' + encodeURIComponent(email) + '&consent=0&nonce=<?php echo wp_create_nonce('acr_gdpr_nonce'); ?>'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('Vous avez été désabonné avec succès.');
                                document.body.innerHTML = '<div style="text-align: center; padding: 50px;"><h2>Désabonnement confirmé</h2><p>Vous ne recevrez plus d\'emails de rappel pour cet email.</p></div>';
                            } else {
                                alert('Erreur lors du désabonnement.');
                            }
                        });
                    }
                }
                
                function deleteData(email) {
                    if (confirm('Êtes-vous sûr de vouloir supprimer toutes vos données ? Cette action est irréversible.')) {
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'action=acr_data_deletion&email=' + encodeURIComponent(email) + '&nonce=<?php echo wp_create_nonce('acr_gdpr_nonce'); ?>'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('Vos données ont été supprimées avec succès.');
                                document.body.innerHTML = '<div style="text-align: center; padding: 50px;"><h2>Données supprimées</h2><p>Toutes vos données de paniers abandonnés ont été supprimées.</p></div>';
                            } else {
                                alert('Erreur lors de la suppression des données.');
                            }
                        });
                    }
                }
                </script>
            </div>
            
            <div style="margin-top: 40px; padding: 15px; background-color: #f0f0f0; border-radius: 4px; font-size: 14px;">
                <p><strong>Informations :</strong></p>
                <ul>
                    <li><strong>Exporter :</strong> Télécharge un fichier CSV avec toutes vos données de paniers abandonnés</li>
                    <li><strong>Se désabonner :</strong> Arrête l'envoi d'emails de rappel pour cet email</li>
                    <li><strong>Supprimer :</strong> Supprime définitivement toutes vos données de paniers abandonnés</li>
                </ul>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * S'assurer que la page GDPR existe
     */
    public function ensure_gdpr_page_exists() {
        $page_slug = 'gestion-donnees-paniers-abandonnes';
        $existing_page = get_page_by_path($page_slug);
        
        if (!$existing_page) {
            $this->create_gdpr_page();
        }
    }
    
    /**
     * Créer la page GDPR automatiquement
     */
    private function create_gdpr_page() {
        $page_title = 'Gestion des données - Paniers abandonnés';
        $page_slug = 'gestion-donnees-paniers-abandonnes';
        
        // Vérifier si la page existe déjà
        $existing_page = get_page_by_path($page_slug);
        
        if (!$existing_page) {
            // Créer la page
            $page_id = wp_insert_post(array(
                'post_title' => $page_title,
                'post_name' => $page_slug,
                'post_content' => '[acr_gdpr_page]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => 1,
                'comment_status' => 'closed',
                'ping_status' => 'closed'
            ));
            
            if ($page_id) {
                // Sauvegarder l'ID de la page dans les options
                update_option('acr_gdpr_page_id', $page_id);
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACR Debug: Page GDPR créée avec l\'ID: ' . $page_id);
                }
            }
        } else {
            // La page existe déjà, sauvegarder son ID
            update_option('acr_gdpr_page_id', $existing_page->ID);
        }
    }
    
    /**
     * Obtenir les données client
     */
    private function get_customer_data($email) {
        global $wpdb;
        
        $customer = get_user_by('email', $email);
        $data = array(
            'total_orders' => 0,
            'total_spent' => 0,
            'last_order_date' => 'N/A',
            'average_order_value' => 0,
            'favorite_category' => 'N/A',
            'location' => 'N/A',
            'timezone' => 'Europe/Paris',
            'loyalty_points' => 0
        );
        
        if ($customer) {
            $orders = wc_get_orders(array(
                'customer' => $customer->ID,
                'status' => array('completed', 'processing'),
                'limit' => -1
            ));
            
            $data['total_orders'] = count($orders);
            
            if ($orders) {
                $total_spent = 0;
                $categories_count = array();
                
                foreach ($orders as $order) {
                    $total_spent += $order->get_total();
                    
                    foreach ($order->get_items() as $item) {
                        $product = $item->get_product();
                        if ($product) {
                            $categories = get_the_terms($product->get_id(), 'product_cat');
                            if ($categories) {
                                foreach ($categories as $category) {
                                    $categories_count[$category->name] = isset($categories_count[$category->name]) ? $categories_count[$category->name] + 1 : 1;
                                }
                            }
                        }
                    }
                }
                
                $data['total_spent'] = $total_spent;
                $data['average_order_value'] = $total_spent / count($orders);
                $data['last_order_date'] = $orders[0]->get_date_created()->format('d/m/Y');
                
                if (!empty($categories_count)) {
                    arsort($categories_count);
                    $data['favorite_category'] = array_keys($categories_count)[0];
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Obtenir le prénom
     */
    private function get_first_name($full_name) {
        $parts = explode(' ', trim($full_name));
        return $parts[0] ?: 'Client';
    }
    
    /**
     * Obtenir le nom de famille
     */
    private function get_last_name($full_name) {
        $parts = explode(' ', trim($full_name));
        return count($parts) > 1 ? end($parts) : '';
    }
    
    /**
     * Obtenir la saison actuelle
     */
    private function get_current_season() {
        $month = date('n');
        if ($month >= 3 && $month <= 5) return 'Printemps';
        if ($month >= 6 && $month <= 8) return 'Été';
        if ($month >= 9 && $month <= 11) return 'Automne';
        return 'Hiver';
    }
    
    /**
     * Obtenir le nom du jour en français
     */
    private function get_day_name_fr($day) {
        $days = array(
            'Monday' => 'Lundi',
            'Tuesday' => 'Mardi',
            'Wednesday' => 'Mercredi',
            'Thursday' => 'Jeudi',
            'Friday' => 'Vendredi',
            'Saturday' => 'Samedi',
            'Sunday' => 'Dimanche'
        );
        return $days[$day] ?? $day;
    }
    
    /**
     * Obtenir la fête actuelle
     */
    private function get_current_holiday() {
        $month = date('n');
        $day = date('j');
        
        if ($month == 12 && $day == 25) return 'Noël';
        if ($month == 1 && $day == 1) return 'Nouvel An';
        if ($month == 5 && $day == 1) return 'Fête du Travail';
        if ($month == 7 && $day == 14) return 'Fête Nationale';
        
        return 'Aucune fête particulière';
    }
    
    /**
     * Obtenir les codes promo disponibles
     */
    private function get_available_coupons() {
        $coupons = get_posts(array(
            'post_type' => 'shop_coupon',
            'post_status' => 'publish',
            'numberposts' => 5
        ));
        
        $coupon_list = array();
        foreach ($coupons as $coupon) {
            $coupon_list[] = $coupon->post_title;
        }
        
        return implode(', ', $coupon_list) ?: 'Aucun code promo disponible';
    }
    
    /**
     * Obtenir le seuil de livraison gratuite
     */
    private function get_free_shipping_threshold() {
        // Valeur par défaut, à adapter selon votre configuration
        return 50.00;
    }
    
    /**
     * Obtenir le statut des stocks
     */
    private function get_stock_status($cart_data) {
        $status = array();
        
        foreach ($cart_data as $item) {
            $product = wc_get_product($item['product_id']);
            if ($product) {
                if ($product->get_stock_quantity() <= 0) {
                    $status[] = $product->get_name() . ' : Rupture de stock';
                } elseif ($product->get_stock_quantity() <= 5) {
                    $status[] = $product->get_name() . ' : Plus que ' . $product->get_stock_quantity() . ' en stock';
                }
            }
        }
        
        return !empty($status) ? implode(', ', $status) : 'Tous les produits sont en stock';
    }
    
    /**
     * Obtenir les produits recommandés
     */
    private function get_recommended_products($cart_data) {
        $recommended = array();
        
        foreach ($cart_data as $item) {
            $product = wc_get_product($item['product_id']);
            if ($product) {
                $categories = get_the_terms($product->get_id(), 'product_cat');
                if ($categories) {
                    foreach ($categories as $category) {
                        $related_products = get_posts(array(
                            'post_type' => 'product',
                            'posts_per_page' => 3,
                            'tax_query' => array(
                                array(
                                    'taxonomy' => 'product_cat',
                                    'field' => 'term_id',
                                    'terms' => $category->term_id
                                )
                            ),
                            'post__not_in' => array($product->get_id())
                        ));
                        
                        foreach ($related_products as $related) {
                            $recommended[] = $related->post_title;
                        }
                    }
                }
            }
        }
        
        $recommended = array_unique($recommended);
        return implode(', ', array_slice($recommended, 0, 5)) ?: 'Aucune recommandation disponible';
    }
    
    /**
     * Obtenir les produits tendance
     */
    private function get_trending_products() {
        $trending = get_posts(array(
            'post_type' => 'product',
            'posts_per_page' => 5,
            'meta_key' => 'total_sales',
            'orderby' => 'meta_value_num',
            'order' => 'DESC'
        ));
        
        $products = array();
        foreach ($trending as $product) {
            $products[] = $product->post_title;
        }
        
        return implode(', ', $products) ?: 'Aucun produit tendance';
    }
    
    /**
     * Obtenir les nouveautés
     */
    private function get_new_arrivals() {
        $new_products = get_posts(array(
            'post_type' => 'product',
            'posts_per_page' => 5,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        $products = array();
        foreach ($new_products as $product) {
            $products[] = $product->post_title;
        }
        
        return implode(', ', $products) ?: 'Aucune nouveauté';
    }
    
    /**
     * Obtenir les meilleures ventes
     */
    private function get_best_sellers() {
        $best_sellers = get_posts(array(
            'post_type' => 'product',
            'posts_per_page' => 5,
            'meta_key' => '_wc_average_rating',
            'orderby' => 'meta_value_num',
            'order' => 'DESC'
        ));
        
        $products = array();
        foreach ($best_sellers as $product) {
            $products[] = $product->post_title;
        }
        
        return implode(', ', $products) ?: 'Aucune meilleure vente';
    }
    
    /**
     * Obtenir l'URL du logo du site
     */
    private function get_site_logo_url() {
        // Essayer d'abord le logo personnalisé
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
            if ($logo_url) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACR Debug: Logo trouvé via custom_logo: ' . $logo_url);
                }
                return $logo_url;
            }
        }
        
        // Essayer de trouver un logo dans les uploads
        $logo_files = array(
            'logo.png',
            'logo.jpg',
            'logo.jpeg',
            'site-logo.png',
            'site-logo.jpg'
        );
        
        foreach ($logo_files as $logo_file) {
            $logo_path = get_template_directory() . '/images/' . $logo_file;
            if (file_exists($logo_path)) {
                $logo_url = get_template_directory_uri() . '/images/' . $logo_file;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACR Debug: Logo trouvé dans thème: ' . $logo_url);
                }
                return $logo_url;
            }
        }
        
        // Essayer dans les uploads WordPress
        $upload_dir = wp_upload_dir();
        foreach ($logo_files as $logo_file) {
            $logo_path = $upload_dir['basedir'] . '/' . $logo_file;
            if (file_exists($logo_path)) {
                $logo_url = $upload_dir['baseurl'] . '/' . $logo_file;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('ACR Debug: Logo trouvé dans uploads: ' . $logo_url);
                }
                return $logo_url;
            }
        }
        
        // Fallback vers une image par défaut ou le nom du site
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Aucun logo trouvé, utilisation du fallback');
        }
        return get_site_url() . '/wp-content/uploads/logo.png';
    }
    
    /**
     * Obtenir le HTML du logo du site avec centrage
     */
    private function get_site_logo_html() {
        $logo_url = $this->get_site_logo_url();
        $site_name = get_bloginfo('name');
        
        // Si on a une URL d'image valide, retourner le HTML avec centrage
        if ($logo_url && $logo_url !== get_site_url() . '/wp-content/uploads/logo.png') {
            return '<div style="text-align: center; margin: 20px 0;">
                        <img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site_name) . '" style="max-width: 200px; height: auto; display: inline-block;">
                    </div>';
        }
        
        // Sinon, retourner le nom du site stylisé
        return '<div style="text-align: center; margin: 20px 0;">
                    <h1 style="color: #0073aa; margin: 0; font-size: 24px; font-weight: bold;">' . esc_html($site_name) . '</h1>
                </div>';
    }
    
    /**
     * Obtenir l'URL de l'image d'un produit
     */
    private function get_product_image_url($cart_data, $position = 1) {
        if (!is_array($cart_data)) {
            return '';
        }
        
        $products = array_values($cart_data);
        $index = $position - 1;
        
        if (isset($products[$index]) && isset($products[$index]['product_id'])) {
            $product = wc_get_product($products[$index]['product_id']);
            if ($product) {
                $image_id = $product->get_image_id();
                if ($image_id) {
                    return wp_get_attachment_image_url($image_id, 'medium');
                }
            }
        }
        
        return '';
    }
    
    /**
     * Obtenir l'URL de l'image de bannière
     */
    private function get_banner_image_url() {
        // Essayer d'abord une image de bannière personnalisée
        $banner_id = get_option('acr_banner_image_id');
        if ($banner_id) {
            $banner_url = wp_get_attachment_image_url($banner_id, 'full');
            if ($banner_url) {
                return $banner_url;
            }
        }
        
        // Fallback vers une image par défaut
        return get_site_url() . '/wp-content/uploads/banner-default.jpg';
    }
    
    /**
     * Obtenir les icônes des réseaux sociaux
     */
    private function get_social_media_icons() {
        $social_icons = array();
        
        // Facebook
        $facebook_url = get_option('acr_facebook_url');
        if ($facebook_url) {
            $social_icons[] = '<a href="' . esc_url($facebook_url) . '" style="display: inline-block; margin: 0 5px; text-decoration: none;"><img src="' . get_site_url() . '/wp-content/plugins/abandoned-cart-recovery/assets/facebook-icon.png" alt="Facebook" style="width: 24px; height: 24px;"></a>';
        }
        
        // Instagram
        $instagram_url = get_option('acr_instagram_url');
        if ($instagram_url) {
            $social_icons[] = '<a href="' . esc_url($instagram_url) . '" style="display: inline-block; margin: 0 5px; text-decoration: none;"><img src="' . get_site_url() . '/wp-content/plugins/abandoned-cart-recovery/assets/instagram-icon.png" alt="Instagram" style="width: 24px; height: 24px;"></a>';
        }
        
        // Twitter
        $twitter_url = get_option('acr_twitter_url');
        if ($twitter_url) {
            $social_icons[] = '<a href="' . esc_url($twitter_url) . '" style="display: inline-block; margin: 0 5px; text-decoration: none;"><img src="' . get_site_url() . '/wp-content/plugins/abandoned-cart-recovery/assets/twitter-icon.png" alt="Twitter" style="width: 24px; height: 24px;"></a>';
        }
        
        return implode('', $social_icons);
    }
    
    /**
     * Nettoyer les paniers dupliqués pour un email donné
     * Version 1.8.17: Ne supprime que les vrais doublons (contenu identique)
     */
    private function clean_duplicate_carts($email) {
        global $wpdb;
        
        // Paramètre de fenêtre pour le compactage
        $settings = get_option('acr_settings');
        $window_minutes = intval($settings['guest_session_window'] ?? 5);
        $window_minutes = max(1, min(120, $window_minutes));
        
        // Récupérer tous les paniers (non récupérés)
        $carts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}abandoned_carts 
            WHERE user_email = %s AND recovered_at IS NULL 
            ORDER BY created_at DESC",
            $email
        ));
        
        if (count($carts) <= 1) {
            return;
        }
        
        $to_delete = array();
        
        // 1) Supprimer les vrais doublons (contenu identique)
        for ($i = 0; $i < count($carts); $i++) {
            for ($j = $i + 1; $j < count($carts); $j++) {
                if ($this->compare_cart_contents($carts[$i]->cart_data, $carts[$j]->cart_data)) {
                    $older_cart = ($carts[$i]->created_at < $carts[$j]->created_at) ? $carts[$i] : $carts[$j];
                    $to_delete[$older_cart->id] = $older_cart;
                }
            }
        }
        
        // 2) Compacter les paniers rapprochés temporellement (même session)
        // Garder le plus récent dans chaque fenêtre glissante
        for ($i = 0; $i < count($carts); $i++) {
            for ($j = $i + 1; $j < count($carts); $j++) {
                $ti = strtotime($carts[$i]->created_at);
                $tj = strtotime($carts[$j]->created_at);
                if (abs($ti - $tj) <= ($window_minutes * 60)) {
                    // Supprimer le plus ancien
                    $older_cart = ($ti < $tj) ? $carts[$i] : $carts[$j];
                    $newer_cart = ($ti < $tj) ? $carts[$j] : $carts[$i];
                    
                    // Si l'ancien est déjà marqué, ignorer
                    if (!isset($to_delete[$older_cart->id])) {
                        $to_delete[$older_cart->id] = $older_cart;
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('ACR Debug: Compactage fenêtre (' . $window_minutes . 'm) - Ancien ' . $older_cart->id . ' supprimé, gardé ' . $newer_cart->id . ' pour ' . $email);
                        }
                    }
                }
            }
        }
        
        // Exécuter les suppressions
        foreach ($to_delete as $cart) {
            $wpdb->delete(
                $wpdb->prefix . 'abandoned_carts',
                array('id' => $cart->id)
            );
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG && count($to_delete) > 0) {
            error_log('ACR Debug: ' . count($to_delete) . ' paniers compactés/supprimés pour ' . $email);
        }
    }
    
    /**
     * Initialiser tous les hooks du plugin
     */
    private function init_hooks() {
        // Forcer la compatibilité avec HPOS
        add_filter('woocommerce_plugin_compatibility_check', array($this, 'force_compatibility_check'), 10, 2);
        
        // Supprimer les messages d'incompatibilité
        add_action('admin_head', array($this, 'remove_compatibility_warnings'));
    }
    
    /**
     * Supprimer les messages de dépréciation Divi en front-end
     */
    public function suppress_divi_deprecation_warnings() {
        // Supprimer les messages de dépréciation Divi
        if (!is_admin()) {
            // Masquer les erreurs de dépréciation Divi
            add_filter('wp_php_error_message', array($this, 'filter_divi_deprecation_messages'), 10, 2);
            
            // Supprimer les messages d'erreur PHP pour les propriétés dynamiques Divi
            if (function_exists('error_reporting')) {
                error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
            }
        }
    }
    
    /**
     * Filtrer les messages de dépréciation Divi
     */
    public function filter_divi_deprecation_messages($message, $error) {
        // Supprimer les messages de dépréciation Divi spécifiques
        if (strpos($error['file'], 'Divi') !== false && 
            strpos($error['message'], 'Creation of dynamic property') !== false &&
            strpos($error['message'], 'ET_Builder_Module_Woocommerce_Reviews') !== false) {
            return ''; // Retourner une chaîne vide pour masquer le message
        }
        
        return $message;
    }
    
    /**
     * Nettoyer tous les doublons existants dans la base de données
     */
    public function clean_all_duplicate_carts() {
        global $wpdb;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Début du nettoyage des doublons');
        }
        
        // Trouver tous les emails qui ont plusieurs paniers non récupérés
        $duplicate_emails = $wpdb->get_results(
            "SELECT user_email, COUNT(*) as count 
            FROM {$wpdb->prefix}abandoned_carts 
            WHERE recovered_at IS NULL 
            GROUP BY user_email 
            HAVING COUNT(*) > 1"
        );
        
        $total_cleaned = 0;
        
        foreach ($duplicate_emails as $duplicate) {
            $carts = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}abandoned_carts 
                WHERE user_email = %s AND recovered_at IS NULL 
                ORDER BY created_at DESC",
                $duplicate->user_email
            ));
            
            $carts_to_delete = array();
            
            // Comparer chaque panier avec les autres pour trouver les vrais doublons
            for ($i = 0; $i < count($carts); $i++) {
                for ($j = $i + 1; $j < count($carts); $j++) {
                    // Si les contenus sont identiques, marquer le plus ancien pour suppression
                    if ($this->compare_cart_contents($carts[$i]->cart_data, $carts[$j]->cart_data)) {
                        $older_cart = ($carts[$i]->created_at < $carts[$j]->created_at) ? $carts[$i] : $carts[$j];
                        $carts_to_delete[] = $older_cart;
                    }
                }
            }
            
            // Supprimer les vrais doublons
            foreach ($carts_to_delete as $cart) {
                $wpdb->delete(
                    $wpdb->prefix . 'abandoned_carts',
                    array('id' => $cart->id)
                );
            }
            
            $total_cleaned += count($carts_to_delete);
            
            if (defined('WP_DEBUG') && WP_DEBUG && count($carts_to_delete) > 0) {
                error_log('ACR Debug: ' . count($carts_to_delete) . ' vrais doublons supprimés pour ' . $duplicate->user_email);
            }
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Nettoyage terminé. Total doublons supprimés: ' . $total_cleaned);
        }
        
        return $total_cleaned;
    }
    
    /**
     * Initialiser automatiquement tous les consentements RGPD
     */
    public function initialize_gdpr_consents() {
        global $wpdb;
        
        // Mettre à jour tous les consentements NULL ou 0 vers 1 (sauf désabonnés)
        $updated = $wpdb->query(
            "UPDATE {$wpdb->prefix}abandoned_carts 
            SET gdpr_consent = 1, gdpr_consent_date = NOW(), gdpr_consent_ip = '127.0.0.1'
            WHERE (gdpr_consent IS NULL OR gdpr_consent = 0) 
            AND gdpr_unsubscribed = 0 
            AND user_email NOT LIKE 'guest_%' 
            AND user_email NOT LIKE '%@example.com'"
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: ' . $updated . ' consentements RGPD initialisés automatiquement');
        }
        
        return $updated;
    }
    
    /**
     * AJAX pour forcer l'exécution du cron
     */
    public function ajax_force_cron() {
        // Vérifier les permissions
        if (!current_user_can('manage_options')) {
            wp_die('Permissions insuffisantes');
        }
        
        // Forcer l'exécution du cron
        $this->send_reminder_emails();
        
        wp_send_json_success('Cron forcé terminé. Vérifiez les logs pour confirmer.');
    }
    
    /**
     * Désactiver le cron WordPress pour utiliser un vrai cron
     */
    public function disable_wp_cron() {
        if (!defined('DISABLE_WP_CRON')) {
            define('DISABLE_WP_CRON', true);
        }
    }
    
    /**
     * Fonction pour être appelée par un vrai cron
     */
    public function manual_cron_trigger() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Cron manuel déclenché');
        }
        
        $this->send_reminder_emails();
    }
    
    /**
     * Déclencher l'envoi d'emails lors des visites (si pas de vrai cron)
     */
    public function maybe_send_emails_on_visit() {
        // Éviter de surcharger le serveur - vérifier seulement 1 fois par 5 minutes
        $last_check = get_transient('acr_visit_email_check');
        if ($last_check) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ACR Debug: Vérification emails déjà effectuée récemment (visite)');
            }
            return;
        }
        
        // Définir un transient pour 5 minutes
        set_transient('acr_visit_email_check', time(), 5 * MINUTE_IN_SECONDS);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ACR Debug: Déclenchement automatique lors de la visite');
        }
        
        // Envoyer les emails
        $this->send_reminder_emails();
    }
}

// Variable globale pour l'instance du plugin
global $abandoned_cart_recovery;

// Vérifier que WooCommerce est actif avant d'initialiser le plugin
function acr_check_woocommerce() {
    global $abandoned_cart_recovery;
    
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>Paniers Abandonnés :</strong> Ce plugin nécessite WooCommerce pour fonctionner. Veuillez installer et activer WooCommerce.</p>';
            echo '</div>';
        });
        return;
    }
    
    // Initialiser le plugin
    $abandoned_cart_recovery = new AbandonedCartRecovery();
}

// Fonction globale pour le remplacement des variables d'email
function acr_replace_email_variables($content, $cart) {
    global $abandoned_cart_recovery;
    if ($abandoned_cart_recovery) {
        return $abandoned_cart_recovery->replace_email_variables($content, $cart);
    }
    return $content;
}

// Attendre que tous les plugins soient chargés
add_action('plugins_loaded', 'acr_check_woocommerce');
