# EventOS WordPress Plugin

EventOS is a modular event-management platform delivered as a standard WordPress
plugin. This sprint contains the **Core Configuration** module only: settings,
branding, regional options, security policy, team roles and invitations, plus the
admin dashboard. Feature modules (Events, Ticketing, CRM, Finance, Scanner, …)
register themselves later through the module registry.

## Architecture

```
eventos.php                     Plugin bootstrap, constants, activation hooks
includes/class-autoloader.php   Namespace → file autoloader
includes/class-plugin.php       Singleton bootstrap + module registry wiring
includes/interface-module.php   Contract every module implements
includes/class-module-registry.php
includes/class-installer.php    dbDelta schema + upgrade routine
includes/class-capabilities.php EventOS roles/capabilities on WordPress users
includes/class-settings.php     Schema-driven options store
includes/class-branding.php     Logo/colour accessors reused by all modules
includes/class-security.php     Password policy, session timeout, login notices
includes/class-invitations.php  Invitation workflow (custom table)
includes/class-activity-log.php Audit trail (custom table)
includes/class-system-status.php Health + storage reporting
includes/class-cron.php         Daily maintenance via WP-Cron
includes/class-woocommerce.php  Optional WooCommerce alignment
includes/modules/               Module implementations (core module today)
includes/admin/                 Admin menu + asset loading
includes/rest/                  REST controllers under /wp-json/eventos/v1
assets/admin/                   Compiled React admin UI (build output)
```

Authentication, users, roles, media and uploads all use WordPress core. Custom
tables exist only where core has no equivalent: `{prefix}eventos_invitations` and
`{prefix}eventos_activity_log`. Configuration is stored in options
(`eventos_settings_general|branding|regional|security`).

### Extending with a new module

```php
add_action( 'eventos_register_modules', function ( \EventOS\Module_Registry $registry ) {
    $registry->add( new \Acme\Events_Module() );
} );
```

Additional admin screens hook `eventos_admin_pages`; extra settings groups hook
`eventos_settings_schema`; extra roles hook `eventos_roles`.

## REST API

All routes live under `/wp-json/eventos/v1` and use the WordPress cookie +
`X-WP-Nonce` authentication used by `wp-admin` (no second login).

| Route | Method | Capability |
| --- | --- | --- |
| `/dashboard` | GET | `eventos_view_dashboard` |
| `/settings` | GET | `eventos_view_dashboard` |
| `/settings/{group}` | GET / POST | view / `eventos_manage_settings` |
| `/team/roles` | GET | `eventos_view_dashboard` |
| `/team/members` | GET | `eventos_manage_team` |
| `/team/members/{id}` | POST | `eventos_manage_team` |
| `/invitations` | GET / POST | `eventos_manage_team` |
| `/invitations/{id}` | DELETE | `eventos_manage_team` |

## Building the admin UI

The React admin app lives in `src/wp-admin/` at the repository root and compiles
into `wordpress-plugin/eventos/assets/admin/`:

```bash
bun install
bun run build:wp-admin
```

## Packaging the plugin

```bash
cd wordpress-plugin && zip -r eventos.zip eventos -x '*/node_modules/*' '*/vendor/*'
```

Upload `eventos.zip` through **Plugins → Add New → Upload Plugin**.

## Quality tooling

```bash
cd wordpress-plugin/eventos
composer install
composer lint          # PHPCS with WordPress Coding Standards
composer lint:fix      # PHPCBF
composer test          # PHPUnit (requires the WordPress test library)
```

PHPUnit needs the WordPress core test suite. Install it once and point
`WP_TESTS_DIR` at the checkout:

```bash
export WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit
composer test
```

No test cases ship with this sprint — the harness is configured so real
integration tests can be added and executed in a local WordPress environment
and in CI.
