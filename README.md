<p align="center">
  <img src="blogsafe-dash-notes/admin/images/dash_notes_logo.png" alt="BlogSafe Dash Notes" width="320">
</p>

# BlogSafe Dash Notes — beta

Sticky notes for the WordPress admin. Put a reminder on the Dashboard or on any admin page, send notes to other users of the site, and keep track of who has read them and who has finished.

Free, with no paid version and nothing held back. Made by [BlogSafe](https://blogsafe.org/).

> **This is a beta.** It is on its way to the WordPress.org plugin directory and has not been released yet. Please try it on a test or staging site, not a site you depend on. Thank you for helping!

## What it does

- **Sticky notes anywhere in the admin:** on the Dashboard, on any admin page you pick, on one post or page while it is being edited, or on every admin page. Notes can also be pinned to a part of a page, such as a setting or a button.
- **Drag, resize, minimise, lock.** Each person arranges notes on their own screen.
- **Rich notes:** the WordPress editor with Add Media, video links (YouTube, Vimeo and more) that play in the note, seven colours or your own.
- **Checklists** that everyone who sees the note ticks together, with a progress bar.
- **Send notes** to selected users, to everyone with a role, or to everyone. On multisite, a super admin can send a note to every site.
- **Read and done status**, replies under each note, priorities, deadlines with *Overdue* warnings.
- **Snooze** a note until tomorrow, next week or a date. **Repeat** a note every day, week or month.
- **Templates** and **history** (the last 20 versions, with restore).
- **All Notes** page with views, search and sorting, plus **export and import** as a file.

Notes never appear on the front of your site, and the plugin sends nothing anywhere.

## Requirements

- WordPress 6.2 or newer
- PHP 7.4 or newer
- Works on single sites and multisite

## Install the beta

1. Download **`blogsafe-dash-notes.zip`** from the [Releases](../../releases) page.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip and click **Install Now**, then **Activate**.
3. Open the **Dashboard** and click **Add sticky note**.

> Don't use the green **Code → Download ZIP** button: that zip is the whole repository, with the plugin one folder down, and WordPress can't install it as it is.

To update to a newer beta, deactivate and delete the old version first, then install the new zip. Deleting the plugin removes its notes, so use **Dash Notes → All Notes → Export my notes** first if you want to keep them, and **Import notes** afterwards.

## What to try

Anything goes, but these are the parts we most want feedback on:

- Writing, moving, resizing, minimising and locking notes.
- Notes on different admin pages, and pinning a note to part of a page.
- Sending a note to another user or a role (a second test account helps), then replying and marking it done as that user.
- Checklists, snooze, repeating notes, templates and history.
- The block editor, other plugins' admin pages, and small screens.
- Multisite, if you run one.
- Anything confusing, anything that looks wrong, anything you expected to work and didn't.

## Reporting problems and ideas

Please open an [issue](../../issues) and tell us:

- what you did, what you expected, and what happened instead;
- your WordPress and PHP versions, and your browser;
- a screenshot if it helps.

**Found a security problem?** Please don't post it publicly. Use **Report a vulnerability** on the [Security](../../security) tab, so it can be fixed before anyone else hears about it.

## Uninstalling

Deactivating the plugin keeps everything. Deleting it from the Plugins screen removes its database tables and settings, and with them every note, on every site of a network.

## License

GPLv2 or later. See [LICENSE.txt](blogsafe-dash-notes/LICENSE.txt).
