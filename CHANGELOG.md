# Changelog

All notable changes to the Synaplan Nextcloud Integration will be documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed

- **Watched folders stay readable beside the chat.** In a window half a
  screen wide, the list on the Synaplan page cut a folder's name to its first
  letter and broke its sentence after every word. Pause, Stop watching and
  Open folder now move below the text when the row is narrow.

## 2.1.0 – 2026-11-24

2.0.0 is already on the App Store. This release feeds watches from this
Nextcloud, lets an administrator cap what Synaplan may read, and brings the
folder-read and file-change fixes since then. Updated servers get the new
tables and background jobs.

### Added

- **Watches are fed from here.** A file that arrives in a watched folder, or
  a new version of a watched file, goes to its watch once it has been quiet
  for a minute. Once an hour each watch asks Synaplan which files it still
  needs. The app's own copies don't start a watch again. Starting a folder
  watch asks whether the files already there go too. "Only what I watch"
  lets Synaplan read the folders you watch.
- **Only people who may edit a folder or a file can watch it.** The Watch
  action is hidden for anything the person may only read, and the server
  refuses it.
- **Allow watching folders** and **Allow watching shared folders** on the
  admin screen. While one is off, nobody can start a watch it covers, and the
  watches already running get no files and say why. With watching off, the
  app also stops telling Synaplan it can watch.
- **Pause, Resume and Stop watching** for the owner, in the Synaplan sidebar
  of a watched folder and in the new **Watched folders** list on the app
  page. Stop asks once. Resuming a task watch asks whether the files that
  arrived while it was paused go too.
- **A warning on the admin screen and in the watch dialog** while background
  jobs run in AJAX mode, because watches then only move on while someone has
  a page open. In webcron mode the admin screen says watches get new files
  only as often as the outside service calls.
- **Synaplan may read at most** on the admin screen: watched folders,
  folders people allow (the default) or all their files. Personal settings
  offer only the choices up to it. Lowering it narrows everyone who chose
  more, and Synaplan learns it within the hour; raising it widens nobody.
  An update from a version where someone already chose "All my files"
  starts the ceiling at all their files, so nobody is narrowed silently.

### Changed

- **New → From Synaplan…** opens "Add from Synaplan": generated files or all
  files, a search, a check box per file and **Add 2 files**, into the folder
  named under the list. The filter and the search run in Synaplan, so a
  generated file on a later page shows too, and the added files appear in
  the folder without a reload. With nothing to show, it says where files
  come from and offers **Open Synaplan**.

### Fixed

- **A failed Synaplan action names the reason.** Watching while cloud files
  are turned off, a missing right, a full watch limit or a full storage says
  so, instead of "Something went wrong." A folder or file the person cannot
  read says they have no access.
- **Large watched folders are checked to the end.** The hourly check of a
  watched folder with more than 500 files could stop at its time limit and
  start over each run, never reaching the later files. It now goes on where
  it stopped.
- **The app's own files stay out of watches for good.** A file this app
  wrote, such as a translation saved into a watched folder, counted as new
  a week later, so a paused or late watch could take it again. Its mark now
  lasts while the file keeps the app's version.
- **A file moved into a paused folder is sent when the watch resumes.**
  Files that arrived while a watch was paused were only found by their
  modification time, so a file moved in with an older one was never sent.
- **"Only new files" leaves out every file from before the resume.** Files
  queued before a watch was resumed with "Only new files" could still be
  sent.
- **Resuming a watch asks first, or stays paused.** When the number of files
  that arrived while it was paused could not be read, the watch resumed and
  took them all without asking. It now stays paused and says why.
- **Watch… shows only where a watch gets files.** With one shared Synaplan
  key, or on a server not registered with Synaplan, a watch could be started
  but no file ever reached it. Watch… is now hidden there.
- **A watched folder deleted by mistake and restored at once keeps its
  watch.** If Synaplan had already heard of the deletion, it never heard of
  the restore, and the watch stayed stopped on a folder that was back. The
  restore now always follows the deletion.
- **A watch stopped elsewhere loses its eyes.** A folder whose watch was
  stopped in Synaplan or in another tab kept its eyes until the page was
  reloaded. They now go with the next answer from Synaplan.
- **Watch… shows only where Synaplan takes watches.** It showed whenever the
  admin screen allowed watching, also while Synaplan's cloud files were off,
  and starting a watch then failed.
- **A watched folder's bird has an eye you can see.** Its eyes were drawn in
  the bird's own colour, so the row looked like any other. It now has a
  white eye.
- **A file made searchable keeps one name in Synaplan.** Make searchable,
  summaries and translations sent its path with a leading slash
  (`/Shelf/plan.txt`), and its first move or new version without one
  (`Shelf/plan.txt`). They now send it without.
- **A removed file's bird stays away.** A slow answer asked for before
  Remove could arrive after the answer asked for after it and bring the
  bird back. Such an answer is now dropped and asked for again.
- **A bird that is adding or updating turns green on its own.** Files asks
  Synaplan again after 10 seconds, then after longer waits, up to five
  times, while the row is shown, and also when Synaplan gave no answer. It
  used to show "updating" until the page was reloaded.
- **A new version Synaplan asks for is sent again when sending it failed for
  now.** Synaplan asks once; with Synaplan unreachable, busy or answering
  something unreadable, or your connection not renewable just then, the
  copy stayed old and its bird
  showed "updating" until the next edit. It is now tried again after 1, 5,
  15 and 60 minutes.
- **A file change made while your connection cannot be renewed** reaches
  Synaplan once it can. With Synaplan briefly unreachable and your access
  token expired, the change or deletion used to be dropped.
- **Withdrawing consent while a token refresh is under way** no longer lets
  the refresh's answer store the grant again, also when the withdrawal
  could not wait for the refresh. Revoking waits for the refresh and ends
  the token it rotated to.
- **Renewing a provisioned key** keeps the old key when no new one could be
  minted, for example while the administrator's key is missing. It used to
  revoke the person's only working key.
- **Notifications signed with a new key** make Synaplan's keys be read at most
  once a minute also when several arrive at once. Each used to read the old
  check time and fetch the keys itself.
- **What may Synaplan read** shows the named folders, **Add a folder** and
  the "every file" warning under their choices whichever is picked, and says
  that a folder's **Let Synaplan read this folder** adds it to the list.
- **Formal German** says the read choices in the person's own words, as
  informal German does: "Nur was ich beobachte", "Auch Ordner, die ich
  freigebe", "Alle meine Dateien".
- **People with a long user id**, such as LDAP UUIDs or user_oidc's hashed
  ids, keep their Synaplan connection after the first hour and can change
  what Synaplan may read. Without a locking cache, Nextcloud keeps at most
  64 characters of a lock name, so their refresh and read-setting locks
  could never be taken.
- **"Only what I watch" and "All my files"** no longer tell Synaplan the
  folders named under "Also folders I allow". Synaplan opened reads of them
  that the app then refused. The app keeps the list for when the person
  switches back. After the update, Synaplan learns within the hour for
  everyone who kept named folders under another choice.
- **The searchable files on the Synaplan page** are the newest hundred that
  came from Nextcloud. The app asked Synaplan for page 100 of all files, so
  the list was mostly empty.
- **Deactivate** in the admin's AI Users panel pauses a person whose account
  the app created, and **Activate AI** resumes them with the same account.
  2.0.0 deleted their key with the key itself, which ended their link in
  Synaplan for good, so they could not turn AI on again.
- **A folder read that runs in the background** checks each file against
  the read setting and the ceiling as saved at that moment. A job sending
  files for minutes kept the values it read first, so it still sent files
  from a folder the person had just taken back or above a ceiling the
  administrator had just lowered.
- **A folder read that names no folder**, or names the home folder by its
  path or by its id, is refused as "not found", like the OpenCloud app does.
  With **All my files** allowed, it read the person's whole home folder.
- **A folder a person let Synaplan read** stays readable when it is renamed
  or moved, and a new folder created under its old name is not readable.
  The permission followed the name only. Saving the read setting keeps each
  allowed folder, also while it cannot be found; it looked every folder up
  by its name again and gave the permission to whatever folder had it.
- **Let Synaplan read this folder** works for a folder that took an allowed
  folder's name, after that folder was deleted or moved away. It said
  "Synaplan may read this folder." and changed nothing, so every read of the
  new folder was refused.
- **A folder read finished in the background** skips a file it cannot open
  or check, sends the rest and reports. One such file ended the read: the
  files after it were never sent and Synaplan waited for a result in vain.
- **A folder read answers within its 20 seconds**, also for a large folder,
  slow storage or a slow Synaplan. Going through the folder, file by file,
  reading each file sent and every call to Synaplan, the result included,
  count against that time; when it runs out before Synaplan has said what
  to send, a background job reads the folder again, and what is left after
  that, uploads or only the result, goes from the job too. Going through
  the folder had no limit and each listing call could wait 30 seconds, well
  past the 30 seconds Synaplan waits for the answer.
- **Folder reads are offered only where Synaplan reads folders.** The read
  setting, **Let Synaplan read this folder** and its menu entry appear when
  Synaplan's configuration offers both folder reads and the read setting.
  Against an older Synaplan, or one with platform links off, they saved a
  setting nothing would use; now they are hidden, and a direct request is
  refused without changing the saved setting. Settings and the menu entry
  both say that this Synaplan does not read folders.
  refused without changing the saved setting. While Synaplan gives no
  answer, its last answer of the past day stands in, so an outage does not
  hide them.
- **"Keep it up to date"** and "If you delete the file here, Synaplan
  forgets it too." show in "Make searchable by AI" only where Synaplan hears
  when a file changes: people use their own accounts, this Nextcloud is
  registered, and Synaplan's configuration offers change reports. Elsewhere
  the upload says nothing about keeping the file, so Synaplan keeps what it
  had. The file was stored as kept anyway, so after its first change it
  read as updating for good, and deleting it here left it in Synaplan.
- **The glyphs Synaplan sets for the bird** are asked for once every five
  minutes, and a minute after Synaplan gave no answer. Every folder view
  and every opened sidebar asked for them again and waited up to 15 seconds
  for the answer.
- **Synaplan hears of a person's file changes in the order they happened.**
  An event Synaplan could not take yet, such as a deletion while it was
  down, now holds back the events after it, so a restore can no longer
  arrive first and be ignored. A change someone else made earlier to a file
  shared with you reaches your copy before yours does. An event is tried
  again after a minute, then 5, 15 and 60 minutes, and given up after that.
  Events go out in batches of up to 100 per call. The queue needs the new
  version number's database update.
- **Synaplan also updates and forgets the copies of the people a file is
  shared with.** A change, move, deletion or restore is told for each
  person who can reach the file and holds a Synaplan connection, with the
  path they see; for a deletion they are noted before the shares go, also
  those who have only a share of something inside a deleted folder. Only
  the person who acted and the owner were told, so a copy someone made of a
  file shared with them stayed old, and stayed after the file was deleted.
- **Removing a share from your files deletes your Synaplan copies of it.**
  Removing a share someone gave you, or leaving a group share, deleted
  nothing in Nextcloud, so your copies of its files stayed in Synaplan.
  What you still reach through another share stays.
- **Renaming a folder shared with you tells Synaplan of your copies only.**
  The folder keeps its name for its owner and everyone else it is shared
  with, but the owner was sent a move of every file in it to the path it
  already had. The same held the other way round: when the owner renamed or
  moved a shared folder or file, everyone it is shared with was sent moves
  to the paths they already had.
- **Moving, deleting or restoring a large folder answers at once.** The
  files inside are queued for Synaplan from the file cache in one statement,
  or, for a move, by the background job 500 at a time. The request went
  through every folder below, read each file and queued a job per file and
  person first, so the desktop client could time out on a move that went
  through.
- **Synaplan learns the new path of an allowed folder** as soon as it, or a
  folder around it, is renamed or moved. Synaplan refused to read it at the
  new path until the person saved their read setting again. A move that
  could not be reported then is put right the next time the setting is
  saved or the hourly job sends it again.
- **A copy that cannot be written** tells Synaplan why: storage full, a
  folder the person may not write to, a target folder that is gone, twenty
  files with that name, or a file over 100 MB. Every failure used to read
  "could not reach".
- **A large copy** has the five minutes its download is valid, not 30
  seconds, and finishes after Synaplan stops waiting for the answer; the
  result call tells Synaplan when it is done. The download only goes to that
  copy's own file on Synaplan.
- **A copy whose claim failed** is claimed once more in the background while
  it is still valid. The app answered "still copying" and then never sent
  the result, so Synaplan marked the copy unconfirmed after ten minutes.
- **A folder read of more than 500 files** lists every file. Synaplan reads
  500 items per listing call, so the files after the first 500 were left out
  without a word.
- **Accounts provisioned before this Nextcloud registered** are moved onto
  its registration by an hourly background job, once. Until then Synaplan
  could not find them when a person was deleted, disabled or paused here, so
  their Synaplan accounts stayed as they were.
- **Saving what Synaplan may read** keeps the folder for files from
  Synaplan. A save that left that folder out put it back to `Synaplan`, and
  the read setting's Save stored an unsaved edit of the folder field.
- **Also folders I allow** keeps the folders you watch readable. Allowing
  one more folder, also through **Let Synaplan read this folder**, stopped
  Synaplan reading the watched ones.
- **A folder for files from Synaplan made of spaces** means the Synaplan
  folder, and a path with a blank folder name in it, like `Projects/ /Q3`,
  is refused. Both were saved, and copies went into a folder without a
  visible name.
- **The table of notifications already seen** comes with the app's
  migrations, like its other tables. It was created at the first
  notification with SQL that Oracle does not accept, so no copy arrived
  there.
- **The address Synaplan uses to reach this Nextcloud** defaults to this
  server's own address, also when that is `localhost`. It was the dev
  stack's `http://nextcloud`, which Synaplan was then told to call; the dev
  and test stacks now set that address themselves.
- **Open in Nextcloud** in Synaplan and the link in the notice about a copy
  go to the address people use, not the one Synaplan called. On a server
  without `overwritehost`, both pointed at the internal address entered for
  Synaplan.
- **Save in the chat** puts the file into the folder you chose under "Save
  files from Synaplan to", in its kind folder, like a copy Synaplan sends.
  It always used `Synaplan/`.
- **From Synaplan and Save in the chat** have the limits of a copy: a file
  over 100 MB is refused before it is read, a full storage and twenty files
  of one name say so, and a write that fails leaves no empty file behind. A
  large file ended the request when PHP ran out of memory.

## 2.0.0 – 2026-11-16

1.8.1 is already on the App Store. That release added Watch with Synaplan
(keep a folder or a file searchable, or run one of your tasks) and the file
features around it. Those stay. 2.0.0 changes how this Nextcloud signs in,
and what an administrator can see.

- **An OAuth client id is the technical login.** This Nextcloud registers
  with a key pair it makes itself. Synaplan keeps the public key and answers
  with a client id. That id is how the server signs in, so files are handed
  over on their own: Synaplan delivers a copy here, and this Nextcloud sends
  file changes with the person's own grant. No secret from Synaplan is stored
  here any more. An optional registration code makes the registration active
  at once; without it a Synaplan administrator approves it.
- **Administration shows that registration.** The settings say whether this
  Nextcloud is waiting for approval, ready, or no longer accepted, and they
  name the address Synaplan uses to reach it. Forgetting the registration and
  registering again is one step. A connection mode takes a click only on its
  label and its button.
- **A person's files stay theirs.** Copies, folder reads, read settings and
  file changes use that person's own login. Synaplan's calls are checked as
  signed tokens before a file is written here. Deleting, disabling or
  enabling a person is reported for that person only.

### Changed
- **Connecting goes through Synaplan's OAuth2 server.** A person allows the
  connection on Synaplan's consent page.
- When Synaplan no longer accepts this Nextcloud's registration (revoked or
  removed there), the admin settings say so, a person who connects is told to
  ask the administrator, and a connected person can still disconnect.
- When Synaplan's answer to a token refresh gets lost, this Nextcloud asks
  once more right away and the person stays connected.

### Fixed
- In the admin settings, a click beside a mode's label no longer looks like a
  choice that did nothing; only the label and its button take the click.

### Upgrading
- Needs a Synaplan with the OAuth2 server for clouds. After the update an
  administrator registers this Nextcloud again in the admin settings, and
  people connect again. Until then file changes are not reported to Synaplan.

## 1.8.1 – 2026-10-29

### Added
- **Watch with Synaplan.** On a folder or a file, choose to keep new files searchable in a knowledge folder, or to run one of your tasks. The choices come from your Synaplan account.
- **Searchable files in Nextcloud Files.** Files that are searchable by AI show the Synaplan bird in the file list and on the Synaplan page, and the Synaplan sidebar tab shows who added the file, which knowledge folder it is in, and lets you remove it or open it in Synaplan. **Make searchable by AI** offers **Keep it up to date**, and changes, moves, deletions and restores in Nextcloud follow in Synaplan.
- **Who is who.** Registering this Nextcloud also works when the administrator creates the accounts, and tells Synaplan which server this is. Accounts that already exist are linked to this server once, in the background. Removing, disabling or enabling a person tells Synaplan about that person only. A Synaplan account that is still used somewhere else is kept.
- **Organisation login** is listed with the other ways people use Synaplan, and says it needs the Nextcloud app user_oidc.
- **What Synaplan may read.** In personal settings a person chooses only what they watch, folders they name, or all of their files. A folder's menu can add that folder. A folder read from Synaplan lists those files and sends the ones that were asked for.
- Synaplan can copy a file into this Nextcloud. It lands in the Synaplan folder, or in the folder a person chooses in their personal settings. A notification names the file and the folder.
- **New → From Synaplan** lists the person's files in Synaplan, with generated files first, and copies the chosen file into the folder that is open.

### Changed
- **Where Synaplan is** shows one pair at a time: the address this Nextcloud calls, and the address a person opens in the browser. Live and local each keep their own pair. Inside Docker the server address is `http://backend` and the browser address is `http://localhost:5173`.
- **Translate no longer returns a summary.** It asks Synaplan to translate the file. When that Synaplan does not offer translation, the action says so. The answer no longer includes summary details.

## 1.7.0 – 2026-10-19

The first release in the App Store since 1.5.0. It also brings everything
listed under 1.6.0, which was never published there, and the planned 1.6.1,
folded in here.

### Added
- **Five languages.** German in the informal (`de`) and the formal (`de_DE`)
  form, Spanish, French and Turkish, next to English. The formal German of
  earlier versions moves to `de_DE`.
- **Honest answers when something fails.** Every Files action and the chat say
  in one sentence what went wrong and what to do, for example
  "Connect your Synaplan account first." with a link to the settings, or
  "Synaplan cannot be reached. Try again in a minute, or ask your
  administrator." No error code and no exception message reach the screen;
  they go to the Nextcloud log.

### Changed
- **Keys are stored encrypted.** Each person's Synaplan key and the admin keys
  are now sensitive values that Nextcloud encrypts. Keys stored in plain text
  by earlier versions are encrypted the first time they are used. A key that
  no longer decrypts counts as no key: a linked person sees Connect again.
- **Translate uses Synaplan's translation mode**, so it translates the whole
  text instead of writing a long summary in the target language. Against a
  Synaplan without that mode it works as before.
- **The core's words.** "Make searchable by AI" replaces "Add to AI
  Knowledge", "knowledge folder" replaces "knowledge group", and the
  connection modes are "One shared account", "Accounts created by the
  administrator" and "Connect your Synaplan account".
- **Only signed-in pages load the app.** The login page and public share
  pages no longer load the chat button or the Files actions.
- **Nextcloud 33 to 35** (planned for 1.6.1). Nextcloud 35 joins the supported
  range. Nextcloud 30 to 32 keep 1.5.0, which the App Store offers them.
- Controllers declare who may call each route with PHP attributes instead of
  the docblock annotations Nextcloud deprecated (planned for 1.6.1). Access is
  unchanged, and a test pins it for every route.

### Fixed
- Only a refused key (HTTP 401) makes the app forget a person's key. A 404 for
  a path that contains "401", or a connection error, no longer disconnects
  them.
- A `/pic` or `/vid` typed right after opening the AI chat is no longer
  refused as "not available": the chat waits until it knows whether image and
  video generation are available.
- **Deactivate** in the admin's AI Users panel revokes the person's key in
  Synaplan for accounts the app created, too. Before, Nextcloud forgot the key
  but it stayed valid in Synaplan.

### Upgrade notes
- Going back to 1.6 after 1.7.0 is not supported: 1.6 cannot read the
  encrypted admin key. If you do, enter the admin key again.

### Development
- Releases come from CI: a tag on a green `main` commit builds, signs and
  publishes them.
- CI clicks through the main journeys on Nextcloud 33 and 35 before every
  merge.

## 1.6.0 – 2026-09-15

Three ways to connect Nextcloud to Synaplan. Existing installs keep working:
unset `mode` still follows the old “Give each user their own account” flag.

### Added
- **Connect an existing Synaplan account (`link` mode).** Personal settings →
  **Connect Synaplan**: sign in once, confirm, and Files actions run as that
  account. No key is typed or shown.
- **Two-option AI gate** in `link` mode: connect your account, or (if the
  admin allows it) create one. If the email is already taken, the gate offers
  to connect that account.
- **Admin mode selector** — shared key, create-an-account-per-user, or connect
  existing accounts — plus **Register this instance** and Linked / Provisioned
  badges on activated users.
- Upgrade note: switching a `provision` install to `link` keeps provisioned
  keys until someone chooses **Connect my Synaplan account**; new users see
  the two-option gate. Personal settings keep Connect available for
  provisioned users (they are not treated as already linked).

## 1.5.0 – 2026-07-12

Knowledge-file lifecycle + per-file chat, built on Synaplan's file-lifecycle
API (CORE-4).

### Added
- **Chat about this file** — a new Files action opens a focused AI chat about a
  single file (text / JSON / XML), wiring up the previously unused per-file chat
  modal.
- **Update / Remove in Knowledge** — the "Add to AI Knowledge" dialog now
  detects when a file is already indexed and switches to **Update in Knowledge**
  (re-index in place, no duplicates) and offers **Remove from Knowledge**.
- **"Changed since indexed" indicator** — the dialog shows a warning when the
  Nextcloud file was modified after it was last indexed, with one-click update.

### Changed
- Knowledge uploads now send the Nextcloud **file id** (`source_id`) and
  **etag** (`source_etag`) and use overwrite-by-source, so re-adding a file (or
  adding a changed one) updates its knowledge entry in place instead of creating
  a duplicate.

### Requires
- For the update/stale/remove features: a Synaplan backend with the
  knowledge-file lifecycle API (`source_id` / `source_etag` / `overwrite`,
  `check-stale`, `mark-stale`). Against older backends the dialog degrades to
  plain "Add to Knowledge".

## 1.4.0 – 2026-06-28

### Added
- **Backend environment switch (Live / Local).** Admin settings now keep two
  separate Synaplan profiles — **Live (production)** and **Local
  (development)** — each with its own URL and API key, plus an **Active
  environment** toggle that flips the whole app (chat, media, knowledge, proxy)
  between them in one click. Makes it easy for any developer to point the app at
  a local Synaplan dev backend (e.g. `http://localhost:8000`) and switch back to
  live without re-entering credentials.

## 1.3.1 – 2026-06-28

Bug-fix release for the generated-media save flow.

### Fixed
- **Generated images/videos now display and can be saved.** A generated media
  card could show a broken image with a "Save to Nextcloud" button that saved a
  non-image (the backend served the file only after it had a database record).
  Paired with a Synaplan backend fix that registers generated media as a file,
  the image now loads and saving works.
- **No "Save" button for media that failed to load.** The "Save to Nextcloud"
  button now appears only once the image/video has actually loaded; if a
  generated file can't be loaded, a clear message is shown instead of an
  actionable save button.

## 1.3.0 – 2026-06-27

File delivery + provenance sprint — the first half of the cross-repo "Send to…"
work (Synaplan Release 4.0 Feature 7), coordinated with Synaplan's file-world
provenance model (`source` / original name).

### Added
- **Save any generated file into organised folders** — saving a Synaplan
  artifact to Nextcloud now sorts it into a typed sub-folder
  (`Synaplan/Documents`, `Synaplan/Audio`, `Synaplan/Calendar`,
  `Synaplan/Images`, `Synaplan/Video`); unknown types fall back to `Synaplan/`.
  The save endpoint is no longer limited to images/videos — it accepts any file
  type, ready for documents/audio/calendar as the chat surfaces them.
- **File provenance on knowledge uploads** — every file the app pushes to
  Synaplan (Add to Knowledge, and the Tika extraction step used for chat
  context) is now tagged `source=nextcloud` and carries its **original
  Nextcloud path** as the source name, so Synaplan can label where the file came
  from in its file manager / "Incoming" view.

### Notes
- Surfacing generated **documents/audio/calendar** inside the in-app chat (so
  they get a "Save to Nextcloud" button like images/videos) is a follow-up: the
  research chat currently generates only images/videos, and its text stream uses
  Synaplan's OpenAI-compatible endpoint, which does not emit generated-file
  events. The save + folder infrastructure added here is already type-agnostic
  and ready for that step.

## 1.2.1 – 2026-06-24

Major upgrade focused on language handling, personalisation, real streaming, and a clearer, better-formatted chat experience.

### Added
- **App version in the chat tagline** — the Research/launcher header now shows the running version (e.g. "Ask anything v1.2.1 — powered by Synaplan"), read dynamically from the installed app version.
- **Configurable answer language** — a new **"Language & AI behaviour"** admin section lets you pick a **default answer language** and toggle **"Use each user's Nextcloud interface language when supported"**. A German user then gets German answers automatically, with the default as the fallback. This replaces the previous behaviour where chat and document tools were effectively always English. The resolved language is injected as a system instruction into the streaming chat and passed through to summaries/translations, and the Summarize/Translate dialogs pre-select it.
- **Personal memories in chat** — when Synaplan's memory service (Qdrant) is reachable and the admin has enabled it (**"Allow personal memories in chat"**), the Research/launcher chat shows a **"Use my memories"** switch (on by default). Relevant memories are searched per question and injected as context so answers are personalised. The control is hidden entirely when the service is unavailable.
- **Live chat status row** — at-a-glance chips show the **active model**, the **answer language**, whether **image (`/pic`) and video (`/vid`) generation** are available, the selected knowledge base, and the memory state. Each answer is captioned with the model that actually produced it.
- **Staged progress while waiting** — instead of a static "Thinking…", the chat now reports what the backend is doing: *Sorting your request → Searching the knowledge base → Recalling your memories → Generating the answer*, stopping the moment streaming begins.
- **Markdown rendering for document results** — Summarize and Translate results now render Markdown (headings, bold, lists, code, links) via `NcRichText`, matching the chat, instead of showing raw `**`/`-` source.
- **Client-config endpoint** (`api#clientConfig`) exposing the resolved language and memory availability to the frontend.
- **Staged upload progress** — the "Add to Knowledge" dialog shows a progress bar and a phase checklist (Uploading → Extracting text → Creating chunks → Generating embeddings) with elapsed time.

### Changed
- **Real token-by-token streaming** — chat answers now stream live. The SSE relay was rewritten to a direct cURL pass-through (`SseProxyResponse`) that writes each chunk straight to the browser and defeats PHP/Nextcloud output buffering (disables `zlib`/output buffering, `Accept-Encoding: identity`, `X-Accel-Buffering: no`, connection priming). Previously the answer arrived all at once after a long pause.
- **Higher-contrast status chips** — active capabilities use a solid brand fill with guaranteed-contrast text; disabled ones are clearly muted but still legible in both light and dark mode.
- **Single-ring loading spinner** — Summarize/Translate dialogs use one clean spinner instead of the twin-circle icon.
- **Tighter Markdown spacing** — list items in rendered answers no longer have oversized gaps.
- **Per-chunk CSS injection** (`relativeCSSInjection`) so shared component styles (NcSelect, NcCheckboxRadioSwitch) are injected by every entry that uses them — fixes unstyled dropdowns/switches on the Research page, the in-page launcher, and the file-action dialogs.

### Fixed
- **Answers are no longer "always English"** — the chat/streaming path previously sent a hard-coded `en` output language; it now respects the admin default and/or the user's interface language.
- **Admin toggles are clickable** — the language/memory switches (and the chat "Use my memories" switch) used the wrong prop/event binding for `@nextcloud/vue` v9 and could not be toggled; they now use the correct `v-model`.
- **Knowledge upload no longer shows a false success** — when the server stores 0 chunks (empty/image-only file, or the embedding service is unavailable), the dialog reports a clear error instead of a green "File added" screen with "Chunks created: 0".
- **Removed leftover "this is a demo" text** from the knowledge-upload progress hint.

### Security / dependencies
- Updated `vite` to `7.3.5`, fixing a moderate dev-server advisory affecting `7.0.0–7.3.3`.
- Refreshed dependency lockfiles: `friendsofphp/php-cs-fixer` `3.95.2`, `prettier` `3.8.4`, `@nextcloud/vue` `9.8.2`, `@nextcloud/axios` `2.6.0`, `@nextcloud/dialogs` `7.4.0`, `vue` `3.5.38`, and `@nextcloud/initial-state` `3.0.0`.

## 1.1.2 – 2026-06-02

### Fixed
- **Chat launcher polish** — taller window (+50px), Knowledge/Model selectors closer together, and the input field no longer clipped at the bottom edge (box-sizing fix).

## 1.1.1 – 2026-06-02

### Fixed
- **Chat launcher layout** — enlarged the window, stacked the Knowledge/Model selectors (no more overlap), tuned fonts/margins, raised contrast (brand-coloured header), and the floating button now hides while the window is open so they never overlap.

## 1.1.0 – 2026-06-02

### Added
- **In-page chat launcher** — a floating button (bottom-right) on every Nextcloud page that opens the Synaplan AI assistant in a compact chat window, without navigating to the full-page Research view. Reuses the existing Research chat (knowledge group + model selection, `/pic` & `/vid` media commands).

### Fixed
- **Boxed Office/Collabora editor** — the modal-fix styles were global and capped Nextcloud's own Viewer modal (which hosts the Collabora editor) at `max-width: 900px`, boxing the editor chrome. These rules are now scoped (via `:has()`) to the plugin's own modals only, so the Office editor renders full-width again.

## 1.0.0 – 2025-02-14

### Added
- **Summarize documents** via file context menu (bullet points, paragraph, abstractive; short/medium/long; 12 output languages)
- **Translate documents** via file context menu (12 target languages)
- **Add to AI Knowledge** — upload files to the Synaplan vector knowledge base with group management
- **Research Chat** — full-page AI assistant accessible from top navigation with knowledge group and LLM model selection
- Support for binary document formats (PDF, DOCX, ODT, XLSX, PPTX) via Synaplan's Tika text extraction
- Admin settings page with Synaplan URL and API key configuration
- Connection test functionality in admin settings
- Top-level "Synaplan" navigation entry with custom bird logo
- Upload progress spinner with elapsed time counter for long-running knowledge uploads
- Improved success state with high-contrast SVG checkmark for dark theme compatibility
- Unit tests for all controllers (42 tests, 114 assertions)
- CI pipeline with PHP lint (PSR-12), PHPUnit, ESLint, Prettier, and Vite build checks
