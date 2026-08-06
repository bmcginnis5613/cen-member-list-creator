<?php
/**
 * Plugin Name: CEN Member List Creator
 * Description: Build a reviewable membership roster from multiple national peer groups and download it as a PDF.
 * Version: 1.0.0
 * Author: FirstTracks Marketing
 * Author URI: https://firsttracksmarketing.com
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CEN_MLV_VERSION', '1.0.7' );
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
				'loadError'         => __( 'The selected groups could not be loaded.', 'cen-member-list-pdf' ),
				'searchError'       => __( 'Member search failed.', 'cen-member-list-pdf' ),
				'chooseGroup'       => __( 'Select at least one national peer group.', 'cen-member-list-pdf' ),
				'empty'             => __( 'No members are selected yet.', 'cen-member-list-pdf' ),
				'oneMember'         => __( '1 member selected', 'cen-member-list-pdf' ),
				'manyMembers'       => __( '%d members selected', 'cen-member-list-pdf' ),
				'oneMissingCompany' => __( '1 selected member has no company.', 'cen-member-list-pdf' ),
				'manyMissingCompany' => __( '%d selected members have no company.', 'cen-member-list-pdf' ),
				'oneMissingDescription' => __( '1 selected member has no company description.', 'cen-member-list-pdf' ),
				'manyMissingDescription' => __( '%d selected members have no company description.', 'cen-member-list-pdf' ),
				'addedIndividually' => __( 'Added individually', 'cen-member-list-pdf' ),
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
 * Use the conflict sender's group map when it is active, with a standalone
 * copy so this plugin also works by itself.
 *
 * @return array<string,string>
 */
function cen_mlv_get_allowed_group_codes() {
	if ( function_exists( 'cen_ccs_get_allowed_group_codes' ) ) {
		$codes = cen_ccs_get_allowed_group_codes();
	} else {
		$codes = array(
			'Architectural Firm Owners & Managing Partners'   => '20AX',
			'Architecture & Engineering Finance Executives'    => '10AE',
			'CEO100'                                           => 'CEO100',
			'CEO50'                                            => 'CEO50',
			'CFO100'                                           => 'CFO100',
			'Chief Strategy & Corporate Development Officers'  => '20VP',
			'Construction Senior Finance Executives'            => '10XC',
			'Controllers'                                      => '1XXCO',
			'Engineering Firm Owners & Managing Partners'       => '20EX',
			'General Contractor Firm Owners & CEOs'             => '20GC',
			'General Managers & Division Presidents'            => '20GM',
			'HR Executives'                                    => '10HR',
			'IT Executives'                                    => '10XCIO',
			'Manufacturing Presidents & CEOs (Large)'           => '500M',
			'Manufacturing Presidents & CEOs (Small to Medium)' => '2XXM',
			'Manufacturing Senior Finance Executives'           => '1XXM',
			'Marketing & Business Development Executives'       => '5MBD',
			'Operations Executives'                            => '10XVM',
			'R&D and Engineering Executives'                   => '10RD',
			'Sales Executives'                                 => '5XM',
			'Sub Contractor Firm Owners & CEOs'                 => '20XC',
			'Supply Chain & Purchasing Executives'              => '10SC',
			'Technology & Software Executives'                  => '21XPS',
		);
	}

	return apply_filters( 'cen_mlv_allowed_group_codes', $codes );
}

/**
 * Return the existing BuddyBoss groups from the shared allowlist.
 *
 * @return array<int,object>
 */
function cen_mlv_get_allowed_groups() {
	if ( ! function_exists( 'groups_get_groups' ) ) {
		return array();
	}

	$result = groups_get_groups(
		array(
			'show_hidden' => true,
			'per_page'    => false,
			'page'        => false,
		)
	);
	$groups = isset( $result['groups'] ) && is_array( $result['groups'] ) ? $result['groups'] : array();
	$allowed_names = array_fill_keys( array_keys( cen_mlv_get_allowed_group_codes() ), true );
	$allowed = array();

	foreach ( $groups as $group ) {
		$group_id = isset( $group->id ) ? absint( $group->id ) : 0;
		$name = isset( $group->name ) ? cen_mlv_clean_text( $group->name ) : '';
		if ( $group_id && isset( $allowed_names[ $name ] ) ) {
			$allowed[ $group_id ] = $group;
		}
	}

	uasort(
		$allowed,
		static function ( $left, $right ) {
			return strcasecmp( (string) $left->name, (string) $right->name );
		}
	);

	return $allowed;
}

/**
 * Normalize a BuddyBoss/WordPress text value for display and export.
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
 * Determine whether BuddyBoss has suspended a user.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function cen_mlv_is_user_suspended( $user_id ) {
	if ( function_exists( 'cen_ccs_is_user_suspended' ) ) {
		return cen_ccs_is_user_suspended( $user_id );
	}

	return function_exists( 'bp_moderation_is_user_suspended' ) && bp_moderation_is_user_suspended( absint( $user_id ) );
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
 * Load and merge members from all selected BuddyBoss groups.
 */
function cen_mlv_ajax_load_members() {
	cen_mlv_verify_ajax_request( 'cen_mlv_load_members' );

	if ( ! function_exists( 'groups_get_group_members' ) ) {
		wp_send_json_error( array( 'message' => __( 'BuddyBoss Platform (or BuddyPress) Groups is not available.', 'cen-member-list-pdf' ) ), 400 );
	}

	$requested_ids = isset( $_GET['group_ids'] ) ? (array) wp_unslash( $_GET['group_ids'] ) : array();
	$requested_ids = array_values( array_unique( array_filter( array_map( 'absint', $requested_ids ) ) ) );
	$allowed_groups = cen_mlv_get_allowed_groups();
	$group_ids = array_values( array_intersect( $requested_ids, array_keys( $allowed_groups ) ) );
	if ( empty( $group_ids ) ) {
		wp_send_json_error( array( 'message' => __( 'Select at least one valid BuddyBoss group.', 'cen-member-list-pdf' ) ), 400 );
	}

	$member_groups = array();
	foreach ( $group_ids as $group_id ) {
		$result = groups_get_group_members(
			array(
				'group_id'       => $group_id,
				'per_page'       => false,
				'page'           => false,
				'exclude_banned' => true,
			)
		);
		$members = isset( $result['members'] ) && is_array( $result['members'] ) ? $result['members'] : array();
		$group_name = cen_mlv_clean_text( $allowed_groups[ $group_id ]->name );

		foreach ( $members as $member ) {
			$user_id = isset( $member->ID ) ? absint( $member->ID ) : 0;
			if ( ! $user_id || cen_mlv_is_user_suspended( $user_id ) ) {
				continue;
			}
			if ( ! isset( $member_groups[ $user_id ] ) ) {
				$member_groups[ $user_id ] = array();
			}
			$member_groups[ $user_id ][] = $group_name;
		}
	}

	$items = array();
	foreach ( $member_groups as $user_id => $group_names ) {
		$user = get_userdata( $user_id );
		if ( $user ) {
			$items[] = cen_mlv_get_member_payload( $user, $group_names );
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
			'number'         => 20,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
			'search'         => '*' . $term . '*',
			'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			'fields'         => array( 'ID', 'display_name', 'user_email' ),
		)
	);
	$items = array();
	foreach ( $query->get_results() as $user ) {
		if ( ! cen_mlv_is_user_suspended( $user->ID ) ) {
			$items[] = cen_mlv_get_member_payload( $user );
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
 * Render the Tools screen.
 */
function cen_mlv_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to create member-list PDFs.', 'cen-member-list-pdf' ) );
	}

	$groups = cen_mlv_get_allowed_groups();
	$codes = cen_mlv_get_allowed_group_codes();
	$buddyboss_ready = function_exists( 'groups_get_groups' ) && function_exists( 'groups_get_group_members' );
	?>
	<div class="wrap cen-mlv-wrap">
		<h1><?php esc_html_e( 'Member List Creator', 'cen-member-list-pdf' ); ?></h1>
		<p class="cen-mlv-intro"><?php esc_html_e( 'Select one or more national peer groups, review the combined member list, remove anyone who should not appear, and download the membership roster PDF.', 'cen-member-list-pdf' ); ?></p>

		<?php if ( ! $buddyboss_ready ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'BuddyBoss Platform (or BuddyPress) with Groups enabled must be active.', 'cen-member-list-pdf' ); ?></p></div>
		<?php elseif ( empty( $groups ) ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'None of the allowed BuddyBoss groups were found. Group names must match the shared CEN allowlist.', 'cen-member-list-pdf' ); ?></p></div>
		<?php endif; ?>

		<div class="cen-mlv-builder">
			<section class="cen-mlv-card" aria-labelledby="cen-mlv-groups-heading">
				<h2 id="cen-mlv-groups-heading"><?php esc_html_e( '1. Choose groups', 'cen-member-list-pdf' ); ?></h2>
				<p class="description" id="cen-mlv-group-help"><?php esc_html_e( 'Use Ctrl (Windows) or Command (Mac) to select multiple groups.', 'cen-member-list-pdf' ); ?></p>
				<select id="cen-mlv-groups" class="cen-mlv-groups" multiple size="12" aria-describedby="cen-mlv-group-help" <?php disabled( empty( $groups ) ); ?>>
					<?php foreach ( $groups as $group_id => $group ) : ?>
						<?php
						$group_name = cen_mlv_clean_text( $group->name );
						$code = isset( $codes[ $group_name ] ) ? (string) $codes[ $group_name ] : '';
						$label = '' !== $code ? sprintf( '%1$s (%2$s)', $group_name, $code ) : $group_name;
						?>
						<option value="<?php echo esc_attr( $group_id ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="cen-mlv-actions">
					<button type="button" class="button button-primary" id="cen-mlv-load-groups" <?php disabled( empty( $groups ) ); ?>><?php esc_html_e( 'Load selected groups', 'cen-member-list-pdf' ); ?></button>
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
		</div>

		<section class="cen-mlv-card cen-mlv-review" aria-labelledby="cen-mlv-review-heading">
			<div class="cen-mlv-review-heading">
				<div>
					<h2 id="cen-mlv-review-heading"><?php esc_html_e( '3. Review members', 'cen-member-list-pdf' ); ?></h2>
					<p id="cen-mlv-count" class="description"><?php esc_html_e( 'No members are selected yet.', 'cen-member-list-pdf' ); ?></p>
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
							<th scope="col"><?php esc_html_e( 'Member', 'cen-member-list-pdf' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Company', 'cen-member-list-pdf' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Company Description', 'cen-member-list-pdf' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Source', 'cen-member-list-pdf' ); ?></th>
							<th scope="col" class="cen-mlv-remove-column"><?php esc_html_e( 'Action', 'cen-member-list-pdf' ); ?></th>
						</tr>
					</thead>
					<tbody id="cen-mlv-members">
						<tr class="cen-mlv-empty-row"><td colspan="6"><?php esc_html_e( 'No members are selected yet.', 'cen-member-list-pdf' ); ?></td></tr>
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
	if ( empty( $member_ids ) ) {
		wp_die( esc_html__( 'Select at least one member before downloading the PDF.', 'cen-member-list-pdf' ), '', array( 'back_link' => true ) );
	}

	$rows = array();
	foreach ( $member_ids as $member_id ) {
		$user = get_userdata( $member_id );
		if ( ! $user || cen_mlv_is_user_suspended( $member_id ) ) {
			continue;
		}
		$rows[] = array(
			'user_id'     => $member_id,
			'member_name' => cen_mlv_get_user_full_name( $user ),
			'company'     => cen_mlv_get_user_company( $member_id ),
			'description' => cen_mlv_get_user_company_description( $member_id ),
		);
	}

	$rows = apply_filters( 'cen_mlv_pdf_rows', $rows, $member_ids );
	$rows = cen_mlv_dedupe_pdf_rows( $rows );
	if ( empty( $rows ) ) {
		wp_die( esc_html__( 'No valid members remained for the PDF.', 'cen-member-list-pdf' ), '', array( 'back_link' => true ) );
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
