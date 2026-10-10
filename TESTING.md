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

## Running the suite

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

## What the suite proves

- `ApiOnlyTest` — the scheme never authenticates, reports a password, is not upgradable, and its
  stored name resolves back to the class.
- `CreatorTest` — what an API user is made as, the administrator record, and refusals.
- `GuardsTest` — a password, core's passwordless scheme, a reset and an email address are each
  refused; an ordinary member is unaffected; login and lost-password both fail.
- `VisibilityTest` — left out of valid-user listings outside the control panel and kept in them
  inside it; the profile checks.
- `CoreAdministratorPageTest` — core's own administrator save, driven against an API user: adding
  one and editing one both leave it API-only and send no mail.
- `AdminControllerTest` — the permission, the two extra requirements for an administrator.
- `HealthCheckTest`, `CommandsTest` — each drift a guard cannot prevent is reported, and the exit
  contract.
- `SetupTest` — the first uninstall step.
- `ConverterTest` — what conversion removes and keeps, each refusal, and that a table an add-on
  registers counts as content.

**Each guard was switched off in turn and the suite failed every time**, on 2026-10-10: the two
entity guards, the listing exclusion, its control panel exemption, the profile check,
`hasPassword()`, and both administrator checks in the controller.

## Proved in a sandbox

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

## Not yet proved


- Uninstall on 2.3 from the release zip.
- The pages looked at by a person in a browser, which is the only check on how they read.
- Archive Site installed beside it in a sandbox. On the development forum, where it is installed,
  the valid-user query carries both add-ons' conditions and `validate` gives the warning.
