# Releasing Illdy Companion

The plugin ships to the [WordPress.org plugin directory](https://wordpress.org/plugins/illdy-companion/)
over **SVN**, and to GitHub as a tagged release. Unlike the theme, a plugin update goes
live the moment it is committed — there is no review queue between you and every
installed site, so the checks below matter.

Current version: **2.3.1** (WordPress 7.2, PHP 7.4+).

## Before you build

Version lives in three places. `bin/build-release.sh` refuses to build if they
disagree, because WordPress.org serves whatever `Stable tag` points at and a mismatch
ships the wrong code:

| File | Field |
|---|---|
| `illdy-companion.php` | `Version:` header |
| `illdy-companion.php` | `define( 'ILLDY_COMPANION', ... )` |
| `readme.txt` | `Stable tag:` |

Then, in order:

1. **Update `readme.txt`.** Its `== Changelog ==` section is what users see on the
   plugin page and in the update notice. `Tested up to:` should name the current
   WordPress release.
2. **Regenerate the translation template** if any string changed:
   ```bash
   wp i18n make-pot . languages/illdy-companion.pot \
     --domain=illdy-companion --exclude=node_modules
   ```
   It must finish with no `Warning:` lines.
3. **Run Plugin Check** — the same tool the directory runs:
   ```bash
   wp plugin check illdy-companion
   ```
   2.3.0 ships with **one** finding: `hidden_files` for `.gitignore`, which the build
   script excludes, so the shipped zip is clean. Anything else is new and worth
   reading before you ship it.

   Two categories are deliberately annotated rather than "fixed", each with a
   `phpcs:ignore` and a reason:

   - `echo $args['before_widget']` / `after_widget` / `before_title` — these come from
     the theme's `register_sidebar()` call, not from user input. Escaping them would
     strip the markup the theme relies on.
   - `echo $output` in the widgets — `$output` is assembled from `esc_url()`,
     `esc_attr()` and `esc_html()` a few lines above. The sniff cannot follow that.

   If you touch those lines, re-check the reasoning still holds rather than keeping
   the annotation out of habit.

## Build

```bash
bin/build-release.sh
```

Produces:

| Path | Purpose |
|---|---|
| `dist/illdy-companion.zip` | installable zip; the GitHub release asset |
| `dist/svn/trunk/` | copy into SVN `trunk/` |
| `dist/svn/tags/2.3.0/` | copy into SVN `tags/2.3.0/` |

The script fails if a hidden file survives into the build.

## Publish to WordPress.org (SVN)

WordPress.org SVN is not GitHub: the tag is a **copy of the files**, not a pointer at a
commit, and `trunk` and the tag must both contain the release.

```bash
# First time only.
svn checkout https://plugins.svn.wordpress.org/illdy-companion svn-illdy-companion
cd svn-illdy-companion

# Replace trunk with the built payload.
rsync -a --delete --exclude '.svn' /path/to/illdy-companion/dist/svn/trunk/ trunk/

# Stage the tag.
mkdir -p tags/2.3.0
rsync -a --delete --exclude '.svn' /path/to/illdy-companion/dist/svn/tags/2.3.0/ tags/2.3.0/

# Tell SVN about added and removed files. It does not notice on its own.
svn add --force trunk tags --auto-props --parents --depth infinity -q
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm

svn status                       # read this before committing
svn commit -m "Release 2.3.0"
```

`svn status | grep '^!'` matters. SVN keeps deleted files unless told, so a file
removed from the plugin stays live until it is explicitly `svn rm`'d.

The update appears within a few minutes. **`Stable tag` in `trunk/readme.txt` is what
decides which version users receive** — if it still says the old number, nothing ships.

### Storefront assets

The banner, icon and screenshots live in `assets/` **beside** `trunk/`, never inside
it, and are not produced by the build:

```
assets/banner-772x250.png
assets/banner-1544x500.png      # retina
assets/icon-128x128.png
assets/icon-256x256.png
assets/screenshot-1.png         # numbered to match readme.txt's == Screenshots ==
```

## Publish to GitHub

No remote is configured in this working copy:

```bash
git remote add origin git@github.com:ColorlibHQ/illdy-companion.git
git push -u origin main
git tag -a v2.3.0 -m "Illdy Companion 2.3.0"
git push origin --tags
gh release create v2.3.0 dist/illdy-companion.zip --title "2.3.0"
```

## What 2.3.0 verified

- Plugin Check went from **92 findings to 1**, and that one is `.gitignore`, which the
  build excludes.
- The plugin boots with the theme on WordPress 7.0.3 / PHP 8.5.6 with **zero PHP
  notices, warnings or deprecations**.
- The demo importer was run end to end over HTTP from its new tab, including
  confirming a bad nonce is rejected, and the site restored byte-exactly afterwards.
- The built zip was installed in place of the working copy and re-verified, so the
  artifact is what was tested.

## Compatibility notes for the next release

- The plugin only loads its main file when Illdy (or a child of it) is the active
  theme. Widgets call `illdy_get_image_id_from_image_url()`, which the theme defines.
- The importer attaches to the theme's About Illdy screen through
  `illdy_welcome_tabs`, and falls back to registering its own Appearance page if the
  theme does not offer that screen. Both paths need testing if you touch it.
- That wiring is deferred to `after_setup_theme` because **plugins load before
  themes** — checking for the theme's classes at plugin-parse time always takes the
  fallback.
