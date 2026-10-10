<?php namespace Hampel\ApiUsers\Authentication;

use XF\Authentication\AbstractAuth;

/**
 * The authentication scheme of an API user: nothing authenticates against it, ever.
 *
 * It is a scheme of its own rather than core's XF:NoPassword for one method. Core reads
 * hasPassword() === false as "this account should be given a password": saving an administrator
 * record resets one and mails a confirmation, and the add-administrator form warns. Core's
 * docblock on hasPassword() says a false answer means the user "can switch away from this auth by
 * requesting a password be emailed". An API user cannot, so the honest answer here is true - and
 * authenticate() refusing everything is what keeps that from being a way in.
 *
 * The name must not contain an underscore: UserAuth::getAuthenticationHandler() rewrites one to a
 * backslash, as a XenForo 1 fallback.
 */
class ApiOnly extends AbstractAuth
{
	const NAME = 'Hampel\ApiUsers:ApiOnly';

	public function generate($password)
	{
		return [];
	}

	public function authenticate($userId, $password)
	{
		return false;
	}

	public function hasPassword()
	{
		return true;
	}

	public function isUpgradable()
	{
		return false;
	}

	public function getAuthenticationName()
	{
		return self::NAME;
	}
}
