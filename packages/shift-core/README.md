# SHIFT Core (PHP)

Shared PHP classes used by the SHIFT portal and the SHIFT PHP SDK.

## Local development override

To automatically use your local `shift-core` while developing (and fall back to Packagist for everyone else), set a global Composer path repository:

```bash
composer config --global repositories.shift-core path /absolute/path/to/shift-core
composer config --global repositories.shift-core.options.symlink true
```

This keeps published installs on the registry while your machine uses the local package without manual toggling.
