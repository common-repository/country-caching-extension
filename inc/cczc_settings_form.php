<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

$cc_networkadmin = is_network_admin() ? 'network_admin_' : '';
add_filter( $cc_networkadmin . 'plugin_action_links_' . plugin_basename( CCZC_CALLING_SCRIPT ), 'cczc_add_sitesettings_link' );
function cczc_add_sitesettings_link( $links ) {
	if (is_multisite()):
	   $admin_suffix = 'network/admin.php?page=' . CCZC_SETTINGS_SLUG;
	else:
	   $admin_suffix = 'admin.php?page=' . CCZC_SETTINGS_SLUG;
	endif;
	return array_merge(	array('settings' => '<a href="' . admin_url($admin_suffix) . '">Caching Settings</a>'),	$links	);
}


// ensure CSS for dashboard forms is sent to browser
add_action('admin_enqueue_scripts', 'CC_load_admincssjs');
function CC_load_admincssjs() {
  if( (! wp_script_is( 'cca-textwidget-style', 'enqueued' )) && $GLOBALS['pagenow'] == 'admin.php' ): wp_enqueue_style( 'cca-textwidget-style', plugins_url( 'css/cca-textwidget.css' , __FILE__ ) ); endif;
}


// messages only shown on CC settings page
function cc_admin_notices_action() {  // unlike add_options_page when using add_menu_page the settings api does not automatically display these messages
    settings_errors( 'geoip_group' );
}
if (is_multisite()): add_action( 'network_admin_notices', 'cc_admin_notices_action' );
else: add_action( 'admin_notices', 'cc_admin_notices_action' );
endif;


function cczc_return_permissions($item) {
   clearstatcache(true, $item);
   $item_perms = @fileperms($item);
return empty($item_perms) ? '' : substr(sprintf('%o', $item_perms), -4);	
}


// instantiate instance of CCZCcountryCache
$cczc_settings_page = new CCZCcountryCache();

//======================
class CCZCcountryCache {  // everything below this point this class
//======================
  private $initial_option_values = array(
	  'activation_status' => 'new',
		'caching_mode' => 'none',
		'cache_iso_cc' => '',
		'use_group' => FALSE,
		'my_ccgroup' => "BE,BG,CZ,DK,DE,EE,IE,GR,ES,FR,HR,IT,CY,LV,LT,LU,HU,MT,NL,AT,PL,PT,RO,SI,SK,FI,SE,EU,GB",
		'diagnostics' => FALSE,
		'initial_message'=> ''
	);

	public $options = array();
  public $user_type;
  public $submit_action;
	public $maxmind_status = array();
  public $is_plugin_update = FALSE;

  public function __construct() {
	  register_activation_hook(CCZC_CALLING_SCRIPT, array( $this, 'CCZC_activate' ) );
		register_deactivation_hook(CCZC_CALLING_SCRIPT, array( $this, 'CCZC_deactivate'));

		// Maxmind data is used by a variety of plugins so we now store its location etc in an option
    $this->maxmind_status = get_option('cc_maxmind_status' , array());

		if (empty($this->options)) $this->options = $this->initial_option_values;
		// retreive/build CC plugin settings
  	if ( get_option ( 'cczc_caching_options' ) ) :
  	  $this->options = get_option ( 'cczc_caching_options' );
		  if (empty($this->options['caching_mode'])) : $this->options['caching_mode'] = 'none'; endif;
      if (empty($this->options['cca_maxmind_data_dir']) ):  $this->options['cca_maxmind_data_dir'] = CCA_MAXMIND_DATA_DIR; endif;
  	endif;
		update_option( 'cczc_caching_options', $this->options );

		// the activation hook does not fire on plugin update, flag it, so we can call it below
		$this->is_plugin_update = get_option( 'CCZC_VERSION_UPDATE' );
		// whenever there is a plugin upgrade we want to check the sanity of existing Maxmind data and rebuild the Zen Cache add-on script as there may be logic changes 
		// we don't want the user to have to manually re-install Maxmind data if there is a change on plugin update
    if ($this->is_plugin_update || empty($this->options['cca_maxmind_data_dir']) || $this->options['cca_maxmind_data_dir'] != CCA_MAXMIND_DATA_DIR):
				$this->options['cca_maxmind_data_dir'] = CCA_MAXMIND_DATA_DIR;
        $this->CCZC_activate();
    endif;

    if (is_multisite() ) :
     	 $this->user_type = 'manage_network_options';
       add_action( 'network_admin_menu', array( $this, 'add_plugin_page' ) ); 
    	$this->submit_action = "../options.php";
    else:
    	$this->user_type = 'manage_options';
      add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
    	$this->submit_action = "options.php";
    endif;

    add_action( 'admin_init', array( $this, 'page_init' ) );
  }  // end "constructor"


	// REMOVE THE ZEN CACHE EXTENSION SCRIPT ON DEACTIVATION
  public function CCZC_deactivate()   {
		// brute force delete add-on script from all possible known locations
    if (defined('ZC_PLUGINDIR') && validate_file(ZC_PLUGINDIR) === 0) @unlink(ZC_ADDON_FILE);
		$this->options['activation_status'] = 'deactivated';
    update_option( 'cczc_caching_options', $this->options );
  }


	// function run on ACTIVATE or NEW VERSION
	public function CCZC_activate() {

	  $this->options['initial_message'] = '';
//		if ( $this->is_plugin_update || ( $this->options['caching_mode'] == 'QuickCache' && (! file_exists(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME) || @filesize(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME) < 800000)) ) :
		if ( $this->options['caching_mode'] == 'QuickCache' && (! file_exists(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME) || @filesize(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME) < 800000) ) :
      // caching was enabled before deactivation, rebuild Maxmind directory if in error or location has changed
		  $do_max = new CCAmaxmindUpdate();
			$success = $do_max->save_maxmind($this->is_plugin_update); // if run due to plugin update user won't see error msg, so set to email on failure
			$this->maxmind_status = $do_max->get_max_status();
			if (! $success):
		    $this->options['initial_message'] .= __('There was a problem installing the Maxmind2 mmdb file. Click the "Configuration & Support" tab for more info.<br>');
		  endif;
 			unset($do_max);
    endif;

	  // The add-on script used by Comet is removed on deactivation so we need to rebuild it from stored settings,
		// we also rebuild it on plugin upgrade in case of logic changes
    if ( $this->options['caching_mode'] == 'QuickCache') :
		    $this->options['activation_status'] = 'activating';
  			if (empty($this->options['use_group'])) : 
				  $this->options['use_group'] = FALSE;
				  if (empty($this->options['my_ccgroup'])) : $this->options['my_ccgroup'] = "BE,BG,CZ,DK,DE,EE,IE,GR,ES,FR,HR,IT,CY,LV,LT,LU,HU,MT,NL,AT,PL,PT,RO,SI,SK,FI,SE,GB"; endif;
				endif;
				$script_update_result = $this->cczc_build_script( $this->options['cache_iso_cc'],$this->options['use_group'],$this->options['my_ccgroup']);
  			if ( empty($this->options['last_output_err']) ) :
  			  $script_update_result = $this->cczc_write_script($script_update_result, $this->options['cache_iso_cc'],$this->options['use_group'],$this->options['my_ccgroup']);
  			endif;
    	  if ( $script_update_result != 'Done' ) :
   		     $this->options['initial_message'] .=  __('You have reactivated this plugin - however there was a problem rebuilding the Comet Cache add-on script: ') . $script_update_result . ')';
    	  else : 
  			  $this->options['initial_message']  .= __('Country Caching is activated, and the add-on script for Comet Cache appears to have been built successfully');
  			endif;
    endif;

    delete_option( 'CCZC_VERSION_UPDATE' );
		$this->options['activation_status'] = 'activated';
		update_option( 'cczc_caching_options', $this->options );
 }  // end activate



// Add Country Caching options page to Dashboard->Settings
  public function add_plugin_page() {
    add_menu_page(
          'Country Caching Settings', /* html title tag */
          'Country Caching', // title (shown in dash->Settings).
          $this->user_type, // 'manage_options', // min user authority
          CCZC_SETTINGS_SLUG, // page url slug
          array( $this, 'create_cczc_site_admin_page' ),  //  function/method to display settings menu
  				'dashicons-admin-plugins'
    );
  }

// Register and add settings
  public function page_init() {        
    register_setting(
      'geoip_group', // group the field is part of 
    	'cczc_caching_options',  // option prefix to name of field
			array( $this, 'sanitize' )
    );
  }


// callback func specified in add_options_page func
  public function create_cczc_site_admin_page() {
	  if ( $this->is_plugin_update) delete_option( 'CCZC_VERSION_UPDATE' );
		if ( ! function_exists('cca_qc_salt_shaker') && is_readable(ZC_ADDON_FILE) ) @include(ZC_ADDON_FILE);
		// if site is not using Cloudflare GeoIP warn if Maxmind data is not installled
		if ( empty($_SERVER["HTTP_CF_IPCOUNTRY"]) && ! file_exists(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME) ) :
		  $this->options['initial_message'] .= __('Maxmind "IP to Country look-up V2" data file needs to be installed. It will be installed automatically from Maxmind if the "Enable CC" check box is checked and you click "Save Settings". This may take a few seconds.<br>'); 
    endif;

		  // render the settings form
?>  <div class="wrap cca-cachesettings">  
      <div id="icon-themes" class="icon32"></div> 
      <h2>Country Caching</h2>  
<?php 
    if (!empty($this->options['initial_message'])) echo '<div class="cca-msg">' . $this->options['initial_message'] . '</div>';
    $this->options['initial_message'] = '';
		// determine which tab to display
    $active_tab = isset( $_GET[ 'tab' ] ) ? $_GET[ 'tab' ] : 'QuickCache';
		$override_tab = empty($this->options['override_tab']) ? '' : $this->options['override_tab'];
		if ($override_tab == 'files') :
		  $active_tab = 'files';
		endif;
?>
      <h2 class="nav-tab-wrapper">  
         <a href="?page=<?php echo CCZC_SETTINGS_SLUG ?>&tab=QuickCache" class="nav-tab <?php echo $active_tab == 'QuickCache' ? 'nav-tab-active' : ''; ?>">Comet Cache</a>  
<a href="?page=<?php echo CCZC_SETTINGS_SLUG ?>&tab=CNusers" class="nav-tab <?php echo $active_tab == 'CNusers' ? 'nav-tab-active' : ''; ?>">Cookie Notice users</a>  
         <a href="?page=<?php echo CCZC_SETTINGS_SLUG ?>&tab=Configuration" class="nav-tab <?php echo $active_tab == 'Configuration' ? 'nav-tab-active' : ''; ?>">Configuration &amp; Support</a>
<?php if ( $active_tab == 'files' || ! empty($override_tab) ) : ?>
          <a href="?page=<?php echo CCZC_SETTINGS_SLUG ?>&tab=files" class="nav-tab <?php echo $active_tab == 'files' ? 'nav-tab-active' : ''; ?>">Dir &amp; File Settings</a>  
<?php endif; ?>
      </h2> 
      <form method="post" action="<?php echo $this->submit_action; ?>">  
<?php 
      settings_fields( 'geoip_group' );
  		if( $active_tab == 'Configuration' ) :
   			 $this->render_config_panel();
   		elseif ($active_tab == 'CNusers'):
   		    $this->render_cookienotice_panel();
	 		elseif ( $active_tab == 'files' ) :
			   $this->render_file_panel();
  		else : $this->render_qc_panel();
  		endif;
?>             
      </form> 
    </div> 
<?php
     update_option( 'cczc_caching_options', $this->options );

  }  // END create_cczc_site_admin_page()


  public function render_qc_panel() {
?>
		<div class="cca-brown"><p><?php echo $this->cczc_qc_status();?></p></div>

    <hr /><h3>Country caching for Comet Cache (CC)</h3>
		<p><input type="checkbox" id="cczc_use_qc" name="cczc_caching_options[caching_mode]" <?php checked($this->options['caching_mode']=='QuickCache'); ?>><label for="cczc_use_qc">
		 <?php _e('Enable CC Country Caching add-on'); ?></label></p>

  	<h3><?php _e('Minimise country caching overheads'); ?></h3>
		<?php _e('Create separate caches for these country codes ONLY:'); ?>
		<input name="cczc_caching_options[cache_iso_cc]" type="text" value="<?php echo $this->options['cache_iso_cc']; ?>" />
		<i>(<?php _e('e.g.');?> "CA,DE,AU")</i>
		<p><i><?php _e('Example 1: if you set the field to "CA,AU", separate cache will only be created for Canada and for Australia; "standard" page cache will be used for all other visitors.');?>.<br>
    <?php _e("If left empty and group cache below is not enabled, then a cached page will be generated for every country from which you've had one or more visitors.");?></i></p>



		<h3><?php _e('AND/OR create a single cache for this group of countries'); ?></h3>
		<p><input type="checkbox" id="cczc_use_group" name="cczc_caching_options[use_group]" <?php checked(!empty($this->options['use_group']));?>><label for="cczc_use_group">
<?php _e('Check this box to use a single cache for this group of country codes'); ?></label></p>
<?php if (empty($this->options['my_ccgroup'])):
   $this->options['my_ccgroup'] = "BE,BG,CZ,DK,DE,EE,IE,GR,ES,FR,HR,IT,CY,LV,LT,LU,HU,MT,NL,AT,PL,PT,RO,SI,SK,FI,SE,GB";
endif;
?>
	  <div class="cca-indent20">
  	  <input id="cczc_my_ccgroup" name="cczc_caching_options[my_ccgroup]" type="text" style="width:600px !important" value="<?php echo $this->options['my_ccgroup']; ?>" />
  		  <br><?php _e("Replace with your own list. (Initially contains European Union countries, but no guarantee it is accurate.)");  ?>
		</div>
<p><i><?php _e('Example 2: You want everyone sees your US content except visitors from France & Canada. For legal compliance you also display a cookie notice when visitors are from the EU'); ?>
<?php _e('<br><b>How:</b> set the plugin to separately cache "FR,CA", ensure the group box contains all EU codes; and enable shared caching.'); ?></i></p>
<p><i><?php _e('Example 3: If you only want 2 separate caches one for Group and one for NOT Group e.g. EU and non-EU: ');
 _e('insert "AX" in the "create unique cache" box, ensure group box contains all EU codes, and enable shared caching.');
 _e( '<br>Result: one cache for EU visitors, a cache for AX (if you ever get a visitor from Aland Islands), ');
 _e( 'and one standard cache seen by your non-EU visitors.'); ?></i></p>

		<input type="hidden" id="cczc_geoip_action" name="cczc_caching_options[action]" value="QuickCache" />
    <?php
      if( $this->using_cloudflare_or_max_already() ):
			  _e('<br><p>This plugin includes GeoLite data created by MaxMind, available from <a href="http://www.maxmind.com">http://www.maxmind.com</a>.</p>');
			endif;
			submit_button('Save Caching Settings','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 
  }  // END render_qc_panel()


  // render info panel for cokkie notice users 
  public function render_cookienotice_panel() {
  ?>
  <p><b>As well as Country/EU geolocation, this plugin also makes Cookie Notice work correctly with Comet Cache.<br>
  (read about the issue in these Cookie Notice support forum posts: <a href=https://dfactory.eu/support/topic/caching-and-and-wrong-cookie-state-for-visitor/" target="_blank">Dfactory</a> and 
  <a href="https://wordpress.org/support/topic/compatible-with-w3-total-cache-9/#post-10366731" target="_blank">wordpress.org</a>)</b></p>
  <hr><h3>If you are NOT using country or EU geolocation:</h3>
  <p>On the settings tab:</p>
  <p class="cca-indent20">Tick the <i>Enable Country Caching</i> check box.</p>
  <p class="cca-indent20">Enter "XX" in the box labeled "<i>Create unique cache for these country codes ONLY</i>". <span class="cca-brown">(XX prevents caching by country)</span></p>
  <p class="cca-indent20">Save these settings.</p>
  <p>Clear your cache. From now on, WPSC will create 2 cached versions (cookies allowed & cookies refused) of a page: and in future your visitors will be served the correct version for their settings.</p><br>
  <hr><h3>If you are also using the CCA plugin to prevent Cookie Notice running for non EU countries:</h3>  
  <p>On the settings tab:</p>
  <p class="cca-indent20">Tick the <i>Enable Country Caching</i> check box.</p>
  <p class="cca-indent20">Enter "XX" in the box labeled "<i>Create unique cache for these country codes ONLY</i>". <span class="cca-brown">(unless you are also using country geolocation for other purposes)</span></p>
  <p class="cca-indent20">Tick the <i>Enable shared caching for this group</i> check box.</p>
  <p class="cca-indent20">Ensure the box below it contains the complete list of EU/EEA country codes</p>
  <p class="cca-indent20">Save these settings.</p>
  <p>Clear your cache. From now on, WPSC will create cached pages for non-EU, EU cookies allowed & EU cookies refused; and your visitors will be served the correct version for their settings.</p>
  <?php
  }  //  END function render_cookienotice_panel


 // This panel is only visible if the plugin was unable to write the add-on script.
 // it provides diagnostic info + an option to download generated add-on for manual upload
  public function render_file_panel() { 

    if ($this->options['override_tab'] == 'files'):
       $this->options['override_tab']  = 'io_error';
    endif;

	  echo '<p>'  . __('This tab is only visible if the CC plugin was unable to write the generated Comet add-on script (') .  CCZC_ADDON_SCRIPT . __(') to "') . '<span class="cca-brown">' . ZC_PLUGINDIR . '</span>" (';
    echo __('the folder where Comet expects to find any add-on scripts') . ').</p>';
	  echo '<p>' . __('This problem is probably due to "non-standard" Directory and File permissions settings on your server preventing either creation of "ac-plugins" dir (if not present)');
		echo __(' or the writing of this plugins add-on file. It might be solved by:') . '</p>';
	  echo '<div class="cca-indent20"><ul><li>' . __('Changing your directory ("wp-content/ac-plugins" and/or  "wp-content/") permissions. ');
	  echo __('On a shared server appropriate  permissions for these are usually "755" and "644" for files; but on a <b>"dedicated" server</b> "775" might be needed and "664" for the script (if present)') . '.</li>';
 		echo '<li>' . __("If 'ac-plugins' dir does not already exist and this plugin STILL won't create it; then you may need to manually create it, with suitable permissions, as a sub-directory of 'wp-content'. ");
		echo __("The brown text above identifies the relevant directory path for your server.") . "</li></ul><br><p><b>After altering your permissions, try saving this plugin's settings again.</b></p></div>";

    echo '<hr /><h4>' . __('Information about current directory &amp; file permissions') . ':</h4>';
    if (!empty($this->options['last_output_err'])):
        echo '<span class="cca-brown">' . __('Last reported error: ') . ':</span> ' . $this->options['last_output_err'] . '<br>';
    endif;
    echo '<span class="cca-brown">' . __('Directory "wp-content"') . ':</span> ' . __('permissions = ') . cczc_return_permissions(WP_CONTENT_DIR) . '<br>';
    echo '<span class="cca-brown">' . __('Directory "') . ZC_PLUGINDIR . '" :</span> ' . __('permissions = ') . cczc_return_permissions(ZC_PLUGINDIR) . '<br>';
    echo '<span class="cca-brown">Permissions for add-on script "' . CCZC_ADDON_SCRIPT . '": </span>' . cczc_return_permissions(ZC_ADDON_FILE) . '<br>';
		clearstatcache();
    $dir_stat = @stat(WP_CONTENT_DIR);
    if (function_exists('posix_getuid') && function_exists('posix_getpwuid') && function_exists('posix_geteuid')
         && function_exists('posix_getgid') && function_exists('posix_getegid') && $dir_stat) :
      $real_process_uid  = posix_getuid(); 
      $real_process_data =  posix_getpwuid($real_process_uid);
      $real_process_user =  $real_process_data['name'];
    	$real_process_group = posix_getgid();
      $real_process_gdata =  posix_getpwuid($real_process_group);
      $real_process_guser =  $real_process_gdata['name'];	
      $e_process_uid  = posix_geteuid(); 
      $e_process_data =  posix_getpwuid($e_process_uid);
      $e_process_user =  $e_process_data['name'];
    	$e_process_group = posix_getegid();
      $e_process_gdata =  posix_getpwuid($e_process_group);
      $e_process_guser =  $e_process_gdata['name'];	
    	$dir_data =  posix_getpwuid($dir_stat['uid']);
    	$dir_owner = $dir_data['name'];
    	$dir_gdata =  posix_getpwuid($dir_stat['gid']);
    	$dir_group = $dir_gdata['name'];
      echo '<span class="cca-brown">' . __('This plugin is being run by "real user":') . '</span> ' . $real_process_user . ' (UID:' . $real_process_uid . ') Group: ' . $real_process_guser .' (GID:' . $real_process_group . ') N.B. this user may also be a member of other groups.<br>'; 
    	echo '<span class="cca-brown">' . __('The effective user is: ') . '</span>' . $e_process_user . ' (UID:' . $e_process_uid . ' GID:' . posix_getegid() . ')<br>'; 
      echo '<span class="cca-brown">' . __('"wp-content" directory') . '</span>: ' . __('Owner = ') . $dir_data['name'] . ' (UID:' . $dir_stat['uid'] . ') | Group = ' .  $dir_group . ' (GID:' . $dir_stat['gid'] . ')<br>';
      unset($dir_stat);
      $dir_stat = @stat(ZC_PLUGINDIR);
    	$dir_data =  @posix_getpwuid($dir_stat['uid']);
      if ( $dir_stat ) :
        echo '<span class="cca-brown">' . __('ZC "add-on" directory') . '</span>: ' . __('Owner = ') . $dir_data['name'] . ' (UID:' . $dir_stat['uid'] . ') | Group = ' .  $dir_group . ' (GID:' . $dir_stat['gid'] . ')<br><hr />';
    	endif;
    else:
      __('Unable to obtain information on the plugin process owner(user).  Your server might not have the PHP posix extension (installed on the majority of Linux servers) which this plugin uses to get this info.') . '<br><hr />';
    endif;
?>
<!-- 		<input type="hidden" id="cczc_geoip_action" name="cczc_caching_options[action]" value="download" /> -->
<?php
  }


// Panel to display Diagnostic Information with option to reset the plugin 
  public function render_config_panel() {
?>
    <p class="cca-brown"><?php _e('View ');?> <a href="http://wptest.means.us.com/quick-cache-and-geoip-enable-caching-by-pagevisitor-country-instead-of-just-page/" target="_blank"><?php _e('Country Caching Guide');?></a>.</p>

		<hr /><h3>Problem Fixing</h3>
    <p><input id="cczc_force_reset" name="cczc_caching_options[force_reset]" type="checkbox"/>
    <label for="cczc_force_reset"><?php _e("Reset Country Caching to initial values (also removes the country caching add-on script(s) generated for Comet Cache).");?></label></p><hr />

		<h3>Information about the add-on script being used by Comet Cache:</h3>
		<p><input type="checkbox" id="cczc_addon_info" name="cczc_caching_options[addon_data]" ><label for="cczc_addon_info">
 		  <?php _e('Display script data'); ?></label></p>
<?php
		if ($this->options['addon_data']) :
			$this->options['addon_data'] = '';
			clearstatcache(true, ZC_ADDON_FILE);
			if ( ! file_exists(ZC_ADDON_FILE) ) :
			  echo '<br><span class="cca-brown">' . __('The Add-on script does not exist.') . '</span><br>';
			else:		
 			  if ( function_exists('cca_qc_salt_shaker') ):
				  $add_on_ver = cca_qc_salt_shaker('cca_version');
					echo '<span class="cca-brown">' . __('Add-on script version: ') . '</span>' . esc_html($add_on_ver) . '<br>';
  				$new_codes = cca_qc_salt_shaker('cca_options');
					$valid_codes = $this->is_valid_ISO_list($new_codes);
          if ($valid_codes):
          	  echo '<span class="cca-brown">' . __('The script is set to separately cache') .  '</span> "<u>';
          		if (empty($new_codes) ):
          			 echo  __('all countries') . '</u>".<br>';
          		else:
          			  echo $new_codes . '</u>"; ' .  __('the standard cache will be used for all other countries.') . '<br>';
          		endif;
           elseif (substr($new_codes, 0, 11) == 'cca_options') :  // addons created by previous plugin versions do not recognise 'options' and will simply return a string starting with 'options'
					    echo  __('The add-on script was created by a previous version of the Country Caching plugin.<br>It will work, but the latest version will show you (here) which Countries it is set to cache') . '<br>';
							echo __('You can update to the latest add-on script by saving settings again on the "Comet Cache" tab.<br>');
					 else:
					    echo  __('Add-on script "') . CCZC_ADDON_SCRIPT . __(' is present in "') . ZC_PLUGINDIR . __('" but has an INVALID Country Code List (values: "') . esc_html($new_codes) . __('") and should be deleted.') . '<br /';
  				 endif;
				 else:
					  echo  __('The add-on script "') . CCZC_ADDON_SCRIPT . __(' is present in "') . ZC_PLUGINDIR . __('" but I am unable to identify its country settings.')  . '<br>';
				 endif;

         $new_codes = cca_qc_salt_shaker('cca_group');
         if (! empty($new_codes) ):
          	 if (substr($new_codes, 0,9 ) == 'cca_group') :  // addons created by previous plugin versions do not recognise 'options' and will simply return a string starting with 'options'
          			echo  __('The add-on script was created by a previous version of the Country Caching plugin.<br>It will work, but the latest version allows you to cache countries as a group') . '<br>';
          			echo __('You can update to the latest add-on script by saving settings again on the "Comet Cache" tab.<br>');
          	 elseif ($this->is_valid_ISO_list($new_codes)):
          	    echo '<span class="cca-brown">' . __('The script is set to create a single cache for this group of countries:') .  '</span> ' . $new_codes . '<br>';
             endif;
          endif;

					$max_dir = cca_qc_salt_shaker('cca_data');
					if ($max_dir != 'cca_data'):
					  echo __('The script looks for Maxmind data in "') . esc_html($max_dir) . '".<br>';
					endif;
			 endif;
		endif;

?>
		<h3>GeoIP Information and Status:</h3>
		<p><input type="checkbox" id="cczc_geoip_info" name="cczc_caching_options[geoip_data]" ><label for="cczc_geoip_info">
 		  <?php _e('Display GeoIP data'); ?></label></p>
<?php
		if ($this->options['geoip_data']) :
			$this->options['geoip_data'] = '';
			if (! function_exists('cca_run_geo_lookup') ) include CCZC_MAXMIND_DIR . 'cca_lookup_ISO.inc';
			if ( ! isset($GLOBALS['CCA_ISO_CODE']) || empty($GLOBALS['cca-lookup-msg'])) : // then lookup has not already been done by another plugin
			  cca_run_geo_lookup(CCZC_MAXMIND_DIR); // sets global CCA_ISO_CODE and status msg
			endif;
			// as GLOBALS can be set by any process we need to sanitize/format before use
			if (! ctype_alnum($GLOBALS["CCA_ISO_CODE"]) || ! strlen($GLOBALS["CCA_ISO_CODE"]) == 2) $_SERVER["CCA_ISO_CODE"] = "";
			$lookupMsg = str_replace('<CCA_CUST_IPVAR_LINK>', CCA_CUST_IPVAR_LINK, $GLOBALS['cca-lookup-msg']);
			$lookupMsg = str_replace('<CCA_CUST_GEO_LINK>', CCA_CUST_GEO_LINK, $lookupMsg);
?>

<p class="cca-brown">You appear to be located in <i>(or CCA preview mode is)</i> <b>"<?php echo $GLOBALS["CCA_ISO_CODE"];?>"</b>
<br><?php echo $lookupMsg;?></p>

<br><hr><p><b>Your Server's IP Address Variables (for info only):</b></p>
<?php
      foreach (array('HTTP_X_REAL_IP', 'REMOTE_ADDR', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED','HTTP_CF_CONNECTING_IP') as $key):
        echo "<hr><p><b>$key</b>: ";  
      	if (empty($_SERVER[$key])):
      	  echo 'is empty or not set</p>';
      		continue;
      	endif;
        $possIP = $_SERVER[$key];
        echo htmlspecialchars($possIP);
      	$ip = explode(',', $possIP);
        if (count($ip) > 1):  // its a comma separated list of enroute IPs
      	  echo '<br>&nbsp;&nbsp;a check of the first item indicates'; 
        endif;
        if ($ip[0] != '127.0.0.1' && filter_var(trim($ip[0]), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) !== false) :
      	  echo ' <i>it appears to be a valid IP address</i>';
        else :
      	  echo ' <i>it look like an invalid or private IP address</i>';
      	endif;
      endforeach;
			echo '<br><hr><hr>';

		endif;
?>
		<h3>Information useful for support requests:</h3>
		<p><input type="checkbox" id="cczc_diagnostics" name="cczc_caching_options[diagnostics]" ><label for="cczc_diagnostics">
 		  <?php _e('List plugin values/Maxmind Health/File Permissions'); ?></label></p>
<?php
		if ($this->options['diagnostics']) :
			$this->options['diagnostics'] = '';
		  echo '<br><span class="cca-brown">This CC plugin version: </span>' . get_option('CCZC_VERSION') . '<br>';
		  echo '<h4><u>Comet Cache Status:</u></h4>';
			echo '<div class="cca-brown">' . $this->cczc_qc_status() . '</div>';


			echo '<hr /><h4><u>Maxmind Data status:</u></h4>';
			if (file_exists(CCA_MAXMIND_DATA_DIR)):
				 echo 'Maxmind Directory: "' . CCA_MAXMIND_DATA_DIR . '"<br>';
				if (file_exists(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME)): 
				    echo __('File "') . CCA_MAX_FILENAME . __('" last successfully updated : ') . date("F d Y H:i:s.",filemtime(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME)) . '<br>';
				 else: 
					  echo '<span class="cca-brown">' . __('Maxmind look-up file "') . CCA_MAX_FILENAME . __('" could not be found. ');
						if (file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIP.dat') || file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIPv6.dat')): 
						  echo __('Out of date Maxmind Legacy files have been found and will be used for geolocation.') . '<br>';
						else:
						  echo __('Maxmind geolocation will not be functioning.') . '<br>';
						endif;
						echo __('Ensure "Enable CC Country Caching add-on" is checked ("Comet Cache" tab) and then save settings'). '</span><br>';
				 endif;
			 else:
					echo '<span class="cca-brown">' . __('The Maxmind Directory ("') . CCA_MAXMIND_DATA_DIR . '") ' . __('does not exist. Maxmind Country GeoLocation will not be working.')  . '</span><br>';
			 endif;

			 if (! empty($this->maxmind_status['health'] ) && $this->maxmind_status['health'] != 'ok'):
			 		echo '<p>' . __('The last update process reported a problem: ') . ' <span class="cca-brown">' . $this->maxmind_status['result_msg'] . '</span>"</p>';
			 endif; 
			 echo '<hr>';

      echo '<h4><u>Constants:</u></h4>';
      echo '<span class="cca-brown">ZC_PLUGINDIR = </span>'; echo defined('ZC_PLUGINDIR') ? ZC_PLUGINDIR : 'not defined';
      echo '<br><span class="cca-brown">ZC_DIREXISTS = </span>'; echo (defined('ZC_DIREXISTS') && ZC_DIREXISTS) ? 'TRUE' : 'FALSE';
      echo '<br><span class="cca-brown">CCZC_ADDON_SCRIPT = </span>'; echo defined('CCZC_ADDON_SCRIPT') ? CCZC_ADDON_SCRIPT : 'not defined';

      echo '<h4><u>Variables:</u></h4>';
		  $esc_options = esc_html(print_r($this->options, TRUE ));  // option values from memory there is a slim chance stored values will differ
		  echo '<span class="cca-brown">' . __("Current setting values") . ':</span>' . str_replace ( '[' , '<br> [' , print_r($esc_options, TRUE )) . '</p>';

      echo '<hr><h4><u>' . __('File and Directory Permissions') . ':</u></h4>';
			$lastFileErr = empty($this->options['last_output_err']) ? 'none' : $this->options['last_output_err'];
      echo '<span class="cca-brown">' . __('Last file/directory error: ') . '</span> ' . $lastFileErr . '<br>';
			clearstatcache();
      $dir_stat = @stat(WP_CONTENT_DIR);
      if (function_exists('posix_getuid') && function_exists('posix_getpwuid') && function_exists('posix_geteuid')
           && function_exists('posix_getgid') && function_exists('posix_getegid') && $dir_stat) :
        $real_process_uid  = posix_getuid(); 
        $real_process_data =  posix_getpwuid($real_process_uid);
        $real_process_user =  $real_process_data['name'];
      	$real_process_group = posix_getgid();
        $real_process_gdata =  posix_getpwuid($real_process_group);
        $real_process_guser =  $real_process_gdata['name'];	
        $e_process_uid  = posix_geteuid(); 
        $e_process_data =  posix_getpwuid($e_process_uid);
        $e_process_user =  $e_process_data['name'];
      	$e_process_group = posix_getegid();
        $e_process_gdata =  posix_getpwuid($e_process_group);
        $e_process_guser =  $e_process_gdata['name'];	
      	$dir_data =  posix_getpwuid($dir_stat['uid']);
      	$dir_owner = $dir_data['name'];
      	$dir_gdata =  posix_getpwuid($dir_stat['gid']);
      	$dir_group = $dir_gdata['name'];
        echo '<span class="cca-brown">' . __('This plugin is being run by "real user":') . '</span> ' . $real_process_user . ' (UID:' . $real_process_uid . ') Group: ' . $real_process_guser .' (GID:' . $real_process_group . ') N.B. this user may also be a member of other groups.<br>'; 
      	echo '<span class="cca-brown">' . __('The effective user is: ') . '</span>' . $e_process_user . ' (UID:' . $e_process_uid . ' GID:' . posix_getegid() . ')<br>'; 
        echo '<span class="cca-brown">' . __('"wp-content" directory') . '</span>: ' . __('Owner = ') . $dir_data['name'] . ' (UID:' . $dir_stat['uid'] . ') | Group = ' .  $dir_group . ' (GID:' . $dir_stat['gid'] . ')<br>';
        unset($dir_stat);
        $dir_stat = @stat(ZC_PLUGINDIR);
      	$dir_data =  @posix_getpwuid($dir_stat['uid']);
        if ( $dir_stat ) :
          echo '<span class="cca-brown">' . __('ZC "add-on" directory') . '</span>: ' . __('Owner = ') . $dir_data['name'] . ' (UID:' . $dir_stat['uid'] . ') | Group = ' .  $dir_group . ' (GID:' . $dir_stat['gid'] . ')<br>';
      	endif;
      else:
        __('Unable to obtain information on the plugin process owner(user).  Your server might not have the PHP posix extension (installed on the majority of Linux servers) which this plugin uses to get this info.') . '<br>';
      endif; 
      echo '<span class="cca-brown">' . __('"wp-content" folder permissions: </span>') . cczc_return_permissions(WP_CONTENT_DIR) . '<br>';
      echo '<span class="cca-brown">' . __('"ZC add-on\'s folder" ') . ZC_PLUGINDIR . __(' permissions') .'</span>: ' . cczc_return_permissions(ZC_PLUGINDIR) . '<br>';
      echo '<span class="cca-brown">Permissions for add-on script "' . CCZC_ADDON_SCRIPT . '": </span>' . cczc_return_permissions(ZC_ADDON_FILE);

		endif;
?>
		<input type="hidden" id="cczc_geoip_action" name="cczc_caching_options[action]" value="Configuration" />
<?php
      submit_button('Submit','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 
 
  }   // END render_config_panel()


  // validate and save settings fields changes
  public function sanitize( $input ) {
  	$input['action'] = empty($input['action']) ? '' : strip_tags($input['action']);
  	// INITIALISE MESSAGES
  	$settings_msg = '';
  	$msg_type = 'updated';
    $delete_result = '';

    if ($this->options['activation_status'] != 'activated'): return $this->options; endif;  // activation hook carries out its own "sanitizing"

		// if cc enabled check if Comet cache plugin is activated
    if ($input['action'] == 'QuickCache' && ! empty($input['caching_mode']) && empty($GLOBALS['comet_cache_advanced_cache'])  && empty($GLOBALS['zencache_advanced_cache']) ) :
		  add_settings_error('geoip_group',esc_attr( 'settings_updated' ),
       		__("ERROR: Comet Cache extension has not been created in folder '") . ZC_PLUGINDIR . 
					  __("'. Check Comet Cache settings, it is either disabled or not activated.<br>COMET CACHE MUST BE ACTIVATED BEFORE SETTINGS BELOW CAN BE SAVED."),
        	'error'
       	);
      return $this->options;
    endif;


//  PROCESS INPUT FROM  "FILES" TAB (NO INPUT IN THIS VERSION)
		if ($input['action'] == 'files'):  return $this->options; endif;


//  PROCESS INPUT FROM  "MONITORING" TAB
    if ($input['action'] == 'Configuration') :
		  $this->options['diagnostics'] = empty($input['diagnostics']) ? FALSE : TRUE;
		  $this->options['addon_data'] = empty($input['addon_data']) ? FALSE : TRUE;
		  $this->options['geoip_data'] = empty($input['geoip_data']) ? FALSE : TRUE;
			if (! empty($input['force_reset']) ) :
			  update_option('cczc_caching_options',$this->initial_option_values);
				$this->options = $this->initial_option_values;
				$this->options['activation_status'] = 'activated';
		    $delete_result = $this->delete_qc_addon();	
  		  if ($delete_result != ''):
  				$msg_type = 'error';
					$settings_msg = $delete_result;
  			else:
			    $this->options['caching_mode'] = 'none';
					$settings_msg = __('Country caching has been reset to none.<br>');
  				$this->options['cache_iso_cc'] = '';
  			endif;
			endif;
  		if ($settings_msg != '') :
        add_settings_error('geoip_group',esc_attr( 'settings_updated' ), __($settings_msg),	$msg_type	);
      endif;
  		return $this->options;
    endif;


//  RETURN IF INPUT IS NOT FROM "QuickCache" TAB (The QC tab should be the only one not sanitized at this point).
    if ($input['action'] != 'QuickCache'): return $this->options; endif;


//  PROCESS INPUT FROM "QuickCache" TAB

		$cache_iso_cc = empty($input['cache_iso_cc']) ? '' : strtoupper(trim($input['cache_iso_cc']));
		$use_group = empty($input['use_group'] ) ? FALSE : TRUE;
		$my_ccgroup = empty($input['my_ccgroup']) ? '' : strtoupper(trim($input['my_ccgroup']));
		$new_mode = empty($input['caching_mode']) ? 'none' : 'QuickCache';

		// user is not enabling country caching and it wasn't previously enabled
		if ( $new_mode == 'none' && $this->options['caching_mode'] == 'none') :
		  if ( $this->options['cache_iso_cc'] != $cache_iso_cc || $this->options['my_ccgroup'] != $my_ccgroup || $this->options['use_group'] != $use_group && $this->is_valid_ISO_list($cache_iso_cc) && $this->is_valid_ISO_list($my_ccgroup)) :
			  $this->options['cache_iso_cc'] = $cache_iso_cc;
        $this->options['my_ccgroup'] = $my_ccgroup;
        $this->options['use_group'] = $use_group;
        $settings_msg = __("The Country Codes list has been updated; HOWEVER you have NOT ENABLED country caching.") .  '.<br>';
			else :
        $settings_msg .= __("Settings have not changed - country caching is NOT enabled.<br>");
			endif;
			$settings_msg .= __("I'll take this opportunity to housekeep and remove any orphan country caching scripts. ");
		  $settings_msg .= $this->delete_qc_addon();
			add_settings_error('geoip_group',esc_attr( 'settings_updated' ), $settings_msg, 'error' );
      return $this->options;	  
		endif;
			
		$msg_part = '';
		// user is changing to OPTION "NONE" we are disabling country caching and need to remove the QC add-on script
    if ($new_mode == 'none') :
		  $delete_result = $this->delete_qc_addon();	
		  if ($delete_result != ''):
				$msg_type = 'error';
			  $msg_part = $delete_result;
			else:
			  $msg_part = __('Country caching has been disabled.<br>');
        if ( $this->options['cache_iso_cc'] != $cache_iso_cc && $this->is_valid_ISO_list($cache_iso_cc) ) :
        		$this->options['cache_iso_cc'] = $cache_iso_cc;
        endif;
        if ($this->options['my_ccgroup'] != $my_ccgroup && $this->is_valid_ISO_list($my_ccgroup) ) :
          $this->options['my_ccgroup'] = $my_ccgroup;
        endif;
        $this->options['use_group'] = $use_group;
				$this->options['caching_mode'] = 'none';
			endif;
			$settings_msg = $msg_part . $settings_msg;

		// else using ZEN/QUICK CACHE
    elseif ( $new_mode == 'QuickCache'  && (! $this->is_valid_ISO_list($cache_iso_cc) || ! $this->is_valid_ISO_list($my_ccgroup))):
					$settings_msg .= __('WARNING: Settings have NOT been changed; your Country Code List or Group entry was is invalid (list must be empty or contain 2 character alphabetic codes separated by commas).<br>');
					$msg_type = 'error';

  	elseif ( $new_mode == 'QuickCache') :  // and country code list is valid
  			$script_update_result = $this->cczc_build_script( $cache_iso_cc, $use_group, $my_ccgroup );
  			if ( empty($this->options['last_output_err']) ) :
  			  $script_update_result = $this->cczc_write_script($script_update_result, $cache_iso_cc, $use_group, $my_ccgroup);
  			endif;
				$this->options['cache_iso_cc'] = $cache_iso_cc;
				$this->options['use_group'] = $use_group;
				$this->options['my_ccgroup'] = $my_ccgroup;
  			if ($script_update_result == 'Done') :
  				$this->options['caching_mode'] = 'QuickCache';
  				$msg_part = __("Settings have been updated and country caching is enabled for Comet Cache. Don't forget to CLEAR THE CACHE.<br>"); 
  				$settings_msg = $msg_part . $settings_msg;
  			else:
  				$msg_type = 'error';
  			  $settings_msg .= $script_update_result . '<br>';
  			endif;
    endif;

		// Comet Cache has been enabled; ensure Maxmind files are installed
		if (! file_exists(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME) || @filesize(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME) < 800000 ) :
		  $do_max = new CCAmaxmindUpdate();
			$success = $do_max->save_maxmind($this->is_plugin_update); // if method argument is true then email will be sent on failure
			$this->maxmind_status = $do_max->get_max_status();
			if (! $success):
			  $settings_msg = $settings_msg . '<br>Maxmind mmdb file is missing and could not be installed.<br>' . $this->maxmind_status['result_msg']; 
				$msg_type = 'error';
			endif;
 			unset($do_max);
		endif;

		if ($settings_msg != '') :
      add_settings_error('geoip_group',esc_attr( 'settings_updated' ), __($settings_msg),	$msg_type	);
    endif;
		return $this->options;

  }   // END santize func


  function delete_qc_addon() {
    if ( ZC_DIREXISTS && ! $this->remove_addon_file(ZC_ADDON_FILE) ) :
  	 return __('Warning: I was unable to remove the old country caching addon script(s): "') . $file . __('". You will have to delete this file yourself.<br>');
  	endif;
    return '';
  }

  function remove_addon_file($file) {
    if ( validate_file($file) === 0 && is_file($file) && ! unlink($file) ) return FALSE;
  	return TRUE;
  }

  
  function cczc_qc_status() {
    if (! empty($_SERVER["HTTP_CF_IPCOUNTRY"]) ) :
	     $geoip_used = __('Cloudflare data is being used for GeoIP	');
		elseif ($this->maxmind_status['health'] == 'fail') :
		   $geoip_used = __('There is a problem with GeoIP. Check GeoIP info on the Cofiguration tab.');
		else:
		   $geoip_used = '';
		endif;

//  	if( class_exists('\\zencache\\plugin') || !empty($GLOBALS['zencache_advanced_cache']) || !empty($GLOBALS['comet_cache_advanced_cache']) ) : 
		if( ! empty($GLOBALS['comet_cache_advanced_cache']) ) : 
	    if ($this->options['caching_mode'] == 'QuickCache'):
    		if (empty($this->options['cache_iso_cc'])) :
    		  $opto = __("<br>To fully optimize performance you should limit the countries that are individually cached.");
    		 else: $opto = '';
  			endif;

  	    if ( is_readable(ZC_ADDON_FILE) ) :
				  if ( function_exists('cca_qc_salt_shaker') ):
					  $add_on_ver = cca_qc_salt_shaker('cca_version');
						if ( $add_on_ver == ZC_ADDON_VERSION) :
						  $qc_status = __("Your site is using Comet Cache and country caching is enabled.<br>");
							$qc_status .= $opto . $geoip_used;
						else:
						  $qc_status = __("Your site is using Comet Cache but the Country Add-on script built by this plugin NEEDS MODIFYING.<br>");
							$qc_status .= __('Click "Save Settings" button to rebuild this script.<br>');
						  $qc_status .= $opto . $geoip_used;
						endif;
					endif;
  			else:
  				$qc_status = $geoip_used . '<br>' . __("Your site is using Comet Cache and you have enabled country caching. <b>***However something has gone wrong***</b> and the relevant add-on script cannot be found in ZC's plugin folder.<br>");
  				$qc_status .= __("Clicking the submit button below might hopefully cause the script to be regenerated and fix the problem<br>");
  				$qc_status .= $opto ;
  			endif;

  		else:
   			$qc_status = $geoip_used . '<br>' . __("It looks like your site is using Comet Cache, but you have not enabled country caching.");
    		if ( file_exists(ZC_ADDON_FILE) ) :
    			$qc_status = __("Something went wrong; although not 'enabled', the country caching addon script was found in ZC's plugins directory and may still be running.<br>");
    			$qc_status .= __("Clicking the Submit button below should result in the add-on being deleted and resolve this problem.");
    		endif;
  		endif;
  	else:
  	  $qc_status = __("Your site does not appear to be using Comet Cache, or caching is disabled.");
			if ($this->options['caching_mode'] == 'QuickCache'):
 			  $qc_status .= '<br>' . __('You should either activate Comet & enable caching or ensure the country caching extension script is disabled by unchecking "Enable CC Country Caching add-on" and save settings.');
			endif;				
    endif;
  	return $qc_status;
  }   // END  cczc_qc_status()


  // build the script that ZC/QC will use to cache by page + country
  function cczc_build_script( $country_codes, $use_group, $my_ccgroup) {

//	  if( $this->options['activation_status'] != 'activating' && ! class_exists('\\zencache\\plugin') && empty($GLOBALS['zencache_advanced_cache']) && empty($GLOBALS['comet_cache_advanced_cache']) ):
		if( $this->options['activation_status'] != 'activating' && empty($GLOBALS['comet_cache_advanced_cache']) ):
		  $this->options['last_output_err']  = '* ' . __("Country caching script has NOT been created. The Comet Cache plugin doesn't appear to be running on your site (maybe you disabled caching or de-activated the plugin).");
		  return $this->options['last_output_err'];
		endif;
		
    $template_script = CCZC_PLUGINDIR . 'caching_plugins/' . CCZC_ADDON_SCRIPT;
    $file_string = @file_get_contents(  CCZC_PLUGINDIR . 'caching_plugins/' . CCZC_ADDON_SCRIPT); // $this->options['qc_addon_script']
		if (empty($file_string)) : 
			if ( file_exists( $template_script ) ):
			  $this->options['last_output_err']  =  '*' . __('Error: unable to read the template script ("') . $template_script . __('") used to create the extension for Comet cache');		
				return $this->options['last_output_err'];
		  else:
			  $this->options['last_output_err']  = '*' . __('Error: it looks like the template script ("') . $template_script . __('") needed to create the extension for Comet cache has been deleted.');
				return $this->options['last_output_err'];
      endif;
		endif;
		unset($this->options['last_output_err']) ;
		if ( ! empty($country_codes) ) : $file_string = str_replace('$just_these = array();', '$just_these = explode(",","' . $country_codes .'");',  $file_string); endif;
		if ( ! empty($use_group) && !  empty($my_ccgroup)) : $file_string = str_replace('$my_ccgroup = array();', '$my_ccgroup = explode(",","' . $my_ccgroup .'");',  $file_string); endif;
		$this->options['cca_maxmind_data_dir'] = CCA_MAXMIND_DATA_DIR;
		$file_string = str_replace('unknown version', ZC_ADDON_VERSION, $file_string);
		$file_string = str_replace('ccaMaxDataDir-Replace', CCA_MAXMIND_DATA_DIR, $file_string);
    $file_string = str_replace('cczcMaxDir-Replace', CCZC_MAXMIND_DIR, $file_string);
		$file_string = str_replace('GeoData-Replace', CCA_MAX_FILENAME, $file_string);

    return $file_string;
  }

	// write the generated script to ZC/QuickCaches add_ons folder
  function cczc_write_script( $file_string, $country_codes, $use_group, $my_ccgroup) {
  	unset($this->options['override_tab']);
    $item_perms = cczc_return_permissions(CCZC_PLUGINDIR);  // determine permissions to set when creating directory
    if (strlen($item_perms) == 4 && substr($item_perms, 2, 1) == '7') :
      $cczc_perms = 0775;
    else:
      $cczc_perms = 0755;
    endif;
  
    clearstatcache(true, ZC_PLUGINDIR);
    if( ! file_exists(ZC_PLUGINDIR) && ! mkdir(ZC_PLUGINDIR, $cczc_perms, true) ):
  			$this->options['override_tab'] = 'files';
 				$this->options['override_isocodes'] = $country_codes;
				$this->options['override_use_group'] = $use_group;
				$this->options['override_my_ccgroup'] = $my_ccgroup;
  			$this->options['last_output_err'] =  date('d M Y h:i a; ') .  __(' unable to create Comet add-on directory "') . ZC_PLUGINDIR . '" .' . __('Full error message = ') . implode(' | ',error_get_last());
  		  return __('Error: Unable to read/create the Comet Cache add-on folder (this might be due to wp-content permissions). Actual error reported = ') . implode(' | ',error_get_last());
  	endif;
  	
/*
// test error
$this->options['override_tab'] = 'files';
$this->options['override_isocodes'] = $country_codes;
$this->options['override_use_group'] = $use_group;
$this->options['override_my_ccgroup'] = $my_ccgroup;
$this->options['last_output_err'] =  date('d M Y h:i a; ') .  __(' unable to create the ZC add-on directory "') . ZC_PLUGINDIR . '" .' . __('Full error message = ') . implode(' | ',error_get_last());
return __('Error: Unable to read/create the Comet Cache add-on folder (this might be due to wp-content permissions). Actual error reported = ') . implode(' | ',error_get_last());
*/


    if ( !  file_put_contents(ZC_PLUGINDIR . CCZC_ADDON_SCRIPT, $file_string, LOCK_EX ) ) :
  		$this->options['override_tab'] = 'files';
  		$this->options['override_isocodes'] = $country_codes;
			$this->options['use_group'] = $use_group;
			$this->options['my_ccgroup'] = $my_ccgroup;
  	  $this->options['last_output_err'] =  date('d M Y h:i a; ') .  __(' unable to create/update add-on script ') . ZC_ADDON_FILE;
  	  return "Error: creating/updating script in Comet Cache's add-on directory. You might not have write permissions to either create or replace it.";  // locking added in 0.6.2
    endif;
    unset($this->options['last_output_err']);
    unset($this->options['override_isocodes']);
		unset($this->options['use_group'] );
		unset($this->options['my_ccgroup']); 
    // check what file permissions are being set by server in wp-content folders and set this scripts files permissions to match
    $item_perms = cczc_return_permissions(CCZC_CALLING_SCRIPT);
    if (strlen($item_perms) == 4 && substr($item_perms, 2, 1) > "5" ) :
      $cczc_perms = 0664;
    else:
      $cczc_perms = 0644;
    endif;
  	chmod(ZC_ADDON_FILE,$cczc_perms);
  
   	return 'Done';
  }


  function is_valid_ISO_list($list) {
    if ( $list != '') :
  	  $codes = explode(',' , $list);
  		foreach ($codes as $code) :
  		   if ( ! ctype_alpha($code) || strlen($code) != 2) :
     		   return FALSE;
  			 endif;
  		endforeach;	
  	endif;
		return TRUE;
	}


  function using_cloudflare_or_max_already() {
	   if(! empty($_SERVER["HTTP_CF_IPCOUNTRY"]) || ! empty($this->maxmind_status) || $this->options['caching_mode'] == 'QuickCache') :
		   return TRUE;
		endif;
		return FALSE;
	}

} // end class
?>
