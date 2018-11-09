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

class BonusAdjustmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('jwt.auth');
    }

    function empAuth() {
        $empAuth = Employee::where("emp_code", Auth::id())->first();
        return $empAuth;
    }

    public function store(Request $request) {
        $errors_validator = [];
        $validator = Validator::make([
            'stage_id' => $request->stage_id
        ], [
            'stage_id' => 'required|integer'
        ]);

        if($validator->fails()) {
            $errors_validator[] = $validator->errors();
        }

        foreach ($request['detail'] as $d) {
            $validator_detail = Validator::make([
                'emp_result_id' => $d['emp_result_id'],
                'percent_adjust' => $d['percent_adjust'],
                'adjust_result_score' => $d['score']
            ], [
                'emp_result_id' => 'required|integer',
                'percent_adjust' => 'required|between:0,100.00',
                'adjust_result_score' => 'required|between:0,100.00'
            ]);

            if($validator_detail->fails()) {
                $errors_validator[] = $validator_detail->errors();
            }
        }

        if(!empty($errors_validator)) {
            return response()->json(['status' => 400, 'data' => $errors_validator]);
        }

        foreach ($request['detail'] as $d) {
            $item = new EmpResultJudgement;
            $item->emp_result_id = $d['emp_result_id'];
            $item->judge_id = $this->empAuth()->emp_id;
            $item->percent_adjust = $d['adjust_result_score'];
            $item->adjust_result_score = $d['adjust_result_score'];
            $item->is_bonus =  1;
            $item->created_by = Auth::id();
            $item->save();

            $emp = EmpResult::find($d['emp_result_id']);
            $emp->stage_id = $request->stage_id;
            $emp->status = AppraisalStage::find($request->stage_id)->status;
            $emp->updated_by = Auth::id();
            $emp->save();

            $emp_stage = new EmpResultStage;
            $emp_stage->emp_result_id = $d['emp_result_id'];
            $emp_stage->stage_id = $request->stage_id;
            $emp_stage->created_by = Auth::id();
            $emp_stage->save();
        }

        return response()->json(['status' => 200, 'data' =>[]]);
    }

    public function index(Request $request) {
        $position_id = empty($request->position_id) ? "" : " AND er.position_id IN (".implode(',', $request->position_id).")";
        $emp_level = empty($request->emp_level) ? "" : " AND e.level_id = '{$request->emp_level}'";
        $org_level = empty($request->org_level) ? "" : " AND o.level_id = '{$request->org_level}'";
        $emp_id = empty($request->emp_id) ? "" : " AND er.emp_id = '{$request->emp_id}'";
        $stage = empty($request->stage_id) ? "" : " AND er.stage_id = '{$request->stage_id}'";

        $items = DB::select("
            SELECT  erj.emp_result_judgement_id,
                    er.emp_result_id,
                    e.emp_code, 
                    e.emp_name, 
                    al.appraisal_level_name, 
                    o.org_name, 
                    p.position_name, 
                    er.status,
                    ee.emp_name result_score_name,
                    erj.adjust_result_score result_score,
                    erj.percent_adjust,
                    ast.edit_flag,
                    ast.stage_id
            FROM emp_result_judgement erj
            INNER JOIN emp_result er ON er.emp_result_id = erj.emp_result_id
            INNER JOIN employee e ON e.emp_id = er.emp_id
            INNER JOIN employee ee ON ee.emp_id = erj.judge_id
            LEFT JOIN appraisal_level al ON al.level_id = e.level_id
            LEFT JOIN org o ON o.org_id = e.org_id
            LEFT JOIN position p ON p.position_id = e.position_id
            LEFT JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
            WHERE erj.created_dttm = (
                SELECT MAX(created_dttm)
                FROM emp_result_judgement
                WHERE emp_result_judgement.emp_result_id = erj.emp_result_id
            ) AND er.period_id = '{$request->period_id}'
            AND er.org_id = '{$request->org_id}'
            ".$position_id.$emp_level.$org_level.$emp_id.$stage."

        ");

        if(empty($items)) {
            $emp_code = Auth::id();
            $items = DB::select("
                SELECT  e.emp_code, 
                        e.emp_name, 
                        al.appraisal_level_name, 
                        o.org_name, 
                        p.position_name, 
                        er.status,
                        ee.emp_name result_score_name_chief,
                        er.result_score result_score_chief,
                        '' percent_adjust,
                        (
                            SELECT emp_name
                            FROM employee WHERE emp_code = '{$emp_code}'
                        ) result_score_name,
                        er.result_score result_score,
                        ast.edit_flag,
                        ast.stage_id
                FROM emp_result er
                INNER JOIN employee e ON e.emp_id = er.emp_id
                LEFT JOIN employee ee ON ee.emp_id = er.chief_emp_id
                LEFT JOIN appraisal_level al ON al.level_id = e.level_id
                LEFT JOIN org o ON o.org_id = e.org_id
                LEFT JOIN position p ON p.position_id = e.position_id
                LEFT JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
                AND er.period_id = '{$request->period_id}'
                AND er.org_id = '{$request->org_id}'
                ".$position_id.$emp_level.$org_level.$emp_id.$stage."
            ");
        } else {
            foreach ($items as $key1 => $value1) {
                $items2 = DB::select("
                    SELECT  er.emp_result_id,
                            ee.emp_name result_score_name_chief,
                            erj.adjust_result_score result_score_chief
                    FROM emp_result_judgement erj
                    INNER JOIN emp_result er ON er.emp_result_id = erj.emp_result_id
                    INNER JOIN employee e ON e.emp_id = er.emp_id
                    INNER JOIN employee ee ON ee.emp_id = erj.judge_id
                    LEFT JOIN appraisal_level al ON al.level_id = e.level_id
                    LEFT JOIN org o ON o.org_id = e.org_id
                    LEFT JOIN position p ON p.position_id = e.position_id
                    LEFT JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
                    WHERE erj.created_dttm = (
                        SELECT MAX(created_dttm)
                        FROM emp_result_judgement
                        WHERE emp_result_judgement.emp_result_id = erj.emp_result_id
                    ) AND erj.emp_result_id = {$value1->emp_result_id}
                    AND erj.emp_result_judgement_id != {$value1->emp_result_judgement_id}
                    AND er.period_id = '{$request->period_id}'
                    AND er.org_id = '{$request->org_id}'
                    ".$position_id.$emp_level.$org_level.$emp_id.$stage."
                ");
                
                if(empty($items2)) {
                    $items2 = DB::select("
                        SELECT  ee.emp_name result_score_name_chief,
                                er.result_score result_score_chief
                        FROM emp_result er
                        INNER JOIN employee e ON e.emp_id = er.emp_id
                        LEFT JOIN employee ee ON ee.emp_id = er.chief_emp_id
                        LEFT JOIN appraisal_level al ON al.level_id = e.level_id
                        LEFT JOIN org o ON o.org_id = e.org_id
                        LEFT JOIN position p ON p.position_id = e.position_id
                        LEFT JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
                        AND er.period_id = '{$request->period_id}'
                        AND er.org_id = '{$request->org_id}'
                        ".$position_id.$emp_level.$org_level.$emp_id.$stage."
                    ");
                }

                $items[$key]->result_score_name_chief = $items2[0]->result_score_name_chief;
                $items[$key]->result_score_chief = $items2[0]->result_score_chief;
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

    public function to_action($stage_id) {
        $stage = DB::table("appraisal_stage")
        ->select("to_stage_id")
        ->where("stage_id", $stage_id)
        ->where("level_id", $this->empAuth()->level_id)
        ->where("emp_result_judgement_flag", 1)
        ->first();

        $stage = empty($stage['to_stage_id']) || $stage == 'null' ? "''" : $stage['to_stage_id'];

        $to_action = DB::select("
            SELECT stage_id, to_action
            FROM appraisal_stage
            WHERE emp_result_judgement_flag = 1
            AND stage_id IN ({$stage})
        ");

        return response()->json($to_action);
    }
}
