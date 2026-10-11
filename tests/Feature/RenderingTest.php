<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * What an administrator is actually shown: this add-on's three pages, and core's user edit page
 * with the four modifications applied. The controller tests stop at the reply.
 */
class RenderingTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const PHRASES = 'hampel_apiusers_';
	private const TEMPLATES = 'hampel_apiusers_';

	private const NOTICE = 'This account is an API user.';
	private const NONE = 'None. An API user cannot have one.';
	private const CONVERT = 'Convert to API user';
	private const EMAIL_INPUT = 'name="user[email]"';
	private const PASSWORD_CHOICE = 'name="change_password"';

	protected function setUp(): void
	{
		parent::setUp();

		// a template error is otherwise written to the forum's own error log
		$this->fakesErrors();
	}

	/**
	 * The page body, and the actions menu beside the title. The menu is set through
	 * <xf:pageaction>, so it is a page parameter and no part of the rendered body; it has to be
	 * read before anything else renders.
	 *
	 * @return string[]
	 */
	private function editPage($userId)
	{
		$html = $this->renderReply($this->dispatch("users/{$userId}/edit", 'admin'));

		return [$html, (string) $this->pageParam('pageAction')];
	}

	public function test_the_list_names_each_api_user_with_its_note_and_who_made_it()
	{
		$this->actingAsAdministrator(['hampelApiUsers' => true]);

		// a saved account: the page reads the creator back from the database by its id
		$madeBy = $this->createMember();

		$creator = $this->app()->service('Hampel\ApiUsers:ApiUser\Creator', $this->uniqueName('api'));
		$creator->setNote('the stock sync');
		$creator->setCreatedBy($madeBy);
		$creator->save();
		$user = $creator->getUser();

		$html = $this->renderReply($this->dispatch('api-users', 'admin'));

		$this->assertSame('API users', (string) $this->pageParam('pageTitle'));
		$this->assertStringContainsString('Add API user', (string) $this->pageParam('pageAction'));
		$this->assertSee($html, $user->username);
		$this->assertSee($html, 'the stock sync');
		$this->assertSee($html, 'No API key');
		$this->assertSeeText($html, 'by ' . $madeBy->username);
		$this->assertDontSee($html, 'Not API-only');
		$this->assertNoUnresolvedPhrases($html, self::PHRASES);
		$this->assertNoTemplateErrors(self::TEMPLATES);
	}

	public function test_the_list_marks_an_administrator_and_an_account_whose_scheme_has_drifted()
	{
		$this->actingAsAdministrator(['hampelApiUsers' => true]);
		$user = $this->createApiUser(true);
		$this->app()->db()->update(
			'xf_user_authenticate', ['scheme_class' => 'XF:NoPassword'], 'user_id = ?', $user->user_id
		);
		$this->app()->em()->clearEntityCache();

		$html = $this->renderReply($this->dispatch('api-users', 'admin'));

		$this->assertSee($html, $user->username);
		$this->assertSee($html, 'Not API-only');
		$this->assertSee($html, 'Command line');
		$this->assertNoUnresolvedPhrases($html, self::PHRASES);
		$this->assertNoTemplateErrors(self::TEMPLATES);
	}

	public function test_the_add_form_shows_the_administrator_option_only_to_those_who_may_use_it()
	{
		$this->actingAsAdministrator(['hampelApiUsers' => true]);
		$without = $this->renderReply($this->dispatch('api-users/add', 'admin'));

		$this->assertSame('Add API user', (string) $this->pageParam('pageTitle'));
		$this->assertSee($without, 'name="username"', false);
		$this->assertDontSee($without, 'name="is_admin"', false);
		$this->assertDontSee($without, 'name="visitor_password"', false);

		$this->actingAsAdministrator(['hampelApiUsers' => true, 'admin' => true]);
		$with = $this->renderReply($this->dispatch('api-users/add', 'admin'));

		$this->assertSee($with, 'name="is_admin"', false);
		$this->assertSee($with, 'name="visitor_password"', false);
		$this->assertSee($with, 'Make this account an administrator');
		$this->assertNoUnresolvedPhrases($with, self::PHRASES);
		$this->assertNoTemplateErrors(self::TEMPLATES);
	}

	public function test_the_convert_page_says_what_is_removed_and_offers_the_form()
	{
		$this->actingAsAdministrator(['hampelApiUsers' => true]);
		$member = $this->createMember();

		$html = $this->renderReply($this->dispatch("api-users/{$member->user_id}/convert", 'admin'));

		$this->assertSame("Convert {$member->username} to an API user", (string) $this->pageParam('pageTitle'));
		$this->assertSee($html, 'This cannot be undone');
		$this->assertSee($html, 'name="note"', false);
		$this->assertDontSee($html, 'cannot be converted');
		$this->assertNoUnresolvedPhrases($html, self::PHRASES);
		$this->assertNoTemplateErrors(self::TEMPLATES);
	}

	public function test_the_convert_page_lists_the_reasons_and_offers_no_form_when_it_would_refuse()
	{
		$this->actingAsAdministrator(['hampelApiUsers' => true]);
		$member = $this->createMember();
		$this->app()->db()->insert('xf_poll_vote', [
			'user_id' => $member->user_id, 'poll_response_id' => 2147480000, 'poll_id' => 2147480000, 'vote_date' => 1,
		]);

		$html = $this->renderReply($this->dispatch("api-users/{$member->user_id}/convert", 'admin'));

		$this->assertSee($html, "{$member->username} cannot be converted to an API user:");
		$this->assertSee($html, 'It has been used as a member.');
		$this->assertSee($html, 'xf_poll_vote');
		$this->assertDontSee($html, 'name="note"', false);
		$this->assertDontSee($html, 'This cannot be undone');
		$this->assertNoUnresolvedPhrases($html, self::PHRASES);
		$this->assertNoTemplateErrors(self::TEMPLATES);
	}

	public function test_all_five_edit_page_modifications_are_applied()
	{
		$this->assertTemplateModificationApplied('hampelApiUsersUserEditNotice');
		$this->assertTemplateModificationApplied('hampelApiUsersUserEditConvert');
		$this->assertTemplateModificationApplied('hampelApiUsersUserEditEmail');
		$this->assertTemplateModificationApplied('hampelApiUsersUserEditPassword');
		$this->assertTemplateModificationApplied('hampelApiUsersUserEditMerge');
	}

	public function test_an_api_user_edit_page_carries_the_notice_and_no_email_or_password_field()
	{
		$this->actingAsAdministrator(['user' => true, 'hampelApiUsers' => true]);
		$user = $this->createApiUser();

		[$html, $actions] = $this->editPage($user->user_id);

		$this->assertSee($html, $user->username);
		$this->assertSee($html, self::NOTICE);
		$this->assertSame(2, substr_count($html, self::NONE), 'one note for the email field and one for the password');
		$this->assertDontSee($html, self::EMAIL_INPUT, false);
		$this->assertDontSee($html, self::PASSWORD_CHOICE, false);
		$this->assertSee($actions, 'Manage watched threads');
		$this->assertDontSee($actions, self::CONVERT);
		$this->assertDontSee($actions, 'Merge with user');
		$this->assertNoUnresolvedPhrases($html, self::PHRASES);
	}

	public function test_an_ordinary_member_edit_page_is_as_core_made_it_plus_the_convert_action()
	{
		$this->actingAsAdministrator(['user' => true, 'hampelApiUsers' => true]);
		$member = $this->createMember();

		[$html, $actions] = $this->editPage($member->user_id);

		$this->assertSee($html, $member->username);
		$this->assertSee($html, self::EMAIL_INPUT, false);
		$this->assertSee($html, self::PASSWORD_CHOICE, false);
		$this->assertSee($actions, self::CONVERT);
		$this->assertSee($actions, 'Merge with user');
		$this->assertSee($actions, $this->app()->router('admin')->buildLink('api-users/convert', $member));
		$this->assertNoUnresolvedPhrases($actions, self::PHRASES);
		$this->assertDontSee($html, self::NOTICE);
		$this->assertDontSee($html, self::NONE);
		$this->assertNoUnresolvedPhrases($html, self::PHRASES);
	}

	/** The fields stay hidden whoever is looking; only the links into this add-on's pages go. */
	public function test_without_manage_api_users_the_edit_page_offers_no_way_into_this_add_on()
	{
		$this->actingAsAdministrator(['user' => true]);
		$user = $this->createApiUser();
		$member = $this->createMember();

		[$apiUserPage] = $this->editPage($user->user_id);

		$this->assertSee($apiUserPage, self::NOTICE);
		$this->assertDontSee($apiUserPage, self::EMAIL_INPUT, false);
		$this->assertDontSee($apiUserPage, 'api-users', false);

		[$memberPage, $actions] = $this->editPage($member->user_id);

		$this->assertSee($memberPage, self::EMAIL_INPUT, false);
		$this->assertSee($actions, 'Merge with user');
		$this->assertDontSee($actions, 'api-users', false);
	}

	public function test_the_add_user_page_is_left_alone()
	{
		$this->actingAsAdministrator(['user' => true, 'hampelApiUsers' => true]);

		$html = $this->renderReply($this->dispatch('users/add', 'admin'));

		$this->assertSee($html, self::EMAIL_INPUT, false);
		$this->assertDontSee($html, self::NOTICE);
		$this->assertDontSee($html, self::NONE);
		$this->assertDontSee($html, self::CONVERT);
	}
}
