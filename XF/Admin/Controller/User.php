<?php namespace Hampel\ApiUsers\XF\Admin\Controller;

use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;

/**
 * Tells an administrator why an API user cannot be merged, before core queues a merge that the
 * service extension would then refuse out of sight, and saves the note that the user edit page
 * offers an API user.
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

	/**
	 * The note field is only on the page for an API user and an administrator with Manage API
	 * users, so a save without it changes nothing; asking again here keeps a crafted request out.
	 *
	 * Core declares no return type; it builds a FormAction on 2.2 and 2.3, and anything else is
	 * handed back untouched rather than guessed at.
	 *
	 * @param \XF\Entity\User $user
	 * @return mixed
	 */
	protected function userSaveProcess(\XF\Entity\User $user)
	{
		$form = parent::userSaveProcess($user);

		$apiUser = $user->exists() ? $user->ApiUser : null;
		if (!$form instanceof FormAction
			|| !$apiUser
			|| !\XF::visitor()->hasAdminPermission('hampelApiUsers')
			|| !$this->request()->exists('api_user_note')
		)
		{
			return $form;
		}

		/** @var \Hampel\ApiUsers\Service\ApiUser\NoteEditor $editor */
		$editor = $this->service('Hampel\ApiUsers:ApiUser\NoteEditor', $apiUser);
		$editor->setNote($this->filter('api_user_note', 'str'));

		$form->validate(function (FormAction $form) use ($editor)
		{
			if (!$editor->validate($errors))
			{
				$form->logErrors($errors);
			}
		});
		$form->apply(function () use ($editor)
		{
			$editor->save();
		});

		return $form;
	}
}
