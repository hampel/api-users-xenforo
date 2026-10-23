<?php namespace Hampel\ApiUsers;

use Hampel\ApiUsers\Authentication\ApiOnly;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	/**
	 * @return void
	 */
	public function installStep1()
	{
		$this->schemaManager()->createTable('xf_hampel_apiusers_user', function (Create $table)
		{
			$table->addColumn('user_id', 'int');
			$table->addColumn('created_date', 'int')->setDefault(0);
			$table->addColumn('created_by_user_id', 'int')->setDefault(0);
			$table->addColumn('note', 'varchar', 255)->setDefault('');
			$table->addPrimaryKey('user_id');
		});
	}

	/**
	 * Move every API user onto core's own passwordless scheme before this add-on's class goes.
	 *
	 * A scheme_class naming a class that no longer exists throws from
	 * UserAuth::getAuthenticationHandler() - on a login attempt against that username and on the
	 * administrator pages. That fails closed, but XF:NoPassword fails closed without the error.
	 * Raw SQL because the entity guard would refuse the change, and by then should.
	 *
	 * The accounts are found with a plain read and updated by primary key. scheme_class has no
	 * index, so updating by it scans and locks the whole table, and waits on any row another
	 * transaction holds.
	 *
	 * @return void
	 */
	public function uninstallStep1()
	{
		$db = $this->db();

		$userIds = $db->fetchAllColumn(
			'SELECT user_id FROM xf_user_authenticate WHERE scheme_class = ?', ApiOnly::NAME
		);

		foreach ($userIds AS $userId)
		{
			$db->update(
				'xf_user_authenticate',
				['scheme_class' => 'XF:NoPassword', 'data' => serialize([])],
				'user_id = ?',
				$userId
			);
		}
	}

	/**
	 * @return void
	 */
	public function uninstallStep2()
	{
		$this->schemaManager()->dropTable('xf_hampel_apiusers_user');
	}
}
