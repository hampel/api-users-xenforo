<?php namespace Hampel\ApiUsers\XF\Entity;

use XF\Mvc\Entity\Structure;

class User extends XFCP_User
{
	/**
	 * @return bool
	 */
	public function isApiUser()
	{
		return $this->user_id && (bool) $this->ApiUser;
	}

	public function canViewFullProfile(&$error = null)
	{
		if (!$this->canViewApiUserProfile($error))
		{
			return false;
		}

		return parent::canViewFullProfile($error);
	}

	public function canViewBasicProfile(&$error = null)
	{
		if (!$this->canViewApiUserProfile($error))
		{
			return false;
		}

		return parent::canViewBasicProfile($error);
	}

	/**
	 * An API user is not a member to the public: its profile is for the account itself and for
	 * staff who can already see past a member's privacy settings.
	 */
	protected function canViewApiUserProfile(&$error = null)
	{
		if (!$this->isApiUser())
		{
			return true;
		}

		$visitor = \XF::visitor();
		if ($visitor->user_id == $this->user_id || $visitor->canBypassUserPrivacy())
		{
			return true;
		}

		$error = \XF::phraseDeferred('this_users_profile_is_not_available');
		return false;
	}

	protected function _preSave()
	{
		parent::_preSave();

		// Every route that sets an email address ends here - the admin user edit form, the REST
		// API, the email change service - so this is the one refusal that covers them all.
		if ($this->isUpdate() && $this->email !== '' && $this->isApiUser())
		{
			$this->error(\XF::phrase('hampel_apiusers_api_user_cannot_have_email_address'), 'email');
		}
	}

	protected function _postDelete()
	{
		parent::_postDelete();

		$this->db()->delete('xf_hampel_apiusers_user', 'user_id = ?', $this->user_id);
	}

	public static function getStructure(Structure $structure)
	{
		$structure = parent::getStructure($structure);

		$structure->relations['ApiUser'] = [
			'entity' => 'Hampel\ApiUsers:ApiUser',
			'type' => self::TO_ONE,
			'conditions' => 'user_id',
			'primary' => true,
		];

		return $structure;
	}
}
