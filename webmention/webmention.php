<?php

/**
 * Name: Webmention
 * Description: (experimental) processes any webmentions discovered in a local posting.
 * Version: 1.0
 *
 */

require_once('library/HTML5/Parser.php');


// Please see https://webmention.rocks for test cases. This is a demo plugin which has not been fully tested. 

function webmention_load() {

	\Zotlabs\Extend\Hook::register('post_local_end','addon/webmention/webmention.php', 'webmention_post_local_end');
	\Zotlabs\Extend\Hook::register('daemon_addon','addon/webmention/webmention.php', 'webmention_daemon');


}

function webmention_unload() {

	\Zotlabs\Extend\Hook::unregister_by_file('addon/webmention/webmention.php');

}



function webmention_post_local_end(&$x) {

	if($x['item_type'] || $x['item_private'] || $x['resource_type'])
		return;
	\Zotlabs\Daemon\Master::Summon([ 'Addon', 'webmention', $x['id'] ]);
 
}


function webmention_daemon(&$x) {
	if($x[1] !== 'webmention')
		return;

	$item_normal = item_normal();

	$r = q("select * from item where id = %d $item_normal limit 1",
		intval($x[2])
	);
	if(! $r)
		return;

	$r = fetch_post_tags($r,true);

	if($r[0]['item_private'])
		return;

	if(! perm_is_allowed($r[0]['uid'],'','view_stream',false))
		return;

	if($r[0]['term'] && is_array($r[0]['term'])) {
		foreach($r[0]['term'] as $term) {
			if(($term['ttype'] == TERM_BOOKMARK) && ($term['url'])) {
				webmention_process($term['url'],$r[0]['plink']);
			}
		}
	}
}

function webmention_process_links($header,&$links) {
	$arr = explode(',',$header);
	if($arr) {
		foreach($arr as $rv) {
			if(preg_match('/\<(.*?)>;(.*?)rel\=(.*?)/',$rv,$matches)) {
				if(attribute_contains($matches[3],'webmention')) {
					$links[] = $matches[1];
					continue;
				}
			}
		}
	}
}	


function webmention_process($url,$source) {

	$redirects = 0;
	$x = z_fetch_url($url,false,$redirects,['novalidate' => true ]);
	if(! $x['success'])
		return;

	$html_content = false;
	$links = [];

	$h = new \Zotlabs\Web\HTTPHeaders($x['header']);
	$fields = $h->fetch();

	if($fields) {
		foreach($fields as $y) {
			if(array_key_exists('content-type',$y)) {
				$type = explode(';',$y['content-type']);
				if($type && trim($type[0]) === 'text/html') {
					$html_content = true;                    
					continue;
				}
			}
            if(array_key_exists('link',$y)) {
				webmention_process_links($y['link'],$links);
				continue;
            }
        }
	}

	$dom = new DOMDocument();

	if($links) {
		webmention_post($links,$url,$source);
	}
	elseif($html_content) {
		try {
			$dom->loadHTML($x['body']);
		}
		catch (DOMException $e) {
			logger('webmention: parse error: ' . $e);
    	}

    	if(! $dom)
        	return;
	}

	$items = $dom->getElementsByTagName('link');
	if($items) {
	    foreach($items as $item) {
    	    if(attribute_contains($item->getAttribute('rel'), 'webmention')) {
				$links[] = $item->getAttribute('href');
				break;
			}
		}
	}
	if($links) {
		webmention_post($links,$url,$source);
	}
	$items = $dom->getElementsByTagName('a');
	if($items) {
	    foreach($items as $item) {
    	    if(attribute_contains($item->getAttribute('rel'), 'webmention')) {
				$links[] = $item->getAttribute('href');
				break;
			}
		}
	}
	if($links) {
		webmention_post($links,$url,$source);
	}
}


function webmention_post($links,$url,$source) {

	if(! $links)
		return;

	$postopts = [ 'source' => $url, 'target' => $source ];

	$recurse = 0;

	foreach($links as $target) {
		$x = z_post_url($target,$postopts,$recurse, [ 'novalidate' => true ]);
		logger('post returns: ' . print_r($x,true), LOGGER_DATA, LOG_INFO);	
	}
}
