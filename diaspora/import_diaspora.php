<?php

use Zotlabs\Lib\Apps;
use Zotlabs\Lib\Connect;
use Zotlabs\Lib\AccessList;

require_once('include/markdown.php');
require_once('include/photo/photo_driver.php');

function import_diaspora_account($data) {

	$account = App::get_account();
	if(! $account)
		return false;

	$address = escape_tags($data['user']['username']);
	if(! $address) {
		notice( t('No username found in import file.') . EOL);
		return false;
	}

	$r = q("select * from channel where channel_address = '%s' limit 1",
		dbesc($address)
	);
	if($r) {
		// try at most ten times to generate a unique address.
		$x = 0;
		$found_unique = false;
		do {
			$tmp = $address . mt_rand(1000,9999);
			$r = q("select * from channel where channel_address = '%s' limit 1",
				dbesc($tmp)
			);
			if(! $r) {
				$address = $tmp;
				$found_unique = true;
				break;
			}
			$x ++;
		} while ($x < 10);
		if(! $found_unique) {
			logger('import_diaspora: duplicate channel address. randomisation failed.');
			notice( t('Unable to create a unique channel address. Import failed.') . EOL);
			return;
		}
	}

	$pr = $data['user']['profile']['entity_data'];

	$c = create_identity(array(
		'name' => escape_tags($pr['first_name'] . (($pr['last_name']) ? ' ' . $pr['last_name'] : '')),
		'nickname' => $address,
		'account_id' => $account['account_id'],
		'permissions_role' => 'social'
	));

	if(! $c['success'])
		return;

	$channel_id = $c['channel']['channel_id'];

	if(! Apps::addon_app_installed($channel_id, 'diaspora')) {
		Apps::app_install($channel_id, 'Diaspora Protocol');
	}

	// todo - add auto follow settings, (and strip exif in hubzilla)

	$location = escape_tags($pr['location']);
	if(! $location)
		$location = '';


	q("update channel set channel_location = '%s' where channel_id = %d",
		dbesc($location),
		intval($channel_id)
	);

	if($pr['nsfw']) {
		q("update channel set channel_pageflags = (channel_pageflags | %d) where channel_id = %d",
				intval(PAGE_ADULT),
				intval($channel_id)
		);
	}

	if($pr['image_url']) {
		$type = import_channel_photo_from_url($pr['image_url']);

	}

	$gender = escape_tags($pr['gender']);
	$about = markdown_to_bb($pr['bio'], false, [ 'diaspora' => true ]);
	$publish = intval($pr['searchable']);
	if($pr['birthday'])
		$dob = datetime_convert('UTC','UTC',$pr['birthday'],'Y-m-d');
	else
		$dob = '0000-00-00';

	// we're relying on the fact that this channel was just created and will only
	// have the default profile currently

	$r = q("update profile set gender = '%s', about = '%s', dob = '%s', publish = %d where uid = %d",
		dbesc($gender),
		dbesc($about),
		dbesc($dob),
		dbesc($publish),
		intval($channel_id)
	);

	if($data['user']['contact_groups']) {
		foreach($data['user']['contact_groups'] as $aspect) {
			AccessList::add($channel_id,escape_tags($aspect['name']),intval($aspect['contacts_visible']));
		}
	}

	// now add connections and send friend requests

	if($data['user']['contacts']) {
		foreach($data['user']['contacts'] as $contact) {
			$result = Connect::connect($c['channel'], $contact['account_id']);
			if($result['success']) {
				if($contact['contact_groups_membership']) {
					foreach($contact['contact_groups_membership'] as $aspect) {
						AccessList::member_add($channel_id,$aspect['name'],$result['abook']['xchan_hash']);
					}
				}
			}
		}
	}


	// Then add items - note this can't be done until Diaspora adds guids to exported
	// items and comments



	// This will indirectly perform a refresh_all *and* update the directory

	proc_run('php', 'include/directory.php', $channel_id);

	notice( t('Import completed.') . EOL);

	change_channel($channel_id);

	goaway(z_root() . '/network' );

}
