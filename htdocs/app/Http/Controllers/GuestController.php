<?php

namespace App\Http\Controllers;

use App\Events\ProjectApply;
use App\Project;
use Illuminate\Http\Request;

class GuestController extends Controller
{
    public function apply()
    {
        return view('3party.apply');
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'organization' => 'required|string|max:150',
            'applicationName' => 'required|string|max:150',
            'reason' => 'required|string|max:255',
            'website' => 'required|url',
            'redirect' => 'required|url',
            'connName' => 'required|string|max:50',
            'connUnit' => 'sometimes|string|max:150',
            'connEmail' => 'required|email:rfc,dns',
            'connTel' => 'required|digits_between:7,10',
        ]);
        if ($request->get('uuid')) {
            $project = Project::find($uuid);
            $project->forceFill([
                'organization' => $request->get('organization'),
                'applicationName' => $request->get('applicationName'),
                'reason' => $request->get('reason'),
                'website' => $request->get('website'),
                'redirect' => $request->get('redirect'),
                'kind' => $request->get('kind'),
                'connName' => $request->get('connName'),
                'connUnit' => $request->get('connUnit') ?: '',
                'connEmail' => $request->get('connEmail') ?: '',
                'connTel' => $request->get('connTel'),
            ])->save();
            $client = $project->getClient();
            if ($client) {
                $client->forceFill([
                    'name' => $request->get('applicationName'),
                    'redirect' => $request->get('redirect'),
                ])->save();
            }
        } else {
            $project = Project::where('redirect', $request->get('redirect'))->first();
            if ($project) {
                return redirect()->back()->with('error', '專案已經存在！請勿重複申請！');
            }
            $project = Project::create([
                'organization' => $request->get('organization'),
                'applicationName' => $request->get('applicationName'),
                'reason' => $request->get('reason'),
                'website' => $request->get('website'),
                'redirect' => $request->get('redirect'),
                'kind' => $request->get('kind'),
                'connName' => $request->get('connName'),
                'connUnit' => $request->get('connUnit') ?: '',
                'connEmail' => $request->get('connEmail') ?: '',
                'connTel' => $request->get('connTel'),
            ]);
        }
        event(new ProjectApply($project));

        return view('3party.store', ['uuid' => $project->uuid]);
    }

    public function edit(Request $request)
    {
        $uuid = $request->get('uuid');
        if ($uuid) {
            $project = Project::find($uuid);
            if (!$project) {
                return back()->with('error', 'UUID 不存在！');
            }

            return view('3party.edit', ['project' => $project]);
        } else {
            return back()->with('error', 'UUID 不存在！');
        }
    }
}
