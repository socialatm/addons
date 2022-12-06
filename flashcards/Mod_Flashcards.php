<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;
use Zotlabs\Storage\Directory;
use Zotlabs\Storage\File;
use Zotlabs\Storage\BasicAuth;
use Zotlabs\Access\AccessList;

class Flashcards extends Controller {

    private $version = "2.09";

    private $boxesDir;
    private $is_owner;
    private $owner;
    private $observer;
    private $canWrite;
    private $auth;
	private $authObserver;

    function init() {

        $this->observer = App::get_observer();

		logger('This is the observer...', LOGGER_DEBUG);
		logger(print_r($this->observer, true), LOGGER_DEBUG);

        $this->checkOwner();

//        $this->flashcards_merge_test();
    }

    function get() {

		if(!$this->owner) {
			if (local_channel()) { // if no channel name was provided, assume the current logged in channel
				$channel = App::get_channel();
				logger('No nick but local channel - channel = ' . $channel);
				if ($channel && $channel['channel_address']) {
					$nick = $channel['channel_address'];
					goaway(z_root() . '/flashcards/' . $nick);
				}
			}
		}

        if (!$this->owner) {
            logger('No nick and no local channel');
            notice(t('Profile Unavailable.') . EOL);
            goaway(z_root());
        }

		if(! Apps::addon_app_installed($this->owner['channel_id'], 'flashcards')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Flashcards');
			return Apps::app_render($papp, 'module');
		}

        $status = $this->permChecks();

        if(! $status['status']) {
            logger('observer prohibited');
            notice($status['errormsg'] . EOL);
            goaway(z_root());
        }


        head_add_css('/addon/flashcards/view/css/flashcards.css');

        $o = replace_macros(get_markup_template('flashcards.tpl','addon/flashcards'),array(
                '$post_url' => 'flashcards/' . $this->owner['channel_address'],
                '$nick' => $this->owner['channel_address'],
                '$is_owner' => $this->is_owner,
                '$flashcards_editor' => $this->observer['xchan_addr'],
                '$flashcards_owner' => $this->owner['xchan_addr'],
                '$is_local_channel' => ((local_channel() && $this->observer) ? true : false),
                '$is_allowed_to_create_box' => ($this->isObserverAllowedToCreateFlashcards() ? true : false),
                '$flashcards_version' => $this->version
        ));

        return $o;
    }

    function post() {

		if(argc() > 1) {
			if(strcasecmp ( argv(1) , 'search') === 0) {
				// API: /flashcards/search
				// get all boxes of flashcards the observer is allowed to see
				logger('Another instance/server requests all boxes of flashcards', LOGGER_DEBUG);
				if(!$this->observer) {
					$msg = "Failed to sent all boxes of flashcards. Reason: No observer found.";
					logger($msg, LOGGER_DEBUG);
					json_return_and_die(array('status' => false, 'errormsg' => $msg . EOL));
				} else {
					$msg = "Failed to sent all boxes of flashcards. Reason: Not implemented.";
					logger($msg, LOGGER_DEBUG);
					json_return_and_die(array('status' => false, 'errormsg' => $msg . EOL));
				}
			}
		}

		if(! Apps::addon_app_installed($this->owner['channel_id'], 'flashcards')) {
			return;
		}

        $status = $this->permChecks();

        if(! $status['status']) {
            notice($status['errormsg'] . EOL);
            json_return_and_die(array('status' => false, 'errormsg' => $status['errormsg'] . EOL));
        }

        // If an observer is allowed to view flashcards of the owner then
        // he can automatically use these flashcards. The addon will create a
        // copy for the observer. Using this copy he will be able to
        // - edit the cards
        // - reshare the edits
        // - store his learning progress.
        // The observer will never own his copy (including learning progress).
        // At every time the owner can
        // - deny the permissions for the observer
        // - delete the flashcards of the observer

        $this->getAddonDir();

        if (argc() > 2) {
            switch (argv(2)) {
                case 'upload':
                    // API: /flashcards/nick/upload
                    // Creates or merges a box
                    $this->writeBox();
                case 'download':
                    // API: /flashcards/nick/download
                    // Downloads a box specified by param "box_id"
                    $this->sendBox();
                case 'list':
                    // API: /flashcards/nick/list
                    // List all boxes owned by the channel
                    $this->listBoxes();
                case 'permissions':
                    // API: /flashcards/nick/permissions/
                    // List all boxes owned by the channel
                    $this->setPermissions();
                case 'acl':
                    // API: /flashcards/nick/acl/
                    // List all boxes owned by the channel
                    $this->getACL();
                case 'delete':
                    // API: /flashcards/nick/delete
                    // Deletes a box specified by param "box_id"
                    $this->deleteBox();
                case 'search':
                    // API: /flashcards/nick/search
                    // get boxes of other instances
                    $this->searchBoxes();
                default:
                    break;
            }
        }

    }

    private function permChecks() {

        $owner_uid = $this->owner['channel_id'];

		//logger('DELETE ME: This is the owner...', LOGGER_DEBUG);
		//logger(print_r($this->owner, true), LOGGER_DEBUG);

        if (!$owner_uid) {  // This IF should be checked before and could be deleted
			logger('Stop: No owner profil', LOGGER_DEBUG);
            return array('status' => false, 'errormsg' => 'No owner profil');
        }

        if (observer_prohibited(true)) {
			logger('Stop: observer prohibited', LOGGER_DEBUG);
            return array('status' => false, 'errormsg' => 'observer prohibited');
        }

        if (!perm_is_allowed($owner_uid, get_observer_hash(), 'view_storage')) {
			logger('Stop: Permission view storage denied', LOGGER_DEBUG);
            return array('status' => false, 'errormsg' => 'Permission view storage denied');
        }

        if (perm_is_allowed($owner_uid, get_observer_hash(), 'write_storage')) {
			logger('write_storage is allowed', LOGGER_DEBUG);
            $this->canWrite = true;
        }


        logger('observer = ' . $this->observer['xchan_addr'] . ', owner = ' . $this->owner['xchan_addr'], LOGGER_DEBUG);

        $this->is_owner = ($this->observer['xchan_hash'] && $this->observer['xchan_hash'] == $this->owner['xchan_hash']);
        if($this->is_owner) {
            logger('observer = owner', LOGGER_DEBUG);
        } else {
            logger('observer != owner', LOGGER_DEBUG);
        }

        return array('status' => true);
    }

    private function checkOwner() {
        // Determine which channel's flashcards to display to the observer
        $nick = null;
        if (argc() > 1) {
            $nick = argv(1); // if the channel name is in the URL, use that
        }
        logger('nick = ' . $nick, LOGGER_DEBUG);

        $this->owner = channelx_by_nick($nick);
	}

    private function getACL() {

        logger('+++ get permissions (ACL) of box ... +++', LOGGER_DEBUG);

        // API: /flashcards/nick/fileid/permissions
        if(! $this->isObserverAllowedToCreateFlashcards()) {
            notice(t('Not allowed.') . EOL);
            json_return_and_die(array('status' => false, 'errormsg' => 'No permission to change ACLs ' . $box_id));
            return;
        }
        require_once('include/acl_selectors.php');
        $box_id = isset($_POST['boxID']) ? $_POST['boxID'] : '';
        if(strlen($box_id) < 1) {
            json_return_and_die(array('status' => false, 'errormsg' => 'Missing post param boxID in request'));
            return;
        }

        logger('user requested ACL for box id = ' . $box_id, LOGGER_DEBUG);

        $filename = $box_id . '.json';
        $r = q("select id, uid, folder, filename, revision, flags, is_dir, os_storage, hash, allow_cid, allow_gid, deny_cid, deny_gid from attach where filename = '%s' and uid = %d limit 1",
                dbesc($filename),
                intval($this->owner['channel_id'])
        );

        $f = $r[0];
        if(!$f) {
            json_return_and_die(array('status' => false, 'errormsg' => 'box ID ' . $file . ' not found.'));
            return;
        }
        $channel = App::get_channel();

        $aclselect_e = populate_acl($f, false, \Zotlabs\Lib\PermissionDescription::fromGlobalPermission('view_storage'));

        $lockstate = (($f['allow_cid'] || $f['allow_gid'] || $f['deny_cid'] || $f['deny_gid']) ? 'lock' : 'unlock');

        //$o = replace_macros(get_markup_template('attach_edit.tpl'), array(
        $o = replace_macros(get_markup_template('flashcards_attach_edit.tpl','addon/flashcards'),array(
                '$boxid' => $box_id,
                '$file' => $f,
                '$uid' => $channel['channel_id'],
                '$channelnick' => $channel['channel_address'],
                '$permissions' => t('Permissions'),
                '$aclselect' => $aclselect_e,
                '$allow_cid' => acl2json($f['allow_cid']),
                '$allow_gid' => acl2json($f['allow_gid']),
                '$deny_cid' => acl2json($f['deny_cid']),
                '$deny_gid' => acl2json($f['deny_gid']),
                '$lockstate' => $lockstate,
                '$permset' => t('Set/edit permissions'),
                '$submit' => t('Submit'),
        ));


        logger('sending post response: ACL for box id = ' . $box_id . ' ...');

        json_return_and_die(array('status' => true, 'acl_modal' => $aclselect_e, 'permissions_panel' => $o));
    }

    private function setPermissions() {

        logger('+++ set permissions of box ... +++', LOGGER_DEBUG);

        $channel_id = ((x($_POST, 'uid')) ? intval($_POST['uid']) : 0);

        $recurse = ((x($_POST, 'recurse')) ? intval($_POST['recurse']) : 0); # not sent
        $resource = ((x($_POST, 'filehash')) ? notags($_POST['filehash']) : '');
        $notify = ((x($_POST, 'notify_edit')) ? intval($_POST['notify_edit']) : 0); # not sent

        $block_changes = ((x($_POST, 'flashcards-block-changes')) ? notags($_POST['flashcards-block-changes']) : '');

        if(! $resource) {
            notice(t('Item not found.') . EOL);
            json_return_and_die(array('status' => false, 'errormsg' => t('Item not found.') . EOL));
            return;
        }

        $channel = App::get_channel();

        $acl = new AccessList($channel); // Hubzilla: $acl = new AccessList($channel);
        $acl->set_from_array($_POST);
        $x = $acl->get();

        $url = get_cloud_url($channel['channel_id'], $channel['channel_address'], $resource);

        //get the object before permissions change so we can catch eventual former allowed members
        $object = get_file_activity_object($channel_id, $resource, $url);

        attach_change_permissions($channel_id, $resource, $x['allow_cid'], $x['allow_gid'], $x['deny_cid'], $x['deny_gid'], $recurse, true);

        file_activity($channel_id, $object, $x['allow_cid'], $x['allow_gid'], $x['deny_cid'], $x['deny_gid'], 'post', $notify);

        logger('sending post response for setting box permissons...');

        $box_id = ((x($_POST, 'boxid')) ? notags($_POST['boxid']) : '');
        $go_away_url = z_root() . '/flashcards/' . $channel['channel_address'] . '/' . $box_id;

        goaway($go_away_url);
    }

    private function isObserverAllowedToViewFlashcards() {

		$this->getAuthObserver();

		$nick = argv(1);

		if(! $nick) {
			return false;
		}

		$dirFlashcards = new Directory($nick . '/flashcards/', [], $this->authObserver);
		try {
			$boxFiles = $dirFlashcards->getChildren();
		} catch (\Exception $e) {
			logger('permission denied for path ' . $nick_fc . '/flashcards/', LOGGER_DEBUG);
			return false;
		}

		return true;
	}

    private function isObserverAllowedToCreateFlashcards() {

		if($this->isObserverAllowedToViewFlashcards() && $this->canWrite) {
			// can view the folder "flashcards" AND has write permissions to cloud files
			return true;
		} else {
			return false;
		}

	}

    private function listBoxes() {

        logger('+++ list boxes ... +++', LOGGER_DEBUG);

        $this->recoverBoxes();

		$nicks = $this->getFlashcardsUsers();

		$this->getAuthObserver();

		$baseURL = App::get_baseurl();

        $boxes = [];

		foreach ($nicks as $nick_fc) {
			$dirFlashcards = new Directory($nick_fc . '/flashcards/', [], $this->authObserver);
			$boxFiles;
			try {
				$boxFiles = $dirFlashcards->getChildren();
			} catch (\Exception $e) {
				logger('permission denied for path ' . $nick_fc . '/flashcards/', LOGGER_DEBUG);
				continue;
			}
			foreach($boxFiles as $child) {
				if ($child instanceof File) {
					if($child->getContentType() === strtolower('application/json')) {
						$fname = $child->getName();
						logger('found json file = '. $fname, LOGGER_DEBUG);
						$box = $this->readBox($dirFlashcards, $child->getName());
						if($box) {
							$box['size'] = count($box['cards']);
							unset($box['cards']);
							$current_owner = channelx_by_nick($nick_fc);
							$box['current_owner'] = $current_owner['xchan_addr'];
							$fn = substr($fname,0,strpos($fname, '.'));
							$box['current_url'] = $baseURL . '/flashcards/' . $nick_fc . '/' . $fn;
							array_push($boxes, $box);
						}
					}
				}
			}
		}
        if (empty($boxes)) {
            logger('no boxes found', LOGGER_DEBUG);
        }

        logger('sending (post response) list of boxes...', LOGGER_DEBUG);

        json_return_and_die(array('status' => true, 'boxes' => $boxes));
    }

    private function searchBoxes() {

        logger('+++ search boxes ... +++', LOGGER_DEBUG);

        json_return_and_die(array('status' => false, 'errormsg' => 'Not implemented' . EOL));
    }

    private function getFlashcardsUsers() {
		$r = q("select app_url from app where app_plugin = 'flashcards' and app_deleted = 0");

        $nicks = [];
		if($r) {
			foreach ($r as $fc_url) {
				$a_url = $fc_url['app_url'];
				if (strpos($a_url, '/$nick')) {
					continue;
				}
				$nick = substr($a_url, strripos($a_url, '/') + 1);
				array_push($nicks, $nick);
			}
		}

		return $nicks;
	}

    private function recoverBoxes() {

        if(! $this->is_owner) {
            return;
        }

        $recoverDir = $this->getRecoverDir();

        $children = $recoverDir->getChildren();
        foreach($children as $child) {
            if ($child instanceof File) {
                if($child->getContentType() === strtolower('application/json')) {
                    $fname = $child->getName();
                    logger('found recover file = ' . $fname);
                    if($this->boxesDir->childExists($fname)) {
                        logger('file exists already');
                        notice('Recovery failed. File "' . $fname . '" exist.');
                    } else {
                        $box = $this->readBox($recoverDir, $fname);
                        $hash = random_string(15);
                        $box["boxID"] = $hash;
                        $box["boxPublicID"] = $hash;
                        $this->boxesDir->createFile($hash . '.json', json_encode($box));
                        logger('created file name of box = ' . $hash . '.json');
                        //info('Box was recovered.');
                    }
                    logger('delete file...' . $fname);
                    $child->delete();
                }
            }
        }


    }

    private function sendBox() {

        logger('+++ send box ... +++', LOGGER_DEBUG);

		if(! $this->observer) {
			json_return_and_die(array('status' => false, 'errormsg' => 'Unknown observer. Please login to view box ' . $box_id));
		}

        $box_id = isset($_POST['boxID']) ? $_POST['boxID'] : '';
        if(strlen($box_id) > 0) {

            logger('user requested box id = ' . $box_id, LOGGER_DEBUG);

            $box = $this->readBox($this->boxesDir, $box_id . '.json');

            if(! $box) {

                logger('box not found or no permission, box id = ' . $box_id, LOGGER_DEBUG);

                json_return_and_die(array('status' => false, 'errormsg' => 'No box found or no permissions for ' . $box_id));
            }

            if($this->is_owner) {

                logger('owner requested box id = ' . $box_id, LOGGER_DEBUG);

                $box = $this->importSharedBoxes($box);

				$box['size'] = count($box['cards']);

                json_return_and_die(
                        array(
                            'status' => true,
                            'box' => $box,
                            'resource_public_id' => $box["boxPublicID"],
                            'resource_id' => $box["boxID"]));
            }
            $this->sendBoxObserver($box);
        }
    }

    private function sendBoxObserver($box) {

        $box_id = $box['boxID'];

        logger('observer requested box id = ' . $box_id, LOGGER_DEBUG);

        $boxDirObserver = $this->createDirBoxObserver($box_id);

        $box_name = $this->getBoxNameObserver();

        $filename = $box_name . '.json';

        if(! $boxDirObserver->childExists($filename)) {
            $cards = $box['cards'];
            if($cards) {
                foreach ($cards as &$card) {
                    for($x = 6; $x < 11; $x++) {
                        $card['content'][$x] = '';
                    }
                }
                $box['cards'] = $cards;
            }
            $hash = random_string(15);
            $box["boxID"] = $hash;
            $boxDirObserver->createFile($filename, json_encode($box));
            //info('Box was copied box for you');
            logger('box created (copied) for observer, box id = ' . $box_id);
        }

        $boxObserver = $this->readBox($boxDirObserver, $filename);

        logger('merge owner box into observer box, box id = ' . $box_id, LOGGER_DEBUG);

        $boxObserver = $this->mergeOwnerBoxIntoObserverBox($boxObserver);

        json_return_and_die(
                array(
                    'status' => true,
                    'box' => $boxObserver,
                    'resource_public_id' => $boxObserver["boxPublicID"],
                    'resource_id' => $boxObserver["boxID"]));

    }

    private function createDirBoxObserver($box_id) {

        logger('create dir for observer, box id = ' . $box_id, LOGGER_DEBUG);

        if(! $this->boxesDir->childExists($box_id)) {
            $this->boxesDir->createDirectory($box_id);
        }

        $boxDirObserver = new Directory('/'. $this->owner['channel_address'] . '/flashcards/' . $box_id, [], $this->getAuth());
        if(! $boxDirObserver) {
            json_return_and_die(array('message' => 'Directory for observer box can not be created.', 'success' => false));
        }

        return $boxDirObserver;

    }

    private function writeBox() {

        logger('+++ write box ... +++', LOGGER_DEBUG);

		if(! $this->observer) {

			logger('Stop: no observer. Can not write the box.');

			json_return_and_die(array('status' => false, 'errormsg' => 'No box was sent. No observer.'));

		}

        $boxRemote = $_POST['box'];
        if(!$boxRemote) {

            logger('no remote box given');

            json_return_and_die(array('status' => false, 'errormsg' => 'No box was sent'));
        }
        $box_id = $boxRemote["boxID"];

        $cards = $boxRemote['cards'];
        $cardIDsReceived = array();
        if(isset($cards)) {
            foreach ($cards as &$card) {
                array_push($cardIDsReceived, $card['content'][0]);
            }
        }

        if($this->is_owner) {

            if(strlen($box_id) > 0) {

                $filename = $box_id . '.json';
                if(! $this->boxesDir->childExists($filename)) {

                    logger('box has to be created for owner, box id = ' . $box_id);

                    $this->boxesDir->createFile($filename, $boxRemote);

                    $boxRemote['lastShared'] = round(microtime(true) * 1000);
                    unset($boxRemote['cards']);

                    json_return_and_die(array('status' => true, 'box' => $boxRemote, 'resource_id' => $box_id, 'cardIDsReceived' => $cardIDsReceived));

                } else {

                    logger('local box has to be merged with remote box for owner, box id = ' . $box_id);

                    $this->mergeBox($box_id, $boxRemote, $cardIDsReceived);

                }

            } else {

                $hash = random_string(15);
                $boxRemote["boxID"] = $hash;
                $boxRemote["boxPublicID"] = $hash;
                $boxRemote["creator"] = $this->observer['xchan_addr'];
                $boxRemote["creator_xchan_hash"] = $this->observer['xchan_hash'];
                $this->boxesDir->createFile($hash . '.json', json_encode($boxRemote));

                logger('box is unknow and has to be created for owner, stored as = ' . $hash . '.json');

                $boxRemote['lastShared'] = round(microtime(true) * 1000);
                unset($boxRemote['cards']);

                json_return_and_die(array('status' => true, 'box' => $boxRemote, 'resource_id' => $hash, 'resource_public_id' => $hash, 'cardIDsReceived' => $cardIDsReceived));

            }

        } else {

            $this->writeBoxObserver($boxRemote, $cardIDsReceived);

        }

    }

    private function writeBoxObserver($boxRemote, $cardIDsReceived) {

        $box_id = $boxRemote["boxID"];
        $box_public_id = $boxRemote["boxPublicID"];

        if(! $this->boxesDir->childExists($box_public_id)) {

            logger('no box dir found. Might be deleted by owner, public box id = ' . $box_public_id);

            notice('No box dir found. Might be deleted by owner.');
            json_return_and_die(array('status' => false, 'errormsg' => 'No box dir found. Might be deleted by owner.'));
        }

        $filename = $this->getBoxNameObserver() . '.json';

        $boxDirObserver = $this->createDirBoxObserver($box_public_id);

        if(! $boxDirObserver->childExists($filename)) {

            logger('no box dir found. Might be deleted by owner, public box id = ' . $filename);

            notice('No box found. Might be delete by owner.');
            json_return_and_die(array('status' => false, 'errormsg' => 'No box found. Might be delete by owner.'));
        }

        $boxLocalObserver = $this->readBox($boxDirObserver, $filename);

        logger('merge local owner box into local observer box = ' . $filename, LOGGER_DEBUG);

        $boxLocalObserverMergedWithOwner = $this->mergeOwnerBoxIntoObserverBox($boxLocalObserver);

        logger('merge local local observer box with remote box', LOGGER_DEBUG);

        $boxes = $this->flashcards_merge($boxLocalObserverMergedWithOwner, $boxRemote);
        $boxToWrite = $boxes['boxLocal'];
        $boxToSend = $boxes['boxRemote'];

        $boxToWrite['lastShared'] = $boxToSend['lastShared'] = round(microtime(true) * 1000);

        // store box of observer
        $boxDirObserver->getChild($filename)->put(json_encode($boxToWrite));

        // write box of observer to dir "share" where the owner can merge it
        $this->shareObserverBoxLocally($boxToWrite);

        json_return_and_die(array('status' => true, 'box' => $boxToSend, 'resource_id' => $box_id, 'cardIDsReceived' => $cardIDsReceived));

    }

    private function mergeOwnerBoxIntoObserverBox($boxObserver) {

        $box_public_id = $boxObserver["boxPublicID"];
        $filename = $box_public_id . '.json';

        $boxOwner = $this->readBox($this->boxesDir, $filename);
        if(! $boxOwner) {
            notice('Box of owner not found on server'); // This should never happen. Anyway.
            return $boxObserver;
        }

        $boxes = $this->flashcards_merge($boxObserver, $boxOwner, false);

        return $boxes['boxLocal'];
    }

    private function shareObserverBoxLocally($boxObserver) {

        $cards = $boxObserver['cards'];
        $cardPublic = array();
        if(isset($cards)) {
            foreach ($cards as &$card) {
                for ($i = 6; $i < 10; $i++) {
                    $card['content'][$i] = 0;
                }
                $card['content'][10] = false;
                array_push($cardPublic, $card);
            }
        }
        $boxObserver['cards'] = $cardPublic;

        $shareDir = $this->getShareDir();

        $filename = $this->getShareFileName($boxObserver);

        if($shareDir->childExists($filename)) {
            $shareDir->getChild($filename)->put(json_encode($boxObserver));
        } else {
            $shareDir->createFile($filename, json_encode($boxObserver));
        }
    }

    private function importSharedBoxes($box) {

        if($box['private_block'] == "true") {
            return $box;
        }

        $boxId = $box['boxID'];

        $shareDir = $this->getShareDir();

        $boxes = [];

        $children = $shareDir->getChildren();
        foreach($children as $child) {
            if ($child instanceof File) {
                if($child->getContentType() === strtolower('application/json')) {
                    $sharedFileName = $child->getName();

                    logger('import shared file = ' . $sharedFileName);

                    if (strpos($sharedFileName, $boxId) === 0) {
                        $sharedBox = $this->readBox($shareDir, $sharedFileName);
                        $boxes = $this->flashcards_merge($box, $sharedBox, false);
                        $box = $boxes['boxLocal'];
						$this->boxesDir->getChild($boxId . '.json')->put(json_encode($box));
						try {
							// Remove the try-catch if everythings works fine on Hubzilla and ZAP.
							// Zotlabs\Storage\BasicAuth was not used correctly (or changed).
							$shareDir->getChild($sharedFileName)->delete();
						} catch (\Exception $e) {
							logger('Please report to the devs. This could be a bug with the usages of Zotlabs\Storage\BasicAuth. This caused a permission denied to delete a file in owned directory ', LOGGER_DEBUG);
							continue;
						}
                    }
                }
            }
        }

        return $box;

    }

    private function getShareFileName($boxObserver) {
        $filename = $boxObserver['boxPublicID'] . '-' . $boxObserver['boxID'] . '.json';
        return $filename;
    }

    private function getBoxNameObserver() {
        $ob_hash = $this->observer['xchan_hash'];
        $box_name = substr($ob_hash, 0, 15);
        return $box_name;
    }

    private function readBox($dir, $filename) {
        $boxFileExists = $dir->childExists($filename);
        if(! $boxFileExists) {
            logger('file does not exist in boxes dir, file = '. $filename, LOGGER_DEBUG);
            return false;
        }

        logger('read box and convert from file = '. $filename, LOGGER_DEBUG);

        $JSONstream = $dir->getChild($filename)->get();
        $contents = stream_get_contents($JSONstream);
        $box = json_decode($contents, true);
        fclose($JSONstream);

        return $box;

    }

    private function mergeBox($box_id, $boxRemote, $cardIDsReceived) {

        $boxLocal = $this->readBox($this->boxesDir, $box_id . '.json');
        if(! $boxLocal) {
            json_return_and_die(array('status' => false, 'resource_id' => $box_id, 'errormsg' => 'Box not found on server'));
        }

        // Another user might have changed the box
        $boxLocalWithImports = $this->importSharedBoxes($boxLocal);

        // The same user might have changed the box meanwhile from a different device
        $boxes = $this->flashcards_merge($boxLocalWithImports, $boxLocal);
        $boxLocalMergedWithImportsAndLocal = $boxes['boxLocal'];

        // Merge the changes from the client (browser)
        $boxes = $this->flashcards_merge($boxLocalMergedWithImportsAndLocal, $boxRemote);
        $boxToWrite = $boxes['boxLocal'];
        $boxToSend = $boxes['boxRemote'];

        $boxToWrite['lastShared'] = $boxToSend['lastShared'] = round(microtime(true) * 1000);

        logger('store and send box id = ' . $box_id);

        $this->boxesDir->getChild($box_id . '.json')->put(json_encode($boxToWrite));
        json_return_and_die(array('status' => true, 'box' => $boxToSend, 'resource_id' => $box_id, 'cardIDsReceived' => $cardIDsReceived));

    }

    private function deleteBox() {

        logger('+++ delete box ... +++', LOGGER_DEBUG);

        $boxID = $_POST['boxID'];
        if(! $boxID) {
            return;
        }

        if(!$this->is_owner) {
            $this->deleteBoxObserver($boxID);
        }

        $filename = $boxID . '.json';

        if($this->boxesDir->childExists($filename)) {

            // delete box itself
            $this->boxesDir->getChild($filename)->delete();
            // delete directory of box for the observers and their boxes too
            if($this->boxesDir->childExists($boxID)) {
                $this->boxesDir->getChild($boxID)->delete();
            }

            // delete boxes in "share" directory
            $shareDir = $this->getShareDir();
            $children = $shareDir->getChildren();
            foreach($children as $child) {
                if ($child instanceof File) {
                    if($child->getContentType() === strtolower('application/json')) {
                        $sharedFileName = $child->getName();
                        if (strpos($sharedFileName, $boxID) === 0) {
                            $shareDir->getChild($sharedFileName)->delete();
                        }
                    }
                }
            }

            json_return_and_die(array('status' => true));
        } else {

            json_return_and_die(array('status' => false, 'errormsg' => 'Box not found on server'));

        }

    }

    private function deleteBoxObserver($box_id) {

		if(! $this->observer) {

			logger('Stop: no observer. Can not delete the box.');

			json_return_and_die(array('status' => false, 'errormsg' => 'Unable to delete the box. No observer.'));

		}

        if(! $this->boxesDir->childExists($box_id)) {
            notice('No box dir found. Might be delete by owner.');
            json_return_and_die(array('status' => true));
        }

        $filename = $this->getBoxNameObserver($observer) . '.json';

        $boxDirObserver = $this->createDirBoxObserver($box_id);

        if($boxDirObserver->childExists($filename)) {
            $boxDirObserver->getChild($filename)->delete();
            json_return_and_die(array('status' => true));
        }

        json_return_and_die(array('status' => false, 'errormsg' => 'No observer box found. Might be delete by owner.'));

    }

    private function getAuth() {

        // copied/adapted from Cloud.php
        if (!$this->auth) {

            $this->auth = new BasicAuth();

            $this->auth->setCurrentUser($this->owner['channel_address']);
            $this->auth->channel_id = $this->owner['channel_id'];
            $this->auth->channel_hash = $this->owner['xchan_hash'];
            $this->auth->channel_account_id = $this->owner['channel_account_id'];
            // this is not true but reflects that no files are owned by the observer
            $this->auth->observer = $this->owner['xchan_hash'];
        }

        return $this->auth;
    }

	private function getAuthObserver() {

		if(! $this->authObserver) {

			$this->authObserver = new BasicAuth();

			$this->authObserver->setCurrentUser($this->observer['xchan_addr']);
			$this->authObserver->channel_id = $this->observer['xchan_guid'];
			$this->authObserver->channel_hash = $this->observer['xchan_hash'];
			$this->authObserver->observer = $this->observer['xchan_hash'];

		}

	}

    private function getShareDir() {

        if(! $this->boxesDir->childExists('share')) {
            $this->boxesDir->createDirectory('share');
        }

        $channelAddress = $this->owner['channel_address'];

        $shareDir = new Directory('/'. $channelAddress . '/flashcards/share', [], $this->getAuth());

        if(! $shareDir) {
            json_return_and_die(array('message' => 'Directory share is missing.', 'success' => false));
        }

        return $shareDir;
    }

    private function getRecoverDir() {

        if(! $this->boxesDir->childExists('recover')) {
            $this->boxesDir->createDirectory('recover');
        }

        $channelAddress = $this->owner['channel_address'];

        $recoverDir = new Directory('/'. $channelAddress . '/flashcards/recover', [], $this->getAuth());

        if(! $recoverDir) {
            json_return_and_die(array('message' => 'Directory recover is missing.', 'success' => false));
        }

        return $recoverDir;
    }

    private function getAddonDir() {

        $this->getRootDir(); // just test this time

        $channelAddress = $this->owner['channel_address'];

        $channelDir = new Directory('/' . $channelAddress, [], $this->getAuth());

        if(! $channelDir->childExists('flashcards')) {
            $channelDir->createDirectory('flashcards');
        }

        $this->boxesDir = new Directory('/'. $channelAddress . '/flashcards', [], $this->getAuth());
        if(! $this->boxesDir) {
            json_return_and_die(array('message' => 'Directory flashcards is missing.', 'success' => false));
        }

    }

    private function getRootDir() {

        $rootDirectory = new Directory('/', [], $this->getAuth());

        $channelAddress = $this->owner['channel_address'];

        if(! $rootDirectory->childExists($channelAddress)) {
            json_return_and_die(array('message' => 'No cloud directory.', 'success' => false));
        }

        return $rootDirectory;

    }

    /*
     * Merge to boxes of flashcards
     *
     *  compare
     *  - boxPublicID > if not equal then do nothing
     *  do not touch
     *  - boxPublicID
     *  - boxID
     *  - creator
     *  - creator_xchan_hash
     *  - lastShared
     *  - maxLengthCardField
     *  - cardsColumnName
     *  - private_hasChanged
     *  - block_changes
     *  lastChangedPublicMetaData
     *  - title
     *  - description
     *  - lastEditor
     *  lastChangedPrivateMetaData
     *  - cardsDecks
     *  - cardsDeckWaitExponent
     *  - cardsRepetitionsPerDeck
     *  - private_sortColumn
     *  - private_sortReverse
     *  - private_filter
     *  - private_visibleColumns
     *  - private_switch_learn_direction
     *  - private_switch_learn_all
     *  - private_autosave
     *  - private_show_card_sort
     *  - private_sort_default
     *  - private_search_convenient
     *  - private_block
     *  calculate
     *  - size
     *  cards
     *  0 - id = creation timestamp, milliseconds, Integer > do not touch
     *  1 - Language A, String > last modified content
     *  2 - Language B, String > last modified content
     *  3 - Description, String > last modified content
     *  4 - Tags, "Lesson 010.03" or anything else, String > last modified content
     *  5 - last modified content, milliseconds, Integer
     *  6 - Deck, Integer from 0 to 6 but configurable > last modified progress
     *  7 - progress in deck default 0, Integer > last modified progress
     *  8 - How often learned (information for the user only), Integer > last modified progress
     *  9 - last modified progress, milliseconds, Integer
     *  10 - has local changes, Boolean > use to create new box to send
     *
     * @param $boxLocal array from local DB
     * @param $boxRemote array received to merge with box in DB
     */
    function flashcards_merge($boxLocal, $boxRemote, $is_private = true) {

        logger('merge boxes local id = ' . $boxLocal['boxID'] . ', remote id = ' . $boxRemote['boxID']);

        if($is_private) {
            if($boxLocal['boxID'] != $boxRemote['boxID']) {
                unset($boxRemote['cards']);
                return array('boxLocal' => $boxLocal, 'boxRemote' => $boxRemote);
            }
        }
        else {
            if($boxLocal['boxPublicID'] != $boxRemote['boxPublicID']) {
                unset($boxRemote['cards']);
                return array('boxLocal' => $boxLocal, 'boxRemote' => $boxRemote);
            }
        }
        $keysPublic = array('title', 'description', 'lastEditor', 'lastChangedPublicMetaData', 'lastShared');
        $keysPrivate = array('cardsDecks', 'cardsDeckWaitExponent', 'cardsRepetitionsPerDeck', 'private_block', 'private_sortColumn', 'private_sortReverse', 'private_filter', 'private_visibleColumns', 'private_switch_learn_direction', 'private_switch_learn_all', 'private_autosave', 'private_show_card_sort', 'private_sort_default', 'private_search_convenient', 'lastChangedPrivateMetaData');
        if($boxLocal['lastChangedPublicMetaData'] != $boxRemote['lastChangedPublicMetaData']) {
            if($boxLocal['lastChangedPublicMetaData'] > $boxRemote['lastChangedPublicMetaData']) {
                foreach ($keysPublic as &$key) {
                    $boxRemote[$key] = $boxLocal[$key];
                }
            } else {
                foreach ($keysPublic as &$key) {
                    $boxLocal[$key] = $boxRemote[$key];
                }
            }
        }
        if($is_private) {
            if($boxLocal['lastChangedPrivateMetaData'] != $boxRemote['lastChangedPrivateMetaData']) {
                if($boxLocal['lastChangedPrivateMetaData'] > $boxRemote['lastChangedPrivateMetaData']) {
                    foreach ($keysPrivate as &$key) {
                        $boxRemote[$key] = $boxLocal[$key];
                    }
                } else {
                    foreach ($keysPrivate as &$key) {
                        $boxLocal[$key] = $boxRemote[$key];
                    }
                }
            }
        }
        $cardsDB = $boxLocal['cards'];
        if(! $cardsDB) {
            $cardsDB = [];
        }
        $cardsRemote = $boxRemote['cards'];
        if(! $cardsRemote) {
            $cardsRemote = [];
        }
        $cardsDBadded = array();
        $cardsRemoteToUpload = array();
        foreach ($cardsRemote as &$cardRemote) {
            $isInDB = false;
            foreach ($cardsDB as &$cardDB) {
                if($cardRemote['content'][0] == $cardDB['content'][0]) {
                    $isInDB = true;
                    $isRemoteChanged = false;
                    if($cardDB['content'][5] != $cardRemote['content'][5]) {
                        if($cardDB['content'][5] > $cardRemote['content'][5]) {
                            for ($i = 1; $i < 6; $i++) {
                                $cardRemote['content'][$i] = $cardDB['content'][$i];
                                $isRemoteChanged = true;
                            }
                        } else {
                            for ($i = 1; $i < 6; $i++) {
                                $cardDB['content'][$i] = $cardRemote['content'][$i];
                            }
                        }
                    }
                    if($is_private) {
                        if($cardDB['content'][9] != $cardRemote['content'][9]) {
                            if($cardDB['content'][9] > $cardRemote['content'][9]) {
                                for ($i = 6; $i < 10; $i++) {
                                    $cardRemote['content'][$i] = $cardDB['content'][$i];
                                    $isRemoteChanged = true;
                                }
                            } else {
                                for ($i = 6; $i < 10; $i++) {
                                    $cardDB['content'][$i] = $cardRemote['content'][$i];
                                }
                            }
                        }
                    }
                    if($isRemoteChanged === true) {
                        array_push($cardsRemoteToUpload, $cardDB);
                    }
                    break;
                }
            }
            if(!$isInDB) {
                if(!$is_private) {
                    for ($i = 6; $i < 10; $i++) {
                        $cardRemote['content'][$i] = 0;
                    }
                    $cardRemote['content'][10] = false;
                }
                array_push($cardsDBadded, $cardRemote);
            }
        }
        // Add cards from local DB that are not in the remote cards
        $lastShared = $boxRemote['lastShared'];
        foreach ($cardsDB as &$cardDB) {
            $isInRemote = false;
            foreach ($cardsRemote as &$cardRemote) {
                if($cardRemote[0] == $cardDB[0]) {
                    $isInRemote = true;
                    break;
                }
            }
            if(!$isInRemote) {
                if($lastShared < $cardDB['content'][5]) {
                    array_push($cardsRemoteToUpload, $cardDB);
                } else if($lastShared < $cardDB['content'][9]) {
                    array_push($cardsRemoteToUpload, $cardDB);
                }
            }
        }
        // Check if the same user change a card on a different client (browser)
        $cardsDB = array_merge($cardsDB, $cardsDBadded);
        $boxLocal['size'] = count($cardsDB);
        $boxRemote['size'] = count($cardsDB);
        $boxLocal['cards'] = $cardsDB;
        $boxRemote['cards'] = $cardsRemoteToUpload; // send changed or new cards only

        logger('merge boxes finished', LOGGER_DEBUG);

        return array('boxLocal' => $boxLocal, 'boxRemote' => $boxRemote);
    }

    function flashcards_merge_test() {
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219599,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219599,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[]}';
        $boxIn2 = '{"boxID":"b2b2b2","title":"a2aaaaaaaaaaa","description":"A2aaaaaaaaaaaa","creator":"bMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"dMan","lastChangedPublicMetaData":1531058219599,"maxLengthCardField":1000,"cardsDecks":"6","cardsDeckWaitExponent":"2","cardsRepetitionsPerDeck":"1","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219599,"private_sortColumn":1,"private_sortReverse":false,"private_filter":["b","","","","","","","","","",""],"private_visibleColumns":[true,false,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":true,"private_switch_learn_all":true,"private_autosave":true,"private_show_card_sort":true,"private_sort_default":true,"private_search_convenient":true,"private_hasChanged":false,"private_block":true,"cards":[]}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare2 = '{"boxID":"b2b2b2","title":"a2aaaaaaaaaaa","description":"A2aaaaaaaaaaaa","creator":"bMan","lastShared":0,"boxPublicID":"b","size":2,"lastEditor":"dMan","lastChangedPublicMetaData":1531058219599,"maxLengthCardField":1000,"cardsDecks":"6","cardsDeckWaitExponent":"2","cardsRepetitionsPerDeck":"1","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219599,"private_sortColumn":1,"private_sortReverse":false,"private_filter":["b","","","","","","","","","",""],"private_visibleColumns":[true,false,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":true,"private_switch_learn_all":true,"private_autosave":true,"private_show_card_sort":true,"private_sort_default":true,"private_search_convenient":true,"private_hasChanged":false,"private_block":true}';
        $box2['boxPublicID'] = 'b';
        $boxes = $this->flashcards_merge($box1, $box2, false);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxIn1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }
        // Nothing changed
        $box2['boxPublicID'] = $box1['boxPublicID'];
        $boxCompare2 = '{"boxID":"b2b2b2","title":"a2aaaaaaaaaaa","description":"A2aaaaaaaaaaaa","creator":"bMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"dMan","lastChangedPublicMetaData":1531058219599,"maxLengthCardField":1000,"cardsDecks":"6","cardsDeckWaitExponent":"2","cardsRepetitionsPerDeck":"1","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219599,"private_sortColumn":1,"private_sortReverse":false,"private_filter":["b","","","","","","","","","",""],"private_visibleColumns":[true,false,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":true,"private_switch_learn_all":true,"private_autosave":true,"private_show_card_sort":true,"private_sort_default":true,"private_search_convenient":true,"private_hasChanged":false,"private_block":true}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxIn1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }
        // Public and private meta data
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219599,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":true,"cards":[]}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a2aaaaaaaaaaa","description":"A2aaaaaaaaaaaa","creator":"bMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"dMan","lastChangedPublicMetaData":1531058219599,"maxLengthCardField":1000,"cardsDecks":"6","cardsDeckWaitExponent":"2","cardsRepetitionsPerDeck":"1","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":1,"private_sortReverse":false,"private_filter":["b","","","","","","","","","",""],"private_visibleColumns":[true,false,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":true,"private_switch_learn_all":true,"private_autosave":true,"private_show_card_sort":true,"private_sort_default":true,"private_search_convenient":true,"private_hasChanged":false,"private_block":false,"cards":[]}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a2aaaaaaaaaaa","description":"A2aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":0,"lastEditor":"dMan","lastChangedPublicMetaData":1531058219599,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219599,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":true,"cards":[]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a2aaaaaaaaaaa","description":"A2aaaaaaaaaaaa","creator":"bMan","lastShared":0,"boxPublicID":1531058219599,"size":0,"lastEditor":"dMan","lastChangedPublicMetaData":1531058219599,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219599,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":false,"private_block":true,"cards":[]}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }
        // Public and private meta data the other way around
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219599,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":true,"cards":[]}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a2aaaaaaaaaaa","description":"A2aaaaaaaaaaaa","creator":"bMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"dMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"6","cardsDeckWaitExponent":"2","cardsRepetitionsPerDeck":"1","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219599,"private_sortColumn":1,"private_sortReverse":true,"private_filter":["b","","","","","","","","","",""],"private_visibleColumns":[true,false,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":true,"private_switch_learn_all":true,"private_autosave":true,"private_show_card_sort":true,"private_sort_default":true,"private_search_convenient":true,"private_hasChanged":false,"private_block":false,"cards":[]}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":0,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219599,"maxLengthCardField":1000,"cardsDecks":"6","cardsDeckWaitExponent":"2","cardsRepetitionsPerDeck":"1","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219599,"private_sortColumn":1,"private_sortReverse":true,"private_filter":["b","","","","","","","","","",""],"private_visibleColumns":[true,false,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":true,"private_switch_learn_all":true,"private_autosave":true,"private_show_card_sort":true,"private_sort_default":true,"private_search_convenient":true,"private_hasChanged":true,"private_block":false,"cards":[]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"bMan","lastShared":0,"boxPublicID":1531058219599,"size":0,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219599,"maxLengthCardField":1000,"cardsDecks":"6","cardsDeckWaitExponent":"2","cardsRepetitionsPerDeck":"1","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219599,"private_sortColumn":1,"private_sortReverse":true,"private_filter":["b","","","","","","","","","",""],"private_visibleColumns":[true,false,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":true,"private_switch_learn_all":true,"private_autosave":true,"private_show_card_sort":true,"private_sort_default":true,"private_search_convenient":true,"private_hasChanged":false,"private_block":false,"cards":[]}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }
        // Add remote card to empty local cards
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":0,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[]}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":0,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230161,0,0,0,0,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230161,0,0,0,0,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[]}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }
        // Add local cards to empty remote cards
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":999,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230161,0,0,0,0,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":999,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[]}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230161,0,0,0,0,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230161,0,0,0,0,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }
        // change card values
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"ax","aax","aaax","aaaax",1531058230161,0,0,0,1531058230162,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,1,2,3,1531058230161,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,0,0,0,1531058230162,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,0,0,0,1531058230162,true]}]}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }
        // change card values the other way around
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,1,2,3,1531058230161,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"ax","aax","aaax","aaaax",1531058230161,0,0,0,1531058230162,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,0,0,0,1531058230162,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,0,0,0,1531058230162,true]}]}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }
        // add public card to box 1 (local)
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"ax","aax","aaax","aaaax",1531058230161,0,0,0,1531058230162,true]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,2,4,6,8,true]}]}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"ax","aax","aaax","aaaax",1531058230161,0,0,0,0,false]},{"content":[1531058231082,"b1","b1b","b1bb","b1bbb",1531058239161,0,0,0,0,false]}]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":0,"boxPublicID":1531058219599,"size":2,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[]}';
        $boxes = $this->flashcards_merge($box1, $box2, false);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }

        // last shared younger than last learnt
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230160,1,2,3,1531058230162,true]}]}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"ax","aax","aaax","aaaax",1531058230162,0,0,0,1531058230160,true]}]}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"ax","aax","aaax","aaaax",1531058230162,1,2,3,1531058230162,true]}]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"ax","aax","aaax","aaaax",1531058230162,1,2,3,1531058230162,true]}]}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }

        // last shared younger than last edit
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,1,2,3,1531058230160,true]}]}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"ax","aax","aaax","aaaax",1531058230160,0,0,0,1531058230162,true]}]}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,0,0,0,1531058230162,true]}]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,0,0,0,1531058230162,true]}]}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }

        // last shared older than last edit and last learnt
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230160,1,2,3,1531058230160,true]}]}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230160,1,2,3,1531058230160,true]}]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058239161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[]}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }

        // last shared older than last edit
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058230161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,1,2,3,1531058230160,true]}]}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058230161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058230161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,1,2,3,1531058230160,true]}]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058230161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230162,1,2,3,1531058230160,true]}]}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }

        // last shared older than last learnt
        $boxIn1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058230161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230160,1,2,3,1531058230162,true]}]}';
        $boxIn2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058230161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false}';
        $box1 = json_decode($boxIn1, true);
        $box2 = json_decode($boxIn2, true);
        $boxCompare1 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058230161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230160,1,2,3,1531058230162,true]}]}';
        $boxCompare2 = '{"boxID":"a1a1a1","title":"a1aaaaaaaaaaa","description":"A1aaaaaaaaaaaa","creator":"aMan","lastShared":1531058230161,"boxPublicID":1531058219599,"size":1,"lastEditor":"cMan","lastChangedPublicMetaData":1531058219598,"maxLengthCardField":1000,"cardsDecks":"7","cardsDeckWaitExponent":"3","cardsRepetitionsPerDeck":"3","cardsColumnName":["Created","Side 1","Side 2","Description","Tags","modified","Deck","Progress","Counter","Learnt","Upload"],"lastChangedPrivateMetaData":1531058219598,"private_sortColumn":0,"private_sortReverse":false,"private_filter":["a","","","","","","","","","",""],"private_visibleColumns":[false,true,true,true,true,false,false,false,false,false,false],"private_switch_learn_direction":false,"private_switch_learn_all":false,"private_autosave":false,"private_show_card_sort":false,"private_sort_default":false,"private_search_convenient":false,"private_hasChanged":true,"private_block":false,"cards":[{"content":[1531058221298,"a","aa","aaa","aaaa",1531058230160,1,2,3,1531058230162,true]}]}';
        $boxes = $this->flashcards_merge($box1, $box2);
        $boxOut1 = json_encode($boxes['boxLocal']);
        $boxOut2 = json_encode($boxes['boxRemote']);
        if($boxOut1 !== $boxCompare1) {
            return false;
        }
        if($boxOut2 !== $boxCompare2) {
            return false;
        }

        logger('tests all passed');
        return true;
    }

}
