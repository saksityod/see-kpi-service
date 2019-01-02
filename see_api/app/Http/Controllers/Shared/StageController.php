<?php

namespace App\Http\Controllers\Shared;

use App\AppraisalLevel;
use App\Employee;
use App\AssessorGroup;

use Auth;
use DB;
use Validator;
use Exception;
use Log;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class StageController extends Controller
{
    public function __construct()
    {
        $this->middleware('jwt.auth');
    }

    /*  Created By Thawatchai Srikot
        public function รับการยิงมาจาก front
        function รับการยิงภายใน service
    */

    function empAuth() {
        $empAuth = DB::table('employee')
        ->join('appraisal_level', 'appraisal_level.level_id', '=', 'employee.level_id')
        ->select('employee.level_id','employee.emp_id','appraisal_level.is_hr')
        ->where('emp_code', Auth::id())
        ->first();
        return $empAuth;
    }

    function orgAuth() {
        $orgAuth = DB::table('employee')
        ->join('org', 'org.org_id', '=', 'employee.org_id')
        ->join('appraisal_level', 'appraisal_level.level_id', '=', 'employee.level_id')
        ->select('org.level_id','employee.emp_id','appraisal_level.is_hr')
        ->where('emp_code', Auth::id())
        ->first();
        return $orgAuth;
    }

    // public function StatusList(Request $request) {
    //     if(empty($request->flag)) {
    //         return response()->json(['status' => 400, 'data' => 'Parameter flag is required']);
    //     }

    //     $empAuth = $this->empAuth();

    //     //hard code ไว้ กรณีหาคนที่เข้ามาว่าอยู่ระดับไหนใน assessor_group
    //     if($empAuth->is_hr==1) {
    //         $in = 5; //คือ hr
    //     } else {
    //         $in = 1; //หัวหน้าของพนักงาน
    //     }

    //     $flag = "ast.".$request->flag." = 1";

    //     $status = DB::select("
    //         SELECT ast.stage_id, ast.status
    //         FROM appraisal_stage ast
    //         INNER JOIN emp_result er ON er.stage_id = ast.stage_id
    //         WHERE {$flag}
    //         AND (find_in_set('{$in}', ast.assessor_see) OR ast.assessor_see = 'all')
    //         AND (find_in_set('{$request->appraisal_form_id}', ast.appraisal_form_id) OR ast.appraisal_form_id = 'all')
    //         AND (find_in_set('{$request->appraisal_type_id}', ast.appraisal_type_id) OR ast.appraisal_type_id = 'all')
    //         GROUP BY ast.stage_id
    //         ORDER BY ast.stage_id
    //     ");
        
    //     return response()->json($status);
    // }

    function to_action_call($request) {
        if(empty($request->flag)) {
            exit(json_encode(['status' => 400, 'data' => 'Parameter flag is required']));
        }

        $empAuth = $this->empAuth();
        $orgAuth = $this->orgAuth();

        $assGroup = (new \App\Http\Controllers\Appraisal360Degree\AppraisalGroupController)->getAssessorGroup(Auth::id());

        if($assGroup != null) {
            $in = $assGroup->assessor_group_id;
        } else {
            $assGroup = AssessorGroup::find(4);
            $in = $assGroup->assessor_group_id;
        }

        $stage = DB::table("appraisal_stage")
        ->select("to_stage_id")
        ->where("stage_id", $request->stage_id)
        ->first();

        $stage = empty($stage->to_stage_id) || $stage == 'null' ? "''" : $stage->to_stage_id;

        //หน้าที่ใช้ stage นี่
        //assignment

        $to_action = DB::select("
            SELECT stage_id, to_action
            FROM appraisal_stage
            WHERE stage_id IN ({$stage})
            AND (find_in_set('{$empAuth->level_id}', level_id) OR find_in_set('{$orgAuth->level_id}', level_id) OR level_id = 'all')
            AND {$request->flag} = 1
            AND (find_in_set('{$in}', assessor_see) OR assessor_see = 'all')
            AND (find_in_set('{$request->appraisal_type_id}', appraisal_type_id) OR appraisal_type_id = 'all')
            ORDER BY stage_id
            ");
        return $to_action;
    }

    public function to_action(Request $request) {
        if(empty($request->flag)) {
            return response()->json(['status' => 400, 'data' => 'Parameter flag is required']);
        }

        $empAuth = $this->empAuth();
        $orgAuth = $this->orgAuth();

        $assGroup = (new \App\Http\Controllers\Appraisal360Degree\AppraisalGroupController)->getAssessorGroup(Auth::id());

        if($assGroup != null) {
            $in = $assGroup->assessor_group_id;
        } else {
            $assGroup = AssessorGroup::find(4);
            $in = $assGroup->assessor_group_id;
        }

        $stage = DB::table("appraisal_stage")
        ->select("to_stage_id")
        ->where("stage_id", $request->stage_id)
        ->first();

        $stage = empty($stage->to_stage_id) || $stage == 'null' ? "''" : $stage->to_stage_id;

        if($request->flag=='appraisal360_flag') {
            //ส่วนเฉพาะหน้า Appraisal360
            $to_action = DB::select("
                SELECT stage_id, to_action
                FROM appraisal_stage
                WHERE stage_id IN ({$stage})
                 AND (find_in_set('{$empAuth->level_id}', level_id) OR find_in_set('{$orgAuth->level_id}', level_id) OR level_id = 'all')
                AND (find_in_set('{$request->appraisal_type_id}', appraisal_type_id) OR appraisal_type_id = 'all')
                AND {$request->flag} = 1
                AND find_in_set('{$request->appraisal_group_id}', assessor_see)
                ORDER BY stage_id
            ");
        } else {
            //หน้าที่ใช้ stage นี่
            //appraisal
            //assignment
            $to_action = DB::select("
                SELECT stage_id, to_action
                FROM appraisal_stage
                WHERE stage_id IN ({$stage})
                AND (find_in_set('{$empAuth->level_id}', level_id) OR find_in_set('{$orgAuth->level_id}', level_id) OR level_id = 'all')
                AND {$request->flag} = 1
                AND (find_in_set('{$in}', assessor_see) OR assessor_see = 'all')
                AND (find_in_set('{$request->appraisal_type_id}', appraisal_type_id) OR appraisal_type_id = 'all')
                ORDER BY stage_id
            ");
        }

        return response()->json($to_action);
    }
}
