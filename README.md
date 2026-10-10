# API Users: accounts restricted to API access, for XenForo

A XenForo add-on that creates user accounts which exist only to hold an API key. An API user has
no password and no email address, cannot log in, and is not shown to the public as a member. For
an administrator who wants a script, a monitor or an integration to act through the REST API as
its own account, without that account also being a way into the forum.

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

## Requirements

- XenForo 2.2.0 or later
- PHP 7.4 or later

## Installation

Install the release zip through the admin control panel, or unpack it and run:

```bash
php cmd.php xf:addon-install Hampel/ApiUsers
```

The add-on creates one table, `xf_hampel_apiusers_user`, and removes it when uninstalled. It adds
no columns to XenForo's own tables.

## What an API user is

**A real, valid user account, recorded as an API user by this add-on.** It is not identified by
having no password: XenForo creates passwordless accounts itself, for a registration through a
connected account, and those are left alone.

| an API user | how |
|---|---|
| cannot log in | its own authentication scheme, which refuses every password |
| cannot be given a password | refused on save, by any route: the user edit form, the REST API, a password reset |
| cannot be given an email address | refused on save, by any route |
| is not listed as a member | left out of the member list, member counts, newest members, statistics and name lookups |
| has no public profile | viewable only by staff who can bypass user privacy |
| is still found in the control panel | user search and the username autocomplete both find it |

**Hiding the account does not hide what it writes.** An API user that creates a thread through the
API is named on it, like any member.

## Creating one

### In the control panel

*Setup > API users* lists them and has an *Add API user* button. It needs the admin permission
**Manage API users**, which is granted separately from managing users.

Ticking *Make this account an administrator* also needs the **Manage administrators** permission
and your own password, as adding any administrator does.

### From the command line

```bash
php cmd.php api-users:create "Monitor" --note="what it is for"
php cmd.php api-users:create "Monitor" --admin
php cmd.php api-users:list
```

### Then give it a key

An API user can do nothing until an API key names it. Under *Setup > API keys*, add a key of type
**User key** with the API user's name.

## Administrators

**An API user made an administrator holds no admin permissions.** Grant the ones it needs under
*Groups & permissions > Administrators*, as for any administrator. Editing it there does not give
it a password.

## Checking

```bash
php cmd.php api-users:validate
```

Reports whether every API user still has no password and no email address, is hidden, and is able
to act — and whether anything has changed an account behind the add-on's back.

| | all fine | warnings only | any failure |
|---|---|---|---|
| `api-users:validate` | 0 | 0 | 1 |
| `api-users:validate --strict` | 0 | 2 | 1 |

**1 always means a check failed.** This is not the monitoring-plugin numbering, where 1 is a
warning and 2 is critical. `--unattended` is accepted and changes nothing: the command neither
writes nor sends.

## Limits

- **The guards are on the entity.** A write that bypasses it — a direct database edit, an importer
  — is not refused. `api-users:validate` reports the result as a failure.
- **A disabled add-on does not guard.** While it is disabled an API user still cannot log in, but
  the user edit form can give it a password. Run `api-users:validate` after enabling it again.
- **Existing accounts cannot be converted**, in either direction. Create an API user, or delete one.

## With Archive Site

[Archive Site](https://xenforo.com/community/resources/archive-site.7824/) archives every account
it has not been told to protect, by removing its password and disabling it. An API user refuses
the first, which stops the archive run at that account. List each API user in Archive Site's
protected users before archiving:

```php
$config['archiveSite'] = [
    'protectedUsers' => [42],   // the API user's user_id, from api-users:list
];
```

`api-users:validate` warns about any API user that is not protected.

## Uninstalling

Uninstalling moves every API user to XenForo's own passwordless scheme and removes the table. The
accounts remain, still unable to log in, but are no longer hidden or guarded: delete any you do
not want kept.

## Licence

MIT. See `LICENSE.md`.
