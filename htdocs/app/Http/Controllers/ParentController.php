<?php

namespace App\Http\Controllers;

use Auth;
use Log;
use Carbon\Carbon;
use App\PSLink;
use App\PSAuthorize;
use App\GQrcode;
use Laravel\Passport\Passport;
use Illuminate\Http\Request;
use App\Providers\SimsServiceProvider;
use App\Providers\LdapServiceProvider;
use App\Rules\idno;

class ParentController extends Controller
{

	public function index()
	{
		$user = Auth::user();
		if (!($user->is_parent)) return redirect()->route('home');
		$idno = $user->idno;
		$kids = PSLink::where('parent_idno', $idno)->where('verified', 1)->orderBy('created_at','desc')->get();
		return view('parents.home', [ 'kids' => $kids ]);
	}

	public function listLink(Request $request)
	{
		$openldap = new LdapServiceProvider();
		$idno = Auth::user()->idno;
		$links = PSLink::where('parent_idno', $idno)->orderBy('created_at','desc')->get();
		$kids = array();
		foreach ($links as $l) {
			$link_id = $l->id;
			$k = array();
			$student_idno = $l->student_idno;
			$entry = $openldap->getUserEntry($student_idno);
			$data = $openldap->getUserData($entry);
			$school = $openldap->getOrgTitle($data['o']);
			$k['idno'] = $idno;
			$k['stdno'] = $data['employeeNumber'];
			$k['name'] = $data['displayName'];
			$k['school'] = $school;
			$k['class'] = $data['tpClass'];
			$k['seat'] = $data['tpSeat'];
			$kids[$link_id] = $k;
		}
		return view('parents.listLink', [ 'links' => $links, 'kids' => $kids ]);
	}

	public function showLinkForm(Request $request)
    {
		$relations = [ '父子', '母子', '監護人' ];
		return view('parents.linkEdit', [ 'relations' => $relations ]);
	}
	
	public function applyLink(Request $request)
    {
		$validatedData = $request->validate([
            'idno' => ['required', 'string', 'size:10', new idno],
			'birthday' => 'required|digits:8',
			'relation' => 'required|string',
		]);
		$alle = new SimsServiceProvider();
		$openldap = new LdapServiceProvider();
		$user = Auth::user();
		$idno = strtoupper($request->get('idno'));
		$birthday = $request->get('birthday');
		$relation = $request->get('relation');
		$student = $openldap->getUserEntry($idno);
		$data = $openldap->getUserData($student);
		if (!isset($data['o'])) return back()->with("error","查不到貴子弟的就學記錄，確定他是臺北市的學生嗎？");
		$dc = $data['o'];
		$role = $data['employeeType'];
		if (!isset($data['employeeNumber'])) return back()->with("error","查不到貴子弟的學號，請向註冊組反應：校務行政系統未登載學號！");
		if ($role != '學生') return back()->with("error","該身分證字號不屬於貴子弟所有！");
		if ($birthday != substr($data['birthDate'], 0, 8)) return back()->with("error","貴子弟的出生日期不正確！");
		$stdno = $data['employeeNumber'];
		$link = PSLink::where('parent_idno', $user->idno)->where('student_idno', $idno)->first();
		if (is_null($link)) {
			$link = new PSLink();
			$link->parent_idno = $user->idno;
			$link->student_idno = $idno;
		}
		$link->relation = $relation;
		$org = $openldap->getOrgEntry($dc);
		$odata = $openldap->getOrgData($org);
		if (!empty($odata['tpSims'])) $sims = $odata['tpSims'];
		if (isset($sims) && $sims == 'alle') {
			$uno = $odata['tpUniformNumbers'];
			$parents = $alle->ps_call('student_parents_info', [ 'sid' => $uno, 'stdno' => $stdno ]);
			$match = false;
			$reason = array();
			if (!empty($parents)) {
				foreach ($parents as $p) {
					if ($p->name == $user->name) {
						if (empty($user->mobile))
							$reason[] = '家長未填寫手機號碼';
						elseif (empty($p->telephone))
							$reason[] = '學籍資料查無家長手機號碼';
						elseif ($user->mobile != $p->telephone)
							$reason[] = '手機號碼不吻合';
						if ($p->relation == $relation) $reason[] = '親子關係不吻合';
						if (empty($reason)) $match = true;
						break;
					}
				}
			}
			if ($match) {
				$link->verified = 1;
				$link->verified_time = Carbon::now();
			} else {
				$link->denyReason = implode('、', $reason);
			}
		}
		$link->save();
		return redirect()->route('parent.listLink');
	}
	
	public function removeLink(Request $request, $id)
    {
		PSLink::find($id)->delete();
		return back()->with("success","已經為您移除親子連結！");
	}

	public function showGuardianAuthForm(Request $request)
    {
		$openldap = new LdapServiceProvider();
		$link_id = $request->get('id');
		$myidno = null;
		if ($link_id) {
			$link = PSLink::find($link_id);
			$myidno = $link->student_idno;
		}
		$idno = Auth::user()->idno;
		$links = PSLink::where('parent_idno', $idno)->orderBy('created_at','desc')->get();
		$idnos = array();
		$kids = array();
		foreach ($links as $l) {
			$student_idno = $l->student_idno;
			$idnos[] = $student_idno;
			$entry = $openldap->getUserEntry($student_idno);
			$data = $openldap->getUserData($entry);
			$age = Carbon::today()->subYears(13);
			$str = $data['birthDate'];
			$born = Carbon::createFromDate(substr($str,0,4), substr($str,4,2), substr($str,6,2), 'Asia/Taipei');
			if ($born > $age) {
				$kids[$l->id]['idno'] = $l->student_idno;
				$kids[$l->id]['name'] = $data['displayName'];
				if (empty($myidno)) $myidno = $l->student_idno; 
			}
		}
		$apps = Passport::client()->all();
		foreach ($apps as $k => $app) {
			if ($app->firstParty()) unset($apps[$k]);
		}
		$agreeAll = null;
		$authorizes = array();
		if ($idnos) {
			if (!$myidno) $myidno = $idnos[0];
			$agreeAll = PSAuthorize::where('student_idno', $myidno)->where('client_id', '*')->first();
			$data = PSAuthorize::where('student_idno', $myidno)->where('client_id', '!=', '*')->get();
			foreach ($data as $d) {
				$authorizes[$d->client_id] = $d->trust_level;
			}
		}
		return view('parents.guardianAuthForm', [ 'student' => $myidno, 'kids' => $kids, 'apps' => $apps, 'agreeAll' => $agreeAll, 'authorizes' => $authorizes, 'trust_level' => config('app.trust_level') ]);		
	}

	public function applyGuardianAuth(Request $request)
    {
		$parent_idno = Auth::user()->idno;
		$student_idno = $request->get('student');
		$agreeAll = $request->get('agreeAll');
		$agree = $request->get('agree');
		if (!empty($agreeAll)) {
			if ($agreeAll == 'new') {
				PSAuthorize::where('student_idno', $student_idno)->delete();
				PSAuthorize::create([
					'parent_idno' => $parent_idno,
					'student_idno' => $student_idno,
					'client_id' => '*',
					'trust_level' => 3,
				]);
			} else {
				PSAuthorize::where('student_idno', $student_idno)->where('client_id', '!=', '*')->delete();
			}
		} elseif (!empty($agree)) {
			PSAuthorize::where('student_idno', $student_idno)->where('client_id', '*')->delete();
			$apps = Passport::client()->all();
			foreach ($apps as $app) {
				if ($app->firstParty()) continue;
				if (in_array($app->id, $agree)) {
					$trust_level = $request->get($app->id.'level');
					$old = PSAuthorize::where('student_idno', $student_idno)->where('client_id', $app->id)->first();
					if ($old) {
						$old->trust_level = $trust_level;
						$old->save();
					} else {
						PSAuthorize::create([
							'parent_idno' => $parent_idno,
							'student_idno' => $student_idno,
							'client_id' => $app->id,
							'trust_level' => $trust_level,
						]);
					}
				} else {
					PSAuthorize::where('student_idno', $student_idno)->where('client_id', $app->id)->delete();
				}
			}
		}
		return redirect()->route('parent.guardianAuth')->with("success","已經為您更新代理授權設定！")->with('student',$request->get('student'));
	}

	public function qrcodeBind(Request $request, $uuid)
    {
		$openldap = new LdapServiceProvider();
		$qrcode = GQrcode::find($uuid);
		if (!$qrcode || $qrcode->expired()) return redirect()->route('home');
		$student = $qrcode->idno;
		$parent = Auth::user()->idno;
		$link = PSLink::where('parent_idno', $parent)->where('student_idno', $student)->first();
		if ($link) {
			$link->verified = true;
			$link->verified_idno = $parent;
			$link->verified_time = Carbon::now();
			$link->save();
			$qrcode->delete();
			return redirect()->route('parent.listLink');
		} else {
			PSLink::create([
				'parent_idno' => $parent,
				'student_idno' => $student,
				'relation' => '監護人',
				'verified' => true,
				'verified_idno' => $parent,
				'verified_time' => Carbon::now(),
			]);
			$qrcode->delete();
			return redirect()->route('parent.listLink');
		}
	}

}
