=== CR Relocate DB ===
Contributors: craftroq
Tags: search replace, migration, database, urls, serialized
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Safely search and replace URLs and text across your WordPress database, with dry runs, serialized data support and a full history.

== Description ==

CR Relocate DB, by CraftRoq, changes text across your database: moving a site from staging to production, switching to HTTPS, changing a domain, or renaming something everywhere it appears.

It is built for doing that on real sites without breaking them.

= Preview first, always =

Every replacement starts as a dry run. It searches the tables you choose, changes nothing, and shows exactly what would change: rows and replacements per table and column, values it would leave alone and why, and before/after examples. Only a completed dry run can be applied, and applying it repeats exactly what was previewed.

= Serialized data and JSON handled properly =

Plugins and themes store settings as serialized PHP. A plain text replacement breaks those values whenever the length changes. Relocate rewrites serialized data token by token and recalculates every length, without unserializing it, so no plugin code runs and objects, private properties, references and numbers stay exactly as they were. URLs inside JSON (as page builders store them, with escaped slashes) are found and replaced too.

A value is left untouched, and reported, when changing it could corrupt it: serialized data that is already broken, a custom serialized format only its own class understands, or JSON that would become invalid.

= Made for large databases =

Tables are processed in small batches by primary key, a few seconds per request, so there are no timeouts or memory limits to hit however big the tables are. Progress is saved after every batch. If the page is closed or the connection drops, the job can be continued from the last completed batch.

= Safety measures =

* Each batch of a replacement is one database transaction: it is saved completely or not at all, so an interruption never leaves a batch half applied or applies it twice. This needs InnoDB tables, which WordPress uses by default; the confirmation step warns about any that are not.
* The original value of everything a replacement changes is saved to a downloadable file of SQL statements. Importing it puts those values back.
* The site address (siteurl and home) is changed last, so you are not logged out part-way through.
* Tables without a primary key or suitable unique key are skipped, because their rows cannot be updated one at a time safely.
* Post GUIDs are left alone unless you ask for them to be changed.
* Only administrators who can post unfiltered HTML can use it.

= Also included =

* Up to five search and replace pairs in one job, applied together in a single pass.
* Choose the tables to search, and leave out individual columns.
* Case-insensitive and whole-word matching, and matching the http:// and protocol-relative versions of a URL.
* A live progress view with the current table, rows scanned, changes found and time remaining.
* A searchable, sortable history of every job, a log, and automatic clean-up. Old jobs can also be deleted by hand.
* Import / Export: download the database as a .sql.gz file and import .sql or .sql.gz files, in resumable steps, with the site address changed on the way out or on the way in.
* Export and import the plugin's settings as a JSON file.
* WP-CLI commands: `wp crq search-replace` and `wp crq resume`.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/cr-relocate-db`, or install it from the Plugins screen.
2. Activate it.
3. Open **Relocate** in the admin menu.

== Screenshots ==

1. Dashboard: start a search and replace, or pick up a recent job.
2. Choose what to replace, up to five pairs at once, and which tables to look in.
3. Live progress: the current table, rows scanned, changes found and time remaining.
4. The dry run: what would change, with before and after examples. Nothing is written yet.
5. Confirm before anything is written, and keep a file of the original values.
6. Import / Export: download the database with the site address changed on the way out.
7. History: every dry run and replacement, searchable and sortable.

== Frequently Asked Questions ==

= Do I still need a backup? =

Yes. Take a full database backup before replacing anything. The file of original values covers what a replacement changed, but it is not a substitute for a backup.

= Can I undo a replacement? =

There is no undo button. Each replacement can save the original value of everything it changed to a downloadable `.sql.gz` file, and importing that file (for example with phpMyAdmin, or `gunzip -c file.sql.gz | mysql your_database`) puts those values back. It overwrites any edits made to those values since, so use it soon after the replacement.

= Does it change serialized data safely? =

Yes. Serialized values are rewritten with their lengths recalculated, and checked to still be readable afterwards. Values that could not be changed safely are left as they were and listed in the results.

= What happens if I close the page during a replacement? =

The batch in progress either completes or is rolled back (on InnoDB tables, which WordPress uses by default). Open the job from the Dashboard or History and continue it: it picks up from the last completed batch.

= Does it support Multisite? =

The free plugin works on single sites. Multisite support is planned for CR Relocate DB Pro. On a Multisite network the free plugin does not run, and says so on the Plugins screen.

= Which tables does it search? =

The tables you tick. By default those are the tables with your WordPress prefix. Other tables in the same database can be added, and each table's text columns can be switched off one by one. The plugin's own tables are never searched.

= How do I use it from WP-CLI? =

`wp crq search-replace https://staging.example.com https://example.com --dry-run` shows what would change. Without `--dry-run` the command asks for confirmation and applies the dry run it just made. See `wp help crq search-replace` for all options.

= Does it send any data anywhere? =

No. Everything happens in your own database. Nothing is sent to CraftRoq or anyone else.

== Changelog ==

= 0.1.0 =
* First release: search and replace with dry runs, serialized data and JSON support, database import and export, history, and WP-CLI commands.
