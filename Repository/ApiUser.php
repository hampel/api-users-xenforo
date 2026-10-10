<?php namespace Hampel\ApiUsers\Repository;

use XF\Mvc\Entity\Repository;

class ApiUser extends Repository
{
	/**
	 * @return \XF\Mvc\Entity\Finder
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
	 * How many API keys name each of the given users. A user with none is absent, not zero.
	 *
	 * @param int[] $userIds
	 *
	 * @return array user_id => count
	 */
	public function countApiKeysByUser(array $userIds)
	{
		if (!$userIds)
		{
			return [];
		}

		$db = $this->db();

		return $db->fetchPairs('
			SELECT user_id, COUNT(*)
			FROM xf_api_key
			WHERE user_id IN (' . $db->quote($userIds) . ')
			GROUP BY user_id
		');
	}
}
