<?php
/**
 * Name: Channel Reputation
 * Description: Reputation system for community channels (forums, etc.)
 * Version: 1.0
 * Author: DM42.Net, LLC
 * Maintainer: devhubzilla@dm42.net
 * MinVersion: 4.4.0
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;
use Zotlabs\Lib\IConfig;
use Zotlabs\Lib\PConfig;

function channelreputation_load() {
        Hook::register('get_all_api_perms', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::get_perms_filter',1,5000);
        Hook::register('get_all_perms', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::get_perms_filter',1,5000);
        Hook::register('perm_is_allowed', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::perm_is_allowed',1,5000);
        Hook::register('can_comment_on_post', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::can_comment_on_post',1,5000);
        Hook::register('channelrep_mod_post', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::mod_post',1,5000);
        Hook::register('dropdown_extras', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::dropdown_extras',1,5000);
        Hook::register('channelrep_mod_content', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::mod_content',1,5000);
        Hook::register('channel_apps', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::channel_apps',1,5000);
        Hook::register('permissions_list', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::permissions_list',1,5000);
        Hook::register('item_store', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::item_store',1,5000);
        Hook::register('page_header', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::page_header',1,5000);
        Hook::register('page_end', 'addon/channelreputation/channelreputation.php', 'ChannelReputation_Utils::page_end',1,5000);
	Route::register('addon/channelreputation/Mod_ChannelReputation.php','channelreputation');
	Route::register('addon/channelreputation/Mod_settings.php','settings/channelreputation');
}

function channelreputation_unload() {
        Hook::unregister_by_file('addon/channelreputation/channelreputation.php');
	Route::unregister_by_file('addon/channelreputation/Mod_ChannelReputation.php');
	Route::unregister_by_file('addon/channelreputation/Mod_settings.php');
}


define('CHANNELREPUTATION_COMMUNITYMODERATION', 0); //All community members contribute to the moderation rating of other members.
define('CHANNELREPUTATION_MOSTREPUTABLEMODERATION', 1); //Only the most reputable community member's opinion "counts" - NOT IMPLEMENTED
define('CHANNELREPUTATION_MODERATORONLYMODERATION', 2); //Only assigned moderators opinion "counts" - NOT IMPLEMENTED

function channelreputation_mod_content(&$arr) {
        ChannelReputation_Utils::mod_content($arr);
}

class ChannelReputation_Utils {
               /* REPUTATION:
                          ['reputation'] - float - reputation value
                          ['last_action'] - time() of last action affecting reputation
               */
        public static $reputation = Array();

        public static $itemsseen = Array();

        public static $settings = null;
        public static $default_settings = Array (
                          'starting_reputation' => 3, //Reputation automatically given to new members
                          'minimum_reputation' => -2, //Reputation will never fall below this value
                          'minimum_to_post' => 2, //Minimum reputation before posting is allowed
                          'minimum_to_comment' => 1, //Minimum reputation before commenting is allowed
                          'minimum_to_moderate' => 4, //Minimum reputation before a member is able to moderate other posts
                          'max_moderation_factor' => 0.25, //max ratio of moderator's reputation that can be added to/deducted from reputation of person being moderated
                          'post_cost' => 2, //Reputation "cost" to post
                          'comment_cost' => 1, //Reputation "cost" to comment
                          'hourly_post_recovery' => 0.25, //Reputation automatically recovers at this rate per hour until it reaches minimum_to_post
                          'hourly_moderate_recovery' => 0.125, //When minimum_to_moderate > reputation > minimum_to_post reputation recovers at this rate per hour
                          'moderators_groups' => Array('Moderators'), //Members of these groups do not lose reputation by moderating (up to moderators_modpoints) NOT IMPLEMENTED
                          'moderators_modpoints' => 2, //Members of moderators_groups may reward/penalize up to this amount per moderation action without losing any of their personal reputation
                          'moderate_by' => CHANNELREPUTATION_COMMUNITYMODERATION
                 );

        public static function page_end(&$footer) {
                $id = App::$profile_uid;
                if (!$id) { return; }
                $enable = get_pconfig ($id,'channelreputation','enable');
                if (!$enable) { return; }
                $footer .= '<div class="modal fade" id="channelrepModal" tabindex="-1" role="dialog" aria-labelledby="channelrepModalLabel" aria-hidden="true"></div>';
        }

        public static function page_header(&$header) {
                $id = App::$profile_uid;
                if (!$id) { return; }
                $enable = get_pconfig ($id,'channelreputation','enable');
                if (!$enable) { return; }

                $header .= '<link href="addon/channelreputation/view/css/channelreputation.css" rel="stylesheet">';
                head_add_js('/addon/channelreputation/view/js/channelreputation.js');
        }

        public static function feature_settings () {
        	if(! Apps::addon_app_installed(local_channel(), 'channelreputation')) {
                	return "<h1>Page not found</h1>";
        	}

                $id = local_channel();

                if (! $id) {
                        notice(t('Access Denied').EOL);
                        echo "<h2>".t('Access Denied')."</h2>";
                        return;
                }

                $enable = get_pconfig ($id,'channelreputation','enable');

                $sc = replace_macros(get_markup_template('field_checkbox.tpl'), array(
                       '$field'   => array('channelrep_enable', t('Enable Community Moderation'),
                               (isset($enable) ? $enable : 0),
                               '',array(t('No'),t('Yes')))));

                if (isset($enable)  && $enable == 1) {

                        $settings = self::get_settings($id);
                        $settings_info = Array (
                                'starting_reputation' => t('Reputation automatically given to new members'),
                                'minimum_reputation' => t('Reputation will never fall below this value'),
                                'minimum_to_post' => t('Minimum reputation before posting is allowed'),
                                'minimum_to_comment' => t('Minimum reputation before commenting is allowed'),
                                'minimum_to_moderate' => t('Minimum reputation before a member is able to moderate other posts'),
                                'max_moderation_factor' => t('Max ratio of moderator\'s reputation that can be added to/deducted from reputation of person being moderated'),
                                'post_cost' => t('Reputation "cost" to post'),
                                'comment_cost' => t('Reputation "cost" to comment'),
                                'hourly_post_recovery' => t('Reputation automatically recovers at this rate per hour until it reaches minimum_to_post'),
                                'hourly_moderate_recovery' => t('When minimum_to_moderate > reputation > minimum_to_post reputation recovers at this rate per hour')
                        );
                        foreach ($settings_info as $setting=>$text) {
                                $sc .= replace_macros(get_markup_template('field_input.tpl'),array(
                                        '$field'     => array ('channelrep_'.$setting, $text,
                                        (isset($settings[$setting]) ? $settings[$setting] : ''),
                                        '',''
                                )));
                        }
                }

                $o = replace_macros(get_markup_template('settings_addon.tpl'), array(
                        '$action_url' => 'settings/channelreputation',
                        '$form_security_token' => get_form_security_token("channelreputation"),
                        '$title' => t('Community Moderation Settings'),
                        '$content'  => $sc,
                        '$baseurl'   => z_root(),
                        '$submit'    => t('Submit'),
                ));


		return $o;
        }

	public static function feature_settings_post () {

		if(! Apps::addon_app_installed(local_channel(), 'channelreputation')) {
			return;
		}

		$id = local_channel();
		if (! $id) {
			return;
		}

                check_form_security_token_redirectOnErr('/channelreputation/settings','channelreputation');

		$prev_enable = get_pconfig ($id,'channelreputation','enable');
		set_pconfig( local_channel(), 'channelreputation', 'enable', intval($_POST['channelrep_enable']) );

		if (!isset($_POST['channelrep_enable']) || $_POST['channelrep_enable'] != $prev_enable) {
			return;
		}

		$settings = self::get_settings($id);
		foreach ($settings as $setting=>$value) {
			$postvar = 'channelrep_'.$setting;

			if (isset($_POST[$postvar])) {
				$newval = $_POST[$postvar];
				$settings[$setting] = floatval($newval);
			}

		}

		$settings["validationkey"] = random_string();

		$allsettings = self::$settings;
		$allsettings[$id] = $settings;
		self::$settings = $allsettings;
		self::update_settings();
	}

        public static function dropdown_extras (&$extras) {
                $uid = App::$profile_uid ? App::$profile_uid : local_channel();
                if (!$uid) {return;}
								$enable = get_pconfig ($uid,'channelreputation','enable');
								if (!$enable) { return; }
                $settings = self::get_settings($uid);
                $moderator_rep = self::get($uid,get_observer_hash());

                if (floatval($moderator_rep['reputation']) < $settings['minimum_to_moderate'] &&
                    !perm_is_allowed($uid,get_observer_hash(),'moderate_reputation')) {
                        return;
                }
                $arr = $extras;
                $item_id = $extras['item']['item_id'];
                $arr['dropdown_extras'] .= '<a class="dropdown-item" href="#" onclick="channelrepShowModerateForm('.$item_id.'); return false;" title="Reputation"><i class="generic-icons-nav fa fa-fw fa-line-chart"></i> Score Reputation</a>';
                $extras = $arr;
        }

        public static function mod_post ($postvars) {
                $success = true;
                $observer = get_observer_hash();
                if ($success && check_form_security_token()) {
                        $itemid = intval($postvars["channelrepId"]);
                        $points = floatval($postvars["channelrepPoints"]);
                        $action = intval($postvars["channelrepAction"]);
                        $uid = intval($postvars["uid"]);

                        $points = $points * $action;

                        $items = q('select * from item where id = %d',intval($itemid));
                        if (!$items) {
                                    $returnjson = self::maybejson(Array("Success"=>false));
                                    return $returnjson;
                        }
                        $item = $items[0];
                        if ($item['id'] == $itemid) {
                            self::moderate($item["uid"],$observer,$item["author_xchan"],$points);
                        }
                } else {
                        if (argc() > 1) {
                             $item = intval(argv(1));
                             $uid = intval($postvars["uid"]);
                             $values = Array (
                                 'title' => t('Channel Reputation'),
                                 'close' => t('Close')
                             );
                             if (!$uid) {
                                     $values['error'] = t('An Error has occurred.');
                                     return replace_macros(get_markup_template('channelrepModalerror.tpl','addon/channelreputation/'), $values);
                             }

                             $settings = self::get_settings($uid);

                             $mod_reputation = self::get($uid,$observer);
                             $modpoints = $mod_reputation['reputation'];
                             $maxpoints = self::get_maxmodpoints($uid,$mod_reputation,perm_is_allowed($uid,$observer,'moderate_reputation'));

                             $recommended = sprintf('%1$.3f',$maxpoints / 10);
                             $maxpoints = sprintf('%1$.3f',$maxpoints);
                             $values += Array(
                                     'maxpoints' => $maxpoints,
                                     'security_token' => get_form_security_token(),
                                     'channelrepId' => $item,
                                     'pointssuggestion' => $recommended,
                                     'uid' => $uid,
                                     'upvote' => t('Upvote'),
                                     'downvote' => t('Downvote')
                             );
                             return replace_macros(get_markup_template('channelrepModal.tpl','addon/channelreputation/'), $values);

                        }
                        $success = false;
                }

                $returnjson = self::maybejson(Array("Success"=>$success));
                return $returnjson;
        }

        public static function update_settings () {
            $channel = local_channel();
            if (!$channel) {
                    return;
            }

            $settings = self::maybejson(self::$settings[$channel]);
            set_pconfig($channel,'channelreputation','settings',$settings);

        }

        public static function get_settings ($uid) {
            if (is_array(self::$settings[$uid])) {
                    return self::$settings[$uid];
            }

            $allsettings = self::$settings;

            $settings = get_pconfig ($uid,'channelreputation','settings');
            $settings = self::maybeunjson($settings);
            foreach (self::$default_settings as $setting=>$value) {
                $allsettings[$uid][$setting] = (isset($settings[$setting])) ? $settings[$setting] : self::$default_settings[$setting];
            }

            self::$settings=$allsettings;

            return self::$settings[$uid];

        }

        public static function get ($uid,$channel_hash=null) {
            if (!$channel_hash || !$uid) {
               return null;
            }
            if (isset(self::$reputation[$uid][$channel_hash])) {
                    return self::$reputation[$uid][$channel_hash];
            }
            $settings = self::get_settings($uid);
            $setting = 'repof-'.$channel_hash;
            $reputation = get_pconfig($uid,'channelreputation',$setting);
            $reputation = self::maybeunjson($reputation);
            if (!is_array($reputation) || !isset($reputation["reputation"])) {
                    $reputation = Array('reputation'=>$settings['starting_reputation'], 'lastactivity'=>time(), 'recentitems'=>[]);
            }

            self::$reputation[$uid][$channel_hash] = $reputation;
            return $reputation;
        }

        public static function save ($uid,$channel_hash,$reputation) {

            $reputation_json = self::maybejson($reputation);

            $repsetting = 'repof-'.$channel_hash;
            set_pconfig($uid,'channelreputation',$repsetting,$reputation_json);
            self::$reputation[$uid][$channel_hash]=$reputation;
        }

        public static function update($uid,$channel_hash,$activity='',$points=0,$mid=null) {
            $enable = get_pconfig ($uid,'channelreputation','enable');
            if (!$enable) { return; }
            $settings = self::get_settings($uid);
            $reputation = self::get($uid,$channel_hash);

		// We have a list of the last few items to
		// try to make sure in a channel-clone situation we
		// don't deduct moderation points multiple times.

		if ($mid && in_array($mid,$reputation['recentitems'])) {
			return;
		}
		$hookinfo = [
			'settings' => $settings,
			'reputation' => $reputation
		];
		call_hooks('channelreputation_beforeupdate',$hookinfo);

            //Process hourly recoveries.
            if ($settings['hourly_post_recovery'] > 0) {
            	$timetopost = ($settings['minimum_to_post'] - $reputation['reputation']) / ($settings['hourly_post_recovery'] / 3600) ;
	    } else {
		$timetopost = -3600;
		// Actually, it'd be negative infinity, but if hourly_post_recovery <= 0 this will effectively make it infinite.
            }
            $now = time();
            $timesince = $now - $reputation["lastactivity"];
            $repdelta = 0;
            if ($timetopost > 0) {
                $repdelta = $timesince * ( $settings['hourly_post_recovery'] / 3600 );
            }

            $pointsuntilmoderate = ($settings['minimum_to_moderate'] - $reputation['reputation'] + $repdelta);
	    if ($settings['hourly_moderate_recovery'] > 0) {
            	$timetomoderate = (($settings['minimum_to_moderate'] - $reputation['reputation'] + $repdelta) / ($settings['hourly_moderate_recovery'] / 3600 ));
	    } else {
		$timetomoderate = -3600;
		// Actually, it'd be infinity, but if hourly_moderate_recovery <= 0 this will effectively make it infinite.
            }

            if ($timetopost <= 0 && $timetomoderate > 0) {
                $moderate_recovery = ($timesince * ($settings['hourly_moderate_recovery'] / 3600));
                $repdelta = $repdelta + ($timesince * ($settings['hourly_moderate_recovery'] / 3600));
            }

            switch ($activity) {
                    case 'post': // Submit a post
                         $repdelta = $repdelta - $settings['post_cost'];
                         break;
                    case 'comment': // Submit a comment
                         $repdelta = $repdelta - $settings['comment_cost'];
                         break;
                    case 'moderate': // Moderate a post/comment
                         $repdelta = $repdelta - abs($points);
                         break;
                    case 'apply_moderation': // Apply moderation
                         $repdelta = $repdelta + $points;
                         break;
                    default:
            }

            $reputation['reputation'] = $reputation['reputation'] + $repdelta;
            if ($reputation['reputation'] < $settings['minimum_reputation']) {
                   $reputation['reputation'] = $settings['minimum_reputation'];
            }
		// Save the mid in a short list of recently moderated items
		// to try to make sure in a channel-clone situation we
		// don't deduct moderation points multiple times.
		if ($mid) {
			array_push($reputation['recentitems'],$mid);
			while (count($reputation['recentitems']) > 5) {
				$oldest = array_shift($reputation['recentitems']);
			}
		}
            $reputation['lastactivity'] = $now;
            self::save($uid,$channel_hash,$reputation);
		$hookinfo = [
			'settings' => $settings,
			'reputation' => $reputation
		];
		call_hooks('channelreputation_afterupdate',$hookinfo);
        }

        public static function permissions_list(&$arr) {
            $uid = local_channel();
            $enable = get_pconfig ($uid,'channelreputation','enable');
            if (!$enable) { return; }
            $new = $arr;
            $new['permissions']['moderate_reputation'] = t('Can moderate reputation on my channel.');
            $arr = $new;
            return;
        }

        public static function get_perms_filter(&$arr) {

            $uid = $arr['channel_id'];
            $enable = get_pconfig ($uid,'channelreputation','enable');
            if (!$enable) { return; }

            if ($uid != \App::$profile_uid && \App::$profile_uid!=0) { return; }

            if ($uid == local_channel()) { return; }

            $settings = self::get_settings($uid);

            $channel_hash = $arr['observer_hash'];

            self::update($uid,$channel_hash);
            $reputation = self::get($uid,$channel_hash);
            if ($reputation['reputation'] < $settings['minimum_to_post']) {
                    $arr['permissions']['post_wall']=false;
                    $arr['permissions']['tag_deliver']=false;
            }
            if ($reputation['reputation'] < $settings['minimum_to_comment']) {
                    $arr['permissions']['post_comments']=false;
            }
        }

        public static function check_post($uid,$reputation) {
            $settings = self::get_settings($uid);
            if ($reputation['reputation'] < $settings['minimum_to_post']) {
                 return false;
            }
            return true;
        }

        public static function check_comment($uid,$reputation) {
            $settings = self::get_settings($uid);
            if ($reputation['reputation'] < $settings['minimum_to_comment']) {
                 return false;
            }
            return true;

        }

        public static function perm_is_allowed(&$arr) {

                $arrinfo = $arr;
                $uid = $arrinfo['channel_id'];
                $enable = get_pconfig ($uid,'channelreputation','enable');
                if (!$enable) { return; }
                if ($arrinfo['observer_hash'] == '') { return; }
                if ($uid != \App::$profile_uid && \App::$profile_uid!=0) { return; }
                if ($uid == local_channel()) { return; }

                if ($arrinfo['permission'] != 'post_comments' && $arrinfo['permission'] != 'post_wall' && $arrinfo['permission'] != 'tag_deliver') {
                       return;
                }

                $reputation = self::get($arrinfo['channel_id'],$arrinfo['observer_hash']);
                $permission=1;
                switch ($arrinfo['permission']) {
                    case 'post_wall':
                    case 'tag_deliver':
                        $permission = intval(self::check_post($uid,$reputation));
                        break;
                    case 'post_comments';
                        $permission = intval(self::check_comment($uid,$reputation));
                        break;
                    default:
                }
                if (!$permission) { $arr['result']=0; }
        }

        public static function can_comment_on_post(&$arr) {
                $uid = $arr['channel_id'];
                $enable = get_pconfig ($uid,'channelreputation','enable');
                if (!$enable) { return; }

                $item = $arr['item'];
                $oh = $arr['observer_hash'];
                if ($uid != \App::$profile_uid && \App::$profile_uid!=0) { return; }
                if ($item['author_xhash']==$oh) { return; }

                $reputation = self::get($arr['channel_id'],$arr['observer_hash']);

                if (! self::check_comment($uid,$reputation)) {
                        $arr['allowed'] = 0;
                }
        }

        public static function get_maxmodpoints($uid,$moderator_rep,$is_moderator=0) {
            $settings = self::get_settings($uid);
            $modrep = ($moderator_rep['reputation'] > 0) ? $moderator_rep['reputation'] : 0;

            if ($is_moderator) {

                //Make sure moderators can always use at least $settings['moderators_modpoints'] per action.

                $maxpoints = ($modrep * $settings['max_moderation_factor']) + $settings['moderators_modpoints'];
                $points = ($maxpoints > $modrep) ? ($modrep + $settings['moderators_modpoints']) : $maxpoints;

            } else {
                 if ($modrep < $settings['minimum_to_moderate']) {
                     return 0;
                 }

                 $maxpoints = $moderator_rep['reputation'] * $settings['max_moderation_factor'];
                 $points = ($modrep > $maxpoints) ? $maxpoints : $modrep;
            }

            return $points;

        }

        public static function moderate($uid,$moderator_hash,$poster_hash,$points) {
            $enable = get_pconfig ($uid,'channelreputation','enable');
            if (!$enable) { return; }
            $settings = self::get_settings($uid);
            $poster_rep = self::get($uid,$poster_hash);
            $moderator_rep = self::get($uid,$moderator_hash);

            $is_moderator = perm_is_allowed($uid,$moderator_hash,'moderate_reputation');
            $maxpoints = self::get_maxmodpoints($uid,$moderator_rep,$is_moderator);
            if ($maxpoints == 0) {
            }

            self::update($uid,$poster_hash,$activity='apply_moderation',$points);

            if ($is_moderator) {
                    if ($points > $settings['moderators_modpoints']) {
                             $points = $points - $settings['moderators_modpoints'];
                             self::update($uid,$moderator_hash,$activity='moderate',$points);
                    }
            } else {
                    self::update($uid,$moderator_hash,$activity='moderate',$points);
            }
                    return true;
        }

        public static function maybeunjson ($value) {

                if (is_array($value)) {
                    return $value;
                }

                if ($value!=null) {
                    $decoded=json_decode($value,true);
                } else {
                    return null;
                }

                if (json_last_error() == JSON_ERROR_NONE) {
                    return ($decoded);
                } else {
                    return ($value);
                }
         }

         public static function maybejson ($value,$options=0) {

                if ($value!=null) {
                    if (!is_array($value)) {
                        $decoded=json_decode($value,true);
                    }
                } else {
                    return null;
                }

                if (is_array($value) || json_last_error() != JSON_ERROR_NONE) {
                            $encoded = json_encode($value,$options);
                    return ($encoded);
                } else {
                    return ($value);
                }
         }

         public static function mod_content(&$arr) {
                notice("No content");
         }

         public static function channel_apps(&$hookdata) {
		/* do not list in channel apps until the app actually provides a frontend
                $hookdata['tabs'][] = [
                        'label' => t('Channel Reputation'),
                        'url'   => z_root() . '/channelreputation/' . $hookdata['nickname'],
                        'sel'   => ((argv(0) == 'channelreputation') ? 'active' : ''),
                        'title' => t('Channel Reputation'),
                        'id'    => 'channelrep-tab',
                        'icon'  => 'line-chart'
                ];
		*/
         }

	private static function check_applied($arr) {

		// Avoid duplicate deductions when channel is cloned.

		$settings = self::get_settings($arr['uid']);
		$applied = IConfig::Get($arr,'channelreputation','applied','no');
		$testkey = md5($arr['mid'].$settings['validationkey']);
		return ($applied === $testkey) ? true : false;

	}

         public static function item_store(&$arr) {

                if (!isset($arr['uid'])) { 
			return; 
		}

		if (!Apps::addon_app_installed($arr['uid'], 'channelreputation')) {
			return;
		}

               	$enable = PConfig::Get($arr['uid'],'channelreputation','enable',false);

               	if (!$enable) { 
			return; 
		}

		if (self::check_applied($arr)) {
			return;
		}

               	$poster_rep = self::get($arr['uid'],$arr['author_xchan']);
               	if ($arr['mid'] !== $arr['parent_mid']) {
                       	// Is comment
                       	self::update($arr['uid'],$arr['author_xchan'],'comment',0,$arr['mid']);
               	} else {
                       	// Is post
                       	self::update($arr['uid'],$arr['author_xchan'],'post',0,$arr['mid']);
               	}
		$settings = self::get_settings($arr['uid']);
		$appliedkey = md5($arr['mid'].$settings['validationkey']);
		$applied = IConfig::Set($arr,'channelreputation','applied',$appliedkey,false);
	}
}

function channelreputation_post (&$a) {
       $html = ChannelReputation_Utils::mod_post($_POST);
       echo $html;
       killme();
}

function channelreputation_module() {

}

global $Channelreputation; 
if (!$Channelreputation instanceof Channelreputation) {
  $Channelreputation = new ChannelReputation_Utils();
}
