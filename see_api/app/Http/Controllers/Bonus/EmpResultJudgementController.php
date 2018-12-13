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

    public function index(Request $request) 
    {
        /* Cancel By: Wirun Pengsri, Cancel Date: 2018-11-30, Ref. Defact No.91
        $request->position_id = in_array('null', $request->position_id) ? "" : $request->position_id;
        $position_id = empty($request->position_id) ? "" : " AND er.position_id IN (".implode(',', $request->position_id).")";
        $emp_level = empty($request->emp_level) ? "" : " AND e.level_id = '{$request->emp_level}'";
        $org_level = empty($request->org_level) ? "" : " AND o.level_id = '{$request->org_level}'";
        $emp_id = empty($request->emp_id) ? "" : " AND er.emp_id = '{$request->emp_id}'";
        $org_id = empty($request->org_id) ? "" : " AND er.org_id = '{$request->org_id}'";
        $form = empty($request->appraisal_form_id) ? "" : "AND er.appraisal_form_id = '{$request->appraisal_form_id}'";

        $all_emp = $this->advanSearch->isAll();

        if ($all_emp[0]->count_no > 0) {
            $emp_all = "";
        } else {
            $gue = $this->advanSearch->GetallUnderEmp(Auth::id());
            $emp_all = empty($gue) ? "" : " AND find_in_set(e.emp_code, '{$gue}')";
        }

        //เช็คว่ามีใน EmpJudgement ไหมแล้วเอา result_score2 ล่าสุดมา
        $items = DB::select("
            SELECT  erj.emp_result_judgement_id,
                    er.emp_result_id,
                    e.emp_code, 
                    e.emp_name, 
                    al.appraisal_level_name, 
                    o.org_name, 
                    p.position_name, 
                    er.status,
                    ale.appraisal_level_name result_score_name2,
                    erj.adjust_result_score result_score2,
                    erj.percent_adjust,
                    ifnull(ast.edit_flag,0) edit_flag
            FROM emp_result_judgement erj
            INNER JOIN emp_result er ON er.emp_result_id = erj.emp_result_id
            INNER JOIN employee e ON e.emp_id = er.emp_id
            INNER JOIN org oo ON oo.level_id = erj.org_level_id
            INNER JOIN appraisal_level ale ON ale.level_id = oo.level_id
            LEFT JOIN appraisal_level al ON al.level_id = e.level_id
            LEFT JOIN org o ON o.org_id = e.org_id
            LEFT JOIN position p ON p.position_id = e.position_id
            LEFT JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
            WHERE erj.created_dttm = (
                SELECT MAX(created_dttm)
                FROM emp_result_judgement
                WHERE emp_result_judgement.emp_result_id = erj.emp_result_id
            ) AND er.period_id = '{$request->period_id}'
            AND er.stage_id = '{$request->stage_id}'
            ".$position_id.$emp_level.$org_level.$emp_id.$org_id.$form.$emp_all."
            GROUP BY emp_result_judgement_id,
            emp_result_id,
            emp_code, 
            emp_name, 
            appraisal_level_name, 
            org_name, 
            position_name, 
            status,
            result_score_name2,
            result_score2,
            percent_adjust,
            edit_flag
        ");

        //ถ้าไม่มีต้องเอา result_score2 มาจาก emp result
        if(empty($items)) {
            $emp_code = Auth::id();
            $items = DB::select("
                SELECT  er.emp_result_id,
                        e.emp_code, 
                        e.emp_name, 
                        al.appraisal_level_name, 
                        o.org_name, 
                        p.position_name, 
                        er.status,
                        'หัวหน้า' result_score_name1,
                        er.result_score result_score1,
                        100.00 percent_adjust,
                        (
                            SELECT emp_name
                            FROM employee WHERE emp_code = '{$emp_code}'
                        ) result_score_name2,
                        er.result_score result_score2,
                        ifnull(ast.edit_flag,0) edit_flag
                FROM emp_result er
                INNER JOIN employee e ON e.emp_id = er.emp_id
                LEFT JOIN appraisal_level al ON al.level_id = e.level_id
                LEFT JOIN org o ON o.org_id = e.org_id
                LEFT JOIN position p ON p.position_id = e.position_id
                LEFT JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
                WHERE er.period_id = '{$request->period_id}'
                AND er.stage_id = '{$request->stage_id}'
                ".$position_id.$emp_level.$org_level.$emp_id.$org_id.$form.$emp_all."
            ");
        } else {
            foreach ($items as $key1 => $value1) {
                //นำ result_score1 ของคนก่อนหน้ามาแสดง
                $items2 = DB::select("
                    SELECT  ale.appraisal_level_name result_score_name1,
                            erj.adjust_result_score result_score1
                    FROM emp_result_judgement erj
                    INNER JOIN emp_result er ON er.emp_result_id = erj.emp_result_id
                    INNER JOIN employee e ON e.emp_id = er.emp_id
                    INNER JOIN employee ee ON ee.emp_id = erj.judge_id
                    INNER JOIN org oo ON oo.level_id = erj.org_level_id
                    INNER JOIN appraisal_level ale ON ale.level_id = oo.level_id
                    LEFT JOIN appraisal_level al ON al.level_id = e.level_id
                    LEFT JOIN org o ON o.org_id = e.org_id
                    LEFT JOIN position p ON p.position_id = e.position_id
                    LEFT JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
                    WHERE erj.created_dttm = (
                        SELECT MAX(created_dttm)
                        FROM emp_result_judgement
                        WHERE emp_result_judgement.emp_result_id = erj.emp_result_id
                        AND emp_result_judgement.emp_result_id = {$value1->emp_result_id}
                        AND emp_result_judgement.emp_result_judgement_id != {$value1->emp_result_judgement_id}
                    ) AND er.period_id = '{$request->period_id}'
                    AND er.stage_id = '{$request->stage_id}'
                    ".$position_id.$emp_level.$org_level.$emp_id.$org_id.$form.$emp_all."
                ");
                
                if(empty($items2)) {
                    //ถ้าไม่มีคะแนนคนก่อนหน้าให้เอา result score1 มาจาก emp result
                    $items2 = DB::select("
                        SELECT  'หัวหน้า' result_score_name1,
                                er.result_score result_score1
                        FROM emp_result er
                        INNER JOIN employee e ON e.emp_id = er.emp_id
                        LEFT JOIN appraisal_level al ON al.level_id = e.level_id
                        LEFT JOIN org o ON o.org_id = e.org_id
                        LEFT JOIN position p ON p.position_id = e.position_id
                        LEFT JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
                        WHERE er.emp_result_id = '{$value1->emp_result_id}'
                        AND er.period_id = '{$request->period_id}'
                        AND er.stage_id = '{$request->stage_id}'
                        ".$position_id.$emp_level.$org_level.$emp_id.$org_id.$form.$emp_all."
                    ");
                }

                $items[$key1]->result_score_name1 = $items2[0]->result_score_name1;
                $items[$key1]->result_score1 = $items2[0]->result_score1;
            }
        }

        // Get the current page from the url if it's not set default to 1
        empty($request->page) ? $page = 1 : $page = $request->page;

        // Number of items per page
        empty($request->rpp) ? $perPage = 10 : $perPage = $request->rpp;

        $offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

        // Get only the items you need using array_slice (only get 10 items since that's what you need)
        $itemsForCurrentPage = array_slice($items, $offSet, $perPage, false);

        // Return the paginator with only 10 items but with the count of all items and set the it on the correct page
        $result = new LengthAwarePaginator($itemsForCurrentPage, count($items), $perPage, $page);

        return response()->json($result);*/
        return response()->json("Function is canceled.");
    }


    public function index2(Request $request)
    {
        $employee = Employee::find(Auth::id());
        $appraisalLevel = AppraisalLevel::find($employee->level_id);

        $empLevelQueryStr = empty($request->emp_level) ? "" : " AND emp.emp_level_id = '{$request->emp_level}'";
        $orgLevelQueryStr = empty($request->org_level) ? "" : " AND emp.org_level_id = '{$request->org_level}'";
        $orgIdQueryStr = empty($request->org_id) ? "" : " AND emp.org_id = '{$request->org_id}'";
        $empIdQueryStr = empty($request->emp_id) ? "" : " AND emp.emp_id = '{$request->emp_id}'";

        $request->position_id = in_array('null', $request->position_id) ? "" : $request->position_id;
        $positionIdQueryStr = empty($request->position_id) ? "" : " AND er.position_id IN (".implode(',', $request->position_id).")";
        $formIdQueryStr = empty($request->appraisal_form_id) ? "" : "AND er.appraisal_form_id = '{$request->appraisal_form_id}'";

        $allEmp = collect($this->advanSearch->isAll())->first();      

        if ($allEmp->count_no > 0) {
            $underLineAllEmp = "";
        } else {
            $gue = $this->advanSearch->GetallUnderEmp(Auth::id());
            $underLineAllEmp = empty($gue) ? "" : " AND find_in_set(emp.emp_code, '{$gue}')";
        }

        $res_query = "SELECT 
                IFNULL(erj.emp_result_judgement_id, 0) AS emp_result_judgement_id,
                er.emp_result_id,
                er.result_score AS emp_result_score,
                er.status,
                er.result_score,
                emp.emp_code,
                emp.emp_name,
                emp.emp_level_name AS appraisal_level_name,
                emp.org_level_name ,
                emp.org_name ,
                emp.position_name,
                erj.judge_id AS judgement_id,
                vel.appraisal_level_name AS judgement_name,
                erj.adjust_result_score AS judgement_score,
                100.00 AS percent_adjust,
                erj.created_dttm AS judgement_date,
                (
                    SELECT GROUP_CONCAT(j.adjust_result_score ORDER BY j.created_dttm DESC) 
                    FROM emp_result_judgement j 
                    WHERE j.emp_result_id = er.emp_result_id
                ) AS perv_score,
                (
                    SELECT 
                        GROUP_CONCAT(l.appraisal_level_name ORDER BY j.created_dttm DESC)
                    FROM emp_result_judgement j 
                    INNER JOIN employee e ON e.emp_id = j.judge_id
                    INNER JOIN appraisal_level l ON l.level_id = e.level_id
                    WHERE j.emp_result_id = er.emp_result_id
                ) AS perv_jud_name,
                ifnull(ast.edit_flag,0) edit_flag
            FROM emp_result er
            INNER JOIN (
                SELECT e.emp_id, e.emp_code, e.emp_name, e.org_id,
                    el.level_id AS emp_level_id,
                    el.appraisal_level_name AS emp_level_name,
                    ol.level_id AS org_level_id,
                    ol.appraisal_level_name AS org_level_name, 
                    p.position_name,
                    org.org_code ,
                    org.org_name ,
                    el.seq_no           
                FROM employee e 
                INNER JOIN appraisal_level el ON el.level_id = e.level_id
                INNER JOIN org ON org.org_id = e.org_id
                INNER JOIN appraisal_level ol ON ol.level_id = org.level_id
                INNER JOIN position p ON p.position_id = e.position_id
            ) emp ON emp.emp_id = er.emp_id
            LEFT OUTER JOIN emp_result_judgement erj ON erj.emp_result_id = er.emp_result_id
            LEFT OUTER JOIN employee jud ON jud.emp_id = erj.judge_id
            LEFT OUTER JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
            LEFT OUTER JOIN appraisal_level vel ON vel.level_id = jud.level_id
            WHERE er.period_id = '{$request->period_id}'
            AND er.stage_id = '{$request->stage_id}'
            ".$empLevelQueryStr."
            ".$orgLevelQueryStr."
            ".$orgIdQueryStr."
            ".$empIdQueryStr."
            ".$positionIdQueryStr."
            ".$formIdQueryStr."
            ".$underLineAllEmp."
            GROUP BY er.emp_result_id
            ORDER BY emp.org_code asc ,emp.emp_level_name desc ,emp.emp_code asc";

        $res = DB::select($res_query);

        
        // Number of items per page
        if($request->rpp == 'All') {
            $perPage = count(empty($res) ? 10 : $res);
        } else {
            empty($request->rpp) ? $perPage = count(empty($res) ? 10 : $res) : $perPage = $request->rpp;
        }

        $res = collect($res);
        $res = $res->map(function($data) use($appraisalLevel){
 
            $perv_score = explode(",", $data->perv_score);
            $perv_jud_name = explode(",", $data->perv_jud_name);

            if(empty($data->judgement_score)){
                $data->judgement_score = $data->result_score;
                $data->judgement_name = $appraisalLevel->appraisal_level_name;
                $data->perv_score_1 = $data->result_score;
                $data->perv_score_1_name = 'หัวหน้า';
            } else {
                $data->perv_score_1 = empty($perv_score[0]) ? null: $perv_score[0];
                $data->perv_score_2 = empty($perv_score[1]) ? null: $perv_score[1];
                $data->perv_score_3 = empty($perv_score[2]) ? null: $perv_score[2];
                $data->perv_score_1_name = empty($perv_jud_name[0]) ? null: $perv_jud_name[0];
                $data->perv_score_2_name = empty($perv_jud_name[1]) ? null: $perv_jud_name[1];
                $data->perv_score_3_name = empty($perv_jud_name[2]) ? null: $perv_jud_name[2];

                $data->judgement_name = $appraisalLevel->appraisal_level_name;
            }
            
            unset($data->perv_score);
            unset($data->perv_jud_name);

            return $data;
        });
        
        $res = $res->toArray();

        // Get the current page from the url if it's not set default to 1
        empty($request->page) ? $page = 1 : $page = $request->page;
        
        $offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

        // Get only the items you need using array_slice (only get 10 items since that's what you need)
        $itemsForCurrentPage = array_slice($res, $offSet, $perPage, false);

        // Return the paginator with only 10 items but with the count of all items and set the it on the correct page
        $result = new LengthAwarePaginator($itemsForCurrentPage, count($res), $perPage, $page);

        return response()->json($result);
    }

    public function index3(Request $request)
    {
        $employee = Employee::find(Auth::id());
        $appraisalLevel = AppraisalLevel::find($employee->level_id);

        $empLevelQueryStr = empty($request->emp_level) ? "" : " AND emp.emp_level_id = '{$request->emp_level}'";
        $orgLevelQueryStr = empty($request->org_level) ? "" : " AND emp.org_level_id = '{$request->org_level}'";
        $orgIdQueryStr = empty($request->org_id) ? "" : " AND emp.org_id = '{$request->org_id}'";
        $empIdQueryStr = empty($request->emp_id) ? "" : " AND emp.emp_id = '{$request->emp_id}'";

        $request->position_id = in_array('null', $request->position_id) ? "" : $request->position_id;
        $positionIdQueryStr = empty($request->position_id) ? "" : " AND er.position_id IN (".implode(',', $request->position_id).")";
        $formIdQueryStr = empty($request->appraisal_form_id) ? "" : "AND er.appraisal_form_id = '{$request->appraisal_form_id}'";

        $res_query = "SELECT
                emp.org_code,
                emp.parent_org_code,
                IFNULL(erj.emp_result_judgement_id, 0) AS emp_result_judgement_id,
                er.emp_result_id,
                er.result_score AS emp_result_score,
                er.status,
                er.result_score,
                emp.emp_code,
                emp.emp_name,
                emp.emp_level_name AS appraisal_level_name,
                emp.org_level_name ,
                emp.org_name ,
                emp.position_name,
                erj.judge_id AS judgement_id,
                vel.appraisal_level_name AS judgement_name,
                erj.adjust_result_score AS judgement_score,
                100.00 AS percent_adjust,
                erj.created_dttm AS judgement_date,
                (
                    SELECT GROUP_CONCAT(j.adjust_result_score ORDER BY j.created_dttm DESC) 
                    FROM emp_result_judgement j 
                    WHERE j.emp_result_id = er.emp_result_id
                ) AS perv_score,
                (
                    SELECT 
                        GROUP_CONCAT(l.appraisal_level_name ORDER BY j.created_dttm DESC)
                    FROM emp_result_judgement j 
                    INNER JOIN employee e ON e.emp_id = j.judge_id
                    INNER JOIN appraisal_level l ON l.level_id = e.level_id
                    WHERE j.emp_result_id = er.emp_result_id
                ) AS perv_jud_name,
                ifnull(ast.edit_flag,0) edit_flag
            FROM emp_result er
            INNER JOIN (
                SELECT e.emp_id, e.emp_code, e.emp_name, e.org_id,
                    el.level_id AS emp_level_id,
                    el.appraisal_level_name AS emp_level_name,
                    ol.level_id AS org_level_id,
                    ol.appraisal_level_name AS org_level_name, 
                    p.position_name,
                    org.org_code ,
                    org.parent_org_code ,
                    org.org_name ,
                    el.seq_no           
                FROM employee e 
                INNER JOIN appraisal_level el ON el.level_id = e.level_id
                INNER JOIN org ON org.org_id = e.org_id
                INNER JOIN appraisal_level ol ON ol.level_id = org.level_id
                INNER JOIN position p ON p.position_id = e.position_id
            ) emp ON emp.emp_id = er.emp_id
            LEFT OUTER JOIN emp_result_judgement erj ON erj.emp_result_id = er.emp_result_id
            LEFT OUTER JOIN employee jud ON jud.emp_id = erj.judge_id
            LEFT OUTER JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
            LEFT OUTER JOIN appraisal_level vel ON vel.level_id = jud.level_id
            WHERE er.period_id = '{$request->period_id}'
            AND er.stage_id = '{$request->stage_id}'
            ".$empLevelQueryStr."
            ".$orgLevelQueryStr."
            ".$orgIdQueryStr."
            ".$empIdQueryStr."
            ".$positionIdQueryStr."
            ".$formIdQueryStr."
            GROUP BY er.emp_result_id
            ORDER BY emp.org_code asc ,emp.emp_level_name desc ,emp.emp_code asc
        ";

        $res = DB::select($res_query);

        //หาค่า level, org, emp ที่อยู่ภายใต้
        $gue_emp_level = empty($request->emp_level) ? $gue_emp_level = '' : $this->advanSearch->GetallUnderLevel($request->emp_level);
        $gue_org_level = empty($request->org_level) ? $gue_org_level = '' : $this->advanSearch->GetallUnderLevel($request->org_level);
        $gueOrgCodeByOrgId = empty($request->org_id) ? $gueOrgCodeByOrgId = '' : $this->advanSearch->GetallUnderOrgByOrg($request->org_id);
        $gueOrgCodeByEmpId = empty($request->emp_id) ? $gueOrgCodeByEmpId = '' : $this->advanSearch->GetallUnderEmpByOrg($request->emp_id);
        $empLevelQueryStr = empty($gue_emp_level) ? "" : " AND find_in_set(emp.emp_level_id, '{$gue_emp_level}')";
        $orgLevelQueryStr = empty($gue_org_level) ? "" : " AND find_in_set(emp.org_level_id, '{$gue_org_level}')";
        $orgIdQueryStr = empty($gueOrgCodeByOrgId) ? "" : " AND find_in_set(emp.org_code, '{$gueOrgCodeByOrgId}')";
        $empIdQueryStr = empty($gueOrgCodeByEmpId) ? "" : " AND find_in_set(emp.org_code, '{$gueOrgCodeByEmpId}')";
        $formIdQueryStr = empty($request->appraisal_form_id) ? "" : "AND er.appraisal_form_id = '{$request->appraisal_form_id}'";

        $resArr = [];
        $empJudgeArr = [];
        $resultArr = [];
        $nOrg = 1;

        $map = function($res) {return $res->org_code;};
        $countOrgCode = array_count_values(array_map($map, $res));

        foreach ($res as $key => $value) {
            // หา org ที่อยู่ภายใต้
            $gue_org = $this->advanSearch->GetallUnderOrg($value->org_code);

            $res_query = "SELECT
                    emp.org_code,
                    emp.parent_org_code,
                    IFNULL(erj.emp_result_judgement_id, 0) AS emp_result_judgement_id,
                    er.emp_result_id,
                    er.result_score AS emp_result_score,
                    er.status,
                    er.result_score,
                    emp.emp_code,
                    emp.emp_name,
                    emp.emp_level_name AS appraisal_level_name,
                    emp.org_level_name ,
                    emp.org_name ,
                    emp.position_name,
                    erj.judge_id AS judgement_id,
                    vel.appraisal_level_name AS judgement_name,
                    erj.adjust_result_score AS judgement_score,
                    100.00 AS percent_adjust,
                    erj.created_dttm AS judgement_date,
                    (
                        SELECT GROUP_CONCAT(j.adjust_result_score ORDER BY j.created_dttm DESC) 
                        FROM emp_result_judgement j 
                        WHERE j.emp_result_id = er.emp_result_id
                    ) AS perv_score,
                    (
                        SELECT 
                            GROUP_CONCAT(l.appraisal_level_name ORDER BY j.created_dttm DESC)
                        FROM emp_result_judgement j 
                        INNER JOIN employee e ON e.emp_id = j.judge_id
                        INNER JOIN appraisal_level l ON l.level_id = e.level_id
                        WHERE j.emp_result_id = er.emp_result_id
                    ) AS perv_jud_name,
                    ifnull(ast.edit_flag,0) edit_flag
                FROM emp_result er
                INNER JOIN (
                    SELECT e.emp_id, e.emp_code, e.emp_name, e.org_id,
                        el.level_id AS emp_level_id,
                        el.appraisal_level_name AS emp_level_name,
                        ol.level_id AS org_level_id,
                        ol.appraisal_level_name AS org_level_name, 
                        p.position_name,
                        org.org_code ,
                        org.parent_org_code ,
                        org.org_name ,
                        el.seq_no           
                    FROM employee e 
                    INNER JOIN appraisal_level el ON el.level_id = e.level_id
                    INNER JOIN org ON org.org_id = e.org_id
                    INNER JOIN appraisal_level ol ON ol.level_id = org.level_id
                    INNER JOIN position p ON p.position_id = e.position_id
                ) emp ON emp.emp_id = er.emp_id
                LEFT OUTER JOIN emp_result_judgement erj ON erj.emp_result_id = er.emp_result_id
                LEFT OUTER JOIN employee jud ON jud.emp_id = erj.judge_id
                LEFT OUTER JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
                LEFT OUTER JOIN appraisal_level vel ON vel.level_id = jud.level_id
                WHERE er.period_id = '{$request->period_id}'
                AND er.stage_id = '{$request->stage_id}'
                AND find_in_set(emp.org_code, '{$gue_org}')
                ".$empLevelQueryStr."
                ".$orgLevelQueryStr."
                ".$orgIdQueryStr."
                ".$empIdQueryStr."
                ".$formIdQueryStr."
                GROUP BY er.emp_result_id
                ORDER BY emp.org_level_id, emp.parent_org_code, emp.org_code
            ";

            $resNew = DB::select($res_query);

            if($countOrgCode[$value->org_code]==$nOrg) { // หา org_code นั้นๆ ว่าเป็นรอบสุดท้ายหรือไม่
                foreach ($resNew as $key2 => $value2) {
                    $resultArr[] = $resNew[$key2]; // เก็บค่า result2
                    $empJudgeArr[] = $value2->emp_result_judgement_id; // เก็บค่า เพื่อนำไปเช็ค
                }
                $nOrg = 1; // เซทค่าเริ่มต้นใหม่
            } else { // ยังไม่ใช่รอบสุดท้าย
                $nOrg ++; // เพิ่มค่า $nOrg เพื่อหารอบถัดไป
            }
        }

        foreach ($res as $key => $value) {
            if(array_search($value->emp_result_judgement_id, $empJudgeArr)!==false) { //ถ้ามีค่าซ้ำกันให้ unset อออก
                unset($res[$key]);
            }
        }

        $res = collect($res)->merge($resultArr);

        $res = collect($res);
        $res = $res->map(function($data) use($appraisalLevel) {
 
            $perv_score = explode(",", $data->perv_score);
            $perv_jud_name = explode(",", $data->perv_jud_name);

            if(empty($data->judgement_score)){
                $data->judgement_score = $data->result_score;
                $data->judgement_name = $appraisalLevel->appraisal_level_name;
                $data->perv_score_1 = $data->result_score;
                $data->perv_score_1_name = 'หัวหน้า';
            } else {
                $data->perv_score_1 = empty($perv_score[0]) ? null: $perv_score[0];
                $data->perv_score_2 = empty($perv_score[1]) ? null: $perv_score[1];
                $data->perv_score_3 = empty($perv_score[2]) ? null: $perv_score[2];
                $data->perv_score_1_name = empty($perv_jud_name[0]) ? null: $perv_jud_name[0];
                $data->perv_score_2_name = empty($perv_jud_name[1]) ? null: $perv_jud_name[1];
                $data->perv_score_3_name = empty($perv_jud_name[2]) ? null: $perv_jud_name[2];
                $data->judgement_name = $appraisalLevel->appraisal_level_name;
            }

            unset($data->perv_score);
            unset($data->perv_jud_name);

            return $data;
        });

        // z-score
        $judAVG = $res->avg('perv_score_1'); // Score ล่าสุดที่ Adjust
        
        $res = $res->toArray();

        $data_dt = [];
        foreach ($res as $key => $value) {
            array_push($data_dt, $value->perv_score_1);
        }

        $dataSTD = empty($data_dt) ? 0 : $this->advanSearch->standard_deviation($data_dt);

        foreach ($res as $key => $value) {
            if($dataSTD==0) {
                $res[$key]->z_score = 0;
            } else {
                $res[$key]->z_score = ($value->perv_score_1-$judAVG) / $dataSTD;
            }
        }

        // Number of items per page
        if($request->rpp == 'All') {
            $perPage = count(empty($res) ? 10 : $res);
        } else {
            empty($request->rpp) ? $perPage = count(empty($res) ? 10 : $res) : $perPage = $request->rpp;
        }

        // Get the current page from the url if it's not set default to 1
        empty($request->page) ? $page = 1 : $page = $request->page;
        
        $offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

        // Get only the items you need using array_slice (only get 10 items since that's what you need)
        $itemsForCurrentPage = array_slice($res, $offSet, $perPage, false);

        // Return the paginator with only 10 items but with the count of all items and set the it on the correct page
        $result = new LengthAwarePaginator($itemsForCurrentPage, count($res), $perPage, $page);

        return response()->json(['result' => $result->toArray(), 'sd' => number_format($dataSTD, 2), 'avg' => number_format($judAVG, 2)]);
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

        $empLevelQueryStr = empty($request->emp_level) ? "" : " AND emp.emp_level_id = '{$request->emp_level}'";
        $orgLevelQueryStr = empty($request->org_level) ? "" : " AND emp.org_level_id = '{$request->org_level}'";
        $empIdQueryStr = empty($request->emp_id) ? "" : " AND emp.emp_id = '{$request->emp_id}'";

        if(empty($request->org_id)){
            $orgQueryStr = "";
        } else {
            $gueOrgCodeByOrgId = $this->advanSearch->GetallUnderOrgByOrg($request->org_id);
            $orgQueryStr = "AND (emp.org_id = '{$request->org_id}' OR find_in_set(emp.org_code, '{$gueOrgCodeByOrgId}'))";
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
}
