<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class AdminControllerTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const PASSWORD = 'the acting administrator password';

	/**
	 * An administrator holding the given admin permissions, with a password of their own so the
	 * confirmation can be answered without reading anybody's real one.
	 */
	private function admin(array $permissions)
	{
		$admin = $this->actingAsAdministrator(array_fill_keys($permissions, true));

		$auth = $this->app()->em()->create('XF:UserAuth');
		$auth->user_id = $admin->user_id;
		$auth->setPassword(self::PASSWORD);
		$admin->hydrateRelation('Auth', $auth);

		return $admin;
	}

	private function save(array $input)
	{
		return $this->dispatch('api-users/save', 'admin', $input, [], 'POST');
	}

	private function userIdOf($username)
	{
		return $this->app()->db()->fetchOne('SELECT user_id FROM xf_user WHERE username = ?', $username);
	}

	public function test_every_page_needs_the_manage_api_users_permission()
	{
		// managing users, and even managing administrators, is not enough
		$this->admin(['user', 'admin']);

		$this->assertReplyIsError($this->dispatch('api-users', 'admin'), 403);
		$this->assertReplyIsError($this->dispatch('api-users/add', 'admin'), 403);
		$this->assertReplyIsError($this->save(['username' => $this->uniqueName('acp')]), 403);
	}

	public function test_the_list_shows_the_api_users_and_their_key_counts()
	{
		$this->admin(['hampelApiUsers']);
		$user = $this->createApiUser();

		$reply = $this->dispatch('api-users', 'admin');

		$this->assertReplyIsView($reply);
		$this->assertReplyTemplate($reply, 'hampel_apiusers_list');
		$this->assertArrayHasKey($user->user_id, $reply->getParam('apiUsers')->toArray());
		$this->assertArrayNotHasKey($user->user_id, $reply->getParam('apiKeyCounts'));
	}

	public function test_saving_creates_an_api_user_recorded_against_the_acting_administrator()
	{
		$admin = $this->admin(['hampelApiUsers']);
		$name = $this->uniqueName('acp');

		$reply = $this->save(['username' => $name, 'note' => 'made in the control panel']);

		$this->assertReplyIsRedirect($reply);

		$userId = $this->userIdOf($name);
		$this->assertIsApiOnly($userId);

		$row = $this->app()->db()->fetchRow('SELECT * FROM xf_hampel_apiusers_user WHERE user_id = ?', $userId);
		$this->assertSame('made in the control panel', $row['note']);
		$this->assertEquals($admin->user_id, $row['created_by_user_id']);
		$this->assertFalse($this->app()->db()->fetchOne('SELECT user_id FROM xf_admin WHERE user_id = ?', $userId));
	}

	public function test_a_name_in_use_is_an_error()
	{
		$this->admin(['hampelApiUsers']);
		$existing = $this->createApiUser();

		$this->assertReplyIsError($this->save(['username' => $existing->username]));
	}

	/** Manage API users must not be a second door to minting administrators. */
	public function test_making_an_administrator_needs_the_manage_administrators_permission_as_well()
	{
		$this->admin(['hampelApiUsers']);
		$name = $this->uniqueName('acp');

		$reply = $this->save(['username' => $name, 'is_admin' => 1, 'visitor_password' => self::PASSWORD]);

		$this->assertReplyIsError($reply, 403);
		$this->assertFalse($this->userIdOf($name));
	}

	public function test_making_an_administrator_needs_the_acting_administrator_password()
	{
		$this->admin(['hampelApiUsers', 'admin']);
		$name = $this->uniqueName('acp');

		$this->assertReplyIsError($this->save(['username' => $name, 'is_admin' => 1]));
		$this->assertReplyIsError($this->save(['username' => $name, 'is_admin' => 1, 'visitor_password' => 'wrong']));
		$this->assertFalse($this->userIdOf($name));
	}

	public function test_with_both_it_creates_an_administrator_holding_no_permissions()
	{
		$this->admin(['hampelApiUsers', 'admin']);
		$name = $this->uniqueName('acp');

		$reply = $this->save(['username' => $name, 'is_admin' => 1, 'visitor_password' => self::PASSWORD]);

		$this->assertReplyIsRedirect($reply);
		$this->assertSame('[]', $this->app()->db()->fetchOne(
			'SELECT permission_cache FROM xf_admin WHERE user_id = ?', $this->userIdOf($name)
		));
	}

	public function test_the_add_form_offers_the_administrator_option_only_to_those_who_may_use_it()
	{
		$this->admin(['hampelApiUsers']);
		$this->assertFalse($this->dispatch('api-users/add', 'admin')->getParam('canMakeAdmin'));

		$this->admin(['hampelApiUsers', 'admin']);
		$this->assertTrue($this->dispatch('api-users/add', 'admin')->getParam('canMakeAdmin'));
	}
}
