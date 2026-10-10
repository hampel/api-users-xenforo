<?php namespace Tests\Feature;

use Hampel\ApiUsers\Cli\Command\Create;
use Hampel\ApiUsers\Cli\Command\ListUsers;
use Hampel\ApiUsers\Cli\Command\Validate;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class CommandsTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function userIdOf($username)
	{
		return $this->app()->db()->fetchOne('SELECT user_id FROM xf_user WHERE username = ?', $username);
	}

	public function test_create_makes_an_api_user()
	{
		$name = $this->uniqueName('cli');

		$tester = $this->runConsoleCommand(Create::class, ['username' => $name, '--note' => 'made in a test']);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString($name, $tester->getDisplay());

		$userId = $this->userIdOf($name);
		$this->assertIsApiOnly($userId);
		$this->assertSame('made in a test', $this->app()->db()->fetchOne(
			'SELECT note FROM xf_hampel_apiusers_user WHERE user_id = ?', $userId
		));
		$this->assertFalse($this->app()->db()->fetchOne('SELECT user_id FROM xf_admin WHERE user_id = ?', $userId));
	}

	public function test_create_with_admin_makes_an_administrator_with_no_permissions()
	{
		$name = $this->uniqueName('cli');

		$tester = $this->runConsoleCommand(Create::class, ['username' => $name, '--admin' => true]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertSame('[]', $this->app()->db()->fetchOne(
			'SELECT permission_cache FROM xf_admin WHERE user_id = ?', $this->userIdOf($name)
		));
	}

	public function test_create_refuses_a_name_in_use_and_exits_1()
	{
		$existing = $this->createApiUser();

		$tester = $this->runConsoleCommand(Create::class, ['username' => $existing->username]);

		$this->assertSame(1, $tester->getStatusCode());
	}

	public function test_list_shows_each_api_user_and_changes_nothing()
	{
		$user = $this->createApiUser(true, 'listed in a test');
		$before = $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_hampel_apiusers_user');

		$tester = $this->runConsoleCommand(ListUsers::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString($user->username, $tester->getDisplay());
		$this->assertStringContainsString('listed in a test', $tester->getDisplay());
		$this->assertEquals($before, $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_hampel_apiusers_user'));
	}

	/**
	 * A new API user has no key yet, which is a warning: 0 by default so a gate can rely on it,
	 * 2 under --strict so a monitor hears of it.
	 */
	public function test_validate_exits_0_on_a_warning_and_2_under_strict()
	{
		$this->createApiUser();

		$plain = $this->runConsoleCommand(Validate::class);
		$strict = $this->runConsoleCommand(Validate::class, ['--strict' => true]);

		$this->assertStringContainsString('[warn]', $plain->getDisplay());
		$this->assertStringNotContainsString('[fail]', $plain->getDisplay());
		$this->assertSame(0, $plain->getStatusCode());
		$this->assertSame(2, $strict->getStatusCode());
	}

	public function test_validate_exits_1_on_a_failure_with_or_without_strict()
	{
		$user = $this->createApiUser();
		$this->app()->db()->update('xf_user', ['email' => 'slipped@example.invalid'], 'user_id = ?', $user->user_id);

		$plain = $this->runConsoleCommand(Validate::class);
		$strict = $this->runConsoleCommand(Validate::class, ['--strict' => true]);

		$this->assertStringContainsString('[fail]', $plain->getDisplay());
		$this->assertSame(1, $plain->getStatusCode());
		$this->assertSame(1, $strict->getStatusCode());
	}

	public function test_validate_accepts_unattended_and_writes_nothing()
	{
		$user = $this->createApiUser();
		$before = $this->app()->db()->fetchRow('SELECT * FROM xf_user_authenticate WHERE user_id = ?', $user->user_id);

		$tester = $this->runConsoleCommand(Validate::class, ['--unattended' => true]);

		$this->assertContains($tester->getStatusCode(), [0, 1]);
		$this->assertSame($before, $this->app()->db()->fetchRow('SELECT * FROM xf_user_authenticate WHERE user_id = ?', $user->user_id));
	}
}
