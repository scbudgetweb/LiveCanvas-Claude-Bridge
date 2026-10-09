<?php
/**
 * Audit log for site-level changes Claude applies (pages, partials, templates…): every apply stores a before-copy,
 * so any change can be restored. Restores are themselves audited, so they can be undone too.
 *
 * Builder edits (the open page, Global CSS/JS) aren't recorded here: the browser checkpoints cover those.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCCB_AUDIT_DB_VERSION', '1' );
define( 'LCCB_AUDIT_KEEP_DAYS', 90 );
define( 'LCCB_AUDIT_KEEP_ROWS', 500 );

function lccb_audit_table() {
	global $wpdb;
	return $wpdb->prefix . 'lccb_audit';
}

function lccb_audit_install() {
	if ( get_option( 'lccb_audit_db_version' ) === LCCB_AUDIT_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = lccb_audit_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE $table (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		created_at datetime NOT NULL,
		source varchar(32) NOT NULL DEFAULT '',
		tool varchar(64) NOT NULL DEFAULT '',
		target_type varchar(32) NOT NULL DEFAULT '',
		target_id bigint(20) unsigned NOT NULL DEFAULT 0,
		summary text NOT NULL,
		before_data longtext NULL,
		after_data longtext NULL,
		restored_from bigint(20) unsigned NULL,
		PRIMARY KEY  (id),
		KEY target (target_type, target_id),
		KEY created_at (created_at)
	) $charset;" );
	update_option( 'lccb_audit_db_version', LCCB_AUDIT_DB_VERSION, false );
}

function lccb_audit_uninstall() {
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . lccb_audit_table() ); // phpcs:ignore WordPress.DB
	delete_option( 'lccb_audit_db_version' );
}

/** @return int audit id */
function lccb_audit_record( $tool, $target_type, $target_id, $summary, $before, $after, $source = '', $restored_from = null ) {
	global $wpdb;
	lccb_audit_install();
	$wpdb->insert( lccb_audit_table(), array(
		'created_at'    => current_time( 'mysql', true ),
		'source'        => substr( (string) $source, 0, 32 ),
		'tool'          => $tool,
		'target_type'   => $target_type,
		'target_id'     => (int) $target_id,
		'summary'       => $summary,
		'before_data'   => null === $before ? null : wp_json_encode( $before ),
		'after_data'    => null === $after ? null : wp_json_encode( $after ),
		'restored_from' => $restored_from,
	) );
	$id = (int) $wpdb->insert_id;
	lccb_audit_housekeeping();
	return $id;
}

function lccb_audit_housekeeping() {
	global $wpdb;
	$table = lccb_audit_table();
	$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - LCCB_AUDIT_KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	$cutoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET %d", LCCB_AUDIT_KEEP_ROWS ) ); // phpcs:ignore WordPress.DB
	if ( $cutoff ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id <= %d", $cutoff ) ); // phpcs:ignore WordPress.DB
	}
}

/** @return array[] newest first, without the big before/after payloads */
function lccb_audit_list( $limit = 30, $target_type = '', $target_id = 0 ) {
	global $wpdb;
	lccb_audit_install();
	$table = lccb_audit_table();
	$where = '1=1';
	$vals  = array();
	if ( $target_type ) {
		$where .= ' AND target_type = %s';
		$vals[] = $target_type;
	}
	if ( $target_id ) {
		$where .= ' AND target_id = %d';
		$vals[] = (int) $target_id;
	}
	$vals[] = max( 1, min( 200, (int) $limit ) );
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, source, tool, target_type, target_id, summary, restored_from, before_data IS NULL AS created FROM $table WHERE $where ORDER BY id DESC LIMIT %d", $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB
	return array_map( function ( $r ) {
		$r['id']            = (int) $r['id'];
		$r['target_id']     = (int) $r['target_id'];
		$r['restored_from'] = $r['restored_from'] ? (int) $r['restored_from'] : null;
		$r['created']       = (bool) $r['created']; // true = this change created the target (restore deletes it)
		$r['created_at']    = $r['created_at'] . ' UTC';
		return $r;
	}, (array) $rows );
}

/** @return array|null full row incl. decoded before/after */
function lccb_audit_get( $id ) {
	global $wpdb;
	lccb_audit_install();
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lccb_audit_table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	if ( ! $row ) {
		return null;
	}
	$row['before'] = null === $row['before_data'] ? null : json_decode( $row['before_data'], true );
	$row['after']  = null === $row['after_data'] ? null : json_decode( $row['after_data'], true );
	unset( $row['before_data'], $row['after_data'] );
	return $row;
}

// ───────────────────────── Post snapshots (pages, partials, templates) ─────────────────────────

/** Meta keys that define what a post is in LiveCanvas terms, kept in snapshots and restored with them. */
function lccb_snapshot_meta_keys( $post_id ) {
	$keys = array( '_lc_livecanvas_enabled', '_wp_page_template', 'lc_use_template_of_slug' );
	foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
		if ( 0 === strpos( $key, 'is_' ) ) {
			$keys[] = $key;
		}
	}
	return array_unique( $keys );
}

/** @return array|null */
function lccb_snapshot_post( $post_id ) {
	$p = get_post( $post_id );
	if ( ! $p ) {
		return null;
	}
	$meta = array();
	foreach ( lccb_snapshot_meta_keys( $p->ID ) as $key ) {
		if ( metadata_exists( 'post', $p->ID, $key ) ) {
			$meta[ $key ] = get_post_meta( $p->ID, $key, true );
		}
	}
	return array(
		'post' => array(
			'ID'           => $p->ID,
			'post_type'    => $p->post_type,
			'post_title'   => $p->post_title,
			'post_name'    => $p->post_name,
			'post_status'  => $p->post_status,
			'post_parent'  => (int) $p->post_parent,
			'menu_order'   => (int) $p->menu_order,
			'post_content' => $p->post_content,
		),
		'meta' => $meta,
	);
}

/** Put a post back exactly as a snapshot describes it (content, fields and LiveCanvas meta). */
function lccb_restore_post_snapshot( array $snap ) {
	$post   = $snap['post'];
	$result = wp_update_post( wp_slash( $post ), true );
	if ( is_wp_error( $result ) ) {
		throw new Exception( $result->get_error_message() );
	}
	$want = (array) $snap['meta'];
	foreach ( lccb_snapshot_meta_keys( $post['ID'] ) as $key ) {
		if ( ! array_key_exists( $key, $want ) ) {
			delete_post_meta( $post['ID'], $key ); // e.g. a display condition added after the snapshot
		}
	}
	foreach ( $want as $key => $value ) {
		update_post_meta( $post['ID'], $key, wp_slash( $value ) );
	}
	clean_post_cache( $post['ID'] );
}
