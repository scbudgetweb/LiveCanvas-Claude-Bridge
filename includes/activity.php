<?php
/**
 * Activity: the audit log made visible.
 *  - Tools › Claude Code › Activity: filterable list, before/after detail, Undo.
 *  - REST (lccb/v1/activity) for the CC Chat Activity popover, using the editor's logged-in session + REST nonce.
 * Undo here = preview the restore and apply it in one step (the user clicked it themselves).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LCCB_TOOL_LABELS = array(
	'lc_page_create'     => 'Created page',
	'lc_page_update'     => 'Updated page',
	'lc_partial_update'  => 'Updated header/footer',
	'lc_template_upsert' => 'Template change',
	'lc_tokens_update'   => 'Design tokens',
	'lc_media_import'    => 'Imported image',
	'lc_html_template_assets' => 'Imported template assets',
	'lc_media_import_batch' => 'Imported images',
	'lc_image_optimise'  => 'Optimised images',
	'lc_image_crop'      => 'Cropped image',
	'lc_media_update'    => 'Image details',
	'lc_stock_import'    => 'Stock photo',
	'lc_section_create'  => 'Created section',
	'lc_section_update'  => 'Updated section',
	'lc_section_replace_inline' => 'Section → shortcode',
	'lc_audit_restore'   => 'Undo',
);

/** Entry ids that a later restore has undone. */
function lccb_activity_undone_ids() {
	global $wpdb;
	lccb_audit_install();
	return array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT restored_from FROM ' . lccb_audit_table() . ' WHERE restored_from IS NOT NULL' ) ); // phpcs:ignore WordPress.DB
}

/** Where to look at the thing a change touched. @return array{label: string, links: array<string, string>} */
function lccb_activity_target( array $row ) {
	$id = (int) $row['target_id'];
	switch ( $row['target_type'] ) {
		case 'tokens':
			return array( 'label' => 'Theme design tokens', 'links' => array( 'Customizer' => admin_url( 'customize.php' ) ) );
		case 'image_optimise':
			return array( 'label' => 'Images converted/resized (references rewritten)', 'links' => array( 'Media' => admin_url( 'upload.php' ) ) );
		case 'media_meta':
			return array( 'label' => 'Image alt text and details', 'links' => array( 'Media' => admin_url( 'upload.php' ) ) );
		case 'media_batch':
			return array( 'label' => 'Images imported from the old site', 'links' => array( 'Media' => admin_url( 'upload.php' ) ) );
		case 'template_assets':
			return array( 'label' => 'Template assets in the child theme', 'links' => array() );
		case 'media':
			$att = get_post( $id );
			return array( 'label' => $att ? 'Image: ' . $att->post_title : "Image #$id (deleted)", 'links' => $att ? array( 'Media' => get_edit_post_link( $id, 'raw' ) ) : array() );
		default:
			$p = get_post( $id );
			if ( ! $p ) {
				return array( 'label' => ucfirst( $row['target_type'] ) . " #$id (deleted)", 'links' => array() );
			}
			$links = array();
			if ( 'trash' !== $p->post_status ) {
				$links['Builder'] = lccb_editor_url( $id );
				if ( 'page' === $p->post_type || 'post' === $p->post_type ) {
					$links['View'] = get_permalink( $id );
				}
			}
			$type = array( 'page' => 'Page', 'post' => 'Post', 'lc_partial' => 'Partial', 'lc_dynamic_template' => 'Template' );
			return array( 'label' => ( isset( $type[ $p->post_type ] ) ? $type[ $p->post_type ] : ucfirst( $p->post_type ) ) . ': ' . $p->post_title . ( 'trash' === $p->post_status ? ' (in the bin)' : '' ), 'links' => $links );
	}
}

/** Shape a row for display / JSON. */
function lccb_activity_item( array $row, array $undone ) {
	$t = lccb_activity_target( $row );
	return array(
		'id'            => (int) $row['id'],
		'when'          => $row['created_at'],
		'ago'           => human_time_diff( strtotime( str_replace( ' UTC', '', $row['created_at'] ) . ' UTC' ) ) . ' ago',
		'action'        => array_key_exists( $row['tool'], LCCB_TOOL_LABELS ) ? LCCB_TOOL_LABELS[ $row['tool'] ] : $row['tool'],
		'summary'       => $row['summary'],
		'target_type'   => $row['target_type'],
		'target'        => $t['label'],
		'links'         => $t['links'],
		'restored_from' => $row['restored_from'],
		'is_undo'       => 'lc_audit_restore' === $row['tool'], // undoing an undo brings the change back: show it as "Redo"
		'undone'        => in_array( (int) $row['id'], $undone, true ),
		'detail_url'    => admin_url( 'tools.php?page=' . LCCB_PAGE . '&tab=activity&entry=' . (int) $row['id'] ),
	);
}

/** Undo an entry in one go (preview + apply). @return array apply result */
function lccb_activity_undo( $id ) {
	$preview = lccb_op_audit_restore( array( 'id' => (int) $id ) );
	return lccb_op_apply_change( array( 'preview_id' => $preview['preview_id'] ) ) + array( 'changes' => $preview['changes'] );
}

// ───────────────────────── REST for CC Chat ─────────────────────────

add_action( 'rest_api_init', function () {
	$can = function () {
		return current_user_can( 'manage_options' ) && lccb_is_connected();
	};
	register_rest_route( 'lccb/v1', '/activity', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => function ( WP_REST_Request $req ) {
			$undone = lccb_activity_undone_ids();
			return array_map( function ( $row ) use ( $undone ) {
				return lccb_activity_item( $row, $undone );
			}, lccb_audit_list( min( 50, max( 1, (int) $req->get_param( 'limit' ) ?: 20 ) ) ) );
		},
	) );
	register_rest_route( 'lccb/v1', '/activity/(?P<id>\d+)/undo', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => function ( WP_REST_Request $req ) {
			try {
				return lccb_activity_undo( (int) $req['id'] );
			} catch ( Exception $e ) {
				return new WP_Error( 'lccb_undo_failed', $e->getMessage(), array( 'status' => 409 ) );
			}
		},
	) );
} );

// ───────────────────────── Admin: Tools › Claude Code › Activity ─────────────────────────

add_action( 'admin_post_lccb_undo', function () {
	lccb_guard( 'lccb_undo' );
	$id = isset( $_POST['entry'] ) ? (int) $_POST['entry'] : 0; // phpcs:ignore WordPress.Security.NonceVerification -- checked in lccb_guard
	try {
		$r      = lccb_activity_undo( $id );
		$result = array( 'title' => "Undo #$id", 'ok' => true, 'steps' => array_merge( array( array( 'ok' => true, 'text' => $r['summary'] ) ), array_map( function ( $c ) { return array( 'ok' => true, 'text' => $c ); }, (array) $r['changes'] ) ) );
	} catch ( Exception $e ) {
		$result = array( 'title' => "Undo #$id", 'ok' => false, 'steps' => array( array( 'ok' => false, 'text' => $e->getMessage() ) ) );
	}
	set_transient( 'lccb_result_' . get_current_user_id(), $result, 120 );
	wp_safe_redirect( admin_url( 'tools.php?page=' . LCCB_PAGE . '&tab=activity' ) );
	exit;
} );

function lccb_undo_button( $id, $label = 'Undo' ) {
	$redo = 0 === strpos( $label, 'Redo' );
	ob_start();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo $redo ? esc_js( 'Redo this? It reverses that undo, putting the change back.' ) : esc_js( 'Undo this change? The item goes back exactly as it was before it. (The undo itself can be undone too.)' ); ?>');">
		<input type="hidden" name="action" value="lccb_undo">
		<input type="hidden" name="entry" value="<?php echo (int) $id; ?>">
		<?php wp_nonce_field( 'lccb_undo' ); ?>
		<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
	return ob_get_clean();
}

function lccb_render_activity_tab() {
	$undone = lccb_activity_undone_ids();
	$entry  = isset( $_GET['entry'] ) ? (int) $_GET['entry'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $entry ) {
		lccb_render_activity_detail( $entry, $undone );
		return;
	}
	$type  = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$rows  = lccb_audit_list( 200, $type );
	$types = array( '' => 'All', 'page' => 'Pages', 'partial' => 'Header & footer', 'template' => 'Templates', 'tokens' => 'Design tokens', 'media' => 'Media' );
	?>
	<p>Every site-level change Claude applied (pages, header and footer, templates, design tokens, media), with what it was before. Builder edits to the open page are covered by CC Chat's Checkpoints instead.</p>
	<ul class="subsubsub">
		<?php
		$links = array();
		foreach ( $types as $key => $label ) {
			$links[] = '<li><a href="' . esc_url( admin_url( 'tools.php?page=' . LCCB_PAGE . '&tab=activity' . ( $key ? '&type=' . $key : '' ) ) ) . '"' . ( $key === $type ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
	</ul>
	<table class="widefat striped" style="clear:both;margin-top:8px">
		<thead><tr><th style="width:60px">#</th><th style="width:130px">When</th><th>Change</th><th>On</th><th style="width:150px"></th></tr></thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="5">No site-level changes yet.</td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $row ) : $it = lccb_activity_item( $row, $undone ); ?>
			<tr<?php echo $it['undone'] ? ' style="opacity:.55"' : ''; ?>>
				<td><a href="<?php echo esc_url( $it['detail_url'] ); ?>">#<?php echo (int) $it['id']; ?></a></td>
				<td title="<?php echo esc_attr( $it['when'] ); ?>"><?php echo esc_html( $it['ago'] ); ?></td>
				<td><strong><?php echo esc_html( $it['action'] ); ?></strong><br><span style="color:#50575e"><?php echo esc_html( $it['summary'] ); ?></span><?php echo $it['undone'] ? ' <em>(undone)</em>' : ''; ?></td>
				<td><?php echo esc_html( $it['target'] ); ?>
					<?php foreach ( $it['links'] as $label => $url ) : ?>
						<br><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</td>
				<td style="text-align:right">
					<a class="button button-small" href="<?php echo esc_url( $it['detail_url'] ); ?>">Details</a>
					<?php echo $it['undone'] ? '' : lccb_undo_button( $it['id'], $it['is_undo'] ? 'Redo' : 'Undo' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description">Kept for <?php echo (int) LCCB_AUDIT_KEEP_DAYS; ?> days (up to <?php echo (int) LCCB_AUDIT_KEEP_ROWS; ?> entries).</p>
	<?php
}

function lccb_render_activity_detail( $id, array $undone ) {
	$e = lccb_audit_get( $id );
	echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=' . LCCB_PAGE . '&tab=activity' ) ) . '">← All activity</a></p>';
	if ( ! $e ) {
		echo '<p>That entry no longer exists.</p>';
		return;
	}
	$it = lccb_activity_item( $e, $undone );
	echo '<h2 style="margin-top:0">#' . (int) $id . ' ' . esc_html( $it['action'] ) . '</h2>';
	echo '<p>' . esc_html( $e['summary'] ) . '<br><span style="color:#50575e">' . esc_html( $it['ago'] . ' · ' . $it['target'] . ( $e['restored_from'] ? ' · undid #' . $e['restored_from'] : '' ) ) . '</span></p>';
	if ( ! $it['undone'] ) {
		echo '<p>' . lccb_undo_button( $id, $it['is_undo'] ? 'Redo (reverse this undo)' : 'Undo this change' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<p><em>This change has been undone.</em></p>';
	}

	$lines = array();
	$diff  = '';
	if ( 'tokens' === $e['target_type'] ) {
		foreach ( (array) $e['after']['mods'] as $k => $v ) {
			$old     = isset( $e['before']['mods'][ $k ] ) ? $e['before']['mods'][ $k ] : null;
			$lines[] = ( 'picostrap_fonts_header_code' === $k ? 'fonts_header_code' : '$' . substr( $k, 8 ) ) . ': ' . ( null === $old ? '(default)' : wp_json_encode( $old ) ) . ' → ' . ( null === $v ? '(default)' : wp_json_encode( $v ) );
		}
	} elseif ( 'media' === $e['target_type'] ) {
		$lines[] = $e['after'] ? 'Imported from ' . ( isset( $e['after']['source'] ) ? $e['after']['source'] : '?' ) : 'Deleted the imported image';
	} elseif ( $e['after'] ) {
		$c     = lccb_describe_changes( $e['before'], $e['after'] );
		$lines = $c['fields'];
		$diff  = $c['content_diff'];
	}
	if ( $lines ) {
		echo '<ul style="list-style:disc;padding-left:20px">';
		foreach ( $lines as $l ) {
			echo '<li><code>' . esc_html( $l ) . '</code></li>';
		}
		echo '</ul>';
	}
	if ( '' !== $diff ) {
		echo '<pre style="background:#fff;border:1px solid #dcdcde;padding:10px;max-height:560px;overflow:auto;font-size:12px;line-height:1.5">';
		foreach ( explode( "\n", $diff ) as $l ) {
			$bg = '+' === substr( $l, 0, 1 ) ? '#e6f4ea' : ( '-' === substr( $l, 0, 1 ) ? '#fce8e6' : 'transparent' );
			echo '<div style="background:' . esc_attr( $bg ) . '">' . esc_html( $l ) . '</div>';
		}
		echo '</pre>';
	}
}
