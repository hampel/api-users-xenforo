<?php namespace Hampel\ApiUsers\XF\Service\User;

/**
 * An API user is never merged, as the source or as the target.
 *
 * Merged into another account it would be deleted, and that account would inherit its API keys;
 * with another account merged into it, it would acquire that member's content and standing. Either
 * way an account made to hold a key stops being only that.
 *
 * This is the enforcement, because every merge ends here whatever started it. The control panel's
 * own form is refused earlier, with a message, by the controller extension.
 */
class Merge extends XFCP_Merge
{
	public function merge($maxRunTime = 0)
	{
		if ($this->source && $this->target && $this->source->user_id != $this->target->user_id)
		{
			foreach ([$this->source, $this->target] AS $user)
			{
				if ($user->isApiUser())
				{
					\XF::logError(
						"ApiUsers: Refused to merge {$this->source->username} ({$this->source->user_id}) into "
						. "{$this->target->username} ({$this->target->user_id}): {$user->username} is an API user."
					);

					// finished, having done nothing: the job that asked must not come back and try again
					return \XF\ContinuationResult::completed();
				}
			}
		}

		return parent::merge($maxRunTime);
	}
}
