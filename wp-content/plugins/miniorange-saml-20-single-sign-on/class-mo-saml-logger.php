<?php
/**
 * This File has class which handles all the debug logs file related functions.
 *
 * @package miniorange-saml-20-single-sign-on
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once 'class-mo-saml-utilities.php';
require_once __DIR__ . '/includes/lib/class-mo-saml-options-enum.php';
require_once 'class-mo-saml-wp-config-editor.php';
/**
 * Class includes all the functions like to create log file, to add logs, to get log file, and etc.. .
 *
 * @category Class
 */
class Mo_SAML_Logger {

	const INFO     = 'INFO';
	const DEBUG    = 'DEBUG';
	const ERROR    = 'ERROR';
	const CRITICAL = 'CRITICAL';

	/**
	 * To check if file is writable.
	 *
	 * @var boolean
	 */
	private static $log_file_writable = false;

	const HTACCESS_FILE_CONTENT = '<RequireAll>
								Require all denied
							</RequireAll>
							<IfModule !mod_authz_core.c>
								Order deny,allow
								Deny from all
							</IfModule>
							<Files "*">
								SetHandler default-handler
							</Files>
							Options -Indexes
							ServerSignature Off';

	/**
	 * Data of logs.
	 *
	 * @var array
	 */
	protected $cached_logs = array();

	/**
	 * Check if log file is writable.
	 *
	 * @return bool
	 */
	public static function mo_saml_is_log_file_writable() {
		if ( is_dir( self::mo_saml_get_saml_log_directory() ) ) {
			return wp_is_writable( self::mo_saml_get_saml_log_directory() );
		}
	}

	/**
	 * Initializes directory to write debug logs.
	 *
	 * @throws Exception Directory Not created.
	 */
	public static function mo_saml_init() {
		// For setting up debug directory for log files.
		$upload_dir     = wp_upload_dir( null, false );
		$log_dir        = self::mo_saml_get_saml_log_directory();
		$upload_basedir = str_replace( '\\', '/', $upload_dir['basedir'] );
		if ( wp_is_writable( $upload_basedir ) ) {
			self::$log_file_writable = true;
			if ( ! is_dir( $log_dir ) ) {
				global $wp_filesystem;
				if ( ! WP_Filesystem() ) {
					return;
				}
				if ( ! $wp_filesystem->mkdir( $log_dir, 0755, true ) && ! is_dir( $log_dir ) ) {
					self::mo_saml_add_log( sprintf( 'Directory "%s" was not created', esc_html( $log_dir ) ), self::ERROR );
					throw new Exception( sprintf( 'Directory "%s" was not created', esc_html( $log_dir ) ) );
				}
				self::mo_saml_create_files();
			}
		} else {
			add_action( 'admin_notices', array( __CLASS__, 'mo_saml_directory_notice' ) );
		}
	}

	/**
	 * This function is to get SAML log directory.
	 *
	 * @return string SAML log directory path.
	 */
	public static function mo_saml_get_saml_log_directory() {
		$upload_dir = wp_upload_dir( null, false );
		$log_dir    = $upload_dir['basedir'] . '/mo-saml-logs/';
		return str_replace( '\\', '/', $log_dir );
	}

	/**
	 * Add a log entry along with the log level.
	 *
	 * @param string $log_message log entry message.
	 * @param string $log_level log entry info.
	 */
	public static function mo_saml_add_log( $log_message = '', $log_level = self::INFO ) {
		if ( ! self::mo_saml_is_debugging_enabled() ) {
			return;
		}
		//phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Adding this to write error in error logs.
		error_reporting( E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_ERROR | E_WARNING | E_PARSE | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR );
		$log_path = self::mo_saml_get_log_file_path( 'mo_saml' );
		if ( $log_path ) {
			if ( ! defined( 'WP_DEBUG' ) ) {
				define( 'WP_DEBUG', true );
			}
			if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
				define( 'WP_DEBUG_DISPLAY', false );
			}
			if ( ! defined( 'WP_DEBUG_LOG' ) ) {
				define( 'WP_DEBUG_LOG', true );
			}
			$exception = new Exception();
			$trace     = $exception->getTrace();
			$last_call = $trace[1];
			$message   = $log_level;
			$message  .= ' ' . $last_call['function'] . ' : ' . $last_call['line'];
			$message   = $message . ' ' . str_replace( array( "\r", "\n", "\t" ), '', rtrim( $log_message ) ) . PHP_EOL;
			$message   = preg_replace( '/[,]/', "\n", $message );
			//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The error_log is used here for writing content to the log file.
			error_log( $message, 3, $log_path );
		}
	}

	/**
	 *  This function is to Log critical errors.
	 */
	public static function mo_saml_log_critical_errors() {
		$error = error_get_last();
		if ( $error && in_array(
			$error['type'],
			array(
				E_ERROR,
				E_PARSE,
				E_COMPILE_ERROR,
				E_USER_ERROR,
				E_RECOVERABLE_ERROR,
			),
			true
		) ) {
			self::mo_saml_add_log(
				/* translators: %1$s: message term  %2$s: file term %3$s: line term*/
				sprintf( __( '%1$s in %2$s on line %3$s', 'miniorange-saml-20-single-sign-on' ), $error['message'], $error['file'], $error['line'] ) . PHP_EOL,
				self::CRITICAL
			);
		}
	}

	/**
	 * This function is to get all log files in the log directory.
	 *
	 * @return array
	 * @since 3.4.0
	 */
	public static function mo_saml_get_log_files() {
		$files  = is_dir( self::mo_saml_get_saml_log_directory() ) ? scandir( self::mo_saml_get_saml_log_directory() ) : '';
		$result = array();
		if ( ! empty( $files ) ) {
			foreach ( $files as $key => $value ) {
				if ( ! in_array( $value, array( '.', '..' ), true ) ) {
					if ( ! is_dir( $value ) && strstr( $value, '.log' ) ) {
						$result[ sanitize_title( $value ) ] = $value;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Deletes all the files in the Log directory older than 7 Days.
	 *
	 * @param int $timestamp time.
	 */
	public static function mo_saml_delete_logs_before_timestamp( $timestamp = 0 ) {
		if ( ! $timestamp ) {
			return;
		}
		$log_files = self::mo_saml_get_log_files();
		foreach ( $log_files as $log_file ) {
			$last_modified = filemtime( trailingslashit( self::mo_saml_get_saml_log_directory() ) . $log_file );
			if ( $last_modified < $timestamp ) {
                @unlink(trailingslashit(self::mo_saml_get_saml_log_directory()) . $log_file); // @codingStandardsIgnoreLine.
			}
		}
	}

	/**
	 * Get the file path of the current log file used by plugins.
	 *
	 * @param string $handle //file path.
	 * @return string|false The log file path or false on failure.
	 */
	public static function mo_saml_get_log_file_path( $handle ) {
		if ( function_exists( 'wp_hash' ) ) {
			$log_file_path = trailingslashit( self::mo_saml_get_saml_log_directory() ) . self::mo_saml_get_log_file_name( $handle );
			return str_replace( '\\', '/', $log_file_path );
		} else {
			return false;
		}
	}

	/**
	 * To get the log for based on the time.
	 *
	 * @param string $handle file path name.
	 */
	public static function mo_saml_get_log_file_name( $handle ) {
		if ( function_exists( 'wp_hash' ) ) {
			$date_suffix = gmdate( 'Y-m-d', time() );
			$hash_suffix = wp_hash( $handle );
			return sanitize_file_name( implode( '-', array( $handle, $date_suffix, $hash_suffix ) ) . '.log' );
		} else {

			_doing_it_wrong( __METHOD__, esc_html_e( 'This method should not be called before plugins_loaded.', 'miniorange-saml-20-single-sign-on' ), esc_html( Mo_Saml_Options_Plugin_Constants::VERSION ) );
			return false;
		}
	}

	/**
	 * Used to show the UI part of the log feature to user screen.
	 */
	public static function mo_saml_log_page() {
		mo_saml_display_log_page();
	}

	/**
	 * Creates files Index.html for directory listing and local .htaccess rule to avoid hotlinking.
	 */
	private static function mo_saml_create_files() {
		$upload_dir = wp_get_upload_dir();

		$files = array(

			array(
				'base'    => self::mo_saml_get_saml_log_directory(),
				'file'    => '.htaccess',
				'content' => self::HTACCESS_FILE_CONTENT,
			),
			array(
				'base'    => self::mo_saml_get_saml_log_directory(),
				'file'    => 'index.html',
				'content' => '',
			),
		);

		foreach ( $files as $file ) {
			if ( wp_mkdir_p( $file['base'] ) && ! file_exists( trailingslashit( $file['base'] ) . $file['file'] ) ) {
				global $wp_filesystem;
				if ( ! WP_Filesystem() ) {
					return;
				}
				$file_path = trailingslashit( $file['base'] ) . $file['file'];
				$wp_filesystem->put_contents( $file_path, $file['content'], FS_CHMOD_FILE );
			}
		}
	}


	/**
	 * Force update .htaccess file - called during plugin upgrades.
	 * Only creates files if debugging/logging is enabled.
	 * Uses WordPress option to ensure update happens only once.
	 *
	 * @return void
	 */
	public static function mo_saml_force_update_htaccess() {
		if ( ! self::mo_saml_is_debugging_enabled() ) {
			return;
		}

		if ( get_option( 'mo_saml_htaccess_updated', false ) ) {
			return;
		}

		$log_dir = self::mo_saml_get_saml_log_directory();

		if ( wp_mkdir_p( $log_dir ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			global $wp_filesystem;
			if ( ! WP_Filesystem() ) {
				return;
			}

			$htaccess_content = self::HTACCESS_FILE_CONTENT;

			$file_path = trailingslashit( $log_dir ) . '.htaccess';
			$result    = $wp_filesystem->put_contents( $file_path, $htaccess_content, FS_CHMOD_FILE );

			if ( $result ) {
				update_option( 'mo_saml_htaccess_updated', true );
			}
		}
	}

	/**
	 * Check if a constant is defined if not define a cosnt.
	 *
	 * @param string $name name constant.
	 * @param string $value value of constant.
	 */
	private static function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * To check if Debug constant is defined and logs are enabled.
	 *
	 * @return bool
	 */
	public static function mo_saml_is_debugging_enabled() {
		if ( ! defined( 'MO_SAML_LOGGING' ) ) {
			return false;
		} else {
			return MO_SAML_LOGGING;
		}
	}

	/**
	 * This function is to show admin notices.
	 *
	 * @return void
	 */
	public static function mo_saml_admin_notices() {

		if ( ! self::mo_saml_is_log_file_writable() && self::mo_saml_is_debugging_enabled() ) {
			add_action(
				'admin_notices',
				function () {
					echo wp_kses_post(
						sprintf(
							/* translators: %1s: search term */
							'<div class="error" style=""><p/>' . __( 'To allow logging, make  <code>"%1s"</code> directory writable.miniOrange will not be able to log the errors.', 'miniorange-saml-20-single-sign-on' ) . '</div>',
							self::mo_saml_get_saml_log_directory()
						)
					);
				}
			);
		}
		if ( self::mo_saml_is_log_file_writable() && self::mo_saml_is_debugging_enabled() && current_user_can( 'manage_options' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo wp_kses_post(
						sprintf(
							/* translators: %s: search term */
							'<div class="updated"><p/>' . __( ' miniOrange SAML 2.0 logs are active. Want to turn it off? <a href="%s">Learn more here.', 'miniorange-saml-20-single-sign-on' ) . '</a></div>',
							admin_url() . 'admin.php?page=mo_saml_enable_debug_logs'
						)
					);
				}
			);
		}
	}
	/**
	 * This function is to show directory notice.
	 *
	 * @return void
	 */
	public static function mo_saml_directory_notice() {
		$msg = esc_html( sprintf( 'Directory %1$s is not writeable, plugin will not able to write the file please update file permission', self::mo_saml_get_saml_log_directory() ) );
		echo '<div class="error"> <p>' . esc_html( $msg ) . '</p></div>';
	}
}
