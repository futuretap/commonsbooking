<?php


namespace CommonsBooking;

use CommonsBooking\CB\CB1UserFields;
use CommonsBooking\Helper\Wordpress;
use CommonsBooking\Map\LocationMapAdmin;
use CommonsBooking\Messages\AdminMessage;
use CommonsBooking\Model\Booking;
use CommonsBooking\Model\BookingCode;
use CommonsBooking\Service\Cache;
use CommonsBooking\Service\Scheduler;
use CommonsBooking\Service\iCalendar;
use CommonsBooking\Settings\Settings;
use CommonsBooking\Repository\BookingCodes;
use CommonsBooking\View\Dashboard;
use CommonsBooking\Wordpress\CustomPostType\CustomPostType;
use CommonsBooking\Wordpress\CustomPostType\Item;
use CommonsBooking\Wordpress\CustomPostType\Location;
use CommonsBooking\Wordpress\CustomPostType\Map;
use CommonsBooking\Wordpress\CustomPostType\Restriction;
use CommonsBooking\Wordpress\CustomPostType\Timeframe;
use CommonsBooking\Wordpress\Options\AdminOptions;
use CommonsBooking\Wordpress\Options\OptionsTab;
use CommonsBooking\Wordpress\PostStatus\PostStatus;

class Plugin {

	use Cache;

	/**
	 * CB-Manager id.
     *
	 * @var string
	 */
	public static $CB_MANAGER_ID = 'cb_manager';

	/**
	 * Plugin activation tasks.
	 */
	public static function activation() {
		// Register custom user roles (e.g. cb_manager)
		self::addCustomUserRoles();

		// add role caps for custom post types
		self::addCPTRoleCaps();

		// Init booking codes table
		BookingCodes::initBookingCodesTable();

		self::clearCache();
	}

	/**
	 * Plugin deactivation tasks.
	 */
	public static function deactivation() {
		do_action( Scheduler::UNSCHEDULER_HOOK );
	}

	protected static function addCPTRoleCaps() {
		$customPostTypes = commonsbooking_isCurrentUserAdmin() ? self::getCustomPostTypes() : self::getCBManagerCustomPostTypes();

		// Add capabilities for user roles
		foreach ( $customPostTypes as $customPostType ) {
			self::addRoleCaps( $customPostType::$postType );
		}
	}

	/**
	 * Returns needed roles and caps.
     *
	 * @return \bool[][]
	 */
	public static function getRoleCapMapping() {
		return [
			self::$CB_MANAGER_ID => [
				'read'                                 => true,
				'manage_' . COMMONSBOOKING_PLUGIN_SLUG => true,
			],
			'administrator'      => [
				'read'                                 => true,
				'edit_posts'                           => true,
				'manage_' . COMMONSBOOKING_PLUGIN_SLUG => true,
			],
		];
	}

	/**
	 * Adds cb user roles to WordPress.
	 */
	public static function addCustomUserRoles() {
		foreach ( self::getRoleCapMapping() as $roleName => $caps ) {
			$role = get_role( $roleName );
			if ( ! $role ) {
				$role = add_role(
					$roleName,
                    // TODO we should set a translatable role display name - for now its not defined at any place
					$roleName
				);
			}

			foreach ( $caps as $cap => $grant ) {
				$role->remove_cap( $cap );
				$role->add_cap( $cap, $grant );
			}
		}
	}

	/**
	 * Will get all registered custom post types for this plugin as an instance of the CustomPostType class
	 * All CustomPostType classes extend the CustomPostType class and must be registered in this method.
	 * When defining a CustomPostType, you must also define a model for it, which extends the CustomPost class.
	 * The existence of a model is checked in the @see PluginTest::testGetCustomPostTypes() test.
	 * @return CustomPostType[]
	 */
	public static function getCustomPostTypes(): array {
		return [
			new Item(),
			new Location(),
			new Timeframe(),
			new Map(),
			new \CommonsBooking\Wordpress\CustomPostType\Booking(),
			new Restriction(),
		];
	}

	/**
	 * Tests if a given post belongs to our CPTs
	 * @param $post int|\WP_Post - post id or post object
	 *
	 * @return bool
	 */
	public static function isPostCustomPostType($post): bool {
		if (is_int($post)) {
			$post = get_post($post);
		}

		if ( empty( $post ) ) {
			return false;
		}

		$validPostTypes = self::getCustomPostTypesLabels();
		return in_array($post->post_type,$validPostTypes);
	}

	/**
	 * Returns only custom post types, which are allowed for cb manager
     *
	 * @return array
	 */
	public static function getCBManagerCustomPostTypes(): array {
		return [
			new Item(),
			new Location(),
			new Timeframe(),
			new \CommonsBooking\Wordpress\CustomPostType\Booking(),
			new Restriction(),
		];
	}

	/**
	 * Adds permissions for cb users.
	 *
	 * @param $postType
	 */
	public static function addRoleCaps( $postType ) {
		// Add the roles you'd like to administer the custom post types
		$roles = array_keys( self::getRoleCapMapping() );

		// Loop through each role and assign capabilities
		foreach ( $roles as $the_role ) {
			$role = get_role( $the_role );
			if ( $role ) {
				$role->add_cap( 'read_' . $postType );
				$role->add_cap( 'manage_' . COMMONSBOOKING_PLUGIN_SLUG . '_' . $postType );

				$role->add_cap( 'edit_' . $postType );
				$role->add_cap( 'edit_' . $postType . 's' ); // show item list
				$role->add_cap( 'edit_private_' . $postType . 's' );
				$role->add_cap( 'edit_published_' . $postType . 's' );

				$role->add_cap( 'publish_' . $postType . 's' );

				$role->add_cap( 'delete_' . $postType );
				$role->add_cap( 'delete_' . $postType . 's' );

				$role->add_cap( 'read_private_' . $postType . 's' );
				$role->add_cap( 'edit_others_' . $postType . 's' );
				$role->add_cap( 'delete_private_' . $postType . 's' );
				$role->add_cap( 'delete_published_' . $postType . 's' ); // delete user post
				$role->add_cap( 'delete_others_' . $postType . 's' );

				$role->add_cap( 'edit_posts' ); // general: create posts -> even wp_post, affects all cpts
				$role->add_cap( 'upload_files' ); // general: change post image

				if ( $the_role == self::$CB_MANAGER_ID ) {
					$role->remove_cap( 'read_private_' . $postType . 's' );
					$role->remove_cap( 'delete_private_' . $postType . 's' );
					$role->remove_cap( 'delete_others_' . $postType . 's' );
				}
			}
		}
	}

	public static function admin_init() {
		// check if we have a new version and run tasks
		self::runTasksAfterUpdate();

		// Check if we need to run post options updated actions
		if ( get_transient( 'commonsbooking_options_saved' ) == 1 ) {
			AdminOptions::SetOptionsDefaultValues();

			flush_rewrite_rules();
			set_transient( 'commonsbooking_options_saved', 0 );
		}
	}

	/**
	 * Check if plugin is installed or updated an run tasks
	 */
	public static function runTasksAfterUpdate() {
		$commonsbooking_version_option    = COMMONSBOOKING_PLUGIN_SLUG . '_plugin_version';
		$commonsbooking_installed_version = esc_html( get_option( $commonsbooking_version_option ) );

		// check if installed version differs from plugin version in database
		if ( COMMONSBOOKING_VERSION != $commonsbooking_installed_version or ! isset( $commonsbooking_installed_version ) ) {

			// reset greyed out color when upgrading, see issue #1121
			Settings::updateOption( 'commonsbooking_options_templates', 'colorscheme_greyedoutcolor', '#e0e0e0' );
			Settings::updateOption( 'commonsbooking_options_templates', 'colorscheme_lighttext', '#a0a0a0');

			// reset iCalendar Titles when upgrading, see issue #1251
			$eventTitle = Settings::getOption( 'commonsbooking_options_templates', 'emailtemplates_mail-booking_ics_event-title' );
			$otherEventTitle = Settings::getOption( COMMONSBOOKING_PLUGIN_SLUG . '_options_advanced-options', 'event_title' );
			if ( str_contains( $eventTitle, 'post_name' ) ){
				$updatedString = str_replace( 'post_name', 'post_title', $eventTitle );
				Settings::updateOption( 'commonsbooking_options_templates', 'emailtemplates_mail-booking_ics_event-title', $updatedString );
			}
			if ( str_contains( $otherEventTitle, 'post_name' ) ){
				$updatedString = str_replace( 'post_name', 'post_title', $otherEventTitle );
				Settings::updateOption( COMMONSBOOKING_PLUGIN_SLUG . '_options_advanced-options', 'event_title', $updatedString );
			}

			// set Options default values (e.g. if there are new fields added)
			AdminOptions::SetOptionsDefaultValues();

			// flush rewrite rules
			flush_rewrite_rules();

			// Update Location Coordinates
			self::updateLocationCoordinates();

			// add role caps for custom post types
			self::addCPTRoleCaps();

			// update version number in options
			update_option( $commonsbooking_version_option, COMMONSBOOKING_VERSION );

			// migrate bookings to new cpt
			\CommonsBooking\Migration\Booking::migrate();

            // Set default values to existing timeframes for advance booking days
            self::setAdvanceBookingDaysDefault();

			// Clear cache
			self::clearCache();

			// unschedules deprecated cronjobs
			Scheduler::unscheduleOldEvents();

		}
	}

	/**
	 * Adds menu pages.
	 */
	public static function addMenuPages() {
		// Dashboard
		add_menu_page(
			'Commons Booking',
			'Commons Booking',
			'manage_' . COMMONSBOOKING_PLUGIN_SLUG,
			'cb-dashboard',
			array( Dashboard::class, 'index' ),
			'data:image/svg+xml;base64,' . base64_encode( '<?xml version="1.0" encoding="UTF-8" standalone="no"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg width="100%" height="100%" viewBox="0 0 32 32" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xml:space="preserve" xmlns:serif="http://www.serif.com/"><path fill="black" d="M12.94,5.68l0,-5.158l6.132,1.352l0,5.641c0.856,-0.207 1.787,-0.31 2.792,-0.31c3.233,0 5.731,1.017 7.493,3.05c1.762,2.034 2.643,4.661 2.643,7.88l0,0.458c0,3.232 -0.884,5.862 -2.653,7.89c-1.769,2.027 -4.283,3.04 -7.542,3.04c-1.566,0 -2.965,-0.268 -4.196,-0.806c1.449,-1.329 2.491,-2.998 3.015,-4.546c0.335,0.123 0.729,0.185 1.181,0.185c1.311,0 2.222,-0.51 2.732,-1.53c0.51,-1.021 0.765,-2.432 0.765,-4.233l0,-0.458c0,-1.749 -0.255,-3.146 -0.765,-4.193c-0.51,-1.047 -1.401,-1.57 -2.673,-1.57c-0.527,0 -0.978,0.107 -1.351,0.321c-1.051,-3.59 -4.047,-6.125 -7.573,-7.013Zm6.06,15.774c0.05,0.153 0.042,0.325 0.042,0.338c-0.001,2.138 -0.918,4.209 -2.516,5.584c-0.172,0.148 -0.346,0.288 -0.523,0.42c-0.209,-0.153 -0.411,-0.316 -0.608,-0.489c-1.676,-1.477 -2.487,-3.388 -2.434,-5.733l0.039,-0.12l6,0Zm-6.06,-13.799c3.351,1.058 5.949,3.88 6.092,7.332c0.011,0.254 0.11,0.416 -0.032,0.843l-6,0l-0.036,-0.108l-0.024,0l0,-8.067Z" /><path fill="black" d="M21.805,24.356c-0.901,0 -1.57,-0.245 -2.008,-0.735c-0.437,-0.491 -0.656,-1.213 -0.656,-2.167l-6.141,0l-0.039,0.12c-0.053,2.345 0.758,4.256 2.434,5.733c1.676,1.478 3.813,2.216 6.41,2.216c3.259,0 5.773,-1.013 7.542,-3.04c1.769,-2.028 2.653,-4.658 2.653,-7.89l0,-0.458c0,-3.219 -6.698,-1.749 -6.698,0l0,0.458c0,1.801 -0.255,3.212 -0.765,4.233c-0.51,1.02 -1.421,1.53 -2.732,1.53Z" /><path fill="black" d="M14.244,28.78c-1.195,0.495 -2.545,0.743 -4.049,0.743c-3.259,0 -5.773,-1.013 -7.542,-3.04c-1.769,-2.028 -2.653,-4.658 -2.653,-7.89l0,-0.458c0,-3.219 0.881,-5.846 2.643,-7.88c1.762,-2.033 4.26,-3.05 7.493,-3.05c0.917,0 1.773,0.086 2.566,0.258c1.566,0.34 2.891,1.016 3.972,2.027c1.63,1.524 2.418,3.597 2.365,6.221l-0.039,0.119l-6.141,0c0,-1.02 -0.226,-1.852 -0.676,-2.494c-0.451,-0.643 -1.133,-0.964 -2.047,-0.964c-1.272,0 -2.163,0.523 -2.673,1.57c-0.51,1.047 -0.765,2.444 -0.765,4.193l0,0.458c0,1.801 0.255,3.212 0.765,4.233c0.51,1.02 1.421,1.53 2.732,1.53c0.32,0 0.61,-0.031 0.871,-0.093c0.517,1.648 1.73,3.281 3.178,4.517Zm-1.244,-7.326l6,0l0.039,0.12c0.053,2.345 -0.758,4.256 -2.434,5.733c-0.134,0.118 -0.27,0.231 -0.409,0.339c-1.85,-1.327 -3.122,-3.233 -3.227,-5.424c-0.011,-0.228 -0.105,-0.357 0.031,-0.768Z" /></svg>' )
		);
		add_submenu_page(
			'cb-dashboard',
			'Dashboard',
			'Dashboard',
			'manage_' . COMMONSBOOKING_PLUGIN_SLUG,
			'cb-dashboard',
			array( Dashboard::class, 'index' ),
			0
		);

		// Custom post types
		$customPostTypes = commonsbooking_isCurrentUserAdmin() ? self::getCustomPostTypes() : self::getCBManagerCustomPostTypes();
		foreach ( $customPostTypes as $cbCustomPostType ) {
			$params = $cbCustomPostType->getMenuParams();
			add_submenu_page(
				$params[0],
				$params[1],
				$params[2],
				$params[3] . '_' . $cbCustomPostType::$postType,
				$params[4],
				$params[5],
				$params[6]
			);
		}

		// Show categories only for admins
		if ( commonsbooking_isCurrentUserAdmin() ) {
			// Add menu item for item categories
			add_submenu_page(
				'cb-dashboard',
				esc_html__( 'Item Categories', 'commonsbooking' ),
				esc_html__( 'Item Categories', 'commonsbooking' ),
				'manage_' . COMMONSBOOKING_PLUGIN_SLUG,
				admin_url( 'edit-tags.php' ) . '?taxonomy=' . Item::$postType . 's_category',
				''
			);

			// Add menu item for location categories
			add_submenu_page(
				'cb-dashboard',
				esc_html__( 'Location Categories', 'commonsbooking' ),
				esc_html__( 'Location Categories', 'commonsbooking' ),
				'manage_' . COMMONSBOOKING_PLUGIN_SLUG,
				admin_url( 'edit-tags.php' ) . '?taxonomy=' . Location::$postType . 's_category',
				''
			);
		}
	}

	/**
	 * Filters the CSS classes for the body tag in the admin.
	 *
	 * @param string $classes
	 * @return string
	 */
	public static function filterAdminBodyClass( $classes ) {
		global $current_screen, $plugin_page;

		$cssClass = 'cb-admin';

		if ( $plugin_page === 'cb-dashboard' ) {
			return $classes . ' ' . $cssClass;
		}

		switch ( $current_screen->post_type ) {
			case \CommonsBooking\Wordpress\CustomPostType\Booking::$postType:
			case Item::$postType:
			case Location::$postType:
			case Map::$postType:
			case Restriction::$postType:
			case Timeframe::$postType:
				return $classes . ' ' . $cssClass;
		}

		switch ( $current_screen->taxonomy ) {
			case 'cb_items_category':
			case 'cb_locations_category':
				return $classes . ' ' . $cssClass;
		}

		return $classes;
	}

	/**
	 * Registers custom post types.
	 */
	public static function registerCustomPostTypes() {
		foreach ( self::getCustomPostTypes() as $customPostType ) {
			register_post_type( $customPostType::getPostType(), $customPostType->getArgs() );
			$customPostType->initListView();
			$customPostType->initHooks();
		}
	}

	/**
	 * Registers additional post statuses.
	 */
	public static function registerPostStates() {
		foreach ( Booking::$bookingStates as $bookingState ) {
			new PostStatus( $bookingState, __( ucfirst( $bookingState ), 'commonsbooking' ) );
		}
	}

    /**
	 * Registers category taxonomy for Custom Post Type Item
     *
	 * @return void
	 */
	public static function registerItemTaxonomy() {
		$customPostType = Item::getPostType();

		$result = register_taxonomy(
			$customPostType . 's_category',
			$customPostType,
			array(
				'label'        => esc_html__( 'Item Category', 'commonsbooking' ),
				'rewrite'      => array( 'slug' => $customPostType . '-cat' ),
				'hierarchical' => true,
				'show_in_rest' => true,
			)
		);

		// If error, yell about it.
		if ( is_wp_error( $result ) ) {
			wp_die( $result->get_error_message() );
		}
	}

	/**
	 * Registers category taxonomy for Custom Post Type Location
     *
	 * @return void
	 */
	public static function registerLocationTaxonomy() {
		$customPostType = Location::getPostType();

		$result = register_taxonomy(
			$customPostType . 's_category',
			$customPostType,
			array(
				'label'        => esc_html__( 'Location Category', 'commonsbooking' ),
				'rewrite'      => array( 'slug' => $customPostType . '-cat' ),
				'hierarchical' => true,
				'show_in_rest' => true,
			)
		);

		// If error, yell about it.
		if ( is_wp_error( $result ) ) {
			wp_die( $result->get_error_message() );
		}
	}

	/**
	 * Renders error for backend_notice.
	 */
	public static function renderError() {
		$errorTypes = [
			Model\Timeframe::ERROR_TYPE,
			BookingCode::ERROR_TYPE,
			OptionsTab::ERROR_TYPE,
            Model\Booking::ERROR_TYPE,
		];

		foreach ( $errorTypes as $errorType ) {
			if ( $error = get_transient( $errorType ) ) {
				$class = 'notice notice-error';
				printf(
					'<div class="%1$s"><p>%2$s</p></div>',
					esc_attr( $class ),
					commonsbooking_sanitizeHTML( $error )
				);
				delete_transient( $errorType );
			}
		}
	}

	/**
	 * Enable Legacy CB1 profile fields.
	 */
	public static function maybeEnableCB1UserFields() {
		$enabled = Settings::getOption( 'commonsbooking_options_migration', 'enable-cb1-user-fields' );
		if ( $enabled == 'on' ) {
			new CB1UserFields();
		}
	}

	/**
	 * run actions after plugin options are saved
	 * TODOD: @markus-mw I think this function is deprecated now. Would you please check this? It is only referenced by an inactive hook
	 */
	public static function saveOptionsActions() {
		// Run actions after options update
		set_transient( 'commonsbooking_options_saved', 1 );
	}

	/**
	 * Register Admin-Options
	 */
	public static function registerAdminOptions() {
		$options_array = include COMMONSBOOKING_PLUGIN_DIR . '/includes/OptionsArray.php';
		foreach ( $options_array as $tab_id => $tab ) {
			new OptionsTab( $tab_id, $tab );
		}
	}

	/**
	 * Gets location position for locations without coordinates.
	 */
	public static function updateLocationCoordinates() {
		$locations = Repository\Location::get();

		foreach ( $locations as $location ) {
			if ( ! ( $location->getMeta( 'geo_latitude' ) && $location->getMeta( 'geo_longitude' ) ) ) {
				$location->updateGeoLocation();
			}
		}
	}

	/**
	 *  Init hooks.
	 */
	public function init() {
		do_action( 'cmb2_init' );

		// Enable CB1 User Fields (needed in case of migration from cb 0.9.x)
		add_action( 'init', array( self::class, 'maybeEnableCB1UserFields' ) );

		// Register custom post types
		add_action( 'init', array( self::class, 'registerCustomPostTypes' ), 0 );
		add_action( 'init', array( self::class, 'registerPostStates' ), 0 );

		// register admin options page
		add_action( 'init', array( self::class, 'registerAdminOptions' ), 0 );

		// Register custom post types taxonomy / categories
		add_action( 'init', array( self::class, 'registerItemTaxonomy' ), 30 );

		// Register custom post types taxonomy / categories
		add_action( 'init', array( self::class, 'registerLocationTaxonomy' ), 30 );

		// loads the Scheduler
		add_action( 'init', array( Scheduler::class, 'initHooks' ) );

		// admin init tasks
		add_action( 'admin_init', array( self::class, 'admin_init' ), 30 );

		// Add menu pages
		add_action( 'admin_menu', array( self::class, 'addMenuPages' ) );

		// Filter body classes of admin pages
		add_filter( 'admin_body_class', array( self::class, 'filterAdminBodyClass' ), 10, 1 );

		// Parent Menu Fix
		add_filter( 'parent_file', array( $this, 'setParentFile' ) );

		// Remove cache items on save.
		add_action( 'wp_insert_post', array( $this, 'savePostActions' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( Cache::class, 'addWarmupAjaxToOutput' ) );
		add_action( 'admin_enqueue_scripts', array( Cache::class, 'addWarmupAjaxToOutput' ) );

		add_action('plugins_loaded', array($this, 'commonsbooking_load_textdomain'), 20);

		$map_admin = new LocationMapAdmin();
		add_action( 'plugins_loaded', array( $map_admin, 'load_location_map_admin' ) );

		// register User Widget
		add_action( 'widgets_init', array( $this, 'registerUserWidget' ) );

		// remove Row Actions
		add_filter( 'post_row_actions', array( CustomPostType::class, 'modifyRowActions' ), 10, 2 );

		// add custom image sizes
		add_action( 'after_setup_theme', array( $this, 'AddImageSizes' ) );

		// renders custom update notice on plugin listing
		add_action(
            'in_plugin_update_message-' . COMMONSBOOKING_PLUGIN_BASE,
            function ( $plugin_data ) {
                $this->UpdateNotice( COMMONSBOOKING_VERSION, $plugin_data['new_version'] );
            }
        );

        // add ajax search for cmb2 fields (e.g. user search etc.)
        add_filter('cmb2_field_ajax_search_url', function(){
            return (COMMONSBOOKING_PLUGIN_URL . '/vendor/ed-itsolutions/cmb2-field-ajax-search/');
        });

    	// iCal rewrite
		iCalendar::initRewrite();

	}

	/**
	 * Loads text domain for (from local file or wordpress plugin-dir)
	 *
	 * @return void
	 */
	public function commonsbooking_load_textdomain() {
		/**
		 * We want to ensure that new translations are available directly after update
		 * so we load the local translation first if its available, otherwise we use the load_plugin_textdomain
		 * to load from the global WordPress translation file.
		 */

		$locale                  = get_locale();
		$locale_translation_file = COMMONSBOOKING_PLUGIN_DIR . 'languages/' . COMMONSBOOKING_PLUGIN_SLUG . '-' . $locale . '.mo';

		if ( file_exists( $locale_translation_file ) ) {
			load_textdomain( COMMONSBOOKING_PLUGIN_SLUG, $locale_translation_file );
		} else {
			load_plugin_textdomain( 'commonsbooking', false, COMMONSBOOKING_PLUGIN_DIR . 'languages' );
		}
	}

	/**
	 * Removes cache item in connection to post_type.
     *
	 * @TODO: Add test if cache is cleared correctly.
	 *
	 * @param $post_id
	 * @param $post
	 * @param $update
	 *
	 * @throws \Psr\Cache\InvalidArgumentException
	 */
	public function savePostActions( $post_id, $post, $update ) {
		if ( ! in_array( $post->post_type, self::getCustomPostTypesLabels() ) ) {
			return;
		}

		$ignoredStates = [ 'auto-draft', 'draft' ];
		if ( ! in_array( $post->post_status, $ignoredStates ) || $update ) {
			$tags   = Wordpress::getRelatedPostIds( $post_id );
			$tags[] = 'misc';
			self::clearCache( $tags );
		}
	}

	/**
	 * @return array
	 */
	public static function getCustomPostTypesLabels(): array {
		return [
			Item::getPostType(),
			Location::getPostType(),
			Timeframe::getPostType(),
			Map::getPostType(),
			Restriction::getPostType(),
			\CommonsBooking\Wordpress\CustomPostType\Booking::getPostType(),
		];
	}

	/**
	 * Function to register our new routes from the controller.
	 */
	/**
	 * Function to register our new routes from the controller.
	 */
	public function initRoutes() {
		// Check if API is activated in settings
		$api_activated = Settings::getOption( 'commonsbooking_options_api', 'api-activated' );
		if ( $api_activated != 'on' ) {
			return false;
		}

		add_action(
			'rest_api_init',
			function () {
				$routes = [
					new \CommonsBooking\API\AvailabilityRoute(),
					new \CommonsBooking\API\ItemsRoute(),
					new \CommonsBooking\API\LocationsRoute(),
					// new \CommonsBooking\API\OwnersRoute(),
					new \CommonsBooking\API\ProjectsRoute(),
					new \CommonsBooking\API\GBFS\Discovery(),
					new \CommonsBooking\API\GBFS\StationInformation(),
					new \CommonsBooking\API\GBFS\StationStatus(),
					new \CommonsBooking\API\GBFS\SystemInformation(),

				];
				foreach ( $routes as $route ) {
					$route->register_routes();
				}
			}
		);
	}

	/**
	 * Adds bookingcode actions.
	 */
	public function initBookingcodes() {
		add_action( 'before_delete_post', array( BookingCodes::class, 'deleteBookingCodes' ), 10 );
		add_action( 'admin_action_csvexport', array( View\BookingCodes::class, 'renderCSV' ), 10, 0 );
	}

	/**
	 * Fixes highlighting issue for cpt views.
	 *
	 * @param $parent_file
	 *
	 * @return string
	 */
	public function setParentFile( $parent_file ): string {
		global $current_screen;

		// Set 'cb-dashboard' as parent for cb post types
		if ( in_array( $current_screen->base, array( 'post', 'edit' ) ) ) {
			foreach ( self::getCustomPostTypes() as $customPostType ) {
				if ( $customPostType::getPostType() === $current_screen->post_type ) {
					return 'cb-dashboard';
				}
			}
		}

		// Set 'cb-dashboard' as parent for cb categories
		if ( in_array( $current_screen->base, array( 'edit-tags' ) ) ) {
			if (
				$current_screen->taxonomy && in_array(
                    $current_screen->taxonomy,
                    [
						Location::$postType . 's_category',
						Item::$postType . 's_category',
                    ]
                )
			) {
				return 'cb-dashboard';
			}
		}

		return $parent_file;
	}

	/**
	 * Appends view data to content.
	 *
	 * @param $content
	 *
	 * @return string
	 */
	public function getTheContent( $content ): string {
		// Check if we're inside the main loop in a single post page.
		if ( is_single() && in_the_loop() && is_main_query() ) {
			global $post;
			foreach ( self::getCustomPostTypes() as $customPostType ) {
				if ( $customPostType::getPostType() === $post->post_type ) {
					return $content . $customPostType::getView()::content( $post );
				}
			}
		}

		return $content;
	}

	public function registerUserWidget() {
		register_widget( '\CommonsBooking\Wordpress\Widget\UserWidget' );
	}


	function AddImageSizes() {

		$crop = Settings::getOption( 'commonsbooking_options_templates', 'image_listing_crop' ) == 'on' ? true : false;

		// image size for small item and location post images in listings
		add_image_size(
			'cb_listing_small',
			Settings::getOption( 'commonsbooking_options_templates', 'image_listing_small_width' ),
			Settings::getOption( 'commonsbooking_options_templates', 'image_listing_small_height' ),
			$crop
		);

		// image size for medium item and location post images in listings
		add_image_size(
			'cb_listing_medium',
			Settings::getOption( 'commonsbooking_options_templates', 'image_listing_medium_width' ),
			Settings::getOption( 'commonsbooking_options_templates', 'image_listing_medium_height' ),
			$crop
		);
	}

	/**
	 * renders a custom update notice in plugin list if the version number increases
	 * in a major release e.g. 2.5 -> 2.6
	 *
	 * @param  mixed $current_version
	 * @param  mixed $new_version
	 * @return void
	 */
	function UpdateNotice( $current_version, $new_version ) {

		$current_version_minor_part = explode( '.', $current_version )[1];
		$new_version_minor_part     = explode( '.', $new_version )[1];

		if ( $current_version_minor_part === $new_version_minor_part ) {
			return;
		}

        ?>
		<hr class="cb-major-update-warning__separator" />
		<div class="cb-major-update-warning">
			<div class="cb-major-update-warning__icon">
				<i class="dashicons dashicons-megaphone"></i>
			</div>
			<div>
				<div class="cb-major-update-warning__title">
					<?php echo esc_html__( 'New features and changes: Please backup before upgrade!', 'commonsbooking' ); ?>
				</div>
				<div class="e-major-update-warning__message">
					<?php
					printf(
						/* translators: %1$s Link open tag, %2$s: Link close tag. */
						commonsbooking_sanitizeHTML(
                            __(
                                '
					This CommonsBooking update has a lot of new features and changes on some templates.<br>
					If you have modified any template files, please backup and check them after update. <br>
                    This update contains new features like reminder emails, restriction management, advance booking limits and more.
					<br><br>We highly recommend you to <strong>%1$sread the update information%2$s </strong> and make a backup of your site before upgrading.',
                                'commonsbooking'
                            )
                        ),
						'<a target="_blank" href="https://commonsbooking.org/docs/installation/update-info/">',
						'</a>'
					);
					?>
				</div>
			</div>
		</div>
        <?php
	}

    /**
     * sets advance booking days to default value for existing timeframes.
     * Advances booking timeframes are available since 2.6 - all timeframes created prior to this version need to have this value set to a default value.
     * @see \CommonsBooking\Wordpress\CustomPostType\Timeframe::ADVANCE_BOOKING_DAYS
     *
     * @return void
     */
    public static function setAdvanceBookingDaysDefault() {
        $timeframes = \CommonsBooking\Repository\Timeframe::getBookable( [], [], null, true );

        foreach ( $timeframes as $timeframe ) {
            if ( $timeframe->getMeta( 'timeframe-advance-booking-days' ) < 1 ) {
                update_post_meta( $timeframe->ID, 'timeframe-advance-booking-days', strval( \CommonsBooking\Wordpress\CustomPostType\Timeframe::ADVANCE_BOOKING_DAYS ) );
            }
        }
    }

}
