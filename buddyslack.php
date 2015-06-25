<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              http://www.davidbisset.com
 * @since             1.0.0
 * @package           Buddyslack
 *
 * @wordpress-plugin
 * Plugin Name:       BuddySlack
 * Plugin URI:        http://www.davidbisset.com/buddyslack
 * Description:       Have BuddyPress activites posted to a Slack channel, private group, or user (via direct messages).
 * Version:           1.0.0
 * Author:            David Bisset
 * Author URI:        http://www.davidbisset.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       buddyslack
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-buddyslack-activator.php
 */
function activate_buddyslack() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-buddyslack-activator.php';
	Buddyslack_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-buddyslack-deactivator.php
 */
function deactivate_buddyslack() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-buddyslack-deactivator.php';
	Buddyslack_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_buddyslack' );
register_deactivation_hook( __FILE__, 'deactivate_buddyslack' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-buddyslack.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_buddyslack() {

	$plugin = new Buddyslack();
	$plugin->run();

}
run_buddyslack();
