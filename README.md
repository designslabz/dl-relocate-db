<p align="center">
  <img src="assets/images/logo.svg" alt="" width="88">
</p>

<h1 align="center">CR Relocate DB</h1>

<p align="center">
  <strong>Safely search and replace URLs and text across your whole WordPress database.</strong><br>
  Preview every change first. Nothing is written until you say so.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/version-0.1.0-c0266d?style=flat-square" alt="Version 0.1.0">
  <img src="https://img.shields.io/badge/WordPress-6.5%2B-c0266d?style=flat-square&logo=wordpress&logoColor=white" alt="WordPress 6.5 or newer">
  <img src="https://img.shields.io/badge/tested%20up%20to-7.1-2ea44f?style=flat-square" alt="Tested up to WordPress 7.1">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-c0266d?style=flat-square&logo=php&logoColor=white" alt="PHP 8.1 or newer">
  <a href="https://github.com/designs-labz/dl-relocate-db/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/designs-labz/dl-relocate-db/ci.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <img src="https://img.shields.io/badge/license-GPLv3-351322?style=flat-square" alt="License GPLv3">
</p>

<p align="center">
  <img src=".github/screenshots/search-replace.jpg" alt="The Search &amp; Replace screen: two search and replace pairs, and the choice of which tables to look in" width="900">
</p>

---

## 🤔 What does it do?

Moving a WordPress site almost always means changing text inside the database. For example, every link that points to your staging site has to point to your live site instead:

```text
https://staging.example.com   →   https://example.com
```

That address can appear thousands of times: in posts, menus, widgets, page builder layouts and plugin settings. **CR Relocate DB finds every one of them and changes them safely**, including the ones hidden inside data that a normal find-and-replace would break.

**Good for:**

- 🚚 Moving a site from staging to live, or live to staging
- 🌐 Changing a domain name
- 🔒 Switching from `http://` to `https://`
- 📁 Updating server paths, such as `/home/old/public_html`
- ✏️ Renaming a product, company or phrase everywhere it appears

---

## ✨ Features

- 👀 **Dry run first, always.** See exactly what would change, table by table, with before and after examples. Nothing is written until you confirm.
- 🧩 **Safe with serialized data and JSON.** Plugin settings and page builders store data in formats that break if their length changes. CR Relocate DB rewrites them correctly every time.
- ➕ **Up to 5 search and replace pairs at once.** Change your domain and your server path in a single run.
- 📦 **Import / Export.** Download the database as a `.sql.gz` file and import `.sql` or `.sql.gz` files, changing the site address on the way out or on the way in. Large databases are handled in small steps and can be resumed.
- ⚙️ **Settings file.** Copy the plugin's settings to another site as a small JSON file.
- 🎯 **Choose tables and columns.** Search all WordPress tables, only some, or leave individual columns out.
- 📊 **Live progress.** A progress bar with the current table, rows scanned, changes found and time remaining.
- 💾 **Keeps a copy of the original values.** Before anything changes, the old values are saved to a file you can download.
- 🐘 **Built for big databases.** Works in small batches, so it never times out or runs out of memory. If it is interrupted, you can continue from where it stopped.
- 🗂️ **Full history.** Every dry run and replacement is recorded, searchable and sortable, with a log.
- ⌨️ **WP-CLI support** for developers and hosting teams.
- 📱 **Works on any screen**, from a phone to a wide monitor.
- ♿ **Accessible.** Keyboard friendly, screen reader friendly, and it never relies on colour alone.

---

## 🖼️ Screenshots

| Dashboard | Choose what to replace and where |
|:---:|:---:|
| <img src=".github/screenshots/dashboard.jpg" alt="Dashboard with the quick search form and recent jobs"> | <img src=".github/screenshots/search-replace.jpg" alt="Two search and replace pairs, and the choice of which tables to look in"> |
| **Watch the progress** | **Review the dry run** |
| <img src=".github/screenshots/progress.jpg" alt="Progress bar with the current table, rows scanned, changes found and time remaining"> | <img src=".github/screenshots/results.jpg" alt="Dry run results: rows that would change, replacements, and before and after examples"> |
| **Confirm before anything changes** | **Move a site with Import / Export** |
| <img src=".github/screenshots/confirm.jpg" alt="Confirmation dialog listing both search and replace pairs"> | <img src=".github/screenshots/import-export.jpg" alt="Database export with the site address changed on the way out"> |
| **Look back at every job** | |
| <img src=".github/screenshots/history.jpg" alt="History of dry runs and replacements"> | |

---

## 🚀 Getting started

### What you need

| Requirement | Version |
|---|---|
| WordPress | 6.5 or newer, single site (Multisite is planned for Pro) |
| PHP | 8.1 or newer |
| Database | MySQL 5.7+ or MariaDB 10.4+ |

### Install it

1. Download the plugin: on this page, click **Code → Download ZIP**.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose the ZIP and click **Install Now**.
3. Click **Activate**.
4. You will find **Relocate DB** in the admin menu on the left.

> 💡 **Tip:** GitHub names the folder inside the ZIP `cr-relocate-db-main`. The plugin works either way, but for tidy future updates you can unzip it, rename the folder to `cr-relocate-db`, and zip it again before uploading.

---

## 🧭 How to use it

Search & Replace walks you through three steps: **What to replace → Where → Preview & apply**.

### 1️⃣ What to replace

Open **Relocate DB → Search & Replace** (or type into the box on the Dashboard).

- **Find:** the text or address you want to change, for example `https://staging.example.com`.
- **Replace with:** what it should become, for example `https://example.com`.
- Need to change more than one thing? Click **➕ Add another** (up to 5 pairs).

Click **Continue**.

### 2️⃣ Where to look

**All WordPress tables** is selected for you, which is right for most sites. Choose **Let me choose** to pick tables yourself or leave single columns out. Matching options (ignore case, whole words, http/https versions of a URL, post GUIDs) are under **Advanced options**.

### 3️⃣ Preview, then apply

Click **Preview changes**. The plugin reads your database and shows:

- how many rows would change and how many replacements that is;
- examples of the text before and after;
- anything it would leave alone to keep your data safe, and why (under **Results per table**).

**Nothing in your database changes during the preview.**

Happy with it? Click **Replace in database…**, tick the box to confirm you have a backup, and click **Replace now**. When it finishes, you can download the original values.

### 📦 Move a site with Import / Export

1. On the **old** site, open **Relocate DB → Import / Export**. Under **Change text while exporting**, put the old address in **Find** and the new address in **Replace with**, then click **Export database** and download the file.
2. On the **new** site (same table prefix), open **Import / Export → Import database**, choose the file, tick the backup box and click **Import database**. (You can change the address here instead, under **Change text while importing**, if you didn't when exporting.)
3. If the users or options tables were replaced, log in again with the old site's details.

> 💡 **Tip:** export the new site first, so you can go back. Import runs `.sql` and `.sql.gz` files from this plugin, phpMyAdmin or mysqldump. Statements that switch database (`USE`) or lock tables are skipped, and the plugin's own tables are never touched.

### ✅ Examples

| I want to… | Search for | Replace with | Options |
|---|---|---|---|
| Move staging to live | `https://staging.example.com` | `https://example.com` | Tick **Include other versions of the URL** |
| Switch to HTTPS | `http://example.com` | `https://example.com` | |
| Change the server path | `/home/staging/public_html` | `/home/live/public_html` | |
| Rename a brand | `Acme Ltd` | `Acme Group` | **Match whole words only** |

> 💡 **Tip:** use the full address, including `https://`. Searching for just `example.com` would also match `myexample.com`.

---

## 🛡️ How it keeps your site safe

- 👀 **Preview before every change.** A replacement can only be started from a finished dry run, and does exactly what the preview showed.
- 🧩 **No broken data.** Serialized settings and JSON are rewritten correctly. If a value could not be changed safely, it is left as it was and listed in the results.
- 🧱 **All or nothing per batch.** Rows are changed in small batches, and each batch is either saved completely or not at all.
- 💾 **Original values saved.** Before anything is written, the old values go into a downloadable file.
- 🏠 **Stays logged in.** Your site address is changed last, so you are not logged out part way through.
- 🔐 **Administrators only.** Only users who can manage the site and post unfiltered HTML can use it.

> ⚠️ **Always take a full backup of your database first.** The file of original values helps, but it is not a replacement for a proper backup.

---

## ❓ Questions

<details>
<summary><strong>Can I undo a replacement?</strong></summary>

There is no undo button. But each replacement can save the original value of everything it changed to a `.sql.gz` file. Importing that file puts the old values back, for example with phpMyAdmin, or:

```bash
gunzip -c relocate-job-12-original-values.sql.gz | mysql your_database
```

It overwrites any edits made to those values since, so use it soon after the replacement.
</details>

<details>
<summary><strong>What if I close the page while it is running?</strong></summary>

Nothing breaks. The batch in progress either finishes or is rolled back. Open the job from the **Dashboard** or **History** and click **Continue**. It carries on from the last completed batch.
</details>

<details>
<summary><strong>Why were some values "left unchanged"?</strong></summary>

Sometimes changing a value would damage it: for example, plugin data that was already broken, or a custom format only that plugin understands. CR Relocate DB leaves those alone and tells you which table they are in, so you can check them yourself.
</details>

<details>
<summary><strong>Does it work on large sites?</strong></summary>

Yes. It works through each table in small batches, a few seconds at a time, so it does not hit time or memory limits. If your pages are very large (for example page builder content), lower **Rows per batch** in **Settings**.
</details>

<details>
<summary><strong>Does it support Multisite?</strong></summary>

The free plugin works on single sites. Multisite support is planned for **CR Relocate DB Pro**. On a Multisite network the free plugin does not run, and says so on the Plugins screen.
</details>

<details>
<summary><strong>Does it send my data anywhere?</strong></summary>

No. Everything happens inside your own database. Nothing is sent to CraftRoq or anyone else.
</details>

---

## ⌨️ WP-CLI

Prefer the command line? The same features are available through WP-CLI:

```bash
# Preview only: nothing is changed
wp crq search-replace https://staging.example.com https://example.com --dry-run

# Preview, then apply after you confirm
wp crq search-replace https://staging.example.com https://example.com

# Two pairs at once, only in two tables, without the confirmation question
wp crq search-replace https://staging.example.com https://example.com /home/staging /home/live \
  --tables=wp_posts,wp_postmeta --yes

# Continue a job that was interrupted
wp crq resume 42
```

Run `wp help crq search-replace` to see every option.

---

## 🗺️ What's next

⭐ **CR Relocate DB Pro** is planned, with:

- 🌐 **Multisite support**: choose which sites of a network to update
- 💾 Automatic backups and one-click rollback
- 🗂️ Saved profiles and scheduled jobs
- 🔄 Pushing and pulling databases directly between sites
- ➕ More than 5 search and replace pairs per job

Ideas or problems? [Open an issue](https://github.com/designs-labz/dl-relocate-db/issues).

---

## 🧑‍💻 For developers

<details>
<summary><strong>How the code is organised</strong></summary>

| Part | Where |
|---|---|
| Replacing within one value: plain text, serialized PHP, JSON | `src/Replace/` (no WordPress dependency) |
| Table discovery and allowlisting | `src/Database/Schema.php` |
| Jobs: batching, transactions, resume, original values file, clean-up | `src/Jobs/` |
| Database export and import, with text changed on the way out or in | `src/Transfer/` |
| The folder for exports, uploaded imports and original values files | `src/Storage.php` (`uploads/crq-relocate/`) |
| REST API used by the admin screens | `src/Rest/` (`crq-relocate/v1`) |
| Admin screens | `src/Admin/`, `templates/admin/`, `assets/` |
| WP-CLI | `src/Cli/Command.php` |

The admin screens and WP-CLI create jobs through `JobStarter` and run them with `JobRunner::step()`, so both behave the same way. Exports and imports work the same way through `TransferStarter` and `TransferRunner::step()`.

Two conventions keep the automated checks able to read the code:

- Queries go through a local `$wpdb = $this->wpdb;`, not `$this->wpdb->…`. The WordPress coding standards only recognise a variable named `$wpdb`, and with it both `composer lint` and Plugin Check can verify that every query is prepared.
- Do not name a method of your own `prepare()`. Plugin Check mistakes it for `$wpdb->prepare()` and stops checking the file.
</details>

<details>
<summary><strong>Hooks</strong></summary>

| Hook | Type | What it does |
|---|---|---|
| `crq_relocate_step_seconds` | filter | How long one step may work before saving and returning. Default `4`. |
| `crq_relocate_max_pairs` | filter | How many search and replace pairs one job may have. Default `5`. |
| `crq_relocate_flush_object_cache` | filter | Whether to flush the whole object cache after a replacement. Default `true`; return `false` when the cache is shared with other sites. |
| `crq_relocate_supports_multisite` | filter | Whether the plugin may run on a Multisite network. Default `false`; meant for the Pro add-on. |
| `crq_relocate_manage` | capability | Required for everything. Maps to `manage_options` plus `unfiltered_html`; change it with a `map_meta_cap` filter. |
</details>

<details>
<summary><strong>Development setup and tests</strong></summary>

Classes load through the PSR-4 autoloader in `cr-relocate-db.php`, so the plugin runs straight from a Git checkout. Composer is only needed for development tools.

```bash
composer install
composer lint              # PHPCS (WordPress-Extra)
composer analyse           # PHPStan level 6
composer test              # Unit tests, no WordPress or database needed
```

Integration tests run against a real WordPress and MySQL. They drop and recreate tables in the database you point them at, so use an empty one:

```bash
WP_TESTS_DB_HOST=127.0.0.1:3306 WP_TESTS_DB_NAME=relocate_tests composer test:integration
```

`WP_TESTS_DB_USER` and `WP_TESTS_DB_PASSWORD` default to `root` and an empty password.

Regenerate the translation template after changing strings:

```bash
wp i18n make-pot . languages/cr-relocate-db.pot --exclude=vendor,tests
```

CI runs the linters, the unit tests on PHP 8.1–8.4, and the integration tests on WordPress 6.5 and the latest release against MySQL 8.0, MySQL 8.4 and MariaDB 10.11.
</details>

<details>
<summary><strong>Releasing to WordPress.org</strong></summary>

WordPress.org runs [Plugin Check](https://wordpress.org/plugins/plugin-check/) on every upload and refuses the ZIP if it reports an error. Run it first, on a site with both plugins installed:

```bash
wp plugin check cr-relocate-db --require=./wp-content/plugins/plugin-check/cli.php
```

Also run it once from **Tools → Plugin Check** in the admin: that screen shows problems inside the checker itself, which the command hides.

Build the ZIP from a commit. Development files are left out by the `export-ignore` rules in `.gitattributes`:

```bash
git archive --format=zip --prefix=cr-relocate-db/ -o dist/cr-relocate-db.zip HEAD
```

Keep the version the same in three places: `Version` in `cr-relocate-db.php`, `Plugin::VERSION`, and `Stable tag` in `readme.txt`.

The directory's icon and screenshots are in `.wordpress-org/`. They go into the `assets` folder of the plugin's SVN repository, not into the ZIP; `screenshot-1.jpg` to `screenshot-7.jpg` match the numbered captions under `== Screenshots ==` in `readme.txt`. A banner (`banner-772x250.png`, `banner-1544x500.png`) goes there too.

The screenshots in `.github/screenshots/` are the same images under descriptive names, for this page.
</details>

---

## 📄 License

GPL-3.0-or-later. Free to use, change and share.

<p align="center">
  Made with ❤️ by <a href="https://craftroq.com/"><strong>CraftRoq</strong></a>
</p>
