<?php
/*
Plugin Name: Country Caching Extension
Plugin URI: http://means.us.com
Description: Makes Country GeoLocation work with Comet Cache 
Author: Andrew Wrigley
Version: 1.2.0
Author URI: http://wptest.means.us.com/
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// for update testing (for insertion into currently installed file do not uncomment here) 
/*
require (WP_CONTENT_DIR . '/plugin-update-checker/plugin-update-checker.php');
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
		'http://blog.XXXXXXXX.com/meta_cccomet.json',
		__FILE__,
		'country-caching-extension'
);
*/


// **** THESE CONSTANTS MAY BE CHANGED BY PLUGIN VERSION ****
define('ZC_ADDON_VERSION', '0.4.0');
define('CCZC_UPGRADE_MSG', "Country Caching has been updated with a fix for a Cookie Notice (CN) caching problem reported on their forums."
 . " If using CN you should re-save your CC settings and clear cache.");

// **** CONSTANTS ONLY USED BY THIS PLUGIN ****
define('CCZC_SETTINGS_SLUG', 'cczc-cache-settings');   // THIS SHOULD BE DIFFERENT FOR BUILT-IN AND SEPARATE PLUGINS
define('CCZC_PLUGINDIR', plugin_dir_path(__FILE__));
define('ZC_PLUGINDIR', WP_CONTENT_DIR . '/ac-plugins/');
define('CCZC_ADDON_SCRIPT','cca_qc_geoip_plugin.php' );
define('ZC_ADDON_FILE', ZC_PLUGINDIR . CCZC_ADDON_SCRIPT);
define('CCZC_MAXMIND_DIR', CCZC_PLUGINDIR . 'maxmind/'); // location of the Maxmind script that returns location country code
  if (file_exists(ZC_PLUGINDIR)) {
define('ZC_DIREXISTS',TRUE);
  } else { define('ZC_DIREXISTS',FALSE); }

//  **** CONSTANTS SHARED WITH OTHER PLUGINS ****
if (!defined('CCA_MAXMIND_DATA_DIR')) define('CCA_MAXMIND_DATA_DIR', WP_CONTENT_DIR . '/cca_maxmind_data/');
if (!defined('CCA_MAX_FILENAME')) define('CCA_MAX_FILENAME', 'GeoLite2-Country.mmdb');
if (!defined('CCA_CUST_IPVAR_LINK')) define('CCA_CUST_IPVAR_LINK', '<a href="//wptest.means.us.com/cca-customize-server-var-lookup/" target="_blank">');
if (!defined('CCA_CUST_GEO_LINK')) define('CCA_CUST_GEO_LINK', '<a href="//wptest.means.us.com/cca-customizing-country-lookup/" target="_blank">');


/*  // save error msg to option
function cca_activation_error() { update_option( 'cca_plugin_error',  ob_get_contents() ); }
add_action( 'activated_plugin', 'cca_activation_error' );
*/

add_action( 'admin_init', 'cczc_version_mangement' );
function cczc_version_mangement(){  // credit to "thenbrent" www.wpaustralia.org/wordpress-forums/topic/update-plugin-hook/
	$plugin_info = get_plugin_data( __FILE__ , false, false );
	$last_script_ver = get_option('CCZC_VERSION');
	if (empty($last_script_ver)):
	  // its a new install
	  update_option('CCZC_VERSION', $plugin_info['Version']);
  else:
	   $new_ver = $plugin_info['Version'];
	   $version_status = version_compare( $new_ver , $last_script_ver );
    // can test if script is later {1}, or earlier {-1} than the previous installed e.g. if ($version_status > 0 &&  version_compare( "0.6.3" , $last_script_ver )  > 0) :
		if ($version_status != 0):
		  // this flag ensures the activation function is run on plugin upgrade,
		  update_option('CCZC_VERSION_UPDATE', true);
      update_option('CCZC_VERSION', $new_ver);
		endif;
	endif;

  if (get_option('CCZC_VERSION_UPDATE')) :  // set just now, or previously set and not yet unset by plugin
    if (is_multisite()):
      add_action( 'network_admin_notices', 'cczc_upgrade_notice' );
    else: 
      add_action( 'admin_notices', 'cczc_upgrade_notice' );
    endif;
  endif;
}


// add_actiom applied by  version check
function cczc_upgrade_notice(){
	if (is_multisite()):
	   $admin_suffix = 'network/admin.php?page=' . CCZC_SETTINGS_SLUG;
	else:
	   $admin_suffix = 'admin.php?page=' . CCZC_SETTINGS_SLUG;
	endif;
  echo '<div class="notice notice-warning"><p>' . CCZC_UPGRADE_MSG . ' <a href="' . admin_url($admin_suffix) . '">Dismiss message and check settings.</a></p></div>';
}


if( is_admin()  ){
  define('CCZC_CALLING_SCRIPT', __FILE__);
  if ( ! class_exists('CCAmaxmindUpdate') ) include(CCZC_PLUGINDIR . 'inc/update_maxmind.php');
  include_once(CCZC_PLUGINDIR . 'inc/cczc_settings_form.php');
}
