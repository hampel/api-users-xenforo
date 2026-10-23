<?php namespace Tests\Feature;

use Hampel\ApiUsers\Cli\Command\Create;
use Hampel\ApiUsers\Cli\Command\ListUsers;
use Hampel\ApiUsers\Cli\Command\Validate;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class CommandsTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * @param string $username
	 * @return int|false
	 */
	private function userIdOf($username)
	{
		$userId = $this->app()->db()->fetchOne('SELECT user_id FROM xf_user WHERE username = ?', $username);

		return $userId === false ? false : $this->asInt($userId);
	}

	public function test_create_makes_an_api_user(): void
	{
		$name = $this->uniqueName('cli');

		$tester = $this->runConsoleCommand(Create::class, ['username' => $name, '--note' => 'made in a test']);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString($name, $tester->getDisplay());

		$userId = $this->userIdOf($name);
		$this->assertNotFalse($userId, 'No account was made');
		$this->assertIsApiOnly($userId);
		$this->assertSame('made in a test', $this->app()->db()->fetchOne(
			'SELECT note FROM xf_hampel_apiusers_user WHERE user_id = ?', $userId
		));
		$this->assertFalse($this->app()->db()->fetchOne('SELECT user_id FROM xf_admin WHERE user_id = ?', $userId));
	}

	public function test_create_with_admin_makes_an_administrator_with_no_permissions(): void
	{
		$name = $this->uniqueName('cli');

		$tester = $this->runConsoleCommand(Create::class, ['username' => $name, '--admin' => true]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertSame('[]', $this->app()->db()->fetchOne(
			'SELECT permission_cache FROM xf_admin WHERE user_id = ?', $this->userIdOf($name)
		));
	}

	public function test_create_refuses_a_name_in_use_and_exits_1(): void
	{
		$existing = $this->createApiUser();

		$tester = $this->runConsoleCommand(Create::class, ['username' => $existing->username]);

		$this->assertSame(1, $tester->getStatusCode());
	}

	public function test_list_shows_each_api_user_and_changes_nothing(): void
	{
		$user = $this->createApiUser(true, 'listed in a test');
		$before = $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_hampel_apiusers_user');

		$tester = $this->runConsoleCommand(ListUsers::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString($user->username, $tester->getDisplay());
		$this->assertStringContainsString('listed in a test', $tester->getDisplay());
		$this->assertEquals($before, $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_hampel_apiusers_user'));
	}

	public function test_list_says_so_when_there_are_none(): void
	{
		$this->setAsideForumApiUsers();

		$tester = $this->runConsoleCommand(ListUsers::class);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('There are no API users.', $tester->getDisplay());
	}

	/** A skipped check is shown as one, and is not counted as a failure. */
	public function test_validate_shows_a_skipped_check_as_skipped(): void
	{
		$this->setAsideForumApiUsers();
		$this->setAppClassType('admin');

		$tester = $this->runConsoleCommand(Validate::class);

		$this->assertMatchesRegularExpression('/\[    \] .*Not checked from the control panel/', $tester->getDisplay());
		$this->assertStringNotContainsString('[fail]', $tester->getDisplay());
		$this->assertNotSame(1, $tester->getStatusCode());
	}

	/**
	 * A new API user has no key yet, which is a warning: 0 by default so a gate can rely on it,
	 * 2 under --strict so a monitor hears of it.
	 */
	public function test_validate_exits_0_on_a_warning_and_2_under_strict(): void
	{
		$this->createApiUser();

		$plain = $this->runConsoleCommand(Validate::class);
		$strict = $this->runConsoleCommand(Validate::class, ['--strict' => true]);

		$this->assertStringContainsString('[warn]', $plain->getDisplay());
		$this->assertStringNotContainsString('[fail]', $plain->getDisplay());
		$this->assertSame(0, $plain->getStatusCode());
		$this->assertSame(2, $strict->getStatusCode());
	}

	public function test_validate_exits_1_on_a_failure_with_or_without_strict(): void
	{
		$user = $this->createApiUser();
		$this->app()->db()->update('xf_user', ['email' => 'slipped@example.invalid'], 'user_id = ?', $user->user_id);

		$plain = $this->runConsoleCommand(Validate::class);
		$strict = $this->runConsoleCommand(Validate::class, ['--strict' => true]);

		$this->assertStringContainsString('[fail]', $plain->getDisplay());
		$this->assertSame(1, $plain->getStatusCode());
		$this->assertSame(1, $strict->getStatusCode());
	}

	public function test_validate_accepts_unattended_and_writes_nothing(): void
	{
		$user = $this->createApiUser();
		$before = $this->app()->db()->fetchRow('SELECT * FROM xf_user_authenticate WHERE user_id = ?', $user->user_id);

		$tester = $this->runConsoleCommand(Validate::class, ['--unattended' => true]);

		$this->assertContains($tester->getStatusCode(), [0, 1]);
		$this->assertSame($before, $this->app()->db()->fetchRow('SELECT * FROM xf_user_authenticate WHERE user_id = ?', $user->user_id));
	}
}
