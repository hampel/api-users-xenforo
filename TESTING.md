# Testing

What this add-on touches, where it can go wrong without saying so, and how each of those is
checked. For whoever changes it next.

## Surfaces

| type | name |
|---|---|
| authentication scheme | `Hampel\ApiUsers:ApiOnly` → `Authentication\ApiOnly` |
| table | `xf_hampel_apiusers_user` — one row per API user |
| class extension | `XF\Entity\User` — the `ApiUser` relation, the email guard, the profile checks |
| class extension | `XF\Entity\UserAuth` — the password guard |
| class extension | `XF\Finder\User` — `isValidUser()`, at `execute_order` 20 |
| admin route | `api-users/` → `Admin\Controller\ApiUser` |
| admin permission | `hampelApiUsers` |
| admin navigation | `hampelApiUsers`, under `serviceProviders` |
| templates | admin `hampel_apiusers_list`, `hampel_apiusers_add`, `hampel_apiusers_convert` |
| template modification | admin `user_edit` × 4 — notice, convert action, email field, password field |
| CLI command | `api-users:create`, `api-users:list`, `api-users:validate` |
| code event listener | `hampel_admin_api_redaction` → `Listener::adminApiRedaction` |
| config key read | another add-on's: `archiveSite.protectedUsers`, `archiveSiteProtectedUsers` |

No options, cron entries, jobs or public templates.

## Fragile points

- **An extension that replaces `isValidUser()` without calling its parent discards the exclusion.**
  This add-on registers at `execute_order` 20 to sit outside most others, which does not help
  against one registered higher. `api-users:validate` reads the built query, so it reports this.
- **The four `user_edit` modifications stop applying when core rewords that template.** The
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
```

**The feature tests run against the development forum's own database, inside a transaction each.**
They create real accounts and roll them back. Two consequences:

- **Touch shared tables by primary key only.** `xf_user_authenticate.scheme_class` has no index, so
  an update or delete by it scans the table and waits on every row another add-on's suite has
  locked. That hung a run here until the statement was rewritten.
- **Nothing that runs DDL can be tested this way**: it commits the transaction. The second
  uninstall step is proved in a sandbox instead.

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
  contract.
- `SetupTest` — the first uninstall step.
- `ConverterTest` — what conversion removes and keeps, each refusal, and that a table an add-on
  registers counts as content.

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

### Counted against other XenForo releases

**Each `user_edit` modification's `find` was counted against the admin templates shipped in six
XenForo releases, on 2026-10-11**: 2.1.15 Patch 1, 2.2.19, 2.2.19 Patch 2, 2.3.0 Patch 2, 2.3.12
and 2.3.13. All four match exactly once in every one. No release in that set fails, so the count
has no control: it shows the modifications match, not that the count can report one that does not.

### Asked of generated members

**The converter was asked what it would refuse for 2,003 generated members on a development
forum, on 2026-10-11**, converting none of them. Of 2,000 that had never posted, 1,598 were
convertible, 400 refused as not in the valid state and 2 as moderators. All 3 that had started
threads were refused as used, naming `xf_post` and `xf_thread`. That is the classification checked
against accounts made by something other than this suite. No member in that set had logged in, been
alerted or followed anyone, so the bookkeeping list is still unchecked against an account that has
been active without posting.

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

### Not yet run

- Archive Site installed beside it in a sandbox. On the development forum, where it is installed,
  the valid-user query carries both add-ons' conditions and `validate` gives the warning.

## Needs a human

**Every page check above read the HTML, in the suite or over HTTP. Nobody has looked at a page in a
browser**, which is the only check on how one reads:

1. The list at `api-users/`: the columns, the empty state, and the key count beside each account.
2. The add form, and the error shown when the administrator option is refused.
3. The *Convert to API user* confirmation, and each refusal it can give.
4. An API user's edit page: where the notice sits, and whether the notes that replace the email
   and password fields read as deliberate and not as a broken form.
5. The navigation entry: that *API users* is where an administrator would look for it.
