<?php
/**
 * Bump digtiali-contact-form version in version.json + plugin header.
 *
 * Usage:
 *   php scripts/bump-version.php 1.0.1 "Fixed bug"
 *
 * @package Digtiali Contact Form
 */

declare(strict_types=1);

$root         = dirname(__DIR__);
$manifestPath = $root . '/version.json';
$pluginPath   = $root . '/digtiali-contact-form.php';

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "Run from CLI only.\n");
	exit(1);
}

$args = array_slice($argv, 1);
if ($args === array() || ! preg_match('/^\d+\.\d+\.\d+/', $args[0])) {
	fwrite(STDERR, "Usage: php scripts/bump-version.php 1.0.1 \"Change summary\"\n");
	exit(1);
}

$newVersion = $args[0];
$changes    = array_slice($args, 1);
if ($changes === array()) {
	$changes = array('Maintenance release');
}

if (! is_readable($manifestPath)) {
	fwrite(STDERR, "Missing version.json\n");
	exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (! is_array($manifest)) {
	fwrite(STDERR, "Invalid version.json\n");
	exit(1);
}

$oldVersion           = (string) ($manifest['version'] ?? '0.0.0');
$manifest['version']  = $newVersion;
$manifest['released'] = gmdate('Y-m-d');

$entry = array(
	'version' => $newVersion,
	'date'    => $manifest['released'],
	'changes' => array_values($changes),
);

$changelog = isset($manifest['changelog']) && is_array($manifest['changelog']) ? $manifest['changelog'] : array();
array_unshift($changelog, $entry);
$manifest['changelog'] = $changelog;

$encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (! is_string($encoded)) {
	fwrite(STDERR, "Failed to encode version.json\n");
	exit(1);
}

file_put_contents($manifestPath, $encoded . "\n");

$plugin = (string) file_get_contents($pluginPath);
$plugin = preg_replace('/(\* Version:\s+)\d+\.\d+\.\d+/', '${1}' . $newVersion, $plugin, 1, $countHeader);
if ($countHeader === 0) {
	fwrite(STDERR, "Could not update plugin header Version line.\n");
	exit(1);
}

$plugin = preg_replace(
	"/(\\\$fallback = ')[^']+(';\\s*\\/\\/ digtiali-contact-form version fallback)/",
	'${1}' . $newVersion . '${2}',
	$plugin,
	1,
	$countFallback
);

file_put_contents($pluginPath, $plugin);

echo "Bumped digtiali-contact-form {$oldVersion} -> {$newVersion}\n";
echo "Updated: version.json, digtiali-contact-form.php\n";
echo "Next: git add version.json digtiali-contact-form.php && git commit && git push\n";
