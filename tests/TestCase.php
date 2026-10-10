<?php namespace Tests;

use Hampel\ApiUsers\Authentication\ApiOnly;
use Hampel\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	/**
	 * Four levels up because this add-on id has a vendor in it:
	 * src/addons/<Vendor>/<AddOn> -> the install root. No trailing slash.
	 */
	protected $rootDir = '../../../..';

	/**
	 * Load only this add-on. Booting with every add-on registers each one's vendor tree onto
	 * XF's class loader, and PHPUnit classes can then resolve out of another add-on's copy.
	 */
	protected $addonsToLoad = ['Hampel/ApiUsers'];

	/**
	 * An API user made the way both interfaces make one. Tests using this need
	 * UsesDatabaseTransactions: the account is real and is rolled back.
	 *
	 * @return \XF\Entity\User
	 */
	protected function createApiUser($admin = false, $note = '')
	{
		/** @var \Hampel\ApiUsers\Service\ApiUser\Creator $creator */
		$creator = $this->app()->service('Hampel\ApiUsers:ApiUser\Creator', $this->uniqueName('api'));
		$creator->setNote($note);
		$creator->setAdmin($admin);

		$this->assertTrue($creator->validate($errors), 'An API user could not be created: ' . json_encode($errors));
		$creator->save();

		return $creator->getUser();
	}

	/**
	 * An ordinary member with a password and an email address, to prove a guard leaves them alone.
	 *
	 * @return \XF\Entity\User
	 */
	protected function createMember()
	{
		$user = $this->app()->repository('XF:User')->setupBaseUser();
		$user->username = $this->uniqueName('member');
		$user->email = $user->username . '@example.invalid';
		$user->user_state = 'valid';
		$user->getRelationOrDefault('Auth')->setPassword(bin2hex(random_bytes(12)));
		$user->save();

		return $user;
	}

	protected function uniqueName($kind)
	{
		return 'apiusers_' . $kind . '_' . bin2hex(random_bytes(4));
	}

	protected function schemeOf($userId)
	{
		return $this->app()->db()->fetchOne(
			'SELECT scheme_class FROM xf_user_authenticate WHERE user_id = ?', $userId
		);
	}

	protected function assertIsApiOnly($userId)
	{
		$this->assertSame(ApiOnly::NAME, $this->schemeOf($userId));
	}
}
