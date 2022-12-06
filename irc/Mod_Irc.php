<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Irc extends Controller {

	function get() {

		$o = '';

		/* set the list of popular channels */
		$sitechats = get_config('irc','sitechats');
		if($sitechats)
			$chats = explode(',',$sitechats);
		else
			$chats = array('hubzilla','friendica','chat','chatback','hottub','ircbar','dateroom','debian');


		App::$page['aside'] .= '<div class="widget"><h3>' . t('Popular Channels') . '</h3><ul>';
		foreach($chats as $chat) {
			App::$page['aside'] .= '<li><a href="' . z_root() . '/irc?channels=' . $chat . '" >' . '#' . $chat . '</a></li>';
		}
		App::$page['aside'] .= '</ul></div>';

		/* setting the channel(s) to auto connect */
		$autochans = get_config('irc','autochans');
		if($autochans)
			$channels = $autochans;
		else
			$channels = ((x($_GET,'channels')) ? $_GET['channels'] : 'hubzilla');

		/* add the chatroom frame and some html */
		$o .= <<< EOT
<h2>IRC chat</h2>
<p><a href="http://tldp.org/HOWTO/IRC/beginners.html" target="_blank">A beginner's guide to using IRC. [en]</a></p>
<iframe src="//webchat.freenode.net?channels=$channels" width="100%" height="600"></iframe>
EOT;

		return $o;
	    
	}

}


