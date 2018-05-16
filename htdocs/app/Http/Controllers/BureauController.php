<?php

namespace App\Http\Controllers;

use Config;
use Validator;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Providers\LdapServiceProvider;
use App\Rules\idno;
use App\Rules\ipv4cidr;
use App\Rules\ipv6cidr;

class BureauController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('bureau');
    }
    
    public function bureauPeopleSearchForm(Request $request)
    {
		$areas = [ '中正區', '大同區', '中山區', '松山區', '大安區', '萬華區', '信義區', '士林區', '北投區', '內湖區', '南港區', '文山區' ];
		$area = $request->get('area');
		if (empty($area)) $area = $areas[0];
		$filter = "st=$area";
		$openldap = new LdapServiceProvider();
		$schools = $openldap->getOrgs($filter);
		$dc = $request->get('dc');
		if (empty($dc) && $schools) $dc = $schools[0]->o;
		if ($dc) {
			$data = $openldap->getOus($dc);
			if ($data) $my_ou = $data[0]->ou;
		}
		$my_field = $request->get('field');
		if (empty($my_field) && $dc) $my_field = "ou=$my_ou";
		$keywords = $request->get('keywords');
		$request->session()->put('area', $area);
		$request->session()->put('dc', $dc);
		$request->session()->put('field', $my_field);
		$request->session()->put('keywords', $keywords);
		$ous = array();
		if (isset($data) && $data)
			foreach ($data as $ou) {
				if (!array_key_exists($ou->ou, $ous)) $ous[$ou->ou] = $ou->description;
			}
		if (substr($my_field,0,3) == 'ou=') {
			$my_ou = substr($my_field,3);
			if ($my_ou == 'deleted')
				$filter = "(&(o=$dc)(inetUserStatus=deleted))";
			elseif (is_numeric($my_ou))
				$filter = "(&(o=$dc)(tpClass=$my_ou)(!(inetUserStatus=deleted)))";
			else
				$filter = "(&(o=$dc)(ou=$my_ou)(!(inetUserStatus=deleted)))";
		} elseif ($my_field == 'uuid' && !empty($keywords)) {
			$filter = "(&(o=$dc)(entryUUID=*".$keywords."*))";
		} elseif ($my_field == 'idno' && !empty($keywords)) {
			$filter = "(&(o=$dc)(cn=*".$keywords."*))";
		} elseif ($my_field == 'name' && !empty($keywords)) {
			$filter = "(&(o=$dc)(displayName=*".$keywords."*))";
		} elseif ($my_field == 'mail' && !empty($keywords)) {
			$filter = "(&(o=$dc)(mail=*".$keywords."*))";
		} elseif ($my_field == 'mobile' && !empty($keywords)) {
			$filter = "(&(o=$dc)(mobile=*".$keywords."*))";
		}
		$people = $openldap->findUsers($filter, [ "cn", "displayName", "employeeType", "entryUUID", "inetUserStatus" ]);
		for ($i=0;$i<$people['count'];$i++) {
			if (!array_key_exists('inetuserstatus', $people[$i]) || $people[$i]['inetuserstatus']['count'] == 0) {
				$people[$i]['inetuserstatus']['count'] = 1;
				$people[$i]['inetuserstatus'][0] = '啟用';
			} elseif (strtolower($people[$i]['inetuserstatus'][0]) == 'active') {
				$people[$i]['inetuserstatus'][0] = '啟用';
			} elseif (strtolower($people[$i]['inetuserstatus'][0]) == 'inactive') {
				$people[$i]['inetuserstatus'][0] = '停用';
			} elseif (strtolower($people[$i]['inetuserstatus'][0]) == 'deleted') {
				$people[$i]['inetuserstatus'][0] = '已刪除';
			}
		}
		return view('admin.bureaupeople', [ 'area' => $area, 'areas' => $areas, 'dc' => $dc, 'schools' => $schools, 'ous' => $ous, 'my_field' => $my_field, 'keywords' => $keywords, 'people' => $people ]);
    }

    public function bureauPeopleEditForm(Request $request, $uuid = null)
	{
		$area = $request->session()->get('area');
		$dc = $request->session()->get('dc');
		$my_field = $request->session()->get('field');
		$keywords = $request->session()->get('keywords');
		$types = [ '教師', '學生', '校長', '職工', '主官管' ];
		$areas = [ '中正區', '大同區', '中山區', '松山區', '大安區', '萬華區', '信義區', '士林區', '北投區', '內湖區', '南港區', '文山區' ];
		if (empty($area)) $area = $areas[0];
		$filter = "st=$area";
		$openldap = new LdapServiceProvider();
		$data = $openldap->getOrgs($filter);
		$schools = array();
		foreach ($data as $school) {
			if (empty($dc)) $dc = $school->o;
			if (!array_key_exists($school->o, $schools)) $schools[$school->o] = $school->description;
		}
		$data = $openldap->getOus($dc, '教學班級');
		$classes = array();
		foreach ($data as $class) {
			if (!array_key_exists($class->ou, $classes)) $classes[$class->ou] = $class->description;
		}
		$data = $openldap->getOus($dc, '行政部門');
		$my_ou = '';
		$ous = array();
		foreach ($data as $ou) {
			if (empty($my_ou)) $my_ou = $ou->ou;
			if (!array_key_exists($ou->ou, $ous)) $ous[$ou->ou] = $ou->description;
		}
		
    	if (!is_null($uuid)) {//edit
    		$entry = $openldap->getUserEntry($uuid);
    		$user = $openldap->getUserData($entry);
    		$org_entry = $openldap->getOrgEntry($user['o']);
    		$data = $openldap->getOrgData($org_entry);
    		$area = $data['st'];
    		if ($user['employeeType'] != '學生') {
	    		if (array_key_exists('ou', $user))
    				$data = $openldap->getRoles($dc, $user['ou']);
    			else
    				$data = $openldap->getRoles($dc, $my_ou);
				$roles = array();
				foreach ($data as $role) {
					if (!array_key_exists($role->cn, $roles)) $roles[$role->cn] = $role->description;
				}
				return view('admin.bureauteacheredit', [ 'my_field' => $my_field, 'keywords' => $keywords, 'area' => $area, 'dc' => $dc, 'areas' => $areas, 'schools' => $schools, 'types' => $types, 'ous' => $ous, 'roles' => $roles, 'user' => $user ]);
			} else {
				return view('admin.bureaustudentedit', [ 'my_field' => $my_field, 'keywords' => $keywords, 'area' => $area, 'dc' => $dc, 'areas' => $areas, 'schools' => $schools, 'classes' => $classes, 'user' => $user ]);
			}
		} else { //add
	    	$data = $openldap->getRoles($dc, $my_ou);
			$roles = array();
			foreach ($data as $role) {
				if (!array_key_exists($role->cn, $roles)) $roles[$role->cn] = $role->description;
			}
			return view('admin.bureaupeopleedit', [ 'my_field' => $my_field, 'keywords' => $keywords, 'area' => $area, 'dc' => $dc, 'areas' => $areas, 'schools' => $schools, 'types' => $types, 'classes' => $classes, 'ous' => $ous, 'roles' => $roles ]);
		}
	}
	
    public function bureauPeopleJSONForm(Request $request)
	{
		$user = new \stdClass;
		$user->id = 'B123456789';
		$user->account = 'myaccount';
		$user->password = 'My_p@ssw0rD';
		$user->o = 'meps';
		$user->type = '教師';
		$user->ou = 'dept02';
		$user->role = 'role014';
		$user->tclass = array('606,sub01', '607,sub01', '608,sub01', '609,sub01', '610,sub01');
		$user->stdno = '102247';
		$user->class = '601';
		$user->seat = '7';
		$user->character = '雙胞胎 外籍配偶子女';
		$user->sn = '蘇';
		$user->gn = '小小';
		$user->name = '蘇小小';
		$user->gender = 2;
		$user->birthdate = '20101105';
		$user->mail = 'johnny@tp.edu.tw';
		$user->mobile = '0900100200';
		$user->fax = '(02)23093736';
		$user->otel = '(02)23033555';
		$user->htel = '(03)3127221';
		$user->register = "臺北市中正區龍興里9鄰三元街17巷22號5樓";
		$user->address = "新北市板橋區中山路1段196號";
		$user->www = 'http://johnny.dev.io';
		$user2 = new \stdClass;
		$user2->id = 'A123456789';
		$user2->o = 'meps';
		$user2->type = '教師';
		$user2->ou = 'dept02';
		$user2->role = 'role014';
		$user2->tclass = array('606,sub01', '607,sub01', '608,sub01', '609,sub01', '610,sub01');
		$user2->sn = '蘇';
		$user2->gn = '小小';
		$user2->gender = 2;
		$user2->birthdate = '20101105';
		$user3 = new \stdClass;
		$user3->id = 'B123456789';
		$user3->o = 'meps';
		$user3->type = '學生';
		$user3->stdno = '102247';
		$user3->class = '601';
		$user3->seat = '7';
		$user3->sn = '蘇';
		$user3->gn = '小小';
		$user3->gender = 2;
		$user3->birthdate = '20101105';
		return view('admin.bureaupeoplejson', [ 'sample1' => $user, 'sample2' => $user2, 'sample3' => $user3 ]);
	}
	
    public function importBureauPeople(Request $request)
    {
		$dc = $request->user()->ldap['o'];
		$openldap = new LdapServiceProvider();
		$entry = $openldap->getOrgEntry($dc);
		$sid = $openldap->getOrgData($entry, 'tpUniformNumbers');
		$sid = $sid['tpUniformNumbers'];
    	$messages[0] = 'heading';
    	if ($request->hasFile('json')) {
	    	$path = $request->file('json')->path();
    		$content = file_get_contents($path);
    		$json = json_decode($content);
    		if (!$json)
				return redirect()->back()->with("error", "檔案剖析失敗，請檢查 JSON 格式是否正確？");
			$rule = new idno;
			$teachers = array();
			if (is_array($json)) { //批量匯入
				$teachers = $json;
			} else {
				$teachers[] = $json;
			}
			$i = 0;
	 		foreach($teachers as $person) {
				$i++;
				if (!isset($person->name) || empty($person->name)) {
					if (empty($person->sn) || empty($person->gn)) {
						$messages[] = "第 $i 筆記錄，無真實姓名，跳過不處理！";
		    			continue;
					}
					$person->name = $person->sn.$person->gn;
				}
				if (!isset($person->id) || empty($person->id)) {
					$messages[] = "第 $i 筆記錄，無身分證字號，跳過不處理！";
		    		continue;
				}
				$validator = Validator::make(
    				[ 'idno' => $person->id ], [ 'idno' => new idno ]
    			);
				if ($validator->fails()) {
					$messages[] = "第 $i 筆記錄，".$person->name."身分證字號格式或內容不正確，跳過不處理！";
		    		continue;
				}
				if (!isset($person->o) || empty($person->o)) {
					$messages[] = "第 $i 筆記錄，".$person->name."無隸屬機構，跳過不處理！";
					continue;
				}
				if ($person->type == '學生') {
					if (!isset($person->stdno) || empty($person->stdno)) {
						$messages[] = "第 $i 筆記錄，".$person->name."無學號，跳過不處理！";
			    		continue;
					}
					if (!isset($person->class) || empty($person->class)) {
						$messages[] = "第 $i 筆記錄，".$person->name."無就讀班級，跳過不處理！";
			    		continue;
					}
					if (!isset($person->seat) || empty($person->seat)) {
						$messages[] = "第 $i 筆記錄，".$person->name."無座號，跳過不處理！";
		    			continue;
					}
				} else {
					if (!isset($person->ou) || empty($person->ou)) {
						$messages[] = "第 $i 筆記錄，".$person->name."無隸屬單位，跳過不處理！";
			    		continue;
					}
					if (!isset($person->title) || empty($person->title)) {
						$messages[] = "第 $i 筆記錄，".$person->name."無主要職稱，跳過不處理！";
			    		continue;
					}
				}
				$validator = Validator::make(
    				[ 'gender' => $person->gender ], [ 'gender' => 'required|digits:1' ]
    			);
    			if ($validator->fails()) {
					$messages[] = "第 $i 筆記錄，".$person->name."性別資訊不正確，跳過不處理！";
	    			continue;
				}
				$validator = Validator::make(
    				[ 'date' => $person->birthdate ], [ 'date' => 'required|date' ]
				);
	    		if ($validator->fails()) {
					$messages[] = "第 $i 筆記錄，".$person->name."出生日期格式或內容不正確，跳過不處理！";
		    		continue;
				}
				$user_dn = Config::get('ldap.userattr')."=".$person->id.",".Config::get('ldap.userdn');
				$entry = array();
				$entry["objectClass"] = array("tpeduPerson","inetUser");
 				$entry["inetUserStatus"] = "active";
   				$entry["cn"] = $person->id;
    			$entry["sn"] = $person->sn;
    			$entry["givenName"] = $person->gn;
    			$entry["displayName"] = $person->name;
    			$entry["gender"] = $person->gender;
				$entry["birthDate"] = $person->birthdate."000000Z";
    			$entry["o"] = $person->o;
    			$entry["employeeType"] = $person->type;
				$entry['info'] = json_encode(array("sid" => $sid, "role" => $person->type), JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
				if ($person->type == '學生') {
    				$entry["employeeNumber"] = $person->stdno;
    				$entry["tpClass"] = $person->class;
	    			$entry["tpSeat"] = $person->seat;
	    		} else {
	    			$entry["ou"] = $person->ou;
    				$entry["title"] = $person->role;
			    	if (isset($person->tclass)) {
		    			$data = array();
		    			$classes = array();
		    			if (is_array($person->tclass)) {
			    			$data = $person->tclass;
			    		} else {
			    			$data[] = $person->tclass;
		    			}
		    			foreach ($data as $class) {
	    					if ($openldap->getOuEntry($dc, $class)) $classes[] = $class;
	    				}
		    			$entry['tpTeachClass'] = $classes;
	    			}
    			}
				$account = array();
   				$account["objectClass"] = "radiusObjectProfile";
			    $account["cn"] = $person->id;
			    $account["description"] = '管理員匯入';
				if (isset($person->account) && !empty($person->account))
					$account["uid"] = $person->account;
				else
					$account["uid"] = $dc.substr($person->id, -9);
    			$entry["uid"] = $account["uid"];
				if (isset($person->password) && !empty($person->password))
					$password = $openldap->make_ssha_password($person->password);
				else
					$password = $openldap->make_ssha_password(substr($person->id, -6));
	   			$account["userPassword"] = $password;
	   			$account_dn = Config::get('ldap.authattr')."=".$account['uid'].",".Config::get('ldap.authdn');
	   			$entry["userPassword"] = $password;
		    	if (isset($person->character)) {
		    	    if (empty($person->character))
	    			    $entry['tpCharacter'] = [];
		    	    else
	    			    $entry['tpCharacter'] = explode(' ', $person->character);
	    		}
		    	if (isset($person->mail)) {
		    		$data = array();
		    		$mails = array();
		    		if (is_array($person->mail)) {
		    			$data = $person->mail;
		    		} else {
		    			$data[] = $person->mail;
		    		}
		    		foreach ($data as $mail) {
						$validator = Validator::make(
    						[ 'mail' => $mail ], [ 'mail' => 'email' ]
    					);
	    				if ($validator->passes()) $mails[] = $mail;
	    			}
	    			$entry['mail'] = $mails;
    			}
			    if (isset($person->mobile)) {
		    		$data = array();
		    		$mobiles = array();
			    	if (is_array($person->mobile)) {
			    		$data = $person->mobile;
			    	} else {
			    		$data[] = $person->mobile;
			    	}
			    	foreach ($data as $mobile) {
						$validator = Validator::make(
    						[ 'mobile' => $mobile ], [ 'mobile' => 'digits:10' ]
    					);
		    			if ($validator->passes()) $mobiles[] = $mobile;
					}
	   				$entry['mobile'] = $mobiles;
    			}
			    if (isset($person->fax)) {
			    	$data = array();
			    	$fax = array();
			    	if (is_array($person->fax)) {
			    		$data = $person->fax;
			    	} else {
			    		$data[] = $person->fax;
			    	}
				    foreach ($data as $tel) {
				    	$fax[] = self::convert_tel($tel);
  					}
		    		$entry['facsimileTelephoneNumber'] = $fax;
    			}
			    if (isset($person->otel)) {
			    	$data = array();
			    	$otel = array();
			    	if (is_array($person->otel)) {
			    		$data = $person->otel;
			    	} else {
			    		$data[] = $person->otel;
			    	}
				    foreach ($data as $tel) {
				    	$otel[] = self::convert_tel($tel);
  					}
		    		$entry['telephoneNumber'] = $otel;
    			}
			    if (isset($person->htel)) {
			    	$data = array();
			    	$htel = array();
			    	if (is_array($person->htel)) {
			    		$data = $person->htel;
			    	} else {
			    		$data[] = $person->htel;
			    	}
				    foreach ($data as $tel) {
				    	$htel[] = self::convert_tel($tel);
  					}
		    		$entry['homePhone'] = $htel;
    			}
			    if (isset($person->register) && !empty($person->register)) $entry["registeredAddress"]=self::chomp_address($person->register);
	    		if (isset($person->address) && !empty($person->register)) $entry["homePostalAddress"]=self::chomp_address($person->address);
	    		if (isset($person->www) && !empty($person->register)) $entry["wWWHomePage"]=$person->www;
			
				$user_entry = $openldap->getUserEntry($entry['cn']);
				if ($user_entry) {
					$result = $openldap->updateData($user_entry, $entry);
					if ($result)
						$messages[] = "第 $i 筆記錄，".$person->name."人員資訊已經更新！";
					else
						$messages[] = "第 $i 筆記錄，".$person->name."人員資訊無法更新！".$openldap->error();
				} else {
					$entry['dn'] = $user_dn;
					$result = $openldap->createEntry($entry);
					if ($result)
						$messages[] = "第 $i 筆記錄，".$person->name."人員資訊已經建立！";
					else
						$messages[] = "第 $i 筆記錄，".$person->name."人員資訊無法建立！".$openldap->error();
				}
				$account_entry = $openldap->getAccountEntry($account['uid']);
				if ($account_entry) {
					$result = $openldap->updateData($account_entry, $account);
					if ($result)
						$messages[] = "第 $i 筆記錄，".$person->name."帳號資訊已經更新！";
					else
						$messages[] = "第 $i 筆記錄，".$person->name."帳號資訊無法更新！".$openldap->error();
				} else {
					$account['dn'] = $account_dn;
					$result = $openldap->createEntry($account);
					if ($result)
						$messages[] = "第 $i 筆記錄，".$person->name."帳號資訊已經建立！";
					else
						$messages[] = "第 $i 筆記錄，".$person->name."帳號資訊無法建立！".$openldap->error();
				}
			}
			$messages[0] = "人員資訊匯入完成！報表如下：";
			return redirect()->back()->with("success", $messages);
    	} else {
			return redirect()->back()->with("error", "檔案上傳失敗！");
    	}
	}
	
    public function createBureauPeople(Request $request)
    {
		$my_field = $request->session()->get('field');
		$keywords = $request->session()->get('keywords');
		$validatedData = $request->validate([
			'idno' => new idno,
			'sn' => 'required|string',
			'gn' => 'required|string',
			'raddress' => 'nullable|string',
			'address' => 'nullable|string',
			'www' => 'nullable|url',
		]);
		$info = array();
		$info['objectClass'] = array('tpeduPerson', 'inetUser');
		$info['o'] = $request->get('o');
		if ($request->get('type') != '學生') {
			$info['employeeType'] = $request->get('type');
			$info['ou'] = $request->get('ou');
			$info['title'] = $request->get('role');
		} else {
			$validatedData = $request->validate([
				'stdno' => 'required|string',
				'seat' => 'required|integer',
			]);
			$info['employeeType'] = '學生';
			$info['employeeNumber'] = $request->get('stdno');
			$info['tpClass'] = $request->get('tclass');
			$info['tpSeat'] = $request->get('seat');
		}
		$openldap = new LdapServiceProvider();
		$entry = $openldap->getOrgEntry($info['o']);
		$sid = $openldap->getOrgData($entry, 'tpUniformNumbers');
		$sid = $sid['tpUniformNumbers'];
		$info['info'] = json_encode(array("sid" => $sid, "role" => $request->get('type')), JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
		$info['inetUserStatus'] = 'active';
		$info['cn'] = $request->get('idno');
		$info['dn'] = Config::get('ldap.userattr').'='.$info['cn'].','.Config::get('ldap.userdn');
		$info['sn'] = $request->get('sn');
		$info['givenName'] = $request->get('gn');
		$info['displayName'] = $info['sn'].$info['givenName'];
		$info['gender'] = $request->get('gender');
		$info['birthDate'] = $request->get('birth');
		if (!is_null($request->get('raddress'))) $info['registeredAddress'] = $request->get('raddress');
		if (!is_null($request->get('address'))) $info['homePostalAddress'] = $request->get('address');
		if (!is_null($request->get('www'))) $info['wWWHomePage'] = $request->get('www');
		if (!is_null($request->get('character'))) {
			$data = array();
			if (is_array($request->get('character'))) {
	    		$data = $request->get('character');
			} else {
	    		$data[] = $request->get('character');
			}
			$info['tpCharacter'] = $data;
		}
		if (!is_null($request->get('mail'))) {
			$data = array();
			if (is_array($request->get('mail'))) {
	    		$data = $request->get('mail');
			} else {
	    		$data[] = $request->get('mail');
			}
			$info['mail'] = $data;
		}
		if (!is_null($request->get('mobile'))) {
			$data = array();
			if (is_array($request->get('mobile'))) {
	    		$data = $request->get('mobile');
			} else {
	    		$data[] = $request->get('mobile');
			}
			$info['mobile'] = $data;
		}
		if (!is_null($request->get('fax'))) {
			$data = array();
			if (is_array($request->get('fax'))) {
	    		$data = $request->get('fax');
			} else {
	    		$data[] = $request->get('fax');
			}
			$info['facsimileTelephoneNumber'] = $data;
		}
		if (!is_null($request->get('otel'))) {
			$data = array();
			if (is_array($request->get('otel'))) {
	    		$data = $request->get('otel');
			} else {
	    		$data[] = $request->get('otel');
			}
			$info['telephoneNumber'] = $data;
		}
		if (!is_null($request->get('htel'))) {
			$data = array();
			if (is_array($request->get('htel'))) {
	    		$data = $request->get('htel');
			} else {
	    		$data[] = $request->get('htel');
			}
			$info['homePhone'] = $data;
		}

		$result = $openldap->createEntry($info);
		if ($result) {
			return redirect('bureau/people?area='.$request->get('area').'&dc='.$request->get('o').'&field='.$my_field.'&keywords='.$keywords)->with("error", "已經為您建立新人員！".$openldap->error());
		} else {
			return redirect('bureau/people?area='.$request->get('area').'&dc='.$request->get('o').'&field='.$my_field.'&keywords='.$keywords)->with("error", "人員新增失敗！".$openldap->error());
		}
	}
	
    public function updateBureauTeacher(Request $request, $uuid)
    {
		$my_field = $request->session()->get('field');
		$keywords = $request->session()->get('keywords');
		$validatedData = $request->validate([
			'idno' => new idno,
			'sn' => 'required|string',
			'gn' => 'required|string',
			'raddress' => 'nullable|string',
			'address' => 'nullable|string',
			'www' => 'nullable|url',
		]);
		$info = array();
		$info['o'] = $request->get('o');
		$info['ou'] = $request->get('ou');
		$info['title'] = $request->get('role');
		$info['sn'] = $request->get('sn');
		$info['givenName'] = $request->get('gn');
		$info['displayName'] = $info['sn'].$info['givenName'];
		$info['gender'] = (int) $request->get('gender');
		$info['birthDate'] = str_replace('-', '', $request->get('birth')).'000000Z';
		if (is_null($request->get('raddress')))
			$info['registeredAddress'] = [];
		else
			$info['registeredAddress'] = $request->get('raddress');
		if (is_null($request->get('address')))
			$info['homePostalAddress'] = [];
		else
			$info['homePostalAddress'] = $request->get('address');
		if (is_null($request->get('www')))
			$info['wWWHomePage'] = [];
		else
			$info['wWWHomePage'] = $request->get('www');
		if (is_null($request->get('character'))) {
			$info['tpCharacter'] = [];
		} else {
			$data = array();
			if (is_array($request->get('character'))) {
	    		$data = $request->get('character');
			} else {
	    		$data[] = $request->get('character');
			}
			$info['tpCharacter'] = $data;
		}
		if (is_null($request->get('mail'))) {
			$info['mail'] = [];
		} else {
			$data = array();
			if (is_array($request->get('mail'))) {
	    		$data = $request->get('mail');
			} else {
	    		$data[] = $request->get('mail');
			}
			$info['mail'] = $data;
		}
		if (is_null($request->get('mobile'))) {
			$info['mobile'] = [];
		} else {
			$data = array();
			if (is_array($request->get('mobile'))) {
	    		$data = $request->get('mobile');
			} else {
	    		$data[] = $request->get('mobile');
			}
			$info['mobile'] = $data;
		}
		if (is_null($request->get('fax'))) {
			$info['facsimileTelephoneNumber'] = [];
		} else {
			$data = array();
			if (is_array($request->get('fax'))) {
	    		$data = $request->get('fax');
			} else {
	    		$data[] = $request->get('fax');
			}
			$info['facsimileTelephoneNumber'] = $data;
		}
		if (is_null($request->get('otel'))) {
			$info['telephoneNumber'] = [];
		} else {
			$data = array();
			if (is_array($request->get('otel'))) {
	    		$data = $request->get('otel');
			} else {
	    		$data[] = $request->get('otel');
			}
			$info['telephoneNumber'] = $data;
		}
		if (is_null($request->get('htel'))) {
			$info['homePhone'] = [];
		} else {
			$data = array();
			if (is_array($request->get('htel'))) {
	    		$data = $request->get('htel');
			} else {
	    		$data[] = $request->get('htel');
			}
			$info['homePhone'] = $data;
		}
		
		$openldap = new LdapServiceProvider();
		$entry = $openldap->getUserEntry($uuid);
		$original = $openldap->getUserData($entry, 'cn');
		$result = $openldap->updateData($entry, $info);
		if ($result) {
			if ($original['cn'] != $request->get('idno')) {
				$result = $openldap->renameUser($original['cn'], $request->get('idno'));
				if ($result) {
					$user = $model->newQuery()
	        		->where('idno', $original['cn'])
	        		->first();
	        		$user->delete();
					if ($request->user()->idno == $original['cn']) Auth::logout();
					return redirect('bureau/people?area='.$request->get('area').'&dc='.$request->get('o').'&field='.$my_field.'&keywords='.$keywords)->with("success", "已經為您更新教師基本資料！");
				} else {
					return redirect('bureau/people?area='.$request->get('area').'&dc='.$request->get('o').'&field='.$my_field.'&keywords='.$keywords)->with("error", "教師身分證字號變更失敗！".$openldap->error());
				}
			}
		} else {
			return redirect('bureau/people?area='.$request->get('area').'&dc='.$request->get('o').'&field='.$my_field.'&keywords='.$keywords)->with("error", "教師基本資料變更失敗！".$openldap->error());
		}
	}
	
    public function updateBureauStudent(Request $request, $uuid)
	{
		$my_field = $request->session()->get('field');
		$keywords = $request->session()->get('keywords');
		$validatedData = $request->validate([
			'idno' => new idno,
			'sn' => 'required|string',
			'gn' => 'required|string',
			'stdno' => 'required|string',
			'seat' => 'required|integer',
			'raddress' => 'nullable|string',
			'address' => 'nullable|string',
			'www' => 'nullable|url',
		]);
		$info = array();
		$info['o'] = $request->get('o');
		$info['employeeNumber'] = $request->get('stdno');
		$info['tpClass'] = $request->get('tclass');
		$info['tpSeat'] = $request->get('seat');
		$info['sn'] = $request->get('sn');
		$info['givenName'] = $request->get('gn');
		$info['displayName'] = $info['sn'].$info['givenName'];
		$info['gender'] = (int) $request->get('gender');
		$info['birthDate'] = str_replace('-', '', $request->get('birth')).'000000Z';
		if (is_null($request->get('raddress'))) 
			$info['registeredAddress'] = [];
		else
			$info['registeredAddress'] = $request->get('raddress');
		if (is_null($request->get('address')))
			$info['homePostalAddress'] = [];
		else
			$info['homePostalAddress'] = $request->get('address');
		if (is_null($request->get('www')))
			$info['wWWHomePage'] = [];
		else
			$info['wWWHomePage'] = $request->get('www');
		if (is_null($request->get('character'))) {
			$info['tpCharacter'] = [];
		} else {
			$data = array();
			if (is_array($request->get('character'))) {
	    		$data = $request->get('character');
			} else {
	    		$data[] = $request->get('character');
			}
			$info['tpCharacter'] = $data;
		}
		if (is_null($request->get('mail'))) {
			$info['mail'] = [];
		} else {
			$data = array();
			if (is_array($request->get('mail'))) {
	    		$data = $request->get('mail');
			} else {
	    		$data[] = $request->get('mail');
			}
			$info['mail'] = $data;
		}
		if (is_null($request->get('mobile'))) {
			$info['mobile'] = [];
		} else {
			$data = array();
			if (is_array($request->get('mobile'))) {
	    		$data = $request->get('mobile');
			} else {
	    		$data[] = $request->get('mobile');
			}
			$info['mobile'] = $data;
		}
		if (is_null($request->get('fax'))) {
			$info['facsimileTelephoneNumber'] = [];
		} else {
			$data = array();
			if (is_array($request->get('fax'))) {
	    		$data = $request->get('fax');
			} else {
	    		$data[] = $request->get('fax');
			}
			$info['facsimileTelephoneNumber'] = $data;
		}
		if (is_null($request->get('otel'))) {
			$info['telephoneNumber'] = [];
		} else {
			$data = array();
			if (is_array($request->get('otel'))) {
	    		$data = $request->get('otel');
			} else {
	    		$data[] = $request->get('otel');
			}
			$info['telephoneNumber'] = $data;
		}
		if (!is_null($request->get('htel'))) {
			$info['homePhone'] = [];
		} else {
			$data = array();
			if (is_array($request->get('htel'))) {
	    		$data = $request->get('htel');
			} else {
	    		$data[] = $request->get('htel');
			}
			$info['homePhone'] = $data;
		}
				
		$openldap = new LdapServiceProvider();
		$entry = $openldap->getUserEntry($uuid);
		$original = $openldap->getUserData($entry, 'cn');
		$result = $openldap->updateData($entry, $info);
		if ($result) {
			if ($original['cn'] != $request->get('idno')) {
				$result = $openldap->renameUser($original['cn'], $request->get('idno'));
				if ($result) {
					$user = $model->newQuery()
	        		->where('idno', $original['cn'])
	        		->first();
	        		$user->delete();				
					return redirect('bureau/people?area='.$request->get('area').'&dc='.$request->get('o').'&field='.$my_field.'&keywords='.$keywords)->with("success", "已經為您更新學生基本資料！");
				} else {
					return redirect('bureau/people?area='.$request->get('area').'&dc='.$request->get('o').'&field='.$my_field.'&keywords='.$keywords)->with("error", "學生身分證字號變更失敗！".$openldap->error());
				}
			}
		} else {
			return redirect('bureau/people?area='.$request->get('area').'&dc='.$request->get('o').'&field='.$my_field.'&keywords='.$keywords)->with("error", "學生基本資料變更失敗！".$openldap->error());
		}
	}
	
    public function toggleBureauPeople(Request $request, $uuid)
    {
		$info = array();
		$openldap = new LdapServiceProvider();
		$entry = $openldap->getUserEntry($uuid);
		$data = $openldap->getUserData($entry, 'inetUserStatus');
		if (array_key_exists('inetUserStatus', $data) && $data['inetUserStatus'] == 'active')
			$info['inetUserStatus'] = 'inactive';
		else
			$info['inetUserStatus'] = 'active';
		$result = $openldap->updateData($entry, $info);
		if ($result) {
			return redirect()->back()->with("success", "已經將人員標註為".($info['inetUserStatus'] == 'active' ? '啟用' : '停用')."！");
		} else {
			return redirect()->back()->with("error", "無法變更人員狀態！".$openldap->error());
		}
	}
	
    public function removeBureauPeople(Request $request, $uuid)
    {
		$openldap = new LdapServiceProvider();
		$entry = $openldap->getUserEntry($uuid);
		$info = array();
		$info['inetUserStatus'] = 'deleted';
		$result = $openldap->updateData($entry, $info);
		if ($result) {
			return redirect()->back()->with("success", "已經將人員標註為刪除！");
		} else {
			return redirect()->back()->with("error", "無法變更人員狀態！".$openldap->error());
		}
	}
	
    public function undoBureauPeople(Request $request, $uuid)
    {
		$openldap = new LdapServiceProvider();
		$entry = $openldap->getUserEntry($uuid);
		$info = array();
		$info['inetUserStatus'] = 'active';
		$result = $openldap->updateData($entry, $info);
		if ($result) {
			return redirect()->back()->with("success", "已經將人員標註為啟用！");
		} else {
			return redirect()->back()->with("error", "無法變更人員狀態！".$openldap->error());
		}
	}
	
    public function resetpass(Request $request, $uuid)
    {
		$openldap = new LdapServiceProvider();
		$entry = $openldap->getUserEntry($uuid);
		$data = $openldap->getUserData($entry, array('cn', 'uid', 'mail', 'mobile'));
		if (array_key_exists('cn', $data)) {
			$idno = $data['cn'];
			$info = array();
			$info['userPassword'] = $openldap->make_ssha_password(substr($idno,-6));
		
			if (array_key_exists('cn', $data)) {
				if (is_array($data['uid'])) {
					foreach ($account as $data['uid']) {
						$account_entry = $openldap->getAccountEntry($account);
						$openldap->updateData($account_entry, $info);
					}
				} else {
					$account_entry = $openldap->getAccountEntry($data['uid']);
					$openldap->updateData($account_entry, $info);
				}
			}
			$result = $openldap->updateData($entry, $info);
			if ($result) {
				return redirect()->back()->with("success", "已經將人員密碼重設為身分證字號後六碼！");
			} else {
				return redirect()->back()->with("error", "無法變更人員密碼！".$openldap->error());
			}
		}
	}

    public function bureauGroupForm(Request $request)
    {
		$model = [ 'mobile' => '聯絡電話', 'mail' => '郵寄清單', 'dn' => '人員目錄', 'entryUUID' => '人員代號（API使用）' ];
		$fields = [ 'employeeType' => '身份別', 'tpCharacter' => '特殊身份註記', 'inetUserStatus' => '帳號狀態' ];
		$openldap = new LdapServiceProvider();
		$data = $openldap->getGroups();
		if (!$data) $data = [];
		return view('admin.bureaugroup', [ 'model' => $model, 'fields' => $fields, 'groups' => $data ]);
    }

    public function createBureauGroup(Request $request)
    {
		$validatedData = $request->validate([
			'new-grp' => 'required|string',
		]);
		$info = array();
		$info['objectClass'] = 'groupOfURLs';
		$info['cn'] = $request->get('new-grp');
		if ($request->has('url') && !empty($request->get('url'))) {
			$info['memberURL'] = $request->get('url');
		} elseif ($request->has('perform') && !empty($request->get('perform'))) {
			$info['memberURL'] = 'ldap:///ou=people,dc=tp,dc=edu,dc=tw?'.$request->get('model').'?sub?('.$request->get('field').'='.$request->get('perform').')';
		} else {
			return redirect()->back()->with("error", "過濾條件填寫不完整！");
		}
		$info['dn'] = 'cn='.$info['cn'].Config::get('ldap.groupdn');
		$openldap = new LdapServiceProvider();
		$result = $openldap->createEntry($info);
		if ($result) {
			return redirect()->back()->with("success", "已經為您建立動態群組！");
		} else {
			return redirect()->back()->with("error", "動態群組建立失敗！".$openldap->error());
		}
    }

    public function updateBureauGroup(Request $request, $cn)
    {
		$info = array();
		$new_cn = $request->get('cn');
		$openldap = new LdapServiceProvider();
		$result = $openldap->renameGroup($cn, $new_cn);
		if ($result) {
			return redirect()->back()->with("success", "已經為您修改群組名稱！");
		} else {
			return redirect()->back()->with("error", "群組名稱更新失敗！".$openldap->error());
		}
    }

    public function removeBureauGroup(Request $request, $cn)
    {
		$openldap = new LdapServiceProvider();
		$entry = $openldap->getGroupEntry($cn);
		$result = $openldap->deleteEntry($entry);
		if ($result) {
			return redirect()->back()->with("success", "已經為您移除動態群組！");
		} else {
			return redirect()->back()->with("error", "動態群組刪除失敗！".$openldap->error());
		}
    }

    public function bureauOrgForm(Request $request)
    {
		$areas = [ '中正區', '大同區', '中山區', '松山區', '大安區', '萬華區', '信義區', '士林區', '北投區', '內湖區', '南港區', '文山區' ];
		$area = $request->get('area');
		if (empty($area)) $area = $areas[0];
		$filter = "st=$area";
		$openldap = new LdapServiceProvider();
		$data = $openldap->getOrgs($filter);
		return view('admin.bureauorg', [ 'my_area' => $area, 'areas' => $areas, 'schools' => $data ]);
    }

    public function bureauOrgEditForm(Request $request, $dc = '')
    {
		$category = [ '幼兒園', '國民小學', '國民中學', '高中', '高職', '大專院校', '特殊教育', '主管機關' ];
		$areas = [ '中正區', '大同區', '中山區', '松山區', '大安區', '萬華區', '信義區', '士林區', '北投區', '內湖區', '南港區', '文山區' ];
		$openldap = new LdapServiceProvider();
		if (!empty($dc)) {
			$entry = $openldap->getOrgEntry($dc);
			$data = $openldap->getOrgData($entry);
			return view('admin.bureauorgedit', [ 'data' => $data, 'areas' => $areas, 'category' => $category ]);
		} else {
			return view('admin.bureauorgedit', [ 'areas' => $areas, 'category' => $category ]);
		}
    }

    public function bureauOrgJSONForm(Request $request)
    {
		$school1 = new \stdClass;
		$school1->id = 'meps';
		$school1->sid = '353604';
		$school1->name = '台北市中正區國語實驗國民小學';
		$school1->category = '國民小學';
		$school1->area = '中正區';
		$school1->fax = '(02)23093736';
		$school1->tel = '(02)23033555';
		$school1->postal = '10001';
		$school1->address = "臺北市中正區龍興里9鄰三元街17巷22號5樓";
		$school1->mbox = '043';
		$school1->www = 'http://www.meps.tp.edu.tw';
		$school1->ipv4 = '163.21.228.0/24';
		$school1->ipv6 = '2001:288:12ce::/64';
		$school2 = new \stdClass;
		$school2->id = 'meps';
		$school2->sid = '353604';
		$school2->name = '台北市中正區國語實驗國民小學';
		$school2->category = '國民小學';
		$school2->area = '中正區';
		return view('admin.bureauorgjson', [ 'sample1' => $school1, 'sample2' => $school2 ]);
	}
	
    public function createBureauOrg(Request $request)
    {
		$openldap = new LdapServiceProvider();
		$validatedData = $request->validate([
			'dc' => 'required|string',
			'description' => 'required|string',
			'businessCategory' => 'required|string',
			'st' => 'required|string',
			'fax' => 'nullable|string',
			'telephoneNumber' => 'nullable|string',
			'postalCode' => 'nullable|digits_between:3,5',
			'street' => 'nullable|string',
			'postOfficeBox' => 'nullable|digits:3',
			'wWWHomePage' => 'nullable|url',
			'tpUniformNumbers' => 'required|digits:6',
			'tpIpv4' => new ipv4cidr,
			'tpIpv6' => new ipv6cidr,
		]);
		$info = array();
		$info['objectClass'] = 'tpeduSchool';
		$info['o'] = $request->get('dc');
		$info['description'] = $request->get('description');
		$info['businessCategory'] = $request->get('businessCategory');
		$info['st'] = $request->get('st');
		if (!empty($request->get('fax'))) $info['facsimileTelephoneNumber'] = $request->get('fax');
		if (!empty($request->get('telephoneNumber'))) $info['telephoneNumber'] = $request->get('telephoneNumber');
		if (!empty($request->get('postalCode'))) $info['postalCode'] = $request->get('postalCode');
		if (!empty($request->get('street'))) $info['street'] = $request->get('street');
		if (!empty($request->get('postOfficeBox'))) $info['postOfficeBox'] = $request->get('postOfficeBox');
        if (!empty($request->get('wWWHomePage'))) $info['wWWHomePage'] = $request->get('wWWHomePage');
		$info['tpUniformNumbers'] = $request->get('tpUniformNumbers');
		if (!empty($request->get('tpIpv4'))) $info['tpIpv4'] = $request->get('tpIpv4');
		if (!empty($request->get('tpIpv6'))) $info['tpIpv6'] = $request->get('tpIpv6');
		$info['dn'] = Config::get('ldap.schattr')."=".$request->get('dc').",".Config::get('ldap.rdn');
				
		if ($openldap->createEntry($info)) {
			return redirect('bureau/organization?area='.$request->get('st'))->with("success", "已經為您建立新的教育機構！");
		} else {
			return redirect('bureau/organization?area='.$request->get('st'))->with("error", "教育機構資訊新增失敗！".$openldap->error());
		}
    }

    public function updateBureauOrg(Request $request, $dc)
    {
		$openldap = new LdapServiceProvider();
		$validatedData = $request->validate([
			'dc' => 'required|string',
			'description' => 'required|string',
			'businessCategory' => 'required|string',
			'st' => 'required|string',
			'fax' => 'nullable|string',
			'telephoneNumber' => 'nullable|string',
			'postalCode' => 'nullable|digits_between:3,5',
			'street' => 'nullable|string',
			'postOfficeBox' => 'nullable|digits:3',
			'wWWHomePage' => 'nullable|url',
			'tpUniformNumbers' => 'required|digits:6',
			'tpIpv4' => new ipv4cidr,
			'tpIpv6' => new ipv6cidr,
		]);
		$info = array();
		$info['o'] = $request->get('dc');
		$info['description'] = $request->get('description');
		$info['businessCategory'] = $request->get('businessCategory');
		$info['st'] = $request->get('st');
		$info['facsimileTelephoneNumber'] = [];
		if (!empty($request->get('fax'))) $info['facsimileTelephoneNumber'] = $request->get('fax');
		$info['telephoneNumber'] = [];
		if (!empty($request->get('telephoneNumber'))) $info['telephoneNumber'] = $request->get('telephoneNumber');
		$info['postalCode'] = [];
		if (!empty($request->get('postalCode'))) $info['postalCode'] = $request->get('postalCode');
		$info['street'] = [];
		if (!empty($request->get('street'))) $info['street'] = $request->get('street');
		$info['postOfficeBox'] = [];
		if (!empty($request->get('postOfficeBox'))) $inbfo['postOfficeBox'] = $request->get('postOfficeBox');
		$info['wWWHomePage'] = [];
        if (!empty($request->get('wWWHomePage'))) $info['wWWHomePage'] = $request->get('wWWHomePage');
		$info['tpUniformNumbers'] = $request->get('tpUniformNumbers');
		$info['tpIpv4'] = [];
		if (!empty($request->get('tpIpv4'))) $info['tpIpv4'] = $request->get('tpIpv4');
		$info['tpIpv6'] = [];
		if (!empty($request->get('tpIpv6'))) $info['tpIpv6'] = $request->get('tpIpv6');

		$entry = $openldap->getOrgEntry($dc);
		$result1 = $openldap->updateData($entry, $info);
		if ($result1) {
			if ($dc != $request->get('dc')) {
				$result2 = $openldap->renameOrg($dc, $request->get('dc'));
				if ($result2) {
					return redirect('bureau/organization?area='.$request->get('st'))->with("success", "已經為您更新教育機構資訊！");
				} else {
					return redirect('bureau/organization?area='.$request->get('st'))->with("error", "教育機構系統代號變更失敗！".$openldap->error());
				}
			}
			return redirect('bureau/organization?area='.$request->get('st'))->with("success", "已經為您更新教育機構資訊！");
		} else {
			return redirect('bureau/organization?area='.$request->get('st'))->with("error", "教育機構資訊變更失敗！".$openldap->error());
		}
    }

    public function removeBureauOrg(Request $request, $dc)
    {
		$openldap = new LdapServiceProvider();
		$users = $openldap->findUsers("o=$dc", "cn");
		if ($users && $users['count']>0) {
			return redirect()->back()->with("error", "尚有人員隸屬於該教育機構，因此無法刪除！");
		}
		$entry = $openldap->getOrgEntry($dc);
		$ous = $openldap->getOus($dc);
		if ($ous) {
			foreach ($ous as $ou) {
				$roles = $openldap->getRoles($dc, $ou);
				foreach ($roles as $role) {
					$role_entry = $openldap->getRoleEntry($dc, $ou, $role->cn);
					$openldap->deleteEntry($role_entry);
				}
				$ou_entry = $openldap->getOuEntry($dc, $ou);
				$openldap->deleteEntry($ou_entry);
			}
		}
		$result = $openldap->deleteEntry($entry);
		if ($result) {
			return redirect()->back()->with("success", "已經為您移除教育機構！");
		} else {
			return redirect()->back()->with("error", "教育機構刪除失敗！".$openldap->error());
		}
    }

    public function importBureauOrg(Request $request)
    {
		$openldap = new LdapServiceProvider();
    	$messages[0] = 'heading';
    	if ($request->hasFile('json')) {
	    	$path = $request->file('json')->path();
    		$content = file_get_contents($path);
    		$json = json_decode($content);
    		if (!$json)
				return redirect()->back()->with("error", "檔案剖析失敗，請檢查 JSON 格式是否正確？");
			$orgs = array();
			if (is_array($json)) { //批量匯入
				$orgs = $json;
			} else {
				$orgs[] = $json;
			}
			$i = 0;
	 		foreach($orgs as $org) {
				$i++;
				if (!isset($org->name) || empty($org->name)) {
					$messages[] = "第 $i 筆記錄，無機構全銜，跳過不處理！";
		    		continue;
				}
				if (!isset($org->id) || empty($org->id)) {
					$messages[] = "第 $i 筆記錄，無系統代號，跳過不處理！";
		    		continue;
				}
				$validator = Validator::make(
    				[ 'sid' => $org->sid ], [ 'sid' => 'required|digits:6' ]
    			);
				if ($validator->fails()) {
					$messages[] = "第 $i 筆記錄，".$org->name."統一編號格式不正確，跳過不處理！";
		    		continue;
				}
				$validator = Validator::make(
    				[ 'category' => $org->category ], [ 'category' => 'required|in:幼兒園,國民小學,國民中學,高中,高職,大專院校,特殊教育,主管機關' ]
    			);
    			if ($validator->fails()) {
					$messages[] = "第 $i 筆記錄，".$org->name."機構類別資訊不正確，跳過不處理！";
	    			continue;
				}
				$validator = Validator::make(
    				[ 'area' => $org->area ], [ 'area' => 'required|in:中正區,大同區,中山區,松山區,大安區,萬華區,信義區,士林區,北投區,內湖區,南港區,文山區' ]
    			);
    			if ($validator->fails()) {
					$messages[] = "第 $i 筆記錄，".$org->name."行政區資訊不正確，跳過不處理！";
	    			continue;
				}
				$org_dn = Config::get('ldap.schattr')."=".$org->id.",".Config::get('ldap.rdn');
				$entry = array();
				$entry["objectClass"] = array("tpeduSchool");
   				$entry["o"] = $org->id;
        		$entry['tpUniformNumbers'] = $org->sid;
		        $entry['description'] = $org->name;
		        $entry['businessCategory'] = $org->category;
		        $entry['st'] = $org->area;
			    if (isset($org->fax)) {
			    	$data = array();
			    	$fax = array();
			    	if (is_array($org->fax)) {
			    		$data = $org->fax;
			    	} else {
			    		$data[] = $org->fax;
			    	}
				    foreach ($data as $tel) {
				    	$fax[] = self::convert_tel($tel);
  					}
		    		$entry['facsimileTelephoneNumber'] = $fax;
    			}
			    if (isset($org->tel)) {
			    	$data = array();
			    	$tel = array();
			    	if (is_array($org->tel)) {
			    		$data = $org->tel;
			    	} else {
			    		$data[] = $org->tel;
			    	}
				    foreach ($data as $otel) {
				    	$tel[] = self::convert_tel($otel);
  					}
		    		$entry['telephoneNumber'] = $tel;
    			}
	    		if (isset($org->mbox) && !empty($org->mbox)) $entry["postOfficeBox"]=$org->mbox;
	    		if (isset($org->postal) && !empty($org->postal)) $entry["postalCode"]=$org->postal;
	    		if (isset($org->address) && !empty($org->address)) $entry["street"]=$org->address;
	    		if (isset($org->www) && !empty($org->www)) $entry["wWWHomePage"]=$org->www;
			    if (isset($org->ipv4)) {
			    	$net = array();
			    	if (is_array($org->ipv4)) {
			    		$data = $org->ipv4;
			    	} else {
			    		$data[] = $org->ipv4;
			    	}
				    foreach ($data as $ip) {
						$validator = Validator::make(
    						[ 'ipv4' => $ip ], [ 'ipv4' => new ipv4cidr ]
    					);
    					if ($validator->fails()) {
							$messages[] = "第 $i 筆記錄，".$org->name."IPv4 網路地址格式不正確，跳過不處理！";
	    					continue;
						}
				    	$net[] = $ip;
  					}
		    		$entry['tpIPv4'] = $net;
    			}
			    if (isset($org->ipv6)) {
			    	$net = array();
			    	if (is_array($org->ipv6)) {
			    		$data = $org->ipv6;
			    	} else {
			    		$data[] = $org->ipv6;
			    	}
				    foreach ($data as $ip) {
						$validator = Validator::make(
    						[ 'ipv6' => $ip ], [ 'ipv6' => new ipv6cidr ]
    					);
    					if ($validator->fails()) {
							$messages[] = "第 $i 筆記錄，".$org->name."IPv6 網路地址格式不正確，跳過不處理！";
	    					continue;
						}
				    	$net[] = $ip;
  					}
		    		$entry['tpIPv6'] = $net;
    			}
			
				$org_entry = $openldap->getOrgEntry($entry['o']);
				if ($org_entry) {
					$result = $openldap->updateData($org_entry, $entry);
					if ($result)
						$messages[] = "第 $i 筆記錄，".$org->name."機構資訊已經更新！";
					else
						$messages[] = "第 $i 筆記錄，".$org->name."機構資訊無法更新！".$openldap->error();
				} else {
					$entry['dn'] = $org_dn;
					$result = $openldap->createEntry($entry);
					if ($result)
						$messages[] = "第 $i 筆記錄，".$org->name."機構資訊已經建立！";
					else
						$messages[] = "第 $i 筆記錄，".$org->name."機構資訊無法建立！".$openldap->error();
				}
			}
			$messages[0] = "機構資訊匯入完成！報表如下：";
			return redirect()->back()->with("success", $messages);
    	} else {
			return redirect()->back()->with("error", "檔案上傳失敗！");
    	}
	}

    public function bureauAdminForm(Request $request)
    {
		$admins = DB::table('users')->where('is_admin', 1)->get();
		return view('admin.bureauadmin', [ 'admins' => $admins ]);
    }

    public function addBureauAdmin(Request $request)
    {
		if ($request->has('new-admin')) {
			$openldap = new LdapServiceProvider();
	    	$validatedData = $request->validate([
				'new-admin' => new idno,
			]);
			$idno = Config::get('ldap.userattr')."=".$request->get('new-admin');
	    	$entry = $openldap->getUserEntry($request->get('new-admin'));
			if (!$entry) {
				return redirect()->back()->with("error","您輸入的身分證字號，不存在於系統！");
	    	}
	    
			$admin = DB::table('users')->where('idno', $request->get('new-admin'))->first();	
	    	if ($admin) {
	    		DB::table('users')->where('id', $admin->id)->update(['is_admin' => 1]);
				return redirect()->back()->with("success", "已經為您新增局端管理員！");
			} else {
				return redirect()->back()->with("error", "尚未登入的人員無法設定為管理員！");
			}
	    }
    }
    
    public function delBureauAdmin(Request $request)
    {
		if ($request->has('delete-admin')) {
			$admin = DB::table('users')->where('idno', $request->get('delete-admin'))->first();	
	    	if ($admin) {
	    		DB::table('users')->where('id', $admin->id)->update(['is_admin' => 0]);
				return redirect()->back()->with("success", "已經為您移除局端管理員！");
			} else {
				return redirect()->back()->with("error", "找不到管理員，是否已經刪除了呢？");
			}
		}
    }

	private function chomp_address($address) {
		return mb_ereg_replace("\\\\", "",$address);
	}

	private function convert_tel($tel) {
  		$ret='';
		for ($i=0; $i<strlen($tel); $i++) {
    		$charter=substr($tel,$i,1);
			$asc=ord($charter);
    		if ($asc>=48 && $asc<=57) $ret.=$charter;
  		}
  		if (substr($ret,0,3)=="886") {
    		$area = substr($ret,3,1);
    		if ($area=="8" || $area=="9") {
      			$ret="(0".substr($ret,3,3).")".substr($ret,6);
    		} else {
      			$ret = "(0".$area.")".substr($ret,4);
    		}
  		}
  		if (substr($ret,0,1)=="0") {
    		$area=substr($ret,0,2);
    		if ($area=="08" || $area=="09") {
      			$ret="(".substr($ret,0,4).")".substr($ret,4);
    		} else {
      			$ret="(".substr($ret,0,2).")".substr($ret,2);
    		}
  		} elseif (substr($ret,0,1)!="(") {
    		$ret="(02)".$ret;
  		}
  		return $ret;
  	}    
}