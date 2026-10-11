# Changelog

## 1.0.0 (unreleased)

- First release: API users with their own authentication scheme, guards against a password or an
  email address, hiding from public member listings, an admin page and CLI commands to create and
  list them, and `api-users:validate`.
- The user edit page marks an API user, replaces its email and password fields with a note, and
  offers *Convert to API user* for an account that has never been used as a member.
- Converting an account is no longer refused because it was sent a conversation it never answered,
  such as a forum's welcome message.
