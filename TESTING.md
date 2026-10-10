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
| templates | admin `hampel_apiusers_list`, `hampel_apiusers_add` |
| CLI command | `api-users:create`, `api-users:list`, `api-users:validate` |
| code event listener | `hampel_admin_api_redaction` → `Listener::adminApiRedaction` |
| config key read | another add-on's: `archiveSite.protectedUsers`, `archiveSiteProtectedUsers` |

No options, cron entries, jobs, template modifications or public templates.

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

**Each guard was switched off in turn and the suite failed every time**, on 2026-10-10: the two
entity guards, the listing exclusion, its control panel exemption, the profile check,
`hasPassword()`, and both administrator checks in the controller.

## Needs a sandbox

- Install and uninstall from the release zip, on XenForo 2.2 and 2.3.
- A login attempt against a surviving account after uninstall.
- The admin pages rendered in a browser.
- With Archive Site installed: the listing exclusion, and the `validate` warning.
