# Hampel/ApiUsers

Standing notes for a session working on this add-on. `README.md` says what it does and
`TESTING.md` what is checked and how; this file holds the decisions that are not visible in the
code and the traps behind them.

## An API user is a row in this add-on's table, never an inference

**Nothing may identify an API user by its having no password or no email address.** Core creates
passwordless accounts for connected-account registrations, another add-on can have its own
passwordless scheme, and an administrator can blank any email. Every guard, the hiding and the
list page ask the `ApiUser` relation.

**The table is the authority and the scheme is the enforcement.** `api-users:validate` fails when
they disagree in either direction.

## `hasPassword()` returns true on purpose

**Core reads `hasPassword() === false` as "give this account a password".** Measured on 2.3.12 on
2026-10-10 by switching the method and driving core's administrator controller:

| `hasPassword()` | adding the account on core's Administrators page | editing it there |
|---|---|---|
| `true` | saved, scheme untouched, no mail | saved, scheme untouched, no mail |
| `false` | **refused**: "password-less method of logging in but has no associated email address" | saved, scheme untouched, no mail |

So `false` does not corrupt the account — the password guard would refuse that anyway — but it
makes core's own form refuse to add an API user as an administrator. `CoreAdministratorPageTest`
holds this.

## The guards are on the entities, and that is the whole enforcement

`XF\Entity\UserAuth::_preSave()` and `XF\Entity\User::_preSave()`. Every route that sets a password
or an email ends in one of those saves, so there is no per-form check and none should be added.
There are deliberately no `Login` or `PasswordReset` service extensions: core refuses both on its
own once the account has no email and a scheme that never authenticates.

## `isValidUser()` is extended at `execute_order` 20, and stands aside in the control panel

- **Above the default of 10 because an extension that replaces the method without calling its
  parent discards every condition added inside it.** Ties are broken by class name, so this
  add-on would otherwise sit inside most others. `HealthCheck::checkListings()` proves the
  exclusion by reading the built query, not by checking the extension is registered.
- **Not applied under `XF\Admin\App`**, because the control panel's username autocomplete calls
  the same method.
- **Registered against `XF\Finder\User`, the 2.2 class name**, by a hand-written
  `_output/class_extensions` file: `xf-make:extension` refuses that name on 2.3. Its
  `_metadata.json` entry is the file's md5.

## No `api-users:config`, by decision

The add-on has no options and no config key of its own, and `api-users:list` already prints its
whole state. `validate` earns its place because drift is invisible anywhere else. Add `config` if
the add-on gains a setting.

## XenForo 2.2

The floor is 2.2.0 and PHP 7.4. Use short class names (`'XF:User'`, `'XF:User\Login'`) and never a
2.3-only `...Repository`/`...Service` class constant; commands extend Symfony's `Command`; no
`strict_types`, no typed parameters on inherited methods.

## Not done, on purpose

- **No conversion of an existing account**, to or from an API user.
- **No granting of admin permissions from the CLI, and no minting of an API key.** Both are
  additive later; core's pages do them today.
