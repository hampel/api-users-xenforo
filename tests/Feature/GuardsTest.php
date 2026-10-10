<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * An API user can never be given a password or an email address, by any route that saves the
 * entity - and an ordinary member is left exactly as core treats them.
 */
class GuardsTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_a_password_cannot_be_set()
	{
		$user = $this->createApiUser();
		$auth = $this->app()->em()->find('XF:UserAuth', $user->user_id);

		$auth->setPassword('correct horse battery staple');

		$this->assertFalse($auth->save(false));
		$this->assertArrayHasKey('password', $auth->getErrors());
		$this->assertIsApiOnly($user->user_id);
	}

	/** What Hampel/ArchiveSite does to every account it archives, and core to a connected account. */
	public function test_it_cannot_be_moved_to_core_passwordless_scheme_either()
	{
		$user = $this->createApiUser();
		$auth = $this->app()->em()->find('XF:UserAuth', $user->user_id);

		$auth->setNoPassword();

		$this->assertFalse($auth->save(false));
		$this->assertIsApiOnly($user->user_id);
	}

	/** The route a lost-password confirmation and core's administrator save both take. */
	public function test_a_password_reset_cannot_give_it_one()
	{
		$user = $this->createApiUser();
		$auth = $this->app()->em()->find('XF:UserAuth', $user->user_id);

		$auth->resetPassword();

		$this->assertFalse($auth->save(false));
		$this->assertIsApiOnly($user->user_id);
	}

	public function test_an_email_address_cannot_be_set_even_by_an_administrator()
	{
		$user = $this->createApiUser();
		$user->setOption('admin_edit', true);

		$user->email = 'someone@example.invalid';

		$this->assertFalse($user->save(false));
		$this->assertArrayHasKey('email', $user->getErrors());
		$this->assertSame('', $this->app()->db()->fetchOne('SELECT email FROM xf_user WHERE user_id = ?', $user->user_id));
	}

	public function test_other_changes_to_an_api_user_still_save()
	{
		$user = $this->createApiUser();

		$user->custom_title = 'Monitor';

		$this->assertTrue($user->save(false));
	}

	public function test_an_ordinary_member_can_change_password_and_email_address()
	{
		$member = $this->createMember();

		$member->Auth->setPassword('another long password');
		$this->assertTrue($member->Auth->save(false));

		$member->setOption('admin_edit', true);
		$member->email = 'changed@example.invalid';
		$this->assertTrue($member->save(false));
	}

	public function test_nobody_can_log_in_as_an_api_user()
	{
		$user = $this->createApiUser();

		/** @var \XF\Service\User\Login $login */
		$login = $this->app()->service('XF:User\Login', $user->username, '192.0.2.10');

		$this->assertNull($login->validate('anything at all', $error));
		$this->assertNotEmpty((string) $error);
	}

	/** Core finds the account by email address, and refuses one that has none. */
	public function test_a_lost_password_request_cannot_be_started()
	{
		$user = $this->createApiUser();

		/** @var \XF\Service\User\PasswordReset $reset */
		$reset = $this->app()->service('XF:User\PasswordReset', $user);

		$this->assertFalse($reset->canTriggerConfirmation($error));
	}
}
