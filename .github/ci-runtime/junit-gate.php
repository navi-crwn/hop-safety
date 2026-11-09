<?php

// CI gate for the isolated runtime test run.
//
// Usage: php junit-gate.php <junit.xml> <allowlist.txt> <expected-total>
//
// Fails unless, in the JUnit report:
//   1. the total number of test cases equals <expected-total>;
//   2. no test case has a <failure> or <error> child element;
//   3. the set of skipped test case names exactly equals the non-blank,
//      non-comment lines of <allowlist.txt> (trimmed).
//
// The allowlist is the documented, curl-aware set: with ext-curl loaded the
// 25 curl-gated tests must run, not skip, and only the 3 "no-curl fallback"
// tests are allowed to skip. Any deviation (a new skip, a renamed test, a
// test hidden behind a new skip or --fail-on-failure blanket rule) breaks
// the equality check, so skips can never be used to hide a failure.

if ($argc !== 4 || ! is_file($argv[1])) {
    fwrite(STDERR, "usage: php junit-gate.php <junit.xml> <allowlist.txt> <expected-total>".PHP_EOL);
    exit(2);
}

[, $junitFile, $allowFile, $expectedTotal] = $argv;

$xml = new DOMDocument();
if (! $xml->load($junitFile)) {
    fwrite(STDERR, "invalid junit xml: {$junitFile}".PHP_EOL);
    exit(2);
}

$nodes = $xml->getElementsByTagName('testcase');
$skipped = [];
$failed = [];
$errored = [];

foreach ($nodes as $case) {
    foreach ($case->getElementsByTagName('skipped') as $signal) {
        $skipped[] = $case->getAttribute('name');
    }
    foreach ($case->getElementsByTagName('failure') as $signal) {
        $failed[] = $case->getAttribute('name').': '.$case->textContent;
    }
    foreach ($case->getElementsByTagName('error') as $signal) {
        $errored[] = $case->getAttribute('name').': '.$case->textContent;
    }
}

$allowed = array_values(array_map(
    'trim',
    array_filter(
        file($allowFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
    ),
));
$expected = array_values(array_map(
    'trim',
    array_filter(
        $skipped,
        static fn (string $name): bool => $name !== '',
    ),
));
sort($expected);
sort($allowed);

$failures = [];

if ((int) $nodes->length !== (int) $expectedTotal) {
    $failures[] = "test case count {$nodes->length} != expected {$expectedTotal}";
}
if ($failed) {
    $failures[] = implode('; ', $failed);
}
if ($errored) {
    $failures[] = 'errors: '.implode('; ', $errored);
}
if ($expected !== $allowed) {
    $extra = array_diff($expected, $allowed);
    $missing = array_diff($allowed, $expected);
    $failures[] = 'skipped set != allowlist ('.count($expected).' skipped)'.
        ($extra ? '; unexpected: '.implode(' | ', $extra) : '').
        ($missing ? '; missing from report: '.implode(' | ', $missing) : '');
}

if ($failures) {
    foreach ($failures as $line) {
        fwrite(STDERR, 'GATE FAIL: '.$line.PHP_EOL);
    }
    exit(1);
}

echo 'GATE PASS: '.$nodes->length." cases, ".count($allowed).' skipped (all documented), 0 failures, 0 errors'.PHP_EOL;
