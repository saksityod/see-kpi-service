<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;

use App\EmpResultJudgement;
use App\Employee;
use App\EmpResult;
use App\AppraisalStage;
use App\EmpResultStage;

use Validator;
use DB;
use Auth;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
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
            ORDER BY appraisal_form_id ASC");

        return response()->json($form);
    }

    public function index(Request $request)
    {
        // หา level ที่เป็นระดับ bu และ coo และ board
        $level_bu_coo_board = "
            SELECT l.level_id as level_bu
    				, le.level_id as level_coo
            , le.parent_id as level_board
    				FROM appraisal_level l
    				LEFT JOIN appraisal_level le ON l.parent_id = le.level_id
    				WHERE l.is_start_cal_bonus = 1";

        // หาค่า score ของระดับ bu และ coo (ตาม level)
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

        // ค่า total ของแต่ละ stucture ตาม form ของ parameter
        /* $total_stucture = "
            SELECT (CASE WHEN max(re.total_one) = 0 THEN (max(re.total_two)+max(re.total_three)) ELSE max(re.total_one) END) as total_one
            , max(re.total_two) as total_two
            , max(re.total_three) as total_three
            , max(re.total_four) as total_four
            , max(re.total_five) as total_five
            , re.appraisal_form_id
            FROM
            (
                SELECT apc.structure_id
                , aps.seq_no
                , apf.appraisal_form_id
                , (CASE WHEN aps.seq_no = 101 THEN apc.weight_percent ELSE 0 END) as total_one
                , (CASE WHEN aps.seq_no = 104 THEN apc.weight_percent ELSE 0 END) as total_two
                , (CASE WHEN aps.seq_no = 105 THEN apc.weight_percent ELSE 0 END) as total_three
                , (CASE WHEN aps.seq_no = 102 THEN apc.weight_percent ELSE 0 END) as total_four
                , (CASE WHEN aps.seq_no = 103 THEN apc.weight_percent ELSE 0 END) as total_five
                FROM appraisal_criteria apc
                INNER JOIN appraisal_form apf ON apc.appraisal_form_id = apf.appraisal_form_id
                INNER JOIN appraisal_structure aps ON apc.structure_id = aps.structure_id
                WHERE apf.is_raise = 1
                AND apf.appraisal_form_id = ".$request->appraisal_form_id."
            ) re"; */

       // หา structure_id ตาม form
        $stucture_form = "
            SELECT apf.appraisal_form_id
            , apc.appraisal_level_id
            , apc.structure_id
            , aps.seq_no
            , apc.weight_percent
            FROM appraisal_criteria apc
            INNER JOIN appraisal_form apf ON apc.appraisal_form_id = apf.appraisal_form_id
            INNER JOIN appraisal_structure aps ON apc.structure_id = aps.structure_id
            WHERE apf.is_raise = 1
            AND apf.appraisal_form_id = ".$request->appraisal_form_id."
            GROUP BY apf.appraisal_form_id
            , apc.appraisal_level_id
            , apc.structure_id
            , aps.seq_no";

        // ---------- seq_no : stucture -----------
        // 101 : ผลการประเมินค่างาน
        // 102 : คะแนนผลงานปีที่ผ่านมา
        // 103 : คะแนนความสามารถที่มีคุณค่าต่อองค์กร
        // 104 : คะแนนความรู้
        // 105 : คะแนนศักยภาพ

        // หาค่า score ของแต่ละ stucture และหา total score สำหรับ "ผลการประเมินค่างาน"
        $score_stucture = "
            SELECT result.emp_result_id
            , result.period_id
            , result.emp_id
            , (CASE WHEN max(result.have_one) = 1 THEN max(result.one) ELSE (max(result.two)+max(result.three)) END) as one
            , max(result.two) as two
            , max(result.three) as three
            , max(result.four) as four
            , max(result.five) as five

            , (CASE WHEN max(result.have_one_total) = 1 THEN max(result.one_total) ELSE (max(result.two_total)+max(result.three_total)) END) as one_total
            FROM
            (

              SELECT re.structure_id
              , re.emp_result_id
              , re.period_id
              , re.emp_id
              , emp.level_id
              , emp.appraisal_form_id
              , re.weigh_score
              , sr.seq_no
              , (CASE WHEN sr.seq_no = 101 THEN 1 ELSE 0 END) as have_one
              , (CASE WHEN sr.seq_no = 101 THEN re.weigh_score ELSE NULL END) as one
              , (CASE WHEN sr.seq_no = 104 THEN re.weigh_score ELSE NULL END) as two
              , (CASE WHEN sr.seq_no = 105 THEN re.weigh_score ELSE NULL END) as three
              , (CASE WHEN sr.seq_no = 102 THEN re.weigh_score ELSE NULL END) as four
              , (CASE WHEN sr.seq_no = 103 THEN re.weigh_score ELSE NULL END) as five

              , (CASE WHEN sr.seq_no = 101 THEN 1 ELSE 0 END) as have_one_total
              , (CASE WHEN sr.seq_no = 101 THEN sr.weight_percent ELSE NULL END) as one_total
              , (CASE WHEN sr.seq_no = 104 THEN sr.weight_percent ELSE NULL END) as two_total
							, (CASE WHEN sr.seq_no = 105 THEN sr.weight_percent ELSE NULL END) as three_total
              FROM structure_result re
              INNER JOIN emp_result emp ON re.emp_result_id = emp.emp_result_id
              LEFT JOIN (".$stucture_form.") sr ON re.structure_id = sr.structure_id
              AND emp.level_id = sr.appraisal_level_id
              AND emp.appraisal_form_id = sr.appraisal_form_id

            ) result
            GROUP BY result.emp_result_id
            , result.period_id
            , result.emp_id";

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
            , em.emp_id
            , em.emp_code
            , em.emp_name
            , po.position_id
            , po.position_name
            , le.level_id as PG
            , o.org_code
            , o.org_name
            , fo.appraisal_form_id
            , fo.appraisal_form_name
            , po.job_code
            , po.position_code
            , emp.knowledge_point
            , emp.capability_point
            , emp.total_point
            , emp.baht_per_point
            , stu.one
            , stu.two
            , stu.three
            , stu.four
            , stu.five
            , emp.result_score as score_manager
            , emj.score_bu
            , emj.score_coo
            , emj.score_board
            , gr.adjust_result_score as score_for_grade
            , gr.grade
            , round(((emp.total_point*stu.one/stu.one_total)*emp.baht_per_point)*(90/100),-2) as total_percent
            , round(((emp.total_point*stu.one/stu.one_total)*emp.baht_per_point)*(65/100),-2) as fix_percent
            , round(((emp.total_point*stu.one/stu.one_total)*emp.baht_per_point)*(25/100),-2) as var_percent
            , (from_base64(emp.s_amount)+from_base64(emp.pqpi_amount)+from_base64(emp.fix_other_amount)+from_base64(emp.mpi_amount)+from_base64(emp.pi_amount)+from_base64(emp.var_other_amount)) as total_now_salary
            , from_base64(emp.s_amount) as salary
            , from_base64(emp.pqpi_amount) as pqpi_amount
            , from_base64(emp.fix_other_amount) as fix_other_amount
            , from_base64(emp.mpi_amount) as mpi_amount
            , from_base64(emp.pi_amount) as pi_amount
            , from_base64(emp.var_other_amount) as var_other_amount
            , ((from_base64(emp.s_amount)+from_base64(emp.pqpi_amount)+from_base64(emp.fix_other_amount)+from_base64(emp.mpi_amount)+from_base64(emp.pi_amount)+from_base64(emp.var_other_amount))-round(((emp.total_point*60/stu.one_total)*emp.baht_per_point)*(90/100),-2)) as miss_over
            , emp.raise_amount as cal_standard
            , pe.appraisal_year
            FROM emp_result emp
            LEFT JOIN employee em ON emp.emp_id = em.emp_id
            LEFT JOIN position po ON emp.position_id = po.position_id
            LEFT JOIN appraisal_level le ON emp.level_id = le.level_id
            LEFT JOIN org o ON emp.org_id = o.org_id
            LEFT JOIN appraisal_form fo ON emp.appraisal_form_id = fo.appraisal_form_id
            LEFT JOIN appraisal_structure st ON emp.appraisal_form_id = st.form_id
            LEFT JOIN appraisal_period pe ON emp.period_id = pe.period_id
            LEFT JOIN (".$score_bu_coo_board.") emj ON emj.emp_result_id = emp.emp_result_id
            LEFT JOIN (".$grade_score.") gr ON gr.emp_result_id = emp.emp_result_id
            LEFT JOIN (".$score_stucture.") stu ON stu.emp_result_id = emp.emp_result_id
              AND stu.period_id = emp.period_id
              AND stu.emp_id = emp.emp_id
            WHERE emp.appraisal_type_id = 2";


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
        $qryFormId = empty($request->appraisal_form_id) ? "": " AND emp.appraisal_form_id = {$request->appraisal_form_id}";
        //------------------------ จบส่วนที่เอามาจาก BonusAdjustmentController ------------------------------------//

        // return $main_data;
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

        // สร้างลำดับให้กับข้อมูล test
        /*
        $item = DB::select("
            SELECT @row_num := IF(@prev_value = rows.num ,@row_num+1 ,1) AS RowNumber
            , rows.*
            , @prev_value := rows.num
            FROM (
              ".$main_data."
              AND emp.period_id = ".$request->period_id."
              ".$qryFormId ."
              ".$qryEmpLevel."
              ".$qryOrgLevel."
              ".$qryOrgId."
              ".$qryEmpId."
              ".$qryPositionId."
              ".$qryStageId."
              ORDER BY o.org_code ASC
              , le.level_id DESC
              , em.emp_code ASC
            ) rows
            ,  (SELECT @row_num := 1) num_value,
            (SELECT @prev_value := '') set_value "
          );
          */


        // สร้างลำดับให้กับข้อมูล
        /* $item = DB::select("
            SELECT @row_num := IF(@prev_value = rows.num ,@row_num+1 ,1) AS RowNumber
            , rows.*
            , @prev_value := rows.num
            FROM (
              ".$main_data."
              ".$qryPositionId."
              ORDER BY o.org_code ASC
              , le.level_id DESC
              , em.emp_code ASC
            ) rows
            ,  (SELECT @row_num := 1) num_value,
            (SELECT @prev_value := '') set_value "
          , $qinput); */

        // สร้าง group เพื่อให้สามารถรวมข้อมูลได้
        $groups = array();
        foreach ($item as $i) {
          $key = $i->num;
        	if (!isset($groups[$key])) {
        		$groups[$key] = array(
        			'items' => array($i),
        			'sum_total_now_salary' => $i->total_now_salary,
              'sum_salary' => $i->salary,
              'sum_pqpi_amount' => $i->pqpi_amount,
              'sum_fix_other_amount' => $i->fix_other_amount,
              'sum_mpi_amount' => $i->mpi_amount,
              'sum_pi_amount' => $i->pi_amount,
              'sum_var_other_amount' => $i->var_other_amount,
              'sum_miss_over' => $i->miss_over,
              'sum_cal_standard' => $i->cal_standard,
        			'count' => 1,
              'edit_flag' => $user[0]->is_board,
        		);
        	} else {
        		$groups[$key]['items'][] = $i;
            $groups[$key]['sum_total_now_salary'] += $i->total_now_salary;
            $groups[$key]['sum_salary'] += $i->salary;
            $groups[$key]['sum_pqpi_amount'] += $i->pqpi_amount;
            $groups[$key]['sum_fix_other_amount'] += $i->fix_other_amount;
            $groups[$key]['sum_mpi_amount'] += $i->mpi_amount;
            $groups[$key]['sum_pi_amount'] += $i->pi_amount;
            $groups[$key]['sum_var_other_amount'] += $i->var_other_amount;
            $groups[$key]['sum_miss_over'] += $i->miss_over;
            $groups[$key]['sum_cal_standard'] += $i->cal_standard;
        		$groups[$key]['count'] += 1;
            $groups[$key]['edit_flag'] = $user[0]->is_board;
        	}
        }
        return response()->json($groups);

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
        $errors = [];
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

        foreach ($request['detail'] as $d) {
            $empl = Employee::where('emp_id', $d['emp_id'])->first();
            $empl->s_amount = base64_encode($d['salary']);
            $empl->pqpi_amount = base64_encode($d['pqpi']);
            $empl->updated_by = Auth::id();

            $emp = EmpResult::find($d['emp_result_id']);
            $emp->adjust_new_s_amount = base64_encode($d['salary']);
            $emp->adjust_new_pqpi_amount = base64_encode($d['pqpi']);
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
                $empl->save();
            } catch (Exception $e) {
                $errors[] = substr($e, 254);
            }
        }

        return response()->json(['status' => 200, 'data' => $errors]);
    }

}
