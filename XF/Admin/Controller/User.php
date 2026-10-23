<?php namespace Hampel\ApiUsers\XF\Admin\Controller;

use XF\Mvc\ParameterBag;

/**
 * Tells an administrator why an API user cannot be merged, before core queues a merge that the
 * service extension would then refuse out of sight.
 */
class User extends XFCP_User
{
	/**
	 * @param ParameterBag $params
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionMerge(ParameterBag $params)
	{
		// a route parameter is untyped, and core documents the id as a string; core's own
		// actionMerge() makes this same call
		// @phpstan-ignore argument.type
		$user = $this->assertUserExists($params->user_id);
		if ($user->isApiUser())
		{
			return $this->error(\XF::phrase('hampel_apiusers_api_user_cannot_be_merged'));
		}

		if ($this->isPost())
		{
			/** @var \XF\Entity\User|null $target */
			$target = $this->em()->findOne('XF:User', ['username' => $this->filter('username', 'str')]);
			if ($target && $target->isApiUser())
			{
				return $this->error(\XF::phrase('hampel_apiusers_api_user_cannot_be_merged'));
			}
		}

		return parent::actionMerge($params);
	}
}
