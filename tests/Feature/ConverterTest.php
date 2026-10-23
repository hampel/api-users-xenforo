<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class ConverterTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * @param \XF\Entity\User $user
	 * @return \Hampel\ApiUsers\Service\ApiUser\Converter
	 */
	private function converter($user)
	{
		return $this->app()->service('Hampel\ApiUsers:ApiUser\Converter', $user);
	}

	/**
	 * @param \XF\Entity\User $user
	 * @return string
	 */
	private function blockerText($user)
	{
		return implode(' | ', array_map('strval', $this->converter($user)->getBlockers()));
	}

	/**
	 * By the refusal's own wording, since every refusal is a non-empty list and a test that only
	 * asks for one passes on the wrong reason.
	 *
	 * @param string $phrase
	 * @param \XF\Entity\User $user
	 * @return void
	 */
	private function assertRefusedWith($phrase, $user)
	{
		$this->assertContains(
			(string) \XF::phrase($phrase),
			array_map('strval', $this->converter($user)->getBlockers())
		);
	}

	/**
	 * @param int $userId
	 * @return \XF\Entity\User|null
	 */
	private function reload($userId)
	{
		$this->app()->em()->clearEntityCache();

		return $this->app()->em()->find('XF:User', $userId);
	}

	public function test_an_unused_account_is_converted(): void
	{
		$member = $this->createMember();
		$userId = $member->user_id;

		$converter = $this->converter($member);
		$converter->setNote('was the old bot account');
		$this->assertSame([], $converter->getBlockers());
		$converter->convert();

		$row = $this->app()->db()->fetchRow('SELECT * FROM xf_user WHERE user_id = ?', $userId);
		$this->assertSame('', $row['email']);
		$this->assertSame('valid', $row['user_state']);
		$this->assertEquals(0, $row['visible']);
		$this->assertIsApiOnly($userId);
		$this->assertSame('was the old bot account', $this->app()->db()->fetchOne(
			'SELECT note FROM xf_hampel_apiusers_user WHERE user_id = ?', $userId
		));
		$this->assertSame('none', $this->app()->db()->fetchOne(
			'SELECT allow_view_profile FROM xf_user_privacy WHERE user_id = ?', $userId
		));
	}

	public function test_the_old_password_no_longer_works_and_the_guards_now_apply(): void
	{
		$member = $this->createMember();
		$member->Auth->setPassword('the password it used to have');
		$member->Auth->save();
		$userId = $member->user_id;

		$this->converter($member)->convert();

		$user = $this->reload($userId);
		$this->assertTrue($user->isApiUser());
		$this->assertFalse($user->Auth->authenticate('the password it used to have'));

		$user->Auth->setPassword('a new one');
		$this->assertFalse($user->Auth->save(false));
	}

	/** A connected account logs its user in whatever the authentication scheme says. */
	public function test_every_other_way_of_logging_in_is_removed(): void
	{
		$member = $this->createMember();
		$userId = $member->user_id;
		$db = $this->app()->db();

		$db->insert('xf_user_connected_account', [
			'user_id' => $userId, 'provider' => 'github', 'provider_key' => 'apiusers-test-' . $userId, 'extra_data' => '[]',
		]);
		$db->insert('xf_user_remember', [
			'user_id' => $userId, 'remember_key' => str_repeat('a', 32), 'start_date' => 1, 'expiry_date' => 2147483000,
		]);
		$db->insert('xf_user_confirmation', [
			'user_id' => $userId, 'confirmation_type' => 'password', 'confirmation_key' => 'k', 'confirmation_date' => 1,
		]);
		$before = $db->fetchOne('SELECT password_date FROM xf_user_profile WHERE user_id = ?', $userId);

		$this->setTestTime(time() + 100);
		$this->converter($this->reload($userId))->convert();

		foreach (['xf_user_connected_account', 'xf_user_remember', 'xf_user_confirmation', 'xf_user_tfa'] AS $table)
		{
			$this->assertEquals(0, $db->fetchOne("SELECT COUNT(*) FROM {$table} WHERE user_id = ?", $userId), $table);
		}

		// a changed password date is what drops the sessions it already has
		$this->assertNotEquals($before, $db->fetchOne('SELECT password_date FROM xf_user_profile WHERE user_id = ?', $userId));
	}

	/**
	 * Two-step verification, a trusted device, a passkey and an OAuth grant, each planted as a real
	 * row, on the account and on a second member who must keep all of them. A count of zero proves
	 * nothing about a table nobody wrote to.
	 */
	public function test_two_step_passkeys_and_oauth_grants_are_removed_and_only_its_own(): void
	{
		$sm = $this->app()->db()->getSchemaManager();
		if (!$sm->tableExists('xf_passkey') || !$sm->tableExists('xf_oauth_token'))
		{
			$this->markTestSkipped('Passkeys and OAuth tokens are XenForo 2.3.');
		}

		$member = $this->createMember();
		$other = $this->createMember();
		$tokenIds = [];
		foreach ([$member, $other] AS $user)
		{
			$tokenIds[$user->user_id] = $this->plantOtherLogins($user);
		}

		$this->converter($this->reload($member->user_id))->convert();
		\XF::triggerRunOnce();

		$tables = ['xf_user_tfa', 'xf_user_tfa_trusted', 'xf_passkey', 'xf_oauth_token'];
		foreach ($tables AS $table)
		{
			$this->assertDatabaseMissing($table, ['user_id' => $member->user_id]);
			$this->assertDatabaseHas($table, ['user_id' => $other->user_id]);
		}
		$this->assertDatabaseMissing('xf_oauth_refresh_token', ['token_id' => $tokenIds[$member->user_id]]);
		$this->assertDatabaseHas('xf_oauth_refresh_token', ['token_id' => $tokenIds[$other->user_id]]);
		$this->assertDatabaseHas('xf_user_option', ['user_id' => $member->user_id, 'use_tfa' => 0]);
	}

	/**
	 * @param \XF\Entity\User $user
	 *
	 * @return int the OAuth token's id, which its refresh token is keyed on
	 */
	private function plantOtherLogins($user)
	{
		$db = $this->app()->db();
		$userId = $user->user_id;

		$db->insert('xf_user_tfa', [
			'user_id' => $userId, 'provider_id' => 'totp', 'provider_data' => '[]', 'last_used_date' => 1,
		]);
		$db->update('xf_user_option', ['use_tfa' => 1], 'user_id = ?', $userId);
		$db->insert('xf_user_tfa_trusted', [
			'user_id' => $userId, 'trusted_key' => bin2hex(random_bytes(16)), 'trusted_until' => 2147483000,
		]);

		$passkey = $this->app()->em()->create('XF:Passkey');
		$passkey->bulkSet([
			'user_id' => $userId, 'name' => 'Planted for a test', 'aaguid' => '',
			'credential_id' => bin2hex(random_bytes(16)), 'credential_public_key' => bin2hex(random_bytes(16)),
		]);
		$passkey->save();

		$db->insert('xf_oauth_token', [
			'token' => bin2hex(random_bytes(32)), 'client_id' => 'apiuserstest', 'user_id' => $userId,
			'issue_date' => 1, 'last_use_date' => 1, 'expiry_date' => 2147483000, 'scopes' => '[]',
		]);
		$tokenId = $db->lastInsertId();
		$db->insert('xf_oauth_refresh_token', [
			'token_id' => $tokenId, 'refresh_token' => bin2hex(random_bytes(32)), 'client_id' => 'apiuserstest',
			'issue_date' => 1, 'expiry_date' => 2147483000,
		]);

		return $tokenId;
	}

	/** A follow names both accounts on each other's public profile, where no listing filter reaches. */
	public function test_following_and_being_followed_are_both_removed(): void
	{
		$member = $this->createMember();
		$other = $this->createMember();
		$em = $this->app()->em();

		foreach ([[$member, $other], [$other, $member]] AS [$from, $to])
		{
			$follow = $em->create('XF:UserFollow');
			$follow->bulkSet(['user_id' => $from->user_id, 'follow_user_id' => $to->user_id]);
			$follow->save();
		}

		$db = $this->app()->db();
		$following = 'SELECT following FROM xf_user_profile WHERE user_id = ?';
		$this->assertSame((string) $member->user_id, (string) $db->fetchOne($following, $other->user_id));

		$this->converter($this->reload($member->user_id))->convert();

		$this->assertEquals(0, $db->fetchOne(
			'SELECT COUNT(*) FROM xf_user_follow WHERE user_id = ? OR follow_user_id = ?', [$member->user_id, $member->user_id]
		));

		// and the other member's own cached list, which is what its profile page reads
		$this->assertSame('', (string) $db->fetchOne($following, $other->user_id));
	}

	public function test_its_api_keys_are_kept(): void
	{
		$member = $this->createMember();
		$key = $this->app()->em()->create('XF:ApiKey');
		$key->bulkSet([
			'title' => 'Planted for a test', 'active' => true, 'user_id' => $member->user_id,
			'is_super_user' => false, 'allow_all_scopes' => false, 'scopes' => [], 'creation_user_id' => 1,
		]);
		$key->save();

		$this->converter($member)->convert();

		$this->assertEquals(1, $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_api_key WHERE user_id = ?', $member->user_id));
	}

	public function test_an_administrator_stays_an_administrator_with_its_permissions(): void
	{
		$member = $this->createMember();
		$admin = $this->app()->em()->create('XF:Admin');
		$admin->bulkSet(['user_id' => $member->user_id, 'permission_cache' => ['viewLogs' => true]]);
		$admin->save();
		$userId = $member->user_id;

		$user = $this->reload($userId);
		$this->assertSame([], $this->converter($user)->getBlockers());
		$this->converter($user)->convert();

		$this->assertSame(['viewLogs' => true], json_decode($this->app()->db()->fetchOne(
			'SELECT permission_cache FROM xf_admin WHERE user_id = ?', $userId
		), true));
		$this->assertIsApiOnly($userId);
	}

	public function test_an_account_with_content_is_refused_and_says_where(): void
	{
		$member = $this->createMember();
		$this->app()->db()->insert('xf_poll_vote', [
			'user_id' => $member->user_id, 'poll_response_id' => 2147480000, 'poll_id' => 2147480000, 'vote_date' => 1,
		]);

		$this->assertStringContainsString('xf_poll_vote', $this->blockerText($member));
	}

	public function test_an_account_with_a_reaction_is_refused(): void
	{
		$member = $this->createMember();

		$this->app()->db()->insert('xf_reaction_content', [
			'reaction_id' => 1, 'content_type' => 'post', 'content_id' => 2147480000,
			'reaction_user_id' => $member->user_id, 'reaction_date' => 1, 'content_user_id' => 0, 'is_counted' => 0,
		]);

		$this->assertStringContainsString('xf_reaction_content', $this->blockerText($member));
	}

	/**
	 * A forum's welcome message is a conversation sent to every account that registers. Holding
	 * one is not having been used; starting one or answering one is.
	 */
	public function test_being_sent_a_conversation_is_not_content_and_writing_in_one_is(): void
	{
		$sender = $this->createMember();
		$recipient = $this->createMember();

		/** @var \XF\Service\Conversation\Creator $creator */
		$creator = $this->app()->service('XF:Conversation\Creator', $sender);
		$creator->setIsAutomated();
		$creator->setOptions(['open_invite' => false, 'conversation_open' => true]);
		$creator->setRecipientsTrusted($recipient);
		$creator->setContent('Welcome', 'Welcome to the forum.');
		$this->assertTrue($creator->validate($errors), json_encode($errors));
		$conversation = $creator->save();

		// the recipient really does hold it, in both of the tables that say so
		$db = $this->app()->db();
		$this->assertEquals(1, $db->fetchOne('SELECT COUNT(*) FROM xf_conversation_recipient WHERE user_id = ?', $recipient->user_id));
		$this->assertEquals(1, $db->fetchOne('SELECT COUNT(*) FROM xf_conversation_user WHERE owner_user_id = ?', $recipient->user_id));

		$this->assertSame([], $this->converter($recipient)->getBlockers());
		$this->assertStringContainsString('xf_conversation_master', $this->blockerText($sender));

		/** @var \XF\Service\Conversation\Replier $replier */
		$replier = $this->app()->service('XF:Conversation\Replier', $conversation, $recipient);
		$replier->setIsAutomated();
		$replier->setMessageContent('Thank you.');
		$this->assertTrue($replier->validate($errors), json_encode($errors));
		$replier->save();

		$this->assertStringContainsString('xf_conversation_message', $this->blockerText($recipient));
	}

	/** Logging in, being alerted and being renamed are not content. */
	public function test_bookkeeping_rows_do_not_count_as_content(): void
	{
		$member = $this->createMember();
		$db = $this->app()->db();

		$db->insert('xf_ip', [
			'user_id' => $member->user_id, 'content_type' => 'user', 'content_id' => $member->user_id,
			'action' => 'login', 'ip' => inet_pton('192.0.2.1'), 'log_date' => 1,
		]);
		$db->insert('xf_username_change', [
			'user_id' => $member->user_id, 'old_username' => 'before', 'new_username' => $member->username,
			'change_reason' => '', 'change_state' => 'approved', 'change_user_id' => 1, 'change_date' => 1,
		]);

		$this->assertSame([], $this->converter($member)->getBlockers());
	}

	public function test_a_table_another_add_on_registers_counts_as_content(): void
	{
		$member = $this->createMember();

		$converter = new class($this->app(), $member) extends \Hampel\ApiUsers\Service\ApiUser\Converter {
			protected function getUserContentMap()
			{
				// xf_user_option stands in for an add-on's own table: it exists, and holds this user
				return ['xf_user_option' => ['user_id', 'emptyable' => false]];
			}
		};

		$this->assertSame(['xf_user_option'], $converter->getContentTables());
	}

	public function test_the_content_map_is_core_s_own(): void
	{
		$member = $this->createMember();

		$converter = new class($this->app(), $member) extends \Hampel\ApiUsers\Service\ApiUser\Converter {
			/**
			 * @return array<string, array<int|string, mixed>>
			 */
			public function map()
			{
				return $this->getUserContentMap();
			}
		};

		$map = $converter->map();
		$this->assertArrayHasKey('xf_post', $map);
		$this->assertArrayHasKey('xf_conversation_message', $map);
	}

	public function test_a_banned_or_unconfirmed_account_is_refused(): void
	{
		$member = $this->createMember();
		$member->user_state = 'email_confirm';
		$member->save();
		$this->assertRefusedWith('hampel_apiusers_convert_blocked_not_valid', $member);

		$member->user_state = 'valid';
		$member->is_banned = true;
		$member->save();
		$this->assertRefusedWith('hampel_apiusers_convert_blocked_not_valid', $member);
	}

	public function test_a_moderator_an_api_user_and_your_own_account_are_refused(): void
	{
		$moderator = $this->createMember();
		$moderator->is_moderator = true;
		$moderator->save();
		$this->assertRefusedWith('hampel_apiusers_convert_blocked_moderator', $moderator);

		$this->assertRefusedWith('hampel_apiusers_convert_blocked_already_api_user', $this->createApiUser());

		$self = $this->createMember();
		$this->assertSame('', $this->blockerText($self));
		$this->actingAs($self);
		$this->assertRefusedWith('hampel_apiusers_convert_blocked_own_account', $self);
	}

	/** The note is stored in a varchar(255), and the creator refuses the same length. */
	public function test_a_note_longer_than_the_column_is_refused(): void
	{
		$member = $this->createMember();

		$converter = $this->converter($member);
		$converter->setNote(str_repeat('n', 255));
		$this->assertSame([], $converter->getBlockers());

		$converter->setNote(str_repeat('n', 256));
		$this->assertSame(
			[(string) \XF::phrase('hampel_apiusers_note_is_too_long')],
			array_map('strval', $converter->getBlockers())
		);
	}

	public function test_convert_refuses_to_run_past_a_blocker(): void
	{
		$member = $this->createMember();
		$member->is_banned = true;
		$member->save();
		$email = $member->email;

		try
		{
			$this->converter($member)->convert();
			$this->fail('A banned account was converted.');
		}
		catch (\LogicException $e)
		{
		}

		$this->assertSame($email, $this->app()->db()->fetchOne('SELECT email FROM xf_user WHERE user_id = ?', $member->user_id));
	}
}
