<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;

use App\Employee;
use App\EmpResult;
use App\AppraisalStage;
use App\EmpResultStage;
use App\EmpResultJudgement;

use Validator;
use DB;
use Auth;
use File;
use Excel;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Bonus\AdvanceSearchController;

class SalaryAdjustmentController extends Controller
{

    public function __construct()
    {
        $this->middleware('jwt.auth');
        $this->advanSearch = new AdvanceSearchController;
    }

    public function period_list(Request $request)
    {
        $period = DB::select("
            SELECT period_id
            , appraisal_period_desc
            , is_raise
            FROM appraisal_period
            WHERE is_raise = 1
            AND appraisal_year = ".$request->appraisal_year."
            ORDER BY period_id ASC");

        return response()->json($period);
    }

    public function form_list()
    {
        $form = DB::select("
            SELECT appraisal_form_id
            , appraisal_form_name
            , is_raise
            FROM appraisal_form
            WHERE is_raise = 1
			      AND is_active = 1
            ORDER BY appraisal_form_id ASC");

        return response()->json($form);
    }

    public function index(Request $request)
    {
        $datas = $this->show_salary($request);
        $items = $datas['datas'];
        $avg = $datas['avg'];
        $sd = $datas['sd'];

        // สร้าง group เพื่อให้สามารถรวมข้อมูลได้
        $groups = array();
        foreach ($items as $i) {
          $key = $i->num;
        	if (!isset($groups[$key])) {
        		$groups[$key] = array(
        			'items' => array($i),
        			'sum_total_now_salary' => $i->total_now_salary,
              'sum_salary' => $i->salary,
              'sum_pqpi_amount' => $i->pqpi_amount,
              'sum_new_salary' => $i->new_salary,
              'sum_new_pqpi_amount' => $i->new_pqpi_amount,
              'sum_fix_other_amount' => $i->fix_other_amount,
              'sum_mpi_amount' => $i->mpi_amount,
              'sum_pi_amount' => $i->pi_amount,
              'sum_var_other_amount' => $i->var_other_amount,
              'sum_cal_standard' => $i->cal_standard,
        			'count' => 1,
              'avg' => $avg,
              'sd' => $sd,
              'is_board' => $i->is_board,
              'edit_flag' => $i->edit_flag,
        		);
        	} else {
        		$groups[$key]['items'][] = $i;
            $groups[$key]['sum_total_now_salary'] += $i->total_now_salary;
            $groups[$key]['sum_salary'] += $i->salary;
            $groups[$key]['sum_pqpi_amount'] += $i->pqpi_amount;
            $groups[$key]['sum_new_salary'] += $i->new_salary;
            $groups[$key]['sum_new_pqpi_amount'] += $i->new_pqpi_amount;
            $groups[$key]['sum_fix_other_amount'] += $i->fix_other_amount;
            $groups[$key]['sum_mpi_amount'] += $i->mpi_amount;
            $groups[$key]['sum_pi_amount'] += $i->pi_amount;
            $groups[$key]['sum_var_other_amount'] += $i->var_other_amount;
            $groups[$key]['sum_cal_standard'] += $i->cal_standard;
        		$groups[$key]['count'] += 1;
            $groups[$key]['avg'] = $avg;
            $groups[$key]['sd'] = $sd;
            $groups[$key]['is_board'] = $i->is_board;
            $groups[$key]['edit_flag'] = $i->edit_flag;
        	}
        }

        return response()->json($groups);
    }


    public function show_salary(Request $request)
    {
        // หา level ที่เป็นระดับ bu และ coo และ board
        $level_bu_coo_board = "
            SELECT l.level_id as level_bu
    				, le.level_id as level_coo
            , le.parent_id as level_board
    				FROM appraisal_level l
    				LEFT JOIN appraisal_level le ON l.parent_id = le.level_id
    				WHERE l.is_start_cal_bonus = 1";

        // หาค่า score ของระดับ bu, coo และ board (ตาม level)
        $score_bu_coo_board = "
            SELECT result.emp_result_id
            , max(result.score_bu) as score_bu
            , max(result.score_coo) as score_coo
            , max(result.score_board) as score_board
            FROM
            (
            		SELECT emrj.org_level_id
            		, emrj.emp_result_id
            		, emrj.adjust_result_score
            		, (CASE WHEN emrj.org_level_id = l.level_bu THEN emrj.adjust_result_score ELSE NULL END) as score_bu
            		, (CASE WHEN emrj.org_level_id = l.level_coo THEN emrj.adjust_result_score ELSE NULL END) as score_coo
                , (CASE WHEN emrj.org_level_id = l.level_board THEN emrj.adjust_result_score ELSE NULL END) as score_board
            		FROM emp_result_judgement emrj
                INNER JOIN (SELECT org_level_id, emp_result_id, max(created_dttm) as max_dttm
  									FROM emp_result_judgement
  									GROUP BY org_level_id, emp_result_id) dttm ON dttm.org_level_id = emrj.org_level_id
  									AND dttm.emp_result_id = emrj.emp_result_id
  									AND dttm.max_dttm = emrj.created_dttm
            		INNER JOIN (".$level_bu_coo_board.") l ON emrj.org_level_id = l.level_bu
            		OR emrj.org_level_id = l.level_coo
                OR emrj.org_level_id = l.level_board
            ) result
            GROUP BY result.emp_result_id ";

        // หาค่า grade ตาม score ของ user
        $grade_score_user = "
          SELECT grade
          FROM appraisal_grade
          WHERE appraisal_form_id = ?
          AND appraisal_level_id = ?
          AND ? BETWEEN begin_score AND end_score";

        // ข้อมูลล่าสุดของตาราง emp_result_judgement พร้อมด้วยเกรด
        $grade_score = "
            SELECT emrj.emp_result_id
            , emrj.adjust_result_score
            , emp.appraisal_form_id
            , emp.level_id
            , gr.grade
            FROM emp_result_judgement emrj
            INNER JOIN (SELECT emp_result_id, max(created_dttm) as max_dttm
            	FROM emp_result_judgement
            	GROUP BY emp_result_id
            	) dttm ON dttm.emp_result_id = emrj.emp_result_id
            	AND dttm.max_dttm = emrj.created_dttm
            INNER JOIN emp_result emp ON emp.emp_result_id = emrj.emp_result_id
            LEFT JOIN appraisal_grade gr ON gr.appraisal_form_id = emp.appraisal_form_id
            	AND gr.appraisal_level_id = emp.level_id
            	AND emrj.adjust_result_score BETWEEN gr.begin_score and gr.end_score";

        // ค่าล่าสุดที่มีการ adjust ใน emp_result_judgement
        $last_score_adjust = "
            SELECT emrj.emp_result_id
            , emrj.adjust_result_score
            FROM emp_result_judgement emrj
            INNER JOIN (SELECT emp_result_id, max(created_dttm) as max_dttm
            	FROM emp_result_judgement
            	GROUP BY emp_result_id
            	) dttm ON dttm.emp_result_id = emrj.emp_result_id
            	AND dttm.max_dttm = emrj.created_dttm
            INNER JOIN emp_result emp ON emp.emp_result_id = emrj.emp_result_id";


        // หา org ภายใต้ user และดูว่า user คือ board หรือไม่? [กำหนด board : edit_flag = 1 นอกนั้นเป็น 0]
        $login = Auth::id();
        $user = DB::select("
            SELECT (CASE WHEN o.level_id = le.level_board THEN 1 ELSE 0 END) as is_board
            , em.level_id
            , em.org_id
            , o.org_code
            , GetAllUnderOrg(o.org_code) as all_under_org
            FROM employee em
            INNER JOIN org o ON em.org_id = o.org_id
            CROSS JOIN (".$level_bu_coo_board.") le
            WHERE em.emp_code = '".$login."'");

        // หาข้อมูลส่วนที่เหลือทั้งหมด
        $main_data = "
            SELECT (1) as num
            , emp.emp_result_id
            , emp.level_id
            , emp.period_id
            , em.emp_id
            , em.emp_code
            , em.emp_name
            , po.position_id
            , po.position_name
            , le.appraisal_level_name as PG
            , o.org_code
            , o.org_name
            , fo.appraisal_form_id
            , fo.appraisal_form_name
            , fo.is_job_evaluation
            , po.job_code
            , po.position_code
            , emp.knowledge_point
            , emp.capability_point
            , emp.total_point
            , emp.baht_per_point
            , emp.result_score as score_manager
            , emj.score_bu
            , emj.score_coo
            , emj.score_board
            , lsa.adjust_result_score as last_score_adjust
            , from_base64(emp.s_amount) as salary
            , from_base64(emp.pqpi_amount) as pqpi_amount
            , from_base64(emp.fix_other_amount) as fix_other_amount
            , from_base64(emp.mpi_amount) as mpi_amount
            , from_base64(emp.pi_amount) as pi_amount
            , from_base64(emp.var_other_amount) as var_other_amount
            , from_base64(emp.adjust_new_s_amount) as new_salary
            , from_base64(emp.adjust_new_pqpi_amount) as new_pqpi_amount
            , (CASE WHEN fo.is_job_evaluation = 1 THEN emp.raise_amount ELSE 0 END) as cal_standard
            -- , emp.raise_amount as cal_standard
            , pe.appraisal_year
            , ast.edit_flag
            , emp.adjust_raise_s_amount
            , emp.adjust_raise_pqpi_amount
            FROM emp_result emp
            LEFT JOIN employee em ON emp.emp_id = em.emp_id
            LEFT JOIN position po ON emp.position_id = po.position_id
            LEFT JOIN appraisal_level le ON emp.level_id = le.level_id
            LEFT JOIN org o ON emp.org_id = o.org_id
            LEFT JOIN appraisal_form fo ON emp.appraisal_form_id = fo.appraisal_form_id
            LEFT JOIN appraisal_period pe ON emp.period_id = pe.period_id
            LEFT JOIN (".$score_bu_coo_board.") emj ON emj.emp_result_id = emp.emp_result_id
            LEFT JOIN (".$last_score_adjust.") lsa ON lsa.emp_result_id = emp.emp_result_id
            INNER JOIN appraisal_stage ast ON ast.stage_id = emp.stage_id
            WHERE emp.appraisal_type_id = 2";
            //LEFT JOIN (".$grade_score.") gr ON gr.emp_result_id = emp.emp_result_id || [gr.grade, gr.adjust_result_score as score_for_grade]


        //------------------------ เอามาจาก BonusAdjustmentController ------------------------------------//
        // set parameter for query
        $request->period_id = empty($request->period_id) ? "": $request->period_id;
        $gue_emp_level = empty($request->emp_level) ? '' : $this->advanSearch->GetallUnderLevel($request->emp_level);
        $gue_org_level = empty($request->org_level) ? '' : $this->advanSearch->GetallUnderLevel($request->org_level);
        $gueOrgCodeByEmpId = empty($request->emp_id) ? '' : $this->advanSearch->GetallUnderEmpByOrg($request->emp_id);
        $gueOrgCodeByOrgId = empty($request->org_id) ? '' : $this->advanSearch->GetallUnderOrgByOrg($request->org_id);

        $qryEmpLevel = empty($gue_emp_level) && empty($request->emp_level) ? "" : " AND (emp.level_id = '{$request->emp_level}' OR find_in_set(emp.level_id, '{$gue_emp_level}'))";
        $qryOrgLevel = empty($gue_org_level) && empty($request->org_level) ? "" : " AND (o.level_id = '{$request->org_level}' OR find_in_set(o.level_id, '{$gue_org_level}'))";
        $qryEmpId = empty($gueOrgCodeByEmpId) && empty($request->emp_id) ? "" : " AND (emp.emp_id = '{$request->emp_id}' OR find_in_set(o.org_code, '{$gueOrgCodeByEmpId}'))";

        $all_emp = $this->advanSearch->isAll();
        $employee = Employee::find(Auth::id());
        if ($all_emp[0]->count_no > 0) {
            if(empty($request->org_id)) {
                $qryOrgId = "";
            } else {
                $qryOrgId = "AND (emp.org_id = '{$request->org_id}' OR find_in_set(o.org_code, '{$gueOrgCodeByOrgId}'))";
            }
        } else {
            if(empty($request->org_id)) {
                $gueOrgCodeByOrgId = $this->advanSearch->GetallUnderOrgByOrg($employee->org_id);
                $qryOrgId = "AND find_in_set(o.org_code, '{$gueOrgCodeByOrgId}')";
            } else {
                $qryOrgId = "AND (emp.org_id = '{$request->org_id}' OR find_in_set(o.org_code, '{$gueOrgCodeByOrgId}'))";
            }
        }

        $request->position_id = in_array('null', $request->position_id) ? "" : $request->position_id;
        $qryPositionId = empty($request->position_id) ? "" : " AND emp.position_id IN (".implode(',', $request->position_id).")";

        $qryStageId = empty($request->stage_id) ? "": " AND emp.stage_id = '{$request->stage_id}'";

        $request->appraisal_form_id = in_array('null', $request->appraisal_form_id) ? "" : $request->appraisal_form_id;
        $qryFormId = empty($request->appraisal_form_id) ? "" : " AND emp.appraisal_form_id IN (".implode(',', $request->appraisal_form_id).")";
        //------------------------ จบส่วนที่เอามาจาก BonusAdjustmentController ------------------------------------//

        // select ข้อมูล
        $item = DB::select(
          $main_data."
          AND emp.period_id = ".$request->period_id."
          ".$qryFormId."
          ".$qryEmpLevel."
          ".$qryOrgLevel."
          ".$qryOrgId."
          ".$qryEmpId."
          ".$qryPositionId."
          ".$qryStageId."
          ORDER BY o.org_code ASC
          , le.seq_no ASC
          , em.emp_code ASC");

        // structure ทั้งหมดที่จะต้องแสดงตาม parameter ทั้งหมด [แสดงเฉพาะที่มีใน emp_result]
        $Structure = "
            SELECT aps.structure_id
            , aps.structure_name
            , aps.seq_no
            FROM emp_result emp
            INNER JOIN structure_result str ON str.emp_result_id = emp.emp_result_id
            INNER JOIN appraisal_structure aps ON str.structure_id = aps.structure_id
            LEFT JOIN org o ON emp.org_id = o.org_id
            WHERE emp.period_id = ".$request->period_id."
            ".$qryFormId."
            ".$qryEmpLevel."
            ".$qryOrgLevel."
            ".$qryOrgId."
            ".$qryEmpId."
            ".$qryPositionId."
            ".$qryStageId."
            GROUP BY aps.structure_id
            , aps.structure_name
            , aps.seq_no";

        // คะแนนเต็มของแต่ละ structure ตาม emp_result
        $total_score = "
            SELECT max(air.weight_percent) as weight_percent
            , emp.emp_result_id
            , aps.structure_id
            FROM emp_result emp
            INNER JOIN appraisal_item_result air ON emp.emp_result_id = air.emp_result_id
            INNER JOIN appraisal_item ai ON air.item_id = ai.item_id
            INNER JOIN appraisal_structure aps ON ai.structure_id = aps.structure_id
            GROUP BY emp.emp_result_id
            , aps.structure_id ";

        // คำนวนค่า avg
        $item = collect($item);
        $avg = $item->avg('last_score_adjust');

        // เก็บค่า adjust ล่าสุดเพื่อนำไปคำนวนหาค่า std
        $score_last = [];
        foreach ($item as $i) {
          array_push($score_last, $i->last_score_adjust);
        }

        // คำนวนค่า std ด้วย function จาก EmpResultJudgementController
        $std = empty($score_last) ? 0 : $this->advanSearch->standard_deviation($score_last);
        $item = $item->toArray();

        //หาค่า structure, structure แรก by record และคำนวณรายได้ปัจจุบัน
        foreach ($item as $i) {
           // คำนวณรายได้ปัจจุบัน
           $i->total_now_salary = ($i->salary+$i->pqpi_amount+$i->fix_other_amount+$i->mpi_amount+$i->pi_amount+$i->var_other_amount);

           //หาค่า structure และ total structure by record
           $Structure_result = DB::select("
              SELECT result.structure_id
              , result.structure_name
              , result.seq_no
              , COALESCE(sr.weigh_score,0) as score
              , COALESCE(ts.weight_percent,0) as total_score
              FROM (".$Structure.") result
              LEFT JOIN structure_result sr ON sr.structure_id = result.structure_id
              	AND sr.emp_result_id = ".$i->emp_result_id."
                AND sr.emp_id = ".$i->emp_id."
              	AND sr.period_id = ".$i->period_id."
              LEFT JOIN (".$total_score.") ts ON ts.structure_id = result.structure_id
                AND ts.emp_result_id = ".$i->emp_result_id."
              ORDER BY result.seq_no ASC ");

           // หา structure แรก by record
           $first_structure = DB::select("
              SELECT aps.structure_id
              , aps.structure_name
              , aps.seq_no
              FROM emp_result emp
              INNER JOIN structure_result str ON str.emp_result_id = emp.emp_result_id
              INNER JOIN appraisal_structure aps ON str.structure_id = aps.structure_id
              WHERE emp.emp_result_id = ".$i->emp_result_id."
              GROUP BY aps.structure_id
              , aps.structure_name
              , aps.seq_no
              ORDER BY aps.seq_no ASC
              LIMIT 1 ");

          //หา grade ตาม level, from by record
          $cal_grade = DB::select("
              SELECT grade_id
              , grade
              , begin_score
              , end_score
              FROM appraisal_grade
              WHERE appraisal_form_id = ".$i->appraisal_form_id."
              AND appraisal_level_id = ".$i->level_id."
              ORDER BY begin_score ASC");

          /*
          [หน้านี้เข้าใช้งานได้เพียงแค่ board, coo]
          is_board = 1 : user ที่เข้าสู่ระบบเป็น board,
          is_board = 0 : user ที่เข้าสู่ระบบเป็น coo
          */
          foreach ($user as $u) {
            $i->is_board = $u->is_board;
          }

          // คำนวนเกรดตามคะแนนของแต่ละ user ที่เข้าสู่ระบบ
          if ($i->is_board == 0){   // user : coo
            $i->score_board = 0;
            $grade = DB::select($grade_score_user, array($i->appraisal_form_id, $i->level_id, $i->score_coo));
          }else if ($i->is_board == 1){   // user : board
            $grade = DB::select($grade_score_user, array($i->appraisal_form_id, $i->level_id, $i->score_board));
          }

          // คำนวนค่า z-score by record
          if($std==0) {
              $i->z_score = 0;
          } else {
              $i->z_score = ($i->last_score_adjust-$avg) / $std;
          }

          // insert date into $item
          if($grade){
            foreach ($grade as $g) {
              $i->grade = $g->grade;
            }
          }else {
            $i->grade = "";
          }

          if($first_structure){
            foreach ($first_structure as $first) {
               $i->first_structure_id = $first->structure_id;
            }
          }else {
            $i->first_structure_id = "";
          }

          $i->structure_result = $Structure_result;
          $i->cal_grade = $cal_grade;

        } // end foreach item

        return ['datas' => $item, 'avg' => $avg, 'sd' => $std];


        //-------------------------ส่วนของแบ่งหน้า (ไม่ต้องแบ่งหน้า ให้แสดงทั้งหมดในหน้าเดียว)------------------
        /*
        // Get the current page from the url if it's not set default to 1
    		empty($request->page) ? $page = 1 : $page = $request->page;

        // Number of items per page
        if($request->rpp == 'All' || $request->rpp == 'all') {
            $perPage = (empty($groups)) ? 10 : $groups[$key]['count'];
        } else {
          	empty($request->rpp) ? $perPage = 10 : $perPage = $request->rpp;
        }

    		$offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

    		// Get only the items you need using array_slice (only get 10 items since that's what you need)
    		$itemsForCurrentPage = array_slice($groups, $offSet, $perPage, false);

    		// Return the paginator with only 10 items but with the count of all items and set the it on the correct page
      	$result = new LengthAwarePaginator($itemsForCurrentPage, count($groups), $perPage, $page);
        */

    }

    public function update(Request $request) {
        DB::beginTransaction();
        $errors = [];
        $errors_validator = [];

        // search [emp_id => judge_id , level_id => org_level_id] for emp_result_judgement
        $user = DB::table('employee')
              ->join('org', 'employee.org_id', '=', 'org.org_id')
              ->select('employee.emp_id', 'org.level_id')
              ->where('employee.emp_code', '=', Auth::id())
              ->first();

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
                'emp_id' => $d['emp_id'],
                'salary' => $d['salary'],
                'pqpi' => $d['pqpi']
            ], [
                'emp_result_id' => 'required|integer',
                'emp_id' => 'required|integer',
                'salary' => 'required|numeric',
                'pqpi' => 'required|numeric'
            ]);

            if($validator_detail->fails()) {
                $errors_validator[] = $validator_detail->errors();
            }
        }

        if(!empty($errors_validator)) {
            return response()->json(['status' => 400, 'data' => $errors_validator]);
        }

        $stage = AppraisalStage::find($request->stage_id);

        foreach ($request['detail'] as $d) {
            $emp = EmpResult::find($d['emp_result_id']);
            $emp->adjust_raise_s_amount = $d['salary'];
            $emp->adjust_raise_pqpi_amount = $d['pqpi'];

            $sum_s_amount = (int)base64_decode($emp->s_amount) + (int)$d['salary'];
            $sum_pqpi_amount = (int)base64_decode($emp->pqpi_amount) + (int)$d['pqpi'];

            $emp->adjust_new_s_amount = base64_encode($sum_s_amount);
            $emp->adjust_new_pqpi_amount = base64_encode($sum_pqpi_amount);

            if($request->stage_id != 999 && $request->calculate_flag == 0) { //stage_id is 999 not update stage
                $emp->stage_id = $request->stage_id;
                $emp->status = $stage->status;
            }

            $emp->updated_by = Auth::id();

            try {
                $emp->save();
            } catch (Exception $em) {
                $errors[] = substr($em, 254);
            }

            // update grade
            $empJust = EmpResultJudgement::where('emp_result_id', '=',  $d['emp_result_id'])
                      ->where('judge_id', '=', $user->emp_id)
                      ->where('org_level_id', '=', $user->level_id)
                      ->update([
                        'adjust_grade' => $d['grade']
                      ]);

            if($request->stage_id != 999 && $request->calculate_flag == 0) { //stage_id is 999 not update stage
                if($stage->final_salary_flag==1) {
                    try {
                        Employee::where('emp_id', '=', $d['emp_id'])->update([
                            's_amount' => $emp->adjust_new_s_amount,
                            'pqpi_amount' => $emp->adjust_new_pqpi_amount,
                            'updated_by' => Auth::id()
                        ]);
                    } catch (Exception $el) {
                        $errors[] = substr($el, 254);
                    }
                }

                $emp_stage = new EmpResultStage;
                $emp_stage->emp_result_id = $d['emp_result_id'];
                $emp_stage->stage_id = $request->stage_id;
                $emp_stage->created_by = Auth::id();
                $emp_stage->updated_by = Auth::id();
                try {
                    $emp_stage->save();
                } catch (Exception $et) {
                    $errors[] = substr($et, 254);
                }
            }
        }

        if(empty($errors)) {
            $status = 200;
            DB::commit();
        } else {
            $status = 400;
            DB::rollback();
        }

        return response()->json(['status' => $status, 'data' => $errors]);
    }

    /*
    public function export(Request $request)
    {
        $datas = $this->show_salary($request);
        $items = $datas['datas'];

        $adjustUser = "";
        $empAdjust = "";
        foreach ($items as $i) {
            if ($i->is_board == 1){               // user board
              $i->score_adjust = $i->score_board;
              $adjustUser = "คะแนนประเมิน Board";
              $empAdjust = array('คะแนนประเมิน Mgr.', 'คะแนนประเมิน BU.', 'คะแนนประเมิน COO.');
            }else if ($i->is_board == 0){         // user coo
              $i->score_adjust = $i->score_coo;
              $adjustUser = "คะแนนประเมิน COO.";
              $empAdjust = array('คะแนนประเมิน Mgr.', 'คะแนนประเมิน BU.');
            }

            // คำนวนค่าในส่วนของ % (90%, 65%, 25%) [ตรวจสอบ is_job_evaluation ด้วย]
            $i->income = (($i->total_point*$i->score_adjust)/100)*$i->baht_per_point;
            $i->income_total = ($i->is_job_evaluation == 1) ? round(($i->income*(90/100)),-2) : 0;
            $i->income_fix = ($i->is_job_evaluation == 1) ? round(($i->income*(65/100)),-2) : 0;
            $i->income_var = ($i->is_job_evaluation == 1) ? round(($i->income*(25/100)),-2) : 0;

            // คำนวนค่า ขาด/เกิน (fix)
            $i->miss_over = ($i->salary+$i->pqpi_amount+$i->fix_other_amount)-($i->income_fix);

        }

        $structure_name = collect($items)->first();
        $structure_name = $structure_name->structure_result;
        $nameRow = [];
        foreach ($structure_name as $k) {
          array_push($nameRow, $k->structure_name);
        }

        $headRow = array('ชื่อ - สกุล', 'ฝ่าย', 'Z-score', $adjustUser, 'เกรด', 'Cal Standard', 'ขาด/เกิน (Fix)'
        , 'รายได้รวมที่ควรได้ 90% ไม่รวม Bonus', 'รายได้ Fix ที่ควรได้ 65%', 'รายได้ Var ที่ควรได้ 25%'
        , 'รายได้ปัจจุบัน Total', 'Salary', 'P-QPI', 'อื่นๆ', 'MPI', 'PI', 'อื่นๆ');

        $evaluationColumn = array('คะแนนเต็มตีค่างาน (ความรู้)', 'คะแนนเต็มตีค่างาน (ศักยภาพ)', 'Total Point', 'Baht/Point');
        $headColumn = array_merge($headRow, $nameRow, $empAdjust, $evaluationColumn);
        //return $headColumn;

        $filename = "salary_adjustment";
    		$x = Excel::create($filename, function($excel) use($items, $filename, $headColumn, $request) {
    			$excel->sheet($filename, function($sheet) use($items, $headColumn, $request) {

    				$sheet->appendRow($headColumn);

    				foreach ($items as $i) {

              // ค่า score ของแต่ละ structure by record
              $structure = collect($i->structure_result);
              $scoreStruc = [];
              foreach ($structure as $st) {
                array_push($scoreStruc, $st->score);
              }

              // ค่า adjust ตาม user by record
              $adjustScore = [];
              if ($i->is_board == 1){            // user board
                $adjustScore = array($i->score_manager, $i->score_bu, $i->score_coo);
              }else if ($i->is_board == 0){      // user coo
                $adjustScore = array($i->score_manager, $i->score_bu);
              }

    					$sheet->appendRow(array_merge(
                array(
      						$i->emp_name,
                  $i->org_name,
                  $i->z_score,
                  $i->score_adjust,
                  $i->grade,
                  $i->cal_standard,
                  $i->miss_over,
                  $i->income_total,
                  $i->income_fix,
                  $i->income_var,
                  $i->total_now_salary,
                  $i->salary,
                  $i->pqpi_amount,
                  $i->fix_other_amount,
                  $i->mpi_amount,
                  $i->pi_amount,
                  $i->var_other_amount
    					  ),$scoreStruc
                ,$adjustScore
                ,array(
                  $i->knowledge_point,
                  $i->capability_point,
                  $i->total_point,
                  $i->baht_per_point
                )
             ));

    				}

    			});
    		})->export('xlsx');

    }
    */

}
