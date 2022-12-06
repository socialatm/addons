<?php

use Zotlabs\Lib\Apps;
use Zotlabs\Lib\Crypto;

function diaspora_prepare_outbound($msg,$owner,$contact,$owner_prvkey,$contact_pubkey,$public = false) {

	if(defined('DIASPORA_V2')) {
		$post = diaspora_v2_build($msg,$owner,$contact,$owner_prvkey,$contact_pubkey,$public);
		logger('diaspora_v2:' . print_r($post,true));
		return $post;
	}
	else {
		return 'xml=' . urlencode(urlencode(diaspora_msg_build($msg,$owner,$contact,$owner_prvkey,$contact_pubkey,$public)));
	}
}


function diaspora_v2_build($msg,$channel,$contact,$prvkey,$pubkey,$public = false) {

	logger('diaspora_v2_build: ' . $msg, LOGGER_DATA, LOG_DEBUG);

    $handle      = channel_reddress($channel);
	$enchandle   = base64url_encode($handle,false);
	$b64url_data = base64url_encode($msg,false);

	$data = str_replace(array("\n","\r"," ","\t"),array('','','',''),$b64url_data);

	$type = 'application/xml';
	$encoding = 'base64url';
	$alg = 'RSA-SHA256';

	$signable_data = $data  . '.' . base64url_encode($type,false) . '.'
		. base64url_encode($encoding,false) . '.' . base64url_encode($alg,false) ;

	$signature = Crypto::sign($signable_data,$prvkey);
	$sig = base64url_encode($signature,false);

$magic_env = <<< EOT
<?xml version='1.0' encoding='UTF-8'?>
<me:env xmlns:me="http://salmon-protocol.org/ns/magic-env">
	<me:encoding>base64url</me:encoding>
	<me:alg>RSA-SHA256</me:alg>
	<me:data type="application/xml">$data</me:data>
	<me:sig key_id="$enchandle">$sig</me:sig>
</me:env>
EOT;

	logger('diaspora_v2_build: magic_env: ' . $magic_env, LOGGER_DATA, LOG_DEBUG);

	if($public)
		return $magic_env;

	// if not public, generate the json encryption packet

	$key = openssl_random_pseudo_bytes(32);
	$iv  = openssl_random_pseudo_bytes(16);
	$data = AES256CBC_encrypt($magic_env,$key,$iv);

	$aes_key1 = '';

	$aes_key = json_encode([
		'key' => base64_encode($key),
		'iv'  => base64_encode($iv),
	]);

	openssl_public_encrypt($aes_key,$aes_key1,$pubkey);

	$j = [
		'aes_key' => base64_encode($aes_key1),
		'encrypted_magic_envelope' => base64_encode($data)
	];
	return json_encode($j);

}

function diaspora_pubmsg_build($msg,$channel,$contact,$prvkey,$pubkey) {

	logger('diaspora_pubmsg_build: ' . $msg, LOGGER_DATA, LOG_DEBUG);

    $handle = channel_reddress($channel);

	$b64url_data = base64url_encode($msg,false);

	$data = str_replace(array("\n","\r"," ","\t"),array('','','',''),$b64url_data);

	$type = 'application/xml';
	$encoding = 'base64url';
	$alg = 'RSA-SHA256';

	$signable_data = $data  . '.' . base64url_encode($type,false) . '.'
		. base64url_encode($encoding,false) . '.' . base64url_encode($alg,false) ;

	$signature = Crypto::sign($signable_data,$prvkey);
	$sig = base64url_encode($signature,false);

$magic_env = <<< EOT
<?xml version='1.0' encoding='UTF-8'?>
<diaspora xmlns="https://joindiaspora.com/protocol" xmlns:me="http://salmon-protocol.org/ns/magic-env" >
  <header>
	<author_id>$handle</author_id>
  </header>
  <me:env>
	<me:encoding>base64url</me:encoding>
	<me:alg>RSA-SHA256</me:alg>
	<me:data type="application/xml">$data</me:data>
	<me:sig>$sig</me:sig>
  </me:env>
</diaspora>
EOT;

	logger('diaspora_pubmsg_build: magic_env: ' . $magic_env, LOGGER_DATA, LOG_DEBUG);
	return $magic_env;

}




function diaspora_msg_build($msg,$channel,$contact,$prvkey,$pubkey,$public = false) {

	if($public)
		return diaspora_pubmsg_build($msg,$channel,$contact,$prvkey,$pubkey);

	logger('diaspora_msg_build: ' . $msg, LOGGER_DATA, LOG_DEBUG);

	// without a public key nothing will work

	if(! $pubkey) {
		logger('diaspora_msg_build: pubkey missing: contact id: ' . $contact['abook_id'], LOG_ERR);
		return '';
	}

	$inner_aes_key = random_string(32);
	$b_inner_aes_key = base64_encode($inner_aes_key);
	$inner_iv = random_string(16);
	$b_inner_iv = base64_encode($inner_iv);

	$outer_aes_key = random_string(32);
	$b_outer_aes_key = base64_encode($outer_aes_key);
	$outer_iv = random_string(16);
	$b_outer_iv = base64_encode($outer_iv);

    $handle = channel_reddress($channel);


	$inner_encrypted = AES256CBC_encrypt($msg,$inner_aes_key,$inner_iv);

	$b64_data = base64_encode($inner_encrypted);

	$b64url_data = base64url_encode($b64_data,false);
	$data = str_replace(array("\n","\r"," ","\t"),array('','','',''),$b64url_data);

	$type = 'application/xml';
	$encoding = 'base64url';
	$alg = 'RSA-SHA256';

	$signable_data = $data  . '.' . base64url_encode($type,false) . '.'
		. base64url_encode($encoding,false) . '.' . base64url_encode($alg,false) ;

	logger('diaspora_msg_build: signable_data: ' . $signable_data, LOGGER_DATA, LOG_DEBUG);

	$signature = Crypto::sign($signable_data,$prvkey);
	$sig = base64url_encode($signature,false);

$decrypted_header = <<< EOT
<decrypted_header>
  <iv>$b_inner_iv</iv>
  <aes_key>$b_inner_aes_key</aes_key>
  <author_id>$handle</author_id>
</decrypted_header>
EOT;

	$ciphertext = AES256CBC_encrypt($decrypted_header,$outer_aes_key,$outer_iv);

	$outer_json = json_encode(array('iv' => $b_outer_iv,'key' => $b_outer_aes_key));

	$encrypted_outer_key_bundle = '';
	openssl_public_encrypt($outer_json,$encrypted_outer_key_bundle,$pubkey);

	$b64_encrypted_outer_key_bundle = base64_encode($encrypted_outer_key_bundle);

	logger('outer_bundle: ' . $b64_encrypted_outer_key_bundle . ' key: ' . $pubkey, LOGGER_DATA, LOG_DEBUG);

	$encrypted_header_json_object = json_encode(array('aes_key' => base64_encode($encrypted_outer_key_bundle),
		'ciphertext' => base64_encode($ciphertext)));
	$cipher_json = base64_encode($encrypted_header_json_object);

	$encrypted_header = '<encrypted_header>' . $cipher_json . '</encrypted_header>';

$magic_env = <<< EOT
<?xml version='1.0' encoding='UTF-8'?>
<diaspora xmlns="https://joindiaspora.com/protocol" xmlns:me="http://salmon-protocol.org/ns/magic-env" >
  $encrypted_header
  <me:env>
	<me:encoding>base64url</me:encoding>
	<me:alg>RSA-SHA256</me:alg>
	<me:data type="application/xml">$data</me:data>
	<me:sig>$sig</me:sig>
  </me:env>
</diaspora>
EOT;

	logger('diaspora_msg_build: magic_env: ' . $magic_env, LOGGER_DATA, LOG_DEBUG);
	return $magic_env;

}


function diaspora_share($owner,$contact) {

	$allowed = Apps::addon_app_installed($owner['channel_id'], 'diaspora');

	if(! intval($allowed)) {
		logger('diaspora_share: disallowed for channel ' . $owner['channel_name']);
		return;
	}

	$myaddr = channel_reddress($owner);

	if(! array_key_exists('hubloc_hash',$contact)) {
		$c = q("select * from xchan left join hubloc on xchan_hash = hubloc_hash where xchan_hash = '%s' limit 1",
			dbesc($contact['xchan_hash'])
		);
		if(! $c) {
			logger('diaspora_share: ' . $contact['xchan_hash']  . ' not found.');
			return;
		}
		$contact = $c[0];
	}

	$theiraddr = $contact['xchan_addr'];

	if(defined('DIASPORA_V2')) {
		$msg = arrtoxml('contact',
			[
				'author'    => $myaddr,
				'recipient' => $theiraddr,
				'following' => 'true',
				'sharing'   => 'true'
			]
		);

	}
	else {
		$tpl = get_markup_template('diaspora_share.tpl','addon/diaspora');
		$msg = replace_macros($tpl, array(
			'$sender' => $myaddr,
			'$recipient' => $theiraddr
		));
	}

	$slap = diaspora_prepare_outbound($msg,$owner,$contact,$owner['channel_prvkey'],$contact['xchan_pubkey']);

	return(diaspora_queue($owner,$contact,$slap, false));
	return;

}

function diaspora_unshare($owner,$contact) {

	$myaddr    = channel_reddress($owner);
	$theiraddr = $contact['xchan_addr'];

	if(defined('DIASPORA_V2')) {
		$msg = arrtoxml('contact',
			[
				'author'    => $myaddr,
				'recipient' => $theiraddr,
				'following' => 'false',
				'sharing'   => 'false'
			]
		);
	}
	else {
		$tpl = get_markup_template('diaspora_retract.tpl','addon/diaspora');
		$msg = replace_macros($tpl, array(
			'$guid'   => $owner['channel_guid'] . str_replace('.','',App::get_hostname()),
			'$type'   => 'Person',
			'$handle' => $myaddr
		));
	}

	$slap = diaspora_prepare_outbound($msg,$owner,$contact,$owner['channel_prvkey'],$contact['xchan_pubkey']);
	return(diaspora_queue($owner,$contact,$slap, false));
}


function diaspora_send_migration($item,$owner,$contact,$public_batch = false) {
	// @TODO: send account_migration message
	// called on keychange with $item set to the keychange packet.
	// @TODO call on account move operation (as opposed to a clone operation)


}

function diaspora_send_status($item,$owner,$contact,$public_batch = false) {

	$msg = diaspora_build_status($item,$owner);
	if(! $msg)
		return [];

	logger('diaspora_send_status: '.$owner['channel_name'].' -> '.$contact['xchan_name'].' base message: ' . $msg, LOGGER_DATA);
	$slap = diaspora_prepare_outbound($msg,$owner,$contact,$owner['channel_prvkey'],$contact['xchan_pubkey'], $public_batch);

	$qi = array(diaspora_queue($owner,$contact,$slap,$public_batch,$item['mid']));
	return $qi;
}

function diaspora_is_reshare($body) {

	$body = trim($body);

	// Skip if it isn't a pure repeated messages
	// Does it start with a share?
	if(strpos($body, "[share") > 0)
		return(false);

	// Does it end with a share?
	if(strlen($body) > (strrpos($body, "[/share]") + 8))
		return(false);

	$attributes = preg_replace("/\[share(.*?)\]\s?(.*?)\s?\[\/share\]\s?/ism","$1",$body);
	// Skip if there is no shared message in there
	if ($body == $attributes)
		return(false);

	$profile = "";
	preg_match("/profile='(.*?)'/ism", $attributes, $matches);
	if ($matches[1] != "")
		$profile = $matches[1];

	preg_match('/profile="(.*?)"/ism', $attributes, $matches);
	if ($matches[1] != "")
		$profile = $matches[1];

	$ret= array();

	$ret["root_handle"] = preg_replace("=https?://(.*)/u/(.*)=ism", "$2@$1", $profile);
	if (($ret["root_handle"] == $profile) OR ($ret["root_handle"] == ""))
		return(false);

	$link = "";
	preg_match("/link='(.*?)'/ism", $attributes, $matches);
	if ($matches[1] != "")
		$link = $matches[1];

	preg_match('/link="(.*?)"/ism', $attributes, $matches);
	if ($matches[1] != "")
		$link = $matches[1];

	$ret["root_guid"] = preg_replace("=https?://(.*)/posts/(.*)=ism", "$2", $link);
	if (($ret["root_guid"] == $link) OR ($ret["root_guid"] == ""))
		return(false);

	return($ret);
}


function diaspora_is_repeat($item) {


	if($item['verb'] !== 'Announce') {
		return false;
	}

	$r = q("select * from item where mid = '%s' and uid = %d and item_private = 0 limit 1",
		dbesc($item['parent_mid'])
	);

	if(! $r) {
		return false;
	}

	xchan_query($r,true);
	$r = fetch_post_tags($r,true);

	$ret = [];

	$ret['root_handle'] = $r[0]['author']['xchan_addr'];

	if(! $ret['root_handle']) {
		return false;
	}

	$ret['root_guid'] = $r[0]['uuid'];
	if(! $ret['root_guid']) {
		return false;
	}

	return $ret;
}



function diaspora_send_images($item,$owner,$contact,$images,$public_batch = false) {

	if(! count($images))
		return;
	$mysite = substr(z_root(),strpos(z_root(),'://') + 3) . '/photo';

	$qi = array();

	$tpl = get_markup_template('diaspora_photo.tpl','addon/diaspora');
	foreach($images as $image) {
		if(! stristr($image['path'],$mysite))
			continue;
		$resource = str_replace('.jpg','',$image['file']);
		$resource = substr($resource,0,strpos($resource,'-'));

		$r = q("select * from photo where resource_id = '%s' and uid = %d limit 1",
			dbesc($resource),
			intval($owner['uid'])
		);
		if(! $r)
			continue;
		$public = (($r[0]['allow_cid'] || $r[0]['allow_gid'] || $r[0]['deny_cid'] || $r[0]['deny_gid']) ? 'false' : 'true' );
		$msg = replace_macros($tpl,array(
			'$path' => xmlify($image['path']),
			'$filename' => xmlify($image['file']),
			'$msg_guid' => xmlify($image['guid']),
			'$guid' => xmlify($r[0]['resource_id']),
			'$handle' => xmlify($image['handle']),
			'$public' => xmlify($public),
			'$created_at' => xmlify(datetime_convert('UTC','UTC',$r[0]['created'],'Y-m-d H:i:s \U\T\C'))
		));

		logger('diaspora_send_photo: base message: ' . $msg, LOGGER_DATA);
		$slap = 'xml=' . urlencode(urlencode(diaspora_msg_build($msg,$owner,$contact,$owner['channel_prvkey'],$contact['xchan_pubkey'],$public_batch)));

		$qi[] = diaspora_queue($owner,$contact,$slap,$public_batch,$item['mid']);
	}

	return $qi;

}

function diaspora_send_upstream($item,$owner,$contact,$public_batch = false,$uplink = false) {

	logger('diaspora_send_upstream');

	$myaddr = channel_reddress($owner);
	$theiraddr = $contact['xchan_addr'];

	$conv_like = false;
	$sub_like  = false;
	$attendance = false;

	if($uplink) {
		logger('uplink not supported');
		return;
	}

	if(activity_match($item['verb'],[ ACTIVITY_ATTEND, ACTIVITY_ATTENDNO, ACTIVITY_ATTENDMAYBE ])
		&& activity_match($item['obj_type'],[ ACTIVITY_OBJ_NOTE ])) {
		$attendance = true;
	}
	if(activity_match($item['verb'],[ ACTIVITY_LIKE, ACTIVITY_DISLIKE ])
		&& activity_match($item['obj_type'],[ ACTIVITY_OBJ_NOTE, ACTIVITY_OBJ_COMMENT ])) {
		$conv_like = true;
		if(($item['thr_parent']) && ($item['thr_parent'] != $item['parent_mid']))
			$sub_like = true;
	}

	if($sub_like) {
		$p = q("select mid, parent_mid from item where mid = '%s' and uid = %d limit 1",
			dbesc($item['thr_parent']),
			intval($item['uid'])
		);
	}
	else {
		$p = q("select * from item where id = %d and id = parent limit 1",
			intval($item['parent'])
		);
	}
	if($p)
		$parent = $p[0];
	else
		return;

	diaspora_deliver_local_comments($item,$parent);

	$signed_fields = get_iconfig($item,'diaspora','fields');

	if($signed_fields) {
		if($attendance) {
			$msg = arrtoxml('event_participation', $signed_fields);
		}
		else {
			$msg = arrtoxml((($conv_like) ? 'like' : 'comment' ), $signed_fields);
		}
	}
	else {
		return;
	}

	logger('diaspora_send_upstream: base message: ' . $msg, LOGGER_DATA);

	$slap = diaspora_prepare_outbound($msg,$owner,$contact,$owner['channel_prvkey'],$contact['xchan_pubkey'],$public_batch);
	return(diaspora_queue($owner,$contact,$slap,$public_batch,$item['mid']));
}

// Diaspora will not send comments downstream to the system it originated from.
// So we have to deliver comments/likes/etc. to everybody on this site that
// received the parent. ***IMPORTANT*** check the owner xchan of the parent
// because others on this system may have received the comment through another
// route or delivery chain.

function diaspora_deliver_local_comments($item,$parent) {

	$r = q("select aid, uid from item where uuid = '%s' and owner_xchan = '%s'",
		dbesc($parent['uuid']),
		dbesc($parent['owner_xchan'])
	);

	if(! $r)
		return;

	$new_item = $item;
	unset($new_item['owner']);
	unset($new_item['author']);
	unset($new_item['id']);
	unset($new_item['parent']);

	foreach($r as $rv) {
		if($rv['uid'] === $item['uid'])
			continue;
		$new_item['uid'] = $rv['uid'];
		$new_item['aid'] = $rv['aid'];
		item_store($new_item);
	}

}




function diaspora_send_downstream($item,$owner,$contact,$public_batch = false) {


	$myaddr = channel_reddress($owner);
	$theiraddr = $contact['xchan_addr'];

	if($item['item_deleted']) {
		return diaspora_send_retraction($item,$owner,$contact,$public_batch);
	}

	$conv_like = false;
	$sub_like  = false;
	$attendance = false;

	if(activity_match($item['verb'],[ ACTIVITY_ATTEND, ACTIVITY_ATTENDNO, ACTIVITY_ATTENDMAYBE ])
		&& activity_match($item['obj_type'],[ ACTIVITY_OBJ_NOTE ])) {
		$attendance = true;
	}

	if(activity_match($item['verb'], [ ACTIVITY_LIKE, ACTIVITY_DISLIKE ])
		&& activity_match($item['obj_type'],[ ACTIVITY_OBJ_NOTE, ACTIVITY_OBJ_COMMENT ])) {
		$conv_like = true;
		if(($item['thr_parent']) && ($item['thr_parent'] != $item['parent_mid']))
			$sub_like = true;
	}

	if($sub_like) {
		$p = q("select mid, parent_mid from item where mid = '%s' and uid = %d limit 1",
			dbesc($item['thr_parent']),
			intval($item['uid'])
		);
	}
	else {
		$p = q("select * from item where id = %d and id = parent limit 1",
			intval($item['parent'])
		);
	}
	if($p)
		$parent = $p[0];
	else
		return;

	$signed_fields = get_iconfig($item,'diaspora','fields');

	if($signed_fields) {
		if($attendance) {
			$msg = arrtoxml('event_participation', $signed_fields);
		}
		else {
			$msg = arrtoxml((($conv_like) ? 'like' : 'comment' ), $signed_fields);
		}
	}
	else {
		if($conv_like)
			return;
		if(get_pconfig($owner['channel_id'],'diaspora','sign_unsigned')) {
			// copy the data so we can mess with it.
			$fake_item = $item;
			// change the body to a simulated reshare of the author's content
			diaspora_share_unsigned($fake_item,(($fake_item['author']) ? $fake_item['author'] : null));
			// change the author to the item owner who will sign it
			$fake_item['author_xchan'] = $fake_item['owner_xchan'];
			$fake_item['author'] = $fake_item['owner'];
			// The diaspora_post_local callback will sign it (with the owner's sig, since the author didn't supply one).
			diaspora_post_local($fake_item);
			// extract the signature we just created
			$signed_fields = get_iconfig($fake_item,'diaspora','fields');
			$msg = arrtoxml((($conv_like) ? 'like' : 'comment' ), $signed_fields);
		}
		else {
			return;
		}
	}

	logger('diaspora_send_downstream: base message: ' . $msg, LOGGER_DATA);

    $slap = diaspora_prepare_outbound($msg,$owner,$contact,$owner['channel_prvkey'],$contact['xchan_pubkey'],$public_batch);
    return(diaspora_queue($owner,$contact,$slap,$public_batch,$item['mid']));

}


function diaspora_send_retraction($item,$owner,$contact,$public_batch = false) {

	$myaddr = channel_reddress($owner);

	if(! $item['item_deleted'])
		return;

	$r = q("select xchan_addr from xchan where xchan_hash = '%s' limit 1",
		dbesc($item['author_xchan'])
	);
	if($r) {
		$author = $r[0]['xchan_addr'];
	}
	else
		return;


	if( $item['mid'] !== $item['parent_mid'] ) {
		if(($item['verb'] === ACTIVITY_LIKE || $item['verb'] === ACTIVITY_DISLIKE) && ($item['obj_type'] === ACTIVITY_POST || $item['obj_type'] === ACTIVITY_OBJ_COMMENT)) {
			$target_type = 'Like';
		}
		else {
			$target_type = 'Comment';
		}
	}
	else {
		$target_type = 'Post';
	}

	$fields = [
		'author'      => $author,
		'target_guid' => $item['uuid'],
		'target_type' => $target_type
	];

	$msg = arrtoxml('retraction',$fields);

	$slap = diaspora_prepare_outbound($msg,$owner,$contact,$owner['channel_prvkey'],$contact['xchan_pubkey'],$public_batch);
	return(diaspora_queue($owner,$contact,$slap,$public_batch,$item['mid']));
}

function diaspora_send_mail($item, $contact) {

	$owner = channelx_by_hash($item['target_item']['author']['xchan_hash']);
	$fields = get_iconfig($item['target_item'], 'diaspora', 'fields');

	if($item['top_level_post'])
		$outmsg = arrtoxml('conversation', $fields);
	else
		$outmsg = arrtoxml('message', $fields);

	$slap = diaspora_prepare_outbound($outmsg, $owner, $contact, $owner['channel_prvkey'], $contact['xchan_pubkey'], false);
	return(diaspora_queue($owner, $contact, $slap, false, $item['target_item']['mid']));

}


function diaspora_profile_change($channel,$recip,$public_batch = false,$profile_visible = false) {


	$channel_id = $channel['channel_id'];

	$r = q("SELECT profile.uid AS profile_uid, profile.* , channel.* FROM profile
		left join channel on profile.uid = channel.channel_id
		WHERE channel.channel_id = %d and profile.is_default = 1 ",
		intval($channel_id)
	);

	if(! $r)
		return;
	$profile = $r[0];

	$handle = xmlify(channel_reddress($channel));
	$fn = xmlify($profile['channel_name']);
	$first = xmlify(((strpos($profile['channel_name'],' '))
		? trim(substr($profile['channel_name'],0,strpos($profile['channel_name'],' '))) : $profile['channel_name']));
	$last = xmlify((($first === $profile['channel_name']) ? '' : trim(substr($profile['channel_name'],strlen($first)))));
	$large = xmlify(z_root() . '/photo/profile/300/' . $profile['profile_uid'] . '.jpg');
	$medium = xmlify(z_root() . '/photo/profile/100/' . $profile['profile_uid'] . '.jpg');
	$small = xmlify(z_root() . '/photo/profile/50/'  . $profile['profile_uid'] . '.jpg');

	$searchable = xmlify((($profile_visible) ? 'true' : 'false' ));

	$nsfw = (($channel['channel_pageflags'] & (PAGE_ADULT|PAGE_CENSORED)) ? 'true' : 'false' );

	if($searchable === 'true') {
		$dob = '1000-00-00';

		if(($profile['dob']) && ($profile['dob'] != '0000-00-00'))
			$dob = ((intval(substr($profile['dob'],0,4))) ? intval($profile['dob']) : '1000') . '-' . datetime_convert('UTC','UTC',$profile['dob'],'m-d');
		if($dob === '1000-00-00')
			$dob = '';
		$gender = xmlify($profile['gender']);
		$about = $profile['about'];
		require_once('include/bbcode.php');
		$about = xmlify(strip_tags(bbcode($about)));
		$location = '';
		if($profile['locality'])
			$location .= $profile['locality'];
		if($profile['region']) {
			if($location)
				$location .= ', ';
			$location .= $profile['region'];
		}
		if($profile['country_name']) {
			if($location)
				$location .= ', ';
			$location .= $profile['country_name'];
		}
		$location = xmlify($location);
		$tags = '';
		if($profile['keywords']) {
			$kw = str_replace(',',' ',$profile['keywords']);
			$kw = str_replace('  ',' ',$kw);
			$arr = explode(' ',$profile['keywords']);
			if(count($arr)) {
				for($x = 0; $x < 5; $x ++) {
					if(trim($arr[$x]))
						$tags .= '#' . trim($arr[$x]) . ' ';
				}
			}
		}
		$tags = xmlify(trim($tags));
	}

	if(defined('DIASPORA_V2')) {

		$msg = [
			'author'           => $handle,
			'full_name'        => $fn,
			'first_name'       => $first,
			'last_name'        => $last,
			'image_url'        => $large,
			'image_url_medium' => $medium,
			'image_url_small'  => $small,
			'public'           => $searchable,
			'nsfw'             => $nsfw,
			'tag_string'       => $tags,
		];

		if($profile_visible) {
			$msg = array_merge($msg, [
				'birthday'         => $dob ,
				'gender'           => $gender,
				'bio'              => $about,
				'location'         => $location,
				'searchable'       => $searchable,
			]);
		}

		$outmsg = arrtoxml('profile',$msg);
		$slap = diaspora_prepare_outbound($outmsg,$channel,$recip,$channel['channel_prvkey'],$recip['xchan_pubkey'],$public_batch);
		return(diaspora_queue($channel,$recip,$slap,$public_batch,$item['mid']));
	}

	$tpl = get_markup_template('diaspora_profile.tpl','addon/diaspora');

	$msg = replace_macros($tpl, [
		'$handle'          => $handle,
		'$first'           => $first,
		'$last'            => $last,
		'$large'           => $large,
		'$medium'          => $medium,
		'$small'           => $small,
		'$dob'             => $dob,
		'$gender'          => $gender,
		'$about'           => $about,
		'$location'        => $location,
		'$profile_visible' => $profile_visible,
		'$searchable'      => $searchable,
		'$nsfw'            => $nsfw,
		'$tags'            => $tags
	]);

	logger('profile_change: ' . $msg, LOGGER_ALL, LOG_DEBUG);

	$slap = 'xml=' . urlencode(urlencode(diaspora_msg_build($msg,$channel,$recip,$channel['channel_prvkey'],$recip['xchan_pubkey'],$public_batch)));
	return(diaspora_queue($channel,$recip,$slap,$public_batch));

}

function diaspora_send_participation($channel, $contact, $item) {
	if(intval($item['item_private']))
		return;

	$msg = arrtoxml('participation',
		[
			'author'      => channel_reddress($channel),
			'guid'        => new_uuid(),
			'parent_type' => 'Post',
			'parent_guid' => $item['uuid']
		]
	);

	$slap = diaspora_prepare_outbound($msg, $channel, $contact, $channel['channel_prvkey'], $contact['xchan_pubkey']);
	return (diaspora_queue($channel, $contact, $slap, false));
}


