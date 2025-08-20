<?php
// src/ScriptingEngine.php

class ScriptingEngine {
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
        if (empty($line) || $line[0] === '#') {
            return;
        }

        $parts = preg_split('/\s+/', $line, 2);
        $command = strtoupper($parts[0]);
        $args = $parts[1] ?? '';

        switch ($command) {
            case 'ECHO':
                $this->output .= $this->resolveVariables($args) . "\n";
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
                $this->output .= "%%WAIT:" . intval($args) . "%%\n";
                break;
        }
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

        preg_match('/(.*?)\s*([\+\-\*\/])\s*(.*)/', $expression, $matches);
        if (count($matches) !== 4) return;

        $leftVal = (float) $this->getValue(trim($matches[1]));
        $operator = trim($matches[2]);
        $rightVal = (float) $this->getValue(trim($matches[3]));
        
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
            $nextLine = trim($this->lines[$lineNumber]);
            $nextCommand = strtoupper(strtok($nextLine, ' '));
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
            $nextLine = trim($this->lines[$lineNumber]);
            $nextCommand = strtoupper(strtok($nextLine, ' '));
            if ($nextCommand === 'IF') $nestingLevel++;
            elseif ($nextCommand === 'ENDIF') {
                $nestingLevel--;
                if ($nestingLevel === 0) break;
            }
        }
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
