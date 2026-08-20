# Inventory Sync for InvenTree and WooCommerce

A WordPress plugin that mirrors [InvenTree](https://inventree.org/) stock into
WooCommerce and records web orders back into InvenTree as sales orders. InvenTree
is the single source of truth for inventory with WooCommerce acting solely as the storefront.

## What it does

- **Adopt-only and inventory-only.** The scheduled sync never creates products and won't change the attributes of existing parts. It matches parts to products you have already built in WooCommerce and alters only the stock quantity.
- **Imports on request.** The **Import products** tab lists your InvenTree parts and what an import would do with each one, so you can select which if any to import. This is the only method to import products directly from InvenTree.
- **Syncs stock in.** On a schedule it reads salable parts from InvenTree, computes their availability and updates WooCommerce stock.
- **Records orders out.** When an order enters a committing status it reserves the stock, reduces the displayed quantity, and creates an InvenTree sales order. Cancellations and partial refunds release the reservation and adjust the sales order, and editing a committed order's items resyncs both.
- **Optionally handles stockable Product Add-Ons.** Off by default. If you use the separate [WooCommerce Product Add-Ons](https://woocommerce.com/products/product-add-ons/) plugin, you can switch on an integration that maps add-on options to InvenTree parts, so a selected add-on reserves its own stock on the same order line.
- **Handles variations.** A variation is treated as a unique product and a variable parent is never adopted, since it holds no stock of its own. If a variation was inheriting its parent's stock, the plugin takes it over so the InvenTree figure is the one the store sells against.
- **Passive retirement.** A part that goes inactive or is deleted from InvenTree it will drop out of the sync action. the product is left for WooCommerce to manage, and resumes if the part returns.

## Requirements

- WordPress 6.0 or newer
- WooCommerce (active)
- PHP 8.0 or newer
- An InvenTree instance reachable from WordPress, with an API token
- A system cron running WordPress cron
- A single-site WordPress install. **Multisite is not supported at this time**

## Installation

1. Copy the plugin folder into `wp-content/plugins/inventory-sync-for-inventree-and-woocommerce`.
2. In WordPress admin, activate **Inventory Sync for InvenTree and WooCommerce**. WooCommerce must be active first.
3. Go to **Settings > Inventory Sync**, set the URL and token, then press **Activate**. The plugin ships switched off, so activating the WordPress plugin alone writes nothing, the Activate button is what starts the background jobs.

## Configuration

Go to **Settings > Inventory Sync**. There are four tabs:

- **Settings** - InvenTree URL and API token, the two feature toggles below, the
  order statuses that commit stock (default `processing, completed`), the sync
  interval, how long stock may stay reserved before it is reported as stuck, and
  how many log records to keep. Every field has a description on the page. The
  **Activate** / **Deactivate** button sits at the bottom beside Save settings.
- **Import products** - bring InvenTree parts into WooCommerce.
- **Add-on mapping** - only shown when the Product Add-Ons integration is switched on.
- **Log** - the plugin's recent actions.

### Roles visibility

|Tab| Administrator | Shop manager |
|---|---|---|
| Settings, Activate / Deactivate | yes | no |
| Log | yes | no |
| Import products | yes | yes |
| Add-on mapping | yes | yes |

### WP-CLI

```bash
wp inventree ping             # check connectivity and auth
wp inventree fields           # inspect a sample part's fields
wp inventree sync             # sync salable parts into WooCommerce
wp inventree sync --dry-run   # read-only: report what the sync would compute
wp inventree schedule         # schedule the recurring background sync
wp inventree unschedule       # cancel it
```

### Monitoring

A health endpoint computed on read is available here:

```
GET /wp-json/inventory-sync/v1/status
```

It returns 200 when healthy and 503 when not, reporting the last successful sync
time and the oldest outstanding stock reservation. A deactivated plugin reports
`enabled: false` and counts as healthy, since nothing is expected to be running.

## Held stock

Once an order enters a committing status the plugin holds its stock back until InvenTree confirms the sales order. There are two conditions in which this stock will be held indefinitely in order to avoid unintended behaviour.

**Cancel an order before you trash it.** Trashing an order without cancellation signals nothing about the intent of the action (they may be trashing to clean up their page or to get rid of an invalid order) An order trashed while it still holds stock keeps holding it, and after the stuck reservation threshold the plugin reports it as a problem. Set the order to **Cancelled** first, which releases the reservation and cancels the InvenTree sales order, then trash it.

**Deactivating does not release held stock.** Deactivating unschedules the background jobs but destroys nothing, so anything reserved against an open order stays reserved and the plugin is no longer running to release it. Reactivating picks up where it left off. If you are deactivating untick **Create sales orders** and save first, as that releases every outstanding reservation before you switch off.

In both cases holding the stock is the safe direction so the store under sells rather than over sells.

## Development environment

An included `docker-compose` stack runs WordPress + WooCommerce with the plugin bind-mounted
in.

```bash
cp .env.example .env      # adjust ports / InvenTree URL if needed
docker compose up -d
docker compose logs -f wpcli   # watch provisioning, Ctrl-C when it prints "done"
```

- Store: <http://localhost:8090>
- Admin: <http://localhost:8090/wp-admin> (default login (admin / admin) from `.env`)

The plugin is served from the repo, so edits on disk are live with no rebuild.
InvenTree (running in its own devcontainer) is reached from the WordPress container
at `http://host.docker.internal:8000`.

## Running tests

Install the dev dependencies via composer:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 install
```

There are two suites:

**Unit** (no WordPress requirement. Auto run on every git commit as a github action):

```bash
docker compose exec -T wordpress sh -c 'cd /var/www/html/wp-content/plugins/inventory-sync-for-inventree-and-woocommerce && php vendor/bin/phpunit'
```

**Integration** (runs tests against a live Wordpress + Woocommerce instance on an isolated test database):

```bash
docker compose exec -T wordpress sh -c 'cd /var/www/html/wp-content/plugins/inventory-sync-for-inventree-and-woocommerce && php vendor/bin/phpunit -c phpunit-integration.xml.dist'
```
Each of these tests will roll back their own actions after being run so will not affect the database.

### Updating and rolling back

The plugin can be updated simply by updating the plugin folder. All configuration and data is stored seperately and will survive updating, activating and deactivating the plugin. Deleting the plugin however will run the uninstall.php and removes all the plugin data from the database.

As with all WordPress plugins it is recommended to backup the full database before any update and ideally test it on a staging instance before production.

## License

GPL-2.0-or-later.
