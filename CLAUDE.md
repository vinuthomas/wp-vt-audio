# CLAUDE.md — vt-audio plugin

A single-file WordPress plugin that generates AI audio versions of posts using OpenAI TTS and embeds a custom player on the front end.

## File

```
vt-audio.php    — entire plugin: settings, meta box, AJAX handlers, OpenAI API, front-end player
```

## How it works

1. Admin enters OpenAI API key at **Settings → VT Audio**
2. On any post edit screen, the "Audio Version" sidebar meta box lets you **Generate Audio**
3. Plugin strips HTML/code blocks/images from post content, prepends the title, calls OpenAI TTS
4. MP3 is saved to the WP media library and linked to the post via `_vt_audio_attachment_id` post meta
5. On the front-end single post, a custom player is injected above `the_content`

## Settings (stored in wp_options)

| Option | Default | Notes |
|---|---|---|
| `vt_audio_api_key` | — | OpenAI secret key, plain text |
| `vt_audio_model` | `tts-1-hd` | Whitelisted: `tts-1`, `tts-1-hd` |
| `vt_audio_voice` | `nova` | Whitelisted: alloy, echo, fable, nova, onyx, shimmer |
| `vt_audio_min_words` | `300` | Posts under this count don't show the player |

## Post meta

- `_vt_audio_attachment_id` — ID of the MP3 attachment in the WP media library

## Key implementation details

- **Chunking**: OpenAI TTS has a 4096-char input limit. `vt_audio_chunk_text()` splits at sentence boundaries; resulting MP3 chunks are binary-concatenated into one file.
- **Content cleaning**: Gutenberg block comments, `<pre>`/`<code>` blocks, `<figure>`/`<img>` tags, and shortcodes are all stripped before sending to TTS. Code blocks are dropped silently (no announcement).
- **Post formats**: Posts with `image` or `gallery` post format never show the player.
- **Security**: AJAX handlers use `check_ajax_referer` + `current_user_can('edit_post', $post_id)`. Model and voice are validated against whitelists before being sent to OpenAI.
- **Accessibility**: Custom player uses `aria-label` on the play/pause button (updated dynamically), `aria-valuetext` on seek (formatted time) and volume (percentage) sliders, and `:focus-visible` outlines on all interactive elements.

## Front-end player structure

```html
<div class="vt-audio-player" role="region" aria-label="Audio version">
  <audio src="...mp3" preload="metadata"></audio>
  <button class="vt-ap-btn" aria-label="Play/Pause">   <!-- SVG icons swap -->
  <span class="vt-ap-label">Listen to this article</span>
  <span class="vt-ap-current vt-ap-time">0:00</span>
  <input type="range" class="vt-ap-seek" ...>
  <span class="vt-ap-duration vt-ap-time">-:--</span>
  <svg class="vt-ap-vol-icon" ...>                      <!-- speaker icon -->
  <input type="range" class="vt-ap-vol" ...>
</div>
```

CSS uses the theme's CSS custom properties (`--accent`, `--accent-text`, `--surface`, `--border`) so it inherits both light and dark mode automatically.

## Local development

The plugin directory is symlinked into the local Docker WordPress install:

```
/Users/vinuthomas/code/vinuthomas-local/site/wp-content/plugins/vt-audio
  → /Users/vinuthomas/code/vinuthomas.com-voiceplugin
```

Start local site: `cd /Users/vinuthomas/code/vinuthomas-local && docker compose up -d`
Site runs at **http://localhost:8080**

## Deploying to production

Upload the plugin folder to `wp-content/plugins/vt-audio/` on the live site and activate via **Plugins**. The OpenAI API key must be re-entered in Settings → VT Audio on the production site.

Bump `Version:` in the plugin header and the `wp_register_style`/`wp_register_script` version strings before uploading updates.

## Production packaging

**Always confirm with the user before creating a package.**

When the user gives the go-ahead:

1. Bump the `Version:` in the plugin header (`* Version: X.Y.Z`) and update the matching version strings in `wp_register_style()` and `wp_register_script()` calls.
2. Update `Tested up to:` in the plugin header if the WordPress version has changed. Current value: `7.0`. `Requires at least:` stays at `6.0` unless explicitly changed.
3. Create a zip at the project root:
   ```bash
   zip -r vt-audio.zip vt-audio.php --exclude "*.DS_Store"
   ```
4. Copy the zip to the Desktop: `cp vt-audio.zip ~/Desktop/vt-audio.zip`
5. Tell the user the zip path and new version number so they can upload it via **Plugins → Add New → Upload Plugin** in wp-admin.
