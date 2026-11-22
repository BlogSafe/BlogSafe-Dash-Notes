=== BlogSafe Dash Notes ===
Contributors: blogsafe
Tags: sticky notes, dashboard, notes, admin notes, collaboration
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sticky notes on any admin page: checklists, replies, deadlines, snooze, templates, and notes sent to other users or roles.

== Description ==

BlogSafe Dash Notes puts real sticky notes in your WordPress admin. Click **Add sticky note** on the Dashboard, use **Notes > Add note to this page** in the admin bar, or press **Alt+Shift+N** on any admin page. Write, pick a colour, and the note stays where you put it.

Everything is free. There is no paid version and nothing is held back.

= Sticky notes =

* Drag a note by its title bar, or move it with the arrow keys once the title bar has focus (hold Shift for bigger steps).
* Resize it from the bottom-right corner. Minimise it to its title bar; a minimised note has a grab handle at the right end of its title bar to change its width.
* **Lock in place** so it can't be moved or resized by accident. Click the lock icon to unlock it.
* Seven preset colours plus any colour you like.
* Each person arranges notes on their own screen: position, size, minimised and locked are saved per user.
* Hide all notes with one click when you want the screen back. They stay hidden, on every page, until you show them again.

= Images, video and formatting =

* Notes are written in the familiar WordPress editor (Visual and Text tabs): bold, lists, links, quotes, colours and more.
* **Add Media** puts images, audio, video and galleries from your Media Library into a note (for users allowed to upload files).
* Paste a YouTube, Vimeo or other supported video link on its own line and the video plays right in the note, just like in a post. A note shows up to 5 players; further links stay ordinary links.
* Long lines wrap, so pasted error messages, file paths and tables stay inside the note.

= Checklists =

* Add checklist items in the note editor (Enter adds the next item).
* Everyone who can see the note ticks the same list, and the title bar shows progress such as "2/5". Hover an item to see who ticked it.

= Replies =

* Reply under any note you can see (Ctrl+Enter sends, Esc or Cancel closes the reply box). A new reply makes the note show as new again for everyone else, its author included.
* You can delete your own replies; the note's author can delete any reply on it.

= Done, snooze and deadlines =

* **Done:** tick a note done for yourself. Done notes fade and are struck through, and the author sees how many people have finished it.
* **Snooze** a note until tomorrow, for a week, or until a date. It leaves your screen and comes back at the start of that day.
* **Deadline:** shown on the note. "Due tomorrow" turns amber, and "Due today" or overdue turns red. Notes due soon come to the front and give a short nudge when you open the page.
* **Priority:** Normal, High or Urgent. High and Urgent notes carry a flag; Urgent notes get a red border.

= Notes on any admin page =

Each note has a **Where it shows** setting. Tick any mix of:

* **Dashboard**
* **This page**: the admin screen you are on, such as Plugins, Settings, Posts or another plugin's page.
* **Only this item**: when editing a post, page or product, the note appears on that one item. Notes work in the block editor too.
* **Every admin page**

You can also **pin a note to a part of the page**, such as a settings field or a box. Pick it with the mouse and the note sits right beside it from then on. Hovering the note outlines what it is pinned to, and clicking its pin icon unpins it.

= Send notes to other people =

* **Who sees it:** only you, selected users, everyone with a chosen role (such as all Editors), or everyone on the site.
* A **New** badge and an unread count on the Dash Notes menu make sure new notes are seen. A note counts as read once it has been shown on screen.
* The author sees how many recipients have read and finished each note.
* When the author changes a note's title, text, priority or deadline, it shows as new again for everyone else.
* Recipients can remove a note from their own screen without deleting it for anyone else, and put it back later.

= History, templates and repeating notes =

* **History:** every edit keeps the previous version (the last 20). Restore any of them from the note's menu; the version you replace is kept too, so a restore can be undone.
* **Templates:** save any note as a template, then pick it under "Start from template" when you add a note. Title, text, colour, priority, repeat and checklist are all filled in.
* **Repeating notes:** a note can repeat every day, week or month. Each time, its checklist and everyone's Done start over, it shows as new again, and its deadline moves on. Ideal for weekly maintenance checklists.

= All Notes page =

**Dash Notes > All Notes** lists the notes sent to you and the notes you wrote, including notes you removed from your screen. Views: Sent to me, Written by me, Urgent, Overdue, Done, Snoozed and Templates; administrators also get every note on the site. It has search, sorting by priority, deadline and date, read and done status, and links to every page each note shows on. It remembers the view you used last.

**Export and import:** download your notes (administrators: every note) as a file, and import them on another site. Text, colours, priorities, deadlines, who sees them, where they show, repeats and checklists come along; replies and history do not. Administrators' files identify users by login name, so they work on another site; other users' files identify recipients by user ID and are meant for the same site.

= Multisite =

Each site has its own notes. A super admin can also write a **network note** on the main site, "Everyone on every site", which appears on every site in the network. Only a super admin can change or delete it, on the main site.

= Who can do what =

* Everyone who can reach the admin sees the notes sent to them. They can move, resize, minimise, lock, snooze and finish them, tick checklist items, reply, and remove notes from their own screen.
* Writing, sending, editing and deleting notes, templates and import/export are open to Contributors and up by default. Change this under **Dash Notes > Settings**, from every user to Administrators only.
* Administrators can edit and delete every note.
* To keep one account from flooding everyone's screen, users other than administrators can have up to 300 notes, 50 of them shared with other users at a time.

= Privacy =

Notes, replies and history are stored in your own WordPress database, and the plugin does not send them anywhere. When a note contains a video link (YouTube, Vimeo and so on), WordPress asks that service for the player, the same way it does for posts, and the player loads from that service when the note is shown. The recipient search shows display names only, never email addresses or login names. Notes can never fetch a web address just because it is written in them: only the video and media services WordPress already knows are contacted. An administrator's export file contains notes and the login names of their authors and recipients, so keep it somewhere safe; other users' export files contain no login names.

== Installation ==

1. In your WordPress admin, go to **Plugins > Add New**, search for "BlogSafe Dash Notes", and click **Install Now**.
2. Activate the plugin.
3. Go to the **Dashboard** and click **Add sticky note** next to the page title, use **Notes > Add note to this page** in the admin bar, or press Alt+Shift+N on any admin page.

== Frequently Asked Questions ==

= Where do the notes appear? =

Wherever you put them: the Dashboard, any admin page, a single post or page being edited, or every admin page, optionally pinned to a part of the page. New notes start on the page you add them from. **Dash Notes > All Notes** lists all your notes and where each one shows.

= Someone sent me a note but I can't see it =

It may be on an admin page you haven't visited or one your role can't open, or you may have snoozed it. **Dash Notes > All Notes** lists every note sent to you, with links to where it shows; the Snoozed view lists snoozed notes. The unread count on the Dash Notes menu includes these notes.

= Does it send emails? =

No. Everything happens in the WordPress admin: the New badge, the unread count, deadline nudges and repeating notes all show when you next open the admin.

= When does a repeating note start over? =

The first time anyone opens the admin after its time has come (the start of the next day, week or month, in your site's time zone). No scheduled task is needed.

= Can I add images and videos? =

Yes. Use **Add Media** in the note editor for files in your Media Library, or paste a video link (YouTube, Vimeo and the other services WordPress embeds) on its own line.

= Do shortcodes work in notes? =

Only WordPress's own media shortcodes: audio, video, playlist, gallery, caption and embed. Other plugins' shortcodes are shown as plain text, so a note can never run another plugin's code on someone else's screen.

= Can a note contain scripts or other unsafe HTML? =

No. Every note is filtered with the same rules WordPress uses for posts by users who are not allowed to post unfiltered HTML, including notes written by administrators, because notes are shown to other users. Checklist items and replies are plain text. Imported notes go through the same filtering.

= Does it work on multisite? =

Yes, activated per site or network-wide. Each site has its own notes and settings, and notes can only be sent to users who belong to that site. Super admins can manage notes on every site and can write network notes that appear on every site. Removing a user from a site deletes the notes they wrote there, deleting a site deletes its notes, and deleting the plugin removes its data from every site. Notes do not appear in Network Admin.

= What happens to a user's notes when the user is deleted? =

Notes they wrote are deleted, together with their checklists, replies and history, and so are their replies on other notes. Checklist items they ticked stay ticked.

= What is removed when I delete the plugin? =

Deleting the plugin from the Plugins screen removes its database tables and settings on every site, and each user's remembered All Notes view.

== Screenshots ==

1. Sticky notes on the Dashboard.
2. Writing a note and sending it to other users.
3. The All Notes list.
