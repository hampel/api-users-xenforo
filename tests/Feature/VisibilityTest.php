<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class VisibilityTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function isListedAsValid($userId)
	{
		return (bool) $this->app()->finder('XF:User')->isValidUser()->where('user_id', $userId)->fetchOne();
	}

	public function test_an_api_user_is_left_out_of_public_member_listings()
	{
		$user = $this->createApiUser();
		$member = $this->createMember();

		$this->assertFalse($this->isListedAsValid($user->user_id));
		$this->assertTrue($this->isListedAsValid($member->user_id));
	}

	public function test_an_api_user_is_not_counted_as_a_member()
	{
		$before = $this->app()->finder('XF:User')->isValidUser()->total();

		$this->createApiUser();

		$this->assertSame($before, $this->app()->finder('XF:User')->isValidUser()->total());
	}

	/** The control panel's username autocomplete asks the same question, and must find it. */
	public function test_the_control_panel_still_lists_an_api_user_as_valid()
	{
		$user = $this->createApiUser();

		$this->setAppClassType('admin');

		$this->assertTrue($this->isListedAsValid($user->user_id));
	}

	public function test_the_control_panel_user_search_finds_an_api_user()
	{
		$user = $this->createApiUser();

		$searcher = $this->app()->searcher('XF:User', ['username' => $user->username]);

		$this->assertSame(1, $searcher->getFinder()->total());
	}

	public function test_a_guest_and_a_member_cannot_view_its_profile()
	{
		$user = $this->createApiUser();

		$this->actingAsGuest(['general' => ['viewProfile' => true]]);
		$this->assertFalse($user->canViewFullProfile());
		$this->assertFalse($user->canViewBasicProfile());

		$this->actingAsMember(['user_id' => 987652], ['general' => ['viewProfile' => true]]);
		$this->assertFalse($user->canViewFullProfile());
		$this->assertFalse($user->canViewBasicProfile());
	}

	public function test_staff_who_can_bypass_user_privacy_can_view_its_profile()
	{
		$user = $this->createApiUser();

		$this->actingAsMember(['user_id' => 987653], ['general' => ['viewProfile' => true, 'bypassUserPrivacy' => true]]);

		$this->assertTrue($user->canViewFullProfile());
		$this->assertTrue($user->canViewBasicProfile());
	}

	public function test_an_ordinary_member_profile_is_unaffected()
	{
		$member = $this->createMember();

		$this->actingAsGuest(['general' => ['viewProfile' => true]]);

		$this->assertTrue($member->canViewFullProfile());
		$this->assertTrue($member->canViewBasicProfile());
	}
}
