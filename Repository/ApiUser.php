<?php namespace Hampel\ApiUsers\Repository;

use Hampel\ApiUsers\Authentication\ApiOnly;
use XF\Mvc\Entity\Repository;

class ApiUser extends Repository
{
	/**
	 * @return \XF\Mvc\Entity\Finder<\Hampel\ApiUsers\Entity\ApiUser>
	 */
	public function findApiUsersForList()
	{
		return $this->finder('Hampel\ApiUsers:ApiUser')
			->with(['User', 'User.Auth', 'CreatedBy'])
			->order('User.username');
	}

	/**
	 * @param int $userId
	 *
	 * @return bool
	 */
	public function isApiUser($userId)
	{
		return $userId && (bool) $this->em->find('Hampel\ApiUsers:ApiUser', $userId);
	}

	/**
	 * Everything about an account that makes it an API user apart from the record of it: no
	 * email address, the API-only scheme, and settings that keep it out of public view.
	 *
	 * The one definition, used when an API user is created and when an existing account is
	 * converted, so the two cannot drift apart.
	 *
	 * @param \XF\Entity\User $user
	 * @return void
	 */
	public function applyApiUserShape(\XF\Entity\User $user)
	{
		$user->setOption('admin_edit', true);

		$user->email = '';

		// Set on the account as well as enforced by the class extensions, because core honours
		// these with the add-on disabled and the extensions stop.
		$user->visible = false;
		$user->activity_visible = false;

		$auth = $user->getRelationOrDefault('Auth');
		$auth->scheme_class = ApiOnly::NAME;
		$auth->data = [];

		$user->getRelationOrDefault('Privacy')->bulkSet([
			'allow_view_profile' => 'none',
			'allow_post_profile' => 'none',
			'allow_send_personal_conversation' => 'none',
			'allow_view_identities' => 'none',
			'allow_receive_news_feed' => 'none',
		]);

		$user->getRelationOrDefault('Option')->receive_admin_email = false;
	}

	/**
	 * How many API keys name each of the given users. A user with none is absent, not zero.
	 *
	 * @param int[] $userIds
	 *
	 * @return array<int, int> user_id => count
	 */
	public function countApiKeysByUser(array $userIds)
	{
		if (!$userIds)
		{
			return [];
		}

		$db = $this->db();

		$counts = [];
		foreach ($db->fetchPairs('
			SELECT user_id, COUNT(*)
			FROM xf_api_key
			WHERE user_id IN (' . $db->quote($userIds) . ')
			GROUP BY user_id
		') AS $userId => $count)
		{
			$counts[(int) $userId] = is_numeric($count) ? (int) $count : 0;
		}

		return $counts;
	}
}
