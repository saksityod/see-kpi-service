<?php

namespace App\Http\Controllers\Bonus;

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
    }

    function empAuth($emp_code) {
        $empAuth = DB::table('employee')
        ->join('appraisal_level', 'appraisal_level.level_id', '=', 'employee.level_id')
        ->select('employee.level_id','employee.emp_id','appraisal_level.is_hr')
        ->where('emp_code', Auth::id())
        ->first();
        return $empAuth;
    }

    function orgAuth($emp_code) {
        $orgAuth = DB::table('employee')
        ->join('org', 'org.org_id', '=', 'employee.org_id')
        ->join('appraisal_level', 'appraisal_level.level_id', '=', 'employee.level_id')
        ->select('org.level_id','employee.emp_id','appraisal_level.is_hr')
        ->where('emp_code', Auth::id())
        ->first();
        return $orgAuth;
    }

    function getFieldAppraisalStage($level_id, $level_id_org) {
        $level = DB::select("
            SELECT stage_id, level_id
            FROM appraisal_stage
            WHERE (level_id LIKE '%{$level_id}%' OR level_id LIKE '%{$level_id_org}%' OR level_id = 'all')
        ");

        $stage_id_array = [];
        foreach ($level as $key => $value) {
            $ex = explode(",",$value->level_id);
            foreach ($ex as $exv) {
                if($level_id==$exv || $level_id_org==$exv || $exv=='all') {
                    array_push($stage_id_array, $value->stage_id);
                }
            }
        }

        if(empty($stage_id_array)) {
            $stage_id_array = "''";
        } else {
            $stage_id_array = implode(",", $stage_id_array);
        }

        return $stage_id_array;
    }

    public function store(Request $request) {
        $errors_validator = [];

        if(empty($request['detail'])) {
            return response()->json([
                'status' => 400, 
                'data' => [
                    0 => [
                        'SelectCheck' => [
                            0 => 'Please Select Employee for Adjust'
                        ]
                    ]
                ]
            ]);
        }

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

        $errors = [];
        foreach ($request['detail'] as $d) {
            if($d['edit_flag']==1) {
                $item = new EmpResultJudgement;
                $item->emp_result_id = $d['emp_result_id'];
                $item->judge_id = $this->orgAuth(Auth::id())->emp_id;
                $item->org_level_id = $this->orgAuth(Auth::id())->level_id;
                $item->percent_adjust = $d['percent_adjust'];
                $item->adjust_result_score = $d['adjust_result_score'];
                $item->is_bonus =  0;
                $item->created_by = Auth::id();
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

                if($d['edit_flag']==1) {
                    $item->save();
                }

                $emp->save();
                $emp_stage->save();
            } catch (Exception $e) {
                $errors[] = substr($e, 254);
            }
        }

        return response()->json(['status' => 200, 'data' => $errors]);
    }

    public function index(Request $request) {
        $request->position_id = in_array('null', $request->position_id) ? "" : $request->position_id;
        $position_id = empty($request->position_id) ? "" : " AND er.position_id IN (".implode(',', $request->position_id).")";
        $emp_level = empty($request->emp_level) ? "" : " AND e.level_id = '{$request->emp_level}'";
        $org_level = empty($request->org_level) ? "" : " AND o.level_id = '{$request->org_level}'";
        $emp_id = empty($request->emp_id) ? "" : " AND er.emp_id = '{$request->emp_id}'";
        $org_id = empty($request->org_id) ? "" : " AND er.org_id = '{$request->org_id}'";
        $form = empty($request->appraisal_form_id) ? "" : "AND er.appraisal_form_id = '{$request->appraisal_form_id}'";
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
            ".$position_id.$emp_level.$org_level.$emp_id.$org_id.$form."
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
                        '' percent_adjust,
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
                ".$position_id.$emp_level.$org_level.$emp_id.$org_id.$form."
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
                    ".$position_id.$emp_level.$org_level.$emp_id.$org_id.$form."
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
                        ".$position_id.$emp_level.$org_level.$emp_id.$org_id.$form."
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

        return response()->json($result);
    }

    public function to_action(Request $request) {
        $empAuth = $this->empAuth(Auth::id());
        $orgAuth = $this->orgAuth(Auth::id());
        $stage_in = $this->getFieldAppraisalStage($empAuth->level_id, $orgAuth->level_id);

        //hard code ไว้ กรณีหาคนที่เข้ามาว่าอยู่ระดับไหนใน assessor_group
        if($empAuth->is_hr==1) {
            $in = 5; //คือ hr
        } else {
            $in = 1; //หัวหน้าของพนักงาน
        }

        $stage = DB::table("appraisal_stage")
        ->select("to_stage_id")
        ->where("stage_id", $request->stage_id)
        ->first();

        $stage = empty($stage->to_stage_id) || $stage == 'null' ? "''" : $stage->to_stage_id;

        if($request->flag=='appraisal_flag') {
            //ส่วนเฉพาะหน้า Appraisal360
            $appraisal_form_id = empty($request->appraisal_form_id) ? "" : " AND (appraisal_form_id = '{$request->appraisal_form_id}' OR appraisal_form_id = 'all')";
            $to_action = DB::select("
                SELECT stage_id, to_action
                FROM appraisal_stage
                WHERE stage_id IN ({$stage})
                AND stage_id IN ({$stage_in}) #แสดง stage เฉพาะ level ที่มีสิธิ์เห็น
                {$appraisal_form_id}
                AND (appraisal_type_id = '{$request->appraisal_type_id}' OR appraisal_type_id = 'all')
                AND {$request->flag} = 1
                AND find_in_set('{$request->appraisal_group_id}', assessor_see)
                ORDER BY stage_id
            ");
        } else {
            $to_action = DB::select("
                SELECT stage_id, to_action
                FROM appraisal_stage
                WHERE stage_id IN ({$stage})
                AND stage_id IN ({$stage_in}) #แสดง stage เฉพาะ level ที่มีสิธิ์เห็น
                AND (assessor_see LIKE '%{$in}%' OR assessor_see = 'all')
                AND (appraisal_form_id = '{$request->appraisal_form_id}' OR appraisal_form_id = 'all')
                AND (appraisal_type_id = '{$request->appraisal_type_id}' OR appraisal_type_id = 'all')
                ORDER BY stage_id
            ");
        }

        return response()->json($to_action);
    }
}
