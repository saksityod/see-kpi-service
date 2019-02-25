<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use DB;
use Auth;
use DateTime;
use App\Employee;
use App\EmpResult;
use App\AppraisalStage;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Http\Controllers\Bonus\AdvanceSearchController;

class MPIJudgementController extends Controller
{

    public function __construct()
    {
       $this->middleware('jwt.auth');
       $this->advanSearch = new AdvanceSearchController;
    }

    public function form_list()
    {
        $form = DB::select("
          SELECT appraisal_form_id
          , appraisal_form_name
          , is_mpi
          FROM appraisal_form
          WHERE is_mpi = 1
          ORDER BY appraisal_form_id ASC");

        return response()->json($form);
    }

    public function index(Request $request)
    {

        //------------------------ เอามาจาก BonusAdjustmentController ------------------------------------//
        // set parameter for query
        $request->period_id = empty($request->period_id) ? "": $request->period_id;
        $gue_emp_level = empty($request->emp_level) ? '' : $this->advanSearch->GetallUnderLevel($request->emp_level);
        $gue_org_level = empty($request->org_level) ? '' : $this->advanSearch->GetallUnderLevel($request->org_level);
        $gueOrgCodeByEmpId = empty($request->emp_id) ? '' : $this->advanSearch->GetallUnderEmpByOrg($request->emp_id);
        $gueOrgCodeByOrgId = empty($request->org_id) ? '' : $this->advanSearch->GetallUnderOrgByOrg($request->org_id);

        $qryEmpLevel = empty($gue_emp_level) && empty($request->emp_level) ? "" : " AND (er.level_id = '{$request->emp_level}' OR find_in_set(er.level_id, '{$gue_emp_level}'))";
        $qryOrgLevel = empty($gue_org_level) && empty($request->org_level) ? "" : " AND (org.level_id = '{$request->org_level}' OR find_in_set(org.level_id, '{$gue_org_level}'))";
        $qryEmpId = empty($gueOrgCodeByEmpId) && empty($request->emp_id) ? "" : " AND (er.emp_id = '{$request->emp_id}' OR find_in_set(org.org_code, '{$gueOrgCodeByEmpId}'))";

        $all_emp = $this->advanSearch->isAll();
        $employee = Employee::find(Auth::id());
        if ($all_emp[0]->count_no > 0) {
            if(empty($request->org_id)) {
                $qryOrgId = "";
            } else {
                $qryOrgId = "AND (er.org_id = '{$request->org_id}' OR find_in_set(org.org_code, '{$gueOrgCodeByOrgId}'))";
            }
        } else {
            if(empty($request->org_id)) {
                $gueOrgCodeByOrgId = $this->advanSearch->GetallUnderOrgByOrg($employee->org_id);
                $qryOrgId = "AND find_in_set(org.org_code, '{$gueOrgCodeByOrgId}')";
            } else {
                $qryOrgId = "AND (er.org_id = '{$request->org_id}' OR find_in_set(org.org_code, '{$gueOrgCodeByOrgId}'))";
            }
        }

        $request->position_id = in_array('null', $request->position_id) ? "" : $request->position_id;
        $qryPositionId = empty($request->position_id) ? "" : " AND er.position_id IN (".implode(',', $request->position_id).")";
        $qryStageId = empty($request->stage_id) ? "": " AND er.stage_id = '{$request->stage_id}'";
        $qryFormId = empty($request->appraisal_form) ? "": " AND er.appraisal_form_id = '{$request->appraisal_form}'";
        //------------------------ จบส่วนที่เอามาจาก BonusAdjustmentController ------------------------------------//

        // หา level ที่เป็นระดับ bu และ coo และ board
        $level_bu_coo_board = "
          SELECT l.level_id as level_bu
          , le.level_id as level_coo
          , le.parent_id as level_board
          FROM appraisal_level l
          LEFT JOIN appraisal_level le ON l.parent_id = le.level_id
          WHERE l.is_start_cal_bonus = 1";

        // หาค่า score, grade ของระดับ bu และ coo (ตาม level)
        $score_bu_coo = "
          SELECT result.emp_result_id
          , max(result.score_bu) as score_bu
          , max(result.score_coo) as score_coo
          , er.appraisal_form_id
					, er.level_id
					, ( SELECT g.grade
						FROM appraisal_grade g
						WHERE g.appraisal_form_id = er.appraisal_form_id
						AND g.appraisal_level_id = er.level_id
						AND max(result.score_bu) BETWEEN g.begin_score and g.end_score ) as grade_bu
					, ( SELECT g.grade
						FROM appraisal_grade g
						WHERE g.appraisal_form_id = er.appraisal_form_id
						AND g.appraisal_level_id = er.level_id
						AND max(result.score_coo) BETWEEN g.begin_score and g.end_score ) as grade_coo
          FROM
          (
              SELECT emrj.org_level_id
              , emrj.emp_result_id
              , emrj.adjust_result_score
              , (CASE WHEN emrj.org_level_id = l.level_bu THEN emrj.adjust_result_score ELSE NULL END) as score_bu
              , (CASE WHEN emrj.org_level_id = l.level_coo THEN emrj.adjust_result_score ELSE NULL END) as score_coo
              FROM emp_result_judgement emrj
              INNER JOIN (SELECT org_level_id, emp_result_id, max(created_dttm) as max_dttm
									FROM emp_result_judgement
									GROUP BY org_level_id, emp_result_id) dttm ON dttm.org_level_id = emrj.org_level_id
									AND dttm.emp_result_id = emrj.emp_result_id
									AND dttm.max_dttm = emrj.created_dttm
              INNER JOIN (".$level_bu_coo_board.") l ON emrj.org_level_id = l.level_bu
              OR emrj.org_level_id = l.level_coo
          ) result
          INNER JOIN emp_result er ON result.emp_result_id = er.emp_result_id
          GROUP BY result.emp_result_id ";

        $main_data = "
          SELECT er.emp_result_id
          , e.emp_code
          , e.emp_name
          , er.appraisal_form_id
          , er.level_id
          , vel.appraisal_level_name
          , org.org_name
          , pos.position_name
          , er.status
          , IF(
          		IFNULL(CAST(FROM_BASE64(er.net_s_amount) AS DECIMAL(10,2)),0)=0,
          			IF(
          				IFNULL(CAST(FROM_BASE64(er.s_amount) AS DECIMAL(10,2)),0)=0,
          				CAST(FROM_BASE64(e.s_amount) AS DECIMAL(10,2)),
          				CAST(FROM_BASE64(er.s_amount) AS DECIMAL(10,2))
          			),
          		CAST(FROM_BASE64(er.net_s_amount) AS DECIMAL(10,2))
          	) AS s_amount
          , er.result_score as score_manager
          , ( SELECT g.grade
            FROM appraisal_grade g
            WHERE g.appraisal_form_id = er.appraisal_form_id
            AND g.appraisal_level_id = er.level_id
            AND er.result_score BETWEEN g.begin_score and g.end_score ) as grade_manager
          , score.score_bu
          , score.grade_bu
          , score.score_coo
          , score.grade_coo
          , sta.edit_flag
          FROM emp_result er
          INNER JOIN employee e ON e.emp_id = er.emp_id
          INNER JOIN appraisal_level vel ON vel.level_id = er.level_id
          INNER JOIN org ON org.org_id = er.org_id
          INNER JOIN position pos ON pos.position_id = er.position_id
          INNER JOIN appraisal_stage sta ON sta.stage_id = er.stage_id
          LEFT JOIN (".$score_bu_coo.") score ON score.emp_result_id = er.emp_result_id
          WHERE er.period_id = '{$request->period_id}'
          ".$qryFormId ."
          ".$qryEmpLevel."
          ".$qryOrgLevel."
          ".$qryOrgId."
          ".$qryEmpId."
          ".$qryPositionId."
          ".$qryStageId."
          ORDER BY e.org_id, er.level_id, e.emp_code";

        $item = DB::select($main_data);

        // หา org ภายใต้ user และดูว่า user คือ board หรือไม่? [กำหนด board : edit_flag = 1 นอกนั้นเป็น 0]
        $login = Auth::id();
        $user = DB::select("
            SELECT (CASE WHEN o.level_id = le.level_bu THEN 1 ELSE 0 END) as is_bu
            , (CASE WHEN o.level_id = le.level_coo THEN 1 ELSE 0 END) as is_coo
            , em.emp_id
						, o.level_id
            FROM employee em
            INNER JOIN org o ON em.org_id = o.org_id
            CROSS JOIN (".$level_bu_coo_board.") le
            WHERE em.emp_code = '".$login."'");

        foreach ($item as $i) {
            $i->is_bu = $user[0]->is_bu;
            $i->is_coo = $user[0]->is_coo;
            $i->user_emp_id = $user[0]->emp_id;
            $i->user_level_id = $user[0]->level_id;
        }

        empty($request->page) ? $page = 1 : $page = $request->page;

        if($request->rpp == 'All') {
          $perPage = count(empty($item) ? 10 : $item);
        } else {
            empty($request->rpp) ? $perPage = count(empty($item) ? 10 : $item) : $perPage = $request->rpp;
        }

        $offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

    		// Get only the items you need using array_slice (only get 10 items since that's what you need)
    		$itemsForCurrentPage = array_slice($item, $offSet, $perPage, false);

        $result = new LengthAwarePaginator($itemsForCurrentPage, count($item), $perPage, $page);
        // $dataInfo = (new BonusAppraisalController)->SetPagination($request->page, $perPage, $dataInfo);
        return response()->json($result);
    }

    public function update(Request $request)
    {

        if(empty($request['data'])) {
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

        $login = Auth::id();
        $now = new DateTime;
        $now = $now->format('Y-m-d H:i:s');

        foreach ($request->data as $data) {

          try {
              $empResult = EmpResult::findOrFail($data['emp_result_id']);
          } catch (ModelNotFoundException $e) {
              return response()->json(['status' => 400, 'data' => "emp result not found"]);
          }

          // ตรวจสอบว่าตาม key ที่ส่งมามีข้อมูลแล้วหรือยัง?
          $check_data = DB::select("
            SELECT emp_result_id
            FROM emp_result_judgement
            WHERE emp_result_id = ".$data['emp_result_id']."
            AND judge_id = ".$data['user_id']."
            AND org_level_id = ".$data['user_level_id']." ");

          // return response()->json($check_data);

          if(empty($check_data)){ // กรณีที่ไม่มีข้อมูลตาม key ให้ทำการ insert
            try {
              $insert = DB::select("
                INSERT INTO emp_result_judgement (emp_result_id, judge_id, org_level_id, adjust_result_score, created_by, created_dttm)
                VALUES (".$data['emp_result_id'].", ".$data['user_id'].", ".$data['user_level_id'].", ".$data['score'].", '".$login."', '".$now."')");
            } catch (ModelNotFoundException $e) {
              return response()->json(['status' => 400, 'data' => "insert data false."]);
            }
          } else { // กรณีที่มีข้อมูลตาม key ให้ทำการ update
            try {
              $update = DB::select("
                UPDATE emp_result_judgement
                SET adjust_result_score = ".$data['score']."
                WHERE emp_result_id = ".$data['emp_result_id']."
                AND judge_id = ".$data['user_id']."
                AND org_level_id = ".$data['user_level_id']." ");
            } catch (ModelNotFoundException $e) {
              return response()->json(['status' => 400, 'data' => "update data false."]);
            }
          }

          if($request->confirm_flag == "1"){
              $empResult->stage_id = $request->stage_id;
              $empResult->status = AppraisalStage::find($request->stage_id)->status;
          }
          $empResult->save();

        } // end foreach ($request->data)

        return response()->json(['status' => 200, 'data' => "Saved Successfully"]);
    }

}
