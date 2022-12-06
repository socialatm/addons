<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Web\Controller;

class Wppost extends Controller {

	function post() {
		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'wppost'))
			return;

		check_form_security_token_redirectOnErr('/wppost', 'wppost');

		set_pconfig(local_channel(),'wppost','post',intval($_POST['wppost']));
		set_pconfig(local_channel(),'wppost','post_by_default',intval($_POST['wp_bydefault']));
		set_pconfig(local_channel(),'wppost','wp_blogid',intval($_POST['wp_blogid']));
		set_pconfig(local_channel(),'wppost','wp_username',trim($_POST['wp_username']));
		set_pconfig(local_channel(),'wppost','wp_password',obscurify(trim($_POST['wp_password'])));
		set_pconfig(local_channel(),'wppost','wp_blog',trim($_POST['wp_blog']));
		set_pconfig(local_channel(),'wppost','forward_comments',trim($_POST['wp_forward_comments']));
		set_pconfig(local_channel(),'wppost','post_source_url',intval($_POST['wp_source_url']));
		set_pconfig(local_channel(),'wppost','post_source_urltext',trim($_POST['wp_source_urltext']));

		info( t('Wordpress Settings saved.') . EOL);

	}

	function get() {

		if(! local_channel())
			return;

		if(! Apps::addon_app_installed(local_channel(), 'wppost')) {
			//Do not display any associated widgets at this point
			App::$pdl = '';
			$papp = Apps::get_papp('Wordpress Post');
			return Apps::app_render($papp, 'module');
		}

		/* Get the current state of our config variables */

		$fwd_enabled = get_pconfig(local_channel(), 'wppost','forward_comments');

		$fwd_checked = (($fwd_enabled) ? 1 : false);

		$def_enabled = get_pconfig(local_channel(),'wppost','post_by_default');

		$def_checked = (($def_enabled) ? 1 : false);

		$wp_username = get_pconfig(local_channel(), 'wppost', 'wp_username');
		$wp_password = unobscurify(get_pconfig(local_channel(), 'wppost', 'wp_password'));
		$wp_blog = get_pconfig(local_channel(), 'wppost', 'wp_blog');
		$wp_blogid = get_pconfig(local_channel(), 'wppost', 'wp_blogid');
		$url_enabled = get_pconfig(local_channel(),'wppost','post_source_url');
		$url_checked = (($url_enabled) ? 1 : false);
		$wp_source_urltext = get_pconfig(local_channel(), 'wppost', 'post_source_urltext');

		/* Add some HTML to the existing form */

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('wp_username', t('WordPress username'), $wp_username, '')
		));

		$sc .= replace_macros(get_markup_template('field_password.tpl'), array(
			'$field'	=> array('wp_password', t('WordPress password'), $wp_password, '')
		));

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('wp_blog', t('WordPress API URL'), $wp_blog,
						 t('Typically https://your-blog.tld/xmlrpc.php'))
		));
		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
			'$field'	=> array('wp_blogid', t('WordPress blogid'), $wp_blogid,
						 t('For multi-user sites such as wordpress.com, otherwise leave blank'))
		));

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('wp_bydefault', t('Post to WordPress by default'), $def_checked, '', array(t('No'),t('Yes'))),
		));

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('wp_forward_comments', t('Forward comments (requires hubzilla_wp plugin)'), $fwd_checked, '', array(t('No'),t('Yes'))),
		));

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
			'$field'	=> array('wp_source_url', t('Add link to original post'), (get_pconfig(local_channel(),'wppost','post_source_url') ? 1 : false), '', array(t('No'),t('Yes'))),
		));

		$sc .= replace_macros(get_markup_template('field_input.tpl'), array(
		    '$field'	=> array('wp_source_urltext', t('Link description (default:') . ' "' . t('Source') . '")', $wp_source_urltext, '')
		));

		$tpl = get_markup_template("settings_addon.tpl");

		$o .= replace_macros($tpl, array(
			'$action_url' => 'wppost',
			'$form_security_token' => get_form_security_token("wppost"),
			'$title' => t('Wordpress Post'),
			'$content'  => $sc,
			'$baseurl'   => z_root(),
			'$submit'    => t('Submit'),
		));

		return $o;

	}

}
