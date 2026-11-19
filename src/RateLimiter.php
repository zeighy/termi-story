<?php
// src/RateLimiter.php
// A small file-based sliding-window limiter for failed password attempts.
// It needs no database changes. Each key is stored as one JSON file of
// timestamps in RATE_LIMIT_DIR (default: <system temp dir>/termi-story-ratelimit).
//
// If the storage directory cannot be used, the limiter fails open (logs and allows the
// attempt) so a misconfigured temp dir never locks everyone out of the game.

class RateLimiter {
    /**
     * Seconds until another attempt is allowed, or 0 when the key is under its limit.
     */
    public static function retryAfter($key, $maxAttempts, $windowSeconds) {
        $now = time();
        $attempts = self::withLockedFile($key, function ($attempts) use ($now, $windowSeconds) {
            return self::prune($attempts, $now, $windowSeconds);
        });
        if ($attempts === null || count($attempts) < $maxAttempts) {
            return 0;
        }
        // The window frees up when the oldest attempt that still counts expires
        $oldestCounted = $attempts[count($attempts) - $maxAttempts];
        return max(1, ($oldestCounted + $windowSeconds) - $now);
    }

    /** Record one failed attempt against the key. */
    public static function hit($key, $windowSeconds) {
        $now = time();
        self::withLockedFile($key, function ($attempts) use ($now, $windowSeconds) {
            $attempts = self::prune($attempts, $now, $windowSeconds);
            $attempts[] = $now;
            return $attempts;
        });
        self::maybeCollectGarbage();
    }

    /** Forget all attempts for the key (e.g. after a successful login). */
    public static function clear($key) {
        $file = self::fileFor($key);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    // --- internals ---

    private static function prune($attempts, $now, $windowSeconds) {
        $attempts = array_values(array_filter($attempts, function ($ts) use ($now, $windowSeconds) {
            return is_int($ts) && $ts > $now - $windowSeconds;
        }));
        sort($attempts);
        return $attempts;
    }

    /**
     * Open the key's file under an exclusive lock, pass its attempts to $mutate,
     * write back what it returns, and return that. Returns null if storage is unavailable.
     */
    private static function withLockedFile($key, callable $mutate) {
        $file = self::fileFor($key);
        if ($file === null) {
            return null;
        }
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            error_log("termi-story: rate limiter could not open {$file}");
            return null;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }
            $raw = stream_get_contents($handle);
            $attempts = json_decode($raw === false ? '' : $raw, true);
            if (!is_array($attempts)) {
                $attempts = [];
            }
            $attempts = $mutate($attempts);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($attempts));
            fflush($handle);
            flock($handle, LOCK_UN);
            return $attempts;
        } finally {
            fclose($handle);
        }
    }

    private static function storageDir() {
        $dir = defined('RATE_LIMIT_DIR') ? RATE_LIMIT_DIR : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'termi-story-ratelimit';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            error_log("termi-story: rate limiter could not create {$dir}");
            return null;
        }
        return $dir;
    }

    private static function fileFor($key) {
        $dir = self::storageDir();
        if ($dir === null) {
            return null;
        }
        return $dir . DIRECTORY_SEPARATOR . hash('sha256', (string)$key) . '.json';
    }

    /** Occasionally delete files that have not been touched for a day. */
    private static function maybeCollectGarbage() {
        if (random_int(1, 100) !== 1) {
            return;
        }
        $dir = self::storageDir();
        if ($dir === null) {
            return;
        }
        $cutoff = time() - 86400;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
