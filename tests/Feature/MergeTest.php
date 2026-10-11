<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * An API user is never merged, in either direction. The service is the enforcement, since every
 * merge ends there; the controller is what tells an administrator why.
 */
class MergeTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const REFUSED = 'An API user cannot be merged with another account, in either direction.';

	private function merge($source, $target)
	{
		/** @var \XF\Service\User\Merge $merger */
		$merger = $this->app()->service('XF:User\Merge');
		$merger->setSource($source)->setTarget($target);

		return $merger->merge();
	}

	private function exists($userId)
	{
		return (bool) $this->app()->db()->fetchOne('SELECT 1 FROM xf_user WHERE user_id = ?', $userId);
	}

	private function mergeJobs()
	{
		return (int) $this->app()->db()->fetchOne("SELECT COUNT(*) FROM xf_job WHERE unique_key LIKE 'userMerge%'");
	}

	public function test_the_merge_service_is_this_add_on_s()
	{
		$this->assertInstanceOf(\Hampel\ApiUsers\XF\Service\User\Merge::class, $this->app()->service('XF:User\Merge'));
	}

	public function test_an_api_user_is_not_merged_into_a_member()
	{
		$this->fakesErrors();
		$apiUser = $this->createApiUser();
		$member = $this->createMember();

		$result = $this->merge($apiUser, $member);

		// finished, so the job that asked does not come back for another go
		$this->assertTrue($result->isCompleted());
		$this->assertTrue($this->exists($apiUser->user_id), 'the source of a merge is deleted, and this one must not be');
		$this->assertIsApiOnly($apiUser->user_id);
		$this->assertExceptionLogged(\ErrorException::class, 'ApiUsers: Refused to merge');
	}

	public function test_a_member_is_not_merged_into_an_api_user()
	{
		$this->fakesErrors();
		$apiUser = $this->createApiUser();
		$member = $this->createMember();
		$this->app()->db()->insert('xf_poll_vote', [
			'user_id' => $member->user_id, 'poll_response_id' => 2147480000, 'poll_id' => 2147480000, 'vote_date' => 1,
		]);

		$result = $this->merge($member, $apiUser);

		$this->assertTrue($result->isCompleted());
		$this->assertTrue($this->exists($member->user_id));
		$this->assertEquals($member->user_id, $this->app()->db()->fetchOne(
			'SELECT user_id FROM xf_poll_vote WHERE poll_id = 2147480000'
		), 'the member keeps its content');
		$this->assertExceptionLogged(\ErrorException::class, $apiUser->username);
	}

	/** The control: core's merge is otherwise left alone, and this suite can tell. */
	public function test_two_ordinary_members_still_merge()
	{
		$source = $this->createMember();
		$target = $this->createMember();

		$this->assertTrue($this->merge($source, $target)->isCompleted());
		$this->assertFalse($this->exists($source->user_id));
		$this->assertTrue($this->exists($target->user_id));
	}

	public function test_the_merge_form_is_refused_for_an_api_user_with_the_reason()
	{
		$this->actingAsAdministrator(['user' => true]);
		$apiUser = $this->createApiUser();
		$member = $this->createMember();

		$this->assertReplyIsError($this->dispatch("users/{$apiUser->user_id}/merge", 'admin'), null, self::REFUSED);
		$this->assertReplyIsError(
			$this->dispatch("users/{$apiUser->user_id}/merge", 'admin', ['username' => $member->username], [], 'POST'),
			null, self::REFUSED
		);
		$this->assertSame(0, $this->mergeJobs());
	}

	public function test_naming_an_api_user_as_the_target_is_refused_and_queues_nothing()
	{
		$this->actingAsAdministrator(['user' => true]);
		$apiUser = $this->createApiUser();
		$member = $this->createMember();

		$reply = $this->dispatch("users/{$member->user_id}/merge", 'admin', ['username' => $apiUser->username], [], 'POST');

		$this->assertReplyIsError($reply, null, self::REFUSED);
		$this->assertSame(0, $this->mergeJobs());
	}

	/** The control for the two above: the form and its job are untouched between members. */
	public function test_the_merge_form_still_works_between_two_members()
	{
		$this->actingAsAdministrator(['user' => true]);
		$source = $this->createMember();
		$target = $this->createMember();

		$this->assertReplyIsView($this->dispatch("users/{$source->user_id}/merge", 'admin'));

		$reply = $this->dispatch("users/{$source->user_id}/merge", 'admin', ['username' => $target->username], [], 'POST');

		$this->assertReplyIsRedirect($reply);
		$this->assertSame(1, $this->mergeJobs());
	}
}
