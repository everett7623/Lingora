<?php
/**
 * Plugin Name:       Lingora
 * Plugin URI:        https://everettlabs.dev
 * Description:       AI-native multilingual engine for WordPress. Translate the live site with OpenAI, Claude, DeepSeek, Gemini, Grok, Dots AI and OpenAI-compatible gateways.
 * Version:           0.0.10
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            everettlabs
 * Author URI:        https://everettlabs.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lingora
 * Domain Path:       /languages
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LINGORA_VERSION', '0.0.10' );
define( 'LINGORA_FILE', __FILE__ );
define( 'LINGORA_PATH', plugin_dir_path( __FILE__ ) );
define( 'LINGORA_URL', plugin_dir_url( __FILE__ ) );
define( 'LINGORA_BASENAME', plugin_basename( __FILE__ ) );

require_once LINGORA_PATH . 'includes/class-plugin.php';

function lingora(): Lingora_Plugin {
	return Lingora_Plugin::instance();
}

register_activation_hook( __FILE__, array( 'Lingora_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Lingora_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
	lingora()->boot();
} );
