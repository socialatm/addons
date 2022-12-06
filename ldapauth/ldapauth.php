<?php
/**
 * Name: LDAP Authenticate
 * Description: Authenticate an account against an LDAP directory
 * Version: 1.0
 * Author: Mike Macgirvin
 * Maintainer: Mike Macgirvin
 */

/**
 *
 * Module: LDAP Authenticate
 *
 * Authenticate a user against an LDAP directory
 * Useful for Windows Active Directory and other LDAP-based organisations
 * to maintain a single password across the organisation.
 *
 * Optionally authenticates only if a member of a given group in the directory.
 *
 * Note when using with Windows Active Directory: you may need to set TLS_CACERT in your site
 * ldap.conf file to the signing cert for your LDAP server.
 *
 * The required configuration options for this module may be set in the .htconfig.php file
 * e.g.:
 *
 * App::$config['ldapauth']['ldap_server'] = 'host.example.com';
 *
 * Parameters:
 * ldap_server = DNS hostname of LDAP service, or URL e.g. ldaps://example.com
 * ldap_binddn = LDAP DN of an account to bind with to search LDAP
 * ldap_bindpw = password for LDAP bind DN
 * ldap_searchdn = base DN for the search root of the LDAP directory
 * ldap_userattr = the name of the attribute containing the username, e.g. SAMAccountName
 * ldap_nameattr = the name of the attribute containing the displayname, e.g. displayName - only required if creating a channel
 * ldap_group = (optional) DN of group to check membership
 * create_account = (optional) 1 or 0 (default), automatically create Hubzilla accounts based on the LDAP 'mail' attribute
 * create_channel = (optional) 1 or 0 (default), automatically create channel if create_account succeeds using nameattr and userattr
 *    and the system default_permissions_role or 'social'
 *
 */


function ldapauth_load() {
	register_hook('authenticate', 'addon/ldapauth/ldapauth.php', 'ldapauth_hook_authenticate');
}


function ldapauth_unload() {
	unregister_hook('authenticate', 'addon/ldapauth/ldapauth.php', 'ldapauth_hook_authenticate');
}


function ldapauth_hook_authenticate($a,&$b) {

	$mail = '';
	$username = '';
	$displayname = '';

	$perms_role = get_config('system','default_permissions_role','social');

	if(ldapauth_authenticate($b['username'],$b['password'],$mail,$nickname,$displayname)) {
		$results = q("SELECT * FROM account where account_email = '%s' OR account_email = '%s'  AND account_flags in (0,1) limit 1",
			dbesc($b['username']), dbesc($mail)
		);
		if((! $results) && ($mail) && intval(get_config('ldapauth','create_account')) == 1) {
			require_once('include/account.php');
			$now = datetime_convert();
			$salt = random_string(32);
			$pass = random_string(64);
			$password = $salt . ',' . hash('whirlpool', $salt . $pass);

			q("INSERT INTO register ("
				. "reg_didx, reg_did2, reg_hash, reg_created, reg_startup, reg_expires,"
				. "reg_email, reg_pass, reg_lang, reg_atip, reg_stuff)"
				. " VALUES ('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s') ",
				dbesc('e'),
				dbesc($mail),
				dbesc('ldapauth'),
				dbesc($now),
				dbesc($now),
				dbesc(datetime_convert('UTC', 'UTC', $now . ' + 1 hour')),
				dbesc($mail),
				dbesc($password),
				dbesc(\App::$language),
				dbesc($_SERVER['REMOTE_ADDR']),
				dbesc(json_encode([], JSON_FORCE_OBJECT))
			);

			$reg = q("SELECT reg_id FROM register WHERE reg_did2 = '%s' AND reg_pass = '%s'",
				dbesc($mail),
				dbesc($password)
			);

			$acct = create_account_from_register(['reg_id' => $reg[0]['reg_id']]);
			if($acct['success']) {
				q("UPDATE register SET reg_vital = 0 WHERE reg_id = %d",
					intval($reg[0]['reg_id'])
				);

				logger('ldapauth: Created account for ' . $b['username'] . ' using ' . $mail);
				info(t('An account has been created for you.'));
				$b['user_record'] = $acct['account'];
				$b['authenticated'] = 1;
			}

		} elseif(intval(get_config('ldapauth','create_account')) != 1 && (! $results)) {
                  logger('ldapauth: User '.$b['username'].' authenticated but no db-record and. Rejecting auth.');
		  notice( t('Authentication successful but rejected: account creation is disabled.'));
		  return;
		}
		if((! $results) && $b['user_record'] && $nickname && $displayname && intval(get_config('ldapauth','create_channel'))) {
			$c = create_identity( [
				'name' => $displayname,
				'nickname' => $nickname,
				'account_id' => $b['user_record']['account_id'],
				'permissions_role' => $perms_role
			] );

			if(! $c['success']) {
				logger('ldapauth: channel creation failed');
				return;
			}
		}
		if($results) {
			logger('ldapauth: Login success for ' . $b['username']);
			$b['user_record'] = $results[0];
			$b['authenticated'] = 1;
		}
	}
	return;
}


function ldapauth_authenticate($username,$password,&$mail,&$nickname,&$displayname) {
    logger('ldapauth: Searching user '.$username.'.');
    $ldap_server   = get_config('ldapauth','ldap_server');
    $ldap_binddn   = get_config('ldapauth','ldap_binddn');
    $ldap_bindpw   = get_config('ldapauth','ldap_bindpw');
    $ldap_searchdn = get_config('ldapauth','ldap_searchdn');
    $ldap_userattr = get_config('ldapauth','ldap_userattr','SAMAccountName');
    $ldap_nameattr = get_config('ldapauth','ldap_nameattr','displayName');
    $ldap_group    = get_config('ldapauth','ldap_group');

    if(empty($password)) {
        logger('ldapauth: Empty Password not allowed.');
        return false;
    }

    if(!function_exists('ldap_connect')
       || empty($ldap_server)) {
            logger('ldapauth: PHP-LDAP fail or no server set.');
            return false;
    }

    $connect = @ldap_connect($ldap_server);

    if(! $connect) {
	logger('ldapauth: Unable to connect to server');
        return false;
    }

    @ldap_set_option($connect, LDAP_OPT_PROTOCOL_VERSION,3);
    @ldap_set_option($connect, LDAP_OPT_REFERRALS, 0);
    if((@ldap_bind($connect,$ldap_binddn,$ldap_bindpw)) === false) {
	logger('ldapauth: Unable to bind to server. Check credentials of binddn.');
        return false;
    }

    $res = @ldap_search($connect,$ldap_searchdn, $ldap_userattr . '=' . $username, [ 'mail', $ldap_nameattr, $ldap_userattr ]);

    if(! $res) {
	logger('ldapauth: User '.$username.' not found.');
        return false;
    }

    $id = @ldap_first_entry($connect,$res);

    if(! $id) {
	logger('ldapauth: User '.$username.' found but unable to load data.');
        return false;
    }

	// get primary email

	$mail = '';

	$attrs = @ldap_get_attributes($connect,$id);
	if($attrs['count'] && $attrs['mail']) {
		if(is_array($attrs['mail']))
			$mail = $attrs['mail'][0];
		else
			$mail = $attrs['mail'];
	}
	if($attrs['count'] && $attrs[$ldap_nameattr]) {
		if(is_array($attrs[$ldap_nameattr]))
			$displayname = $attrs[$ldap_nameattr][0];
		else
			$displayname = $attrs[$ldap_nameattr];
	}

	if($attrs['count'] && $attrs[$ldap_userattr]) {
		if(is_array($attrs[$ldap_userattr]))
			$nickname = $attrs[$ldap_userattr][0];
		else
			$nickname = $attrs[$ldap_userattr];
	}

    $dn = @ldap_get_dn($connect,$id);

    if(! @ldap_bind($connect,$dn,$password)) {
	logger('ldapauth: User '.$username.' provided wrong credentials.');
        return false;
    }

    if(empty($ldap_group)) {
	logger('ldapauth: User '.$username.' authenticated.');
        return true;
    }

    $r = @ldap_compare($connect,$ldap_group,'member',$dn);
    if ($r === -1) {
        $err = @ldap_error($connect);
        $eno = @ldap_errno($connect);
        @ldap_close($connect);

        if ($eno === 32) {
            logger('ldapauth: access control group Does Not Exist');
            return false;
        }
        elseif ($eno === 16) {
            logger('ldapauth: membership attribute does not exist in access control group');
            return false;
        }
        else {
            logger('ldapauth: error: ' . $err);
            return false;
        }
    }
    elseif ($r === false) {
	logger('ldapauth: User '.$username.' is not in the allowed group.');
        @ldap_close($connect);
        return false;
    }
//    logger('ldapauth: User '.$username.' authenticated and in allowed group.');
  return true;
}
