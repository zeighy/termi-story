<?php
// src/ScriptingEngine.php

class ScriptingEngine {
    // Longest pause a single WAIT can request, in milliseconds
    const MAX_WAIT_MS = 30000;

    // Presentation commands. Like WAIT, each becomes a marker line (%%NAME:arg%%) in the
    // output, which the browser acts on instead of printing. Arguments are checked here,
    // so a marker only ever carries a known word or a bounded number.
    const GLITCH_MS = ['default' => 400, 'min' => 100, 'max' => 3000];
    const SHAKE_MS = ['default' => 500, 'min' => 100, 'max' => 2000];
    // Must match the sounds in public/sound.js
    const BEEP_TONES = ['beep', 'high', 'low', 'alert', 'error', 'success', 'static', 'power', 'thud'];
    const TYPE_PACES = ['slow', 'normal', 'fast'];
    const TEXT_STYLES = ['normal', 'dim', 'bright', 'warn', 'alert'];

    private $variables = [];
    private $output = '';
    private $lines = [];
    private $linePointer = 0;

    public function execute($scriptContent) {
        if (isset($_SESSION['env_vars'])) {
            $this->variables = $_SESSION['env_vars'];
        } else {
            $_SESSION['env_vars'] = [];
        }

        $this->variables['USERNAME'] = $_SESSION['username'] ?? 'guest';
        $this->variables['SESS_RUN_INPUT'] = $_SESSION['run_input'] ?? "";

        $this->lines = preg_split("/\r\n|\n|\r/", $scriptContent);
        
        while ($this->linePointer < count($this->lines)) {
            $this->processLine($this->linePointer);
            $this->linePointer++;
        }

        unset($this->variables['USERNAME']);
        unset($this->variables['SESS_RUN_INPUT']);
        $_SESSION['env_vars'] = $this->variables;
        
        unset($_SESSION['run_input']);
        
        return rtrim($this->output, "\n");
    }

    private function processLine(&$lineNumber) {
        $line = trim($this->lines[$lineNumber]);
        if ($line === '' || $line[0] === '#') {
            return;
        }

        $parts = preg_split('/\s+/', $line, 2);
        $command = strtoupper($parts[0]);
        $args = $parts[1] ?? '';

        switch ($command) {
            case 'ECHO':
                $text = $this->resolveVariables($args);
                // A line that starts with %% would be read as a marker. Prefix a zero-width
                // space so text (e.g. a variable's value) can never pose as one.
                if (str_starts_with($text, '%%')) {
                    $text = "\u{200B}" . $text;
                }
                $this->output .= $text . "\n";
                break;
            case 'SET':
                $this->handleSet($args);
                break;
            case 'CALC':
                $this->handleCalc($args);
                break;
            case 'IF':
                $this->handleIf($args, $lineNumber);
                break;
            case 'ELSE':
                $this->skipToEndif($lineNumber);
                break;
            case 'WAIT':
                $ms = max(0, min(self::MAX_WAIT_MS, intval($args)));
                $this->output .= "%%WAIT:" . $ms . "%%\n";
                break;
            case 'FETCH':
                $this->handleFetch($args);
                break;
            case 'CLEAR':
                $this->marker('CLEAR');
                break;
            case 'GLITCH':
                $this->marker('GLITCH', $this->boundedMs($args, self::GLITCH_MS));
                break;
            case 'SHAKE':
                $this->marker('SHAKE', $this->boundedMs($args, self::SHAKE_MS));
                break;
            case 'BEEP':
                $this->marker('BEEP', $this->pickWord($args, self::BEEP_TONES, 'beep'));
                break;
            case 'TYPE':
                $this->marker('TYPE', $this->pickWord($args, self::TYPE_PACES, 'normal'));
                break;
            case 'STYLE':
                $this->marker('STYLE', $this->pickWord($args, self::TEXT_STYLES, 'normal'));
                break;
        }
    }

    /** Append a presentation marker line for the browser. */
    private function marker($name, $arg = null) {
        $this->output .= '%%' . $name . ($arg === null ? '' : ':' . $arg) . "%%\n";
    }

    /** A duration in ms from a command's argument (variables allowed), clamped to its range. */
    private function boundedMs($args, $range) {
        $value = trim($this->resolveVariables($args));
        if ($value === '' || !is_numeric($value)) {
            return $range['default'];
        }
        return max($range['min'], min($range['max'], (int)$value));
    }

    /** The argument as one of the allowed words (case-insensitive), or the default. */
    private function pickWord($args, $allowed, $default) {
        $word = strtolower(trim($this->resolveVariables($args)));
        return in_array($word, $allowed, true) ? $word : $default;
    }

    private function handleFetch($args) {
        $parts = explode('=', $args, 2);
        if (count($parts) === 2) {
            $varName = trim($parts[0]);
            $url = trim($parts[1]);

            $result = $this->fetchUrl($url);

            $finalValue = "";
            if ($result !== false) {
                $data = @json_decode($result, true);
                if (is_array($data) && isset($data['value'])) {
                    // Sanitize by removing all non-alphanumeric characters
                    $sanitizedValue = preg_replace('/[^a-zA-Z0-9]/', '', (string)$data['value']);
                    $finalValue = is_numeric($sanitizedValue) ? (float)$sanitizedValue : $sanitizedValue;
                }
            }

            $this->variables[$varName] = $finalValue;
        }
    }

    // --- FETCH safety ---
    // FETCH only talks to http(s) servers on public IP addresses. This stops scripts
    // (including ones arriving via story import) from reading local files through PHP
    // stream wrappers (file://, php://, phar://) or probing internal services and
    // cloud metadata endpoints. To allow LAN/localhost endpoints, define
    // FETCH_ALLOW_PRIVATE_HOSTS as true in config/config.local.php.

    const FETCH_TIMEOUT_SECONDS = 10;
    const FETCH_MAX_BYTES = 65536;

    /** Returns the response body, or false if the URL is disallowed or the request fails. */
    private function fetchUrl($url) {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            return false;
        }
        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return false;
        }

        $host = trim($parsed['host'], '[]');
        $port = isset($parsed['port']) ? (int)$parsed['port'] : null;
        $hostHeader = (strpos($host, ':') !== false ? "[{$host}]" : $host) . ($port !== null ? ":{$port}" : '');

        $httpOptions = [
            'timeout' => self::FETCH_TIMEOUT_SECONDS,
            'follow_location' => 0, // a redirect could point somewhere we never validated
            'user_agent' => 'termi-story-fetch',
        ];
        $sslOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];
        $requestUrl = $url;

        $allowPrivate = defined('FETCH_ALLOW_PRIVATE_HOSTS') && FETCH_ALLOW_PRIVATE_HOSTS;
        if (!$allowPrivate) {
            $ips = $this->resolveHost($host);
            if (empty($ips)) {
                return false;
            }
            foreach ($ips as $ip) {
                if (!$this->isPublicIp($ip)) {
                    return false;
                }
            }
            // Connect to the address we just checked (not a fresh DNS lookup), while
            // still sending the original Host header and validating TLS against the hostname.
            $pinned = strpos($ips[0], ':') !== false ? "[{$ips[0]}]" : $ips[0];
            $requestUrl = $scheme . '://' . $pinned . ($port !== null ? ":{$port}" : '')
                . ($parsed['path'] ?? '/')
                . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
            $httpOptions['header'] = "Host: {$hostHeader}\r\n";
            $sslOptions['peer_name'] = $host;
        }

        $context = stream_context_create(['http' => $httpOptions, 'ssl' => $sslOptions]);
        return @file_get_contents($requestUrl, false, $context, 0, self::FETCH_MAX_BYTES);
    }

    /** All IP addresses the host resolves to (or the literal IP itself). */
    private function resolveHost($host) {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = @gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaa as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }

    private function isPublicIp($ip) {
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is checked as the IPv4 address it carries
        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $ip = inet_ntop(substr($packed, 12));
        }
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            $flags |= FILTER_FLAG_GLOBAL_RANGE; // PHP 8.2+: also excludes CGNAT, documentation ranges, etc.
        }
        return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
    }

    private function handleSet($args) {
        $parts = explode('=', $args, 2);
        if (count($parts) === 2) {
            $varName = trim($parts[0]);
            $value = $this->getValue(trim($parts[1]));
            $this->variables[$varName] = is_numeric($value) ? (float)$value : $value;
        }
    }

    private function handleCalc($args) {
        $parts = explode('=', $args, 2);
        if (count($parts) < 2) return;

        $varName = trim($parts[0]);
        $expression = trim($parts[1]);

        // operand: a number (optionally negative/decimal), a $VARIABLE, a "quoted" value or a bare word.
        // Matching whole operands lets "-5 + 3" and "$A - -1" parse correctly.
        $operand = '(-?\d+(?:\.\d+)?|\$[A-Za-z0-9_]+|"[^"]*"|[A-Za-z_][A-Za-z0-9_]*)';
        if (!preg_match('/^' . $operand . '\s*([+\-*\/])\s*' . $operand . '$/', $expression, $matches)) {
            return;
        }

        $leftVal = (float) $this->getValue($matches[1]);
        $operator = $matches[2];
        $rightVal = (float) $this->getValue($matches[3]);
        
        $result = 0;
        switch($operator) {
            case '+': $result = $leftVal + $rightVal; break;
            case '-': $result = $leftVal - $rightVal; break;
            case '*': $result = $leftVal * $rightVal; break;
            case '/': $result = $rightVal != 0 ? $leftVal / $rightVal : 0; break;
        }

        $this->variables[$varName] = $result;
    }

    private function handleIf($condition, &$lineNumber) {
        $isMet = $this->evaluateCondition($condition);
        if (!$isMet) {
            $this->skipToElseOrEndif($lineNumber);
        }
    }

    private function evaluateCondition($condition) {
        $orParts = preg_split('/\s+OR\s+/i', $condition);
        foreach ($orParts as $orPart) {
            $andParts = preg_split('/\s+AND\s+/i', $orPart);
            $allAndsMet = true;
            foreach ($andParts as $andPart) {
                if (!$this->evaluateSingleAssertion(trim($andPart))) {
                    $allAndsMet = false;
                    break;
                }
            }
            if ($allAndsMet) {
                return true;
            }
        }
        return false;
    }

    private function evaluateSingleAssertion($assertion) {
        preg_match('/(.*?)\s*(>=|<=|==|!=|>|<)\s*(.*)/', $assertion, $matches);
        if (count($matches) !== 4) return false;

        $left = $this->getValue(trim($matches[1]));
        $op = trim($matches[2]);
        $right = $this->getValue(trim($matches[3]));

        if (is_numeric($left) && is_numeric($right)) {
            $left = (float)$left;
            $right = (float)$right;
        }

        switch ($op) {
            case '==': return $left == $right;
            case '!=': return $left != $right;
            case '>':  return $left > $right;
            case '<':  return $left < $right;
            case '>=': return $left >= $right;
            case '<=': return $left <= $right;
            default:   return false;
        }
    }

    private function skipToElseOrEndif(&$lineNumber) {
        $nestingLevel = 1;
        while ($lineNumber < count($this->lines) - 1) {
            $lineNumber++;
            $nextCommand = $this->commandOf($this->lines[$lineNumber]);
            if ($nextCommand === 'IF') $nestingLevel++;
            elseif ($nextCommand === 'ENDIF') {
                $nestingLevel--;
                if ($nestingLevel === 0) break;
            } elseif ($nextCommand === 'ELSE' && $nestingLevel === 1) break;
        }
    }

    private function skipToEndif(&$lineNumber) {
        $nestingLevel = 1;
        while ($lineNumber < count($this->lines) - 1) {
            $lineNumber++;
            $nextCommand = $this->commandOf($this->lines[$lineNumber]);
            if ($nextCommand === 'IF') $nestingLevel++;
            elseif ($nextCommand === 'ENDIF') {
                $nestingLevel--;
                if ($nestingLevel === 0) break;
            }
        }
    }

    /** The upper-cased command word of a script line, split the same way processLine does. */
    private function commandOf($line) {
        $parts = preg_split('/\s+/', trim($line), 2);
        return strtoupper($parts[0] ?? '');
    }

    private function getValue($token) {
        // If it's a variable, resolve it
        if (str_starts_with($token, '$')) {
            $varName = substr($token, 1);
            return $this->variables[$varName] ?? "";
        }
        // If it's a quoted string, return its content
        if (preg_match('/^"(.*)"$/', $token, $matches)) {
            return $matches[1];
        }
        // Otherwise, it's a number or unquoted literal
        return $token;
    }

    private function resolveVariables($string) {
        return preg_replace_callback('/\$([A-Z_a-z0-9]+)/', function($matches) {
            $varName = $matches[1];
            return $this->variables[$varName] ?? "";
        }, $string);
    }
}
