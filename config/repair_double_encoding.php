<?php
// repair_double_encoding.php - fix txt/app content that was HTML-encoded more than once.
//
// Older versions of the admin editor re-encoded text on every save, so "don't" could
// become "don&amp;#039;t" (and worse after more saves). This collapses those repeated
// encodings back to a single level, which is the format the app expects.
//
// Usage (from the project root):
//   php config/repair_double_encoding.php           # dry run: show what would change
//   php config/repair_double_encoding.php --apply   # write the fixes
//
// Review the dry run first: text that deliberately contains a literal entity such as
// "&lt;" (stored as "&amp;lt;") is indistinguishable from a double encoding and would
// also be collapsed.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../src/Database.php';

$apply = in_array('--apply', $argv, true);

function collapse_double_encoding($content) {
    // "&amp;" followed by an entity that htmlspecialchars produces => one level too many
    $pattern = '/&amp;((?:amp|lt|gt|quot|apos|#0*39|#x0*27);)/i';
    do {
        $previous = $content;
        $content = preg_replace($pattern, '&$1', $content);
    } while ($content !== $previous);
    return $content;
}

function preview($text) {
    $text = str_replace(["\r", "\n"], ['', ' '], $text);
    return mb_strlen($text) > 70 ? mb_substr($text, 0, 70) . '…' : $text;
}

$db = new Database();
$db->query("SELECT id, name, type, content FROM filesystem WHERE type IN ('txt', 'app') AND content LIKE '%&amp;%'");
$rows = $db->resultSet();

$changes = [];
foreach ($rows as $row) {
    $fixed = collapse_double_encoding((string)$row['content']);
    if ($fixed === $row['content']) {
        continue;
    }
    $changes[] = ['id' => (int)$row['id'], 'content' => $fixed];

    // Show the first difference, decoded the way players will see it
    $before = htmlspecialchars_decode((string)$row['content'], ENT_QUOTES);
    $after = htmlspecialchars_decode($fixed, ENT_QUOTES);
    $offset = strspn($before ^ $after, "\0");
    $start = max(0, $offset - 20);
    echo "#{$row['id']} {$row['name']}\n";
    echo "    before: " . preview(substr($before, $start)) . "\n";
    echo "    after:  " . preview(substr($after, $start)) . "\n";
}

if (empty($changes)) {
    echo "No double-encoded content found.\n";
    exit(0);
}

if (!$apply) {
    echo "\n" . count($changes) . " item(s) would be repaired. Re-run with --apply to write the changes.\n";
    exit(0);
}

$db->beginTransaction();
try {
    foreach ($changes as $change) {
        $db->query("UPDATE filesystem SET content = :content WHERE id = :id");
        $db->bind(':content', $change['content']);
        $db->bind(':id', $change['id']);
        $db->execute();
    }
    $db->commit();
    echo "\nRepaired " . count($changes) . " item(s).\n";
} catch (Throwable $e) {
    $db->rollBack();
    echo "Repair failed, nothing was changed: " . $e->getMessage() . "\n";
    exit(1);
}
