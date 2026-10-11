<?php namespace Hampel\ApiUsers\Service\ApiUser;

use XF\Entity\User;
use XF\Service\AbstractService;

/**
 * Turns an existing account into an API user: removes its email address, its password and every
 * other way it could log in, and records it as an API user.
 *
 * Refused for an account that has ever been used as a member. The conversion cannot be undone -
 * the email address and password are gone - so it is for an account that was made to be a bot
 * before this add-on existed, not for a person's.
 */
class Converter extends AbstractService
{
	/**
	 * Tables in core's map of where a user is recorded that are bookkeeping and not content: an
	 * account that has only ever been created and logged in to has rows in several of them.
	 * Anything in the map that is not listed here counts as content - including a table from an
	 * add-on this list has never heard of, so that the unknown case refuses.
	 *
	 * The two conversation tables listed are the ones that say an account *holds* a conversation.
	 * One it started is in xf_conversation_master and a message it wrote in
	 * xf_conversation_message, and neither of those is here. So an account that has only been sent
	 * a conversation - the welcome message a forum sends on registration, above all - is not
	 * refused, and one that has answered it is.
	 */
	const NOT_CONTENT = [
		'xf_admin_log', 'xf_ban_email', 'xf_bookmark_item', 'xf_bookmark_label',
		'xf_conversation_recipient', 'xf_conversation_user', 'xf_cookie_consent_log', 'xf_deletion_log', 'xf_error_log', 'xf_feed', 'xf_forum',
		'xf_forum_watch', 'xf_ip', 'xf_ip_match', 'xf_moderator_log', 'xf_news_feed',
		'xf_purchase_request', 'xf_report', 'xf_spam_cleaner_log', 'xf_spam_trigger_log',
		'xf_thread_user_post', 'xf_thread_watch', 'xf_user_alert', 'xf_user_ban', 'xf_user_follow',
		'xf_user_ignored', 'xf_user_trophy', 'xf_user_upgrade_active', 'xf_user_upgrade_expired',
		'xf_username_change',
		'xf_mg_album_comment_read', 'xf_mg_album_watch', 'xf_mg_category_watch',
		'xf_mg_media_comment_read', 'xf_mg_media_temp', 'xf_mg_media_watch',
		'xf_mg_shared_map_add', 'xf_mg_shared_map_view',
		'xf_rm_category_watch', 'xf_rm_resource_download', 'xf_rm_resource_watch',
	];

	/** @var User */
	protected $user;

	/** @var string */
	protected $note = '';

	/** @var int */
	protected $createdByUserId = 0;

	public function __construct(\XF\App $app, User $user)
	{
		parent::__construct($app);

		$this->user = $user;
	}

	public function setNote($note)
	{
		$this->note = (string) $note;
	}

	public function setCreatedBy(User $user)
	{
		$this->createdByUserId = $user->user_id;
	}

	/**
	 * Why this account cannot be converted. Empty when it can.
	 *
	 * @return \XF\Phrase[]
	 */
	public function getBlockers()
	{
		$user = $this->user;

		if ($user->isApiUser())
		{
			return [\XF::phrase('hampel_apiusers_convert_blocked_already_api_user')];
		}

		$blockers = [];

		if ($user->user_id == \XF::visitor()->user_id)
		{
			$blockers[] = \XF::phrase('hampel_apiusers_convert_blocked_own_account');
		}

		// the REST API treats a key whose user is banned or not valid as a guest, and this must
		// not quietly lift a ban or confirm an account to get round that
		if ($user->user_state != 'valid' || $user->is_banned)
		{
			$blockers[] = \XF::phrase('hampel_apiusers_convert_blocked_not_valid');
		}

		if ($user->is_moderator)
		{
			$blockers[] = \XF::phrase('hampel_apiusers_convert_blocked_moderator');
		}

		if (mb_strlen($this->note) > 255)
		{
			$blockers[] = \XF::phrase('hampel_apiusers_note_is_too_long');
		}

		$tables = $this->getContentTables();
		if ($tables)
		{
			$blockers[] = \XF::phrase('hampel_apiusers_convert_blocked_has_content', [
				'tables' => implode(', ', $tables),
			]);
		}

		return $blockers;
	}

	/**
	 * The tables in which this account has content, from the same map core uses to rename, merge
	 * and delete a user - so an add-on that tells core where it keeps a user's content has told
	 * this too.
	 *
	 * @return string[]
	 */
	public function getContentTables()
	{
		$db = $this->db();
		$sm = $db->getSchemaManager();
		$userId = $this->user->user_id;

		$found = [];

		foreach ($this->getUserContentMap() AS $table => $columns)
		{
			if (in_array($table, self::NOT_CONTENT, true) || !$sm->tableExists($table))
			{
				continue;
			}

			foreach ($this->userIdColumns($columns) AS $column)
			{
				if ($db->fetchOne("SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1", $userId))
				{
					$found[] = $table;
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Core keeps the map on its content change service and offers no way to read it, so it is
	 * read by reflection. Asking for the service by its short name fires
	 * user_content_change_init, which is how add-ons extend the map.
	 *
	 * @return array table => column definition(s)
	 */
	protected function getUserContentMap()
	{
		$service = $this->service('XF:User\ContentChange', $this->user);

		$property = new \ReflectionProperty($service, 'updates');
		$property->setAccessible(true);

		return $property->getValue($service);
	}

	/**
	 * A definition is either one [user id column, username column?] or a list of them, and
	 * either may carry an 'emptyable' flag that is not a column.
	 *
	 * @return string[]
	 */
	protected function userIdColumns(array $definition)
	{
		if (isset($definition[0]) && is_array($definition[0]))
		{
			$columns = [];
			foreach ($definition AS $key => $one)
			{
				if (is_int($key) && is_array($one) && isset($one[0]))
				{
					$columns[] = $one[0];
				}
			}

			return $columns;
		}

		return isset($definition[0]) ? [$definition[0]] : [];
	}

	/**
	 * @return \Hampel\ApiUsers\Entity\ApiUser
	 */
	public function convert()
	{
		if ($this->getBlockers())
		{
			throw new \LogicException('This account cannot be converted to an API user.');
		}

		$user = $this->user;
		$userId = $user->user_id;

		$db = $this->db();
		$db->beginTransaction();

		// Every other way in goes before the password does. A connected account logs its user in
		// whatever the authentication scheme says, so leaving one would leave a working login.
		$this->removeOtherLogins();
		$this->removeFollows();

		$this->repository('Hampel\ApiUsers:ApiUser')->applyApiUserShape($user);

		// a session is dropped when the date it recorded no longer matches this one
		$profile = $user->getRelationOrDefault('Profile');
		$profile->password_date = \XF::$time;
		$profile->connected_accounts = [];

		$user->save(true, false);

		$apiUser = $this->em()->create('Hampel\ApiUsers:ApiUser');
		$apiUser->bulkSet([
			'user_id' => $userId,
			'created_date' => \XF::$time,
			'created_by_user_id' => $this->createdByUserId,
			'note' => $this->note,
		]);
		$apiUser->save(true, false);

		$user->hydrateRelation('ApiUser', $apiUser);

		$db->commit();

		return $apiUser;
	}

	protected function removeOtherLogins()
	{
		$user = $this->user;
		$userId = $user->user_id;
		$db = $this->db();
		$sm = $db->getSchemaManager();

		$this->repository('XF:UserRemember')->clearUserRememberRecords($userId);
		$this->repository('XF:Tfa')->disableTfaForUser($user);

		$db->delete('xf_user_connected_account', 'user_id = ?', $userId);
		$db->delete('xf_user_confirmation', 'user_id = ?', $userId);

		// XenForo 2.3 only from here: passkeys, and the tokens an OAuth client was granted to act
		// as this user. Passkeys go through the entity, which keeps core's own record in step.
		if ($sm->tableExists('xf_passkey'))
		{
			foreach ($this->finder('XF:Passkey')->where('user_id', $userId)->fetch() AS $passkey)
			{
				$passkey->delete(true, false);
			}
		}

		if ($sm->tableExists('xf_oauth_token'))
		{
			if ($sm->tableExists('xf_oauth_refresh_token'))
			{
				$db->query('
					DELETE refresh_token
					FROM xf_oauth_refresh_token AS refresh_token
					INNER JOIN xf_oauth_token AS token ON (token.token_id = refresh_token.token_id)
					WHERE token.user_id = ?
				', $userId);
			}

			$db->delete('xf_oauth_token', 'user_id = ?', $userId);
		}
	}

	/**
	 * Following and being followed both put this account's name on another member's public
	 * profile, where the listing filter does not reach. Each row goes through its entity, which
	 * rebuilds the cached list on the profile of whoever was following.
	 */
	protected function removeFollows()
	{
		$userId = $this->user->user_id;

		$follows = $this->finder('XF:UserFollow')
			->whereOr(['user_id', $userId], ['follow_user_id', $userId])
			->fetch();

		foreach ($follows AS $follow)
		{
			$follow->delete(true, false);
		}
	}
}
