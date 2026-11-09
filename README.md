# Software Directory

## Overview

Software Directory is a ClassicPress plugin for building directories of plugins and themes. It provides software entries, category-like kinds, frontend templates, GitHub release downloads, and WP-CLI commands for importing repository READMEs.

## Features

- Public custom post type `xsxsd`, shown as **Software** in the administration area, with the permalink base `software`.
- Support for titles, full content, authors, excerpts, custom fields, and featured images.
- Hierarchical taxonomy `xsxsd-kind`, shown as **Kinds**, with the rewrite base `kind`.
- A **Software Details** meta box for the software slug and GitHub repository URL.
- Paginated kind archives using the main ClassicPress query, with responsive cards containing images, linked titles, excerpts, visible fields, and Download links.
- Responsive single pages with full content, featured images, and a sidebar containing downloads, visible fields, and linked kinds.
- Local download URLs redirecting to ZIP assets from the latest GitHub release.
- Software entries and kind terms available to ClassicPress navigation menus; kinds explicitly enable `show_in_nav_menus`.
- WP-CLI commands to insert entries and refresh their content from GitHub.

## Requirements

- ClassicPress **2.0 or later**.
- PHP **8.2 or later**, including the PHP runtime used by WP-CLI.
- Outbound HTTPS access to `api.github.com` for release lookups and README imports.
- WP-CLI for the optional CLI commands.
- PHP DOM/libxml support (`DOMDocument`) for CLI imports and updates, used to remove images from converted HTML.
- Working permalink rewriting for the documented frontend paths and download endpoint.

No bundled Markdown library or Composer installation is required. CLI imports reuse an available CommonMark or Parsedown class, otherwise they render Markdown through GitHub's API.

## Installation

1. Copy the plugin into `wp-content/plugins/software-directory/`.
2. Activate **Software Directory** in the ClassicPress Plugins screen.
3. Open **Settings → Permalinks** and save the settings to regenerate rewrite rules. Repeat this after installing changes to the plugin's URL rules; the plugin does not flush rewrite rules automatically.
4. Create kinds and software entries under **Software**.

## Configuration

Create terms such as `plugin` and `theme` in **Software → Kinds**. These terms are not created automatically.

For each software entry, set a title, content, optional excerpt and featured image, and assign one or more kinds. Publish the entry to make its download endpoint eligible for use. Featured image controls also depend on the active theme's thumbnail support.

The **Software Details** meta box stores these post meta keys:

| Key | Label | Purpose | Displayed by default |
| --- | --- | --- | --- |
| `slug` | slug | Optional software identifier, sanitized with `sanitize_key` | No |
| `git-repo` | GitHub repo | Repository URL, sanitized with `sanitize_url` | Yes |

Use a repository URL such as `https://github.com/xxsimoxx/githeme`. The `slug` custom field is separate from the post's permalink slug and is not used to build download URLs.

The meta box save handler requires a valid nonce and the `update_plugins` capability. Frontend templates omit fields whose `display` setting is disabled, whose values are missing or non-scalar, or whose URL values fail URL escaping. URL fields use their descriptive labels as link text when available.

Use **Appearance → Menus** to add software entries and kind archives to an existing theme menu. The plugin does not create a menu or register a menu location.

## Frontend templates

The plugin provides:

| File | Purpose |
| --- | --- |
| `templates/taxonomy-xsxsd-kind.php` | Kind archive, term heading and description, main loop, cards, and pagination |
| `templates/single-xsxsd.php` | Single software page, full content, and optional metadata sidebar |
| `templates/custom-fields.php` | Shared custom field rendering |
| `templates/download.php` | Shared repository validation and Download link markup |

Both page templates call the theme's header and footer. Archive thumbnails request `medium_large`; single thumbnails request `large`, using native thumbnail rendering and its responsive attributes.

### Theme overrides

For kind archives, the plugin searches the active child/parent theme using `locate_template()` in this order:

1. `taxonomy-xsxsd-kind-{term-slug}.php`.
2. `taxonomy-xsxsd-kind.php`.
3. `taxonomy.php`.
4. The plugin's `templates/taxonomy-xsxsd-kind.php` fallback.

For single software pages, the order is:

1. A valid custom template explicitly assigned to the post, if present in the theme.
2. `single-xsxsd-{post-slug}.php`.
3. `single-xsxsd.php`.
4. The plugin's `templates/single-xsxsd.php` fallback.

For both slug-specific lookups, a URL-decoded slug is tried before the stored slug when they differ. Generic theme templates such as `single.php`, `singular.php`, `archive.php`, and `index.php` do not replace these plugin fallbacks. The shared partials are included directly from the plugin and do not have a separate theme lookup.

### Single featured image filter

`software_directory_show_single_thumbnail` receives the default boolean `true` and the current post ID. The plugin renders its single-page featured image only if the filter returns boolean `true` and the post has a thumbnail.

For a theme that already displays the image, add this to the theme or child theme:

```php
add_filter('software_directory_show_single_thumbnail', function ($show, $post_id) {
    return false;
}, 10, 2);
```

This affects only the image generated by the plugin's single template; archive images and images rendered by the theme are unaffected.

## GitHub downloads

Published software entries have a local endpoint:

```text
/software/{post-slug}/latest.zip
/software/githeme/latest.zip
```

The endpoint reads `git-repo` and accepts HTTPS repository URLs on `github.com` with an owner and repository name. A trailing slash and a `.git` suffix are accepted. Additional path segments, credentials, query strings, and fragments are not accepted.

On a cache miss, it calls `GET https://api.github.com/repos/{owner}/{repo}/releases/latest` without authentication. It uses uploaded release assets ending in `.zip`, not GitHub's automatically generated source archives. Drafts and prereleases are rejected.

ZIP selection follows these rules:

1. Prefer one asset whose name matches `{repository-name}.zip`, case-insensitively.
2. Otherwise, accept a ZIP only when exactly one exists.
3. Reject ambiguous selections rather than choosing an arbitrary asset.

The asset's `browser_download_url` must be an HTTPS GitHub release download URL under the expected owner and repository. The endpoint issues an HTTP **302** redirect; ClassicPress does not download, proxy, or serve the ZIP itself.

Results, including errors, are stored in a repository-specific transient for **120 minutes**. Missing software, invalid repositories, and absent releases or ZIPs return errors; ambiguous ZIP choices return HTTP 409, while upstream failures generally return HTTP 502.

Download buttons appear when `git-repo` passes local URL validation. Rendering a button does not call GitHub or guarantee that a downloadable release exists. On password-protected single pages, the metadata sidebar is withheld until the password requirement is satisfied.

## WP-CLI

Commands are registered only when WP-CLI is available. Run them against a ClassicPress installation where Software Directory is active.

### Insert

```bash
wp software-directory insert <kind-slug> <repository-url>
wp software-directory insert theme https://github.com/xxsimoxx/githeme
```

The command:

- Requires an existing `xsxsd-kind` term with the supplied slug; it does not create terms.
- Validates that the GitHub repository is public and retrieves the root `README.md` from its default branch.
- Converts Markdown to HTML, sanitizes it, removes images, and retains image alternative text where present. Normal links are preserved.
- Creates a published `xsxsd` post with a title and permalink slug derived from the repository name.
- Assigns the requested kind and saves the canonical repository URL in `git-repo`.
- Rejects repositories already associated with a software entry, including entries in other statuses or the trash. Comparison normalizes case, HTTP/HTTPS, a trailing slash, and a `.git` suffix.
- Reports the created post ID and permalink through `WP_CLI::success()`.

CLI repository URLs may use HTTP or HTTPS; stored canonical URLs use HTTPS and lowercase owner/repository names. Imports do not populate the separate `slug` custom field, excerpt, or featured image.

### Update

```bash
wp software-directory update <post-id>
wp software-directory update 693
```

The ID must identify an existing `xsxsd` post. The command reads its `git-repo` value and uses the same repository validation, README retrieval, Markdown conversion, sanitization, and image removal as `insert`.

**Update replaces the post's entire content with HTML generated from the remote README.** Local content edits are overwritten. It submits only `post_content` for updating and preserves the title, slug, excerpt, status, taxonomy assignments, custom fields, and featured image.

If the generated content is identical to the stored content, no update is performed. A missing README, HTTP failure, or conversion failure prevents the update. Both changed and unchanged outcomes report the post ID through `WP_CLI::success()`; errors use WP-CLI error messages.

## Customization

Frontend classes use the `software-directory-` prefix. Common selectors include `.software-directory-archive`, `.software-directory-single`, `.software-directory-card`, `.software-directory-fields`, and `.software-directory-download`.

| Stylesheet | Handle | Loaded on |
| --- | --- | --- |
| `assets/css/archive.css` | `software-directory-archive` | `xsxsd-kind` archives only |
| `assets/css/single.css` | `software-directory-single` | Single `xsxsd` pages only |
| `assets/css/download.css` | `software-directory-download` | Both contexts, as a shared dependency |

Archive cards use one, two, or three columns according to viewport width. Single pages use a wider content column and a sidebar on desktop, and one column on smaller screens. Styles inherit theme fonts and colors and provide visible keyboard focus indicators.

Add scoped overrides in a child theme stylesheet. Asset loading depends on the queried archive or post type, so it also applies when the theme supplies a template override.

`xxsimoxx\SoftwareDirectory\SoftwareDirectory::get_custom_fields()` exposes field definitions through the filter **`xsxsd-datails`**. This spelling is the implemented hook name. Definitions include `name`, `display`, `render`, `sanitize`, and `escape`. For example, to display the existing slug field:

```php
add_filter('xsxsd-datails', function ($fields) {
    $fields['slug']['display'] = true;
    return $fields;
});
```

The field editor, saving logic, and frontend partial consume these definitions; release downloads and CLI commands specifically use `git-repo`.

## Limitations

- GitHub operations support public repositories only. There is no built-in token configuration or support for other Git hosts.
- GitHub API rate limits and network availability can prevent downloads or README imports. API redirects are not followed; use the repository's current URL.
- Download links require a suitable uploaded ZIP asset on the latest release. There is no fallback to source archives, tags, or prereleases.
- CLI imports require the exact root filename `README.md` on the default branch and a base64 file response from the Contents API. Unsupported responses are rejected.
- Markdown output depends on the available renderer: CommonMark, then Parsedown, then GitHub's Markdown API. No parser is bundled.
- README content is sanitized and imported without images; images are not downloaded or assigned as featured images.
- There is no automatic README synchronization. Run `update` explicitly to refresh an entry.
- The plugin supplies kind archives, but does not register a general software post-type archive.
