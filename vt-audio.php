<?php
/**
 * Plugin Name: VT Audio
 * Description: Generate AI audio versions of posts via OpenAI TTS and embed a native player.
 * Version: 1.3.4
 * Author: Vinu Thomas
 * License: GPL-2.0+
 * Requires at least: 6.0
 * Tested up to: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'VT_AUDIO_META_KEY',     '_vt_audio_attachment_id' );
define( 'VT_AUDIO_DURATION_KEY', '_vt_audio_duration' );
define( 'VT_AUDIO_PLAYS_TABLE',  'vt_audio_plays' );
define( 'VT_AUDIO_DB_VERSION',   '1.0' );

// ─── Database ─────────────────────────────────────────────────────────────────

register_activation_hook( __FILE__, 'vt_audio_create_table' );

add_action( 'admin_init', function () {
	if ( get_option( 'vt_audio_db_version' ) !== VT_AUDIO_DB_VERSION ) {
		vt_audio_create_table();
	}
}, 5 );

function vt_audio_create_table(): void {
	global $wpdb;
	$table           = $wpdb->prefix . VT_AUDIO_PLAYS_TABLE;
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE $table (
		id        bigint(20) NOT NULL AUTO_INCREMENT,
		post_id   bigint(20) NOT NULL,
		played_at datetime   NOT NULL,
		PRIMARY KEY  (id),
		KEY post_id   (post_id),
		KEY played_at (played_at)
	) $charset_collate;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( 'vt_audio_db_version', VT_AUDIO_DB_VERSION );
}

function vt_audio_get_play_count( int $post_id ): int {
	global $wpdb;
	$table = $wpdb->prefix . VT_AUDIO_PLAYS_TABLE;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE post_id = %d", $post_id ) );
}

// ─── Settings ─────────────────────────────────────────────────────────────────

add_action( 'admin_init', function () {
	register_setting( 'vt_audio', 'vt_audio_api_key',   [ 'sanitize_callback' => 'sanitize_text_field' ] );
	register_setting( 'vt_audio', 'vt_audio_model',     [ 'sanitize_callback' => 'sanitize_text_field', 'default' => 'tts-1-hd' ] );
	register_setting( 'vt_audio', 'vt_audio_voice',     [ 'sanitize_callback' => 'sanitize_text_field', 'default' => 'nova' ] );
	register_setting( 'vt_audio', 'vt_audio_min_words', [ 'sanitize_callback' => 'absint',              'default' => 300 ] );
	register_setting( 'vt_audio', 'vt_audio_skip_tags',    [ 'sanitize_callback' => 'vt_audio_sanitize_tag_list', 'default' => 'pre,code,figure,img,blockquote,table' ] );
	register_setting( 'vt_audio', 'vt_audio_show_plays',   [ 'sanitize_callback' => 'absint', 'default' => 1 ] );
} );

function vt_audio_sanitize_tag_list( string $value ): string {
	$tags = array_filter( array_map( function ( $t ) {
		return preg_replace( '/[^a-z0-9]/i', '', strtolower( trim( $t ) ) );
	}, explode( ',', $value ) ) );
	return implode( ',', array_unique( $tags ) );
}

add_action( 'admin_menu', function () {
	add_options_page( 'VT Audio',       'VT Audio',       'manage_options', 'vt-audio',       'vt_audio_settings_page' );
	add_options_page( 'VT Audio Stats', 'VT Audio Stats', 'manage_options', 'vt-audio-stats', 'vt_audio_stats_page' );
} );

// Settings + Stats links on the Plugins list row.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( array $links ): array {
	return array_merge( [
		'<a href="' . esc_url( admin_url( 'options-general.php?page=vt-audio' ) ) . '">Settings</a>',
		'<a href="' . esc_url( admin_url( 'options-general.php?page=vt-audio-stats' ) ) . '">Stats</a>',
	], $links );
} );

function vt_audio_settings_page(): void {
	$voices        = [ 'alloy', 'echo', 'fable', 'nova', 'onyx', 'shimmer' ];
	$current_voice = get_option( 'vt_audio_voice', 'nova' );
	$current_model = get_option( 'vt_audio_model', 'tts-1-hd' );
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">VT Audio Settings</h1>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=vt-audio-stats' ) ); ?>" class="page-title-action">View play stats</a>
		<hr class="wp-header-end">
		<form method="post" action="options.php">
			<?php settings_fields( 'vt_audio' ); ?>

			<h2 class="title">OpenAI TTS</h2>
			<p class="description" style="max-width:46em;">Credentials and voice settings for generating the audio narration.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="vt_audio_api_key">OpenAI API Key</label></th>
					<td>
						<input type="password" id="vt_audio_api_key" name="vt_audio_api_key"
							value="<?php echo esc_attr( get_option( 'vt_audio_api_key' ) ); ?>"
							class="regular-text" autocomplete="off">
						<p class="description">Find your key at platform.openai.com/api-keys</p>
					</td>
				</tr>
				<tr>
					<th><label for="vt_audio_model">Model</label></th>
					<td>
						<select id="vt_audio_model" name="vt_audio_model">
							<option value="tts-1"    <?php selected( $current_model, 'tts-1' ); ?>>tts-1 (faster, cheaper ~$0.015/1K chars)</option>
							<option value="tts-1-hd" <?php selected( $current_model, 'tts-1-hd' ); ?>>tts-1-hd (higher quality ~$0.030/1K chars)</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="vt_audio_voice">Voice</label></th>
					<td>
						<select id="vt_audio_voice" name="vt_audio_voice">
							<?php foreach ( $voices as $v ) : ?>
								<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $current_voice, $v ); ?>><?php echo esc_html( ucfirst( $v ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Nova and Shimmer work well for tech/narrative content.</p>
					</td>
				</tr>
			</table>

			<h2 class="title">Player &amp; display</h2>
			<p class="description" style="max-width:46em;">Control when and how the front-end audio player appears to readers.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="vt_audio_min_words">Min word count</label></th>
					<td>
						<input type="number" id="vt_audio_min_words" name="vt_audio_min_words"
							value="<?php echo esc_attr( get_option( 'vt_audio_min_words', 300 ) ); ?>"
							min="0" class="small-text">
						<p class="description">Player won't show on posts shorter than this. Set 0 to always show.</p>
					</td>
				</tr>
				<tr>
					<th><label for="vt_audio_show_plays">Show play count</label></th>
					<td>
						<label>
							<input type="checkbox" id="vt_audio_show_plays" name="vt_audio_show_plays" value="1"
								<?php checked( 1, get_option( 'vt_audio_show_plays', 1 ) ); ?>>
							Show play count in the front-end player
						</label>
						<p class="description">Displays e.g. "248 plays" next to the "Listen to this article" label.</p>
					</td>
				</tr>
				<tr>
					<th><label for="vt_audio_skip_tags">Tags to skip</label></th>
					<td>
						<input type="text" id="vt_audio_skip_tags" name="vt_audio_skip_tags"
							value="<?php echo esc_attr( get_option( 'vt_audio_skip_tags', 'pre,code,figure,img,blockquote,table' ) ); ?>"
							class="regular-text" placeholder="pre,code,figure,img,blockquote,table">
						<p class="description">Comma-separated HTML tags whose content will be silently removed before generating audio. Block elements (e.g. <code>pre</code>, <code>blockquote</code>) are stripped including their inner content. Void elements (e.g. <code>img</code>) are stripped as standalone tags.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Resolves the selected range into [from_datetime|null, to_datetime|null, label].
 * A null bound means "unbounded" on that side.
 *
 * @return array{0:?string,1:?string,2:string}
 */
function vt_audio_resolve_range( string $range, string $date_from, string $date_to ): array {
	$now = current_time( 'timestamp' );

	switch ( $range ) {
		case 'today':
			return [ gmdate( 'Y-m-d', $now ) . ' 00:00:00', null, 'Today' ];

		case '7days':
			return [ gmdate( 'Y-m-d', strtotime( '-6 days', $now ) ) . ' 00:00:00', null, 'Last 7 days' ];

		case '30days':
			return [ gmdate( 'Y-m-d', strtotime( '-29 days', $now ) ) . ' 00:00:00', null, 'Last 30 days' ];

		case 'quarter':
			$q_start_month = (int) ( floor( ( (int) gmdate( 'n', $now ) - 1 ) / 3 ) * 3 ) + 1;
			$from          = gmdate( 'Y', $now ) . '-' . str_pad( (string) $q_start_month, 2, '0', STR_PAD_LEFT ) . '-01 00:00:00';
			return [ $from, null, 'This quarter' ];

		case 'year':
			return [ gmdate( 'Y', $now ) . '-01-01 00:00:00', null, 'This year' ];

		case 'all':
			return [ null, null, 'All time' ];

		case 'custom':
			$from = $date_from !== '' ? $date_from . ' 00:00:00' : null;
			$to   = $date_to   !== '' ? $date_to   . ' 23:59:59' : null;
			$label = 'Custom range';
			if ( $date_from !== '' && $date_to !== '' ) {
				$label = $date_from . ' → ' . $date_to;
			} elseif ( $date_from !== '' ) {
				$label = 'Since ' . $date_from;
			} elseif ( $date_to !== '' ) {
				$label = 'Up to ' . $date_to;
			}
			return [ $from, $to, $label ];
	}

	// Fallback — should never hit because caller validates.
	return [ gmdate( 'Y-m-d', strtotime( '-29 days', $now ) ) . ' 00:00:00', null, 'Last 30 days' ];
}

function vt_audio_stats_page(): void {
	global $wpdb;
	$table = $wpdb->prefix . VT_AUDIO_PLAYS_TABLE;

	// Summary counts (cheap indexed COUNT(*) — always shown, regardless of filter)
	$ts_today = current_time( 'Y-m-d' ) . ' 00:00:00';
	$ts_week  = date( 'Y-m-d', strtotime( 'monday this week', current_time( 'timestamp' ) ) ) . ' 00:00:00';
	$ts_month = current_time( 'Y-m' ) . '-01 00:00:00';

	$total_all   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" ); // phpcs:ignore
	$total_month = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE played_at >= %s", $ts_month ) );
	$total_week  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE played_at >= %s", $ts_week ) );
	$total_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE played_at >= %s", $ts_today ) );

	// Range presets for the per-post breakdown. Default = last 30 days to keep the
	// GROUP BY query bounded even if the table grows very large.
	$presets = [
		'today'   => 'Today',
		'7days'   => 'Last 7 days',
		'30days'  => 'Last 30 days',
		'quarter' => 'This quarter',
		'year'    => 'This year',
		'all'     => 'All time',
		'custom'  => 'Custom',
	];

	$range = sanitize_key( wp_unslash( $_GET['range'] ?? '30days' ) );
	if ( ! isset( $presets[ $range ] ) ) {
		$range = '30days';
	}
	$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
	$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to']   ?? '' ) );

	[ $from_dt, $to_dt, $range_label ] = vt_audio_resolve_range( $range, $date_from, $date_to );

	// Build the bounded breakdown query.
	$where  = '';
	$params = [];
	if ( $from_dt !== null && $to_dt !== null ) {
		$where  = 'WHERE played_at BETWEEN %s AND %s';
		$params = [ $from_dt, $to_dt ];
	} elseif ( $from_dt !== null ) {
		$where  = 'WHERE played_at >= %s';
		$params = [ $from_dt ];
	} elseif ( $to_dt !== null ) {
		$where  = 'WHERE played_at <= %s';
		$params = [ $to_dt ];
	}

	$sql  = "SELECT post_id, COUNT(*) as plays FROM `$table` $where GROUP BY post_id ORDER BY plays DESC LIMIT 200";
	$rows = $params
		? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) // phpcs:ignore
		: $wpdb->get_results( $sql ); // phpcs:ignore

	$range_total = array_sum( array_column( (array) $rows, 'plays' ) );

	$base_url   = admin_url( 'options-general.php?page=vt-audio-stats' );
	$card_style = 'background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 26px;min-width:120px;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,.05);';
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">VT Audio — Play Statistics</h1>
		<hr class="wp-header-end">

		<h2 style="margin-top:1.2em;">At a glance</h2>
		<div style="display:flex;gap:16px;margin:12px 0 28px;flex-wrap:wrap;">
			<?php foreach ( [
				[ 'All time',   $total_all ],
				[ 'This month', $total_month ],
				[ 'This week',  $total_week ],
				[ 'Today',      $total_today ],
			] as [ $label, $count ] ) : ?>
			<div style="<?php echo esc_attr( $card_style ); ?>">
				<div style="font-size:2.1em;font-weight:700;line-height:1.1;color:#1d2327;"><?php echo esc_html( number_format( (int) $count ) ); ?></div>
				<div style="font-size:.8125em;color:#646970;margin-top:4px;text-transform:uppercase;letter-spacing:.03em;"><?php echo esc_html( $label ); ?></div>
			</div>
			<?php endforeach; ?>
		</div>

		<h2 style="margin-bottom:.4em;">Plays by post</h2>

		<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
			<?php foreach ( $presets as $key => $label ) :
				if ( 'custom' === $key ) continue;
				$is_active = ( $range === $key );
				$url       = add_query_arg( 'range', $key, $base_url );
			?>
			<a href="<?php echo esc_url( $url ); ?>"
				class="button<?php echo $is_active ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>

		<form method="get" style="margin-bottom:18px;display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;">
			<input type="hidden" name="page" value="vt-audio-stats">
			<input type="hidden" name="range" value="custom">
			<label style="display:flex;flex-direction:column;font-size:.8125em;color:#646970;gap:3px;">Custom from
				<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
			</label>
			<label style="display:flex;flex-direction:column;font-size:.8125em;color:#646970;gap:3px;">Custom to
				<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
			</label>
			<?php submit_button( 'Apply custom range', 'secondary', '', false ); ?>
		</form>

		<p style="margin:0 0 12px;color:#646970;">
			Showing <strong><?php echo esc_html( $range_label ); ?></strong> —
			<strong><?php echo number_format( (int) $range_total ); ?></strong> total plays
			across <strong><?php echo number_format( count( (array) $rows ) ); ?></strong> post<?php echo count( (array) $rows ) === 1 ? '' : 's'; ?>.
		</p>

		<?php if ( empty( $rows ) ) : ?>
			<div class="notice notice-info inline" style="margin:0;"><p>No plays recorded in this range.</p></div>
		<?php else : ?>
		<table class="wp-list-table widefat fixed striped" style="max-width:720px;">
			<thead>
				<tr>
					<th style="width:42px;">#</th>
					<th>Post</th>
					<th style="width:90px;">Plays</th>
					<th style="width:160px;">Share</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $i => $row ) :
					$post  = get_post( (int) $row->post_id );
					$title = $post ? get_the_title( $post ) : '(Post #' . $row->post_id . ' — deleted)';
					$link  = $post ? get_edit_post_link( $post->ID ) : '';
					$pct   = $range_total > 0 ? round( $row->plays / $range_total * 100, 1 ) : 0;
				?>
				<tr>
					<td><?php echo (int) $i + 1; ?></td>
					<td><?php if ( $link ) : ?><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $title ); ?></a><?php else : echo esc_html( $title ); endif; ?></td>
					<td><strong><?php echo number_format( (int) $row->plays ); ?></strong></td>
					<td>
						<div style="display:flex;align-items:center;gap:8px;">
							<span style="flex:1;height:8px;background:#f0f0f1;border-radius:4px;overflow:hidden;min-width:60px;">
								<span style="display:block;height:100%;width:<?php echo esc_attr( $pct ); ?>%;background:#c8853a;"></span>
							</span>
							<span style="font-size:.8125em;color:#646970;white-space:nowrap;"><?php echo esc_html( $pct ); ?>%</span>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th colspan="2" style="text-align:right;">Total</th>
					<th><?php echo number_format( (int) $range_total ); ?></th>
					<th>100%</th>
				</tr>
			</tfoot>
		</table>
		<?php if ( count( (array) $rows ) >= 200 ) : ?>
			<p style="color:#646970;font-style:italic;margin-top:8px;">Showing the top 200 posts for this range.</p>
		<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

// ─── Meta Box ─────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'vt_audio', 'Audio Version', 'vt_audio_meta_box_html', 'post', 'side' );
} );

function vt_audio_meta_box_html( WP_Post $post ): void {
	$attachment_id = (int) get_post_meta( $post->ID, VT_AUDIO_META_KEY, true );
	$file_ok       = vt_audio_has_file( $attachment_id );
	$audio_url     = $file_ok ? wp_get_attachment_url( $attachment_id ) : '';
	$has_audio     = (bool) $audio_url;
	$missing       = $attachment_id && ! $file_ok; // meta points to a gone attachment/file
	$play_count    = vt_audio_get_play_count( $post->ID );

	wp_nonce_field( 'vt_audio_nonce_action', 'vt_audio_nonce' );
	?>
	<div id="vt-audio-box">
		<style>
			#vt-audio-box { font-size:13px; }
			.vt-ab-status { padding:5px 8px; border-radius:4px; margin:8px 0; }
			.vt-ab-status.ok  { background:#edfaef; color:#1a7a2e; }
			.vt-ab-status.nil { background:#f0f0f1; color:#50575e; }
			.vt-ab-status.err { background:#fce8e8; color:#8a1c1c; }
			#vt-audio-box audio { width:100%; margin:6px 0; }
			.vt-ab-actions { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; }
			#vt-ab-progress { color:#666; font-style:italic; margin-top:6px; display:none; }
		</style>

		<div class="vt-ab-status <?php echo $has_audio ? 'ok' : ( $missing ? 'err' : 'nil' ); ?>" id="vt-ab-status">
			<?php
			if ( $has_audio ) {
				echo 'Audio generated';
			} elseif ( $missing ) {
				echo 'Audio file missing — regenerate to restore it.';
			} else {
				echo 'No audio yet';
			}
			?>
		</div>

		<?php if ( $has_audio || $play_count > 0 ) : ?>
		<p style="margin:4px 0 6px;font-size:12px;color:#50575e;">
			&#9654; <strong><?php echo number_format( $play_count ); ?></strong> <?php echo $play_count === 1 ? 'play' : 'plays'; ?>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=vt-audio-stats' ) ); ?>" style="margin-left:6px;">All stats &rarr;</a>
		</p>
		<?php endif; ?>

		<div id="vt-ab-preview">
			<?php if ( $has_audio ) : ?>
				<audio controls preload="none">
					<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/mpeg">
				</audio>
			<?php endif; ?>
		</div>

		<div class="vt-ab-actions">
			<button type="button" id="vt-ab-generate" class="button button-primary">
				<?php echo $has_audio ? 'Regenerate' : 'Generate Audio'; ?>
			</button>
			<?php if ( $has_audio ) : ?>
				<button type="button" id="vt-ab-delete" class="button">Delete</button>
			<?php endif; ?>
		</div>

		<p id="vt-ab-progress">Generating… this takes 15–45 seconds for longer posts.</p>
	</div>

	<script>
	(function ($) {
		var postId = <?php echo (int) $post->ID; ?>;
		var nonce  = $('#vt_audio_nonce').val();

		$('#vt-ab-generate').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);
			$('#vt-ab-progress').show();
			setStatus('nil', 'Generating…');

			$.ajax({
				url: ajaxurl,
				method: 'POST',
				timeout: 120000,
				data: { action: 'vt_audio_generate', post_id: postId, nonce: nonce },
				success: function (r) {
					$('#vt-ab-progress').hide();
					$btn.prop('disabled', false);
					if ( r.success ) {
						setStatus('ok', 'Audio generated');
						var $src = $('<source>').attr({ src: r.data.url, type: 'audio/mpeg' });
						$('#vt-ab-preview').empty().append($('<audio controls preload="none"></audio>').append($src));
						$btn.text('Regenerate');
						if ( !$('#vt-ab-delete').length ) {
							$('.vt-ab-actions').append('<button type="button" id="vt-ab-delete" class="button">Delete</button>');
							bindDelete();
						}
					} else {
						setStatus('err', 'Error: ' + r.data.message);
					}
				},
				error: function (xhr, status) {
					$('#vt-ab-progress').hide();
					$btn.prop('disabled', false);
					setStatus('err', status === 'timeout' ? 'Request timed out — try a shorter post first.' : 'Request failed.');
				}
			});
		});

		function bindDelete() {
			$(document).on('click', '#vt-ab-delete', function () {
				if ( !confirm('Delete the audio file?') ) return;
				var $btn = $(this).prop('disabled', true);
				$.post(ajaxurl, { action: 'vt_audio_delete', post_id: postId, nonce: nonce }, function (r) {
					if ( r.success ) {
						setStatus('nil', 'No audio yet');
						$('#vt-ab-preview').html('');
						$('#vt-ab-generate').text('Generate Audio');
						$btn.remove();
					} else {
						$btn.prop('disabled', false);
						alert('Delete failed: ' + r.data.message);
					}
				});
			});
		}

		function setStatus(cls, msg) {
			$('#vt-ab-status').removeClass('ok nil err').addClass(cls).text(msg);
		}

		bindDelete();
	}(jQuery));
	</script>
	<?php
}

// ─── AJAX: Generate ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_vt_audio_generate', function () {
	check_ajax_referer( 'vt_audio_nonce_action', 'nonce' );

	$post_id = absint( $_POST['post_id'] ?? 0 );

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( [ 'message' => 'Permission denied.' ] );
	}

	$post    = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( [ 'message' => 'Post not found.' ] );
	}

	$api_key = get_option( 'vt_audio_api_key' );
	if ( ! $api_key ) {
		wp_send_json_error( [ 'message' => 'API key not set — go to Settings → VT Audio.' ] );
	}

	$text = vt_audio_clean_content( $post );
	if ( ! $text ) {
		wp_send_json_error( [ 'message' => 'No readable content found in this post.' ] );
	}

	// Allow long-running requests
	set_time_limit( 180 );

	$audio = vt_audio_call_openai( $api_key, $text );
	if ( is_wp_error( $audio ) ) {
		wp_send_json_error( [ 'message' => $audio->get_error_message() ] );
	}

	// Delete old audio if regenerating
	$old_id = get_post_meta( $post_id, VT_AUDIO_META_KEY, true );
	if ( $old_id ) {
		wp_delete_attachment( (int) $old_id, true );
		delete_post_meta( $post_id, VT_AUDIO_DURATION_KEY );
	}

	$filename      = 'audio-post-' . $post_id . '-' . time() . '.mp3';
	$attachment_id = vt_audio_save_mp3( $audio, $filename, $post_id );
	if ( is_wp_error( $attachment_id ) ) {
		wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
	}

	update_post_meta( $post_id, VT_AUDIO_META_KEY, $attachment_id );

	wp_send_json_success( [
		'url'           => wp_get_attachment_url( $attachment_id ),
		'attachment_id' => $attachment_id,
	] );
} );

// ─── AJAX: Delete ─────────────────────────────────────────────────────────────

add_action( 'wp_ajax_vt_audio_delete', function () {
	check_ajax_referer( 'vt_audio_nonce_action', 'nonce' );

	$post_id = absint( $_POST['post_id'] ?? 0 );

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( [ 'message' => 'Permission denied.' ] );
	}
	$attachment_id = get_post_meta( $post_id, VT_AUDIO_META_KEY, true );

	if ( $attachment_id ) {
		wp_delete_attachment( (int) $attachment_id, true );
		delete_post_meta( $post_id, VT_AUDIO_META_KEY );
		delete_post_meta( $post_id, VT_AUDIO_DURATION_KEY );
	}

	wp_send_json_success();
} );

// ─── AJAX: Record Play ────────────────────────────────────────────────────────

add_action( 'wp_ajax_vt_audio_record_play',        'vt_audio_handle_record_play' );
add_action( 'wp_ajax_nopriv_vt_audio_record_play', 'vt_audio_handle_record_play' );

function vt_audio_handle_record_play(): void {
	check_ajax_referer( 'vt_audio_play_nonce', 'nonce' );

	$post_id = absint( $_POST['post_id'] ?? 0 );
	if ( ! $post_id || get_post_status( $post_id ) !== 'publish' ) {
		wp_send_json_error( null, 400 );
	}

	// Flood guard: collapse repeat hits from the same visitor on the same post
	// into one count per window. The front-end only fires once per page load, so
	// this never drops a legitimate play — it just blocks automated inflation.
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'vt_audio_play_' . $post_id . '_' . md5( $ip );
	if ( get_transient( $key ) ) {
		wp_send_json_success(); // already counted recently — ack without inserting
	}
	set_transient( $key, 1, MINUTE_IN_SECONDS );

	global $wpdb;
	$wpdb->insert(
		$wpdb->prefix . VT_AUDIO_PLAYS_TABLE,
		[ 'post_id' => $post_id, 'played_at' => current_time( 'mysql' ) ],
		[ '%d', '%s' ]
	);
	wp_send_json_success();
}

// ─── OpenAI TTS ───────────────────────────────────────────────────────────────

/**
 * Calls OpenAI TTS, chunking input if it exceeds the 4096-char limit.
 * Returns raw MP3 binary string, or WP_Error on failure.
 *
 * @return string|WP_Error
 */
function vt_audio_call_openai( string $api_key, string $text ) {
	$allowed_models = [ 'tts-1', 'tts-1-hd' ];
	$allowed_voices = [ 'alloy', 'echo', 'fable', 'nova', 'onyx', 'shimmer' ];
	$model  = in_array( get_option( 'vt_audio_model' ), $allowed_models, true ) ? get_option( 'vt_audio_model' ) : 'tts-1-hd';
	$voice  = in_array( get_option( 'vt_audio_voice' ), $allowed_voices, true ) ? get_option( 'vt_audio_voice' ) : 'nova';
	$chunks = vt_audio_chunk_text( $text, 4000 );
	$mp3    = '';

	foreach ( $chunks as $chunk ) {
		$response = wp_remote_post( 'https://api.openai.com/v1/audio/speech', [
			'timeout' => 90,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( [
				'model' => $model,
				'input' => $chunk,
				'voice' => $voice,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$err = json_decode( $body, true );
			$msg = $err['error']['message'] ?? 'OpenAI API error (HTTP ' . $code . ')';
			return new WP_Error( 'openai_error', $msg );
		}

		$mp3 .= $body;
	}

	return $mp3;
}

/**
 * Splits text into chunks at sentence boundaries, respecting $max_chars.
 *
 * @return string[]
 */
function vt_audio_chunk_text( string $text, int $max_chars ): array {
	if ( strlen( $text ) <= $max_chars ) {
		return [ $text ];
	}

	$chunks    = [];
	$sentences = preg_split( '/(?<=[.!?])\s+/u', $text ) ?: [ $text ];
	$current   = '';

	foreach ( $sentences as $sentence ) {
		if ( strlen( $current ) + strlen( $sentence ) + 1 > $max_chars ) {
			if ( $current !== '' ) {
				$chunks[]  = trim( $current );
				$current   = '';
			}
			// Sentence itself is too long — hard split
			while ( strlen( $sentence ) > $max_chars ) {
				$chunks[]  = substr( $sentence, 0, $max_chars );
				$sentence  = substr( $sentence, $max_chars );
			}
			$current = $sentence;
		} else {
			$current .= ( $current !== '' ? ' ' : '' ) . $sentence;
		}
	}

	if ( $current !== '' ) {
		$chunks[] = trim( $current );
	}

	return array_filter( $chunks );
}

// ─── Content Cleaning ─────────────────────────────────────────────────────────

function vt_audio_clean_content( WP_Post $post ): string {
	$content = $post->post_content;

	// Strip Gutenberg block comments
	$content = preg_replace( '/<!--.*?-->/s', '', $content );

	// Remove skip tags (block elements strip content; void elements strip the tag itself)
	$skip_raw  = get_option( 'vt_audio_skip_tags', 'pre,code,figure,img,blockquote,table' );
	$skip_tags = array_filter( array_map( 'trim', explode( ',', $skip_raw ) ) );
	if ( $skip_tags ) {
		$tag_pattern = implode( '|', array_map( 'preg_quote', $skip_tags ) );
		$content = preg_replace( '/<(' . $tag_pattern . ')[^>]*>.*?<\/\1>/si', ' ', $content );
		$content = preg_replace( '/<(?:' . $tag_pattern . ')[^>]*\/?>/i', '', $content );
	}

	// Remove shortcodes
	$content = strip_shortcodes( $content );

	// Decode entities before stripping tags
	$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$content = wp_strip_all_tags( $content );

	// Clean up whitespace
	$content = preg_replace( '/\n{3,}/', "\n\n", $content );
	$content = trim( $content );

	// Prepend the title so the audio starts with the article name
	$title   = html_entity_decode( get_the_title( $post->ID ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$content = $title . ".\n\n" . $content;

	return $content;
}

// ─── Save MP3 to Media Library ────────────────────────────────────────────────

/** @return int|WP_Error attachment ID */
function vt_audio_save_mp3( string $data, string $filename, int $post_id ) {
	$upload = wp_upload_bits( $filename, null, $data );
	if ( ! empty( $upload['error'] ) ) {
		return new WP_Error( 'upload_error', $upload['error'] );
	}

	$attachment_id = wp_insert_attachment( [
		'post_mime_type' => 'audio/mpeg',
		'post_title'     => 'Audio: ' . get_the_title( $post_id ),
		'post_status'    => 'inherit',
	], $upload['file'], $post_id );

	if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
		// Run getID3 so WP records the clip length, then cache it on the post.
		// The concatenated TTS MP3 has no duration header, so the browser can't
		// derive the length itself — we hand it the authoritative value instead.
		require_once ABSPATH . 'wp-admin/includes/media.php'; // wp_read_audio_metadata()
		require_once ABSPATH . 'wp-admin/includes/image.php'; // wp_generate_attachment_metadata()
		$meta = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		if ( $meta ) {
			wp_update_attachment_metadata( $attachment_id, $meta );
		}
		if ( ! empty( $meta['length'] ) ) {
			update_post_meta( $post_id, VT_AUDIO_DURATION_KEY, (float) $meta['length'] );
		}
	}

	return $attachment_id;
}

/**
 * True when the attachment row still exists and its file is present on disk.
 * Guards against a deleted attachment (Case A) or a file removed out from under
 * WordPress while the DB row lingers (Case B) — either would otherwise leave a
 * broken player pointing at a 404.
 */
function vt_audio_has_file( int $attachment_id ): bool {
	if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
		return false;
	}
	$file = get_attached_file( $attachment_id );
	return $file && file_exists( $file );
}

/**
 * Returns the clip length in seconds, computing and caching it on first use.
 * Backfills posts whose audio was generated before duration was tracked.
 */
function vt_audio_get_duration( int $post_id, int $attachment_id ): float {
	$cached = (float) get_post_meta( $post_id, VT_AUDIO_DURATION_KEY, true );
	if ( $cached > 0 ) {
		return $cached;
	}
	if ( ! $attachment_id ) {
		return 0.0;
	}

	$meta = wp_get_attachment_metadata( $attachment_id );
	if ( empty( $meta['length'] ) ) {
		// No stored metadata (legacy attachment) — scan the file once via getID3.
		$file = get_attached_file( $attachment_id );
		if ( $file && file_exists( $file ) ) {
			// Front-end requests don't have these admin includes loaded, and
			// wp_generate_attachment_metadata() calls wp_read_audio_metadata()
			// (in media.php) for audio — load both before invoking it.
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$meta = wp_generate_attachment_metadata( $attachment_id, $file );
			if ( $meta ) {
				wp_update_attachment_metadata( $attachment_id, $meta );
			}
		}
	}

	if ( ! empty( $meta['length'] ) ) {
		$length = (float) $meta['length'];
		update_post_meta( $post_id, VT_AUDIO_DURATION_KEY, $length );
		return $length;
	}
	return 0.0;
}

// ─── Front-end Player ─────────────────────────────────────────────────────────

add_filter( 'the_content', function ( string $content ): string {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	// Skip image/gallery post formats
	$format = get_post_format();
	if ( in_array( $format, [ 'image', 'gallery' ], true ) ) {
		return $content;
	}

	$post_id       = get_the_ID();
	$attachment_id = (int) get_post_meta( $post_id, VT_AUDIO_META_KEY, true );

	// No audio, or the file/attachment has gone missing — fail silently rather
	// than render a player that points at a 404.
	if ( ! vt_audio_has_file( $attachment_id ) ) {
		return $content;
	}

	$audio_url = wp_get_attachment_url( $attachment_id );
	if ( ! $audio_url ) {
		return $content;
	}

	// Respect minimum word count setting
	$min_words = (int) get_option( 'vt_audio_min_words', 300 );
	if ( $min_words > 0 && str_word_count( wp_strip_all_tags( $content ) ) < $min_words ) {
		return $content;
	}

	$show_plays = (bool) get_option( 'vt_audio_show_plays', 1 );
	$play_count = $show_plays ? vt_audio_get_play_count( $post_id ) : 0;
	$plays_html = '';
	if ( $show_plays && $play_count > 0 ) {
		$plays_html = sprintf(
			'<span class="vt-ap-plays">%s %s</span>',
			number_format( $play_count ),
			$play_count === 1 ? 'play' : 'plays'
		);
	}

	// Authoritative duration (the MP3 has no duration header for the browser to read).
	$duration  = vt_audio_get_duration( $post_id, (int) $attachment_id );
	$dur_attr  = $duration > 0 ? sprintf( ' data-duration="%s"', esc_attr( $duration ) ) : '';
	$dur_label = $duration > 0
		? sprintf( '%d:%02d', (int) ( $duration / 60 ), (int) $duration % 60 )
		: '-:--';

	$player = sprintf(
		'<div class="vt-audio-player" role="region" aria-label="Audio version">
			<audio src="%1$s" preload="metadata"%2$s></audio>
			<button class="vt-ap-btn" aria-label="Play">
				<svg class="vt-ap-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="5,3 19,12 5,21"/></svg>
				<svg class="vt-ap-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
			</button>
			<span class="vt-ap-label">Listen to this article</span>%3$s
			<span class="vt-ap-current vt-ap-time">0:00</span>
			<input type="range" class="vt-ap-seek" min="0" value="0" step="0.1" aria-label="Seek" style="--pct:0%%">
			<span class="vt-ap-duration vt-ap-time">%4$s</span>
			<svg class="vt-ap-vol-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="11,5 6,9 2,9 2,15 6,15 11,19"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
			<input type="range" class="vt-ap-vol" min="0" max="1" step="0.05" value="1" aria-label="Volume" style="--pct:100%%">
		</div>',
		esc_url( $audio_url ),
		$dur_attr,
		$plays_html,
		esc_html( $dur_label )
	);

	return $player . $content;
} );

// ─── Front-end Styles ─────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular( 'post' ) ) return;
	if ( ! get_post_meta( get_the_ID(), VT_AUDIO_META_KEY, true ) ) return;

	wp_register_style( 'vt-audio', false, [], '1.3.4' );
	wp_enqueue_style( 'vt-audio' );
	wp_add_inline_style( 'vt-audio', '
		.vt-audio-player {
			display: flex;
			align-items: center;
			gap: .625rem;
			background: var(--surface, #f8f5f0);
			border: 1px solid var(--border, #e8e0d5);
			border-left: 3px solid var(--accent, #c8853a);
			border-radius: 8px;
			padding: .75rem 1rem;
			margin-bottom: 2rem;
		}
		.vt-ap-btn {
			flex-shrink: 0;
			width: 34px;
			height: 34px;
			border-radius: 50%;
			border: none;
			background: var(--accent, #c8853a);
			color: #fff;
			display: flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			padding: 0;
			transition: background .15s;
		}
		.vt-ap-btn:hover { background: var(--accent-hover, #b5722e); }
		.vt-ap-btn:focus-visible { outline: 2px solid var(--accent, #c8853a); outline-offset: 2px; }
		.vt-ap-btn svg { width: 15px; height: 15px; }
		.vt-ap-pause { display: none; }
		.vt-ap-label {
			font-size: .6875rem;
			font-weight: 600;
			color: var(--accent-text, #8c5a1e);
			text-transform: uppercase;
			letter-spacing: .06em;
			white-space: nowrap;
			flex-shrink: 0;
		}
		.vt-ap-time {
			font-size: .75rem;
			color: #666; /* 5.27:1 on the #f8f5f0 player surface — passes WCAG AA for small text */
			white-space: nowrap;
			flex-shrink: 0;
			font-variant-numeric: tabular-nums;
		}
		.vt-ap-plays {
			font-size: .6875rem;
			color: #666;
			white-space: nowrap;
			flex-shrink: 0;
		}
		.vt-ap-seek { flex: 1; min-width: 0; }
		.vt-ap-vol  { width: 68px; flex-shrink: 0; }
		.vt-ap-vol-icon { width: 16px; height: 16px; flex-shrink: 0; color: var(--text-muted, #888); }
		.vt-ap-seek,
		.vt-ap-vol {
			-webkit-appearance: none;
			appearance: none;
			height: 4px;
			border-radius: 2px;
			background: linear-gradient(to right,
				var(--accent, #c8853a) var(--pct, 0%),
				var(--border, #e8e0d5) var(--pct, 0%));
			outline: none;
			cursor: pointer;
			margin: 0;
		}
		.vt-ap-seek:focus-visible,
		.vt-ap-vol:focus-visible {
			outline: 2px solid var(--accent, #c8853a);
			outline-offset: 3px;
			border-radius: 2px;
		}
		.vt-ap-seek::-webkit-slider-thumb,
		.vt-ap-vol::-webkit-slider-thumb {
			-webkit-appearance: none;
			width: 13px; height: 13px;
			border-radius: 50%;
			background: var(--accent, #c8853a);
			cursor: pointer;
		}
		.vt-ap-seek::-moz-range-thumb,
		.vt-ap-vol::-moz-range-thumb {
			width: 13px; height: 13px;
			border-radius: 50%;
			background: var(--accent, #c8853a);
			border: none;
			cursor: pointer;
		}
		[data-theme="dark"] .vt-audio-player {
			background: var(--surface, #1e1a16);
			border-color: var(--border, #3a3228);
		}
		[data-theme="dark"] .vt-ap-time,
		[data-theme="dark"] .vt-ap-plays { color: var(--text-muted, #9e9690); }
		@media (max-width: 560px) {
			.vt-ap-label, .vt-ap-plays { display: none; }
			.vt-ap-vol   { width: 52px; }
		}
	' );

	wp_register_script( 'vt-audio', false, [], '1.3.4', true );
	wp_enqueue_script( 'vt-audio' );
	wp_localize_script( 'vt-audio', 'vtAudio', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'vt_audio_play_nonce' ),
		'postId'  => get_the_ID(),
	] );
	wp_add_inline_script( 'vt-audio', '
		document.querySelectorAll(".vt-audio-player").forEach(function(player) {
			var audio   = player.querySelector("audio");
			var btn     = player.querySelector(".vt-ap-btn");
			var iconPl  = player.querySelector(".vt-ap-play");
			var iconPa  = player.querySelector(".vt-ap-pause");
			var seek    = player.querySelector(".vt-ap-seek");
			var vol     = player.querySelector(".vt-ap-vol");
			var current = player.querySelector(".vt-ap-current");
			var dur     = player.querySelector(".vt-ap-duration");

			function fmt(s) {
				if (!isFinite(s)) return "-:--";
				var m = Math.floor(s / 60), ss = Math.floor(s % 60);
				return m + ":" + (ss < 10 ? "0" : "") + ss;
			}

			var measuring = false;
			var knownDur  = parseFloat(audio.getAttribute("data-duration")) || 0;

			// Effective duration: the browser value if it has one, else the
			// server-supplied length (the MP3 has no duration header).
			function effDur() {
				if (isFinite(audio.duration) && audio.duration > 0) return audio.duration;
				return knownDur > 0 ? knownDur : 0;
			}

			function applyDuration() {
				var d = effDur();
				if (d <= 0) return false;
				seek.max = d;
				seek.setAttribute("aria-valuemax", Math.floor(d));
				dur.textContent = fmt(d);
				return true;
			}

			// Set up immediately from the server-known duration if we have it.
			applyDuration();

			audio.addEventListener("loadedmetadata", function() {
				applyDuration();
				// If the browser itself never resolved a finite duration, probe by
				// seeking to the end so byte-range seeking works reliably.
				if (!(isFinite(audio.duration) && audio.duration > 0)) {
					measuring = true;
					audio.currentTime = 1e101;
				}
			});

			audio.addEventListener("durationchange", function() {
				if (measuring && isFinite(audio.duration)) {
					measuring = false;
					applyDuration();
					audio.currentTime = 0;
				}
			});

			audio.addEventListener("timeupdate", function() {
				if (measuring) return; // ignore the seek-to-end probe
				var d = effDur();
				if (!seek._dragging) {
					seek.value = audio.currentTime;
					var pct = d > 0 ? audio.currentTime / d * 100 : 0;
					seek.style.setProperty("--pct", pct + "%");
				}
				var t = fmt(audio.currentTime);
				current.textContent = t;
				seek.setAttribute("aria-valuetext", t);
			});

			audio.addEventListener("ended", function() {
				iconPl.style.display = "block"; iconPa.style.display = "none";
				btn.setAttribute("aria-label", "Play");
			});

			btn.addEventListener("click", function() {
				if (audio.paused) {
					audio.play();
					iconPl.style.display = "none"; iconPa.style.display = "block";
					btn.setAttribute("aria-label", "Pause");
				} else {
					audio.pause();
					iconPl.style.display = "block"; iconPa.style.display = "none";
					btn.setAttribute("aria-label", "Play");
				}
			});

			seek.addEventListener("mousedown", function() { seek._dragging = true; });
			seek.addEventListener("mouseup",   function() { seek._dragging = false; });
			seek.addEventListener("input", function() {
				audio.currentTime = this.value;
				this.style.setProperty("--pct", (this.value / this.max * 100) + "%");
			});

			vol.addEventListener("input", function() {
				audio.volume = this.value;
				this.style.setProperty("--pct", (this.value * 100) + "%");
				this.setAttribute("aria-valuetext", Math.round(this.value * 100) + "%");
			});

			audio.addEventListener("play", function() {
				if (typeof vtAudio === "undefined") return;
				fetch(vtAudio.ajaxUrl, {
					method: "POST",
					headers: {"Content-Type": "application/x-www-form-urlencoded"},
					body: "action=vt_audio_record_play&post_id=" + vtAudio.postId + "&nonce=" + vtAudio.nonce,
					keepalive: true
				});
			}, {once: true});
		});
	' );
} );
