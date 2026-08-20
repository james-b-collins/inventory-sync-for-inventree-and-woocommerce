<?php

// various constants for WordPress unit testing environment
define( 'ABSPATH', '/var/www/html/' );

define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', 'db' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'InvenTree Woo Tests' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
