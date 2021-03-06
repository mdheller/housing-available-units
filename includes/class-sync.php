<?php

class BU_HAU_Sync {

	// constants
	const PREFIX    = 'bu_hau_';
	const SYNC_TIMEOUT = 300; // 30 seconds
	const SPACES_SYNC_NAME = 'sync_all';
	const SPACES_SYNC_TIME = '3 am';
	const SPACES_SYNC_FREQ = 'daily';
	const BOOKINGS_SYNC_NAME = 'sync_bookings';
	const BOOKINGS_SYNC_FREQ = 'per5minutes';
	const BOOKINGS_SAMPLE_SYNC_FREQ = 'minutely';

	const SYNC_STATUS_SUCCESS = 1;
	const SYNC_STATUS_FAILED  = 2;
	const SYNC_STATUS_RUNNING = 3;

	// regex
	const GET_LAST_HSV  = '/-.*/';
	const GET_FIRST_HSV = '/.*-/';

	public static $debug        = false;
	public static $start_time   = false;
	public static $log          = array();
	public static $sync_status  = 0;
	public static $sync_options = array(
		'bookings_only' => false,
	);
	public static $rate_translation = array(
		// 'Code' => 'Yearly Amount',
		'CB' => '9810',
		'ST' => '10360',
		'SC' => '11050',
		'SS' => '11050',
		'HD' => '11860',
		'SB' => '12680',
		'HS' => '13590',
		'PB' => '13600',
		'AP' => '12830',
		'AN' => '15430',
		'AB' => '16220',
		'AT' => '16720',
		'AS' => '17160',
		'GD' => '10690',
		'GT' => '11240',
		'GS' => '13560',
	);

	// output
	public static $sync_start_time = null;
	public static $output          = array();
	public static $areas           = array();

	// counts
	public static $space_types_counts   = array();
	public static $housing_codes_counts = array();
	public static $gender_counts        = array(
		'Male'   => 0,
		'Female' => 0,
		'CoEd'   => 0,
	);
	public static $room_size_counts     = array(
		'Single' => 0,
		'Double' => 0,
		'Triple' => 0,
		'Quad'   => 0,
	);

	// import
	private static $spaces        = array();
	private static $bookings      = array();
	private static $housing_codes = array();

	// sync files
	private static $spaces_file = '';
	private static $bookings_file = '';
	private static $housing_codes_file = '';

	// api
	private static $api_url = '';
	private static $api_username = '';
	private static $api_password = '';


	/**
	 * Setup
	 * @return null
	 */
	static function init( $debug = false ) {
		self::$debug = $debug;
		self::$start_time = time();

		self::setup_cron();

		// admin only sync ajax calls
		add_action( 'wp_ajax_bu_hau_sync_all', array( __CLASS__, 'sync_all' ) );
		add_action( 'wp_ajax_bu_hau_sync_bookings', array( __CLASS__, 'sync_bookings' ) );
	}

	/**
	 * Clear previous sync jobs
	 * @return null
	 */
	static function clear_sync() {
		wp_clear_scheduled_hook( self::PREFIX . self::SPACES_SYNC_NAME );
		wp_clear_scheduled_hook( self::PREFIX . self::BOOKINGS_SYNC_NAME );
	}

	/**
	 * Setup sync jobs
	 * @return null
	 */
	static function setup_sync() {
		self::clear_sync();

		if ( ! wp_next_scheduled( self::PREFIX . self::SPACES_SYNC_NAME ) ) {
			wp_schedule_event( strtotime( self::SPACES_SYNC_TIME ), self::SPACES_SYNC_FREQ, self::PREFIX . self::SPACES_SYNC_NAME );
		}
		if ( ! wp_next_scheduled( self::PREFIX . self::BOOKINGS_SYNC_NAME ) ) {
			$sync_args = array( 'bookings_only' => true );
			$sync_freq = self::BOOKINGS_SYNC_FREQ;
			if ( defined( 'BU_HAU_USE_SAMPLE_BOOKINGS' ) && BU_HAU_USE_SAMPLE_BOOKINGS ) {
				$sync_freq = self::BOOKINGS_SAMPLE_SYNC_FREQ;
			}
			wp_schedule_event( time(), $sync_freq, self::PREFIX . self::BOOKINGS_SYNC_NAME, array( $sync_args ) );
		}
	}

	/**
	 * Setup cron
	 * - Adds per5minutes recurrence type
	 * - Adds hooks to fire when sync happens
	 * @return null
	 */
	static function setup_cron() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_custom_cron_schedules' ) );
		add_action( self::PREFIX . self::BOOKINGS_SYNC_NAME, array( __CLASS__, 'sync' ), 10, 1 );
		add_action( self::PREFIX . self::SPACES_SYNC_NAME, array( __CLASS__, 'sync' ), 10, 1 );
	}

	/**
	 * Allow us to be able to register WP cron to run on a custom interval
	 * @param  array $schedules current
	 * @return array            modified
	 */
	static function add_custom_cron_schedules( $schedules ) {
		$schedules['per5minutes'] = array(
			'interval' => 300,
			'display' => __( 'Every 5 minutes' ),
		);
		$schedules['minutely'] = array(
			'interval' => 60,
			'display' => __( 'Every Minute' ),
		);
		return $schedules;
	}

	/**
	 * Get remote file from StarRez API
	 * @param type $url
	 * @return boolean
	 */
	static function get_remote_file( $url, $filename ) {
		// $args = array(
		// 	'timeout'             => self::SYNC_TIMEOUT,
		// 	'headers'             => array(
		// 		'StarRezUsername: ' . self::$api_username,
		// 		'StarRezPassword: ' . self::$api_password,
		// 	),
		// 	'body'                => '<Parameters></Parameters>',
		//  'sslcertificates'     => '/etc/pki/tls/certs/ca-bundle.crt',
		// );
		// $response = wp_remote_post( $url, $args );

		// $error_msg = '';
		// if ( is_wp_error( $response ) ) {
		// 	$error_msg = sprintf( 'Could not get file for URL %s. Error: %s', $url, $response->get_error_message() );
		// } else if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		// 	$error_msg = sprintf( 'Could not get file for URL %s. Error: %s', $url, wp_remote_retrieve_body( $response ) );
		// }

		// if ( ! empty( $error_msg ) ) {
		// 	return new WP_Error( __METHOD__, $error_msg );
		// }

		// $content = wp_remote_retrieve_body( $response );

		/**
		 * wp_remote_post/curl is behaving really weird with the WP included certs (or newer downloaded ones).
		 * curl_setopt( $handle, CURLOPT_CAINFO, $r['sslcertificates'] ); causes problems
		 * Default systems' certs (/etc/pki/tls/certs/ca-bundle.crt) does work, but can't do it due to compatibility purposes.
		 * @var array
		 */
		$curl_opts = array(
			CURLOPT_HEADER         => false,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::SYNC_TIMEOUT,
			CURLOPT_TIMEOUT        => self::SYNC_TIMEOUT,
			// CURLOPT_PROXYTYPE      => CURLPROXY_HTTP,
			// CURLOPT_PROXYPORT      => WP_PROXY_PORT,
			// CURLOPT_PROXY          => WP_PROXY_HOST,
			CURLOPT_POSTFIELDS     => '<Parameters></Parameters>',
			CURLOPT_HTTPHEADER     => array(
				'StarRezUsername: ' . self::$api_username,
				'StarRezPassword: ' . self::$api_password,
			),
		);

		$ch = curl_init( $url );
		curl_setopt_array( $ch, $curl_opts );
		$content = $response = curl_exec( $ch );
		$info = curl_getinfo( $ch );

		// Validate common errors
		if ( false == $response ) {
			$error_msg = sprintf( 'CURL error: %s URL: %s', curl_error( $ch ), $url );
		} else if ( empty( $response ) ) {
			$error_msg = sprintf( 'HTTP request received empty response for URL: %s', $info['url'] );
		} else if ( $info['http_code'] != 200 ) {
			$error_msg = sprintf( 'HTTP request received bad response code for URL: %s Code: %s', $info['url'], $info['http_code'] );
		}

		curl_close( $ch );

		if ( ! empty( $error_msg ) ) {
			return new WP_Error( __METHOD__, $error_msg );
		}

		self::write_contents( $filename, $content );

		return $content;
	}

	/**
	 * Setup API constants, add a sync lock to avoid overlaps
	 * @return WP_Error|true
	 */
	static function prepare_sync() {

		self::$sync_status = self::SYNC_STATUS_RUNNING;

		// setup API paths
		if ( ! self::$debug ) {
			if ( defined( 'BU_HAU_API_URL' ) && defined( 'BU_HAU_API_PASSWORD' ) && defined( 'BU_HAU_API_USERNAME' )
				 && BU_HAU_API_URL && BU_HAU_API_USERNAME && BU_HAU_API_PASSWORD ) {
				self::$api_url = BU_HAU_API_URL;
				self::$api_username = BU_HAU_API_USERNAME;
				self::$api_password = BU_HAU_API_PASSWORD;
			} else {
				$msg = 'Missing BU_HAU_API_USERNAME, BU_HAU_API_PASSWORD or BU_HAU_API_URL constants to do sync.';
				return new WP_Error( __METHOD__, $msg );
			}
		}

		self::log( sprintf( '[%s]: Starting %s sync.', __METHOD__, self::get_sync_type() ), true );

		// extra safety check
		// if sync is bookings only, ensure we have required files
		if ( ! self::$debug && self::$sync_options['bookings_only'] ) {

			$wp_upload_dir = wp_upload_dir();
			$units_file = $wp_upload_dir['basedir'] . BU_HAU_MEDIA_UNITS_JSON_FILE;

			if ( ! file_exists( $units_file ) ) {
				self::log( sprintf( '[%s]: Missing previously generated files. Sync all files.', __METHOD__ ) );
				self::$sync_options['bookings_only'] = false;
			}
		}

		return true;
	}

	static function cleanup_sync() {

		self::log( sprintf( '[%s]: Cleanup sync.', __METHOD__ ), true );

		BU_HAU_Sync_Lock::get_instance()->unlock();
		$duration = time() - self::$start_time;
		self::log( sprintf( '[%s]: Completed %s sync in %s seconds.', __METHOD__, self::get_sync_type(), $duration ) );
		self::save_log();
	}

	/**
	 * Download the spaces, bookings, and housing codes files from remote
	 * When in debug mode, use sample files.
	 * @return WP_Error|null
	 */
	static function sync_files() {

		if ( self::$debug ) {
			self::$spaces_file = BU_HAU_SAMPLE_DIR . BU_HAU_SPACE_FILENAME . BU_HAU_FILE_EXT;
			self::$bookings_file = BU_HAU_SAMPLE_DIR . BU_HAU_BOOKINGS_FILENAME . BU_HAU_FILE_EXT;
			self::$housing_codes_file = BU_HAU_SAMPLE_DIR . BU_HAU_HOUSING_CODES_FILENAME . BU_HAU_FILE_EXT;
		} else {
			// download remote files to media dir
			$wp_upload_dir = wp_upload_dir();
			$sync_dir = $wp_upload_dir['basedir'] . BU_HAU_MEDIA_DIR . 'sync/';

			// use local sample bookings
			// call the bookings sync every 10 seconds to see it update to 6 different stages
			if ( defined( 'BU_HAU_USE_SAMPLE_BOOKINGS' ) && BU_HAU_USE_SAMPLE_BOOKINGS ) {
				self::$bookings_file = BU_HAU_SAMPLE_DIR . BU_HAU_BOOKINGS_FILENAME . BU_HAU_FILE_EXT;
				$bookings_num = ( (int) date( 'i' ) % 6 ) + 1; // range: 1 to 6, changes every minute
				$bookings_num = str_pad( $bookings_num, 2, '0', STR_PAD_LEFT ); // range 01 to 06
				self::$bookings_file = BU_HAU_SAMPLE_DIR . BU_HAU_BOOKINGS_FILENAME . '-' . $bookings_num . BU_HAU_FILE_EXT;
				self::log( sprintf( '[%s]: BU_HAU_USE_SAMPLE_BOOKINGS turned on. Using %s.', __METHOD__, basename( self::$bookings_file ) ), true );
			} else {
				$bookings_url = self::$api_url . rawurlencode( BU_HAU_BOOKINGS_FILENAME . BU_HAU_FILE_EXT );
				self::$bookings_file = $sync_dir . BU_HAU_BOOKINGS_FILENAME . BU_HAU_FILE_EXT;
				$result = self::get_remote_file( $bookings_url, self::$bookings_file );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			if ( self::$sync_options['bookings_only'] ) { return; }

			$spaces_url = self::$api_url . rawurlencode( BU_HAU_SPACE_FILENAME . BU_HAU_FILE_EXT );
			self::$spaces_file = $sync_dir . BU_HAU_SPACE_FILENAME . BU_HAU_FILE_EXT;
			$result = self::get_remote_file( $spaces_url, self::$spaces_file );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$housing_codes_url = self::$api_url . rawurlencode( BU_HAU_HOUSING_CODES_FILENAME . BU_HAU_FILE_EXT );
			self::$housing_codes_file = $sync_dir . BU_HAU_HOUSING_CODES_FILENAME . BU_HAU_FILE_EXT;
			$result = self::get_remote_file( $housing_codes_url, self::$housing_codes_file );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}

	/**
	 * Sync all data
	 * @return null
	 */
	static function sync_all() {

		self::log( sprintf( '[%s]: Explicitly firing all sync.', __METHOD__ ) );

		echo self::sync();
		die;
	}

	/**
	 * Sync only the bookings
	 * @return null
	 */
	static function sync_bookings() {

		self::log( sprintf( '[%s]: Explicitly firing bookings sync.', __METHOD__ ) );

		$args = array( 'bookings_only' => true );
		echo self::sync( $args );
		die;
	}

	/**
	 * Sync the spaces, bookings, and housing codes
	 * Uses sample files when in Debug mode
	 * @return string output as written to media dir file
	 */
	static function sync( $args = array() ) {
		if ( defined( 'BU_FS_READ_ONLY' ) && BU_FS_READ_ONLY ) { return; }

		self::$sync_options = wp_parse_args( $args, self::$sync_options );

		// setup lock
		BU_HAU_Sync_Lock::get_instance()->setup( self::$start_time, self::SYNC_TIMEOUT * 3 );
		$lock_result = BU_HAU_Sync_Lock::get_instance()->lock();
		if ( is_wp_error( $lock_result ) ) {
			self::log( sprintf( '[%s]: Aborting sync. %s', $lock_result->get_error_code(), $lock_result->get_error_message() ) );
			return false;
		}
		self::log( sprintf( '[%s]: Acquired lock at ' . BU_HAU_Sync_Lock::get_instance()->get_start_time( true ), __METHOD__ ), true );

		$prepare_sync = self::prepare_sync();
		if ( is_wp_error( $prepare_sync ) ) {
			self::$sync_status = self::SYNC_STATUS_FAILED;
			self::log( sprintf( '[%s]: Aborting sync. %s', $prepare_sync->get_error_code(), $prepare_sync->get_error_message() ) );
			self::cleanup_sync();
			return false;
		}

		$sync_files = self::sync_files();
		if ( is_wp_error( $sync_files ) ) {
			self::$sync_status = self::SYNC_STATUS_FAILED;
			self::log( sprintf( '[%s]: Aborting sync. %s', $sync_files->get_error_code(), $sync_files->get_error_message() ) );
			self::cleanup_sync();
			return false;
		}

		if ( self::$sync_options['bookings_only'] ) {

			self::load();

		} else {

			self::parse();
			self::pre_sort();
			self::process();
			self::cleanup();
			self::sort();
		}

		self::apply_bookings();
		self::prepare_output();
		self::write();
		self::cleanup_sync();

		return json_encode( self::$output );
	}

	/**
	 * Load space file and bookings file into static variables
	 * @param  string $units_file    JSON file
	 * @param  string $bookings_file CSV file
	 * @return null
	 */
	static function load() {

		self::log( sprintf( '[%s]: Loading previously processed spaces.', __METHOD__ ) );

		// spaces
		$wp_upload_dir = wp_upload_dir();
		$units_file = $wp_upload_dir['basedir'] . BU_HAU_MEDIA_UNITS_JSON_FILE;
		$output_json = file_get_contents( $units_file );
		self::$output = json_decode( $output_json, true );
		self::$areas = self::$output['areas'];
		self::$space_types_counts = self::$output['spaceTypes'];

		// bookings
		self::parse_bookings( self::$bookings_file );
	}

	/**
	 * Parses the CSV parameter files into the static class vars for output.
	 *
	 * @param  string $space_file         CSV file
	 * @param  string $bookings_file      CSV file
	 * @param  string $housing_codes_file CSV file
	 * @return true                       when successful
	 */
	static function parse() {
		self::parse_spaces( self::$spaces_file );
		self::parse_bookings( self::$bookings_file );
		self::parse_housing_codes( self::$housing_codes_file );
		return true;
	}

	/**
	 * Parses the Spaces CSV file into format:
	 * [{ 'Room': 'something', 'Type': 'else' }, ... ]
	 * @uses self::$spaces adds parsed spaces
	 * @param  string $space_file         CSV file
	 * @return null
	 */
	static function parse_spaces( $space_file ) {

		self::log( sprintf( '[%s]: Parsing spaces.', __METHOD__ ), true );

		if ( file_exists( $space_file ) ) {
			if ( false !== ( $handle = fopen( $space_file , 'r' ) ) ) {
				$headers = fgetcsv( $handle, 0, ',' );
				$headers = array_map( 'trim', $headers );
				while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					$data = array_map( 'trim', $data );
					// format: { 'Room': 'something', 'Type': 'else' }
					$space = array();
					for ( $c = 0; $c < count( $data ); $c++ ) {
						$space[ $headers[ $c ] ] = $data[ $c ];
					}
					self::$spaces[] = $space;
				}
				fclose( $handle );
			}
		}
	}

	/**
	 * Parses the Bookings CSV file into format:
	 * [ 1, 2 ... 999 ]
	 * @uses self::$bookings adds parsed bookings
	 * @param  string $bookings_file CSV file
	 * @return null
	 */
	static function parse_bookings( $bookings_file ) {

		self::log( sprintf( '[%s]: Parsing bookings.', __METHOD__ ), true );

		if ( file_exists( $bookings_file ) ) {
			if ( false !== ( $handle = fopen( $bookings_file , 'r' ) ) ) {

				// ignore "Space ID" column header row
				$headers = fgetcsv( $handle, 0, ',' );
				if ( $headers[0] != 'Space ID' ) {
					$data = array_map( 'trim', $data );
					self::$bookings[] = $data[0];
				}

				while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					$data = array_map( 'trim', $data );
					// format: [ 1, 2 ... 999 ]
					self::$bookings[] = $data[0];
				}
				fclose( $handle );
			}
		}
	}

	/**
	 * Parses the Housing Codes CSV file into format:
	 * [
	 * 	CODE => Code Name,
	 * 	CODE2 => Code Name 2,
	 * 	...
	 * ]
	 * @uses self::$housing_codes adds parsed housing codes and descriptions
	 * @param  string $housing_codes_file CSV file
	 * @return null
	 */
	static function parse_housing_codes( $housing_codes_file ) {

		self::log( sprintf( '[%s]: Parsing specialty housing codes.', __METHOD__ ), true );

		if ( file_exists( $housing_codes_file ) ) {
			if ( false !== ( $handle = fopen( $housing_codes_file , 'r' ) ) ) {

				// ignore column header row
				$headers = fgetcsv( $handle, 0, ',' );

				while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					$data = array_map( 'trim', $data );

					if ( $data[2] == 'True' ) {
						// ensure the code is active
						// format: CODE => Code Name
						self::$housing_codes[ $data[0] ] = $data[1];
					}
				}
				fclose( $handle );
			}
		}
	}

	/**
	 * Spaces Sort Order:
	 * - Room Location Area
	 * - Room Location Summary
	 * - Room Location
	 * - Room Space Summary
	 * - Room Space
	 *
	 * @param  array $a left side comparison
	 * @param  array $b right side comparison
	 * @return -1,0,1   based on comparison
	 */
	static function spaces_sort_func( $a, $b ) {
		$rla_cmp = strcasecmp( $a['Room Location Area'], $b['Room Location Area'] );
		if ( $rla_cmp != 0 ) { return $rla_cmp; }

		$rls_cmp = strcasecmp( $a['Room Location Summary'], $b['Room Location Summary'] );
		if ( $rls_cmp != 0 ) { return $rls_cmp; }

		$ra_cmp = strnatcasecmp( $a['Room Location'], $b['Room Location'] );
		if ( $ra_cmp != 0 ) { return $ra_cmp; }

		$rss_cmp = strcasecmp( $a['Room Space Summary'], $b['Room Space Summary'] );
		if ( $rss_cmp != 0 ) { return $rss_cmp; }

		$rs_cmp = strnatcasecmp( $a['Room Space'], $b['Room Space'] );
		return $rs_cmp;
	}

	/**
	 * Presort the spaces
	 * @todo Do this after process() for less sorting calculations
	 * @return null
	 */
	static function pre_sort() {

		// Street addresses special
		// Gets rid of leading building numbers to do more meaningful comparisons of street names
		foreach ( self::$spaces as &$space ) {
			$space['Room Space Summary'] = preg_replace( '!^[0-9\s\-]*!', '', $space['Room Space'] );
			$space['Room Location Summary'] = preg_replace( '!^[0-9\s\-]*!', '', $space['Room Location'] );
		}

		usort( self::$spaces, array( __CLASS__, 'spaces_sort_func' ) );
	}

	/**
	 * Sort the counts
	 * @return null
	 */
	static function sort() {
			ksort( self::$housing_codes_counts );
			ksort( self::$space_types_counts );
	}

	/**
	 * Process the areas data into structured React-consumable data
	 * Does not apply any bookings. That happens in `apply_bookings()`.
	 * @return null
	 */
	static function process() {

		self::log( sprintf( '[%s]: Processing data.', __METHOD__ ), true );

		if ( isset( $_GET['hau_limit'] ) ) {
			array_splice( self::$spaces, intval( $_GET['hau_limit'] ) );
		}

		self::$areas = array();
		foreach ( self::$spaces as $space ) {

			// areas
			$area_id = $space['Room Location Area'];
			if ( ! isset( self::$areas[ $area_id ] ) ) {
				self::$areas[ $area_id ] = array(
					'areaID'                => $area_id,
					'buildings'             => array(),
					'roomCount'             => 0,
					'availableSpaceCount'   => 0,
					'totalSpaceCount'       => 0,
					'spacesAvailableByType' => array(
						'Apt'        => 0,
						'Dorm'       => 0,
						'Semi-Suite' => 0,
						'Studio'     => 0,
						'Suite'      => 0,
					),
				);
			}

			self::$areas[ $area_id ]['totalSpaceCount']++;

			$unit_type = self::get_unit_type( $space['Room Type'] );

			// Add any previously-unkonwn unit types (not mentioned above)
			if ( ! isset( self::$areas[ $area_id ]['spacesAvailableByType'][ $unit_type ] ) ) {
				self::$areas[ $area_id ]['spacesAvailableByType'][ $unit_type ] = 0;
			}

			// counts
			if ( ! isset( self::$space_types_counts[ $unit_type ] ) ) {
				self::$space_types_counts[ $unit_type ] = 0;
			}

			if ( ! isset( self::$gender_counts[ $space['Gender'] ] ) ) {
				self::$gender_counts[ $space['Gender'] ] = 0;
			}

			if ( ! in_array( $space['Room Location'], self::$areas[ $area_id ]['buildings'] ) ) {
				self::$areas[ $area_id ]['buildings'][] = $space['Room Location'];
			}

			// units
			$unit_id = $space['Room Location Floor Suite'];
			if ( ! isset( self::$areas[ $area_id ]['units'][ $unit_id ] ) ) {

				$specialty = self::get_specialty_code( $space['Specialty Cd'] );
				if ( $specialty ) {
					self::$housing_codes_counts[ $specialty ] = 0;
				}

				self::$areas[ $area_id ]['units'][ $unit_id ] = array(
					'unitID'          => $unit_id,
					'location'        => $space['Room Location'],
					'floor'           => preg_replace( self::GET_FIRST_HSV, '', $space['Room Location Section'] ),
					'suite'           => str_replace( $space['Short Name'] . '-', '', $unit_id ),
					'totalSpaces'     => 0,
					'availableSpaces' => 0,
					'gender'          => $space['Gender'],
					'specialty'       => $specialty,
					'floorplan'       => $space['Web Comments'],
					'unitType'        => $unit_type,
					'mealplan'        => $space['Mandatory Meal Plan Flag'] === 'True',
					'spacesAvailableBySize' => array(
						'Single' => 0,
						'Double' => 0,
						'Triple' => 0,
						'Quad'   => 0,
					),
				);

			}

			self::$areas[ $area_id ]['units'][ $unit_id ]['totalSpaces']++;

			// rooms
			$room = $space['Room Base'];
			if ( isset( self::$areas[ $area_id ]['units'][ $unit_id ]['rooms'][ $room ] ) ) {
				self::$areas[ $area_id ]['units'][ $unit_id ]['rooms'][ $room ]['spaceIDs'][] = $space['Space ID'];
			} else {
				// new room
				$room_size = self::get_room_size( $space['Room Type'] );

				$rate = '';
				if ( ! empty( $space['Room Type Code'] ) && ! empty( self::$rate_translation[ $space['Room Type Code'] ] ) ) {
					$rate_amt = intval( self::$rate_translation[ $space['Room Type Code'] ] );

					// Myles Standish Hall & Myles Annex discounted
					// for 2016/17 -- rate code unchanged
					if( "Myles Standish Hall" == self::$areas[ $area_id ]['units'][ $unit_id ]['location']
						|| "Myles Annex" == self::$areas[ $area_id ]['units'][ $unit_id ]['location'] ){
						$rate_amt = $rate_amt * 0.5;
					}
					$rate = '$' . number_format( $rate_amt, 0 ) . '/year';
				}

				self::$areas[ $area_id ]['roomCount']++;
				self::$areas[ $area_id ]['units'][ $unit_id ]['rooms'][ $room ] = array(
					'roomID'          => $room,
					'roomSize'        => $room_size,
					'room'            => preg_replace( self::GET_FIRST_HSV, '', $room ),
					'totalSpaces'     => 0,
					'availableSpaces' => 0,
					'spaceIDs'        => array( $space['Space ID'] ),
					'rate'            => $rate,
				);

				if ( ! isset( self::$room_size_counts[ $room_size ] ) ) {
					self::$room_size_counts[ $room_size ] = 0;
				}

				// Add any previously-unknown room sizes (not mentioned above)
				if ( ! isset( self::$areas[ $area_id ]['units'][ $unit_id ]['spacesAvailableBySize'][ $room_size ] ) ) {
					self::$areas[ $area_id ]['units'][ $unit_id ]['spacesAvailableBySize'][ $room_size ] = 0;
				}
			}

			self::$areas[ $area_id ]['units'][ $unit_id ]['rooms'][ $room ]['totalSpaces']++;

		}
		return true;
	}

	/**
	 * Apply bookings data to areas
	 * @return null
	 */
	static function apply_bookings() {

		self::log( sprintf( '[%s]: Applying bookings to spaces.', __METHOD__ ) );

		// reset counts
		$counts_arrays = array( 'space_types_counts', 'gender_counts', 'room_size_counts', 'housing_codes_counts' );
		foreach ( $counts_arrays as $counters ) {
			foreach ( self::$$counters as &$counter ) {
				$counter = 0;
			}
		}

		foreach ( self::$areas as &$area ) {
			$area['availableSpaceCount'] = $area['totalSpaceCount'];
			$area['spacesAvailableByType'] = array_map( '__return_zero', $area['spacesAvailableByType'] );

			foreach ( $area['units'] as &$unit ) {
				$unit['availableSpaces'] = $unit['totalSpaces'];
				$unit['spacesAvailableBySize'] = array_map( '__return_zero', $unit['spacesAvailableBySize'] );

				foreach ( $unit['rooms'] as &$room ) {
					$room['availableSpaces'] = $room['totalSpaces'];
					foreach ( $room['spaceIDs'] as $space_id ) {
						if ( in_array( $space_id, self::$bookings ) ) {
							// space is booked, update totals
							$area['availableSpaceCount']--;
							$unit['availableSpaces']--;
							$room['availableSpaces']--;

						} else {
							$area['spacesAvailableByType'][ $unit['unitType'] ]++;
							$unit['spacesAvailableBySize'][ $room['roomSize'] ]++;
							self::$space_types_counts[ $unit['unitType'] ]++;
							self::$gender_counts[ $unit['gender'] ]++;
							self::$room_size_counts[ $room['roomSize'] ]++;
							if ( ! empty( $unit['specialty'] ) && trim( $unit['specialty'] ) ) {
								self::$housing_codes_counts[ $unit['specialty'] ]++;
							}
						}
					}
				}
				unset( $room );
			}
			unset( $unit );
		}
		unset( $area );
	}

	/**
	 * Specifies in text if we're syncing bookings only or all/everything.
	 * @return string
	 */
	static function get_sync_type() {
		return self::$sync_options['bookings_only'] ? 'bookings only' : 'all';
	}

	/**
	 * Returns a matching housing code with $code using self::$housing_codes
	 * @param  string $code 3-letter code
	 * @return string       definition if found
	 */
	static function get_specialty_code( $code ) {
		return ! empty( self::$housing_codes[ $code ] ) ? self::$housing_codes[ $code ] : '';
	}

	/**
	 * Room Types are weirdly stored. Sometimes they're at the end, except when:
	 * * There is a abbreviated 2 letter code at the end
	 * * Or when it ends with the words Paddle.
	 *
	 * @param  string $room_type format: Apt-4Person-Single
	 * @return string            Single
	 */
	static function get_room_size( $room_type ) {
		if ( preg_match( '!-([A-Za-z]*)-([A-Z]{2})!', $room_type, $matches ) ) {
			$room_size = $matches[1];
		} else if ( stripos( $room_type, '-Paddle' ) !== false ) {
			$room_size = preg_replace( '/.*-([^-]+)-Paddle/', '\1', $room_type );
		} else {
			$room_size = preg_replace( self::GET_FIRST_HSV, '', $room_type );
		}
		return $room_size;
	}

	/**
	 * Parse unit type (Apt, Suite, Semi-Suite, etc.) from Room Type strings:
	 * - Apt-4Person-Single
	 * - Suite-4Person-Single
	 * - Semi-Suite-Double
	 *
	 * @param  string $room_type examples above
	 * @return string            [Apt|Dorm|Semi-Suite|Studio|Suite]
	 */
	static function get_unit_type( $room_type ) {

		$unit_type = preg_replace( self::GET_LAST_HSV, '', $room_type );
		if ( $unit_type == 'Semi' ) {
			$unit_type = 'Semi-Suite';
		}

		return $unit_type;
	}

	/**
	 * Writes the output to a WP media dir (relative to BU_HAU_MEDIA_DIR) file
	 * @return null
	 */
	static function write() {
		$wp_upload_dir = wp_upload_dir();
		$units_json_file = $wp_upload_dir['basedir'] . BU_HAU_MEDIA_UNITS_JSON_FILE;
		$units_js_file = $wp_upload_dir['basedir'] . BU_HAU_MEDIA_UNITS_JS_FILE;

		self::write_contents( $units_json_file, json_encode( self::$output ) );
		self::write_contents( $units_js_file, 'var _bootstrap = ' . json_encode( self::$output ) );

		self::log( sprintf( '[%s]: Saving processed files.', __METHOD__ ) );
		self::$sync_status = self::SYNC_STATUS_SUCCESS;
	}

	static function write_contents( $file, $contents ) {
		$path = dirname( $file );

		if ( ! file_exists( $path ) ) {
			if ( ! wp_mkdir_p( $path ) ) {
				self::log( __METHOD__ . ': Failed to create dir for writing processed data: ' . $path );
				return false;
			}
		}

		$handle = fopen( $file, 'w+' );
		fwrite( $handle, $contents );
		fclose( $handle );
	}

	/**
	 * Cleans up the key/value pairs to be only value
	 * @return true
	 */
	static function cleanup() {
		self::$areas = array_values( self::$areas );

		foreach ( self::$areas as &$area ) {
			$area['units'] = array_values( $area['units'] );
			foreach ( $area['units'] as &$unit ) {
				$unit['rooms'] = array_values( $unit['rooms'] );
			}
		}
		return true;
	}

	/**
	 * Combines the final output from static class vars
	 * @return null
	 */
	static function prepare_output() {

		self::log( sprintf( '[%s]: Preparing output.', __METHOD__ ) );

		self::$output = array(
			'createTime'   => BU_HAU_Sync_Lock::get_instance()->get_start_time( true ),
			'spaceTypes'   => self::$space_types_counts,
			'housingCodes' => self::$housing_codes_counts,
			'gender'       => self::$gender_counts,
			'roomSize'     => self::$room_size_counts,
			'areas'        => self::$areas,
		);
	}

	/**
	 * Logs messages into an array and then saves to WP
	 *
	 * @param  string  $msg          Log message
	 * @param  boolean $ensure_debug Only error_log when using debug
	 * @return boolean               if log got saved into WP
	 */
	static function log( $msg, $ensure_debug = false ) {
		if ( ! $ensure_debug || self::$debug ) {
			error_log( $msg );
		}

		array_unshift( self::$log, array(
			'time' => time(),
			'msg'  => $msg,
		) );

		return self::save_log();
	}

	/**
	 * Save log to an option
	 * * Allows continuous updates based on the start time, just call the func
	 * @return boolean saved or not
	 */
	static function save_log() {

		$option_name = self::PREFIX . 'sync_log';

		$option          = get_option( $option_name, array() );
		$sync_log        = array(
			'start_time' => self::$start_time,
			'log'        => self::$log,
			'status'     => self::$sync_status,
		);

		if ( ! empty( $option[0] ) && $option[0]['start_time'] == self::$start_time ) {
			$option = array_slice( $option, 1 );
		}

		array_unshift( $option, $sync_log );

		// keep only the first 100 entries (chronologically last)
		$option = array_slice( $option, 0, 100 );

		return update_option( $option_name, $option );
	}
}
