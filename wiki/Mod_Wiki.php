<?php /** @file */

namespace Zotlabs\Module;

use App;
use Zotlabs\Web\Controller;
use Zotlabs\Lib\Apps;
use Zotlabs\Lib\PermissionDescription;
use Zotlabs\Lib\MarkdownSoap;
use Michelf\MarkdownExtra;
use NativeWiki;
use NativeWikiPage;
use Wiki_pages;

require_once('include/acl_selectors.php');
require_once('include/conversation.php');
require_once('include/bbcode.php');

class Wiki extends Controller {

	private $wiki = null;

	function init() {
		// Determine which channel's wikis to display to the observer
		$nick = null;
		if (argc() > 1)
			$nick = argv(1); // if the channel name is in the URL, use that
		if (! $nick && local_channel()) { // if no channel name was provided, assume the current logged in channel
			$channel = \App::get_channel();
			if ($channel && $channel['channel_address']) {
				$nick = $channel['channel_address'];
				goaway(z_root() . '/wiki/' . $nick);
			}
		}
		if (! $nick) {
			notice( t('Profile Unavailable.') . EOL);
			goaway(z_root());
		}

		profile_load($nick);
	}

	function get() {

		if(observer_prohibited(true)) {
			return login();
		}

		if(! Apps::addon_app_installed(App::$profile_uid, 'wiki')) {
			//Do not display any associated widgets at this point
			App::$pdl = EMPTY_STR;

			if (App::$profile_uid !== local_channel()) {
				return EMPTY_STR;
			}

			$papp = Apps::get_papp('Wiki');
			return Apps::app_render($papp, 'module');
		}


		if(! perm_is_allowed(\App::$profile_uid,get_observer_hash(),'view_wiki')) {
			notice( t('Permission denied.') . EOL);
			return;
		}

		// TODO: Combine the interface configuration into a unified object
		// Something like $interface = array('new_page_button' => false, 'new_wiki_button' => false, ...)

		$wiki_owner = false;
		$showNewWikiButton = false;
		$pageHistory = array();
		$local_observer = null;
		$resource_id = '';

		// init() should have forced the URL to redirect to /wiki/channel so assume argc() > 1

		$nick = argv(1);
		$owner = channelx_by_nick($nick);  // The channel who owns the wikis being viewed
		if(! $owner) {
			notice( t('Invalid channel') . EOL);
			goaway('/' . argv(0));
		}

		$observer_hash = get_observer_hash();

		// Determine if the observer is the channel owner so the ACL dialog can be populated
		if (local_channel() === intval($owner['channel_id'])) {

			$wiki_owner = true;

			nav_set_selected('Wiki');

			// Obtain the default permission settings of the channel
			$owner_acl = array(
				'allow_cid' => $owner['channel_allow_cid'],
				'allow_gid' => $owner['channel_allow_gid'],
				'deny_cid' => $owner['channel_deny_cid'],
				'deny_gid' => $owner['channel_deny_gid']
			);

			// Initialize the ACL to the channel default permissions

			$x = array(
					'lockstate' => (( $owner['channel_allow_cid'] ||
						$owner['channel_allow_gid'] ||
						$owner['channel_deny_cid'] ||
						$owner['channel_deny_gid'])
						? 'lock' : 'unlock'
					),
					'acl' => populate_acl($owner_acl, false, PermissionDescription::fromGlobalPermission('view_wiki')),
					'allow_cid' => acl2json($owner_acl['allow_cid']),
					'allow_gid' => acl2json($owner_acl['allow_gid']),
					'deny_cid'  => acl2json($owner_acl['deny_cid']),
					'deny_gid'  => acl2json($owner_acl['deny_gid']),
					'bang' => ''
			);
		}
		else {
			// Not the channel owner
			$owner_acl = $x = array();
		}

		$is_owner = ((local_channel()) && (local_channel() == \App::$profile['profile_uid']) ? true : false);

		$o = '';

		// Download a wiki

		if((argc() > 3) && (argv(2) === 'download') && (argv(3) === 'wiki')) {

			$resource_id = argv(4);
			$w = NativeWiki::get_wiki($owner['channel_id'],$observer_hash,$resource_id);
//			$w = NativeWiki::get_wiki($owner,$observer_hash,$resource_id);
			if(! $w['htmlName']) {
				notice(t('Error retrieving wiki') . EOL);
			}

			$zip_folder_name = random_string(10);
			$zip_folderpath = '/tmp/' . $zip_folder_name;
			if(!mkdir($zip_folderpath, 0770, false)) {
				logger('Error creating zip file export folder: ' . $zip_folderpath, LOGGER_NORMAL);
				notice(t('Error creating zip file export folder') . EOL);
			}

			$zip_filename = $w['urlName'];
			$zip_filepath = '/tmp/' . $zip_folder_name . '/' . $zip_filename;


			// Generate the zip file

			$zip = new \ZipArchive;
			$r = $zip->open($zip_filepath, \ZipArchive::CREATE);
			if($r === true) {
				$pages = [];
				$i = q("select * from item where resource_type = 'nwikipage' and resource_id = '%s' order by revision desc",
					dbesc($resource_id)
				);

				if($i) {
					foreach($i as $iv) {
						if(in_array($iv['mid'],$pages))
							continue;

						if($iv['mimetype'] === 'text/plain') {
							$content = html_entity_decode($iv['body'],ENT_COMPAT,'UTF-8');
						}
						elseif($iv['mimetype'] === 'text/bbcode') {
							$content = html_entity_decode($iv['body'],ENT_COMPAT,'UTF-8');
						}
						elseif($iv['mimetype'] === 'text/markdown') {
							$content = html_entity_decode(MarkdownSoap::unescape($iv['body']),ENT_COMPAT,'UTF-8');
						}
						$fname = get_iconfig($iv['id'],'nwikipage','pagetitle') . NativeWikiPage::get_file_ext($iv);
						$zip->addFromString($fname,$content);
						$pages[] = $iv['mid'];
					}


				}

			}
			$zip->close();

			// Output the file for download

			header('Content-disposition: attachment; filename="' . $zip_filename . '.zip"');
			header('Content-Type: application/zip');

			$success = readfile($zip_filepath);

			if(!$success) {
				logger('Error downloading wiki: ' . $resource_id);
				notice(t('Error downloading wiki: ' . $resource_id) . EOL);
			}

			// delete temporary files
			rrmdir($zip_folderpath);
			killme();

		}

		switch(argc()) {
			case 2:
				$wikis = NativeWiki::listwikis($owner, get_observer_hash());

				if($wikis) {
					$o .= replace_macros(get_markup_template('wikilist.tpl', 'addon/wiki'), array(
						'$header' => t('Wikis'),
						'$channel' => $owner['channel_address'],
						'$wikis' => $wikis['wikis'],
						// If the observer is the local channel owner, show the wiki controls
						'$owner' => ((local_channel() && local_channel() === intval(\App::$profile['uid'])) ? true : false),
						'$edit' => t('Edit'),
						'$download' => t('Download'),
						'$view' => t('View'),
						'$create' => t('Create New'),
						'$submit' => t('Submit'),
						'$wikiName' => array('wikiName', t('Wiki name')),
						'$mimeType' => array('mimeType', t('Content type'), '', '', ['text/markdown' => t('Markdown'), 'text/bbcode' => t('BBcode'), 'text/plain' => t('Text') ]),
						'$name' => t('Name'),
						'$type' => t('Type'),
						'$unlocked' => t('Any&nbsp;type'),
						'$lockstate' => (x($x,'lockstate') ? $x['lockstate'] : ''),
						'$acl' 	     => (x($x,'acl') ? $x['acl'] : ''),
						'$allow_cid' => (x($x,'allow_cid') ? $x['allow_cid'] : ''),
						'$allow_gid' => (x($x,'allow_gid') ? $x['allow_gid'] : ''),
						'$deny_cid'  => (x($x,'deny_cid') ? $x['deny_cid'] : ''),
						'$deny_gid'  => (x($x,'deny_gid') ? $x['deny_gid'] : ''),
						'$typelock' => array('typelock', t('Lock content type'), '', '', array(t('No'), t('Yes'))),
						'$notify' => array('postVisible', t('Create a status post for this wiki'), '', '', array(t('No'), t('Yes'))),
						'$edit_wiki_name' => t('Edit Wiki Name')
					));

					return $o;
				}
				break;

			case 3:

				// /wiki/channel/wiki -> No page was specified, so redirect to Home.md

				//$wikiUrlName = urlencode(argv(2));
				$wikiUrlName = NativeWiki::name_encode(argv(2));
				goaway(z_root() . '/' . argv(0) . '/' . argv(1) . '/' . $wikiUrlName . '/Home');

			case 4:
			default:

				// GET /wiki/channel/wiki/page
				// Fetch the wiki info and determine observer permissions

				//$wikiUrlName = urldecode(argv(2));
				$wikiUrlName = NativeWiki::name_decode(argv(2));

				$page_name = '';
				$ignore_language = false;

				for($x = 3; $x < argc(); $x ++) {
					if($page_name === '' && argv($x) === '-') {
						$ignore_language = true;
						continue;
					}
					if($page_name) {
						$page_name .= '/';
					}
					$page_name .= argv($x);
				}

				//$pageUrlName = urldecode($page_name);
				$pageUrlName = NativeWiki::name_decode($page_name);
				$langPageUrlName = \App::$language . '/' . $pageUrlName;

				$w = NativeWiki::exists_by_name($owner['channel_id'], $wikiUrlName);

				if(! $w['resource_id']) {
					notice(t('Wiki not found') . EOL);
					goaway(z_root() . '/' . argv(0) . '/' . argv(1));
				}

				$resource_id = $w['resource_id'];

				if(! $wiki_owner) {
					// Check for observer permissions
					$observer_hash = get_observer_hash();
					$perms = NativeWiki::get_permissions($resource_id, intval($owner['channel_id']), $observer_hash);
					if(! $perms['read']) {
						notice(t('Permission denied.') . EOL);
						goaway(z_root() . '/' . argv(0) . '/' . argv(1));
						return; //not reached
					}
					$wiki_editor = (($perms['write']) ? true : false);
				}
				else {
					$wiki_editor = true;
				}

				//$wikiheaderName = urldecode($wikiUrlName);
				$wikiheaderName = escape_tags(NativeWiki::name_decode($wikiUrlName));
				//$wikiheaderPage = urldecode($pageUrlName);
				$wikiheaderPage = escape_tags(NativeWiki::name_decode($pageUrlName));

				$renamePage = (($wikiheaderPage === 'Home') ? '' : t('Rename page'));
				$sharePage  = t('Share');

				$p = [];

				if(! $ignore_language) {
					$p = NativeWikiPage::get_page_content(array('channel_id' => $owner['channel_id'], 'observer_hash' => $observer_hash, 'resource_id' => $resource_id, 'pageUrlName' => $langPageUrlName));
				}
				if(! ($p && $p['success'])) {
					$p = NativeWikiPage::get_page_content(array('channel_id' => $owner['channel_id'], 'observer_hash' => $observer_hash, 'resource_id' => $resource_id, 'pageUrlName' => $pageUrlName));
				}
				if(! ($p && $p['success'])) {

					$html = self::create_missing_page();
					//json_return_and_die(array('pages' => $page_list_html, 'message' => '', 'success' => true));
					notice( t('Error retrieving page content') . EOL);
					//goaway(z_root() . '/' . argv(0) . '/' . argv(1) );
					$renderedContent = NativeWikiPage::convert_links($html, argv(0) . '/' . argv(1) . '/' . NativeWiki::name_encode($wikiUrlName));
					$showPageControls = $wiki_editor;
				}
                                else {
				    $mimeType = $p['pageMimeType'];

				    $sampleContent = (($mimeType == 'text/bbcode') ? '[h3]' . t('New page') . '[/h3]' : '### ' . t('New page'));
				    if($mimeType === 'text/plain')
				        $sampleContent = t('New page');

				    $content = (($p['content'] == '') ? $sampleContent : $p['content']);

				    $hookinfo = ['content' => $content, 'mimetype' => $mimeType];
				    call_hooks('wiki_preprocess',$hookinfo);
				    $content = $hookinfo['content'];

				    // Render the Markdown-formatted page content in HTML
				    if($mimeType == 'text/bbcode') {
					$renderedContent = zidify_links(smilies(bbcode($content)));
					$renderedContent = NativeWikiPage::convert_links($renderedContent,argv(0) . '/' . argv(1) . '/' . NativeWiki::name_encode($wikiUrlName));
				    }
			 	    elseif($mimeType === 'text/plain') {
				        $renderedContent = str_replace(["\n",' ',"\t"],[EOL,'&nbsp;','&nbsp;&nbsp;&nbsp;&nbsp;'],htmlentities($content,ENT_COMPAT,'UTF-8',false));
				    }
				    elseif($mimeType === 'text/markdown') {
				     $content = MarkdownSoap::unescape($content);
				     //$html = NativeWikiPage::generate_toc(zidify_text(MarkdownExtra::defaultTransform(NativeWikiPage::bbcode($content))));
				     //$renderedContent = NativeWikiPage::convert_links($html, argv(0) . '/' . argv(1) . '/' . $wikiUrlName);
				     $html = NativeWikiPage::convert_links($content, argv(0) . '/' . argv(1) . '/' . NativeWiki::name_encode($wikiUrlName));
				     $renderedContent = NativeWikiPage::generate_toc(zidify_text(MarkdownExtra::defaultTransform(NativeWikiPage::bbcode($html))));
				    }
				    $showPageControls = $wiki_editor;
                                }
				break;
//			default:	// Strip the extraneous URL components
//				goaway('/' . argv(0) . '/' . argv(1) . '/' . NativeWiki::name_encode($wikiUrlName) . '/' . $pageUrlName);
		}


		$wikiModalID = random_string(3);

		$wikiModal = replace_macros(get_markup_template('generic_modal.tpl'), array(
			'$id' => $wikiModalID,
			'$title' => t('Revision Comparison'),
			'$ok' => (($showPageControls) ? t('Revert') : ''),
			'$cancel' => t('Cancel')
		));

		$types = [ 'text/bbcode' => t('BBcode'), 'text/markdown' => t('Markdown'), 'text/plain' => 'Text' ];
		$currenttype = $types[$mimeType];

		$placeholder = t('Short description of your changes (optional)');

		$zrl = z_root() . '/wiki/' . argv(1) . '/' . NativeWiki::name_encode($wikiUrlName) . '/' . NativeWiki::name_encode($pageUrlName);
		$o .= replace_macros(get_markup_template('wiki.tpl', 'addon/wiki'),array(
			'$wikiheaderName' => $wikiheaderName,
			'$wikiheaderPage' => $wikiheaderPage,
			'$renamePage' => $renamePage,
			'$sharePage' => $sharePage,
			'$shareLink' => urlencode('#^[zrl=' . $zrl . ']' . '[ ' . $owner['channel_name'] . ' ] ' . $wikiheaderName . ' - ' . $wikiheaderPage . '[/zrl]'),
			'$showPageControls' => $showPageControls,
			'$editOrSourceLabel' => (($showPageControls) ? t('Edit') : t('Source')),
			'$tools_label' => 'Page Tools',
			'$channel_address' => $owner['channel_address'],
			'$channel_id' => $owner['channel_id'],
			'$resource_id' => $resource_id,
			'$page' => $pageUrlName,
			'$mimeType' => $mimeType,
			'$typename' => $currenttype,
			'$content' => $content,
			'$renderedContent' => $renderedContent,
			'$pageRename' => array('pageRename', t('New page name'), '', ''),
			'$commitMsg' => array('commitMsg', '', '', '', '', 'placeholder="' . $placeholder . '"'),
			'$wikiModal' => $wikiModal,
			'$wikiModalID' => $wikiModalID,
			'$commit' => 'HEAD',
			'$embedPhotos' => t('Embed image from photo albums'),
			'$embedPhotosModalTitle' => t('Embed an image from your albums'),
			'$embedPhotosModalCancel' => t('Cancel'),
			'$embedPhotosModalOK' => t('OK'),
			'$modalchooseimages' => t('Choose images to embed'),
			'$modalchoosealbum' => t('Choose an album'),
			'$modaldiffalbum' => t('Choose a different album'),
			'$modalerrorlist' => t('Error getting album list'),
			'$modalerrorlink' => t('Error getting photo link'),
			'$modalerroralbum' => t('Error getting album'),
			'$view_lbl' => t('View'),
			'$history_lbl' => t('History')
		));

		if($p['pageMimeType'] === 'text/markdown')
			head_add_js('/library/ace/ace.js');	// Ace Code Editor

		return $o;
	}

	function post() {

		require_once('include/bbcode.php');

		$nick = argv(1);
		$owner = channelx_by_nick($nick);
		$observer_hash = get_observer_hash();

		if(! $owner) {
			notice( t('Permission denied.') . EOL);
			return;
		}

		// /wiki/channel/preview
		// Render mardown-formatted text in HTML for preview
		if((argc() > 2) && (argv(2) === 'preview')) {
			$content = $_POST['content'];
			$resource_id = $_POST['resource_id'];

			$w = NativeWiki::get_wiki($owner['channel_id'],$observer_hash,$resource_id);

			$wikiURL = argv(0) . '/' . argv(1) . '/' . $w['urlName'];

			$mimeType = $_POST['mimetype'];

			if($mimeType === 'text/bbcode') {
				$html = zidify_links(smilies(bbcode($content)));
				$html = NativeWikiPage::convert_links($html,$wikiURL);
			}
			elseif($mimeType === 'text/markdown') {
				$linkconverted = NativeWikiPage::convert_links($content,$wikiURL);
				$bb = NativeWikiPage::bbcode($linkconverted);
				$x = new MarkdownSoap($bb);
				$md = $x->clean();
				$md = MarkdownSoap::unescape($md);
				$html = MarkdownExtra::defaultTransform($md);
				$html = NativeWikiPage::generate_toc(zidify_text($html));
			}
			elseif($mimeType === 'text/plain') {
				$html = str_replace(["\n",' ',"\t"],[EOL,'&nbsp;','&nbsp;&nbsp;&nbsp;&nbsp;'],htmlentities($content,ENT_COMPAT,'UTF-8',false));
			}
			json_return_and_die(array('html' => $html, 'success' => true));
		}

		// Create a new wiki
		// /wiki/channel/create/wiki
		if ((argc() > 3) && (argv(2) === 'create') && (argv(3) === 'wiki')) {

			// Only the channel owner can create a wiki, at least until we create a
			// more detail permissions framework

			if (local_channel() !== intval($owner['channel_id'])) {
				goaway('/' . argv(0) . '/' . $nick . '/');
			}
			$wiki = array();

			// backslashes won't work well in the javascript functions
			$name = str_replace('\\','',$_POST['wikiName']);

			// Generate new wiki info from input name
			$wiki['postVisible'] = ((intval($_POST['postVisible'])) ? 1 : 0);
			$wiki['rawName']     = $name;
			$wiki['htmlName']    = escape_tags($name);
			//$wiki['urlName']     = urlencode(urlencode($name));
			$wiki['urlName']     = NativeWiki::name_encode($name);
			$wiki['mimeType']    = $_POST['mimeType'];
			$wiki['typelock']    = $_POST['typelock'];

			if($wiki['urlName'] === '') {
				notice( t('Error creating wiki. Invalid name.') . EOL);
				goaway('/wiki');
				return; //not reached
			}

			$exists = NativeWiki::exists_by_name($owner['channel_id'], $wiki['urlName']);
			if($exists['id']) {
				notice( t('A wiki with this name already exists.') . EOL);
				goaway('/wiki');
				return; //not reached
			}

			// Get ACL for permissions
			$acl = new \Zotlabs\Access\AccessList($owner);
			$acl->set_from_array($_POST);
			$r = NativeWiki::create_wiki($owner, $observer_hash, $wiki, $acl);
			if($r['success']) {
				NativeWiki::sync_a_wiki_item($owner['channel_id'],$r['item_id'],$r['item']['resource_id']);
				$homePage = NativeWikiPage::create_page($owner, $observer_hash, 'Home', $r['item']['resource_id'], $wiki['mimeType']);
				if(! $homePage['success']) {
					notice( t('Wiki created, but error creating Home page.'));
					goaway(z_root() . '/wiki/' . $nick . '/' . NativeWiki::name_encode($wiki['urlName']));
				}
				NativeWiki::sync_a_wiki_item($owner['channel_id'], $homePage['item_id'], $r['item']['resource_id']);
				goaway(z_root() . '/wiki/' . $nick . '/' . NativeWiki::name_encode($wiki['urlName']) . '/' . NativeWiki::name_encode($homePage['page']['urlName']));
			}
			else {
				notice( t('Error creating wiki'));
				goaway(z_root() . '/wiki');
			}
		}

		// Update a wiki
		// /wiki/channel/update/wiki
		if ((argc() > 3) && (argv(2) === 'update') && (argv(3) === 'wiki')) {
			// Only the channel owner can update a wiki, at least until we create a
			// more detail permissions framework

			if (local_channel() !== intval($owner['channel_id'])) {
				goaway('/' . argv(0) . '/' . $nick . '/');
			}

			$arr = [];

			//$arr['urlName'] = urlencode(urlencode($_POST['origRawName']));
			$arr['urlName'] = NativeWiki::name_encode($_POST['origRawName']);

			if($_POST['updateRawName'])
				$arr['updateRawName'] = $_POST['updateRawName'];

			if(($arr['urlName'] || $arr['updateRawName']) === '') {
				notice( t('Error updating wiki. Invalid name.') . EOL);
				goaway('/wiki');
				return; //not reached
			}

			$wiki = NativeWiki::exists_by_name($owner['channel_id'], $arr['urlName']);
			if($wiki['resource_id']) {

				$arr['resource_id'] = $wiki['resource_id'];

				$acl = new \Zotlabs\Access\AccessList($owner);
				$acl->set_from_array($_POST);

				$r = NativeWiki::update_wiki($owner['channel_id'], $observer_hash, $arr, $acl);
				if($r['success']) {
					NativeWiki::sync_a_wiki_item($owner['channel_id'], $r['item_id'], $r['item']['resource_id']);
					goaway(z_root() . '/wiki/' . $nick);
				}
				else {
					notice( t('Error updating wiki'));
					goaway(z_root() . '/wiki');
				}

			}
			goaway(z_root() . '/wiki');
		}

		// Delete a wiki
		if ((argc() > 3) && (argv(2) === 'delete') && (argv(3) === 'wiki')) {

			// Only the channel owner can delete a wiki, at least until we create a
			// more detail permissions framework
			if (local_channel() !== intval($owner['channel_id'])) {
				logger('Wiki delete permission denied.');
				json_return_and_die(array('message' => t('Wiki delete permission denied.'), 'success' => false));
			}
			$resource_id = $_POST['resource_id'];
			$deleted = NativeWiki::delete_wiki($owner['channel_id'],$observer_hash,$resource_id);
			if ($deleted['success']) {
				NativeWiki::sync_a_wiki_item($owner['channel_id'], 0, $resource_id);
				json_return_and_die(array('message' => '', 'success' => true));
			}
			else {
				logger('Error deleting wiki: ' . $resource_id . ' ' . $deleted['message']);
				json_return_and_die(array('message' => t('Error deleting wiki'), 'success' => false));
			}
		}


		// Create a page
		if ((argc() === 4) && (argv(2) === 'create') && (argv(3) === 'page')) {

			$mimetype = $_POST['mimetype'];

			$resource_id = $_POST['resource_id'];
			// Determine if observer has permission to create a page


			$perms = NativeWiki::get_permissions($resource_id, intval($owner['channel_id']), $observer_hash);
			if(! $perms['write']) {
				logger('Wiki write permission denied. ' . EOL);
				json_return_and_die(array('success' => false));
			}

			$name = isset($_POST['pageName']) ? $_POST['pageName'] : $_POST['missingPageName']; //Get new page name

			// backslashes won't work well in the javascript functions
			$name = str_replace('\\','',$name);

			if(NativeWiki::name_encode(escape_tags($name)) === '') {
				json_return_and_die(array('message' => 'Error creating page. Invalid name (' . print_r($_POST,true) . ').', 'success' => false));
			}

			$page = NativeWikiPage::create_page($owner, $observer_hash, $name, $resource_id, $mimetype);
			if($page['item_id']) {

				$commit = NativeWikiPage::commit([
					'commit_msg'    => t('New page created'),
					'resource_id'   => $resource_id,
					'channel_id'    => $owner['channel_id'],
					'observer_hash' => $observer_hash,
					'pageUrlName'   => $name
				]);
				if($commit['success']) {
					NativeWiki::sync_a_wiki_item($owner['channel_id'], $commit['item_id'], $resource_id);
					//json_return_and_die(array('url' => '/' . argv(0) . '/' . argv(1) . '/' . urlencode($page['wiki']['urlName']) . '/' . urlencode($page['page']['urlName']), 'success' => true));
					json_return_and_die(array('url' => '/' . argv(0) . '/' . argv(1) . '/' . $page['wiki']['urlName'] . '/' . $page['page']['urlName'], 'success' => true));
				}
				else {
					json_return_and_die(array('message' => 'Error making git commit','url' => '/' . argv(0) . '/' . argv(1) . '/' . NativeWiki::name_encode($page['wiki']['urlName']) . '/' . NativeWiki::name_encode($page['page']['urlName']),'success' => false));
				}


			}
			else {
				logger('Error creating page');
				json_return_and_die(array('message' => 'Error creating page.', 'success' => false));
			}
		}

		// Fetch page list for a wiki
		if((argc() === 5) && (argv(2) === 'get') && (argv(3) === 'page') && (argv(4) === 'list')) {
			$resource_id = $_POST['resource_id']; // resource_id for wiki in db

			$perms = NativeWiki::get_permissions($resource_id, intval($owner['channel_id']), $observer_hash);
			if(!$perms['read']) {
				logger('Wiki read permission denied.' . EOL);
				json_return_and_die(array('pages' => null, 'message' => 'Permission denied.', 'success' => false));
			}

			// @FIXME - we shouldn't invoke this if it isn't in the PDL or has been over-ridden

			$x = new Wiki_pages();

			$page_list_html = $x->widget([
				'resource_id' => $resource_id,
				'channel_id' => $owner['channel_id'],
				'channel_address' => $owner['channel_address'],
				'refresh' => true
			]);
			json_return_and_die(array('pages' => $page_list_html, 'message' => '', 'success' => true));
		}

		// Save a page
		if ((argc() === 4) && (argv(2) === 'save') && (argv(3) === 'page')) {

			$resource_id = $_POST['resource_id'];
			$pageUrlName = $_POST['name'];
			$pageHtmlName = escape_tags($_POST['name']);
			$content = $_POST['content']; //Get new content
			$commitMsg = $_POST['commitMsg'];
			if ($commitMsg === '') {
				$commitMsg = 'Updated ' . $pageHtmlName;
			}

			// Determine if observer has permission to save content
			$perms = NativeWiki::get_permissions($resource_id, intval($owner['channel_id']), $observer_hash);
			if(! $perms['write']) {
				logger('Wiki write permission denied. ' . EOL);
				json_return_and_die(array('success' => false));
			}

			$saved = NativeWikiPage::save_page([
				'channel_id' => $owner['channel_id'],
				'observer_hash' => $observer_hash,
				'resource_id' => $resource_id,
				'pageUrlName' => $pageUrlName,
				'content' => $content
			]);
			if($saved['success']) {

				$commit = NativeWikiPage::commit([
					'commit_msg' => $commitMsg,
					'pageUrlName' => $pageUrlName,
					'resource_id' => $resource_id,
					'channel_id'    => $owner['channel_id'],
					'observer_hash' => $observer_hash,
					'revision' => (-1)
				]);
				if($commit['success']) {
					NativeWiki::sync_a_wiki_item($owner['channel_id'], $commit['item_id'], $resource_id);
					json_return_and_die(array('message' => 'Wiki git repo commit made', 'success' => true , 'content' => $content));
				}
				else {
					json_return_and_die(array('message' => 'Error making git commit','success' => false));
				}
			}
			else {
				json_return_and_die(array('message' => 'Error saving page', 'success' => false));
			}
		}

		// Update page history
		// /wiki/channel/history/page
		if ((argc() === 4) && (argv(2) === 'history') && (argv(3) === 'page')) {

			$resource_id = $_POST['resource_id'];
			$pageUrlName = $_POST['name'];

			// Determine if observer has permission to read content

			$perms = NativeWiki::get_permissions($resource_id, intval($owner['channel_id']), $observer_hash);
			if(! $perms['read']) {
				logger('Wiki read permission denied.' . EOL);
				json_return_and_die(array('historyHTML' => '', 'message' => 'Permission denied.', 'success' => false));
			}

			$historyHTML = NativeWikiPage::render_page_history(array(
				'resource_id' => $resource_id,
				'pageUrlName' => $pageUrlName,
				'permsWrite'  => $perms['write']
			));

			json_return_and_die(array('historyHTML' => $historyHTML, 'message' => '', 'success' => true));
		}

		// Delete a page
		if ((argc() === 4) && (argv(2) === 'delete') && (argv(3) === 'page')) {

			$resource_id = $_POST['resource_id'];
			$pageUrlName = $_POST['name'];

			if ($pageUrlName === 'Home') {
				json_return_and_die(array('message' => t('Cannot delete Home'),'success' => false));
			}

			// Determine if observer has permission to delete pages
			// currently just allow page owner
			if((! local_channel()) || (local_channel() != $owner['channel_id'])) {
				logger('Wiki write permission denied. ' . EOL);
				json_return_and_die(array('success' => false));
			}

			$perms = NativeWiki::get_permissions($resource_id, intval($owner['channel_id']), $observer_hash);
			if(! $perms['write']) {
				logger('Wiki write permission denied. ' . EOL);
				json_return_and_die(array('success' => false));
			}

			$deleted = NativeWikiPage::delete_page([
				'channel_id' => $owner['channel_id'],
				'observer_hash' => $observer_hash,
				'resource_id' => $resource_id,
				'pageUrlName' => $pageUrlName
			]);
			if($deleted['success']) {
				NativeWiki::sync_a_wiki_item($owner['channel_id'], 0, $resource_id);
				json_return_and_die(array('message' => 'Wiki git repo commit made', 'success' => true));
			}
			else {
				json_return_and_die(array('message' => 'Error deleting page', 'success' => false));
			}
		}

		// Revert a page
		if ((argc() === 4) && (argv(2) === 'revert') && (argv(3) === 'page')) {

			$resource_id = $_POST['resource_id'];
			$pageUrlName = $_POST['name'];
			$commitHash = $_POST['commitHash'];

			// Determine if observer has permission to revert pages
			$perms = NativeWiki::get_permissions($resource_id, intval($owner['channel_id']), $observer_hash);
			if(! $perms['write']) {
				logger('Wiki write permission denied.' . EOL);
				json_return_and_die(array('success' => false));
			}

			$reverted = NativeWikiPage::revert_page([
				'channel_id' => $owner['channel_id'],
				'observer_hash' => $observer_hash,
				'commitHash' => $commitHash,
				'resource_id' => $resource_id,
				'pageUrlName' => $pageUrlName
			]);
			if($reverted['success']) {
				json_return_and_die(array('content' => $reverted['content'], 'message' => '', 'success' => true));
			}
			else {
				json_return_and_die(array('content' => '', 'message' => 'Error reverting page', 'success' => false));
			}
		}

		// Compare page revisions
		if ((argc() === 4) && (argv(2) === 'compare') && (argv(3) === 'page')) {
			$resource_id = $_POST['resource_id'];
			$pageUrlName = $_POST['name'];
			$compareCommit = $_POST['compareCommit'];
			$currentCommit = $_POST['currentCommit'];
			// Determine if observer has permission to revert pages

			$perms = NativeWiki::get_permissions($resource_id, intval($owner['channel_id']), $observer_hash);
			if(!$perms['read']) {
				logger('Wiki read permission denied.' . EOL);
				json_return_and_die(array('success' => false));
			}

			$compare = NativeWikiPage::compare_page(array('channel_id' => $owner['channel_id'], 'observer_hash' => $observer_hash, 'currentCommit' => $currentCommit, 'compareCommit' => $compareCommit, 'resource_id' => $resource_id, 'pageUrlName' => $pageUrlName));
			if($compare['success']) {
				$diffHTML = '<table class="text-center" width="100%"><tr><td class="lead" width="50%">' . t('Current Revision') . '</td><td class="lead" width="50%">' . t('Selected Revision') . '</td></tr></table>' . $compare['diff'];
				json_return_and_die(array('diff' => $diffHTML, 'message' => '', 'success' => true));
			} else {
				json_return_and_die(array('diff' => '', 'message' => 'Error comparing page', 'success' => false));
			}
		}

		// Rename a page
		if ((argc() === 4) && (argv(2) === 'rename') && (argv(3) === 'page')) {
			$resource_id = $_POST['resource_id'];
			$pageUrlName = $_POST['oldName'];
			$pageNewName = str_replace('\\','',$_POST['newName']);
			if ($pageUrlName === 'Home') {
				json_return_and_die(array('message' => 'Cannot rename Home','success' => false));
			}
			if(NativeWiki::name_encode(escape_tags($pageNewName)) === '') {
				json_return_and_die(array('message' => 'Error renaming page. Invalid name.', 'success' => false));
			}
			// Determine if observer has permission to rename pages

			$perms = NativeWiki::get_permissions($resource_id, intval($owner['channel_id']), $observer_hash);
			if(! $perms['write']) {
				logger('Wiki write permission denied. ' . EOL);
				json_return_and_die(array('success' => false));
			}

			$renamed = NativeWikiPage::rename_page([
				'channel_id' => $owner['channel_id'],
				'observer_hash' => $observer_hash,
				'resource_id' => $resource_id,
				'pageUrlName' => $pageUrlName,
				'pageNewName' => $pageNewName
			]);
			if($renamed['success']) {
				$commit = NativeWikiPage::commit([
					'channel_id' => $owner['channel_id'],
					'commit_msg' => 'Renamed ' . NativeWiki::name_decode($pageUrlName) . ' to ' . $renamed['page']['htmlName'],
					'resource_id' => $resource_id,
					'observer_hash' => $observer_hash,
					'pageUrlName' => $pageNewName
				]);
				if($commit['success']) {
					NativeWiki::sync_a_wiki_item($owner['channel_id'], $commit['item_id'], $resource_id);
					json_return_and_die(array('name' => $renamed['page'], 'message' => 'Wiki git repo commit made', 'success' => true));
				}
				else {
					json_return_and_die(array('message' => 'Error making git commit','success' => false));
				}
			}
			else {
				json_return_and_die(array('message' => 'Error renaming page', 'success' => false));
			}
		}

		//notice( t('You must be authenticated.'));
		json_return_and_die(array('message' => t('You must be authenticated.'), 'success' => false));

	}

	function create_missing_page() {
		if(argc() < 4)
			return;

		$c = channelx_by_nick(argv(1));
		$w = NativeWiki::exists_by_name($c['channel_id'],NativeWiki::name_decode(argv(2)));
		$arr = array(
			'resource_id' => $w['resource_id'],
			'channel_id' => $c['channel_id'],
			'channel_address' => $c['channel_address'],
			'refresh' => false
		);

		$can_create = perm_is_allowed(\App::$profile['uid'],get_observer_hash(),'write_wiki');

		$can_delete = ((local_channel() && (local_channel() == \App::$profile['uid'])) ? true : false);
                $pageName = NativeWiki::name_decode(escape_tags(argv(3)));

		$wikiname = $w['urlName'];
		return replace_macros(get_markup_template('wiki_page_not_found.tpl', 'addon/wiki'), array(
				'$resource_id' => $arr['resource_id'],
				'$channel_address' => $arr['channel_address'],
				'$wikiname' => $wikiname,
				'$canadd' => $can_create,
				'$candel' => $can_delete,
				'$addnew' => t('Add new page'),
				'$typelock' => $typelock,
				'$lockedtype' => $w['mimeType'],
				'$mimetype' => mimetype_select(0,$w['mimeType'],
					[ 'text/markdown' => t('Markdown'), 'text/bbcode' => t('BBcode'), 'text/plain' => t('Text') ]),
				'$pageName' => array('missingPageName', 'Create Page' , $pageName),
				'$refresh' => $arr['refresh'],
				'$options' => t('Options'),
				'$submit' => t('Submit')
		));
	}

}
