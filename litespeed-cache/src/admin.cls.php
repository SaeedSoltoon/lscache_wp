<?php
/**
 * The admin-panel specific functionality of the plugin.
 *
 *
 * @since      1.0.0
 * @package    LiteSpeed_Cache
 * @subpackage LiteSpeed_Cache/admin
 * @author     LiteSpeed Technologies <info@litespeedtech.com>
 */
namespace LiteSpeed ;

defined( 'WPINC' ) || exit ;

class Admin extends Instance
{
	protected static $_instance ;
	private $__cfg ;// cfg instance
	private $display ;

	/**
	 * Initialize the class and set its properties.
	 * Run in hook `after_setup_theme` when is_admin()
	 *
	 * @since    1.0.0
	 */
	protected function __construct()
	{
		// Define LSCWP_MU_PLUGIN if is mu-plugins
		if ( defined( 'WPMU_PLUGIN_DIR' ) && dirname( LSCWP_DIR ) == WPMU_PLUGIN_DIR ) {
			define( 'LSCWP_MU_PLUGIN', true ) ;
		}

		// Additional litespeed assets on admin display
		// Also register menu
		$this->display = Admin_Display::get_instance() ;

		$this->__cfg = Config::get_instance() ;

		// initialize admin actions
		add_action( 'admin_init', array( $this, 'admin_init' ) ) ;
		// add link to plugin list page
		add_filter( 'plugin_action_links_' . LSCWP_BASENAME, array( $this->display, 'add_plugin_links' ) ) ;

		if ( defined( 'LITESPEED_ON' ) ) {
			// register purge_all actions
			$purge_all_events = Core::config( Conf::O_PURGE_HOOK_ALL ) ;

			// purge all on upgrade
			if ( Core::config( Conf::O_PURGE_ON_UPGRADE ) ) {
				$purge_all_events[] = 'upgrader_process_complete' ;
				$purge_all_events[] = 'admin_action_do-plugin-upgrade' ;
			}
			foreach ( $purge_all_events as $event ) {
				// Don't allow hook to update_option bcos purge_all will cause infinite loop of update_option
				if ( in_array( $event, array( 'update_option' ) ) ) {
					continue ;
				}
				add_action( $event, 'Purge::purge_all' ) ;
			}
			// add_filter( 'upgrader_pre_download', 'Purge::filter_with_purge_all' ) ;
		}
	}

	/**
	 * Callback that initializes the admin options for LiteSpeed Cache.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function admin_init()
	{
		load_plugin_textdomain( Core::PLUGIN_NAME, false, 'litespeed-cache/lang/' ) ;

		$this->_proceed_admin_action() ;

		// Terminate if user doesn't have the access to settings
		if( is_network_admin() ) {
			$capability = 'manage_network_options' ;
		}
		else {
			$capability = 'manage_options' ;
		}
		if ( ! current_user_can($capability) ) {
			return ;
		}

		// Save setting from admin settings page
		// NOTE: cli will call `validate_plugin_settings` manually. Cron activation doesn't need to validate

		// Add privacy policy
		// @since 2.2.6
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( Core::PLUGIN_NAME, Doc::privacy_policy() ) ;
		}

		do_action( 'litspeed_after_admin_init' ) ;

		Control::set_nocache( 'Admin page' ) ;

		if ( Router::esi_enabled() ) {
			add_action( 'in_widget_form', array( $this->display, 'show_widget_edit' ), 100, 3 ) ;
			add_filter( 'widget_update_callback', __NAMESPACE__ . '\Admin_Settings::validate_widget_save', 10, 4 ) ;
		}
	}

	/**
	 * Run litespeed admin actions
	 *
	 * @since 1.1.0
	 */
	private function _proceed_admin_action()
	{
		// handle actions
		switch (Router::get_action()) {

			// Save htaccess
			case Core::ACTION_SAVE_HTACCESS:
				Htaccess::get_instance()->htaccess_editor_save() ;
				break ;

			case Router::ACTION_SAVE_SETTINGS :
				Admin_Settings::get_instance()->save( $_POST ) ;
				break ;


			// Save network settings
			case Core::ACTION_SAVE_SETTINGS_NETWORK:
				Admin_Settings::get_instance()->network_save( $_POST ) ;
				break ;

			default:
				break ;
		}

	}

	/**
	 * Clean up the input string of any extra slashes/spaces.
	 *
	 * @since 1.0.4
	 * @access public
	 * @param string $input The input string to clean.
	 * @return string The cleaned up input.
	 */
	public static function cleanup_text( $input )
	{
		if ( is_array( $input ) ) {
			return array_map( __CLASS__ . '::cleanup_text', $input ) ;
		}

		return stripslashes( trim( $input ) ) ;
	}

	/**
	 * After a LSCWP_CTRL action, need to redirect back to the same page
	 * without the nonce and action in the query string.
	 *
	 * @since 1.0.12
	 * @access public
	 * @global string $pagenow
	 */
	public static function redirect( $url = false )
	{
		global $pagenow ;
		$qs = '' ;
		if ( ! $url ) {
			if ( ! empty( $_GET ) ) {
				if ( isset( $_GET[ Router::ACTION_KEY ] ) ) {
					unset( $_GET[ Router::ACTION_KEY ] ) ;
				}
				if ( isset( $_GET[ Router::NONCE_NAME ] ) ) {
					unset( $_GET[ Router::NONCE_NAME ] ) ;
				}
				if ( ! empty( $_GET ) ) {
					$qs = '?' . http_build_query( $_GET ) ;
				}
			}
			if ( is_network_admin() ) {
				$url = network_admin_url( $pagenow . $qs ) ;
			}
			else {
				$url = admin_url( $pagenow . $qs ) ;
			}
		}

		wp_redirect( $url ) ;
		exit() ;
	}
}
