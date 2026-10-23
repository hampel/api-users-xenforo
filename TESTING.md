# Testing

What this add-on touches, where it can go wrong without saying so, and how each of those is
checked. For whoever changes it next.

## Surfaces

| type | name |
|---|---|
| authentication scheme | `Hampel\ApiUsers:ApiOnly` → `Authentication\ApiOnly` |
| table | `xf_hampel_apiusers_user` — one row per API user |
| class extension | `XF\Entity\User` — the `ApiUser` relation, the email guard, the profile and follow checks |
| class extension | `XF\Entity\UserAuth` — the password guard |
| class extension | `XF\Finder\User` — `isValidUser()`, at `execute_order` 20 |
| class extension | `XF\Service\User\Merge` — refuses a merge that involves an API user |
| class extension | `XF\Admin\Controller\User` — the merge form's refusal, with its reason |
| admin route | `api-users/` → `Admin\Controller\ApiUser` |
| admin permission | `hampelApiUsers` |
| admin navigation | `hampelApiUsers`, under `serviceProviders` |
| templates | admin `hampel_apiusers_list`, `hampel_apiusers_add`, `hampel_apiusers_convert` |
| template modification | admin `user_edit` × 5 — notice, convert action, email field, password field, merge action |
| CLI command | `api-users:create`, `api-users:list`, `api-users:validate` |
| code event listener | `hampel_admin_api_redaction` → `Listener::adminApiRedaction` |
| config key read | another add-on's: `archiveSite.protectedUsers`, `archiveSiteProtectedUsers` |

No options, cron entries, jobs or public templates.

## Fragile points

- **An extension that replaces `isValidUser()` without calling its parent discards the exclusion.**
  This add-on registers at `execute_order` 20 to sit outside most others, which does not help
  against one registered higher. `api-users:validate` reads the built query, so it reports this.
- **The five `user_edit` modifications stop applying when core rewords that template.** The
  upgrade succeeds and the notice, the convert action and the two notes vanish. Nothing is
  unguarded when they do: the entity guards refuse a password and an email address with every
  modification disabled. `validate` warns for each one that no longer matches.
- **The table and the scheme can be made to disagree from outside**, by a direct database edit or
  by code that writes `xf_user_authenticate` without the entity. The guards run on entity saves
  only. `validate` fails in either direction.
- **Conversion only knows the content tables that are registered with core.** A table counts as
  content when it is in the map core uses to rename and delete a user. An add-on that keeps a
  member's content in a table it never registered there is invisible, and an account with rows in
  it converts.
- **Conversion only removes the ways in that it knows.** An add-on that adds a login route of its
  own, keyed on something other than the password, a connected account, a passkey or an OAuth
  token, is not covered, and `validate` does not look for it.
- **Beside Archive Site, an API user that is not in its protected list is treated as a member to
  archive.** `validate` warns for each one missing from the list.

## Automated

### Running the suite

```bash
composer install
vendor/bin/phpunit
php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text   # what phpunit.xml's <source> names
```

**The feature tests run against the development forum's own database, inside a transaction each.**
They create real accounts and roll them back, and they read the forum's own `config.php`. Four
consequences:

- **Touch shared tables by primary key only.** `xf_user_authenticate.scheme_class` has no index, so
  an update or delete by it scans the table and waits on every row another add-on's suite has
  locked. That hung a run here until the statement was rewritten.
- **Feature tests can fail with a deadlock when another suite is using the same forum.** Each
  test creates accounts, and two suites doing so at once deadlock on `xf_user_group_relation`. Run
  again when the other has finished; a real failure is not a `DeadlockException`.
- **The forum's Archive Site keys are cleared before every test**, both of them, in
  `Tests\TestCase::setUp()`. The nested `archiveSite['protectedUsers']` takes precedence over the
  older flat key, so on a forum that sets it, a test setting only the flat key was overridden and
  failed. Found on 2026-10-15, when the development forum's `config.php` gained the nested key;
  with the nested clear removed, that test fails there again.
- **Nothing that runs DDL can be tested this way**: it commits the transaction. The second
  uninstall step is proved in a sandbox instead.

### Static analysis

```bash
vendor/bin/xenforo-phpstan generate --check   # read-only: is the forum's generated set current?
vendor/bin/xenforo-phpstan generate           # once per forum, and after a XenForo upgrade
vendor/bin/phpstan analyse --memory-limit=1G
```

**Level 7, with no errors and no baseline**, since 2026-10-23, through `hampel/xenforo-phpstan`.
Run it from this directory with this add-on's own `vendor/bin/phpstan`. `generate` writes into the
forum's `internal_data/phpstan/`, not into this repository, and a stale set fails loudly.

- **`Seed/` and `Factory/` are not analysed.** They extend the Seeder add-on and call the Faker it
  vendors, and another add-on's `vendor/` is deliberately left out of the scan.
- **Only `XF\Mvc\ParameterBag` is treated as an object crate.** Route parameters have no static
  type. Entities are not crates, so a mistyped column is still reported.
- **It runs against the analysis PHP, not against 7.4.** A `phpVersion` floor of 7.4 hides
  PHPUnit 12 from the analysis and breaks every test, so the 7.4 floor is held by the release
  lint in a sandbox instead. The shipped code alone was clean against 7.4 at level 5 on that date.
- **Types are docblocks in the shipped code and native only on test methods.** The floor is PHP
  7.4, and an inherited method takes no typed parameters, so `@param` and `@return` carry them;
  every test method declares `: void`, as `setUp()` already did.
- **A value that can be `false` is asserted before it is used.** A file that cannot be read, a
  user that was not made: the test fails there, by name, and not on a type error further on.
- **Level 8 is the next step**: 64 errors on that date, 62 in the tests. Levels 9 and max were 98
  and 232.

**One report needed a change to the code rather than to the configuration.** The four `instanceof`
tests in `HealthCheck::checkExtensions()` read as always true, because the analysis types what the
container builds as this add-on's extension already, which is the very thing the check asks at
runtime. They go through a method of their own now. One more is ignored by identifier: the merge
extension's guard on its source and target, which core documents as `User` and sets by setters,
so either can still be null.

### What the suite proves

- `ApiOnlyTest` — the scheme never authenticates, reports a password, is not upgradable, and its
  stored name resolves back to the class.
- `CreatorTest` — what an API user is made as, the administrator record, and refusals.
- `GuardsTest` — a password, core's passwordless scheme, a reset and an email address are each
  refused; an ordinary member is unaffected; login and lost-password both fail.
- `VisibilityTest` — left out of valid-user listings outside the control panel and kept in them
  inside it; the profile checks.
- `CoreAdministratorPageTest` — core's own administrator save, driven against an API user: adding
  one and editing one both leave it API-only and send no mail.
- `AdminControllerTest` — the permission, the two extra requirements for an administrator, where
  each save redirects to, and that neither write is reachable without a CSRF token. Each refusal is
  asserted by its message, since several of them share a response code.
- `RenderingTest` — the three pages rendered: titles, the notes and labels each state shows, no
  phrase left as its key and no template error. And core's user edit page with the modifications
  applied: an API user's has the notice and neither field; a member's has both fields and the
  convert action; an administrator without the permission is given no link into this add-on; the
  add-user page is unchanged.
- `HealthCheckTest`, `CommandsTest` — each drift a guard cannot prevent is reported, and the exit
  contract. Neither shows `validate` reporting an extension that is not applied. A class extension
  resolved once in a process cannot be taken away for one test, so it would take the check reading
  an expected list a test can replace, which it does not yet.
- `ClassExtensionsTest` — all five extensions are registered against the 2.2 class names and
  resolve, the controller's included, which `validate` does not check.
- `SetupTest` — the first uninstall step.
- `MergeTest` — an API user is merged neither into a member nor with a member into it, by the
  service or through the control panel's form, which gives the reason and queues nothing; two
  ordinary members still merge both ways.
- `ConverterTest` — what conversion removes and keeps, each refusal by its own wording, and that a
  table an add-on registers counts as content. Each way in is planted as a real row, on the
  account and on a second member who keeps it. Until 2026-10-23 two-step verification was
  asserted absent without ever having been written, and passkeys and OAuth tokens were not
  asserted at all, so any of those removals could have been deleted with the suite still passing.
  Each of the four was then switched off in turn, and the test failed every time.

**Each guard was switched off in turn and the suite failed every time**, on 2026-10-10: the two
entity guards, the listing exclusion, its control panel exemption, the profile check,
`hasPassword()`, and both administrator checks in the controller. **On 2026-10-11** three more:
the wrong-password refusal given another refusal's wording, the super administrator rule removed,
and a phrase reference in the list template broken. Each failed the test written for it.

**The convert action is asserted on the page action and not on the page body.** Core's actions
menu is set through `<xf:pageaction>`, so a modification inside it never appears in the rendered
template.

### Proved in a sandbox

**Run on 2026-10-10 from the `1.0.0a1` release zip, with development off**, on XenForo 2.2.19
Patch 2 with PHP 7.4.33 and on 2.3.13 with PHP 8.3. The same on both unless noted:

- **Install**: imported from `_data`, no `_output/` created, every PHP file lints on 7.4.
- **Commands**, exit codes read through `docker exec`: `validate` 0 and `--strict` 2 with a
  warning present; `create` 0, and 1 for a name in use.
- **The REST API**, with a user key naming an API administrator: `GET api/me` answers as that
  account; `POST api/me/password` and `api/me/email` are refused.
- **The public side**, as a guest: the profile is a 403, the member list names no API user, and a
  login attempt answers *Incorrect password*.
- **The control panel**, logged in over HTTP: the list and add pages render; a save creates the
  account recorded against the acting administrator; the administrator option is refused on a
  wrong password; user search and the username autocomplete find API users.
- **Core's own forms**: editing an API administrator's permissions, and adding an API user as an
  administrator, both save with the scheme untouched, no row in `xf_user_confirmation` and nothing
  in Mailpit. The user edit form is refused a password and refused an email address, and saves a
  custom title.
- **`xf_error_log` stayed empty throughout.**
- **Uninstall, on 2.2 only**: every API user moved to `XF:NoPassword`, the table dropped, no
  extension, phrase, permission, navigation entry, route or listener left behind, the forum index
  rendering, and a login attempt against a surviving account still refused.

**The user edit page and conversion, on 2026-10-10**, on the same 2.3.13 instance after upgrading
it from the `1.0.0a1` zip to `1.0.0a2` by the real import path:

- all four modifications logged as applied, and `validate` reporting them;
- an API user's edit page shows the notice and the note, and has no email input, no password
  choice and no convert action; an ordinary member's has all three and no notice; the add-user
  page is unchanged;
- the convert page asks first, and refuses the acting administrator's own account;
- a member made through core's own form, with an email address and a password, converted: email
  gone, scheme API-only, hidden, recorded with the note, and its old password refused at login;
- core's edit form, posted without the hidden fields, still saves.

**The `1.0.0a3` zip, on 2026-10-10**: a fresh install on 2.2.19 Patch 2 with PHP 7.4.33, and an
upgrade from `1.0.0a2` on the 2.3.13 instance. On 2.2 the same edit page and conversion checks
were repeated over HTTP with the same results, which is the first time the four modifications were
rendered on that line; `xf_error_log` and Mailpit stayed empty.

**Uninstall on 2.3, on 2026-10-11**, of `1.0.0a3` on the 2.3.13 instance, with five API users
present, three of them administrators and one converted from a member:

- every one moved to `XF:NoPassword` with empty data, and no row anywhere still named this
  add-on's scheme;
- the table dropped; no class extension, phrase, admin permission or permission grant, navigation
  entry, route, listener, template or template modification left behind;
- the forum index and the control panel login rendering, `xf_error_log` and Mailpit empty after
  the job queue ran, and a login attempt against a surviving account answering *Incorrect
  password*;
- the three commands still listed, since an uninstall deletes no files, and each refused by
  XenForo as belonging to an inactive add-on;
- installed again from the same files: data imported by the real path, a new API user created,
  and `validate` exiting 0. **The five earlier accounts stay as they were left**: passwordless
  members, not API users, and `validate` does not report them, which is the rule that nothing is
  inferred from a missing password.

**The `1.0.0b1` zip, on 2026-10-11**, in two sandboxes with development off:

- **A fresh install on XenForo 2.2.0 Patch 2 with PHP 7.4.33, the declared floor.** Every PHP file
  lints. The install imports from `_data`; all five extensions and all five modifications apply.
  The three commands run with the exit codes of the contract. Driven by a script through
  XenForo's own entities and services: a password and an email address are refused, login is
  refused, a member cannot view an API user's profile or follow it, a member sent a welcome
  conversation and holding a connected account and follows converts and loses all three, a member
  who started a conversation is refused, and a merge is refused in each direction while two
  members still merge. Logged in to the control panel over HTTP: the list, the add form, both
  convert pages, the merge form's refusal, and the edit pages of an API user and of a member, each
  showing what the 2.3 pages show. As a guest the profile is a 403 and the member list names no
  API user. Then uninstalled: nothing left behind, the accounts on `XF:NoPassword`, the forum
  rendering. The error log held only the two merge refusals, and Mailpit nothing.
- **An upgrade from `1.0.0a3` on XenForo 2.3.13 with PHP 8.3**, by the real import path, with the
  job queue run afterwards. The two API users made before the upgrade are still API users with
  their notes; the upgraded copy holds no file the zip does not; `validate` exits 0; and the
  merge and follow refusals, new in this build, are in force.

**The `1.0.0b2` zip, on 2026-10-11**, in one sandbox with development off. It differs from
`1.0.0b1` in `addon.json`, which gained its links, and in the README and changelog; no code,
template or phrase changed, so only the upgrade was run:

- **An upgrade from the `1.0.0b1` zip on XenForo 2.3.13 with PHP 8.3**, by the real import path,
  with the job queue run afterwards. The two API users made before the upgrade, one of them an
  administrator, are still API users with their notes; the installed `addon.json` carries the
  three links; `validate` exits 0; the forum and the control panel answer; and the error log is
  empty. It was not installed fresh, not run on 2.2, and not uninstalled.

### Counted against other XenForo releases

**Each `user_edit` modification's `find` was counted against the admin templates shipped in eight
XenForo releases, on 2026-10-11**: 2.1.0, 2.1.15 Patch 1, 2.2.0 Patch 2, 2.2.19, 2.2.19 Patch 2,
2.3.0 Patch 2, 2.3.12 and 2.3.13. All five match exactly once in every one. The same pass read
each release's source for the core methods the extensions call: the merge service and its
continuation result, `canFollowUser()`, `isFollowing()` and `canBypassUserPrivacy()` are in all
eight. **2.2.0 Patch 2 is the declared floor**, so this is the nearest thing to a test of it short
of an install. No release in that set fails, so the count
has no control: it shows the modifications match, not that the count can report one that does not.

### Asked of generated members

**The converter was asked what it would refuse for 2,003 generated members on a development
forum, on 2026-10-11**, converting none of them. Of 2,000 that had never posted, 1,598 were
convertible, 400 refused as not in the valid state and 2 as moderators. All 3 that had started
threads were refused as used, naming `xf_post` and `xf_thread`. That is the classification checked
against accounts made by something other than this suite. No member in that set had logged in, been
alerted or followed anyone, so the bookkeeping list is still unchecked against an account that has
been active without posting.

**And for members in generated conversations, in a sandbox on the same date.** Of eight members,
the six who had written a message were refused, naming the conversation tables, and the two in no
conversation were convertible. One of those two was then sent a conversation the way XenForo's
welcome message is, as its only recipient and without reading or answering it. **It was refused
too**, naming `xf_conversation_recipient` and `xf_conversation_user`. That was changed the same day:
those two tables no longer count, and `ConverterTest` holds both halves with a conversation made by
XenForo's own services.

**And for members with another way in, or active without content, in the same sandbox.** Nine
generated members: one each with a connected account, two-step verification, a passkey, a saved
login and a pending email confirmation, and two that had logged in, been alerted, followed a
member and been followed back, with the two members they followed. The one awaiting confirmation
was refused as not valid. The other eight were convertible and were converted: every connected
account, two-step, passkey and saved login row was gone afterwards, and the login, alert and
who's-online rows of the active ones did not count as content. That is the first check of the
removal against rows written the way XenForo writes them.

**It found a leak.** The follows survived conversion, and a converted account was named on the
*About*, *Following* and *Followers* pages of the member it followed, as a guest. Conversion now
removes them, a new one is refused, and `validate` warns of any, which it did for the four
accounts converted before the change.

**Merging, through core's own job, in the same sandbox on 2026-10-11.** Three merges were queued
as the control panel queues them and the queue was run: an API user into a member, a member into
an API user, and one member into another. The first two left both accounts, their posts and their
API keys exactly as they were, each wrote one line to the server error log naming the pair and
which of them is an API user, and neither job stayed in the queue. The third merged as core
intends: the source gone and its posts on the target.

### Seeding API users

`Seed/ApiUserSeeder` makes API users in each state the list page shows, on a forum that has the
Seeder add-on:

```bash
php cmd.php seeder:run Hampel/ApiUsers:ApiUser -p users=30 --batch=api-users
php cmd.php seeder:clean api-users
```

**The run prints each key's value, and they are working credentials on that forum.**

**Run and cleaned on a development forum on 2026-10-11**, XenForo 2.3.12: seven accounts and six
keys made, two of the accounts administrators with an administrator record, two with no key and one
with a second, inactive key; every account on the API-only scheme with no email address, dated
across the run's span; `validate` exiting 0. After cleaning, the row counts of the user, profile,
option, privacy, authentication, administrator, API key, key scope, change log and error log
tables, and of this add-on's own, were each what they had been before the run.

### Beside Archive Site

**Run on 2026-10-11 in a sandbox on XenForo 2.3.13 with PHP 8.3, development off**, with this
add-on's `1.0.0a4` build and Archive Site 2.2.2 both installed from their release files, four
members with passwords, three API users and one API key naming an API user:

- **Installed together**: both import by the real path, the forum and the control panel render,
  and the error log is empty. The valid-user query carries both add-ons' conditions at once:
  Archive Site's widened state test, and this add-on's exclusion after it.
- **Unprotected**: `api-users:validate` warns, naming all three, and exits 2 under `--strict`.
  An archive of every account archives the four members, then stops at the first API user with
  *An API user cannot be given a password* and exits 1. No API user is changed, the key still
  answers `api/me`, and nothing is written to the error log.
- **Restored**: every member returns to its state and scheme, and the API users are untouched.
- **Protected**, with the three ids in `$config['archiveSite']['protectedUsers']`: `validate`
  reports them protected, Archive Site's own validate counts them, and the archive run completes
  and exits 0. Afterwards the members are disabled and refused at login with Archive Site's
  message; each API user is still valid, on the API-only scheme and recorded; the key still
  answers as its account, and is refused a password and an email address; an API user's profile is
  a 403 and the member list names none of them; a restore leaves them alone again.
- **The error log and Mailpit were empty at the end.**

## Needs a human

**How the pages read can only be judged in a browser.** Looked at by the author on 2026-10-11, on
XenForo 2.3.13, and found right:

1. The list at `api-users/`: the columns, the empty state, and the key count beside each account.
2. The add form, and the error shown when the administrator option is refused.
3. The *Convert to API user* confirmation, and each refusal it can give.
4. An API user's edit page: where the notice sits, that the notes replacing the email and
   password fields read as deliberate, and that its actions menu has no *Merge with user*.
5. The navigation entry: that *API users* is where an administrator would look for it.

**Not looked at on XenForo 2.2**, where the control panel's markup differs. Look again after any
change to a template or a modification.
