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
	 *
	 * @param string[] $permissions
	 * @return \XF\Entity\User
	 */
	private function admin(array $permissions)
	{
		$admin = $this->actingAsAdministrator(array_fill_keys($permissions, true));

		$auth = $this->app()->em()->create('XF:UserAuth');
		$auth->user_id = $this->idOf($admin);
		$auth->setPassword(self::PASSWORD);
		$admin->hydrateRelation('Auth', $auth);

		return $admin;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	private function save(array $input)
	{
		return $this->dispatch('api-users/save', 'admin', $input, [], 'POST');
	}

	/**
	 * Core's own wording, so that a test names the guard that refused and not only its code.
	 *
	 * @param string $name
	 * @return string
	 */
	private function corePhrase($name)
	{
		return \XF::phrase($name)->render('raw');
	}

	/**
	 * @param string $username
	 * @return int|false
	 */
	private function userIdOf($username)
	{
		$userId = $this->app()->db()->fetchOne('SELECT user_id FROM xf_user WHERE username = ?', $username);

		return $userId === false ? false : $this->asInt($userId);
	}

	public function test_every_page_needs_the_manage_api_users_permission(): void
	{
		// managing users, and even managing administrators, is not enough
		$this->admin(['user', 'admin']);

		$this->assertReplyIsError($this->dispatch('api-users', 'admin'), 403);
		$this->assertReplyIsError($this->dispatch('api-users/add', 'admin'), 403);
		$this->assertReplyIsError($this->save(['username' => $this->uniqueName('acp')]), 403);
		$this->assertReplyIsError($this->dispatch('api-users/1/edit', 'admin'), 403);
	}

	public function test_the_list_shows_the_api_users_and_their_key_counts(): void
	{
		$this->admin(['hampelApiUsers']);
		$user = $this->createApiUser();

		$reply = $this->dispatch('api-users', 'admin');

		$this->assertReplyTemplate($reply, 'hampel_apiusers_list');
		$apiUsers = $this->replyParam($reply, 'apiUsers');
		$this->assertInstanceOf(\XF\Mvc\Entity\AbstractCollection::class, $apiUsers);
		$this->assertArrayHasKey($user->user_id, $apiUsers->toArray());
		$apiKeyCounts = $this->replyParam($reply, 'apiKeyCounts');
		$this->assertIsArray($apiKeyCounts);
		$this->assertArrayNotHasKey($user->user_id, $apiKeyCounts);
	}

	public function test_saving_creates_an_api_user_recorded_against_the_acting_administrator(): void
	{
		$admin = $this->admin(['hampelApiUsers']);
		$name = $this->uniqueName('acp');

		$reply = $this->save(['username' => $name, 'note' => 'made in the control panel']);

		$this->assertReplyIsRedirect($reply);
		$this->assertStringContainsString($this->app()->router('admin')->buildLink('api-users'), $reply->getUrl());
		$this->assertStringContainsString($name, $this->asText($reply->getMessage()));

		$userId = $this->userIdOf($name);
		$this->assertNotFalse($userId, 'No account was made');
		$this->assertIsApiOnly($userId);

		$row = $this->app()->db()->fetchRow('SELECT * FROM xf_hampel_apiusers_user WHERE user_id = ?', $userId);
		$this->assertIsArray($row);
		$this->assertSame('made in the control panel', $row['note']);
		$this->assertEquals($admin->user_id, $row['created_by_user_id']);
		$this->assertFalse($this->app()->db()->fetchOne('SELECT user_id FROM xf_admin WHERE user_id = ?', $userId));
	}

	public function test_a_name_in_use_is_an_error(): void
	{
		$this->admin(['hampelApiUsers']);
		$existing = $this->createApiUser();

		$this->assertReplyIsError(
			$this->save(['username' => $existing->username]), null, $this->corePhrase('usernames_must_be_unique')
		);
	}

	/** Manage API users must not be a second door to minting administrators. */
	public function test_making_an_administrator_needs_the_manage_administrators_permission_as_well(): void
	{
		$this->admin(['hampelApiUsers']);
		$name = $this->uniqueName('acp');

		$reply = $this->save(['username' => $name, 'is_admin' => 1, 'visitor_password' => self::PASSWORD]);

		$this->assertReplyIsError($reply, 403, 'requires the Manage administrators permission');
		$this->assertFalse($this->userIdOf($name));
	}

	public function test_making_an_administrator_needs_the_acting_administrator_password(): void
	{
		$this->admin(['hampelApiUsers', 'admin']);
		$name = $this->uniqueName('acp');

		$wrong = $this->corePhrase('your_existing_password_is_not_correct');

		$this->assertReplyIsError($this->save(['username' => $name, 'is_admin' => 1]), null, $wrong);
		$this->assertReplyIsError(
			$this->save(['username' => $name, 'is_admin' => 1, 'visitor_password' => 'wrong']), null, $wrong
		);
		$this->assertFalse($this->userIdOf($name));
	}

	public function test_with_both_it_creates_an_administrator_holding_no_permissions(): void
	{
		$this->admin(['hampelApiUsers', 'admin']);
		$name = $this->uniqueName('acp');

		$reply = $this->save(['username' => $name, 'is_admin' => 1, 'visitor_password' => self::PASSWORD]);

		$this->assertReplyIsRedirect($reply);
		$this->assertSame('[]', $this->app()->db()->fetchOne(
			'SELECT permission_cache FROM xf_admin WHERE user_id = ?', $this->userIdOf($name)
		));
	}

	public function test_converting_needs_the_manage_api_users_permission(): void
	{
		$this->admin(['user']);
		$member = $this->createMember();

		$this->assertReplyIsError($this->dispatch('api-users/' . $member->user_id . '/convert', 'admin'), 403);
		$this->assertReplyIsError($this->dispatch('api-users/' . $member->user_id . '/convert', 'admin', [], [], 'POST'), 403);
		$this->assertNotSame('', $this->app()->db()->fetchOne('SELECT email FROM xf_user WHERE user_id = ?', $member->user_id));
	}

	public function test_the_convert_page_asks_first_and_changes_nothing(): void
	{
		$this->admin(['hampelApiUsers']);
		$member = $this->createMember();

		$reply = $this->dispatch('api-users/' . $member->user_id . '/convert', 'admin');

		$this->assertReplyTemplate($reply, 'hampel_apiusers_convert');
		$this->assertSame([], $this->replyParam($reply, 'blockers'));
		$this->assertNotSame('', $this->app()->db()->fetchOne('SELECT email FROM xf_user WHERE user_id = ?', $member->user_id));
	}

	public function test_confirming_converts_the_account_and_records_who_did_it(): void
	{
		$admin = $this->admin(['hampelApiUsers']);
		$member = $this->createMember();

		$reply = $this->dispatch('api-users/' . $member->user_id . '/convert', 'admin', ['note' => 'the old bot'], [], 'POST');

		$this->assertReplyIsRedirect($reply, $this->app()->router('admin')->buildLink('users/edit', $member));
		$this->assertIsApiOnly($this->idOf($member));

		$row = $this->app()->db()->fetchRow('SELECT * FROM xf_hampel_apiusers_user WHERE user_id = ?', $member->user_id);
		$this->assertIsArray($row);
		$this->assertSame('the old bot', $row['note']);
		$this->assertEquals($admin->user_id, $row['created_by_user_id']);
	}

	public function test_an_account_with_content_is_shown_why_and_cannot_be_converted(): void
	{
		$this->admin(['hampelApiUsers']);
		$member = $this->createMember();
		$this->app()->db()->insert('xf_poll_vote', [
			'user_id' => $member->user_id, 'poll_response_id' => 2147480000, 'poll_id' => 2147480000, 'vote_date' => 1,
		]);

		$this->assertNotEmpty($this->replyParam($this->dispatch('api-users/' . $member->user_id . '/convert', 'admin'), 'blockers'));
		$this->assertReplyIsError(
			$this->dispatch('api-users/' . $member->user_id . '/convert', 'admin', [], [], 'POST'),
			null, 'It has been used as a member.'
		);
		$this->assertNotSame('', $this->app()->db()->fetchOne('SELECT email FROM xf_user WHERE user_id = ?', $member->user_id));
	}

	public function test_only_a_super_administrator_can_convert_a_super_administrator(): void
	{
		$this->admin(['hampelApiUsers']);
		$member = $this->createMember();
		$record = $this->app()->em()->create('XF:Admin');
		$record->bulkSet(['user_id' => $member->user_id, 'is_super_admin' => true]);
		$record->save();
		$this->app()->em()->clearEntityCache();

		$this->assertReplyIsError(
			$this->dispatch('api-users/' . $member->user_id . '/convert', 'admin', [], [], 'POST'),
			null, $this->corePhrase('you_must_be_super_administrator_to_edit_user')
		);
		$this->assertNotSame('', $this->app()->db()->fetchOne('SELECT email FROM xf_user WHERE user_id = ?', $member->user_id));
	}

	/** Both writes sit behind XenForo's own request check, which a forged form cannot pass. */
	public function test_neither_write_is_reachable_without_a_csrf_token(): void
	{
		$this->admin(['hampelApiUsers']);
		$member = $this->createMember();
		$name = $this->uniqueName('acp');

		$this->assertReplyIsError($this->dispatchWithoutCsrfToken('api-users/save', 'admin', ['username' => $name]));
		$this->assertFalse($this->userIdOf($name));

		$this->assertReplyIsError($this->dispatchWithoutCsrfToken('api-users/' . $member->user_id . '/convert', 'admin'));
		$this->assertNotSame('', $this->app()->db()->fetchOne('SELECT email FROM xf_user WHERE user_id = ?', $member->user_id));

		// and with one, the same two requests go through
		$this->assertReplyIsRedirect($this->save(['username' => $name]));
		$this->assertReplyIsRedirect($this->dispatch('api-users/' . $member->user_id . '/convert', 'admin', [], [], 'POST'));
	}

	public function test_the_add_form_offers_the_administrator_option_only_to_those_who_may_use_it(): void
	{
		$this->admin(['hampelApiUsers']);
		$this->assertFalse($this->replyParam($this->dispatch('api-users/add', 'admin'), 'canMakeAdmin'));

		$this->admin(['hampelApiUsers', 'admin']);
		$this->assertTrue($this->replyParam($this->dispatch('api-users/add', 'admin'), 'canMakeAdmin'));
	}

	/**
	 * @param int $userId
	 * @return string|false
	 */
	private function noteOf($userId)
	{
		$note = $this->app()->db()->fetchOne('SELECT note FROM xf_hampel_apiusers_user WHERE user_id = ?', $userId);

		return $note === false ? false : $this->asText($note);
	}

	public function test_the_edit_page_shows_the_note(): void
	{
		$this->admin(['hampelApiUsers']);
		$user = $this->createApiUser(false, 'the nightly export');

		$reply = $this->dispatch('api-users/' . $user->user_id . '/edit', 'admin');

		$this->assertReplyTemplate($reply, 'hampel_apiusers_edit');
		$this->assertSame('the nightly export', $this->noteOf($this->idOf($user)));
	}

	public function test_saving_the_edit_page_changes_the_note_and_nothing_else(): void
	{
		$this->admin(['hampelApiUsers']);
		$user = $this->createApiUser(true, 'the nightly export');
		$before = $this->app()->db()->fetchRow('SELECT * FROM xf_hampel_apiusers_user WHERE user_id = ?', $user->user_id);

		$reply = $this->dispatch('api-users/' . $user->user_id . '/edit', 'admin', ['note' => 'the hourly export'], [], 'POST');

		$this->assertReplyIsRedirect($reply, $this->app()->router('admin')->buildLink('api-users'));
		$after = $this->app()->db()->fetchRow('SELECT * FROM xf_hampel_apiusers_user WHERE user_id = ?', $user->user_id);
		$this->assertIsArray($before);
		$this->assertSame(array_merge($before, ['note' => 'the hourly export']), $after);
		$this->assertIsApiOnly($this->idOf($user));
	}

	public function test_an_empty_note_clears_it(): void
	{
		$this->admin(['hampelApiUsers']);
		$user = $this->createApiUser(false, 'the nightly export');

		$this->dispatch('api-users/' . $user->user_id . '/edit', 'admin', ['note' => ''], [], 'POST');

		$this->assertSame('', $this->noteOf($this->idOf($user)));
	}

	public function test_a_note_over_255_characters_is_refused_and_the_old_one_kept(): void
	{
		$this->admin(['hampelApiUsers']);
		$user = $this->createApiUser(false, 'the nightly export');

		$reply = $this->dispatch('api-users/' . $user->user_id . '/edit', 'admin', ['note' => str_repeat('x', 256)], [], 'POST');

		$this->assertReplyIsError($reply, null, $this->corePhrase('hampel_apiusers_note_is_too_long'));
		$this->assertSame('the nightly export', $this->noteOf($this->idOf($user)));
	}

	/** The page edits an API user's record, so a member has no page here. */
	public function test_an_ordinary_member_has_no_edit_page(): void
	{
		$this->admin(['hampelApiUsers']);
		$member = $this->createMember();

		$this->assertReplyIsError($this->dispatch('api-users/' . $member->user_id . '/edit', 'admin'), 404);
		$this->assertReplyIsError($this->dispatch('api-users/' . $member->user_id . '/edit', 'admin', ['note' => 'x'], [], 'POST'), 404);
		$this->assertFalse($this->noteOf($this->idOf($member)));
	}

	public function test_saving_a_note_needs_a_csrf_token(): void
	{
		$this->admin(['hampelApiUsers']);
		$user = $this->createApiUser(false, 'the nightly export');

		$this->assertReplyIsError($this->dispatchWithoutCsrfToken('api-users/' . $user->user_id . '/edit', 'admin', ['note' => 'changed']));
		$this->assertSame('the nightly export', $this->noteOf($this->idOf($user)));
	}
}
