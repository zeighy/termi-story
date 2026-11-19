<?php
// src/Security.php
// Session hardening, CSRF tokens and request helpers shared by the player terminal and the admin panel.

class Security {
    const PLAYER_SESSION_NAME = 'termistory';
    const ADMIN_SESSION_NAME = 'termistory_admin';

    /**
     * Start a session with hardened cookie settings.
     * The player terminal and the admin panel use separate session names so that
     * logging out of one never affects the other.
     */
    public static function startSession($name = self::PLAYER_SESSION_NAME) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Reject session IDs the server did not issue (mitigates session fixation)
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        session_name($name);
        session_set_cookie_params([
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Issue a fresh session ID after a privilege change (login), discarding the old one.
     */
    public static function regenerateSession() {
        session_regenerate_id(true);
    }

    /**
     * Fully end the current session and expire its cookie.
     */
    public static function destroySession() {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function isHttps() {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        // Common reverse-proxy header. Only affects the cookie's Secure flag.
        return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    // --- CSRF ---

    public static function csrfToken() {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function rotateCsrfToken() {
        unset($_SESSION['csrf_token']);
        return self::csrfToken();
    }

    public static function verifyCsrfToken($token) {
        $expected = $_SESSION['csrf_token'] ?? '';
        return is_string($token) && is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    /** Token sent by JavaScript in the X-CSRF-Token header. */
    public static function csrfTokenFromHeader() {
        return $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }

    // --- Request helpers ---

    public static function isPost() {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }

    /**
     * True when the request declares a JSON body. Browsers cannot send this
     * content type cross-site without a CORS preflight, so requiring it blocks
     * form-based and text/plain CSRF.
     */
    public static function isJsonRequest() {
        $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        return $contentType === 'application/json';
    }

    /** The client address used for rate limiting. Proxy headers are deliberately not trusted. */
    public static function clientIp() {
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
