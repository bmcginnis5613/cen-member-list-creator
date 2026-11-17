<?php
/**
 * Plugin Name: CEN Member List Creator
 * Description: Build a reviewable membership roster from user profiles and manual company entries, then download it as a PDF.
 * Version: 1.4.1
 * Author: FirstTracks Marketing
 * Author URI: https://firsttracksmarketing.com
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CEN_MLV_VERSION', '1.4.1' );
define( 'CEN_MLV_PLUGIN_FILE', __FILE__ );
define( 'CEN_MLV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CEN_MLV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CEN_MLV_PLUGIN_DIR . 'includes/class-cen-mlv-pdf.php';

add_action( 'admin_menu', 'cen_mlv_register_tools_page' );
add_action( 'admin_enqueue_scripts', 'cen_mlv_enqueue_admin_assets' );
add_action( 'wp_ajax_cen_mlv_load_members', 'cen_mlv_ajax_load_members' );
add_action( 'wp_ajax_cen_mlv_search_members', 'cen_mlv_ajax_search_members' );
add_action( 'admin_post_cen_mlv_download_pdf', 'cen_mlv_handle_pdf_download' );

/**
 * Add the roster builder beneath WordPress Tools.
 */
function cen_mlv_register_tools_page() {
	add_management_page(
		__( 'Member List Creator', 'cen-member-list-pdf' ),
		__( 'Member List Creator', 'cen-member-list-pdf' ),
		'manage_options',
		'cen-member-list-pdf',
		'cen_mlv_render_admin_page'
	);
}

/**
 * Load the small admin-only script and stylesheet.
 *
 * @param string $hook_suffix Current admin screen hook.
 */
function cen_mlv_enqueue_admin_assets( $hook_suffix ) {
	if ( 'tools_page_cen-member-list-pdf' !== $hook_suffix ) {
		return;
	}

	// Load ACF's Select2-based field UI for the roster-filter multi-selects.
	if ( function_exists( 'acf_enqueue_scripts' ) ) {
		acf_enqueue_scripts();
	}

	wp_enqueue_style(
		'cen-mlv-admin',
		CEN_MLV_PLUGIN_URL . 'assets/admin.css',
		array(),
		CEN_MLV_VERSION
	);
	wp_enqueue_script(
		'cen-mlv-admin',
		CEN_MLV_PLUGIN_URL . 'assets/admin.js',
		array(),
		CEN_MLV_VERSION,
		true
	);
	wp_localize_script(
		'cen-mlv-admin',
		'CEN_MLV',
		array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'loadNonce'   => wp_create_nonce( 'cen_mlv_load_members' ),
			'searchNonce' => wp_create_nonce( 'cen_mlv_search_members' ),
			'strings'     => array(
				'loading'           => __( 'Loading members...', 'cen-member-list-pdf' ),
				'loadError'         => __( 'The selected filters could not be loaded.', 'cen-member-list-pdf' ),
				'searchError'       => __( 'Member search failed.', 'cen-member-list-pdf' ),
				'chooseFilter'      => __( 'Select at least one group number or local board.', 'cen-member-list-pdf' ),
				'empty'             => __( 'No members or companies are selected yet.', 'cen-member-list-pdf' ),
				'oneMember'         => __( '1 list entry selected', 'cen-member-list-pdf' ),
				'manyMembers'       => __( '%d list entries selected', 'cen-member-list-pdf' ),
				'oneMissingCompany' => __( '1 selected member has no company.', 'cen-member-list-pdf' ),
				'manyMissingCompany' => __( '%d selected members have no company.', 'cen-member-list-pdf' ),
				'oneMissingDescription' => __( '1 selected member has no company description.', 'cen-member-list-pdf' ),
				'manyMissingDescription' => __( '%d selected members have no company description.', 'cen-member-list-pdf' ),
				'addedIndividually' => __( 'Added individually', 'cen-member-list-pdf' ),
				'enteredManually'   => __( 'Entered manually', 'cen-member-list-pdf' ),
				'manualCompany'     => __( 'Manual company', 'cen-member-list-pdf' ),
				'companyRequired'   => __( 'Enter a company name.', 'cen-member-list-pdf' ),
				'descriptionRequired' => __( 'Enter a company description.', 'cen-member-list-pdf' ),
				'companyAdded'      => __( 'Company added to the list.', 'cen-member-list-pdf' ),
				'alreadyAdded'      => __( 'Already added', 'cen-member-list-pdf' ),
				'add'               => __( 'Add', 'cen-member-list-pdf' ),
				'remove'            => __( 'Remove', 'cen-member-list-pdf' ),
				'editUser'          => __( 'Edit WordPress user %d', 'cen-member-list-pdf' ),
				'noResults'         => __( 'No members found.', 'cen-member-list-pdf' ),
			),
		)
	);
}

/**
 * Find an ACF field by its field name, including nested fields.
 *
 * @param array<int,array<string,mixed>> $fields     ACF fields.
 * @param string                         $field_name  Field name.
 * @param string                         $field_label Optional field label.
 * @return array<string,mixed>
 */
function cen_mlv_find_acf_field_by_name( $fields, $field_name, $field_label = '' ) {
	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}

		$name_matches = isset( $field['name'] ) && $field_name === (string) $field['name'];
		$label_matches = '' !== $field_label && isset( $field['label'] ) && 0 === strcasecmp( $field_label, cen_mlv_clean_text( $field['label'] ) );
		if ( $name_matches || $label_matches ) {
			return $field;
		}

		if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			$match = cen_mlv_find_acf_field_by_name( $field['sub_fields'], $field_name, $field_label );
			if ( ! empty( $match ) ) {
				return $match;
			}
		}
	}

	return array();
}

/**
 * Return an ACF field definition, discovering it by name when no key is known.
 *
 * @param string $field_name  ACF field name.
 * @param string $field_key   Optional ACF field key.
 * @param string $field_label Optional ACF field label.
 * @return array<string,mixed>
 */
function cen_mlv_get_acf_field_definition( $field_name, $field_key = '', $field_label = '' ) {
	static $field_cache = array();
	$cache_key = $field_name . '|' . $field_key . '|' . $field_label;
	if ( array_key_exists( $cache_key, $field_cache ) ) {
		return $field_cache[ $cache_key ];
	}

	if ( '' !== $field_key && function_exists( 'get_field_object' ) ) {
		$field = get_field_object( $field_key, false, false, false );
		if ( is_array( $field ) ) {
			$field_cache[ $cache_key ] = $field;
			return $field_cache[ $cache_key ];
		}
	}

	if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
		$field_cache[ $cache_key ] = array();
		return $field_cache[ $cache_key ];
	}

	foreach ( (array) acf_get_field_groups() as $field_group ) {
		$fields = acf_get_fields( $field_group );
		if ( ! is_array( $fields ) ) {
			continue;
		}

		$field = cen_mlv_find_acf_field_by_name( $fields, $field_name, $field_label );
		if ( ! empty( $field ) ) {
			$field_cache[ $cache_key ] = $field;
			return $field_cache[ $cache_key ];
		}
	}

	$field_cache[ $cache_key ] = array();
	return $field_cache[ $cache_key ];
}

/**
 * Return live choices from an ACF select-style field.
 *
 * @param string $field_name  ACF field name.
 * @param string $field_key   Optional ACF field key.
 * @param string $field_label Optional ACF field label.
 * @return array<string,string>
 */
function cen_mlv_get_acf_choice_options( $field_name, $field_key = '', $field_label = '' ) {
	$field = cen_mlv_get_acf_field_definition( $field_name, $field_key, $field_label );
	$options = isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : array();
	$clean_options = array();

	foreach ( $options as $value => $label ) {
		$value = cen_mlv_normalize_filter_value( $value );
		if ( '' === $value ) {
			continue;
		}

		$label = cen_mlv_clean_text( $label );
		$clean_options[ $value ] = '' !== $label ? $label : $value;
	}

	uksort( $clean_options, 'strnatcasecmp' );

	return $clean_options;
}

/**
 * Return the selectable ACF group numbers.
 *
 * @return array<string,string>
 */
function cen_mlv_get_group_number_options() {
	$field_key = (string) apply_filters( 'cen_mlv_group_number_acf_field_key', 'field_6a7ddc6aed794' );
	$options = cen_mlv_get_acf_choice_options( 'group_number', $field_key );

	$clean_options = array();
	foreach ( (array) apply_filters( 'cen_mlv_group_number_options', $options ) as $value => $label ) {
		$number = cen_mlv_normalize_group_number( $value );
		if ( '' === $number ) {
			continue;
		}

		$clean_label = cen_mlv_clean_text( $label );
		if ( '' === $clean_label ) {
			$clean_label = $number;
		}
		$clean_options[ $number ] = $clean_label;
	}
	uksort(
		$clean_options,
		static function ( $left, $right ) {
			$sort_keys = array(
				'10XM' => '10XM-0',
				'11XM' => '10XM-1',
			);
			$left_key = isset( $sort_keys[ $left ] ) ? $sort_keys[ $left ] : $left;
			$right_key = isset( $sort_keys[ $right ] ) ? $sort_keys[ $right ] : $right;

			return strnatcasecmp( $left_key, $right_key );
		}
	);

	return $clean_options;
}

/**
 * Return the selectable local boards from the live ACF field definition.
 *
 * The field is discovered by name because its generated ACF key may differ
 * between environments. A key can still be supplied with the filter below.
 *
 * @return array<string,string>
 */
function cen_mlv_get_local_board_options() {
	$field_key = (string) apply_filters( 'cen_mlv_local_board_acf_field_key', '' );
	$options = cen_mlv_get_acf_choice_options( 'local_board', $field_key, 'Local Board' );
	$filtered_options = (array) apply_filters( 'cen_mlv_local_board_options', $options );
	$clean_options = array();
	foreach ( $filtered_options as $value => $label ) {
		$value = cen_mlv_normalize_filter_value( $value );
		if ( '' === $value ) {
			continue;
		}

		$label = cen_mlv_clean_text( $label );
		$clean_options[ $value ] = '' !== $label ? $label : $value;
	}
	uksort( $clean_options, 'strnatcasecmp' );

	return $clean_options;
}

/**
 * Return the discovered local-board field's user-meta key.
 *
 * @return string
 */
function cen_mlv_get_local_board_meta_key() {
	$field_key = (string) apply_filters( 'cen_mlv_local_board_acf_field_key', '' );
	$field = cen_mlv_get_acf_field_definition( 'local_board', $field_key, 'Local Board' );
	$meta_key = isset( $field['name'] ) ? (string) $field['name'] : 'local_board';

	return (string) apply_filters( 'cen_mlv_local_board_meta_key', $meta_key );
}

/**
 * Normalize a WordPress text value for display and export.
 *
 * @param mixed $value Source value.
 * @return string
 */
function cen_mlv_clean_text( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	return trim( wp_specialchars_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES ) );
}

/**
 * Normalize a group number stored in the user profile.
 *
 * @param mixed $value Group number.
 * @return string
 */
function cen_mlv_normalize_group_number( $value ) {
	$number = strtoupper( cen_mlv_clean_text( $value ) );

	return (string) preg_replace( '/[^A-Z0-9_-]/', '', $number );
}

/**
 * Normalize an ACF choice value without changing its meaning.
 *
 * @param mixed $value Choice value.
 * @return string
 */
function cen_mlv_normalize_filter_value( $value ) {
	return cen_mlv_clean_text( $value );
}

/**
 * Read a raw ACF user value, with a user-meta fallback.
 *
 * @param int    $user_id   User ID.
 * @param string $field_name ACF field name/user-meta key.
 * @param string $field_key  ACF field key.
 * @return mixed
 */
function cen_mlv_get_acf_user_value( $user_id, $field_name, $field_key ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return '';
	}

	$value = get_user_meta( $user_id, $field_name, true );
	if ( '' !== $value && null !== $value && false !== $value && array() !== $value ) {
		return $value;
	}

	if ( function_exists( 'get_field' ) && '' !== $field_key ) {
		$value = get_field( $field_key, 'user_' . $user_id, false );
		if ( null !== $value && false !== $value ) {
			return $value;
		}
	}

	return '';
}

/**
 * Return all ACF group numbers assigned to a user.
 *
 * @param int $user_id User ID.
 * @return string[]
 */
function cen_mlv_get_user_group_numbers( $user_id ) {
	$field_name = (string) apply_filters( 'cen_mlv_group_number_meta_key', 'group_number' );
	$field_key = (string) apply_filters( 'cen_mlv_group_number_acf_field_key', 'field_6a7ddc6aed794' );
	$value = cen_mlv_get_acf_user_value( $user_id, $field_name, $field_key );
	$value = is_string( $value ) ? maybe_unserialize( $value ) : $value;
	$raw_numbers = array();

	if ( is_array( $value ) ) {
		array_walk_recursive(
			$value,
			static function ( $number ) use ( &$raw_numbers ) {
				$raw_numbers[] = $number;
			}
		);
	} elseif ( is_scalar( $value ) ) {
		$raw_numbers = preg_split( '/[\s,]+/', (string) $value );
	}

	$numbers = array_values( array_unique( array_filter( array_map( 'cen_mlv_normalize_group_number', $raw_numbers ) ) ) );

	return (array) apply_filters( 'cen_mlv_user_group_numbers', $numbers, absint( $user_id ) );
}

/**
 * Return all local boards assigned to a user.
 *
 * @param int $user_id User ID.
 * @return string[]
 */
function cen_mlv_get_user_local_boards( $user_id ) {
	$field_name = cen_mlv_get_local_board_meta_key();
	$field = cen_mlv_get_acf_field_definition( 'local_board', (string) apply_filters( 'cen_mlv_local_board_acf_field_key', '' ), 'Local Board' );
	$field_key = isset( $field['key'] ) ? (string) $field['key'] : '';
	$value = cen_mlv_get_acf_user_value( $user_id, $field_name, $field_key );
	$value = is_string( $value ) ? maybe_unserialize( $value ) : $value;
	$raw_boards = array();

	if ( is_array( $value ) ) {
		array_walk_recursive(
			$value,
			static function ( $board ) use ( &$raw_boards ) {
				$raw_boards[] = $board;
			}
		);
	} elseif ( is_scalar( $value ) ) {
		$raw_boards[] = $value;
	}

	$boards = array_values( array_unique( array_filter( array_map( 'cen_mlv_normalize_filter_value', $raw_boards ) ) ) );

	return (array) apply_filters( 'cen_mlv_user_local_boards', $boards, absint( $user_id ) );
}

/**
 * Return the user's ACF member type.
 *
 * @param int $user_id User ID.
 * @return string
 */
function cen_mlv_get_user_member_type( $user_id ) {
	$field_name = (string) apply_filters( 'cen_mlv_member_type_meta_key', 'member_type' );
	$field_key = (string) apply_filters( 'cen_mlv_member_type_acf_field_key', 'field_6a7c995f271bd' );
	$member_type = cen_mlv_clean_text( cen_mlv_get_acf_user_value( $user_id, $field_name, $field_key ) );

	return (string) apply_filters( 'cen_mlv_user_member_type', $member_type, absint( $user_id ) );
}

/**
 * Determine whether a user may appear in the roster builder or PDF.
 *
 * Prospects and users without a member type are excluded. BuddyBoss
 * suspension status does not affect roster eligibility.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function cen_mlv_is_user_eligible( $user_id ) {
	$user_id = absint( $user_id );
	$member_type = cen_mlv_get_user_member_type( $user_id );
	$eligible = $user_id
		&& '' !== $member_type
		&& 0 !== strcasecmp( 'Prospect', $member_type )
		&& 0 !== strcasecmp( 'Null', $member_type );

	return (bool) apply_filters( 'cen_mlv_is_user_eligible', $eligible, $user_id, $member_type );
}

/**
 * Return the user's full name using the same fallback as the conflict sender.
 *
 * @param WP_User $user WordPress user.
 * @return string
 */
function cen_mlv_get_user_full_name( $user ) {
	if ( function_exists( 'cen_ccs_get_user_full_name' ) ) {
		return cen_ccs_get_user_full_name( $user );
	}

	$first_name = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
	$last_name = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );
	$full_name = trim( $first_name . ' ' . $last_name );

	return '' !== $full_name ? $full_name : trim( (string) $user->display_name );
}

/**
 * Return the xProfile company value, reusing the existing plugin when active.
 *
 * @param int $user_id User ID.
 * @return string
 */
function cen_mlv_get_user_company( $user_id ) {
	if ( function_exists( 'cen_ccs_get_user_company' ) ) {
		return cen_ccs_get_user_company( $user_id );
	}

	if ( ! function_exists( 'xprofile_get_field_data' ) ) {
		return '';
	}

	$default_field_id = defined( 'CEN_CCS_XPROFILE_COMPANY_FIELD_ID' ) ? CEN_CCS_XPROFILE_COMPANY_FIELD_ID : 5;
	$field_id = absint( apply_filters( 'cen_mlv_company_field_id', $default_field_id ) );
	$value = $field_id ? xprofile_get_field_data( $field_id, absint( $user_id ), 'comma' ) : '';

	return cen_mlv_clean_text( $value );
}

/**
 * Read the company description from its ACF user field.
 *
 * ACF stores the value under the field name and a hidden `_field_name` user
 * meta record containing the field key. The reference lookup keeps this
 * working even when ACF itself is temporarily unavailable.
 *
 * @param int $user_id User ID.
 * @return string
 */
function cen_mlv_get_acf_company_description( $user_id ) {
	$user_id = absint( $user_id );
	$field_key = (string) apply_filters( 'cen_mlv_company_description_acf_field_key', 'field_6a57e414af13d' );
	if ( ! $user_id || '' === $field_key ) {
		return '';
	}

	if ( function_exists( 'get_field' ) ) {
		$description = cen_mlv_clean_text( get_field( $field_key, 'user_' . $user_id, false ) );
		if ( '' !== $description ) {
			return $description;
		}
	}

	$description = cen_mlv_clean_text( get_user_meta( $user_id, $field_key, true ) );
	if ( '' !== $description ) {
		return $description;
	}

	$all_meta = get_user_meta( $user_id );
	foreach ( $all_meta as $meta_key => $values ) {
		if ( 0 !== strpos( (string) $meta_key, '_' ) || ! is_array( $values ) ) {
			continue;
		}
		$references = array_map( 'strval', $values );
		if ( ! in_array( $field_key, $references, true ) ) {
			continue;
		}

		$value_key = substr( (string) $meta_key, 1 );
		$description = cen_mlv_clean_text( get_user_meta( $user_id, $value_key, true ) );
		if ( '' !== $description ) {
			return $description;
		}
	}

	return '';
}

/**
 * Locate an optional company-description xProfile field by common names.
 *
 * @return int
 */
function cen_mlv_get_company_description_field_id() {
	static $resolved_id = null;

	if ( null !== $resolved_id ) {
		return $resolved_id;
	}

	$explicit_id = absint( apply_filters( 'cen_mlv_company_description_field_id', 0 ) );
	if ( $explicit_id ) {
		$resolved_id = $explicit_id;
		return $resolved_id;
	}

	$resolved_id = 0;
	if ( ! function_exists( 'xprofile_get_field_id_from_name' ) ) {
		return $resolved_id;
	}

	$field_names = apply_filters(
		'cen_mlv_company_description_field_names',
		array( 'Company Description', 'Company Overview', 'Description of Company' )
	);
	foreach ( (array) $field_names as $field_name ) {
		$field_id = absint( xprofile_get_field_id_from_name( (string) $field_name ) );
		if ( $field_id ) {
			$resolved_id = $field_id;
			break;
		}
	}

	return $resolved_id;
}

/**
 * Return an optional company description for the PDF's second column.
 *
 * @param int $user_id User ID.
 * @return string
 */
function cen_mlv_get_user_company_description( $user_id ) {
	$description = cen_mlv_get_acf_company_description( $user_id );
	if ( '' === $description ) {
		$field_id = cen_mlv_get_company_description_field_id();
		if ( $field_id && function_exists( 'xprofile_get_field_data' ) ) {
			$description = cen_mlv_clean_text( xprofile_get_field_data( $field_id, absint( $user_id ), 'comma' ) );
		}
	}

	if ( '' === $description ) {
		$meta_keys = apply_filters( 'cen_mlv_company_description_meta_keys', array( 'company_description', 'cen_company_description' ) );
		foreach ( (array) $meta_keys as $meta_key ) {
			$description = cen_mlv_clean_text( get_user_meta( absint( $user_id ), (string) $meta_key, true ) );
			if ( '' !== $description ) {
				break;
			}
		}
	}

	return (string) apply_filters( 'cen_mlv_user_company_description', $description, absint( $user_id ) );
}

/**
 * Convert a WordPress user into the JSON shape used by the review table.
 *
 * @param WP_User $user User object.
 * @param string[] $group_names Groups that supplied the user.
 * @return array<string,mixed>
 */
function cen_mlv_get_member_payload( $user, $group_names = array() ) {
	$company = cen_mlv_get_user_company( $user->ID );
	$group_names = array_values( array_unique( array_filter( array_map( 'cen_mlv_clean_text', (array) $group_names ) ) ) );

	return array(
		'id'          => absint( $user->ID ),
		'editUrl'     => get_edit_user_link( $user->ID ),
		'name'        => cen_mlv_get_user_full_name( $user ),
		'email'       => sanitize_email( $user->user_email ),
		'company'     => $company,
		'description' => cen_mlv_get_user_company_description( $user->ID ),
		'groups'      => $group_names,
		'label'       => sprintf(
			'%1$s%2$s',
			cen_mlv_get_user_full_name( $user ),
			'' !== $company ? ' — ' . $company : ''
		),
	);
}

/**
 * Verify the current AJAX request.
 *
 * @param string $nonce_action Nonce action.
 */
function cen_mlv_verify_ajax_request( $nonce_action ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Access denied.', 'cen-member-list-pdf' ) ), 403 );
	}
	check_ajax_referer( $nonce_action, 'nonce' );
}

/**
 * Load and merge members assigned to selected ACF group numbers or local boards.
 */
function cen_mlv_ajax_load_members() {
	cen_mlv_verify_ajax_request( 'cen_mlv_load_members' );

	$requested_numbers = isset( $_GET['group_numbers'] ) ? (array) wp_unslash( $_GET['group_numbers'] ) : array();
	$requested_numbers = array_values( array_unique( array_filter( array_map( 'cen_mlv_normalize_group_number', $requested_numbers ) ) ) );
	$allowed_numbers = array_keys( cen_mlv_get_group_number_options() );
	$group_numbers = array_values( array_intersect( $requested_numbers, $allowed_numbers ) );

	$requested_boards = isset( $_GET['local_boards'] ) ? (array) wp_unslash( $_GET['local_boards'] ) : array();
	$requested_boards = array_values( array_unique( array_filter( array_map( 'cen_mlv_normalize_filter_value', $requested_boards ) ) ) );
	$local_board_options = cen_mlv_get_local_board_options();
	$local_boards = array_values( array_intersect( $requested_boards, array_keys( $local_board_options ) ) );
	if ( empty( $group_numbers ) && empty( $local_boards ) ) {
		wp_send_json_error( array( 'message' => __( 'Select at least one valid group number or local board.', 'cen-member-list-pdf' ) ), 400 );
	}

	$filter_meta_query = array( 'relation' => 'OR' );
	$group_meta_key = (string) apply_filters( 'cen_mlv_group_number_meta_key', 'group_number' );
	foreach ( $group_numbers as $group_number ) {
		// ACF multi-selects are serialized; equality also supports legacy scalar values.
		$filter_meta_query[] = array(
			'key'     => $group_meta_key,
			'value'   => '"' . $group_number . '"',
			'compare' => 'LIKE',
		);
		$filter_meta_query[] = array(
			'key'     => $group_meta_key,
			'value'   => $group_number,
			'compare' => '=',
		);
	}
	$local_board_meta_key = cen_mlv_get_local_board_meta_key();
	foreach ( $local_boards as $local_board ) {
		$filter_meta_query[] = array(
			'key'     => $local_board_meta_key,
			'value'   => '"' . $local_board . '"',
			'compare' => 'LIKE',
		);
		$filter_meta_query[] = array(
			'key'     => $local_board_meta_key,
			'value'   => $local_board,
			'compare' => '=',
		);
	}

	$query = new WP_User_Query(
		array(
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'fields'      => array( 'ID', 'display_name', 'user_email' ),
			'count_total' => false,
			'meta_query'  => $filter_meta_query,
		)
	);
	$items = array();
	$seen_user_ids = array();
	foreach ( $query->get_results() as $user ) {
		$user_id = isset( $user->ID ) ? absint( $user->ID ) : 0;
		if ( ! $user_id || isset( $seen_user_ids[ $user_id ] ) || ! cen_mlv_is_user_eligible( $user_id ) ) {
			continue;
		}

		$matching_numbers = array_values( array_intersect( $group_numbers, cen_mlv_get_user_group_numbers( $user_id ) ) );
		$matching_boards = array_values( array_intersect( $local_boards, cen_mlv_get_user_local_boards( $user_id ) ) );
		if ( ! empty( $matching_numbers ) || ! empty( $matching_boards ) ) {
			$sources = array();
			foreach ( $matching_numbers as $number ) {
				$sources[] = sprintf( __( 'Group: %s', 'cen-member-list-pdf' ), $number );
			}
			foreach ( $matching_boards as $board ) {
				$sources[] = sprintf( __( 'Local Board: %s', 'cen-member-list-pdf' ), $local_board_options[ $board ] );
			}
			$seen_user_ids[ $user_id ] = true;
			$items[] = cen_mlv_get_member_payload( $user, $sources );
		}
	}
	usort( $items, 'cen_mlv_compare_member_payloads' );

	wp_send_json_success( array( 'members' => $items ) );
}

/**
 * Search WordPress members for individual additions.
 */
function cen_mlv_ajax_search_members() {
	cen_mlv_verify_ajax_request( 'cen_mlv_search_members' );

	$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
	if ( strlen( $term ) < 2 ) {
		wp_send_json_success( array() );
	}

	$query = new WP_User_Query(
		array(
			'number'         => 50,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
			'search'         => '*' . $term . '*',
			'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			'fields'         => array( 'ID', 'display_name', 'user_email' ),
		)
	);
	$items = array();
	foreach ( $query->get_results() as $user ) {
		if ( cen_mlv_is_user_eligible( $user->ID ) ) {
			$items[] = cen_mlv_get_member_payload( $user );
			if ( 20 === count( $items ) ) {
				break;
			}
		}
	}

	wp_send_json_success( $items );
}

/**
 * Sort member payloads by company, then member name.
 *
 * @param array<string,mixed> $left Left item.
 * @param array<string,mixed> $right Right item.
 * @return int
 */
function cen_mlv_compare_member_payloads( $left, $right ) {
	$left_company = isset( $left['company'] ) ? (string) $left['company'] : '';
	$right_company = isset( $right['company'] ) ? (string) $right['company'] : '';
	$company_order = strnatcasecmp( $left_company, $right_company );
	if ( 0 !== $company_order ) {
		return $company_order;
	}

	return strnatcasecmp( (string) $left['name'], (string) $right['name'] );
}

/**
 * Remove duplicate companies from the final PDF rows.
 *
 * The review screen intentionally remains member-based. Only the exported
 * company roster is collapsed. Blank-company rows remain distinct so members
 * without company data are not silently lost.
 *
 * @param array<int,array<string,mixed>> $rows PDF rows.
 * @return array<int,array<string,mixed>>
 */
function cen_mlv_dedupe_pdf_rows( $rows ) {
	$companies = array();
	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$company = isset( $row['company'] ) ? cen_mlv_clean_text( $row['company'] ) : '';
		$row['company'] = $company;
		if ( '' === $company ) {
			$user_id = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : count( $companies );
			$key = '__missing_company_' . $user_id;
		} else {
			$normalized = preg_replace( '/\s+/u', ' ', $company );
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $normalized, 'UTF-8' ) : strtolower( $normalized );
		}

		if ( ! isset( $companies[ $key ] ) ) {
			$companies[ $key ] = $row;
			continue;
		}

		$current_description = isset( $companies[ $key ]['description'] ) ? cen_mlv_clean_text( $companies[ $key ]['description'] ) : '';
		$candidate_description = isset( $row['description'] ) ? cen_mlv_clean_text( $row['description'] ) : '';
		if ( strlen( $candidate_description ) > strlen( $current_description ) ) {
			$companies[ $key ]['description'] = $candidate_description;
		}
	}

	return array_values( $companies );
}

/**
 * Read and sanitize companies entered directly on the roster builder.
 *
 * @return array<int,array<string,mixed>>
 */
function cen_mlv_get_posted_manual_companies() {
	$posted_companies = isset( $_POST['manual_companies'] ) ? (array) wp_unslash( $_POST['manual_companies'] ) : array();
	$rows = array();

	foreach ( array_slice( $posted_companies, 0, 500 ) as $company ) {
		if ( ! is_array( $company ) ) {
			continue;
		}

		$name = isset( $company['company'] ) ? cen_mlv_clean_text( $company['company'] ) : '';
		$description = isset( $company['description'] ) ? cen_mlv_clean_text( $company['description'] ) : '';
		if ( '' === $name || '' === $description ) {
			continue;
		}

		$rows[] = array(
			'user_id'     => 0,
			'member_name' => '',
			'company'     => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 200 ) : substr( $name, 0, 200 ),
			'description' => function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 5000 ) : substr( $description, 0, 5000 ),
			'manual'      => true,
		);
	}

	return $rows;
}

/**
 * Render the Tools screen.
 */
function cen_mlv_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to create member-list PDFs.', 'cen-member-list-pdf' ) );
	}

	$group_numbers = cen_mlv_get_group_number_options();
	$local_boards = cen_mlv_get_local_board_options();
	$local_board_field = cen_mlv_get_acf_field_definition( 'local_board', (string) apply_filters( 'cen_mlv_local_board_acf_field_key', '' ), 'Local Board' );
	$local_board_field_name = isset( $local_board_field['name'] ) ? (string) $local_board_field['name'] : 'local_board';
	$local_board_field_key = isset( $local_board_field['key'] ) ? (string) $local_board_field['key'] : '';
	$has_roster_filters = ! empty( $group_numbers ) || ! empty( $local_boards );
	?>
	<div class="wrap cen-mlv-wrap">
		<h1><?php esc_html_e( 'Member List Creator', 'cen-member-list-pdf' ); ?></h1>
		<p class="cen-mlv-intro"><?php esc_html_e( 'Select profile group numbers or local boards, add individual members or companies, review the combined list, and download the membership roster PDF.', 'cen-member-list-pdf' ); ?></p>

		<?php if ( ! $has_roster_filters ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'No Group Number or Local Board choices are configured in ACF.', 'cen-member-list-pdf' ); ?></p></div>
		<?php endif; ?>

		<div class="cen-mlv-builder">
			<section class="cen-mlv-card" aria-labelledby="cen-mlv-groups-heading">
				<h2 id="cen-mlv-groups-heading"><?php esc_html_e( '1. Choose groups or local boards', 'cen-member-list-pdf' ); ?></h2>
				<p class="description" id="cen-mlv-group-help"><?php esc_html_e( 'Search or click to add one or more values from the live ACF fields.', 'cen-member-list-pdf' ); ?></p>
				<label class="cen-mlv-filter-label" for="cen-mlv-groups"><?php esc_html_e( 'Group Number', 'cen-member-list-pdf' ); ?></label>
				<div class="acf-field acf-field-select acf-field-6a7ddc6aed794 cen-mlv-group-number-field" data-name="group_number" data-type="select" data-key="field_6a7ddc6aed794">
					<div class="acf-input">
						<select
							id="cen-mlv-groups"
							class="cen-mlv-groups"
							name="cen_mlv_group_numbers[]"
							multiple
							size="12"
							data-ui="1"
							data-multiple="1"
							data-placeholder="<?php echo esc_attr__( 'Select group numbers', 'cen-member-list-pdf' ); ?>"
							data-allow_null="1"
							data-ajax="0"
							aria-describedby="cen-mlv-group-help"
							<?php disabled( empty( $group_numbers ) ); ?>
						>
							<?php foreach ( $group_numbers as $group_number => $label ) : ?>
								<option value="<?php echo esc_attr( $group_number ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<label class="cen-mlv-filter-label" for="cen-mlv-local-boards"><?php esc_html_e( 'Local Board', 'cen-member-list-pdf' ); ?></label>
				<div class="acf-field acf-field-select cen-mlv-group-number-field" data-name="<?php echo esc_attr( $local_board_field_name ); ?>" data-type="select"<?php echo '' !== $local_board_field_key ? ' data-key="' . esc_attr( $local_board_field_key ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute components are escaped. ?>>
					<div class="acf-input">
						<select
							id="cen-mlv-local-boards"
							class="cen-mlv-groups"
							name="cen_mlv_local_boards[]"
							multiple
							size="12"
							data-ui="1"
							data-multiple="1"
							data-placeholder="<?php echo esc_attr__( 'Select local boards', 'cen-member-list-pdf' ); ?>"
							data-allow_null="1"
							data-ajax="0"
							aria-describedby="cen-mlv-group-help"
							<?php disabled( empty( $local_boards ) ); ?>
						>
							<?php foreach ( $local_boards as $local_board => $label ) : ?>
								<option value="<?php echo esc_attr( $local_board ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<p class="description"><?php esc_html_e( 'Prospects and users with no Member Type are excluded. BuddyBoss suspension status is ignored.', 'cen-member-list-pdf' ); ?></p>
				<p class="cen-mlv-actions">
					<button type="button" class="button button-primary" id="cen-mlv-load-groups" <?php disabled( ! $has_roster_filters ); ?>><?php esc_html_e( 'Load selected filters', 'cen-member-list-pdf' ); ?></button>
					<button type="button" class="button" id="cen-mlv-clear"><?php esc_html_e( 'Clear list', 'cen-member-list-pdf' ); ?></button>
				</p>
				<div id="cen-mlv-load-status" class="cen-mlv-status" role="status" aria-live="polite"></div>
			</section>

			<section class="cen-mlv-card" aria-labelledby="cen-mlv-search-heading">
				<h2 id="cen-mlv-search-heading"><?php esc_html_e( '2. Add individual members', 'cen-member-list-pdf' ); ?></h2>
				<label class="screen-reader-text" for="cen-mlv-search"><?php esc_html_e( 'Search members', 'cen-member-list-pdf' ); ?></label>
				<input type="search" id="cen-mlv-search" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr__( 'Search by name or email...', 'cen-member-list-pdf' ); ?>">
				<p class="description"><?php esc_html_e( 'Type at least two characters, then add members one at a time.', 'cen-member-list-pdf' ); ?></p>
				<div id="cen-mlv-search-results" class="cen-mlv-search-results" role="listbox" hidden></div>
			</section>

			<section class="cen-mlv-card" aria-labelledby="cen-mlv-manual-company-heading">
				<h2 id="cen-mlv-manual-company-heading"><?php esc_html_e( '3. Add a company manually', 'cen-member-list-pdf' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Use this for a company that is not associated with a member in the database.', 'cen-member-list-pdf' ); ?></p>
				<div class="cen-mlv-manual-company-fields">
					<label for="cen-mlv-manual-company-name"><?php esc_html_e( 'Company name', 'cen-member-list-pdf' ); ?></label>
					<input type="text" id="cen-mlv-manual-company-name" maxlength="200" autocomplete="organization">
					<label for="cen-mlv-manual-company-description"><?php esc_html_e( 'Company description', 'cen-member-list-pdf' ); ?></label>
					<textarea id="cen-mlv-manual-company-description" rows="5" maxlength="5000"></textarea>
				</div>
				<p class="cen-mlv-actions">
					<button type="button" class="button button-primary" id="cen-mlv-add-company"><?php esc_html_e( 'Add company to list', 'cen-member-list-pdf' ); ?></button>
				</p>
				<div id="cen-mlv-manual-company-status" class="cen-mlv-status" role="status" aria-live="polite"></div>
			</section>
		</div>

		<section class="cen-mlv-card cen-mlv-review" aria-labelledby="cen-mlv-review-heading">
			<div class="cen-mlv-review-heading">
				<div>
					<h2 id="cen-mlv-review-heading"><?php esc_html_e( '4. Review list', 'cen-member-list-pdf' ); ?></h2>
					<p id="cen-mlv-count" class="description"><?php esc_html_e( 'No members or companies are selected yet.', 'cen-member-list-pdf' ); ?></p>
					<p id="cen-mlv-company-warning" class="cen-mlv-warning" hidden></p>
					<p id="cen-mlv-description-warning" class="cen-mlv-warning" hidden></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cen-mlv-download-form">
					<input type="hidden" name="action" value="cen_mlv_download_pdf">
					<?php wp_nonce_field( 'cen_mlv_download_pdf' ); ?>
					<div id="cen-mlv-download-fields"></div>
					<button type="submit" class="button button-primary button-hero" id="cen-mlv-download" disabled><?php esc_html_e( 'Download PDF', 'cen-member-list-pdf' ); ?></button>
				</form>
			</div>

			<div class="cen-mlv-table-wrap">
				<table class="widefat striped" id="cen-mlv-table">
					<thead>
						<tr>
							<th scope="col" class="cen-mlv-user-id-column"><?php esc_html_e( 'ID', 'cen-member-list-pdf' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Member / Entry', 'cen-member-list-pdf' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Company', 'cen-member-list-pdf' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Company Description', 'cen-member-list-pdf' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Source', 'cen-member-list-pdf' ); ?></th>
							<th scope="col" class="cen-mlv-remove-column"><?php esc_html_e( 'Action', 'cen-member-list-pdf' ); ?></th>
						</tr>
					</thead>
					<tbody id="cen-mlv-members">
						<tr class="cen-mlv-empty-row"><td colspan="6"><?php esc_html_e( 'No members or companies are selected yet.', 'cen-member-list-pdf' ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</section>
	</div>
	<?php
}

/**
 * Build and stream the requested PDF.
 */
function cen_mlv_handle_pdf_download() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to create member-list PDFs.', 'cen-member-list-pdf' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'cen_mlv_download_pdf' );

	$raw_member_ids = isset( $_POST['member_ids'] ) ? (array) wp_unslash( $_POST['member_ids'] ) : array();
	$member_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $raw_member_ids ) ) ) ), 0, 2000 );
	$manual_companies = cen_mlv_get_posted_manual_companies();
	if ( empty( $member_ids ) && empty( $manual_companies ) ) {
		wp_die( esc_html__( 'Add at least one member or company before downloading the PDF.', 'cen-member-list-pdf' ), '', array( 'back_link' => true ) );
	}

	$rows = $manual_companies;
	foreach ( $member_ids as $member_id ) {
		$user = get_userdata( $member_id );
		if ( ! $user || ! cen_mlv_is_user_eligible( $member_id ) ) {
			continue;
		}
		$rows[] = array(
			'user_id'     => $member_id,
			'member_name' => cen_mlv_get_user_full_name( $user ),
			'company'     => cen_mlv_get_user_company( $member_id ),
			'description' => cen_mlv_get_user_company_description( $member_id ),
		);
	}

	$rows = apply_filters( 'cen_mlv_pdf_rows', $rows, $member_ids, $manual_companies );
	$rows = cen_mlv_dedupe_pdf_rows( $rows );
	if ( empty( $rows ) ) {
		wp_die( esc_html__( 'No valid members or companies remained for the PDF.', 'cen-member-list-pdf' ), '', array( 'back_link' => true ) );
	}
	usort(
		$rows,
		static function ( $left, $right ) {
			$company_order = strnatcasecmp( (string) $left['company'], (string) $right['company'] );
			return 0 !== $company_order ? $company_order : strnatcasecmp( (string) $left['member_name'], (string) $right['member_name'] );
		}
	);

	$pdf = new CEN_MLV_PDF();
	$pdf_binary = $pdf->render( $rows );
	$filename = sanitize_file_name( 'membership-roster-' . wp_date( 'Y-m-d' ) . '.pdf' );

	while ( ob_get_level() ) {
		ob_end_clean();
	}
	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . strlen( $pdf_binary ) );
	echo $pdf_binary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF output.
	exit;
}
