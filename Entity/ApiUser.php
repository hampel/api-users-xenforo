<?php namespace Hampel\ApiUsers\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * A row here is what makes an account an API user. Nothing infers it from the account having no
 * password or no email address: core creates passwordless accounts itself, for a registration
 * through a connected account, and an administrator can blank any email address.
 *
 * COLUMNS
 * @property int $user_id
 * @property int $created_date
 * @property int $created_by_user_id
 * @property string $note
 *
 * RELATIONS
 * @property-read \XF\Entity\User|null $User
 * @property-read \XF\Entity\User|null $CreatedBy
 */
class ApiUser extends Entity
{
	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_hampel_apiusers_user';
		$structure->shortName = 'Hampel\ApiUsers:ApiUser';
		$structure->primaryKey = 'user_id';
		$structure->columns = [
			'user_id' => ['type' => self::UINT, 'required' => true],
			'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
			'created_by_user_id' => ['type' => self::UINT, 'default' => 0],
			'note' => ['type' => self::STR, 'maxLength' => 255, 'default' => ''],
		];
		$structure->getters = [];
		$structure->relations = [
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true,
			],
			'CreatedBy' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => [['user_id', '=', '$created_by_user_id']],
				'primary' => true,
			],
		];

		return $structure;
	}
}
