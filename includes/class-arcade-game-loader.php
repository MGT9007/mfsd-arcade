<?php
/**
 * MFSD Arcade — Game Loader
 * Serves game HTML and static assets through session-gated REST endpoints.
 *
 * Game files live in /games/{slug}/ inside the plugin directory,
 * protected by .htaccess to block direct HTTP access.
 * Every request validates a valid active session token.
 *
 * Supports subdirectory assets (e.g. images/bg.png, sounds/coin.wav)
 * via query-param routing: /game-asset/{slug}?file=path/to/file&token=xxx
 */

if (!defined('ABSPATH')) exit;

class MFSD_Arcade_Game_Loader {

    /** Allowed static asset extensions and their MIME types. */
    const MIME_TYPES = array(
        'js'   => 'application/javascript',
        'css'  => 'text/css',
        'wav'  => 'audio/wav',
        'mp3'  => 'audio/mpeg',
        'ogg'  => 'audio/ogg',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'json' => 'application/json',
        'html' => 'text/html',
        'htm'  => 'text/html',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
    );

    /** Base path to the games directory. */
    private $games_dir;

    public function __construct() {
        $this->games_dir = plugin_dir_path(dirname(__FILE__)) . 'games/';
    }

    /* ================================================================
       REGISTER REST ROUTES
       ================================================================ */
    public function register_routes() {
        $ns = 'mfsd-arcade/v1';

        /* Serve the game HTML page */
        register_rest_route($ns, '/game/(?P<slug>[a-z0-9_-]+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'serve_game'),
            'permission_callback' => '__return_true', /* auth handled inside */
            'args' => array(
                'slug'  => array('required' => true, 'sanitize_callback' => 'sanitize_key'),
                'token' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        /* Serve static assets — file path passed as query param to support subdirectories.
           URL format: /game-asset/{slug}?file=images/bg.png&token=xxx */
        register_rest_route($ns, '/game-asset/(?P<slug>[a-z0-9_-]+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'serve_asset'),
            'permission_callback' => '__return_true', /* auth handled inside */
            'args' => array(
                'slug'  => array('required' => true, 'sanitize_callback' => 'sanitize_key'),
                'file'  => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'token' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));
    }

    /* ================================================================
       VALIDATE SESSION — checks active session token + student role.
       Authenticates via token (not WP cookies) because this runs
       inside an iframe that doesn't send X-WP-Nonce.
       ================================================================ */
    private function validate_request($token) {
        if (empty($token)) {
            return new WP_Error('no_token', 'Session token required.', array('status' => 400));
        }

        /* Run cleanup first */
        $session_mgr = new MFSD_Arcade_Session();
        $session_mgr->cleanup();

        /* Validate token against an active session */
        $db = new MFSD_Arcade_DB();
        $session = $db->get_session_by_token($token);

        if (!$session) {
            return new WP_Error('invalid_token', 'Invalid or expired session.', array('status' => 403));
        }

        if ($session['status'] !== 'active' && $session['status'] !== 'paused') {
            return new WP_Error('session_ended', 'Your session has ended.', array('status' => 403));
        }

        $user = get_userdata((int) $session['student_id']);
        if (!$user) {
            return new WP_Error('invalid_user', 'Session owner not found.', array('status' => 403));
        }

        if (!in_array('student', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
            return new WP_Error('not_student', 'Arcade access is for students only.', array('status' => 403));
        }

        return $session;
    }

    /* ================================================================
       SERVE GAME HTML — builds the full page dynamically
       ================================================================ */
    public function serve_game($req) {
        $slug  = $req->get_param('slug');
        $token = $req->get_param('token');

        $session = $this->validate_request($token);
        if (is_wp_error($session)) return $session;

        /* Check game directory exists */
        $game_dir = $this->games_dir . $slug . '/';
        if (!is_dir($game_dir)) {
            return new WP_Error('game_not_found', 'Game not found.', array('status' => 404));
        }

        /* Look for a manifest file, or fall back to auto-detection */
        $manifest_path = $game_dir . 'mfsd-manifest.json';
        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
        } else {
            $manifest = $this->auto_detect_manifest($slug, $game_dir);
        }

        /* Build the asset base URL — file passed as query param for subdirectory support.
           Format: /wp-json/mfsd-arcade/v1/game-asset/{slug}?file={path}&token={token} */
        $asset_base  = rest_url('mfsd-arcade/v1/game-asset/' . $slug);
        $token_param = urlencode($token);

        /* Assemble the HTML */
        $html = $this->build_game_html($slug, $token, $manifest, $asset_base, $game_dir, $token_param);

        /* Serve as a full HTML page (bypass REST JSON response) */
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Frame-Options: SAMEORIGIN');
        echo $html;
        exit;
    }

    /* ================================================================
       SERVE STATIC ASSET — JS, CSS, audio, images (with subdirs)
       ================================================================ */
    public function serve_asset($req) {
        $slug  = $req->get_param('slug');
        $file  = $req->get_param('file');
        $token = $req->get_param('token');

        $session = $this->validate_request($token);
        if (is_wp_error($session)) return $session;

        /* ── Security: sanitise the file path ── */
        /* Normalise separators */
        $file = str_replace('\\', '/', $file);

        /* Strip leading slashes */
        $file = ltrim($file, '/');

        if ($file === '') {
            return new WP_Error('invalid_path', 'Invalid file path.', array('status' => 400));
        }

        /* Resolve the full path and verify it stays within the game directory.
           realpath() resolves '..' segments and symlinks, then we check the
           resolved path is still inside the game folder. This safely handles
           RequireJS-style relative paths like js/views/../../templates/foo.html
           while blocking any actual escape from the game directory. */
        $game_dir = realpath($this->games_dir . $slug);
        if (!$game_dir) {
            return new WP_Error('game_not_found', 'Game not found.', array('status' => 404));
        }

        $filepath = realpath($game_dir . '/' . $file);
        if (!$filepath || strpos($filepath, $game_dir . DIRECTORY_SEPARATOR) !== 0) {
            return new WP_Error('asset_not_found', 'Asset not found.', array('status' => 404));
        }

        if (!is_file($filepath)) {
            return new WP_Error('asset_not_found', 'Asset not found.', array('status' => 404));
        }

        /* Block hidden files (dotfiles) after resolution */
        $resolved_relative = substr($filepath, strlen($game_dir) + 1);
        $segments = explode(DIRECTORY_SEPARATOR, $resolved_relative);
        foreach ($segments as $seg) {
            if ($seg !== '' && $seg[0] === '.') {
                return new WP_Error('invalid_path', 'Invalid file path.', array('status' => 400));
            }
        }

        /* Check the extension is allowed */
        $ext  = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$ext] ?? null;
        if (!$mime) {
            return new WP_Error('invalid_type', 'File type not allowed.', array('status' => 403));
        }

        /* Serve the file */
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: private, max-age=3600');
        readfile($filepath);
        exit;
    }

    /* ================================================================
       AUTO-DETECT MANIFEST — for games without a manifest file
       ================================================================ */
    private function auto_detect_manifest($slug, $game_dir) {
        $manifest = array(
            'head_scripts' => array(),
            'body_scripts' => array(),
            'stylesheets'  => array(),
            'canvas_id'    => 'canvas',
        );

        $files = scandir($game_dir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

            if ($ext === 'css') {
                $manifest['stylesheets'][] = $f;
            } elseif ($ext === 'js') {
                if (strpos($f, 'mfsd-') === 0) {
                    $manifest['body_scripts'][] = $f;
                } else {
                    $manifest['head_scripts'][] = $f;
                }
            }
        }

        return $manifest;
    }

    /* ================================================================
       BUILD GAME HTML — the full page served inside the iframe
       ================================================================ */
    private function build_game_html($slug, $token, $manifest, $asset_base, $game_dir, $token_param) {

        /* Helper: build a gated asset URL from a relative file path */
        $asset_url = function ($file) use ($asset_base, $token_param) {
            return $asset_base . '?file=' . urlencode($file) . '&token=' . $token_param;
        };

        /* If there's a custom template in the game dir, use it */
        $template_path = $game_dir . 'mfsd-template.html';
        if (file_exists($template_path)) {
            $html = file_get_contents($template_path);
            $html = str_replace('{{ASSET_BASE}}', $asset_base, $html);
            $html = str_replace('{{TOKEN}}', esc_attr($token), $html);
            $html = str_replace('{{TOKEN_PARAM}}', $token_param, $html);
            $html = str_replace('{{SLUG}}', esc_attr($slug), $html);
            return $html;
        }

        /* Otherwise, auto-build from manifest */
        ob_start();
        ?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MFSD Arcade</title>
<?php /* ── External CDN scripts (e.g. jQuery) ── */ ?>
<?php foreach (($manifest['cdn_scripts'] ?? array()) as $cdn): ?>
<script src="<?php echo esc_url($cdn); ?>"></script>
<?php endforeach; ?>
<?php /* ── Stylesheets ── */ ?>
<?php foreach (($manifest['stylesheets'] ?? array()) as $css): ?>
<link rel="stylesheet" href="<?php echo esc_url($asset_url($css)); ?>">
<?php endforeach; ?>
<script>
/* ── Asset URL rewriter ──
   Intercepts relative URLs used by game code (new Audio("sounds/coin.wav"),
   Image.src = "images/bg.png") and rewrites them through the gated endpoint.
   Supports both flat files and subdirectory paths. */
(function(){
  var base = <?php echo wp_json_encode($asset_base); ?>;
  var tp   = <?php echo wp_json_encode($token_param); ?>;
  function rewrite(src) {
    if (!src) return src;
    /* Skip absolute URLs, data URIs, blob URIs, already-rewritten URLs */
    if (src.indexOf('://') !== -1 || src.indexOf('//') === 0 ||
        src.indexOf('data:') === 0 || src.indexOf('blob:') === 0 ||
        src.indexOf(base) !== -1) return src;
    return base + '?file=' + encodeURIComponent(src) + '&token=' + tp;
  }
  /* Patch Audio constructor */
  var _Audio = window.Audio;
  window.Audio = function(src) { return new _Audio(rewrite(src)); };
  window.Audio.prototype = _Audio.prototype;
  /* Patch Image.src setter */
  var _imgDesc = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
  Object.defineProperty(HTMLImageElement.prototype, 'src', {
    set: function(v) { _imgDesc.set.call(this, rewrite(v)); },
    get: function()  { return _imgDesc.get.call(this); }
  });
  /* Patch Audio.src setter (for games that create Audio() then set .src separately) */
  var _audDesc = Object.getOwnPropertyDescriptor(HTMLMediaElement.prototype, 'src');
  if (_audDesc && _audDesc.set) {
    Object.defineProperty(HTMLMediaElement.prototype, 'src', {
      set: function(v) { _audDesc.set.call(this, rewrite(v)); },
      get: function()  { return _audDesc.get.call(this); }
    });
  }
})();
</script>
<?php /* ── Head scripts ── */ ?>
<?php foreach (($manifest['head_scripts'] ?? array()) as $js): ?>
<script src="<?php echo esc_url($asset_url($js)); ?>"></script>
<?php endforeach; ?>
<style>
  html, body { margin: 0; padding: 0; overflow: hidden; background: #000; }
  #canvas { display: block; margin: 0 auto; background: <?php echo esc_attr($manifest['canvas_bg'] ?? '#fff'); ?>; }
  #game-container { width: 100%; height: 100vh; display: flex; align-items: center; justify-content: center; }
</style>
<?php /* ── Extra head HTML from manifest ── */ ?>
<?php if (!empty($manifest['extra_head_html'])) echo $manifest['extra_head_html']; ?>
</head>
<body>
<div id="game-container">
  <canvas id="<?php echo esc_attr($manifest['canvas_id'] ?? 'canvas'); ?>"<?php
    if (!empty($manifest['canvas_width']))  echo ' width="'  . (int) $manifest['canvas_width']  . '"';
    if (!empty($manifest['canvas_height'])) echo ' height="' . (int) $manifest['canvas_height'] . '"';
  ?>></canvas>
</div>
<?php /* ── Body scripts ── */ ?>
<?php foreach (($manifest['body_scripts'] ?? array()) as $js): ?>
<script src="<?php echo esc_url($asset_url($js)); ?>"></script>
<?php endforeach; ?>
<?php /* ── Inline init script from manifest ── */ ?>
<?php if (!empty($manifest['init_script'])): ?>
<script><?php echo $manifest['init_script']; ?></script>
<?php endif; ?>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
       GET THE GATED GAME URL — for use in the arcade admin/shortcode
       ================================================================ */
    public static function get_game_url($slug, $token) {
        return rest_url('mfsd-arcade/v1/game/' . $slug) . '?token=' . urlencode($token);
    }
}