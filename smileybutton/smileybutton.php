<?php
/**
 * Name: Smileybutton
 * Description: Adds a smileybutton to the Inputbox
 * Version: 0.1
 * Author: Johannes Schwab , Christian Vogeley
 * ToDo: Add this to comments, Allow to disable on webpages, nicer position of button
 * Maintainer: none
 */

use Zotlabs\Lib\Apps;
use Zotlabs\Extend\Hook;
use Zotlabs\Extend\Route;

function smileybutton_load() {

	/**
	 * 
	 * Register hooks for jot_tool and plugin_settings
	 *
	 */

	Hook::register('jot_tool', 'addon/smileybutton/smileybutton.php', 'show_button');
	Route::register('addon/smileybutton/Mod_Smileybutton.php','smileybutton');
 
}

function smileybutton_unload() {

	/**
	 *
	 * Delet registered hooks
	 *
	 */

	Hook::register('jot_tool',    'addon/smileybutton/smileybutton.php', 'show_button');
	Route::unregister('addon/smileybutton/Mod_Smileybutton.php','smileybutton');
	 
}

function show_button(&$b) {

	/**
	 *
	 * Check if it is a local user and he has enabled smileybutton
	 *
	 */

	if(! local_channel())
		return;

	if(! Apps::addon_app_installed(local_channel(),'smileybutton'))
		return;

	$nobutton = get_pconfig(local_channel(), 'smileybutton', 'nobutton');

	/**
	 *
	 * Prepare the Smilie-Arrays
	 *
	 */

	$s = list_smilies(true);

	/**
	 *
	 * Generate html for smileylist
	 *
	 */

	$html = "\t<table class=\"smiley-preview\"><tr>\n";
	for($x = 0; $x < count($s['texts']); $x ++) {
		$icon = $s['icons'][$x];
		$icon = str_replace('/>', 'onclick="smileybutton_addsmiley(\'' . $s['texts'][$x] . '\')"/>', $icon);
		$icon = str_replace('class="smiley"', 'class="smiley_preview"', $icon);
		$html .= "<td>" . $icon . "</td>";
		if (($x+1) % (sqrt(count($s['texts']))+1) == 0) {
			$html .= "</tr>\n\t<tr>";
		}
	}
	$html .= "\t</tr></table>\n";

	/**
	 *
	 * Add the button to the Inputbox
	 *
	 */	
	if (! $nobutton ) {
		$b .= "<div id=\"profile-smiley-wrapper\"  >\n";
		//$b .= "\t<img src=\"" . z_root() . "/addon/smileybutton/icon.gif\" onclick=\"toggle_smileybutton(); return false;\" alt=\"smiley\">\n";
		$b .= "\t<button class=\"btn btn-default btn-sm\" onclick=\"toggle_smileybutton(); return false;\"><i id=\"profile-smiley-button\" class=\"fa fa-smile-o jot-icons\" ></i></button>\n";
		$b .= "\t</div>\n";
	}

	/**
	 *
	 * Write the smileies to an (hidden) div
	 *
	 */
	if ($nobutton) {
		$b .= "\t<div id=\"smileybutton\">\n";
	} else {
		$b .= "\t<div id=\"smileybutton\" style=\"display:none;\">\n";
	}

	$b .= $html . "\n"; 
	$b .= "</div>\n";

	/**
	 *
	 * Function to show and hide the smiley-list in the hidden div
	 *
	 */
	$b .= "<script>\n"; 

	if (! $nobutton) {
		$b .= "	smileybutton_show = 0;\n";
		$b .= "	function toggle_smileybutton() {\n";
		$b .= "	if (! smileybutton_show) {\n";
		$b .= "		$(\"#smileybutton\").show();\n";
		$b .= "		smileybutton_show = 1;\n";
		$b .= "	} else {\n";
		$b .= "		$(\"#smileybutton\").hide();\n";
		$b .= "		smileybutton_show = 0;\n";
		$b .= "	}}\n";
	} 

	/**
	 *
	 * Function to add the chosen smiley to the inputbox
	 *
	 */
	$b .= "	function smileybutton_addsmiley(text) {\n";
	$b .= "		if(plaintext == 'none') {\n";
	$b .= "			var v = $(\"#profile-jot-text\").val();\n";
	$b .= "			v = v + text;\n";
	$b .= "			$(\"#profile-jot-text\").val(v);\n";
	$b .= "			$(\"#profile-jot-text\").focus();\n";
	$b .= "		} else {\n";
	$b .= "			var v = tinymce.activeEditor.getContent();\n";
	$b .= "			v = v + text;\n";
	$b .= "			tinymce.activeEditor.setContent(v);\n";
	$b .= "			tinymce.activeEditor.focus();\n";
	$b .= "		}\n";
	$b .= "	}\n";
	$b .= "</script>\n";
}

