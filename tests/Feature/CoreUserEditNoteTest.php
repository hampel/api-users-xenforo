<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The note field on core's user edit page, saved through core's own controller: the same service as
 * this add-on's edit page, behind the same permission.
 */
class CoreUserEditNoteTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * Core's save with the account's own values, as its form would post them unchanged.
	 *
	 * @param \XF\Entity\User $user
	 * @param array<string, mixed> $extra
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	private function saveUser(\XF\Entity\User $user, array $extra = [])
	{
		return $this->dispatch('users/' . $user->user_id . '/save', 'admin', $extra + [
			'user' => [
				'username' => $user->username,
				'user_group_id' => $user->user_group_id,
				'user_state' => $user->user_state,
				'timezone' => $user->timezone,
				'language_id' => $user->language_id,
				'style_id' => $user->style_id,
				'visible' => 1,
				'activity_visible' => 1,
			],
		], [], 'POST');
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

	public function test_saving_core_page_changes_the_note(): void
	{
		$this->actingAsAdministrator(['user' => true, 'hampelApiUsers' => true]);
		$user = $this->createApiUser(false, 'the nightly export');

		$reply = $this->saveUser($user, ['api_user_note' => '  the hourly export ']);

		$this->assertReplyIsRedirect($reply);
		$this->assertSame('the hourly export', $this->noteOf($this->idOf($user)));
		$this->assertIsApiOnly($this->idOf($user));
	}

	public function test_an_empty_field_clears_the_note(): void
	{
		$this->actingAsAdministrator(['user' => true, 'hampelApiUsers' => true]);
		$user = $this->createApiUser(false, 'the nightly export');

		$this->assertReplyIsRedirect($this->saveUser($user, ['api_user_note' => '']));
		$this->assertSame('', $this->noteOf($this->idOf($user)));
	}

	/** Core's form saves as a whole, so a note that is refused stops the rest of the save too. */
	public function test_a_note_over_255_characters_refuses_the_whole_save(): void
	{
		$this->actingAsAdministrator(['user' => true, 'hampelApiUsers' => true]);
		$user = $this->createApiUser(false, 'the nightly export');
		$before = $user->username;

		$reply = $this->saveUser($user, [
			'api_user_note' => str_repeat('x', 256),
			'user' => [
				'username' => 'renamed api user',
				'user_group_id' => $user->user_group_id,
				'user_state' => $user->user_state,
				'timezone' => $user->timezone,
				'language_id' => $user->language_id,
				'style_id' => $user->style_id,
			],
		]);

		$this->assertReplyIsError($reply, null, \XF::phrase('hampel_apiusers_note_is_too_long')->render('raw'));
		$this->assertSame('the nightly export', $this->noteOf($this->idOf($user)));
		$this->assertSame($before, $this->app()->db()->fetchOne('SELECT username FROM xf_user WHERE user_id = ?', $user->user_id));
	}

	/** A save without the field, as core's page posts it with the field left out, leaves the note. */
	public function test_a_save_without_the_field_leaves_the_note(): void
	{
		$this->actingAsAdministrator(['user' => true, 'hampelApiUsers' => true]);
		$user = $this->createApiUser(false, 'the nightly export');

		$this->assertReplyIsRedirect($this->saveUser($user));
		$this->assertSame('the nightly export', $this->noteOf($this->idOf($user)));
	}

	/** The field is not on the page without Manage API users, and a crafted one is ignored. */
	public function test_without_manage_api_users_the_note_is_left_alone(): void
	{
		$this->actingAsAdministrator(['user' => true]);
		$user = $this->createApiUser(false, 'the nightly export');

		$this->assertReplyIsRedirect($this->saveUser($user, ['api_user_note' => 'changed']));
		$this->assertSame('the nightly export', $this->noteOf($this->idOf($user)));
	}

	/** A member has no row, and the field does nothing for one. */
	public function test_a_member_gains_no_row(): void
	{
		$this->actingAsAdministrator(['user' => true, 'hampelApiUsers' => true]);
		$member = $this->createMember();

		$this->saveUser($member, ['api_user_note' => 'sneaked in']);

		$this->assertFalse($this->noteOf($this->idOf($member)));
	}
}
