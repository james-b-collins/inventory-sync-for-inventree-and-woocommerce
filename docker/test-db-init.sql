-- Creates the isolated database the integration tests use
CREATE DATABASE IF NOT EXISTS wordpress_test;
GRANT ALL PRIVILEGES ON wordpress_test.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
