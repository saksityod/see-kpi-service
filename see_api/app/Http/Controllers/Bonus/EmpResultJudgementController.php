<?php

namespace App\Http\Controllers\Bonus;

use App\Http\Controllers\Bonus\AdvanceSearchController;

use App\AppraisalLevel;
use App\EmpResultJudgement;
use App\Employee;
use App\EmpResult;
use App\AppraisalStage;
use App\EmpResultStage;

use Auth;
use DB;
use Validator;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EmpResultJudgementController extends Controller
{
    public function __construct()
    {
        $this->middleware('jwt.auth');
        $this->advanSearch = new AdvanceSearchController;
    }

    public function store2(Request $request) {
        $errors_validator = [];

        if(empty($request['detail'])) {
            return response()->json([
                'status' => 400, 
                'data' => 'Please Select Employee for Adjust'
            ]);
        }

        if($request->cal_flag==0) {

            $validator = Validator::make([
                'stage_id' => $request->stage_id
            ], [
                'stage_id' => 'required|integer'
            ]);

            if($validator->fails()) {
                $errors_validator[] = $validator->errors();
            }

            foreach ($request['detail'] as $d) {
                if($d['edit_flag']==1) {
                    $validator_detail = Validator::make([
                        'emp_result_id' => $d['emp_result_id'],
                        'percent_adjust' => $d['percent_adjust'],
                        'adjust_result_score' => $d['adjust_result_score'],
                        'edit_flag' => $d['edit_flag']
                    ], [
                        'emp_result_id' => 'required|integer',
                        'percent_adjust' => 'required|between:0,100.00',
                        'adjust_result_score' => 'required|between:0,100.00',
                        'edit_flag' => 'required|integer'
                    ]);

                    if($validator_detail->fails()) {
                        $errors_validator[] = $validator_detail->errors();
                    }
                }
            }

            if(!empty($errors_validator)) {
                return response()->json(['status' => 400, 'data' => $errors_validator]);
            }

            /* $request->fake_flag
            1 คือ เป็นการประเมินแทน
            2 คือ เป็นการประเมินแทน แต่ปรับแค่ stage อย่างเดียว
            3 คือ การประเมินแบบปกติ
            */

            if($request->fake_flag==1) {
                $judge_id = $request['object_judge']['emp_id'];
                $judge_level = $request['object_judge']['level_id'];
            } else {
                $judge_id = $this->advanSearch->orgAuth()->emp_id;
                $judge_level = $this->advanSearch->orgAuth()->level_id;
            }

            $errors = [];
            foreach ($request['detail'] as $d) {
                if($request->fake_flag==1 OR $request->fake_flag==3) {
                    if($d['edit_flag']==1) {

                        // get current value
                        $empResultJudgement = EmpResultJudgement::where('emp_result_id', $d['emp_result_id'])
                            ->where('judge_id', $judge_id)
                            ->first();

                        
                        // chech insert or update
                        if(!$empResultJudgement){
                            $item = new EmpResultJudgement;
                            $item->emp_result_id = $d['emp_result_id'];
                            $item->judge_id = $judge_id;
                            $item->org_level_id = $judge_level;
                            $item->percent_adjust = $d['percent_adjust'];
                            $item->adjust_result_score = $d['adjust_result_score'];
                            $item->is_bonus =  0;
                            $item->created_by = Auth::id();

                            try {
                                $item->save();
                            } catch (Exception $e) {
                                $errors[] = substr($e, 254);
                            }
                        } else {
                            DB::table('emp_result_judgement')
                                ->where('emp_result_judgement_id', '=', $empResultJudgement->emp_result_judgement_id)
                                ->update(['percent_adjust'=>$d['percent_adjust'], 'adjust_result_score'=>$d['adjust_result_score']]);
                        }
                    }
                }


                $emp = EmpResult::find($d['emp_result_id']);
                $emp->stage_id = $request->stage_id;
                $emp->status = AppraisalStage::find($request->stage_id)->status;
                $emp->updated_by = Auth::id();

                $emp_stage = new EmpResultStage;
                $emp_stage->emp_result_id = $d['emp_result_id'];
                $emp_stage->stage_id = $request->stage_id;
                $emp_stage->created_by = Auth::id();
                $emp_stage->updated_by = Auth::id();

                try {
                    $emp->save();
                    $emp_stage->save();
                } catch (Exception $e) {
                    $errors[] = substr($e, 254);
                }
            }

        } else {

            foreach ($request['detail'] as $d) {
                if($d['edit_flag']==1) {
                    $validator_detail = Validator::make([
                        'emp_result_id' => $d['emp_result_id'],
                        'percent_adjust' => $d['percent_adjust'],
                        'adjust_result_score' => $d['adjust_result_score'],
                        'edit_flag' => $d['edit_flag']
                    ], [
                        'emp_result_id' => 'required|integer',
                        'percent_adjust' => 'required|between:0,100.00',
                        'adjust_result_score' => 'required|between:0,100.00',
                        'edit_flag' => 'required|integer'
                    ]);

                    if($validator_detail->fails()) {
                        $errors_validator[] = $validator_detail->errors();
                    }
                }
            }

            if(!empty($errors_validator)) {
                return response()->json(['status' => 400, 'data' => $errors_validator]);
            }

            $errors = [];
            foreach ($request['detail'] as $d) {
                if($d['edit_flag']==1) {

                    // get current value
                    $empResultJudgement = EmpResultJudgement::where('emp_result_id', $d['emp_result_id'])
                        ->where('judge_id', $this->advanSearch->orgAuth()->emp_id)
                        ->first();
                    
                    // chech insert or update
                    if(!$empResultJudgement) {
                        $item = new EmpResultJudgement;
                        $item->emp_result_id = $d['emp_result_id'];
                        $item->judge_id = $this->advanSearch->orgAuth()->emp_id;
                        $item->org_level_id = $this->advanSearch->orgAuth()->level_id;
                        $item->percent_adjust = $d['percent_adjust'];
                        $item->adjust_result_score = $d['adjust_result_score'];
                        $item->is_bonus =  0;
                        $item->created_by = Auth::id();

                        try {
                            $item->save();
                        } catch (Exception $e) {
                            $errors[] = substr($e, 254);
                        }
                    } else {
                        DB::table('emp_result_judgement')
                            ->where('emp_result_judgement_id', '=', $empResultJudgement->emp_result_judgement_id)
                            ->update(['percent_adjust'=> $d['percent_adjust'], 'adjust_result_score'=> $d['adjust_result_score']]);
                    }
                }
            }
        }

        return response()->json(['status' => 200, 'data' => $errors]);
    }

    public function store(Request $request) {
        $errors_validator = [];

        if(empty($request['detail'])) {
            return response()->json([
                'status' => 400, 
                'data' => 'Please Select Employee for Adjust'
            ]);
        }

        if($request->cal_flag==0) {
            $validator = Validator::make([
                'stage_id' => $request->stage_id
            ], [
                'stage_id' => 'required|integer'
            ]);

            if($validator->fails()) {
                $errors_validator[] = $validator->errors();
            }
        }

        foreach ($request['detail'] as $d) {
            if($d['edit_flag']==1) {
                $validator_detail = Validator::make([
                    'emp_result_id' => $d['emp_result_id'],
                    'percent_adjust' => $d['percent_adjust'],
                    'adjust_result_score' => $d['adjust_result_score'],
                    'edit_flag' => $d['edit_flag']
                ], [
                    'emp_result_id' => 'required|integer',
                    'percent_adjust' => 'required|between:0,100.00',
                    'adjust_result_score' => 'required|between:0,100.00',
                    'edit_flag' => 'required|integer'
                ]);

                if($validator_detail->fails()) {
                    $errors_validator[] = $validator_detail->errors();
                }
            }
        }

        if(!empty($errors_validator)) {
            return response()->json(['status' => 400, 'data' => $errors_validator]);
        }

        /* $request->fake_flag
            1 คือ เป็นการประเมินแทน
            2 คือ เป็นการประเมินแทน แต่ปรับแค่ stage อย่างเดียว
            3 คือ การประเมินแบบปกติ
        */

        if($request->fake_flag==1) {
            $judge_id = $request['object_judge']['emp_id'];
            $judge_level = $request['object_judge']['level_id'];
        } else {
            $judge_id = $this->advanSearch->orgAuth()->emp_id;
            $judge_level = $this->advanSearch->orgAuth()->level_id;
        }

        $errors = [];
        foreach ($request['detail'] as $d) {
            if($request->fake_flag==1 OR $request->fake_flag==3) {
                if($d['edit_flag']==1) {

                    // get current value
                    $empResultJudgement = EmpResultJudgement::where('emp_result_id', $d['emp_result_id'])
                    ->where('judge_id', $judge_id)
                    ->first();
                    
                    $dataJudge = DB::table("employee")->where('emp_id', '=', $judge_id)->first();

                    // chech insert or update
                    if(!$empResultJudgement){
                        $item = new EmpResultJudgement;
                        $item->emp_result_id = $d['emp_result_id'];
                        $item->judge_id = $judge_id;
                        $item->org_level_id = $judge_level;
                        $item->percent_adjust = $d['percent_adjust'];
                        $item->adjust_result_score = $d['adjust_result_score'];
                        $item->is_bonus =  0;
                        $item->created_by = $dataJudge->emp_code;

                        try {
                            $item->save();
                        } catch (Exception $e) {
                            $errors[] = substr($e, 254);
                        }
                    } else {
                        DB::table('emp_result_judgement')
                        ->where('emp_result_judgement_id', '=', $empResultJudgement->emp_result_judgement_id)
                        ->update([
                            'percent_adjust' => $d['percent_adjust'], 
                            'adjust_result_score' => $d['adjust_result_score'],
                            'created_by' => $dataJudge->emp_code
                        ]);
                    }
                }
            }

            if($request->cal_flag==0) {
                $emp = EmpResult::find($d['emp_result_id']);
                $emp->stage_id = $request->stage_id;
                $emp->status = AppraisalStage::find($request->stage_id)->status;
                $emp->updated_by = Auth::id();

                $emp_stage = new EmpResultStage;
                $emp_stage->emp_result_id = $d['emp_result_id'];
                $emp_stage->stage_id = $request->stage_id;
                $emp_stage->created_by = Auth::id();
                $emp_stage->updated_by = Auth::id();

                try {
                    $emp->save();
                    $emp_stage->save();
                } catch (Exception $e) {
                    $errors[] = substr($e, 254);
                }
            }
        }

        return response()->json(['status' => 200, 'data' => $errors]);
    }


    public function index4(Request $request)
    {
        // get judgement level
        $appraisalLevel = AppraisalLevel::select('level_id', 'appraisal_level_name')->where('is_start_cal_bonus', 1)->where('is_org', 1)->first();
        $levelList = $this->advanSearch->GetAllParentLevel($appraisalLevel->level_id, true);

        // set parameter
        $employee = Employee::find(Auth::id());
        $appraisalLevel = AppraisalLevel::find($employee->level_id);
        $AuthOrgLevel = collect(DB::select("
            SELECT level_id, appraisal_level_name 
            FROM appraisal_level vel 
            WHERE level_id = (SELECT level_id FROM org WHERE org.org_id = {$employee->org_id})
        "))->first();

        $gue_emp_level = empty($request->emp_level) ? '' : $this->advanSearch->GetallUnderLevel($request->emp_level);
        $gue_org_level = empty($request->org_level) ? '' : $this->advanSearch->GetallUnderLevel($request->org_level);
        $gueOrgCodeByEmpId = empty($request->emp_id) ? '' : $this->advanSearch->GetallUnderEmpByOrg($request->emp_id);
        $gueOrgCodeByOrgId = empty($request->org_id) ? '' : $this->advanSearch->GetallUnderOrgByOrg($request->org_id);

        $empLevelQueryStr = empty($gue_emp_level) && empty($request->emp_level) ? "" : " AND (emp.emp_level_id = '{$request->emp_level}' OR find_in_set(emp.emp_level_id, '{$gue_emp_level}'))";
        $orgLevelQueryStr = empty($gue_org_level) && empty($request->org_level) ? "" : " AND (emp.org_level_id = '{$request->org_level}' OR find_in_set(emp.org_level_id, '{$gue_org_level}'))";
        $empIdQueryStr = empty($gueOrgCodeByEmpId) && empty($request->emp_id) ? "" : " AND (emp.emp_id = '{$request->emp_id}' OR find_in_set(emp.org_code, '{$gueOrgCodeByEmpId}'))";

        // $empLevelQueryStr = empty($request->emp_level) ? "" : " AND emp.emp_level_id = '{$request->emp_level}'";
        // $orgLevelQueryStr = empty($request->org_level) ? "" : " AND emp.org_level_id = '{$request->org_level}'";
        // $empIdQueryStr = empty($request->emp_id) ? "" : " AND emp.emp_id = '{$request->emp_id}'";

        $all_emp = $this->advanSearch->isAll();
        if ($all_emp[0]->count_no > 0) {
            if(empty($request->org_id)) {
                $orgQueryStr = "";
            } else {
                $orgQueryStr = "AND (emp.org_id = '{$request->org_id}' OR find_in_set(emp.org_code, '{$gueOrgCodeByOrgId}'))";
            }
        } else {
            if(empty($request->org_id)) {
                $gueOrgCodeByOrgId = $this->advanSearch->GetallUnderOrgByOrg($employee->org_id);
                $orgQueryStr = "AND find_in_set(emp.org_code, '{$gueOrgCodeByOrgId}')";
            } else {
                $orgQueryStr = "AND (emp.org_id = '{$request->org_id}' OR find_in_set(emp.org_code, '{$gueOrgCodeByOrgId}'))";
            }
        }

        // if(empty($request->org_id)){
        //     $orgQueryStr = "";
        // } else {
        //     $gueOrgCodeByOrgId = $this->advanSearch->GetallUnderOrgByOrg($request->org_id);
        //     $orgQueryStr = "AND (emp.org_id = '{$request->org_id}' OR find_in_set(emp.org_code, '{$gueOrgCodeByOrgId}'))";
        // }
        
        $request->position_id = in_array('null', $request->position_id) ? "" : $request->position_id;
        $positionIdQueryStr = empty($request->position_id) ? "" : " AND er.position_id IN (".implode(',', $request->position_id).")";
        $formIdQueryStr = empty($request->appraisal_form_id) ? "" : "AND er.appraisal_form_id = '{$request->appraisal_form_id}'";
        

        // get data from emp result
        $empResult = DB::select("
            SELECT
                emp.org_code, emp.parent_org_code, er.emp_result_id, er.status,
                emp.emp_code, emp.emp_name, emp.emp_level_name AS appraisal_level_name,
                emp.org_level_name, emp.org_name, emp.position_name, ifnull(ast.edit_flag,0) edit_flag,
                100.00 AS percent_adjust, er.result_score AS mgr_score
            FROM emp_result er
            INNER JOIN (
                SELECT e.emp_id, e.emp_code, e.emp_name, e.org_id,
                    el.level_id AS emp_level_id, el.appraisal_level_name AS emp_level_name,
                    ol.level_id AS org_level_id, ol.appraisal_level_name AS org_level_name, 
                    p.position_name, org.org_code, org.parent_org_code, org.org_name, el.seq_no           
                FROM employee e 
                INNER JOIN appraisal_level el ON el.level_id = e.level_id
                INNER JOIN org ON org.org_id = e.org_id
                INNER JOIN appraisal_level ol ON ol.level_id = org.level_id
                INNER JOIN position p ON p.position_id = e.position_id
            ) emp ON emp.emp_id = er.emp_id
            LEFT OUTER JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
            WHERE er.period_id = '{$request->period_id}'
            AND er.stage_id = '{$request->stage_id}'
            ".$empLevelQueryStr."
            ".$orgLevelQueryStr."
            ".$orgQueryStr."
            ".$empIdQueryStr."
            ".$positionIdQueryStr."
            ".$formIdQueryStr."
            ORDER BY emp.org_id
        ");
        $empResult = collect($empResult);


        // get and push emp result judgement into emp result
        $empResult = $empResult->map(function($result) use($levelList, $AuthOrgLevel) {
            $judgements = collect();
            $judgements = $judgements->push([
                'org_level_id' => 0,  'org_level_name' => 'Mgr.', 
                'judge_id' => 0, 'adjust_result_score'=>$result->mgr_score
            ]);
            $lastJudScore = $result->mgr_score;

            foreach ($levelList as $level) {
                
                $empResultJudgement = EmpResultJudgement::select('judge_id', 'adjust_result_score')->where('emp_result_id', $result->emp_result_id)
                    ->where('org_level_id', $level->level_id)->first();
                    
                if($empResultJudgement){
                    $judgements = $judgements->push([
                        'org_level_id' => $level->level_id, 'org_level_name' => $level->appraisal_level_name,
                        'judge_id' => $empResultJudgement->judge_id, 'adjust_result_score' => $empResultJudgement->adjust_result_score
                    ]);
                    $lastJudScore = $empResultJudgement->adjust_result_score;
                } else {
                    $judgements = $judgements->push([
                        'org_level_id' => $level->level_id, 'org_level_name' => $level->appraisal_level_name,
                        'judge_id' => 0, 'adjust_result_score' => 0.00
                    ]);
                }
            }
            $result->judgements = $judgements;
            $result->last_judge_score = $lastJudScore;
            $result->cur_judge_org_level = $AuthOrgLevel->appraisal_level_name;
            return $result;
        });


        // calculate z-score
        $judAVG = $empResult->avg('last_judge_score'); // Score ล่าสุดที่ Adjust
        
        $empResult = $empResult->toArray();

        $data_dt = [];
        foreach ($empResult as $key => $value) {
            array_push($data_dt, $value->last_judge_score);
        }

        $dataSTD = empty($data_dt) ? 0 : $this->advanSearch->standard_deviation($data_dt);

        foreach ($empResult as $key => $value) {
            if($dataSTD==0) {
                $empResult[$key]->z_score = 0;
            } else {
                $empResult[$key]->z_score = ($value->last_judge_score-$judAVG) / $dataSTD;
            }
        }


        // Number of items per page
        if($request->rpp == 'All') {
            $perPage = count(empty($empResult) ? 10 : $empResult);
        } else {
            empty($request->rpp) ? $perPage = count(empty($empResult) ? 10 : $empResult) : $perPage = $request->rpp;
        }

        // Get the current page from the url if it's not set default to 1
        empty($request->page) ? $page = 1 : $page = $request->page;
        
        $offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

        // Get only the items you need using array_slice (only get 10 items since that's what you need)
        $itemsForCurrentPage = array_slice($empResult, $offSet, $perPage, false);

        // Return the paginator with only 10 items but with the count of all items and set the it on the correct page
        $result = new LengthAwarePaginator($itemsForCurrentPage, count($empResult), $perPage, $page);

        return response()->json(['result' => $result->toArray(), 'sd' => number_format($dataSTD, 2), 'avg' => number_format($judAVG, 2)]);
    }


    public function index5(Request $request)
    {
        // get appraisal form info 
        $appraisalForm = AppraisalForm::find($request->appraisal_form_id);
        if($appraisalForm->is_raise == 1){
            $flagColumn = "is_start_cal_raise";
        }elseif($appraisalForm->is_mpi == 1){
            $flagColumn = "is_start_cal_mpi";
        }else{
            $flagColumn = "is_start_cal_bonus";
        }

        // get judgement level
        $appraisalLevel = AppraisalLevel::select('level_id', 'appraisal_level_name')->where($flagColumn, 1)->where('is_org', 1)->first();
        $levelList = $this->advanSearch->GetAllParentLevel($appraisalLevel->level_id, true);
        
        // set parameter
        $employee = Employee::find(Auth::id());
        $appraisalLevel = AppraisalLevel::find($employee->level_id);
        $AuthOrgLevel = collect(DB::select("
            SELECT level_id, appraisal_level_name 
            FROM appraisal_level vel 
            WHERE level_id = (SELECT level_id FROM org WHERE org.org_id = {$employee->org_id})
        "))->first();

        $gue_emp_level = empty($request->emp_level) ? '' : $this->advanSearch->GetallUnderLevel($request->emp_level);
        $gue_org_level = empty($request->org_level) ? '' : $this->advanSearch->GetallUnderLevel($request->org_level);
        $gueOrgCodeByEmpId = empty($request->emp_id) ? '' : $this->advanSearch->GetallUnderEmpByOrg($request->emp_id);
        $gueOrgCodeByOrgId = empty($request->org_id) ? '' : $this->advanSearch->GetallUnderOrgByOrg($request->org_id);

        $empLevelQueryStr = empty($gue_emp_level) && empty($request->emp_level) ? "" : " AND (emp.emp_level_id = '{$request->emp_level}' OR find_in_set(emp.emp_level_id, '{$gue_emp_level}'))";
        $orgLevelQueryStr = empty($gue_org_level) && empty($request->org_level) ? "" : " AND (emp.org_level_id = '{$request->org_level}' OR find_in_set(emp.org_level_id, '{$gue_org_level}'))";
        $empIdQueryStr = empty($gueOrgCodeByEmpId) && empty($request->emp_id) ? "" : " AND (emp.emp_id = '{$request->emp_id}' OR find_in_set(emp.org_code, '{$gueOrgCodeByEmpId}'))";

        $all_emp = $this->advanSearch->isAll();
        if ($all_emp[0]->count_no > 0) {
            if(empty($request->org_id)) {
                $orgQueryStr = "";
            } else {
                $orgQueryStr = "AND (emp.org_id = '{$request->org_id}' OR find_in_set(emp.org_code, '{$gueOrgCodeByOrgId}'))";
            }
        } else {
            if(empty($request->org_id)) {
                $gueOrgCodeByOrgId = $this->advanSearch->GetallUnderOrgByOrg($employee->org_id);
                $orgQueryStr = "AND find_in_set(emp.org_code, '{$gueOrgCodeByOrgId}')";
            } else {
                $orgQueryStr = "AND (emp.org_id = '{$request->org_id}' OR find_in_set(emp.org_code, '{$gueOrgCodeByOrgId}'))";
            }
        }
        
        $request->position_id = in_array('null', $request->position_id) ? "" : $request->position_id;
        $positionIdQueryStr = empty($request->position_id) ? "" : " AND er.position_id IN (".implode(',', $request->position_id).")";
        $formIdQueryStr = empty($request->appraisal_form_id) ? "" : "AND er.appraisal_form_id = '{$request->appraisal_form_id}'";
        

        // get data from emp result
        $empResult = DB::select("
            SELECT
                emp.org_code, emp.parent_org_code, er.emp_result_id, er.status,
                emp.emp_code, emp.emp_name, emp.emp_level_name AS appraisal_level_name,
                emp.org_level_name, emp.org_name, emp.position_name, ifnull(ast.edit_flag,0) edit_flag,
                100.00 AS percent_adjust, er.result_score AS mgr_score
            FROM emp_result er
            INNER JOIN (
                SELECT e.emp_id, e.emp_code, e.emp_name, e.org_id,
                    el.level_id AS emp_level_id, el.appraisal_level_name AS emp_level_name,
                    ol.level_id AS org_level_id, ol.appraisal_level_name AS org_level_name, 
                    p.position_name, org.org_code, org.parent_org_code, org.org_name, el.seq_no           
                FROM employee e 
                INNER JOIN appraisal_level el ON el.level_id = e.level_id
                INNER JOIN org ON org.org_id = e.org_id
                INNER JOIN appraisal_level ol ON ol.level_id = org.level_id
                INNER JOIN position p ON p.position_id = e.position_id
            ) emp ON emp.emp_id = er.emp_id
            LEFT OUTER JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
            WHERE er.period_id = '{$request->period_id}'
            AND er.stage_id = '{$request->stage_id}'
            ".$empLevelQueryStr."
            ".$orgLevelQueryStr."
            ".$orgQueryStr."
            ".$empIdQueryStr."
            ".$positionIdQueryStr."
            ".$formIdQueryStr."
            ORDER BY emp.org_id ASC, emp.seq_no DESC, emp.emp_code ASC
        ");
        $empResult = collect($empResult);

        // bonus get level 3 step, mpi&raise 2 step
        if($appraisalForm->is_raise == 1){
            $levelList = $levelList->slice(0, 2);
        }elseif($appraisalForm->is_mpi == 1){
            $levelList = $levelList->slice(0, 2);
        }else{
            $levelList = $levelList->slice(0, 3);
        }

        // get and push emp result judgement into emp result
        $empResult = $empResult->map(function($result) use($levelList, $AuthOrgLevel) {
            $judgements = collect();
            $judgements = $judgements->push([
                'org_level_id' => 0,  'org_level_name' => 'Mgr.', 
                'judge_id' => 0, 'adjust_result_score'=>$result->mgr_score
            ]);
            $lastJudScore = $result->mgr_score;

            foreach ($levelList as $level) {
                
                $empResultJudgement = EmpResultJudgement::select('judge_id', 'adjust_result_score')->where('emp_result_id', $result->emp_result_id)
                    ->where('org_level_id', $level->level_id)->first();
                    
                if($empResultJudgement){
                    $judgements = $judgements->push([
                        'org_level_id' => $level->level_id, 'org_level_name' => $level->appraisal_level_name,
                        'judge_id' => $empResultJudgement->judge_id, 'adjust_result_score' => $empResultJudgement->adjust_result_score
                    ]);
                    $lastJudScore = $empResultJudgement->adjust_result_score;
                } else {
                    $judgements = $judgements->push([
                        'org_level_id' => $level->level_id, 'org_level_name' => $level->appraisal_level_name,
                        'judge_id' => 0, 'adjust_result_score' => 0.00
                    ]);
                }
            }
            $result->judgements = $judgements;
            $result->last_judge_score = $lastJudScore;
            $result->cur_judge_org_level = $AuthOrgLevel->appraisal_level_name;
            return $result;
        });


        // calculate z-score
        $judAVG = $empResult->avg('last_judge_score'); // Score ล่าสุดที่ Adjust
        
        $empResult = $empResult->toArray();

        $data_dt = [];
        foreach ($empResult as $key => $value) {
            array_push($data_dt, $value->last_judge_score);
        }

        $dataSTD = empty($data_dt) ? 0 : $this->advanSearch->standard_deviation($data_dt);

        foreach ($empResult as $key => $value) {
            if($dataSTD==0) {
                $empResult[$key]->z_score = 0;
            } else {
                $empResult[$key]->z_score = ($value->last_judge_score-$judAVG) / $dataSTD;
            }
        }


        // Number of items per page
        if($request->rpp == 'All') {
            $perPage = count(empty($empResult) ? 10 : $empResult);
        } else {
            empty($request->rpp) ? $perPage = count(empty($empResult) ? 10 : $empResult) : $perPage = $request->rpp;
        }

        // Get the current page from the url if it's not set default to 1
        empty($request->page) ? $page = 1 : $page = $request->page;
        
        $offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

        // Get only the items you need using array_slice (only get 10 items since that's what you need)
        $itemsForCurrentPage = array_slice($empResult, $offSet, $perPage, false);

        // Return the paginator with only 10 items but with the count of all items and set the it on the correct page
        $result = new LengthAwarePaginator($itemsForCurrentPage, count($empResult), $perPage, $page);

        return response()->json(['result' => $result->toArray(), 'sd' => number_format($dataSTD, 2), 'avg' => number_format($judAVG, 2)]);
    }
}
