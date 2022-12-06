<?php


/**
 * Name: Emojione
 * Description: Emoji emoticons
 * Version: 1.0
 *
 */



function emojione_load() {
	\Zotlabs\Extend\Hook::register('smilie','addon/emojione/emojione.php', [ '\\Emojione' , 'smilie' ]);
}

function emojione_unload() {
	\Zotlabs\Extend\Hook::unregister('smilie','addon/emojione/emojione.php', [ '\\Emojione' , 'smilie' ]);
}


class Emojione {

	static $listing = null;

	static public function smilie(&$x) {

		if(! self::$listing)
			self::$listing = json_decode(@file_get_contents('addon/emojione/emoji.json'),true);

		if(self::$listing) {
			foreach(self::$listing as $lv) {
				if(strpos($lv['shortname'],':tone') === 0)
					continue;
				$x['texts'][] = $lv['shortname'];
				$x['icons'][] = '<img class="smiley emoji" style="height: 1.2em; width: 1.2em;" src="addon/emojione/emojis/' . $lv['unicode'] . '.png' . '" alt="' . $lv['name'] . '" />';
			}
		}
	}
}
