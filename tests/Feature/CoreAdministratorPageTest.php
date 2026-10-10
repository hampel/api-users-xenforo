<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * An API administrator is created holding no permissions, so the ordinary way to grant any is to
 * edit it on core's own Administrators page. That page's save resets the password of any
 * administrator whose scheme answers hasPassword() false, and mails a confirmation - on every
 * save, not only on adding one. These tests drive core's controller to prove the API-only scheme
 * is left alone by it, which is the reason the scheme reports a password at all.
 */
class CoreAdministratorPageTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const PASSWORD = 'the acting administrator password';

	private function actingAsSuperAdmin()
	{
		$admin = $this->actingAsAdministrator([], ['is_super_admin' => true]);

		$auth = $this->app()->em()->create('XF:UserAuth');
		$auth->user_id = $admin->user_id;
		$auth->setPassword(self::PASSWORD);
		$admin->hydrateRelation('Auth', $auth);

		return $admin;
	}

	private function saveAdministrator($userId, array $permissions)
	{
		return $this->dispatch('admins/' . $userId . '/save', 'admin', [
			'visitor_password' => self::PASSWORD,
			'permission_cache' => $permissions,
			'extra_user_group_ids' => [],
		], [], 'POST');
	}

	public function test_granting_permissions_on_core_page_leaves_it_api_only_and_sends_no_mail()
	{
		$this->fakesMail();
		$this->actingAsSuperAdmin();
		$user = $this->createApiUser(true);

		$reply = $this->saveAdministrator($user->user_id, ['viewLogs', 'viewStatistics']);

		$this->assertReplyIsRedirect($reply);
		$this->assertIsApiOnly($user->user_id);
		$this->assertNoMailSent();
		$this->assertEquals(0, $this->app()->db()->fetchOne(
			'SELECT COUNT(*) FROM xf_user_confirmation WHERE user_id = ?', $user->user_id
		));

		// and the edit itself took: this is the route by which it gets its permissions
		$granted = json_decode($this->app()->db()->fetchOne(
			'SELECT permission_cache FROM xf_admin WHERE user_id = ?', $user->user_id
		), true);
		$this->assertSame(['viewLogs' => true, 'viewStatistics' => true], $granted);
	}

	/** An API user that is not yet an administrator, added as one through core's own form. */
	public function test_adding_an_api_user_as_an_administrator_on_core_page_leaves_it_api_only()
	{
		$this->fakesMail();
		$this->actingAsSuperAdmin();
		$user = $this->createApiUser();

		$reply = $this->dispatch('admins/save', 'admin', [
			'username' => $user->username,
			'visitor_password' => self::PASSWORD,
			'permission_cache' => ['viewLogs'],
			'extra_user_group_ids' => [],
		], [], 'POST');

		$this->assertReplyIsRedirect($reply);
		$this->assertIsApiOnly($user->user_id);
		$this->assertNoMailSent();
		$this->assertEquals(1, $this->app()->db()->fetchOne('SELECT is_admin FROM xf_user WHERE user_id = ?', $user->user_id));
	}
}
