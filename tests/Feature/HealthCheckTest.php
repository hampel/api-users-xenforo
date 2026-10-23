<?php namespace Tests\Feature;

use Hampel\ApiUsers\Service\HealthCheck;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Each test breaks one thing behind the entity's back, the way a write that bypasses the guards
 * would, and expects exactly that check to say so.
 */
class HealthCheckTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function setUp(): void
	{
		parent::setUp();

		$this->setAsideForumApiUsers();
	}

	/** @phpstan-impure */
	private function statuses()
	{
		$results = $this->app()->service('Hampel\ApiUsers:HealthCheck')->run();

		return array_column($results, 'status', 'check');
	}

	private function detail($check)
	{
		$results = $this->app()->service('Hampel\ApiUsers:HealthCheck')->run();

		return array_column($results, 'detail', 'check')[$check];
	}

	private function giveKey($userId, $active = true)
	{
		$key = $this->app()->em()->create('XF:ApiKey');
		$key->bulkSet([
			'title' => 'Planted for a test',
			'active' => $active,
			'user_id' => $userId,
			'is_super_user' => false,
			'allow_all_scopes' => false,
			'scopes' => [],
			'creation_user_id' => 1,
		]);
		$key->save();
	}

	private function healthyApiUser($admin = false)
	{
		$user = $this->createApiUser($admin);
		$this->giveKey($user->user_id);

		return $user;
	}

	public function test_a_forum_with_no_api_users_is_a_warning_not_a_pass()
	{
		$statuses = $this->statuses();

		$this->assertSame(HealthCheck::WARNING, $statuses['accounts']);
		$this->assertSame(HealthCheck::SKIPPED, $statuses['keys']);
		$this->assertNotContains(HealthCheck::PROBLEM, $statuses);
	}

	public function test_a_healthy_api_user_passes_every_check_about_it()
	{
		$this->healthyApiUser(true);

		$statuses = $this->statuses();
		unset($statuses['archive']);

		$this->assertSame([HealthCheck::OK], array_values(array_unique($statuses)));
	}

	public function test_an_api_user_moved_off_the_scheme_is_a_problem()
	{
		$user = $this->healthyApiUser();
		$this->app()->db()->update('xf_user_authenticate', ['scheme_class' => 'XF:Core12'], 'user_id = ?', $user->user_id);

		$this->assertSame(HealthCheck::PROBLEM, $this->statuses()['scheme']);
		$this->assertStringContainsString($user->username, $this->detail('scheme'));
	}

	public function test_an_api_user_with_no_authentication_row_is_a_problem()
	{
		$user = $this->healthyApiUser();
		$this->app()->db()->delete('xf_user_authenticate', 'user_id = ?', $user->user_id);

		$this->assertSame(HealthCheck::PROBLEM, $this->statuses()['scheme']);
	}

	public function test_an_account_on_the_scheme_that_is_not_recorded_is_a_problem()
	{
		$user = $this->healthyApiUser();
		$this->app()->db()->delete('xf_hampel_apiusers_user', 'user_id = ?', $user->user_id);

		$this->assertSame(HealthCheck::PROBLEM, $this->statuses()['unrecorded']);
		$this->assertStringContainsString($user->username, $this->detail('unrecorded'));
	}

	public function test_an_api_user_given_an_email_address_is_a_problem()
	{
		$user = $this->healthyApiUser();
		$this->app()->db()->update('xf_user', ['email' => 'slipped@example.invalid'], 'user_id = ?', $user->user_id);

		$this->assertSame(HealthCheck::PROBLEM, $this->statuses()['email']);
	}

	public function test_an_api_user_with_a_connected_account_is_a_problem()
	{
		$user = $this->healthyApiUser();
		$this->app()->db()->insert('xf_user_connected_account', [
			'user_id' => $user->user_id, 'provider' => 'github', 'provider_key' => 'apiusers-test-' . $user->user_id, 'extra_data' => '[]',
		]);

		$this->assertSame(HealthCheck::PROBLEM, $this->statuses()['logins']);
		$this->assertStringContainsString($user->username, $this->detail('logins'));
	}

	public function test_an_api_user_in_a_follow_either_way_round_is_a_warning()
	{
		$this->assertSame(HealthCheck::OK, $this->statuses()['follows']);

		$follower = $this->healthyApiUser();
		$followed = $this->healthyApiUser();
		$member = $this->createMember();
		$db = $this->app()->db();
		$db->insert('xf_user_follow', ['user_id' => $follower->user_id, 'follow_user_id' => $member->user_id, 'follow_date' => 1]);
		$db->insert('xf_user_follow', ['user_id' => $member->user_id, 'follow_user_id' => $followed->user_id, 'follow_date' => 1]);

		$this->assertSame(HealthCheck::WARNING, $this->statuses()['follows']);
		$this->assertStringContainsString($follower->username, $this->detail('follows'));
		$this->assertStringContainsString($followed->username, $this->detail('follows'));
	}

	public function test_a_user_edit_modification_that_stops_matching_is_a_warning()
	{
		$this->assertSame(HealthCheck::OK, $this->statuses()['modifications']);

		$id = $this->app()->db()->fetchOne(
			'SELECT modification_id FROM xf_template_modification WHERE modification_key = ?', 'hampelApiUsersUserEditEmail'
		);
		$this->app()->db()->update('xf_template_modification_log', ['apply_count' => 0], 'modification_id = ?', $id);

		$this->assertSame(HealthCheck::WARNING, $this->statuses()['modifications']);
		$this->assertStringContainsString('hampelApiUsersUserEditEmail', $this->detail('modifications'));
	}

	public function test_an_add_on_with_no_modifications_registered_is_a_warning()
	{
		$db = $this->app()->db();
		$ids = $db->fetchAllColumn('SELECT modification_id FROM xf_template_modification WHERE addon_id = ?', 'Hampel/ApiUsers');
		$this->assertCount(5, $ids);
		foreach ($ids AS $id)
		{
			$db->delete('xf_template_modification', 'modification_id = ?', $id);
		}

		$this->assertSame(HealthCheck::WARNING, $this->statuses()['modifications']);
		$this->assertStringContainsString('None registered', $this->detail('modifications'));
	}

	/** The control panel lists API users on purpose, so the listing check has nothing to prove there. */
	public function test_the_listing_check_is_skipped_in_the_control_panel()
	{
		$this->setAppClassType('admin');

		$this->assertSame(HealthCheck::SKIPPED, $this->statuses()['listings']);
	}

	public function test_a_banned_or_invalid_api_user_is_a_warning()
	{
		$user = $this->healthyApiUser();
		$db = $this->app()->db();

		$db->update('xf_user', ['is_banned' => 1], 'user_id = ?', $user->user_id);
		$this->assertSame(HealthCheck::WARNING, $this->statuses()['state']);

		$db->update('xf_user', ['is_banned' => 0, 'user_state' => 'disabled'], 'user_id = ?', $user->user_id);
		$this->assertSame(HealthCheck::WARNING, $this->statuses()['state']);
	}

	public function test_an_administrator_flag_with_no_administrator_record_is_a_problem()
	{
		$user = $this->healthyApiUser(true);
		$this->app()->db()->delete('xf_admin', 'user_id = ?', $user->user_id);

		$this->assertSame(HealthCheck::PROBLEM, $this->statuses()['admins']);
	}

	public function test_an_api_user_with_no_active_key_is_a_warning()
	{
		$user = $this->createApiUser();
		$this->assertSame(HealthCheck::WARNING, $this->statuses()['keys']);

		$this->giveKey($user->user_id, false);
		$this->assertSame(HealthCheck::WARNING, $this->statuses()['keys']);

		$this->giveKey($user->user_id);
		$this->assertSame(HealthCheck::OK, $this->statuses()['keys']);
	}

	public function test_a_record_whose_account_is_gone_is_a_warning()
	{
		$this->app()->db()->insert('xf_hampel_apiusers_user', ['user_id' => 2147480000, 'created_date' => 1]);

		$this->assertSame(HealthCheck::WARNING, $this->statuses()['accounts']);
		$this->assertStringContainsString('2147480000', $this->detail('accounts'));
	}

	public function test_a_missing_table_is_a_problem_and_does_not_end_the_run()
	{
		$this->fakesErrors();

		$check = new class($this->app()) extends HealthCheck {
			const TABLE = 'xf_hampel_apiusers_no_such_table';
		};

		$results = $check->run();
		$statuses = array_column($results, 'status', 'check');

		$this->assertCount(count(HealthCheck::CHECKS), $results);
		$this->assertSame(HealthCheck::PROBLEM, $statuses['table']);
	}

	public function test_a_check_that_throws_is_a_problem_logged_with_the_add_on_prefix()
	{
		$this->fakesErrors();

		$check = new class($this->app()) extends HealthCheck {
			public function checkEmail()
			{
				throw new \RuntimeException('the check itself broke');
			}
		};

		$statuses = array_column($check->run(), 'status', 'check');

		$this->assertSame(HealthCheck::PROBLEM, $statuses['email']);
		$this->assertArrayHasKey('keys', $statuses);
		$this->assertExceptionLogged(\RuntimeException::class, HealthCheck::ERROR_LOG_PREFIX);
	}

	public function test_archive_site_is_skipped_where_it_is_not_installed()
	{
		$this->healthyApiUser();

		$check = new class($this->app()) extends HealthCheck {
			protected function archiveSiteInstalled()
			{
				return false;
			}
		};

		$this->assertSame(HealthCheck::SKIPPED, array_column($check->run(), 'status', 'check')['archive']);
	}

	public function test_an_api_user_missing_from_the_archive_protected_list_is_a_warning()
	{
		$user = $this->healthyApiUser();

		$check = new class($this->app()) extends HealthCheck {
			protected function archiveSiteInstalled()
			{
				return true;
			}
		};

		$this->setConfig('archiveSite', ['protectedUsers' => []]);
		$statuses = array_column($check->run(), 'status', 'check');
		$this->assertSame(HealthCheck::WARNING, $statuses['archive']);

		$this->setConfig('archiveSite', ['protectedUsers' => [$user->user_id]]);
		$statuses = array_column($check->run(), 'status', 'check');
		$this->assertSame(HealthCheck::OK, $statuses['archive']);
	}

	public function test_the_older_flat_archive_key_is_honoured_too()
	{
		$user = $this->healthyApiUser();

		$check = new class($this->app()) extends HealthCheck {
			protected function archiveSiteInstalled()
			{
				return true;
			}
		};

		$this->setConfig('archiveSiteProtectedUsers', [$user->user_id]);

		$this->assertSame(HealthCheck::OK, array_column($check->run(), 'status', 'check')['archive']);
	}
}
