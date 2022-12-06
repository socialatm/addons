<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Fediwordle extends Controller {

	function get() {
		if(!local_channel())
			return;

		if(!Apps::addon_app_installed(local_channel(), 'fediwordle')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Fediwordle');
			return Apps::app_render($papp, 'module');
		}

		$o = '<h2>' . t('Fediwordle App') . '</h2>';
		$o .= t('A distributed word game inspired by wordle.') . '<br><br>';
		$o .= t('To start a game, enter [wordle]your_word[/wordle] somewhere in a toplevel post.') . '<br>';
		$o .= t('Your contacts can post their guess in the comments.') . '<br>';
		$o .= t('Your channel will evaluate the guess and automatically post the response.') . '<br><br>';

		$o .= '🟢 ' . t('Correct letters') . '<br>';
		$o .= '🟡 ' . t('Letters contained in the word but at the wrong spot') . '<br>';
		$o .= '🔴 ' . t('Letters not contained in the word') . '<br>';

		return $o;
	}

}
