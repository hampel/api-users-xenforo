<?php namespace Hampel\ApiUsers\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class ApiUser extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertAdminPermission('hampelApiUsers');
	}

	public function actionIndex()
	{
		$page = $this->filterPage();
		$perPage = 50;

		$repo = $this->getApiUserRepo();

		$finder = $repo->findApiUsersForList()->limitByPage($page, $perPage);
		$total = $finder->total();
		$apiUsers = $finder->fetch();

		$viewParams = [
			'apiUsers' => $apiUsers,
			'apiKeyCounts' => $repo->countApiKeysByUser($apiUsers->keys()),
			'apiOnlyScheme' => \Hampel\ApiUsers\Authentication\ApiOnly::NAME,
			'total' => $total,
			'page' => $page,
			'perPage' => $perPage,
		];
		return $this->view('Hampel\ApiUsers:ApiUser\Listing', 'hampel_apiusers_list', $viewParams);
	}

	public function actionAdd()
	{
		$viewParams = [
			'canMakeAdmin' => $this->canMakeAdmin(),
		];
		return $this->view('Hampel\ApiUsers:ApiUser\Add', 'hampel_apiusers_add', $viewParams);
	}

	public function actionSave()
	{
		$this->assertPostOnly();

		$input = $this->filter([
			'username' => 'str',
			'note' => 'str',
			'is_admin' => 'bool',
		]);

		if ($input['is_admin'])
		{
			// Manage API users must not be a second door to minting administrators: core asks for
			// its own permission and the acting administrator's password, so this does too.
			if (!$this->canMakeAdmin())
			{
				return $this->noPermission(\XF::phrase('hampel_apiusers_manage_administrators_permission_required'));
			}

			if (!\XF::visitor()->authenticate($this->filter('visitor_password', 'str')))
			{
				return $this->error(\XF::phrase('your_existing_password_is_not_correct'));
			}
		}

		/** @var \Hampel\ApiUsers\Service\ApiUser\Creator $creator */
		$creator = $this->service('Hampel\ApiUsers:ApiUser\Creator', $input['username']);
		$creator->setNote($input['note']);
		$creator->setCreatedBy(\XF::visitor());
		$creator->setAdmin($input['is_admin']);

		if (!$creator->validate($errors))
		{
			return $this->error($errors);
		}

		$creator->save();

		return $this->redirect(
			$this->buildLink('api-users'),
			\XF::phrase('hampel_apiusers_api_user_x_created', ['name' => $creator->getUser()->username])
		);
	}

	/**
	 * @return bool
	 */
	protected function canMakeAdmin()
	{
		return \XF::visitor()->hasAdminPermission('admin');
	}

	/**
	 * @return \Hampel\ApiUsers\Repository\ApiUser
	 */
	protected function getApiUserRepo()
	{
		return $this->repository('Hampel\ApiUsers:ApiUser');
	}
}
