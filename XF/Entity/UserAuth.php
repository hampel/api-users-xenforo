<?php namespace Hampel\ApiUsers\XF\Entity;

use Hampel\ApiUsers\Authentication\ApiOnly;

class UserAuth extends XFCP_UserAuth
{
	/**
	 * @return void
	 */
	protected function _preSave()
	{
		parent::_preSave();

		// Every route that gives an account a password ends in this save - the admin user edit
		// form, the REST API, a lost-password reset, an administrator being added - so refusing
		// the scheme change here covers them all. It cannot cover a write that bypasses the
		// entity, which is why api-users:validate checks for drift.
		if ($this->scheme_class !== ApiOnly::NAME
			&& $this->user_id
			&& $this->repository('Hampel\ApiUsers:ApiUser')->isApiUser($this->user_id)
		)
		{
			$this->error(\XF::phrase('hampel_apiusers_api_user_cannot_have_password'), 'password');
		}
	}
}
