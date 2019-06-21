<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;

use App\Employee;
use App\EmpResult;
use App\AppraisalStage;
use App\EmpResultStage;
use App\EmpResultJudgement;
use App\AppraisalGrade;

use Validator;
use DB;
use Auth;
use File;
use Excel;
use DateTime;
use Log;
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
      $datas = $this->show_salary_v2($request);
      $items = collect($datas['datas']);

      return response()->json([
        'items' => $items,
        'sum_total_now_salary' => $items->sum('total_now_salary'),
        'sum_salary' => $items->sum('salary'),
        'sum_pqpi_amount' => $items->sum('pqpi_amount'),
        'sum_new_salary' => $items->sum('new_salary'),
        'sum_new_pqpi_amount' => $items->sum('new_pqpi_amount'),
        'sum_fix_other_amount' => $items->sum('fix_other_amount'),
        'sum_mpi_amount' => $items->sum('mpi_amount'),
        'sum_pi_amount' => $items->sum('pi_amount'),
        'sum_var_other_amount' => $items->sum('var_other_amount'),
        'sum_cal_standard' => $items->sum('cal_standard'),
        'count' => $items->count(),
        'avg' => $datas['avg'],
        'avg_filter' => $datas['avg_filter'],
        'sd' => $datas['sd'],
        'sd_filter' => $datas['std_filter'],
        'is_board' => $items->first()->is_board,
        'edit_flag' => $items->first()->edit_flag,
        'emp_cnt' => $datas['emp_cnt'],
      ]);
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
            , COALESCE(emp.result_score, 0) as score_manager
            , COALESCE(emj.score_bu, 0) as score_bu
            , COALESCE(emj.score_coo, 0) as score_coo
            , COALESCE(emj.score_board, 0) as score_board
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
            , 0 as percent_diff
            , 0 as total_now_salary_new
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
                $qryOrgId = "AND (emp.org_id = '{$employee->org_id}' OR find_in_set(o.org_code, '{$gueOrgCodeByOrgId}') )";
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

           // หาคะแนนล่าสุด by record
           if ($i->score_board != 0){
             $i->score_last = $i->score_board;
           }else if ($i->score_board == 0 && $i->score_coo != 0){
             $i->score_last = $i->score_coo;
           }else if ($i->score_board == 0 && $i->score_coo == 0 && $i->score_bu != 0){
             $i->score_last = $i->score_bu;
           }else if ($i->score_board == 0 && $i->score_coo == 0 && $i->score_bu == 0 && $i->score_manager != 0){
             $i->score_last = $i->score_manager;
           }else {
             $i->score_last = 0;
           }

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
              , raise_type
              , salary_raise_amount
              , salary_raise_percent
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

          // คำนวนเกรดตามคะแนนล่าสุดที่ใช้แสดง (ดูตั้งแต่ Mgr., BU., COO, Board
          if ($i->is_board == 0){   // user : coo
            $i->score_board = 0;
          }
		  $grade = DB::select($grade_score_user, array($i->appraisal_form_id, $i->level_id, $i->score_last));
		  

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


    public function show_salary_v2(Request $request)
    {
      $login = Auth::id();
      // หา level ที่เป็นระดับ bu และ coo และ board
      $adjustLevel = collect(DB::select("
        SELECT ifnull(l.level_id,0) as level_bu, ifnull(le.level_id,0) as level_coo, ifnull(le.parent_id,0) as level_board
    		FROM appraisal_level l
    		LEFT JOIN appraisal_level le ON l.parent_id = le.level_id
        WHERE l.is_start_cal_bonus = 1
      "))->first();

      // set parameter for query
      //------------------------ เอามาจาก BonusAdjustmentController ------------------------------------//
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
          $qryOrgId = "AND (emp.org_id = '{$employee->org_id}' OR find_in_set(o.org_code, '{$gueOrgCodeByOrgId}') )";
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

      // ดึงข้อมูลของ emp ใน period นั้นตาม parameter ที่กำหนด
      $itemList = DB::select("
        SELECT (1) as num, emp.emp_result_id, emp.level_id, emp.period_id, em.emp_id, em.emp_code
          , em.emp_name, po.position_id, po.position_name, le.appraisal_level_name as PG, o.org_code
          , o.org_name, fo.appraisal_form_id, fo.appraisal_form_name, fo.is_job_evaluation, po.job_code
          , po.position_code, emp.knowledge_point, emp.capability_point, emp.total_point, emp.baht_per_point
          , COALESCE(emp.result_score, 0) as score_manager, from_base64(emp.s_amount) as salary
          , from_base64(emp.pqpi_amount) as pqpi_amount, from_base64(emp.fix_other_amount) as fix_other_amount
          , from_base64(emp.mpi_amount) as mpi_amount, from_base64(emp.pi_amount) as pi_amount
          , from_base64(emp.var_other_amount) as var_other_amount, from_base64(emp.adjust_new_s_amount) as new_salary
          , from_base64(emp.adjust_new_pqpi_amount) as new_pqpi_amount
          , (CASE WHEN fo.is_job_evaluation = 1 THEN emp.raise_amount ELSE 0 END) as cal_standard
          , pe.appraisal_year, ast.edit_flag, emp.adjust_raise_s_amount, emp.adjust_raise_pqpi_amount
          , 0 as percent_diff, 0 as total_now_salary_new
        FROM emp_result emp
        LEFT JOIN employee em ON emp.emp_id = em.emp_id
        LEFT JOIN position po ON emp.position_id = po.position_id
        LEFT JOIN appraisal_level le ON emp.level_id = le.level_id
        LEFT JOIN org o ON emp.org_id = o.org_id
        LEFT JOIN appraisal_form fo ON emp.appraisal_form_id = fo.appraisal_form_id
        LEFT JOIN appraisal_period pe ON emp.period_id = pe.period_id
        INNER JOIN appraisal_stage ast ON ast.stage_id = emp.stage_id
        WHERE emp.appraisal_type_id = 2
        AND emp.period_id = {$request->period_id}
        {$qryFormId}
        {$qryEmpLevel}
        {$qryOrgLevel}
        {$qryOrgId}
        {$qryEmpId}
        {$qryPositionId}
        {$qryStageId}
        ORDER BY o.org_code ASC, le.seq_no ASC, em.emp_code ASC
        LIMIT 10
      ");
      
      // หาค่า score ที่ถูก adjust ด้วยตาม level(bu,coo,board) และหา last score ทั้งหมดที่อยู่ใน period นั้น ๆ 
      $scoreList = collect(DB::select("
        SELECT er.emp_result_id,
          IFNULL(MAX(ds.score_by_bu), 0) AS score_bu, 
          IFNULL(MAX(ds.score_by_coo), 0) AS score_coo, 
          IFNULL(MAX(ds.score_by_board), 0) AS score_board,
          IFNULL(IF( MAX(ds.score_by_board) != 0, 
            MAX(ds.score_by_board),
              IF( MAX(ds.score_by_coo) != 0,
                MAX(ds.score_by_coo),
                MAX(ds.score_by_bu)
              )
          ), 0) AS last_score
        FROM emp_result er
        LEFT OUTER JOIN(
          SELECT erj.emp_result_id,
            IF(erj.org_level_id = {$adjustLevel->level_bu}, erj.adjust_result_score, 0) AS score_by_bu,
            IF(erj.org_level_id = {$adjustLevel->level_coo}, erj.adjust_result_score, 0) AS score_by_coo,
            IF(erj.org_level_id = {$adjustLevel->level_board}, erj.adjust_result_score, 0) AS score_by_board
          FROM emp_result_judgement erj
          INNER JOIN (
            SELECT  mac.emp_result_id, mac.org_level_id, MAX(mac.created_dttm) max_date 
            FROM emp_result_judgement mac
            GROUP BY mac.emp_result_id, mac.org_level_id
          ) mac ON mac.emp_result_id = erj.emp_result_id AND mac.org_level_id = erj.org_level_id AND mac.max_date = erj.created_dttm
          INNER JOIN emp_result emac ON emac.emp_result_id = mac.emp_result_id
          WHERE emac.period_id = {$request->period_id}
        )ds ON er.emp_result_id = ds.emp_result_id
        WHERE er.period_id = {$request->period_id}
        GROUP BY er.emp_result_id
      "));
        
      // หาค่า avg และ std ของ employee ทั้งหมดที่อยู่ใน period นั้น ๆ
      $avg = $scoreList->avg('last_score');
      $lastAdjScoreArr = $scoreList->pluck('last_score')->toArray();
      $std = empty($lastAdjScoreArr) ? 0 : $this->advanSearch->standard_deviation($lastAdjScoreArr);

      // หาค่า avg และ std ของ employee กรองตาม parameter โดยกำหนดตัวแปลเพื่อไปเก็บค่าใน loop ของ $itemList และคำนวณหลังจากจบ loop
      $lastScoreSum4Filter = floatval(0.00);
      $itemListCnt4Filter = floatval(0.00);
      $lastScoreArr4Filter = Array();
      
      // structure ทั้งหมดที่จะต้องแสดงตาม parameter ทั้งหมด [แสดงเฉพาะที่มีใน emp_result]
      $strResultList = collect(DB::select("
        SELECT er.emp_result_id,
          result.structure_id, 
          result.structure_name, 
          result.seq_no,
          IFNULL(sr.weigh_score,0) as score,
          (
            SELECT MAX(air.weight_percent)
            FROM emp_result ser 
            INNER JOIN appraisal_item_result air ON air.emp_result_id = ser.emp_result_id
            INNER JOIN appraisal_item ai ON ai.item_id = air.item_id
            WHERE ser.emp_result_id = er.emp_result_id
            AND ai.structure_id = sr.structure_id
          ) AS total_score
        FROM emp_result er
        CROSS JOIN (
          SELECT aps.structure_id, aps.structure_name, aps.seq_no
          FROM emp_result emp
          INNER JOIN structure_result str ON str.emp_result_id = emp.emp_result_id
          INNER JOIN appraisal_structure aps ON str.structure_id = aps.structure_id
          LEFT JOIN org o ON emp.org_id = o.org_id
          WHERE emp.period_id = {$request->period_id}
          {$qryFormId}
          {$qryEmpLevel}
          {$qryOrgLevel}
          {$qryOrgId}
          {$qryEmpId}
          {$qryPositionId}
          {$qryStageId}
          GROUP BY aps.structure_id, aps.structure_name, aps.seq_no
        ) result
        LEFT JOIN structure_result sr ON sr.emp_result_id = er.emp_result_id
          AND sr.period_id = er.period_id
          AND sr.structure_id = result.structure_id
        WHERE er.period_id = {$request->period_id}
        ORDER BY er.emp_result_id, result.seq_no
      "));

      // Grade information
      $gradeInfo = AppraisalGrade::all();

      // หา org ภายใต้ user และดูว่า user คือ board หรือไม่? [กำหนด board : edit_flag = 1 นอกนั้นเป็น 0]
      $userInfo = collect(DB::select("
        SELECT (CASE WHEN o.level_id = {$adjustLevel->level_board} THEN 1 ELSE 0 END) as is_board,
          em.level_id, em.org_id, o.org_code,
          GetAllUnderOrg(o.org_code) as all_under_org
        FROM employee em
        INNER JOIN org o ON em.org_id = o.org_id
        WHERE em.emp_code = '{$login}'
      "))->first();
        
      // หาค่า structure, structure แรก by record และคำนวณรายได้ปัจจุบัน
      foreach ($itemList as $item) {
        // นำข้อมูลของ adjust score ของแต่ละ level มาใส่ให้ main data
        $score_ = $scoreList->where('emp_result_id', $item->emp_result_id)->first();
        if(empty($score_)) {
          $item->score_bu = NULL;
          $item->score_coo = NULL;
          $item->score_board = NULL;
          $item->last_score_adjust = NULL;
          $item->score_last = NULL;

          $lastScoreSum4Filter = $lastScoreSum4Filter;
          array_push($lastScoreArr4Filter, 0);
        } else {
          $item->score_bu = $score_->score_bu;
          $item->score_coo = $score_->score_coo;
          $item->score_board = $score_->score_board;
          $item->last_score_adjust = $score_->last_score;
          $item->score_last = $score_->last_score;

          $lastScoreSum4Filter = $lastScoreSum4Filter + floatval(($item->score_last == NULL) ? 0 : $item->score_last);
          array_push($lastScoreArr4Filter, $item->score_last);
        }
        $itemListCnt4Filter = $itemListCnt4Filter + 1;
        
        
        // คำนวณหารายได้ปัจจุบันของ employee
        $item->total_now_salary = ($item->salary + $item->pqpi_amount + $item->fix_other_amount + $item->mpi_amount + $item->pi_amount + $item->var_other_amount);

        // หาค่า structure, total structure, first structure ตามแต่ละ record 
        $strResult = $strResultList->where('emp_result_id', $item->emp_result_id);
        if($strResult->count() == 0) {
          $item->structure_result = [];
          $item->first_structure_id = NULL;
        } else {
          $item->structure_result = array_values($strResult->toArray()); //array_values():reset array index
          $item->first_structure_id = $strResult->first()->structure_id;
        }

        // หาข้อมุูล grade infomation ในแต่ละ form แต่ละ level ของ employee
        $item->cal_grade = array_values($gradeInfo->where('appraisal_form_id', $item->appraisal_form_id)->where('appraisal_level_id', $item->level_id)->toArray()); //array_values():reset array index

        /**
         * [หน้านี้เข้าใช้งานได้เพียงแค่ board, coo]
         * is_board = 1 : user ที่เข้าสู่ระบบเป็น board,
         * is_board = 0 : user ที่เข้าสู่ระบบเป็น coo
        */
        $item->is_board = $userInfo->is_board;
        $item->score_board = ($item->is_board == 0) ? 0 : $item->score_board;

        // คำนวนเกรดตามคะแนนล่าสุดที่ใช้แสดง (ดูตั้งแต่ Mgr., BU., COO, Board)
        if($item->cal_grade){
          $grade = collect($item->cal_grade)->filter(function($i) use($item){
            return $i['begin_score'] <= $item->score_last && $i['end_score'] >= $item->score_last;
          })->first();
          $item->grade = ($grade) ? $grade['grade'] : '';
        } else {
          $item->grade = '';
        }

        // คำนวนค่า z-score by record
        $item->z_score = ($std == 0) ? 0 : (($item->last_score_adjust-$avg) / $std);   

      } // end foreach item

      // หาค่า avg และ std ของ employee กรองตาม parameter และคำนวณ z-score ของแต่ละ employee
      $avg_filter = $lastScoreSum4Filter / $itemListCnt4Filter;
      $std_filter = empty($lastScoreArr4Filter) ? 0 : $this->advanSearch->standard_deviation($lastScoreArr4Filter);
      foreach ($itemList as $item){
        $item->z_score_filter = ($std_filter == 0) ? 0 : (($item->last_score_adjust-$avg_filter) / $std_filter);
      }

      return ['datas' => $itemList, 'avg' => $avg, 'sd' => $std, 'avg_filter' => $avg_filter, 'std_filter' => $std_filter, 'emp_cnt'=>$itemListCnt4Filter];
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

            if($request->calculate_flag == 0) {
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
                      ->get();

            if(count($empJust) > 0){
              $empJustUpdate = EmpResultJudgement::where('emp_result_id', '=',  $d['emp_result_id'])
                        ->where('judge_id', '=', $user->emp_id)
                        ->where('org_level_id', '=', $user->level_id)
                        ->update([
                          'adjust_grade' => (empty($d['grade']) ? '' : $d['grade']),
                          'adjust_result_score' => $d['score_adjust']
                        ]);

            } else if (count($empJust) == 0){
              $now = new DateTime();
              $date = $now->format('Y-m-d H:i:s');

              $empResult = new EmpResultJudgement;
              $empResult->emp_result_id = $d['emp_result_id'];
              $empResult->judge_id = $user->emp_id;
              $empResult->org_level_id = $user->level_id;
              $empResult->adjust_result_score = $d['score_adjust'];
              $empResult->adjust_grade = $d['grade'];
              $empResult->created_by = Auth::id();
              $empResult->created_dttm = $date;
              $empResult->save();
            }

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

                if($request->calculate_flag == 0) {
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
