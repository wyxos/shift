<?php

// Define paths for the hooks directory and hook files
$hooksDir = __DIR__.'/.git/hooks';
$prePushHookFile = $hooksDir.'/pre-push';
$postMergeHookFile = $hooksDir.'/post-merge';

// Check if the hooks directory exists
if (! is_dir($hooksDir)) {
    echo "Error: Git hooks directory not found. Are you inside a Git repository?\n";
    exit(1);
}

/**
 * Install the pre-push hook that runs Artisan tests.
 */
$prePushHookContent = <<<'EOT'
#!/bin/sh

# Define the PHP executable
PHP_EXEC="php"

# Run Laravel tests
echo "Running Laravel tests before push..."
$PHP_EXEC artisan test

# Check if the tests pass
if [ $? -ne 0 ]; then
    echo "Tests failed. Aborting push."
    exit 1
fi

echo "Tests passed. Proceeding with push."
exit 0
EOT;

if (file_put_contents($prePushHookFile, $prePushHookContent) === false) {
    echo "Error: Failed to write the pre-push hook.\n";
    exit(1);
}
chmod($prePushHookFile, 0755);
echo "Pre-push hook installed successfully!\n";
