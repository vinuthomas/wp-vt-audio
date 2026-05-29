<?php
/**
 * Plugin Name: VT Audio
 * Description: Generate AI audio versions of posts via OpenAI TTS and embed a native player.
 * Version: 1.1.0
 * Author: Vinu Thomas
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'VT_AUDIO_META_KEY', '_vt_audio_attachment_id' );

// ─── Settings ─────────────────────────────────────────────────────────────────

add_action( 'admin_init', function () {
	register_setting( 'vt_audio', 'vt_audio_api_key',   [ 'sanitize_callback' => 'sanitize_text_field' ] );
	register_setting( 'vt_audio', 'vt_audio_model',     [ 'sanitize_callback' => 'sanitize_text_field', 'default' => 'tts-1-hd' ] );
	register_setting( 'vt_audio', 'vt_audio_voice',     [ 'sanitize_callback' => 'sanitize_text_field', 'default' => 'nova' ] );
	register_setting( 'vt_audio', 'vt_audio_min_words', [ 'sanitize_callback' => 'absint',              'default' => 300 ] );
} );

add_action( 'admin_menu', function () {
	add_options_page( 'VT Audio', 'VT Audio', 'manage_options', 'vt-audio', 'vt_audio_settings_page' );
} );

function vt_audio_settings_page(): void {
	$voices        = [ 'alloy', 'echo', 'fable', 'nova', 'onyx', 'shimmer' ];
	$current_voice = get_option( 'vt_audio_voice', 'nova' );
	$current_model = get_option( 'vt_audio_model', 'tts-1-hd' );
	?>
	<div class="wrap">
		<h1>VT Audio Settings</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'vt_audio' ); ?>
			<table class="form-table">
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
				<tr>
					<th><label for="vt_audio_min_words">Min word count</label></th>
					<td>
						<input type="number" id="vt_audio_min_words" name="vt_audio_min_words"
							value="<?php echo esc_attr( get_option( 'vt_audio_min_words', 300 ) ); ?>"
							min="0" class="small-text">
						<p class="description">Player won't show on posts shorter than this. Set 0 to always show.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

// ─── Meta Box ─────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'vt_audio', 'Audio Version', 'vt_audio_meta_box_html', 'post', 'side' );
} );

function vt_audio_meta_box_html( WP_Post $post ): void {
	$attachment_id = get_post_meta( $post->ID, VT_AUDIO_META_KEY, true );
	$audio_url     = $attachment_id ? wp_get_attachment_url( (int) $attachment_id ) : '';
	$has_audio     = (bool) $audio_url;

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

		<div class="vt-ab-status <?php echo $has_audio ? 'ok' : 'nil'; ?>" id="vt-ab-status">
			<?php echo $has_audio ? 'Audio generated' : 'No audio yet'; ?>
		</div>

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
	}

	wp_send_json_success();
} );

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

	// Remove code blocks silently — pre/code content is never read aloud
	$content = preg_replace( '/<(pre|code)[^>]*>.*?<\/\1>/si', ' ', $content );

	// Remove figures and images
	$content = preg_replace( '/<figure[^>]*>.*?<\/figure>/si', '', $content );
	$content = preg_replace( '/<img[^>]*>/i', '', $content );

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

	return $attachment_id;
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
	$attachment_id = get_post_meta( $post_id, VT_AUDIO_META_KEY, true );
	if ( ! $attachment_id ) {
		return $content;
	}

	$audio_url = wp_get_attachment_url( (int) $attachment_id );
	if ( ! $audio_url ) {
		return $content;
	}

	// Respect minimum word count setting
	$min_words = (int) get_option( 'vt_audio_min_words', 300 );
	if ( $min_words > 0 && str_word_count( wp_strip_all_tags( $content ) ) < $min_words ) {
		return $content;
	}

	$player = sprintf(
		'<div class="vt-audio-player" role="region" aria-label="Audio version">
			<audio src="%s" preload="metadata"></audio>
			<button class="vt-ap-btn" aria-label="Play">
				<svg class="vt-ap-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="5,3 19,12 5,21"/></svg>
				<svg class="vt-ap-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
			</button>
			<span class="vt-ap-label">Listen to this article</span>
			<span class="vt-ap-current vt-ap-time">0:00</span>
			<input type="range" class="vt-ap-seek" min="0" value="0" step="0.1" aria-label="Seek" style="--pct:0%%">
			<span class="vt-ap-duration vt-ap-time">-:--</span>
			<svg class="vt-ap-vol-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="11,5 6,9 2,9 2,15 6,15 11,19"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
			<input type="range" class="vt-ap-vol" min="0" max="1" step="0.05" value="1" aria-label="Volume" style="--pct:100%%">
		</div>',
		esc_url( $audio_url )
	);

	return $player . $content;
} );

// ─── Front-end Styles ─────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular( 'post' ) ) return;
	if ( ! get_post_meta( get_the_ID(), VT_AUDIO_META_KEY, true ) ) return;

	wp_register_style( 'vt-audio', false, [], '1.1.0' );
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
			color: var(--text-muted, #888);
			white-space: nowrap;
			flex-shrink: 0;
			font-variant-numeric: tabular-nums;
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
		@media (max-width: 560px) {
			.vt-ap-label { display: none; }
			.vt-ap-vol   { width: 52px; }
		}
	' );

	wp_register_script( 'vt-audio', false, [], '1.1.0', true );
	wp_enqueue_script( 'vt-audio' );
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

			audio.addEventListener("loadedmetadata", function() {
				seek.max = audio.duration;
				seek.setAttribute("aria-valuemax", Math.floor(audio.duration));
				dur.textContent = fmt(audio.duration);
			});

			audio.addEventListener("timeupdate", function() {
				if (!seek._dragging) {
					seek.value = audio.currentTime;
					seek.style.setProperty("--pct", (audio.duration ? audio.currentTime / audio.duration * 100 : 0) + "%");
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
		});
	' );
} );
