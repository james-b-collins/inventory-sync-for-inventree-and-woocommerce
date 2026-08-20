# installs and activates WooCommerce and plugin on a wordpress install

set -eu

WP_PATH=/var/www/html

echo "waiting for WordPress to be installed"
until [ -f "$WP_PATH/wp-config.php" ]; do sleep 2; done

echo "wp-setup: waiting for database..."
i=0
# wait for the database to be reachable
until php -r 'exit(@mysqli_connect(getenv("WORDPRESS_DB_HOST"), getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME")) ? 0 : 1);' 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -ge 30 ]; then
    echo "database not reachable after 30 seconds, giving up"
    break
  fi
  sleep 2
done

#Install WordPress if not already installed
if wp core is-installed --path="$WP_PATH" >/dev/null 2>&1; then
  echo "WordPress already installed."
else
  echo "Installing WordPress"
  wp core install \
    --path="$WP_PATH" \
    --url="${WP_URL:-http://localhost:8090}" \
    --title="${WP_TITLE:-InvenTree Dev Store}" \
    --admin_user="${WP_ADMIN_USER:-admin}" \
    --admin_password="${WP_ADMIN_PASSWORD:-admin}" \
    --admin_email="${WP_ADMIN_EMAIL:-admin@example.com}" \
    --skip-email
fi

# create perma-links structure
wp rewrite structure '/%postname%/' --path="$WP_PATH" >/dev/null

# Install and activate WooCommerce if not already installed
if wp plugin is-installed woocommerce --path="$WP_PATH" >/dev/null 2>&1; then
  wp plugin activate woocommerce --path="$WP_PATH"
else
  echo "Installing WooCommerce"
  wp plugin install woocommerce --activate --path="$WP_PATH"
fi

# Install and activate the plugin if not already installed
if wp plugin activate inventory-sync-for-inventree-and-woocommerce --path="$WP_PATH"; then
  echo "wp-setup: inventory-sync-for-inventree-and-woocommerce activated."
else
  echo "wp-setup: WARNING: could not activate inventory-sync-for-inventree-and-woocommerce (missing autoloader?)."
fi

echo ""
echo "wp-setup: done."
echo "  Store : ${WP_URL:-http://localhost:8090}"
echo "  Admin : ${WP_URL:-http://localhost:8090}/wp-admin  (user: ${WP_ADMIN_USER:-admin} / pass: ${WP_ADMIN_PASSWORD:-admin})"
