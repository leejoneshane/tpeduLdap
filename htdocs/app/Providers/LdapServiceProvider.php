<?php

namespace App\Providers;

use Log;
use Illuminate\Support\ServiceProvider;

class LdapServiceProvider extends ServiceProvider
{
    private static $ldap_read = null;
    private static $ldap_write = null;

    public function __construct()
    {
        if (is_null(self::$ldap_read) || is_null(self::$ldap_write))
            $this->connect();
    }

    public function error()
    {
        if (is_null(self::$ldap_read) || is_null(self::$ldap_write)) return;
        return ldap_error(self::$ldap_read).ldap_error(self::$ldap_write);
    }

    public function connect()
    {
		$rhost = config('ldap.rhost');
		if (empty($rhost)) $rhost = config('ldap.host');
		$whost = config('ldap.whost');
		if (empty($whost)) $whost = config('ldap.host');
        if ($ldapconn = @ldap_connect($rhost)) {
            @ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, intval(config('ldap.version')));
            @ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);
            self::$ldap_read = $ldapconn;
        } else
            Log::error("Connecting LDAP server failed.\n");
		
		if ($ldapconn = @ldap_connect($whost)) {
			@ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, intval(config('ldap.version')));
			@ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);
			self::$ldap_write = $ldapconn;
		} else
			Log::error("Connecting LDAP server failed.\n");
	}

    public function administrator() 
    {
		@ldap_bind(self::$ldap_read, config('ldap.rootdn'), config('ldap.rootpwd'));
		@ldap_bind(self::$ldap_write, config('ldap.rootdn'), config('ldap.rootpwd'));
    }
    
    public function authenticate($username, $password)
    {
        if (empty($username) || empty($password)) return false;
    	$account = "uid=$username";
    	$base_dn = config('ldap.authdn');
    	$auth_dn = "$account,$base_dn";
    	return @ldap_bind(self::$ldap_read, $auth_dn, $password);
    }

    public function userLogin($username, $password)
    {
        if (empty($username) || empty($password)) return false;
    	$base_dn = config('ldap.userdn');
    	$user_dn = "$username,$base_dn";
    	return @ldap_bind(self::$ldap_read, $user_dn, $password);
    }

    public function schoolLogin($username, $password)
    {
        if (empty($username) || empty($password)) return false;
    	$base_dn = config('ldap.rdn');
    	$sch_dn = "$username,$base_dn";
    	return @ldap_bind(self::$ldap_read, $sch_dn, $password);
    }

    public function checkIdno($idno)
    {
		if (strlen($idno) == 13) $idno = substr($idno,3);
		if (strlen($idno) != 10) return false;
		$this->administrator();
		$resource = @ldap_list(self::$ldap_read, config('ldap.userdn'), "cn=$idno");
		if ($resource) {
	    	$entry = ldap_first_entry(self::$ldap_read, $resource);
			if (!$entry) return false;
			return $idno;
		}
        return false;
    }

    public function checkSchool($dc)
    {
		if (empty($dc)) return false;
		$this->administrator();
		$resource = @ldap_list(self::$ldap_read, config('ldap.rdn'), "dc=$dc");
		if ($resource) {
	    	$entry = ldap_first_entry(self::$ldap_read, $resource);
			if (!$entry) return false;
			return true;
		}
		return false;
    }

    public function checkSchoolAdmin($dc)
    {
		if (empty($dc)) return false;
		$this->administrator();
		$resource = @ldap_list(self::$ldap_read, config('ldap.rdn'), $dc, array('tpAdministrator'));
		if ($resource) {
	    	$entry = ldap_first_entry(self::$ldap_read, $resource);
			if (!$entry) return false;
			return true;
		}
		return false;
    }

    public function checkAccount($username)
    {
        if (empty($username)) return false;
        $filter = "(uid=$username)";
		$this->administrator();
		$resource = @ldap_list(self::$ldap_read, config('ldap.authdn'), $filter, array('cn'));
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			if (!$entry) return false;
			$id = ldap_get_values(self::$ldap_read, $entry, 'cn');
			return $id[0];
		}
        return false;
    }

    public function checkEmail($email)
    {
    	if (empty($email)) return false;
    	$filter = "(mail=$email)";
		$this->administrator();
		$resource = @ldap_list(self::$ldap_read, config('ldap.userdn'), $filter, array('cn'));
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			if (!$entry) return false;
			$id = ldap_get_values(self::$ldap_read, $entry, 'cn');
			return $id[0];
		} 
        return false;
    }

    public function checkMobil($mobile)
    {
    	if (empty($mobile)) return false;
    	$filter = "(mobile=$mobile)";
		$this->administrator();
		$resource = ldap_list(self::$ldap_read, config('ldap.userdn'), $filter, array('cn'));
		if ($resource) {
			$entry = @ldap_first_entry(self::$ldap_read, $resource);
			if (!$entry) return false;
			$id = ldap_get_values(self::$ldap_read, $entry, 'cn');
			return $id[0];
		} 
        return false;
    }

    public function checkStatus($idno)
    {
    	if (empty($idno)) return false;
		$this->administrator();
		$entry = $this->getUserEntry($idno);
		$data = $this->getUserData($entry, ['inetUserStatus', 'o']);
		if ($data) {
			if (empty($data['o']) || $data['inetUserStatus'] == 'inactive') return 'inactive';
			if ($data['inetUserStatus'] == 'deleted') return 'deleted';
			return 'active';
		}
		return false;
    }

    public function accountAvailable($account)
    {
		if (empty($account)) return false;
		$filter = "uid=$account";
		$this->administrator();
		$resource = @ldap_list(self::$ldap_read, config('ldap.authdn'), $filter, array('uid'));
		if ($resource && ldap_first_entry(self::$ldap_read, $resource)) {
			return false;
		} else {
			return true;
		}
    }

    public function emailAvailable($idno, $mailaddr)
    {
		if (empty($idno) || empty($mailaddr)) return;
		$filter = "(&(mail=$mailaddr)(!(cn=$idno)))";
		$this->administrator();
		$resource = @ldap_list(self::$ldap_read, config('ldap.userdn'), $filter, array('mail'));
		if ($resource && ldap_first_entry(self::$ldap_read, $resource))
			return false;
		else
			return true;
    }

    public function mobileAvailable($idno, $mobile)
    {
		if (empty($idno) || empty($mobile)) return;
		$filter = "(&(mobile=$mobile)(!(cn=$idno)))";
		$this->administrator();
		$resource = @ldap_list(self::$ldap_read, config('ldap.userdn'), $filter, array('mobile'));
		if ($resource && ldap_first_entry(self::$ldap_read, $resource))
			return false;
		else
			return true;
    }

    public function getOrgs($filter = '')
    {
		$schools = array();
		$this->administrator();
		$base_dn = config('ldap.rdn');
		if (empty($filter)) $filter = "objectClass=tpeduSchool";
		$resource = @ldap_search(self::$ldap_read, $base_dn, $filter, ['o', 'st', 'tpUniformNumbers', 'description']);
		$entry = @ldap_first_entry(self::$ldap_read, $resource);
		if ($entry) {
			do {
				$school = new \stdClass();
				foreach (['o', 'st', 'tpUniformNumbers', 'description'] as $field) {
					$value = @ldap_get_values(self::$ldap_read, $entry, $field);
					if ($value) $school->$field = $value[0];
				}
	    		$schools[] = $school;
			} while ($entry=ldap_next_entry(self::$ldap_read, $entry));
		}
		return $schools;
    }

    public function getOrgEntry($identifier)
    {
		$this->administrator();
		$base_dn = config('ldap.rdn');
		$sch_rdn = "dc=$identifier";
		$resource = @ldap_search(self::$ldap_read, $base_dn, $sch_rdn, array("*","entryUUID","modifyTimestamp"));
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			return $entry;
		}
		return false;
    }
    
    public function getOrgData($entry, $attr = '')
    {
		$fields = array();
		if (is_array($attr)) {
			$fields = $attr;
		} elseif ($attr == '') {
			$fields[] = 'entryUUID';
			$fields[] = 'modifyTimestamp';
			$fields[] = 'o';
			$fields[] = 'businessCategory';
			$fields[] = 'st';
			$fields[] = 'description';
			$fields[] = 'facsimileTelephoneNumber';
			$fields[] = 'telephoneNumber';
			$fields[] = 'postalCode';
			$fields[] = 'street';
			$fields[] = 'postOfficeBox';
			$fields[] = 'wWWHomePage';
			$fields[] = 'tpUniformNumbers';
			$fields[] = 'tpSims';
			$fields[] = 'tpIpv4';
			$fields[] = 'tpIpv6';
			$fields[] = 'tpAdministrator';
		} else {
			$fields[] = $attr;
		}
	
		$info = array();
    	foreach ($fields as $field) {
			$value = @ldap_get_values(self::$ldap_read, $entry, $field);
			if ($value) {
				if ($value['count'] == 1) {
					$info[$field] = $value[0];
				} else {
					unset($value['count']);
					$info[$field] = $value;
				}
			}
		}
		return $info;
    }
    
    public function getOrgTitle($dc)
    {
		if (empty($dc)) return '';
		$this->administrator();
		$base_dn = config('ldap.rdn');
		$sch_rdn = "dc=$dc";
		$sch_dn = "$sch_rdn,$base_dn";
		$resource = @ldap_search(self::$ldap_read, $sch_dn, "objectClass=tpeduSchool", array("description"));
		if ($resource) {
			$entry = @ldap_first_entry(self::$ldap_read, $resource);
			if ($entry) {
				$value = @ldap_get_values(self::$ldap_read, $entry, "description");
				if ($value) return $value[0];
			}
		}
		return '';
    }
    
    public function getOrgID($dc)
    {
		if (empty($dc)) return '';
		$this->administrator();
		$base_dn = config('ldap.rdn');
		$sch_rdn = "dc=$dc";
		$sch_dn = "$sch_rdn,$base_dn";
		$resource = @ldap_search(self::$ldap_read, $sch_dn, "objectClass=tpeduSchool", [ 'tpUniformNumbers' ]);
		if ($resource) {
			$entry = @ldap_first_entry(self::$ldap_read, $resource);
			if ($entry) {
				$value = @ldap_get_values(self::$ldap_read, $entry, "tpUniformNumbers");
				if ($value) return $value[0];
			}
		}
		return '';
	}
    
    public function renameOrg($old_dc, $new_dc)
    {
		$this->administrator();
		$dn = "dc=$old_dc,".config('ldap.rdn');
		$rdn = "dc=$new_dc";
		$result = @ldap_rename(self::$ldap_write, $dn, $rdn, null, true);
		if ($result) {
			$users = $this->findUsers("o=$old_dc");
			if ($users) {
				foreach ($users as $user) {
					$this->UpdateData($user, [ 'o' => $new_dc ]); 
				}
			}
		}
		return $result;
	}

    public function getOus($dc, $category = '')
    {
		$ous = array();
		$this->administrator();
		$base_dn = config('ldap.rdn');
		$sch_rdn = "dc=$dc";
		$sch_dn = "$sch_rdn,$base_dn";
		$filter = "objectClass=organizationalUnit";
		$resource = @ldap_search(self::$ldap_read, $sch_dn, $filter, ["businessCategory", "ou", "description"]);
		$entry = @ldap_first_entry(self::$ldap_read, $resource);
		if ($entry) {
			do {
				$ou = new \stdClass();
				$info = $this->getOuData($entry);
				if (!empty($category) && $info['businessCategory'] != $category) continue;
				$ou->ou = $info['ou'];
				$ou->description = $info['description'];
				if ($info['businessCategory'] == '教學班級') {
					$ou->grade = $info['grade'];
					$ou->tutor = $info['tpTutor'];
				}
				$ous[] = $ou;
			} while ($entry=ldap_next_entry(self::$ldap_read, $entry));
			return $ous;
		}
		return false;
	}
    
    public function getOuEntry($dc, $ou)
    {
		$this->administrator();
		$sch_dn = "dc=$dc,".config('ldap.rdn');
		$filter = "ou=$ou";
		$resource = @ldap_search(self::$ldap_read, $sch_dn, $filter);
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			return $entry;
		}
		return false;
}
    
    public function getOuData($entry, $attr='')
    {
		$fields = array();
		if (is_array($attr)) {
			$fields = $attr;
		} elseif ($attr == '') {
			$fields[] = 'ou';
			$fields[] = 'businessCategory';
			$fields[] = 'description';
		} else {
			$fields[] = $attr;
		}

		$info = array();
		foreach ($fields as $field) {
			$value = @ldap_get_values(self::$ldap_read, $entry, $field);
			if ($value) {
				if ($value['count'] == 1) {
					$info[$field] = $value[0];
				} else {
					unset($value['count']);
					$info[$field] = $value;
				}
			}
		}
		if (isset($info['businessCategory']) && $info['businessCategory'] == '教學班級') {
			$info['grade'] = substr($info['ou'], 0, 1);
			$dn = ldap_get_dn(self::$ldap_read,$entry);
			$augs = explode(',', $dn);
			$o = explode('=', $augs[1]);
			$filter = '(&(o='.$o[1].')(tpTutorClass='.$info['ou'].'))';
			$tutors = $this->findUsers($filter,'entryUUID');
			$teachers = array();
			if (!empty($tutors))
				foreach ($tutors as $t) {
					$teachers[] = $t['entryUUID'];
				}
			$info['tpTutor'] = $teachers;
		}
		return $info;
	}
    
    public function getOuTitle($dc, $ou)
    {
		if (empty($dc)) return '';
		if (is_array($dc)) $dc = array_pop($dc);
		$this->administrator();
		$sch_dn = "dc=$dc,".config('ldap.rdn');
		$filter = "ou=$ou";
		$resource = @ldap_search(self::$ldap_read, $sch_dn, $filter, array("description"));
		if ($resource) {
			$entry = @ldap_first_entry(self::$ldap_read, $resource);
			if ($entry) {
				$value = @ldap_get_values(self::$ldap_read, $entry, "description");
				if ($value) return $value[0];
			}
		}
		return '';
	}
    
    public function updateOus($dc, array $ous)
    {
		if (empty($dc) || empty($ous)) return false;
		$this->administrator();
		foreach ($ous as $ou) {
			if (!isset($ou->id) || !isset($ou->name) || !isset($ou->roles)) return false;
			$entry = $this->getOuEntry($dc, $ou->id);
			if ($entry) {
				$this->updateData($entry, array( "description" => $ou->name));
				foreach ($ou->roles as $role) {
					if (empty($role->id) || empty($role->name)) return false;
					$role_entry = $this->getRoleEntry($dc, $ou->id, $role->id);
					if ($role_entry) {
						$this->updateData($role_entry, array( "description" => $role->name));
					} else {
						$dn = "cn=$role->id,ou=$ou->id,dc=$dc,".config('ldap.rdn');
						$this->createEntry(array( "dn" => $dn, "ou" => $ou->id, "cn" => $role->id, "description" => $role->name));
					}
				}
			} else {
				$dn = "ou=$ou->id,dc=$dc,".config('ldap.rdn');
				$this->createEntry(array( "dn" => $dn, "ou" => $ou->id, "businessCategory" => "行政部門", "description" => $ou->name));
				foreach ($ou->roles as $role) {
					if (empty($role->id) || empty($role->name)) return false;
					$dn = "cn=$role->id,ou=$ou->id,dc=$dc,".config('ldap.rdn');
					$this->createEntry(array( "dn" => $dn, "ou" => $ou->id, "cn" => $role->id, "description" => $role->name));
				}
			}
		}
		return true;
	}

	public function updateClasses($dc, array $classes)
    {
		if (empty($dc) || empty($classes)) return false;
		$this->administrator();
		foreach ($classes as $class) {
			if (empty($class->id) || empty($title->name)) return false;
			$entry = $this->getOuEntry($dc, $class->id);
			if ($entry) {
				$this->updateData($entry, array( "description" => $class->name));
			} else {
				$dn = "ou=$class->id,dc=$dc,".config('ldap.rdn');
				$this->createEntry(array( "dn" => $dn, "ou" => $class->id, "businessCategory" => "教學班級", "description" => $class->name));
			}
		}
		return true;
	}

    public function getSubjects($dc)
    {
		$subjs = array();
		$this->administrator();
		$base_dn = config('ldap.rdn');
		$sch_rdn = "dc=$dc";
		$sch_dn = "$sch_rdn,$base_dn";
		$filter = "objectClass=tpeduSubject";
		$resource = @ldap_search(self::$ldap_read, $sch_dn, $filter, ["tpSubject", "tpSubjectDomain", "description"]);
		$entry = @ldap_first_entry(self::$ldap_read, $resource);
		if ($entry)
			do {
				$subjs[] = $this->getSubjectData($entry);
			} while ($entry=ldap_next_entry(self::$ldap_read, $entry));
		return $subjs;
	}
    
    public function getSubjectEntry($dc, $subj)
    {
		$this->administrator();
		$base_dn = config('ldap.rdn');
		$sch_rdn = "dc=$dc";
		$sch_dn = "$sch_rdn,$base_dn";
		$filter = "tpSubject=$subj";
		$resource = @ldap_search(self::$ldap_read, $sch_dn, $filter);
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			return $entry;
		}
		return false;
	}
    
    public function getSubjectData($entry, $attr='')
    {
		$fields = array();
		if (is_array($attr)) {
			$fields = $attr;
		} elseif ($attr == '') {
			$fields[] = 'tpSubject';
			$fields[] = 'tpSubjectDomain';
			$fields[] = 'description';
		} else {
			$fields[] = $attr;
		}

		$info = array();
		foreach ($fields as $field) {
			$value = @ldap_get_values(self::$ldap_read, $entry, $field);
			if ($value) {
				if ($value['count'] == 1) {
					$info[$field] = $value[0];
				} else {
					unset($value['count']);
					$info[$field] = $value;
				}
			}
		}
		return $info;
	}

	public function getSubjectTitle($dc, $subj)
    {
		if (empty($dc) || empty($subj)) return '';
		$this->administrator();
		$sch_dn = "dc=$dc,".config('ldap.rdn');
		$filter = "tpSubject=$subj";
		$resource = @ldap_search(self::$ldap_read, $sch_dn, $filter, array("description"));
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			if ($entry) {
				$value = @ldap_get_values(self::$ldap_read, $entry, "description");
				if ($value) return $value[0];
			}
		}
		return '';
	}
    
    public function updateSubjects($dc, array $subjects)
    {
		if (empty($dc) || empty($subjects)) return false;
		$this->administrator();
		foreach ($subjects as $subj) {
			if (!isset($subj->id) || !isset($subj->domain) || !isset($subj->title)) return false;
			$entry = $this->getSubjectEntry($dc, $subj->id);
			if ($entry) {
				$this->updateData($entry, array( "tpSubjectDomain" => $subj->domain, "description" => $subj->title));
			} else {
				$dn = "tpSubject=$subj->id,dc=$dc,".config('ldap.rdn');
				$this->createEntry(array( "dn" => $dn, "tpSubject" => $subj->id, "tpSubjectDomain" => $subj->domain, "description" => $subj->title));
			}
		}
		return true;
	}

    public function allRoles($dc)
    {
		$roles = array();
		$this->administrator();
		$ous = $this->getOus($dc, '行政部門');
		if (!empty($ous))
			foreach ($ous as $ou) {
				$ou_id = $ou->ou;
				$ou_name = $ou->description;
				$info = $this->getRoles($dc, $ou_id);
				foreach ($info as $role_obj) {
					$role = new \stdClass();
					$role->cn = "$ou_id,".$role_obj->cn;
					$role->description = $ou_name.$role_obj->description;
					$roles[] = $role;
				}
			}
		return $roles;
	}
    
    public function getRoles($dc, $ou)
    {
		$roles = array();
		$this->administrator();
		$base_dn = config('ldap.rdn');
		$sch_rdn = "dc=$dc";
		$sch_dn = "$sch_rdn,$base_dn";
		$ou_dn = "ou=$ou,$sch_dn";
		$filter = "objectClass=organizationalRole";
		$resource = @ldap_search(self::$ldap_read, $ou_dn, $filter, ["cn", "description"]);
		if ($resource) {
			$entry = @ldap_first_entry(self::$ldap_read, $resource);
			if ($entry)
				do {
					$role = new \stdClass();
					$info = $this->getRoleData($entry);
					$role->cn = $info['cn'];
					$role->description = $info['description'];
					$roles[] = $role;
				} while ($entry=ldap_next_entry(self::$ldap_read, $entry));
		}
		return $roles;
	}
    
    public function getRoleEntry($dc, $ou, $role_id)
    {
		$this->administrator();
		$ou_dn = "ou=$ou,dc=$dc,".config('ldap.rdn');
		$filter = "cn=$role_id";
		$resource = @ldap_search(self::$ldap_read, $ou_dn, $filter);
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			return $entry;
		}
		return false;
	}
    
    public function getRoleData($entry, $attr='')
    {
		$fields = array();
		if (is_array($attr)) {
			$fields = $attr;
		} elseif ($attr == '') {
			$fields[] = 'ou';
			$fields[] = 'cn';
			$fields[] = 'description';
		} else {
			$fields[] = $attr;
		}

		$info = array();
		foreach ($fields as $field) {
			$value = @ldap_get_values(self::$ldap_read, $entry, $field);
			if ($value) {
				if ($value['count'] == 1) {
					$info[$field] = $value[0];
				} else {
					unset($value['count']);
					$info[$field] = $value;
				}
			}
		}
		return $info;
	}
    
    public function getRoleTitle($dc, $ou, $role)
    {
		if (empty($dc)) return '';
		$this->administrator();
		$ou_dn = "ou=$ou,dc=$dc,".config('ldap.rdn');
		$filter = "cn=$role";
		$resource = @ldap_search(self::$ldap_read, $ou_dn, $filter, array("description"));
		if ($resource) {
			$entry = @ldap_first_entry(self::$ldap_read, $resource);
			if ($entry) {
				$value = @ldap_get_values(self::$ldap_read, $entry, "description");
				if ($value) return $value[0];
			}
		}
		return '';
	}
    
    public function findUsers($filter, $attr = '')
    {
		$userinfo = array();
		$this->administrator();
		$base_dn = config('ldap.userdn');
		$resource = @ldap_list(self::$ldap_read, $base_dn, $filter, array("*","entryUUID","modifyTimestamp"));
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			if ($entry) {
				do {
					$data = $this->getUserData($entry, $attr);
					if (!empty($data)) $userinfo[] = $data;
				} while ($entry=ldap_next_entry(self::$ldap_read, $entry));
			}
			return $userinfo;
		}
		return false;
	}

    public function findUserEntries($filter)
    {
		$userentry = array();
		$this->administrator();
		$base_dn = config('ldap.userdn');
		$resource = @ldap_list(self::$ldap_read, $base_dn, $filter, array("*","entryUUID","modifyTimestamp"));
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			if ($entry) {
				do {
					$userentry[] = $entry;
				} while ($entry=ldap_next_entry(self::$ldap_read, $entry));
			}
			return $userentry;
		}
		return false;
	}

    public function getUserEntry($identifier)
    {
		$this->administrator();
		$base_dn = config('ldap.userdn');
		if (strlen($identifier) == 10) { //idno
			$filter = "cn=$identifier";
		} else { //uuid
			$filter = "entryUUID=$identifier";
		}
		$resource = @ldap_list(self::$ldap_read, $base_dn, $filter, array("*","entryUUID","modifyTimestamp"));
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			return $entry;
		}
		return false;
	}
    
    public function getUserData($entry, $attr = '')
    {
		$fields = array();
		if ($attr == '') {
			$fields[] = 'entryUUID';
			$fields[] = 'modifyTimestamp';
			$fields[] = 'cn';
			$fields[] = 'o';
			$fields[] = 'ou';
			$fields[] = 'uid';
			$fields[] = 'info';
			$fields[] = 'title';
			$fields[] = 'gender';
			$fields[] = 'birthDate';
			$fields[] = 'sn';
			$fields[] = 'givenName';
			$fields[] = 'displayName';
			$fields[] = 'mail';
			$fields[] = 'mobile';
			$fields[] = 'facsimileTelephoneNumber';
			$fields[] = 'telephoneNumber';
			$fields[] = 'homePhone';
			$fields[] = 'registeredAddress';
			$fields[] = 'homePostalAddress';
			$fields[] = 'wWWHomePage';
			$fields[] = 'employeeType';
			$fields[] = 'employeeNumber';
			$fields[] = 'tpClass';
			$fields[] = 'tpClassTitle';
			$fields[] = 'tpSeat';
			$fields[] = 'tpTeachClass';
			$fields[] = 'tpTutorClass';
			$fields[] = 'tpCharacter';
			$fields[] = 'tpAdminSchools';
			$fields[] = 'inetUserStatus';
		} elseif (is_array($attr)) {
			$fields = $attr;
		} else {
			$fields[] = $attr;
		}
		if (in_array('uid', $fields))
			$fields = array_values(array_unique($fields + ['mail', 'mobile']));
		if (in_array('ou',$fields) || in_array('tpClass',$fields) || in_array('tpTeachClass',$fields))
			$fields = array_values(array_unique($fields + ['o']));
		if (in_array('tpAdminSchools',$fields)) 
			$fields = array_values(array_unique($fields + ['o', 'cn']));
		if (in_array('title',$fields))
			$fields = array_values(array_unique($fields + ['o', 'ou']));
		$userinfo = array();
		foreach ($fields as $field) {
			$value = @ldap_get_values(self::$ldap_read, $entry, $field);
			if ($value) {
				if ($value['count'] == 1) {
					$userinfo[$field] = $value[0];
				} else {
					unset($value['count']);
					$userinfo[$field] = $value;
				}
			}
		}
		if (!empty($userinfo['tpClass'])) {
			$classname = $this->getOuTitle($userinfo['o'], $userinfo['tpClass']);
			if (!empty($classname) && (!isset($userinfo['tpClassTitle']) || $userinfo['tpClassTitle'] != $classname))
				$this->updateData($entry, [ "tpClassTitle" => $classname ]);
			$userinfo['tpClassTitle'] = $classname;
		}
		if (in_array('inetUserStatus', $fields) && empty($userinfo['inetUserStatus'])) {
			$userinfo['inetUserStatus'] = 'active';
			$this->updateData($entry, [ "inetUserStatus" => "active" ]);
		}
		if (isset($userinfo['inetUserStatus']) && $userinfo['inetUserStatus'] == 'Active') {
			$userinfo['inetUserStatus'] = 'active';
			$this->updateData($entry, [ "inetUserStatus" => "active" ]);
		}
		$userinfo['email_login'] = false;
		$userinfo['mobile_login'] = false;
		if (isset($userinfo['uid']) && is_array($userinfo['uid'])) {
			if (isset($userinfo['mail'])) {
				if (is_array($userinfo['mail'])) {
					foreach ($userinfo['mail'] as $mail) {
						if (in_array($mail, $userinfo['uid'])) $userinfo['email_login'] = true;
					}
				} else {
					if (in_array($userinfo['mail'], $userinfo['uid'])) $userinfo['email_login'] = true;
				}
			}
			if (isset($userinfo['mobile'])) {
				if (is_array($userinfo['mobile'])) {
					foreach ($userinfo['mobile'] as $mobile) {
						if (in_array($mobile, $userinfo['uid'])) $userinfo['mobile_login'] = true;
					}
				} else {
					if (in_array($userinfo['mobile'], $userinfo['uid'])) $userinfo['mobile_login'] = true;
				}
			}
		}
		$userinfo['adminSchools'] = false;
		$as = array();
		if (isset($userinfo['tpAdminSchools'])) {
			if (is_array($userinfo['tpAdminSchools'])) {
				$as = $userinfo['tpAdminSchools'];
			} else {
				$as[] = $userinfo['tpAdminSchools'];
			}
		}
		if (isset($userinfo['o']) && !is_array($userinfo['o']) && !in_array($userinfo['o'], $as))
			$as[] = $userinfo['o'];
		if (isset($userinfo['cn'])) {
			foreach ($as as $o) {
				$sch_entry = $this->getOrgEntry($o);
				$admins = $this->getOrgData($sch_entry, "tpAdministrator");
				if (isset($admins['tpAdministrator'])) {
					if (is_array($admins['tpAdministrator'])) {
						if (in_array($userinfo['cn'], $admins['tpAdministrator'])) $userinfo['adminSchools'][] = $o;
					} else {
						if ($userinfo['cn'] == $admins['tpAdministrator']) $userinfo['adminSchools'][] = $o;
					}
				}
			}
		}
		$orgs = array();
		if (isset($userinfo['o'])) {
			if (is_array($userinfo['o'])) {
				$orgs = $userinfo['o'];
			} else {
				$orgs[] = $userinfo['o'];
			}
			foreach ($orgs as $o) {
				$userinfo['school'][$o] = $this->getOrgTitle($o);
			}
		}
		if (!empty($orgs) && !empty($userinfo['ou'])) {
			$units = array();
			$ous = array();
			if (is_array($userinfo['ou'])) {
				$units = $userinfo['ou'];
			} else {
				$units[] = $userinfo['ou'];
			}
			foreach ($units as $ou_pair) {
				$a = explode(',' , $ou_pair);
				if (count($a) == 2) {
					$o = $a[0];
					$ou = $a[1];
					$ous[] = $ou_pair;
					$obj = new \stdClass();
					$obj->key = $ou_pair;
					$obj->name = $this->getOuTitle($o, $ou);
					$userinfo['department'][$o][] = $obj;					
				}
			}
			if (count($ous) == 1) {
				$userinfo['ou'] = $ous[0];
			} else {
				$userinfo['ou'] = $ous;
			}
			if (isset($userinfo['title'])) {
				$roles = array();
				$titles = array();
				if (is_array($userinfo['title'])) {
					$roles = $userinfo['title'];
				} else {
					$roles[] = $userinfo['title'];
				}
				foreach ($roles as $role_pair) {
					$a = explode(',' , $role_pair);
					if (count($a) == 3) {
						$o = $a[0];
						$ou = $a[1];
						$role = $a[2];
						$titles[] = $role_pair;
						$obj = new \stdClass();
						$obj->key = $role_pair;
						$obj->name = $this->getRoleTitle($o, $ou, $role);
						$userinfo['titleName'][$o][] = $obj;
					}
				}
				if (count($titles) == 1) {
					$userinfo['title'] = $titles[0];
				} else {
					$userinfo['title'] = $titles;
				}
			}
		}
		if (!empty($orgs) && !empty($userinfo['tpTeachClass'])) {
			if (is_array($userinfo['tpTeachClass'])) {
				$classes = $userinfo['tpTeachClass'];
			} else {
				$classes[] = $userinfo['tpTeachClass'];
			}
			$tclass = array();
			foreach ($classes as $class_pair) {
				$a = explode(',' , $class_pair);
				if (count($a) == 3) {
					$o = $a[0];
					$class = $a[1];
					$subject = '';
					if (isset($a[2])) $subject = $a[2];
					$tclass[] = "$o,$class,$subject";
					$obj = new \stdClass();
					$obj->key = $class_pair;
					$obj->name = $this->getOuTitle($o, $class).$this->getSubjectTitle($o, $subject);
					$userinfo['teachClass'][$o][] = $obj; 
				}
			}
			$userinfo['tpTeachClass'] = $tclass;
		}
		return $userinfo;
	}

	public function getUserIDNO($identifier)
  	{
		if (strlen($identifier) == 10) return $identifier;
		$entry = $this->getUserEntry($identifier);
		if ($entry) {
			$dn = ldap_get_dn(self::$ldap_read, $entry);
			$augs = explode(',', $dn);
			$cn = explode('=', $augs[0]);
			return $cn[1];
		}
		return false;
	}

	public function getUserUUID($identifier)
  	{
		if (strlen($identifier) > 10) return $identifier;
		$entry = $this->getUserEntry($identifier);
		if ($entry) {
			$value = @ldap_get_values(self::$ldap_read, $entry, 'entryUUID');
			return $value['count'] == 1 ? $value[0] : false;
		}
		return false;
	}

	public function getUserName($identifier)
  	{
		$entry = $this->getUserEntry($identifier);
		$value = @ldap_get_values(self::$ldap_read, $entry, 'displayName');
		return $value['count'] == 1 ? $value[0] : $identifier;
	}

	public function getUserAccounts($identifier)
  	{
		$this->administrator();
		$entry = $this->getUserEntry($identifier);
		$data = $this->getUserData($entry, ['uid', 'mail', 'mobile']);
		$accounts = array();
		if (!isset($data['uid'])) return $accounts;
		if (is_array($data['uid'])) {
			$accounts = $data['uid'];
		} else {
			$accounts[] = $data['uid'];
		}
		for ($i=0;$i<count($accounts);$i++) {
			if (is_numeric($accounts[$i])) {
				unset($accounts[$i]);
				continue;
			}
			if (strpos($accounts[$i], '@')) {
				unset($accounts[$i]);
				continue;
			}
		}
		return array_values($accounts);
	}

    public function renameUser($old_idno, $new_idno)
    {
		$this->administrator();
		$dn = "cn=$old_idno,".config('ldap.userdn');
		$rdn = "cn=$new_idno";
		$entry = $this->getUserEntry($old_idno);
		$new_pwd = $this->make_ssha_password(substr($new_idno, -6));
		$this->updateData($entry, ["userPassword" => $new_pwd]);
		$accounts = @ldap_get_values(self::$ldap_read, $entry, "uid");
		for($i=0;$i<$accounts['count'];$i++) {
			$account_entry = $this->getAccountEntry($accounts[$i]);
			$this->updateData($account_entry, array( "cn" => $new_idno ));
		}
		$result = @ldap_rename(self::$ldap_write, $dn, $rdn, null, true);
		return $result;
	}

    public function addData($entry, array $fields)
    {
		$this->administrator();
		$fields = array_filter($fields);
		$dn = @ldap_get_dn(self::$ldap_read, $entry);
		foreach ($fields as $field => $value) {
			$values = @ldap_get_values(self::$ldap_read, $entry, $field);
			if ($values && in_array($value, $values)) unset($fields[$field]);
		}
		if (!empty($fields)) {
			$value = @ldap_mod_add(self::$ldap_write, $dn, $fields);
			if (!$value && config('ldap.debug')) Log::debug("Data can't add into $dn:\n".print_r($fields, true)."\n".$this->error()."\n");
			return $value;
		}
		return false;
	}

    public function updateData($entry, array $fields)
    {
		$this->administrator();
		$dn = @ldap_get_dn(self::$ldap_read, $entry);
		$value = @ldap_mod_replace(self::$ldap_write, $dn, $fields);
		if (!$value && config('ldap.debug')) Log::debug("Data can't update to $dn:\n".print_r($fields, true)."\n".$this->error()."\n");
		return $value;
	}

    public function deleteData($entry, array $fields)
    {
		$this->administrator();
		$dn = @ldap_get_dn(self::$ldap_read, $entry);
		$value = @ldap_mod_del(self::$ldap_write, $dn, $fields);
		if (!$value && config('ldap.debug')) Log::debug("Data can't remove from $dn:\n".print_r($fields, true)."\n".$this->error()."\n");
		return $value;
		return false;
	}

    public function createEntry(array $info)
    {
		$this->administrator();
		$dn = $info['dn'];
		unset($info['dn']);
		$info = array_filter($info);
		$value = @ldap_add(self::$ldap_write, $dn, $info);
		if (!$value && config('ldap.debug')) Log::debug("Entry can't create for $dn:\n".print_r($info, true)."\n".$this->error()."\n");
		return $value;
	}

    public function deleteEntry($entry)
    {
		$this->administrator();
		$dn = @ldap_get_dn(self::$ldap_read, $entry);
		$value = @ldap_delete(self::$ldap_write, $dn);
		if (!$value && config('ldap.debug')) Log::debug("Entry can't delete for $dn:\n".$this->error());
		return $value;
	}

    public function findAccounts($filter, $attr = '')
    {
		$accountinfo = array();
		$this->administrator();
		$base_dn = config('ldap.authdn');
		$resource = @ldap_list(self::$ldap_read, $base_dn, $filter);
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			if ($entry) {
				do {
					$accountinfo[] = $this->getAccountData($entry, $attr);
				} while ($entry=ldap_next_entry(self::$ldap_read, $entry));
			}
			return $accountinfo;
		}
		return false;
	}

    public function getAccountEntry($identifier)
    {
		$this->administrator();
		$base_dn = config('ldap.authdn');
		$auth_rdn = "uid=$identifier";
		$resource = @ldap_list(self::$ldap_read, $base_dn, $auth_rdn);
		if ($resource) {
			$entry = @ldap_first_entry(self::$ldap_read, $resource);
			return $entry;
		}
		return false;
	}

    public function getAccountData($entry, $attr = '')
    {
		$fields = array();
		if ($attr == '') {
			$fields[] = 'cn';
			$fields[] = 'uid';
			$fields[] = 'userPassword';
			$fields[] = 'description';
		} elseif (is_array($attr)) {
			$fields = $attr;
		} else {
			$fields[] = $attr;
		}

		$info = array();
		foreach ($fields as $field) {
			$value = @ldap_get_values(self::$ldap_read, $entry, $field);
			if ($value) {
				if ($value['count'] == 1) {
					$info[$field] = $value[0];
				} else {
					unset($value['count']);
					$info[$field] = $value;
				}
			}
		}
		return $info;
	}

    public function updateAccounts($entry, $accounts)
    {
		if (!$entry) return false;
		$this->administrator();
		$data = $this->getUserData($entry, ['cn', 'uid']);
		if (!isset($data['uid']) || empty($data['uid'])) {
			if (!empty($accounts))
				foreach ($accounts as $account) {
					$this->addAccount($entry, $account, '自建帳號');
				}
		} else {
			$uids = array();
			if (is_array($data['uid'])) {
				$uids = $data['uid'];
			} else {
				$uids[] = $data['uid'];
			}
			foreach ($uids as $uid) {
				if (!in_array($uid, $accounts)) $this->deleteAccount($entry, $uid);
			}
			if (!empty($accounts))
				foreach ($accounts as $account) {
					if (!in_array($account, $uids)) $this->addAccount($entry, $account, '自建帳號');
				}
		}
		$idno = $data['cn'];
		$acc_data = $this->findAccounts("cn=$idno", 'uid');
		if (!empty($acc_data)) {
			foreach ($acc_data as $acc) {
				if (!in_array($acc['uid'], $accounts))  $this->deleteAccount($entry, $acc['uid']);
			}
		}
		return true;
	}

    public function resetPassword($entry, $pwd)
    {
		if (!$entry) return;
		$this->administrator();
		$ssha = $this->make_ssha_password($pwd);
		$new_passwd = array( 'userPassword' => $ssha );
		$data = $this->getUserData($entry, 'cn');
		$idno = $data['cn'];
		$resource = @ldap_list(self::$ldap_read, config('ldap.authdn'), "cn=$idno");
		if ($resource) {
			$acc_entry = ldap_first_entry(self::$ldap_read, $resource);
			do {
				if ($acc_entry) $this->updateData($acc_entry,$new_passwd);
			} while ($acc_entry=ldap_next_entry(self::$ldap_read, $acc_entry));
		}
		$this->updateData($entry,$new_passwd);
    }

    public function addAccount($entry, $account, $memo)
    {
		$this->administrator();
		$data = $this->getUserData($entry, ['cn', 'uid', 'userPassword']);
		if (!isset($data['cn'])) return;
		$idno = $data['cn'];
		$password = $data['userPassword'];
		$this->addData($entry, array( "uid" => $account));
		$acc = $this->getAccountEntry($account);
		if ($acc) return;
		$account_info = array();
		$account_info['dn'] = "uid=$account,".config('ldap.authdn');
		$account_info['objectClass'] = "radiusObjectProfile";
		$account_info['uid'] = $account;
		$account_info['cn'] = $idno;
		$account_info['userPassword'] = $password;
		$account_info['description'] = $memo;
		$this->createEntry($account_info);
    }

    public function renameAccount($entry, $new_account)
    {
		$this->administrator();
		$data = $this->getUserData($entry, ['uid', 'mail', 'mobile']);
		$uid = $data['uid'];
		$accounts = array();
		if (is_array($uid))
			$accounts = $uid;
		else
			$accounts[] = $uid;
		for ($i=0;$i<count($accounts);$i++) {
			$match = true;
			if (isset($data['mail']) && $accounts[$i] == $data['mail']) $match = false;
			if (isset($data['mobile']) && $accounts[$i] == $data['mobile']) $match = false;
			if ($match) {
				$old_account = $accounts[$i];
				$accounts[$i] = $new_account;
			}
		}
		if (!empty($old_account)) {
			$this->updateData($entry, array( "uid" => $accounts));
			$dn = "uid=$old_account,".config('ldap.authdn');
			$rdn = "uid=$new_account";
			$result = @ldap_rename(self::$ldap_write, $dn, $rdn, null, true);
			return $result;
		} else {
			return $this->updateData($entry, array( "uid" => $accounts));
		}

	}

    public function deleteAccount($entry, $account)
    {
		$this->administrator();
		$this->deleteData($entry, array('uid' => $account));
		$acc_entry = $this->getAccountEntry($account);
		if ($acc_entry) $this->deleteEntry($acc_entry);
    }

    public function getGroupEntry($grp)
    {
		$this->administrator();
		$base_dn = config('ldap.groupdn');
		$grp_rdn = "cn=$grp";
		$resource = ldap_list(self::$ldap_read, $base_dn, $grp_rdn);
		if ($resource) {
			$entry = ldap_first_entry(self::$ldap_read, $resource);
			return $entry;
		}
		return false;
    }

    public function renameGroup($old_grp, $new_grp)
    {
		$this->administrator();
		$dn = "cn=$old_grp,".config('ldap.groupdn');
		$rdn = "cn=$new_grp";
		$result = @ldap_rename(self::$ldap_write, $dn, $rdn, null, true);
		return $result;
	}

    public function getGroups()
    {
		$this->administrator();
    	$filter = "objectClass=groupOfURLs";
    	$resource = @ldap_list(self::$ldap_read, config('ldap.groupdn'), $filter);
    	if ($resource) {
    		$info = @ldap_get_entries(self::$ldap_read, $resource);
    		$groups = array();
    		for ($i=0;$i<$info['count'];$i++) {
				$group = new \stdClass();
				$group->cn = $info[$i]['cn'][0];
				$group->url = $info[$i]['memberurl'][0];
				$groups[] = $group;
    		}
    		return $groups;
        }
        return false;
    }

    public function getMembers($identifier)
    {
		$this->administrator();
		$entry = $this->getGroupEntry($identifier);
		if ($entry) {
			$data = @ldap_get_values(self::$ldap_read, $entry, "memberURL");
			preg_match("/^ldap:\/\/\/".config('ldap.userdn')."\?(\w+)\?sub\?\(.*\)$/", $data[0], $matchs);
			$field = $matchs[1];
			$member = array();
			$value = @ldap_get_values(self::$ldap_read, $entry, $field);
			if ($value) {
				if ($value['count'] == 1) {
					$member[] = $value[0];
				} else {
					unset($value['count']);
					$member = $value;
				}
			}
			$member['attribute'] = $field;
			return $member;
		}
		return false;
	}

	public function ssha_check($text,$hash)
	{
		$ohash = base64_decode(substr($hash,6));
		$osalt = substr($ohash,20);
		$ohash = substr($ohash,0,20);
		$nhash = pack("H*",sha1($text.$osalt));
		return $ohash == $nhash;
	}

	public function make_ssha_password($password)
	{
		$salt = random_bytes(4);
		$hash = "{SSHA}" . base64_encode(pack("H*", sha1($password . $salt)) . $salt);
		return $hash;
	}
    
	public function make_ssha256_password($password)
	{
		$salt = random_bytes(4);
		$hash = "{SSHA256}" . base64_encode(pack("H*", hash('sha256', $password . $salt)) . $salt);
		return $hash;
	}
    
	public function make_ssha384_password($password)
	{
		$salt = random_bytes(4);
		$hash = "{SSHA384}" . base64_encode(pack("H*", hash('sha384', $password . $salt)) . $salt);
		return $hash;
	}
    
	public function make_ssha512_password($password)
	{
		$salt = random_bytes(4);
		$hash = "{SSHA512}" . base64_encode(pack("H*", hash('sha512', $password . $salt)) . $salt);
		return $hash;
	}
    
    public function make_sha_password($password)
    {
        $hash = "{SHA}" . base64_encode(pack("H*", sha1($password)));
        return $hash;
    }
    
    public function make_sha256_password($password)
    {
		$hash = "{SHA256}" . base64_encode(pack("H*", hash('sha256', $password)));
        return $hash;
    }
    
    public function make_sha384_password($password)
    {
        $hash = "{SHA384}" . base64_encode(pack("H*", hash('sha384', $password)));
        return $hash;
    }
    
    public function make_sha512_password($password)
    {
        $hash = "{SHA512}" . base64_encode(pack("H*", hash('sha512', $password)));
        return $hash;
    }
    
    public function make_smd5_password($password)
    {
        $salt = random_bytes(4);
        $hash = "{SMD5}" . base64_encode(pack("H*", md5($password . $salt)) . $salt);
        return $hash;
    }

    public function make_md5_password($password)
    {
        $hash = "{MD5}" . base64_encode(pack("H*", md5($password)));
        return $hash;
    }
    
    public function make_crypt_password($password, $hash_options)
    {
    	$salt_length = 2;
    	if ( isset($hash_options['crypt_salt_length']) ) {
    	    $salt_length = $hash_options['crypt_salt_length'];
    	}
    	// Generate salt
		$possible = '0123456789'.
					'abcdefghijklmnopqrstuvwxyz'.
					'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.
					'./';
		$salt = "";
		while( strlen( $salt ) < $salt_length ) {
    		$salt .= substr( $possible, random_int( 0, strlen( $possible ) - 1 ), 1 );
    	}
		if ( isset($hash_options['crypt_salt_prefix']) ) {
   			$salt = $hash_options['crypt_salt_prefix'] . $salt;
		}
    	$hash = '{CRYPT}' . crypt( $password,  $salt);
    	return $hash;
    }
}
