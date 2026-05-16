<?php

require __DIR__ . '/../src/Compressor.php';

function fail($message) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertSameValue($expected, $actual, $message) {
    if ($expected !== $actual) {
        fail($message . "\nExpected: {$expected}\nActual:   {$actual}");
    }
}

function assertTrueValue($condition, $message) {
    if (!$condition) {
        fail($message);
    }
}

function invokeMinifyContent($content, $extension, $config = []) {
    $compressor = new Compressor($config);
    $method = new ReflectionMethod('Compressor', 'minifyContent');
    return $method->invoke($compressor, $content, $extension);
}

function writeFixture($path, $content) {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fail("Could not create directory: {$dir}");
    }

    if (file_put_contents($path, $content) === false) {
        fail("Could not write fixture: {$path}");
    }
}

function removeDirectory($path) {
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($path);
}

function withTempProject($callback) {
    $tempRoot = sys_get_temp_dir() . '/compressor-test-' . bin2hex(random_bytes(4));

    if (!mkdir($tempRoot, 0755, true) && !is_dir($tempRoot)) {
        fail("Could not create temp directory: {$tempRoot}");
    }

    try {
        $callback($tempRoot);
    } finally {
        removeDirectory($tempRoot);
    }
}

$phpInput = <<<'PHP'
<?php
if (!defined('ABSPATH')) {
    exit;
}

$site_name = get_bloginfo('name');
$site_url = esc_url(home_url());
PHP;

$phpExpected = "<?php if(!defined('ABSPATH')){exit;}\$site_name=get_bloginfo('name');\$site_url=esc_url(home_url());";
assertSameValue($phpExpected, invokeMinifyContent($phpInput, 'php'), 'PHP minifier should remove blank lines and compact statements.');

$phpSqlInput = <<<'PHP'
<?php
$query = "SELECT * FROM {$table}
    WHERE email = %s
    ORDER BY created_at DESC
    LIMIT 1";

return "CREATE TABLE {$table} (
    id INT(11) NOT NULL AUTO_INCREMENT,
    email VARCHAR(100) NOT NULL,
    PRIMARY KEY (id)
) {$charset};";
PHP;

$phpSqlExpected = '<?php $query="SELECT * FROM {$table} WHERE email = %s ORDER BY created_at DESC LIMIT 1";return"CREATE TABLE {$table} (id INT(11) NOT NULL AUTO_INCREMENT,email VARCHAR(100) NOT NULL,PRIMARY KEY (id)) {$charset};";';
assertSameValue($phpSqlExpected, invokeMinifyContent($phpSqlInput, 'php'), 'Structured multiline SQL strings should be compacted inside PHP files.');

$phpTextInput = <<<'PHP'
<?php
$message = "Hello user,
Please verify your email.

Thanks";
PHP;

$phpTextExpected = <<<'PHP'
<?php $message="Hello user,
Please verify your email.

Thanks";
PHP;
assertSameValue($phpTextExpected, invokeMinifyContent($phpTextInput, 'php'), 'Plain multiline text strings should keep their original content.');

$jsInput = <<<'JS'
(function() {
const row = `
  <td>${value}</td>
  <td>${label}</td>
`;
})();
JS;

$jsExpected = '(function(){const row=`<td>${value}</td><td>${label}</td>`;})();';
assertSameValue($jsExpected, invokeMinifyContent($jsInput, 'js'), 'JS minifier should compact HTML-like template literals.');

withTempProject(function($tempRoot) {
    $source = $tempRoot . '/source';
    $output = $tempRoot . '/output';

    writeFixture($source . '/.gitignore', "backend/js/users/all-users.js\nassets/*.js\n!assets/keep.js\nignored/\n");
    writeFixture($source . '/backend/js/users/all-users.js', "const users = [];\n");
    writeFixture($source . '/assets/drop.js', "const drop = true;\n");
    writeFixture($source . '/assets/keep.js', "const keep = true;\n");
    writeFixture($source . '/ignored/file.php', "<?php echo 'skip';\n");
    writeFixture($source . '/app/file.php', "<?php echo 'keep';\n");

    $compressor = new Compressor([
        'createIndex' => false,
        'exclude' => []
    ]);
    $compressor->compress($source, $output, false);

    assertTrueValue(!file_exists($output . '/backend/js/users/all-users.js'), 'Relative .gitignore file rules should be respected.');
    assertTrueValue(!file_exists($output . '/assets/drop.js'), 'Wildcard .gitignore rules should skip matching files.');
    assertTrueValue(file_exists($output . '/assets/keep.js'), 'Negated .gitignore rules should restore explicitly allowed files.');
    assertTrueValue(!file_exists($output . '/ignored/file.php'), 'Ignored directories should not be copied.');
    assertTrueValue(file_exists($output . '/app/file.php'), 'Files outside ignore rules should still be processed.');
});

withTempProject(function($tempRoot) {
    $source = $tempRoot . '/source';
    $output = $tempRoot . '/output';

    writeFixture($source . '/vendor/package.php', "<?php echo 'package';\n");

    $compressor = new Compressor([
        'createIndex' => false
    ]);
    $compressor->compress($source, $output, false);

    assertTrueValue(file_exists($output . '/vendor/package.php'), 'Default excludes should not skip vendor unless configured or gitignored.');
});

echo "All regression checks passed.\n";
