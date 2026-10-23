<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class VisibilityTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * @param int $userId
	 * @return bool
	 */
	private function isListedAsValid($userId)
	{
		return (bool) $this->app()->finder('XF:User')->isValidUser()->where('user_id', $userId)->fetchOne();
	}

	public function test_an_api_user_is_left_out_of_public_member_listings(): void
	{
		$user = $this->createApiUser();
		$member = $this->createMember();

		$this->assertFalse($this->isListedAsValid($user->user_id));
		$this->assertTrue($this->isListedAsValid($member->user_id));
	}

	public function test_an_api_user_is_not_counted_as_a_member(): void
	{
		$before = $this->app()->finder('XF:User')->isValidUser()->total();

		$this->createApiUser();

		$this->assertSame($before, $this->app()->finder('XF:User')->isValidUser()->total());
	}

	/** The control panel's username autocomplete asks the same question, and must find it. */
	public function test_the_control_panel_still_lists_an_api_user_as_valid(): void
	{
		$user = $this->createApiUser();

		$this->setAppClassType('admin');

		$this->assertTrue($this->isListedAsValid($user->user_id));
	}

	public function test_the_control_panel_user_search_finds_an_api_user(): void
	{
		$user = $this->createApiUser();

		$searcher = $this->app()->searcher('XF:User', ['username' => $user->username]);

		$this->assertSame(1, $searcher->getFinder()->total());
	}

	public function test_a_guest_and_a_member_cannot_view_its_profile(): void
	{
		$user = $this->createApiUser();

		$this->actingAsGuest(['general' => ['viewProfile' => true]]);
		$this->assertFalse($user->canViewFullProfile());
		$this->assertFalse($user->canViewBasicProfile());

		$this->actingAsMember(['user_id' => 987652], ['general' => ['viewProfile' => true]]);
		$this->assertFalse($user->canViewFullProfile());
		$this->assertFalse($user->canViewBasicProfile());
	}

	public function test_staff_who_can_bypass_user_privacy_can_view_its_profile(): void
	{
		$user = $this->createApiUser();

		$this->actingAsMember(['user_id' => 987653], ['general' => ['viewProfile' => true, 'bypassUserPrivacy' => true]]);

		$this->assertTrue($user->canViewFullProfile());
		$this->assertTrue($user->canViewBasicProfile());
	}

	public function test_nobody_can_start_following_an_api_user_and_it_can_follow_nobody(): void
	{
		$apiUser = $this->createApiUser();
		$member = $this->createMember();
		$other = $this->createMember();

		$this->assertFalse($member->canFollowUser($apiUser));
		$this->assertFalse($apiUser->canFollowUser($member));

		// and the check is not simply refusing everybody
		$this->assertTrue($member->canFollowUser($other));
	}

	/** Core's check also guards unfollowing, so a follow that already exists can still be removed. */
	public function test_a_follow_that_already_exists_can_still_be_removed(): void
	{
		$apiUser = $this->createApiUser();
		$member = $this->createMember();
		$this->app()->db()->insert('xf_user_follow', [
			'user_id' => $member->user_id, 'follow_user_id' => $apiUser->user_id, 'follow_date' => 1,
		]);
		$this->app()->db()->update('xf_user_profile', ['following' => (string) $apiUser->user_id], 'user_id = ?', $member->user_id);
		$this->app()->em()->clearEntityCache();
		$member = $this->app()->em()->find('XF:User', $member->user_id);

		$this->assertTrue($member->canFollowUser($this->app()->em()->find('XF:User', $apiUser->user_id)));
	}

	public function test_an_ordinary_member_profile_is_unaffected(): void
	{
		$member = $this->createMember();

		$this->actingAsGuest(['general' => ['viewProfile' => true]]);

		$this->assertTrue($member->canViewFullProfile());
		$this->assertTrue($member->canViewBasicProfile());
	}
}
