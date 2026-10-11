# Changelog

## 1.0.0 Beta 1 (2026-10-11)

First public release.

- An API user is an account with its own authentication scheme: no password, no email address and
  no way to log in. It can act only through an API key that names it.
- A password or an email address is refused for an API user wherever one is set: the user edit
  page, the REST API, or another add-on.
- An API user is not a member to the public. It is left out of member listings, the member count
  and the username autocomplete; its profile is shown only to the account itself and to staff who
  can bypass user privacy; and it cannot follow anyone or be followed.
- API users are created under *Setup > API users*, which needs the new **Manage API users**
  administrator permission, or with `api-users:create`. Either can make the account an
  administrator holding no permissions. `api-users:list` lists them.
- An existing account that has never been used as a member can be converted from its edit page.
  Converting removes its email address, its password, every other way it could log in, and its
  follows. An account with content is refused; a conversation it was only sent does not count.
- The user edit page marks an API user, replaces its email and password fields with a note, and
  leaves out *Merge with user*.
- An API user cannot be merged with another account, in either direction.
- `api-users:validate` checks that every API user is still without a password or an email address,
  hidden, and able to act, and that the add-on's guards are applied. It exits 0 unless a check
  fails, 1 on a failure, and 2 for a warning under `--strict`.
- On a forum that also runs Archive Site, `api-users:validate` warns about an API user missing from
  its protected users.
