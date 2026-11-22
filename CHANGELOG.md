# BlogSafe Dash Notes — changelog

Internal record of every change. The readme's `== Changelog ==` section is added only when the plugin is approved on WordPress.org: version 1.0.0, "Initial public release." Everything below happened before that.

## Unreleased (0.1.0 in the code, becomes 1.0.0 on approval)

### 2026-11-21
- Fresh build replacing the old Note Press: free-floating sticky notes on the Dashboard, the user's own position and size per note, colours, priority, deadline.
- Rich content: the WordPress editor with Add Media, video embeds, core media shortcodes only.
- Notes on any admin page, on one post, or on every page; pin to a part of a page; block editor support.
- Sending: selected users, roles, everyone, and network notes on multisite; read and done status; replies.
- Checklists with shared ticks, repeating notes, templates, history with restore, snooze, lock, Done.
- All Notes page (views, search, sorting, remembered view), JSON export/import, settings (who can write notes).
- Multisite: per-site tables, network notes, site deletion and network uninstall clean-up.
- Admin pages in the BlogSafe Forensics console theme; footer link to blogsafe.org.
- UX fixes: clickable lock and pin, reply box with Send/Cancel, discard prompt, Undo toasts, explained stuck drags, Esc closes menus, restyled checklists, centred tick.
- Saves retry once after a dropped connection; one-time tokens stop duplicate notes and replies.
- Plugin Check clean-up: translator comments, Tested up to 7.1, database-rule comments.
- Security review (`SECURITY-REVIEW.md`), all four findings fixed: note content can't cover the admin screen; embed, note and sharing limits; no login names through search, export or import; network notes for super admins only.
- Help links (page header, admin bar, Plugins screen) to https://blogsafe.org/dash-notes-help/; the guide is written in Site Core and goes live after approval.
- Readme changelog section removed until approval.
- GitHub README for beta testers; beta zip built for a GitHub pre-release.
- Taint scan (wp-taint-scan 0.2.0) brought from 9 findings to 0: every field the REST answers send is rebuilt inline; note text filtered on the way out too; import reads only the real upload file; allowlist copies for saved values; typed `save_state()` columns.
