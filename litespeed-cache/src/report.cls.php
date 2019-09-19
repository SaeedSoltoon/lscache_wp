<?php
/**
 * The report class
 *
 *
 * @since      1.1.0
 * @package    LiteSpeed
 * @subpackage LiteSpeed/inc
 * @author     LiteSpeed Technologies <info@litespeedtech.com>
 */
namespace LiteSpeed ;

defined( 'WPINC' ) || exit ;

class Report extends Conf
{
	protected static $_instance ;
	const DB_PREFIX = 'env' ; // DB record prefix name

	const TYPE_SEND_REPORT = 'send_report' ;

	const ITEM_REF = 'ref' ;

	/**
	 * Handle all request actions from main cls
	 *
	 * @since  1.6.5
	 * @access public
	 */
	public static function handler()
	{
		$instance = self::get_instance() ;

		$type = Router::verify_type() ;

		switch ( $type ) {

			case self::TYPE_SEND_REPORT :
				$instance->_post_env() ;
				break ;

			default:
				break ;
		}

		Admin::redirect() ;
	}

	/**
	 * post env report number to ls center server
	 *
	 * @since  1.6.5
	 * @access private
	 */
	private function _post_env()
	{
		$report_con = $this->generate_environment_report() ;
		$data = array(
			'env' => $report_con,
		) ;

		$json = Admin_API::post( Admin_API::IAPI_ACTION_ENV_REPORT, Utility::arr2str( $data ), false, true ) ;

		if ( ! is_array( $json ) ) {
			Log::debug( 'Env: Failed to post to LiteSpeed server ', $json ) ;
			$msg = __( 'Failed to push to LiteSpeed server', 'litespeed-cache' ) . ': ' . $json ;
			Admin_Display::error( $msg ) ;
			return ;
		}

		$data = array(
			'num'	=> ! empty( $json[ 'num' ] ) ? $json[ 'num' ] : '--',
			'dateline'	=> time(),
		) ;

		self::update_option( self::ITEM_REF, $data ) ;

	}

	/**
	 * Get env report number from db
	 *
	 * @since  1.6.4
	 * @access public
	 * @return array
	 */
	public function get_env_ref()
	{
		$info = self::get_option( self::ITEM_REF ) ;

		if ( ! is_array( $info ) ) {
			return array(
				'num'	=> '-',
				'dateline'	=> '-',
			) ;
		}

		$info[ 'dateline' ] = date( 'm/d/Y H:i:s', $info[ 'dateline' ] ) ;

		return $info ;
	}

	/**
	 * Gathers the environment details and creates the report.
	 * Will write to the environment report file.
	 *
	 * @since 1.0.12
	 * @access public
	 * @param mixed $options Array of options to output. If null, will skip
	 * the options section.
	 * @return string The built report.
	 */
	public function generate_environment_report($options = null)
	{
		global $wp_version, $_SERVER ;
		$frontend_htaccess = Htaccess::get_frontend_htaccess() ;
		$backend_htaccess = Htaccess::get_backend_htaccess() ;
		$paths = array($frontend_htaccess) ;
		if ( $frontend_htaccess != $backend_htaccess ) {
			$paths[] = $backend_htaccess ;
		}

		if ( is_multisite() ) {
			$active_plugins = get_site_option('active_sitewide_plugins') ;
			if ( ! empty($active_plugins) ) {
				$active_plugins = array_keys($active_plugins) ;
			}
		}
		else {
			$active_plugins = get_option('active_plugins') ;
		}

		if ( function_exists('wp_get_theme') ) {
			$theme_obj = wp_get_theme() ;
			$active_theme = $theme_obj->get('Name') ;
		}
		else {
			$active_theme = get_current_theme() ;
		}

		$extras = array(
			'wordpress version' => $wp_version,
			'siteurl' => get_option( 'siteurl' ),
			'home' => get_option( 'home' ),
			'home_url' => home_url(),
			'locale' => get_locale(),
			'active theme' => $active_theme,
		) ;

		$extras[ 'active plugins' ] = $active_plugins ;

		if ( is_null($options) ) {
			$options = Config::get_instance()->get_options() ;
		}

		if ( ! is_null($options) && is_multisite() ) {
			$blogs = Activation::get_network_ids() ;
			if ( ! empty($blogs) ) {
				foreach ( $blogs as $blog_id ) {
					$opts = Config::get_instance()->load_options( $blog_id, true ) ;
					if ( isset($opts[ Conf::O_CACHE ]) ) {
						$options['blog ' . $blog_id . ' radio select'] = $opts[ Conf::O_CACHE ] ;
					}
				}
			}
		}

		// Security: Remove cf key in report
		$secure_fields = array(
			Conf::O_CDN_QUIC_KEY,
			Conf::O_CDN_CLOUDFLARE_KEY,
			Conf::O_OBJECT_PSWD,
		) ;
		foreach ( $secure_fields as $v ) {
			if ( ! empty( $options[ $v ] ) ) {
				$options[ $v ] = str_repeat( '*', strlen( $options[ $v ] ) ) ;
			}
		}

		$report = $this->build_environment_report($_SERVER, $options, $extras, $paths) ;
		return $report ;
	}

	/**
	 * Builds the environment report buffer with the given parameters
	 *
	 * @access private
	 * @param array $server - server variables
	 * @param array $options - cms options
	 * @param array $extras - cms specific attributes
	 * @param array $htaccess_paths - htaccess paths to check.
	 * @return string The Environment Report buffer.
	 */
	private function build_environment_report($server, $options, $extras = array(), $htaccess_paths = array())
	{
		$server_keys = array(
			'DOCUMENT_ROOT'=>'',
			'SERVER_SOFTWARE'=>'',
			'X-LSCACHE'=>'',
			'HTTP_X_LSCACHE'=>''
		) ;
		$server_vars = array_intersect_key($server, $server_keys) ;
		$server_vars[] = "LSWCP_TAG_PREFIX = " . LSWCP_TAG_PREFIX ;

		$server_vars = array_merge( $server_vars, Config::get_instance()->server_vars() ) ;

		$buf = $this->format_report_section('Server Variables', $server_vars) ;

		$buf .= $this->format_report_section('Wordpress Specific Extras', $extras) ;

		$buf .= $this->format_report_section('LSCache Plugin Options', $options) ;

		if ( empty($htaccess_paths) ) {
			return $buf ;
		}

		foreach ( $htaccess_paths as $path ) {
			if ( ! file_exists($path) || ! is_readable($path) ) {
				$buf .= $path . " does not exist or is not readable.\n" ;
				continue ;
			}

			$content = file_get_contents($path) ;
			if ( $content === false ) {
				$buf .= $path . " returned false for file_get_contents.\n" ;
				continue ;
			}
			$buf .= $path . " contents:\n" . $content . "\n\n" ;
		}
		return $buf ;
	}

	/**
	 * Creates a part of the environment report based on a section header
	 * and an array for the section parameters.
	 *
	 * @since 1.0.12
	 * @access private
	 * @param string $section_header The section heading
	 * @param array $section An array of information to output
	 * @return string The created report block.
	 */
	private function format_report_section( $section_header, $section )
	{
		$tab = '    ' ; // four spaces

		if ( empty( $section ) ) {
			return 'No matching ' . $section_header . "\n\n" ;
		}
		$buf = $section_header ;

		foreach ( $section as $k => $v ) {
			$buf .= "\n" . $tab ;

			if ( ! is_numeric( $k ) ) {
				$buf .= $k . ' = ' ;
			}

			if ( ! is_string( $v ) ) {
				$v = var_export( $v, true ) ;
			}

			$buf .= $v ;
		}
		return $buf . "\n\n" ;
	}

}
