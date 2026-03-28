<?php
/**
 * MFSD Arcade — Game Loader
 * Serves game HTML and static assets through session-gated REST endpoints.
 *
 * Game files live in /games/{slug}/ inside the plugin directory,
 * protected by .htaccess to block direct HTTP access.
 * Every request validates: logged in + student/admin role + valid active session token.
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
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'json' => 'application/json',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
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

        /* Serve static assets (JS, CSS, audio, images) */
        register_rest_route($ns, '/game-asset/(?P<slug>[a-z0-9_-]+)/(?P<file>[a-zA-Z0-9_.\-]+)', array(
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
       VALIDATE SESSION — checks login, role, and active session token
       Returns session row or WP_Error.
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

        /*
         * Verify the session owner is a student/admin.
         * We authenticate via the session token itself (not WordPress cookies)
         * because this endpoint is loaded as an iframe src — the browser sends
         * the auth cookie but NOT the X-WP-Nonce, so WP REST won't recognise
         * the user as logged in. The token is the auth credential here.
         */
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

        /* Look for a manifest file, or fall back to building from index.html */
        $manifest_path = $game_dir . 'mfsd-manifest.json';
        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
        } else {
            $manifest = $this->auto_detect_manifest($slug, $game_dir);
        }

        /* Build the asset base URL (all assets go through the gated endpoint) */
        $asset_base  = rest_url('mfsd-arcade/v1/game-asset/' . $slug . '/');
        $token_query = '?token=' . urlencode($token);

        /* Assemble the HTML */
        $html = $this->build_game_html($slug, $token, $manifest, $asset_base, $game_dir, $token_query);

        /* Serve as a full HTML page (bypass REST JSON response) */
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Frame-Options: SAMEORIGIN');
        echo $html;
        exit;
    }

    /* ================================================================
       SERVE STATIC ASSET — JS, CSS, audio, images
       ================================================================ */
    public function serve_asset($req) {
        $slug  = $req->get_param('slug');
        $file  = $req->get_param('file');
        $token = $req->get_param('token');

        $session = $this->validate_request($token);
        if (is_wp_error($session)) return $session;

        /* Security: prevent directory traversal */
        $file = basename($file);
        $filepath = $this->games_dir . $slug . '/' . $file;

        if (!file_exists($filepath)) {
            return new WP_Error('asset_not_found', 'Asset not found.', array('status' => 404));
        }

        /* Determine MIME type */
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$ext] ?? 'application/octet-stream';

        /* Serve the file */
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: private, max-age=3600'); /* cache for 1h (token will expire anyway) */
        readfile($filepath);
        exit;
    }

    /* ================================================================
       AUTO-DETECT MANIFEST — for games without a manifest file
       Scans the game directory and builds a list of files by type.
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
                /* Leaderboard files go in body (after game), everything else in head */
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
    private function build_game_html($slug, $token, $manifest, $asset_base, $game_dir, $token_query = '') {
        /* If there's a custom template in the game dir, use it */
        $template_path = $game_dir . 'mfsd-template.html';
        if (file_exists($template_path)) {
            $html = file_get_contents($template_path);
            /* Replace asset placeholders */
            $html = str_replace('{{ASSET_BASE}}', $asset_base, $html);
            $html = str_replace('{{TOKEN}}', esc_attr($token), $html);
            $html = str_replace('{{TOKEN_QUERY}}', $token_query, $html);
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
<?php foreach (($manifest['stylesheets'] ?? array()) as $css): ?>
<link rel="stylesheet" href="<?php echo esc_url($asset_base . urlencode($css) . $token_query); ?>">
<?php endforeach; ?>
<script>
/* Rewrite relative asset URLs (audio, images) through the gated endpoint.
   Game code uses e.g. new Audio("laser.wav") which resolves relative to
   the REST game page — this patch redirects them to /game-asset/ with token. */
(function(){
  var base  = <?php echo wp_json_encode($asset_base); ?>;
  var tq    = <?php echo wp_json_encode($token_query); ?>;
  function rewrite(src) {
    if (!src || src.indexOf('://') !== -1 || src.indexOf('//') === 0 || src.indexOf('data:') === 0) return src;
    return base + encodeURIComponent(src) + tq;
  }
  /* Patch Audio constructor */
  var _Audio = window.Audio;
  window.Audio = function(src) { return new _Audio(rewrite(src)); };
  window.Audio.prototype = _Audio.prototype;
  /* Patch Image.src setter for any image assets loaded by game code */
  var _imgDesc = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
  Object.defineProperty(HTMLImageElement.prototype, 'src', {
    set: function(v) { _imgDesc.set.call(this, rewrite(v)); },
    get: function()  { return _imgDesc.get.call(this); }
  });
})();
</script>
<?php foreach (($manifest['head_scripts'] ?? array()) as $js): ?>
<script src="<?php echo esc_url($asset_base . urlencode($js) . $token_query); ?>"></script>
<?php endforeach; ?>
<style>
  html, body { margin: 0; padding: 0; overflow: hidden; background: #000; }
  #canvas { display: block; margin: 0 auto; background: <?php echo esc_attr($manifest['canvas_bg'] ?? '#fff'); ?>; }
  #game-container { width: 100%; height: 100vh; display: flex; align-items: center; justify-content: center; }
</style>
</head>
<body>
<div id="game-container">
  <canvas id="<?php echo esc_attr($manifest['canvas_id'] ?? 'canvas'); ?>"<?php
    if (!empty($manifest['canvas_width']))  echo ' width="'  . (int) $manifest['canvas_width']  . '"';
    if (!empty($manifest['canvas_height'])) echo ' height="' . (int) $manifest['canvas_height'] . '"';
  ?>></canvas>
</div>
<?php foreach (($manifest['body_scripts'] ?? array()) as $js): ?>
<script src="<?php echo esc_url($asset_base . urlencode($js) . $token_query); ?>"></script>
<?php endforeach; ?>
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