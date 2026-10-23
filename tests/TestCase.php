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
	 * Archive Site's protected list comes from the forum's own config.php, which may set either
	 * key. Both are cleared, so a test that sets neither means neither is set: the nested key
	 * takes precedence, so a test setting only the older flat key would otherwise be overridden by
	 * whatever the forum holds there.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->setConfig('archiveSite', null);
		$this->setConfig('archiveSiteProtectedUsers', null);
	}

	/**
	 * An API user made the way both interfaces make one. Tests using this need
	 * UsesDatabaseTransactions: the account is real and is rolled back.
	 *
	 * @param bool $admin
	 * @param string $note
	 *
	 * @return \XF\Entity\User
	 */
	protected function createApiUser($admin = false, $note = '')
	{
		/** @var \Hampel\ApiUsers\Service\ApiUser\Creator $creator */
		$creator = $this->app()->service('Hampel\ApiUsers:ApiUser\Creator', $this->uniqueName('api'));
		$creator->setNote($note);
		$creator->setAdmin($admin);

		$this->assertTrue($creator->validate($errors), 'An API user could not be created: ' . json_encode($errors, JSON_THROW_ON_ERROR));
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

	/**
	 * A saved user's id. XenForo types it int|null, since a user not yet saved has none.
	 *
	 * @param \XF\Entity\User $user
	 *
	 * @return int
	 */
	protected function idOf($user)
	{
		$this->assertNotNull($user->user_id, 'The user has not been saved');

		return $user->user_id;
	}

	/**
	 * A user that must exist.
	 *
	 * @param int $userId
	 *
	 * @return \XF\Entity\User
	 */
	protected function findUser($userId)
	{
		$user = $this->app()->em()->find('XF:User', $userId);
		$this->assertNotNull($user, "User {$userId} is gone");

		return $user;
	}

	/**
	 * A user's authentication record, which every account made here has.
	 *
	 * @param \XF\Entity\User $user
	 *
	 * @return \XF\Entity\UserAuth
	 */
	protected function authOf($user)
	{
		$this->assertNotNull($user->Auth, "User {$user->user_id} has no authentication record");

		return $user->Auth;
	}

	/**
	 * The forum's own API users are not the suite's business, so a test that counts them sets them
	 * aside for the length of its transaction. Needs UsesDatabaseTransactions. By primary key
	 * only: this database is shared with other add-ons' suites running at the same time, and an
	 * update that scans xf_user_authenticate waits on every row one of them has locked.
	 *
	 * @return void
	 */
	protected function setAsideForumApiUsers()
	{
		$db = $this->app()->db();
		$userIds = $db->fetchAllColumn('SELECT user_id FROM xf_hampel_apiusers_user');
		foreach ($userIds AS $userId)
		{
			$db->update('xf_user_authenticate', ['scheme_class' => 'XF:NoPassword'], 'user_id = ?', $userId);
			$db->delete('xf_hampel_apiusers_user', 'user_id = ?', $userId);
		}
	}

	/**
	 * @param string $kind
	 * @return string
	 */
	protected function uniqueName($kind)
	{
		return 'apiusers_' . $kind . '_' . bin2hex(random_bytes(4));
	}

	/**
	 * @param int $userId
	 * @return string|false
	 */
	protected function schemeOf($userId)
	{
		return $this->app()->db()->fetchOne(
			'SELECT scheme_class FROM xf_user_authenticate WHERE user_id = ?', $userId
		);
	}

	/**
	 * @param int $userId
	 * @return void
	 */
	protected function assertIsApiOnly($userId)
	{
		$this->assertSame(ApiOnly::NAME, $this->schemeOf($userId));
	}
}
