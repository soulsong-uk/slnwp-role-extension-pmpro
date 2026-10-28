=== SLNWP Role Extension for Paid Memberships Pro ===
Contributors:       jainanda
Tags:               paid memberships pro, pmpro, membership, roles, user roles
Requires at least:  6.5
Tested up to:       7.1
Requires PHP:       7.4
Stable tag:         2.1.10
License:            GPLv2 or later
License URI:        https://www.gnu.org/licenses/gpl-2.0.html

Sync WordPress roles with Paid Memberships Pro levels. Pick a role per level; members’ roles update on signup, level change, cancellation and expiry.

== Description ==

SLNWP Role Extension for Paid Memberships Pro syncs WordPress roles to Paid Memberships Pro (PMPro) levels, with a bulk re-sync for existing members.

Requires Paid Memberships Pro, available from paidmembershipspro.com (it is not in the WordPress.org plugin directory).

It replaces the official PMPro Roles add-on and fixes these issues with it:

* Roles were not being updated when a user changed membership level
* Roles were not being removed on cancellation or expiry
* The administrator role was being removed when a membership was cancelled

= Key Features =

* **WordPress Role dropdown** on each membership level's settings page
* **Shared role slugs** — multiple levels can map to one role (e.g. Gold Monthly, Gold Quarterly, and Gold Annual all → `gold`)
* **Protected roles** — administrator, editor, author, contributor, customer, and subscriber are never removed under any circumstances
* **Admin-safe** — users with admin roles get membership roles correctly assigned AND removed without losing their admin role
* **Conflict detection** — warns if the official PMPro Roles add-on is still active
* **One-click bulk sync** — a single "Run Sync Now" button under Memberships → Role Sync fixes all existing users immediately

= How It Works =

Each PMPro membership level has a "WordPress Role" dropdown in its settings. Choose the role you want assigned to members of that level. Multiple levels can share the same role — ideal for sites that offer the same membership tier at different payment frequencies (monthly, quarterly, annually).

Roles are automatically synced when:

* A user signs up for a membership
* A user upgrades or downgrades their level
* A membership is cancelled
* A membership expires

= Protected Roles =

The following roles are never removed under any circumstances:

* `administrator`
* `editor`
* `author`
* `contributor`
* `customer` (WooCommerce)
* `subscriber`

Note: protecting a role only stops this plugin from *removing* it — a non-member is still *assigned* the site's default role (usually `subscriber`) as normal. Protection just means that role, once held, can never be stripped again afterwards.

For the same reason, a protected role can't be chosen as a membership level's role: members would keep it after their membership ended. If a level already uses one, the plugin shows a warning naming that level.

You can add additional protected roles via filter:

`add_filter('pmpro_protected_roles', function($roles) {
    $roles[] = 'shop_manager';
    return $roles;
});`

= Requirements =

* WordPress 6.5+
* PHP 7.4+
* Paid Memberships Pro, from paidmembershipspro.com (not in the WordPress.org plugin directory)
* The Multiple Memberships Per User add-on is supported (a member's levels are each evaluated independently)
* The official PMPro Roles add-on must be **deactivated** to avoid conflicts

== Installation ==

1. Upload the `slnwp-role-extension-pmpro` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins screen in WordPress
3. Make sure the official PMPro Roles add-on is deactivated
4. Go to **Memberships → Membership Levels** and edit each level to choose its WordPress Role
5. Go to **Memberships → Role Sync** and click **Run Sync Now** to fix all existing users

== Frequently Asked Questions ==

= Do I need the official PMPro Roles add-on installed? =

No — this plugin is a complete replacement. The official add-on must be **deactivated** to avoid conflicts. This plugin will show a warning in the admin if it detects the official add-on is still active.

= Can multiple membership levels share the same role? =

Yes — this is a core feature. If you offer Gold Monthly (Level 1), Gold Quarterly (Level 2), and Gold Annual (Level 3), simply choose the `gold` role for all three levels. Members will be assigned the `gold` role regardless of which payment plan they chose.

= What happens when a membership is cancelled? =

The membership role is removed. If the user has no other active memberships, they are reset to the default WordPress role (usually `subscriber`). Protected roles such as `administrator` are never touched.

= What happens if a user has both an admin role and a membership? =

Both roles are handled correctly. The membership role is assigned and removed as normal, while the admin role is always preserved. This was a key bug in the official add-on that this plugin fixes.

= How do I fix existing users whose roles are out of sync? =

Go to **Memberships → Role Sync** and click **Run Sync Now**. This is safe to run at any time.

= How do I add a role to the protected list? =

Add a filter to your theme's `functions.php`:

`add_filter('pmpro_protected_roles', function($roles) {
    $roles[] = 'shop_manager';
    return $roles;
});`

= What WordPress roles are protected by default? =

* `administrator`
* `editor`
* `author`
* `contributor`
* `customer` (WooCommerce)
* `subscriber`

= Is subscriber protected, given it's also the fallback role for non-members? =

Yes. Protection only blocks *removal* — a member who loses their last active membership is still reset to the site's default role (usually `subscriber`) exactly as before. Protecting it just means that once assigned, it can never be stripped again afterwards, the same as every other protected role.

= Does this work with WooCommerce? =

Yes — the `customer` role is in the protected list by default so WooCommerce customers are never affected by membership role changes.

== Screenshots ==

1. Choose the WordPress Role for a membership level, in the level's own settings.
2. The About tab: how roles are kept in sync, and every level's configured role at a glance.
3. The Sync tab: bring all existing members into line in one click, with the protected roles that are never removed.
4. The Developers tab: the pmpro_protected_roles filter for adding your own protected roles.

== Changelog ==

= 2.1.10 =
* First release on WordPress.org

== Upgrade Notice ==

= 2.1.10 =
First WordPress.org release.
