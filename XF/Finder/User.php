<?php namespace Hampel\ApiUsers\XF\Finder;

class User extends XFCP_User
{
	/**
	 * Nearly every public listing of members asks for valid users through this method - the
	 * member list, the newest members and member statistics widgets, birthdays, the board's user
	 * count and latest member, the REST API's user list, name lookups - so excluding API users
	 * here removes them from all of those at once.
	 *
	 * Not in the admin control panel: its username autocomplete asks the same question, and an
	 * API user has to be findable there.
	 *
	 * Registered above the default execute_order on purpose. An extension of this method that
	 * replaces it without calling the parent discards every condition added inside it, so this
	 * one has to be outside.
	 *
	 * @param bool $recentlyActive
	 * @return $this
	 */
	public function isValidUser($recentlyActive = false)
	{
		parent::isValidUser($recentlyActive);

		if (!(\XF::app() instanceof \XF\Admin\App))
		{
			$this->where('ApiUser.user_id', null);
		}

		return $this;
	}
}
