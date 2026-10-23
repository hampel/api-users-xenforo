<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class CreatorTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_it_creates_a_valid_account_with_no_password_and_no_email_address(): void
	{
		$user = $this->createApiUser(false, 'for the monitor');

		$row = $this->app()->db()->fetchRow('SELECT * FROM xf_user WHERE user_id = ?', $user->user_id);

		$this->assertSame('', $row['email']);
		$this->assertSame('valid', $row['user_state']);
		$this->assertEquals(0, $row['is_banned']);
		$this->assertEquals(0, $row['is_admin']);
		$this->assertIsApiOnly($this->idOf($user));
	}

	public function test_it_records_the_account_as_an_api_user(): void
	{
		$this->actingAsMember(['user_id' => 987651]);
		$actor = \XF::visitor();

		$creator = $this->app()->service('Hampel\ApiUsers:ApiUser\Creator', $this->uniqueName('api'));
		$creator->setNote('for the monitor');
		$creator->setCreatedBy($actor);
		$this->assertTrue($creator->validate($errors));
		$apiUser = $creator->save();

		$row = $this->app()->db()->fetchRow('SELECT * FROM xf_hampel_apiusers_user WHERE user_id = ?', $apiUser->user_id);

		$this->assertSame('for the monitor', $row['note']);
		$this->assertEquals(987651, $row['created_by_user_id']);
		$this->assertGreaterThan(0, $row['created_date']);
		$this->assertTrue($creator->getUser()->isApiUser());
	}

	public function test_it_sets_the_account_up_to_be_hidden_even_with_the_add_on_disabled(): void
	{
		$user = $this->createApiUser();

		$row = $this->app()->db()->fetchRow('SELECT visible, activity_visible FROM xf_user WHERE user_id = ?', $user->user_id);
		$privacy = $this->app()->db()->fetchRow('SELECT * FROM xf_user_privacy WHERE user_id = ?', $user->user_id);

		$this->assertEquals(0, $row['visible']);
		$this->assertEquals(0, $row['activity_visible']);
		$this->assertSame('none', $privacy['allow_view_profile']);
		$this->assertSame('none', $privacy['allow_send_personal_conversation']);
	}

	public function test_it_is_not_an_administrator_unless_asked(): void
	{
		$user = $this->createApiUser();

		$this->assertFalse($this->app()->db()->fetchOne('SELECT user_id FROM xf_admin WHERE user_id = ?', $user->user_id));
	}

	public function test_an_administrator_is_created_holding_no_permissions(): void
	{
		$user = $this->createApiUser(true);
		$db = $this->app()->db();

		$admin = $db->fetchRow('SELECT * FROM xf_admin WHERE user_id = ?', $user->user_id);

		$this->assertNotEmpty($admin);
		$this->assertEquals(0, $admin['is_super_admin']);
		$this->assertSame([], json_decode($admin['permission_cache'], true));
		$this->assertEquals(0, $db->fetchOne('SELECT COUNT(*) FROM xf_admin_permission_entry WHERE user_id = ?', $user->user_id));
		$this->assertEquals(1, $db->fetchOne('SELECT is_admin FROM xf_user WHERE user_id = ?', $user->user_id));

		// becoming an administrator must not have cost it the scheme
		$this->assertIsApiOnly($this->idOf($user));
	}

	public function test_a_name_already_in_use_is_refused_and_nothing_is_written(): void
	{
		$existing = $this->createApiUser();
		$before = $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_hampel_apiusers_user');

		$creator = $this->app()->service('Hampel\ApiUsers:ApiUser\Creator', $existing->username);

		$this->assertFalse($creator->validate($errors));
		$this->assertArrayHasKey('username', $errors);
		$this->assertEquals($before, $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_hampel_apiusers_user'));
	}

	public function test_a_note_longer_than_the_column_is_refused(): void
	{
		$creator = $this->app()->service('Hampel\ApiUsers:ApiUser\Creator', $this->uniqueName('api'));
		$creator->setNote(str_repeat('n', 256));

		$this->assertFalse($creator->validate($errors));
		$this->assertArrayHasKey('note', $errors);
	}

	public function test_deleting_the_account_removes_its_record(): void
	{
		$user = $this->createApiUser();
		$userId = $this->idOf($user);

		$user->delete();

		$this->assertFalse($this->app()->db()->fetchOne('SELECT user_id FROM xf_hampel_apiusers_user WHERE user_id = ?', $userId));
	}
}
