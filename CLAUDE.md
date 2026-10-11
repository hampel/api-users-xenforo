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

## Converting an account removes every way in, not only the password

**A connected account or a passkey logs its user in whatever the authentication scheme says**, so
`Service\ApiUser\Converter` deletes those, two-step verification, saved logins, pending
confirmations and OAuth tokens before it sets the scheme, and changes `password_date` so existing
sessions are dropped. `HealthCheck::checkOtherLogins()` reports an API user that has acquired
one since.

**It removes follows in both directions as well, which is about hiding and not about logging in.**
A member's *Following* and *Followers* lists are read from `xf_user_follow` and a cache on the
profile, and neither goes through `isValidUser()`. Found on 2026-10-11 by converting a generated
member that followed another: the API user was named on the other member's public profile.
`canFollowUser()` refuses a new follow either way round, and defers to core when the follow
already exists, because core uses the same check to allow removing it.

**What counts as content is core's own map, minus a list of bookkeeping tables.** The map is the
`$updates` property of `XF:User\ContentChange`, the service core uses to rename, merge and delete a
user, extended by add-ons through `user_content_change_init`. Core offers no getter, so it is read
by reflection. `Converter::NOT_CONTENT` lists the tables in it that an unused account can still
have rows in. **A table not on that list counts as content, including one from an add-on nobody
here has heard of** — the unknown case refuses, which is the safe direction for something that
cannot be undone.

**Holding a conversation is on that list, by decision on 2026-10-11.** `xf_conversation_recipient`
and `xf_conversation_user` say an account was sent a conversation, which a forum's welcome message
does to every account that registers; refusing those refused every normally registered account.
Starting a conversation and writing a message are in two other tables and still refuse.

## The user edit page is changed by four template modifications, and only for display

A notice, the *Convert to API user* action, and the email and password fields replaced by a note.
**None of them is the enforcement** — the entity guards are, and they hold with every modification
disabled. Core's save reads an absent email field as empty and an absent password choice as
no change, which is why hiding the fields is safe. The email and password modifications are
regular expressions because the email row differs between XenForo 2.2 and 2.3.
`HealthCheck::checkModifications()` warns when one stops matching.

## No `api-users:config`, by decision

The add-on has no options and no config key of its own, and `api-users:list` already prints its
whole state. `validate` earns its place because drift is invisible anywhere else. Add `config` if
the add-on gains a setting.

## XenForo 2.2

The floor is 2.2.0 and PHP 7.4. Use short class names (`'XF:User'`, `'XF:User\Login'`) and never a
2.3-only `...Repository`/`...Service` class constant; commands extend Symfony's `Command`; no
`strict_types`, no typed parameters on inherited methods.

## `Seed/` and `Factory/` are development files, and a release leaves them out

**They extend the Seeder add-on's classes, which is not a dependency and needs XenForo 2.3 and
PHP 8.1.** Nothing loads either class until `seeder:run` names it, so they are inert on a forum
without that add-on, 2.2 included. `build.json` removes both directories, and `PackagingTest` fails
if it stops doing so. The suite cannot run them, for the same reason they cannot ship.

**The factory builds and records the account, not this add-on's row**, through the same `Creator`
service as the control panel. Cleaning deletes the account, which removes the row; recording the
row would leave the account behind.

## Not done, on purpose

- **No conversion of an API user back into an ordinary account.** That is the hole the guarantee
  exists to close.
- **No `api-users:convert` command.** Conversion is offered on the user edit page only.
- **No granting of admin permissions from the CLI, and no minting of an API key.** Both are
  additive later; core's pages do them today.
