<?php

namespace App\Http\Controllers\Appraisal360degree;

use App\SystemConfiguration;
use App\Employee;
use App\Org;
use App\AssessorGroup;
use App\AppraisalLevel;
use App\CompetencyResult;
use App\AppraisalItemResult;
use App\WorkflowStage;
use App\StructureResult;
use App\EmpResult;
use App\CDSResult;
use App\AppraisalGrade;
use App\Http\Controllers\AppraisalController;
use App\Http\Controllers\Bonus\AdvanceSearchController;

use Auth;
use DB;
use DateTime;
use File;
use Validator;
use Excel;
use Config;
use Mail;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AppraisalGroupController extends Controller
{

	public function __construct()
	{
		$this->middleware('jwt.auth');
		$this->advanSearch = new AdvanceSearchController;
	}

	public function check_permission_popup($emp_result_id) {
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
		", array(Auth::id()));
		
		if ($all_emp[0]->count_no > 0) {
			$status = 200;
		} else {
			$emp = DB::table("employee")->where("emp_code", Auth::id())->first();
			$emp_result = DB::select("
				SELECT 1
				FROM emp_result
				WHERE emp_result_id = '{$emp_result_id}'
				AND (emp_id = '{$emp->emp_id}' OR chief_emp_id = '{$emp->emp_id}')
			");

			if(empty($emp_result)) {
				$status = 401;
			} else {
				$status = 200;
			}
		}

		return response()->json(['status' => $status]);
	}

	public function emp_level_list(Request $request)
	{
		$result = AppraisalLevel::select('level_id', 'appraisal_level_name')
			->where('is_active', 1)
			->where('is_individual', 1)
			->orderBy('level_id')
			->get();
		
		/* โค้ดที่ comment ด้านล่างเป็นแบบกรองตามหัวหน้าและลูกน้อง
		$AuthEmpCode = Auth::id();
		$empLevInfo = (new AppraisalController())->is_all_employee($AuthEmpCode);
		if ($empLevInfo["is_all"]) {
			$result = DB::select("
				SELECT level_id, appraisal_level_name
				FROM appraisal_level
				WHERE is_active = 1
				AND is_individual = 1
				ORDER BY level_id DESC
			");
		} else {
			// Get chief and under //
			$allChiefEmp = $this->GetallChiefEmp($AuthEmpCode)->lists('emp_code')->toArray();
			$allUnderEmp = $this->GetallUnderEmp($AuthEmpCode)->lists('emp_code')->toArray();
			$empList = array_merge($allChiefEmp, $allUnderEmp);
			$empList = implode(",",$empList);

			$result = DB::select("
				SELECT lev.level_id, lev.appraisal_level_name
				FROM employee emp
				INNER JOIN appraisal_level lev ON lev.level_id = emp.level_id
				WHERE emp.is_active = 1
				AND lev.is_active = 1
				AND lev.is_individual = 1
				AND (
					find_in_set(emp.emp_code, '{$empList}')
					OR emp.emp_code = '{$AuthEmpCode}'
				)
				GROUP BY lev.level_id
				ORDER BY lev.level_id DESC
			");
		}
		*/

		return response()->json($result);
	}


	public function org_level_list_individual(Request $request)
	{
		$AuthEmpCode = Auth::id();
		$result = DB::select("
			SELECT DISTINCT org.level_id, vel.appraisal_level_name,
				CASE 
					WHEN org.level_id = 
						(
							SELECT so.level_id
							FROM org so 
							INNER JOIN employee se ON se.org_id = so.org_id 
							WHERE emp_code = '{$AuthEmpCode}'
						)
					THEN 1 
					ELSE 0
				END AS default_flag
			FROM org
			INNER JOIN appraisal_level vel ON vel.level_id = org.level_id
			WHERE EXISTS(
				SELECT 1
				FROM employee emp 
				WHERE emp.org_id = org.org_id
				AND emp.level_id = {$request->level_id}
			)
			ORDER BY org.level_id
		");

		return response()->json($result);
	}


	public function org_individual(Request $request)
	{
		$AuthEmpCode = Auth::id();
		$result = DB::select("
			SELECT org.org_id, org.org_name,
				CASE 
					WHEN org.org_id = (SELECT org_id FROM employee WHERE emp_code = '{$AuthEmpCode}')
					THEN 1 
					ELSE 0
				END AS default_flag
			FROM org
			WHERE org.level_id = '{$request->org_level}'
		");
		return response()->json($result);
	}


	public function index(Request $request)
	{
		$employee = Employee::find(Auth::id());
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
		", array(Auth::id()));

		$qinput = array();
		$system_config = SystemConfiguration::where('current_appraisal_year', $request->appraisal_year)->first();	
		if ($all_emp[0]->count_no > 0) {
			$query = "
				SELECT a.emp_result_id, a.emp_id, b.emp_code, b.emp_name, d.appraisal_level_name, 
					e.appraisal_type_id, e.appraisal_type_name, p.position_name, o.org_code, o.org_name, 
					po.org_name parent_org_name, f.to_action, a.stage_id, g.period_id,
					g.start_date as appraisal_period_start_date, 
					concat(g.appraisal_period_desc,' Start Date: ',g.start_date,' End Date: ',g.end_date) appraisal_period_desc,
					af.appraisal_form_name
				FROM emp_result a
				LEFT OUTER JOIN employee b ON a.emp_id = b.emp_id
				LEFT OUTER JOIN appraisal_level d ON a.level_id = d.level_id
				LEFT OUTER JOIN appraisal_type e ON a.appraisal_type_id = e.appraisal_type_id
				LEFT OUTER JOIN appraisal_stage f ON a.stage_id = f.stage_id
				LEFT OUTER JOIN appraisal_period g ON a.period_id = g.period_id
				LEFT OUTER JOIN position p ON a.position_id = p.position_id
				LEFT OUTER JOIN org o ON a.org_id = o.org_id
				LEFT OUTER JOIN org po ON o.parent_org_code = po.org_code
				INNER JOIN appraisal_form af on af.appraisal_form_id = a.appraisal_form_id
				WHERE d.is_hr = 0
			";

			empty($request->appraisal_year) ?: ($query .= " and g.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
			empty($request->period_no) ?: ($query .= " and g.period_id = ? " AND $qinput[] = $request->period_no);
			empty($request->level_id) ?: ($query .= " and a.level_id = ? " AND $qinput[] = $request->level_id);
			empty($request->level_id_org) ?: ($query .= " and o.level_id = ? " AND $qinput[] = $request->level_id_org);
			empty($request->appraisal_type_id) ?: ($query .= " and a.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
			empty($request->org_id) ?: ($query .= " and a.org_id = ? " AND $qinput[] = $request->org_id);
			empty($request->position_id) ?: ($query .= " and a.position_id = ? " AND $qinput[] = $request->position_id);
			empty($request->emp_id) ?: ($query .= " And a.emp_id = ? " AND $qinput[] = $request->emp_id);
			empty($request->appraisal_form_id) ?: ($query .= " And a.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form_id);

			$items = DB::select($query. " order by o.org_code asc, d.seq_no desc, b.emp_code asc ", $qinput);

		} else {
			if ($request->appraisal_type_id == 2) {
				$query = "
					select a.emp_result_id, b.emp_code, b.emp_name, d.appraisal_level_name, 
						e.appraisal_type_id, e.appraisal_type_name, p.position_name, o.org_code, 
						o.org_name, po.org_name parent_org_name, f.to_action, a.stage_id, g.period_id, 
						g.start_date as appraisal_period_start_date,
						concat(g.appraisal_period_desc,' Start Date: ',g.start_date,' End Date: ',g.end_date) appraisal_period_desc,
						af.appraisal_form_name
					from emp_result a
					left outer join employee b on a.emp_id = b.emp_id
					left outer join appraisal_level d on a.level_id = d.level_id
					left outer join appraisal_type e on a.appraisal_type_id = e.appraisal_type_id
					left outer join appraisal_stage f on a.stage_id = f.stage_id
					left outer join appraisal_period g on a.period_id = g.period_id
					left outer join position p on a.position_id = p.position_id
					left outer join org o on a.org_id = o.org_id
					left outer join org po on o.parent_org_code = po.org_code
					INNER JOIN appraisal_form af on af.appraisal_form_id = a.appraisal_form_id
					where d.is_hr = 0
				";

				empty($request->appraisal_year) ?: ($query .= " and g.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
				empty($request->period_no) ?: ($query .= " and g.period_id = ? " AND $qinput[] = $request->period_no);
				empty($request->level_id) ?: ($query .= " and a.level_id = ? " AND $qinput[] = $request->level_id);
				empty($request->level_id_org) ?: ($query .= " and o.level_id = ? " AND $qinput[] = $request->level_id_org);
				empty($request->appraisal_type_id) ?: ($query .= " and a.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
				empty($request->org_id) ?: ($query .= " and a.org_id = ? " AND $qinput[] = $request->org_id);
				empty($request->position_id) ?: ($query .= " and a.position_id = ? " AND $qinput[] = $request->position_id);
				empty($request->emp_id) ?: ($query .= " And a.emp_id = ? " AND $qinput[] = $request->emp_id);
				empty($request->appraisal_form_id) ?: ($query .= " And a.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form_id);

				$items = DB::select($query. " order by o.org_code asc, d.seq_no desc, b.emp_code asc ", $qinput);

			} else {
				$in_org_code = $this->advanSearch->GetallUnderOrgByOrg($employee->org_id);
				empty($in_org_code) ? $in_org_code = "null," : null;

				$org_code_auth = DB::table('org')->select('org_code')->where('org_id', $employee->org_id)->first();
				
				empty($system_config->appraisal_360_flag)?($appraisal_360_flag=""):($appraisal_360_flag = $system_config->appraisal_360_flag);

				$query = "
					select a.emp_result_id, b.emp_code, b.emp_name, d.appraisal_level_name, 
						e.appraisal_type_id, e.appraisal_type_name, p.position_name, o.org_code, o.org_name, 
						po.org_name parent_org_name, f.to_action, a.stage_id, g.period_id, 
						g.start_date as appraisal_period_start_date,
						concat(g.appraisal_period_desc,' Start Date: ',g.start_date,' End Date: ',g.end_date) appraisal_period_desc,
						af.appraisal_form_name
					from emp_result a
					left outer join employee b on a.emp_id = b.emp_id
					left outer join appraisal_level d on a.level_id = d.level_id
					left outer join appraisal_type e on a.appraisal_type_id = e.appraisal_type_id
					left outer join appraisal_stage f on a.stage_id = f.stage_id
					left outer join appraisal_period g on a.period_id = g.period_id
					left outer join position p on a.position_id = p.position_id
					left outer join org o on a.org_id = o.org_id
					left outer join org po on o.parent_org_code = po.org_code
					INNER JOIN appraisal_form af on af.appraisal_form_id = a.appraisal_form_id
					where d.is_hr = 0
				";
				($appraisal_360_flag == 0 ) ?($query .= " and o.org_code in ({$in_org_code}".$org_code_auth->org_code.")"):null ;
				empty($request->appraisal_year) ?: ($query .= " and g.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
				empty($request->period_no) ?: ($query .= " and g.period_id = ? " AND $qinput[] = $request->period_no);
				empty($request->level_id) ?: ($query .= " and a.level_id = ? " AND $qinput[] = $request->level_id);
				empty($request->level_id_org) ?: ($query .= " and o.level_id = ? " AND $qinput[] = $request->level_id_org);
				empty($request->appraisal_type_id) ?: ($query .= " and a.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
				empty($request->org_id) ?: ($query .= " and a.org_id = ? " AND $qinput[] = $request->org_id);
				empty($request->position_id) ?: ($query .= " and a.position_id = ? " AND $qinput[] = $request->position_id);
				empty($request->emp_id) ?: ($query .= " And a.emp_id = ? " AND $qinput[] = $request->emp_id);
				empty($request->appraisal_form_id) ?: ($query .= " And a.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form_id);

				$items = DB::select($query. " order by o.org_code asc, d.seq_no desc, b.emp_code asc ", $qinput);

			}
		}

		// Number of items per page
        if($request->rpp == 'All' || empty($request->rpp)) {
            $request->rpp = empty($items) ? 10 : count($items);
        }

		// Get the current page from the url if it's not set default to 1
		empty($request->page) ? $page = 1 : $page = $request->page;

		// Number of items per page
		empty($request->rpp) ? $perPage = 10 : $perPage = $request->rpp;

		$offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

		// Get only the items you need using array_slice (only get 10 items since that's what you need)
		$itemsForCurrentPage = array_slice($items, $offSet, $perPage, false);
		
		// Add Assessor Group.
		foreach($itemsForCurrentPage as $item) {
			// Is Organization.
			if($request->appraisal_type_id == 1){
				$assGroup = AssessorGroup::find(4);
				$item->group_id = $assGroup->assessor_group_id;
				$item->group_name = $assGroup->assessor_group_name;
			} else {  // Is Individual.
				$assGroup = $this->getAssessorGroup($item->emp_code);
				if($assGroup != null){
					$item->group_id = $assGroup->assessor_group_id;
					$item->group_name = $assGroup->assessor_group_name;
				} else {
					$assGroup = AssessorGroup::find(4);
					$item->group_id = $assGroup->assessor_group_id;
					$item->group_name = $assGroup->assessor_group_name;
				}
			}
		}
		
		// Return the paginator with only 10 items but with the count of all items and set the it on the correct page
		$result = new LengthAwarePaginator($itemsForCurrentPage, count($items), $perPage, $page);
		
		$groups = array();
		foreach ($itemsForCurrentPage as $item) {
			$key = "p".$item->period_id;
			if (!isset($groups[$key])) {
				$groups[$key] = array(
					'items' => array($item),
					'appraisal_period_desc' => $item->appraisal_period_desc,
					'appraisal_period_start_date' => $item->appraisal_period_start_date,
					'count' => 1,
				);
			} else {
				$groups[$key]['items'][] = $item;
				$groups[$key]['count'] += 1;
			}
		}
		$resultT = $result->toArray();
		$resultT['group'] = $groups;
		$resultT['system_config'] = $system_config;
		return response()->json($resultT);
	}


	public function show(Request $request)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}
		$allow_input_actual = (empty($config->allow_input_actual)? 0 : $config->allow_input_actual);
		
		$head = DB::select("
			SELECT b.emp_code, b.emp_name, b.working_start_date, p.position_name, o.org_code, o.org_name, 
				po.org_name parent_org_name, b.chief_emp_code, b.has_second_line, e.emp_name chief_emp_name, 
				s.emp_code second_chief_emp_code, s.emp_name second_chief_emp_name, c.appraisal_period_desc, 
				a.appraisal_type_id, d.appraisal_type_name, a.stage_id, f.status, a.result_score, f.edit_flag, 
				al.no_weight, a.position_id, a.org_id, af.appraisal_form_name, ag.grade , a.salary_grade_id, a.result_score ,a.level_id , a.appraisal_form_id
			FROM emp_result a
			left outer join employee b on a.emp_id = b.emp_id
			left outer join appraisal_period c on c.period_id = a.period_id
			left outer join appraisal_type d on a.appraisal_type_id = d.appraisal_type_id
			left outer join employee e on b.chief_emp_code = e.emp_code
			left outer join employee s on e.chief_emp_code = s.emp_code
			left outer join appraisal_stage f on a.stage_id = f.stage_id
			left outer join position p on b.position_id = p.position_id
			left outer join org o on a.org_id = o.org_id
			left outer join org po on o.parent_org_code = po.org_code
			left outer join appraisal_level al on a.level_id = al.level_id
			left outer join appraisal_grade ag on ag.grade_id = a.salary_grade_id
			left outer join appraisal_form af on af.appraisal_form_id = a.appraisal_form_id
			where a.emp_result_id = ?
		", array($request->emp_result_id));
		 $head = collect($head);

		    // set grade if emp_result.grade is null
		    $head = $head->map(function($hd){
		      $grade = collect();
		      if($hd->salary_grade_id === null){
		        $grade = AppraisalGrade::where('is_active', 1)
		          ->where("appraisal_form_id", $hd->appraisal_form_id)
		          ->where("appraisal_level_id",$hd->level_id)
		          ->whereRaw("? BETWEEN begin_score and end_score", [$hd->result_score])
		          ->first();

		        $hd->grade = (empty($grade->grade) ? null : $grade->grade);
		      }
		      return $hd;
				});
		
		$employee = DB::table('employee')
    ->join('org', 'org.org_id', '=', 'employee.org_id')
    ->select('employee.level_id AS level_emp', 'org.level_id AS level_org')
    ->where('emp_code', Auth::id())
		->first();
		
		if($head[0]->appraisal_type_id==1) {
			$level_id = $employee->level_org;
		} else {
			$level_id = $employee->level_emp;
		}
		    
		if($head[0]->emp_code==Auth::id()) {
			$items = DB::select("
				select b.item_name,uom.uom_name, b.structure_id, c.structure_name, d.form_id, d.app_url, c.nof_target_score, a.*, e.perspective_name, a.weigh_score, f.weigh_score total_weigh_score, a.contribute_percent, a.weight_percent, g.weight_percent total_weight_percent, al.no_weight,
					if(ifnull(a.target_value,0) = 0,0,(ifnull(a.actual_value,0)/a.target_value)*100) achievement, a.percent_achievement, h.result_threshold_group_id, c.is_value_get_zero, (select count(1) from appraisal_item_result_doc where a.item_result_id = item_result_id) files_amount,
					-- ((a.score*a.weight_percent*a.contribute_percent)/100) weigh_score_swc,
					-- ((a.percent_achievement*a.weight_percent*a.contribute_percent)/100) weigh_score_awc
					a.weigh_score as weigh_score_swc, a.weigh_score as weigh_score_awc
					, ".$allow_input_actual." as allow_input_actual 
					, c.is_no_raise_value
					, c.is_derive
					, if(c.level_id={$level_id}, 1, 0) edit_derive
				from appraisal_item_result a
				left outer join appraisal_item b on a.item_id = b.item_id
				left outer join appraisal_structure c on b.structure_id = c.structure_id
				left outer join form_type d on c.form_id = d.form_id
				left outer join perspective e on b.perspective_id = e.perspective_id
				left outer join structure_result f on a.emp_result_id = f.emp_result_id and c.structure_id = f.structure_id
				left outer join appraisal_criteria g on g.appraisal_form_id = a.appraisal_form_id and c.structure_id = g.structure_id and a.level_id = g.appraisal_level_id
				left outer join appraisal_level al on a.level_id = al.level_id
				left outer join emp_result h on a.emp_result_id = h.emp_result_id
				left join uom on  b.uom_id= uom.uom_id
				INNER JOIN assessor_group_structure ags ON ags.structure_id = b.structure_id 
				and ags.assessor_group_id = ?
				where a.emp_result_id = ?
				-- and d.form_id != 2
				GROUP BY a.item_result_id
				order by c.seq_no, b.item_id
				", array($request->assessor_group_id, $request->emp_result_id));
		} else {
			/* CancelBy:Wirun  CancelDate:2019.01.24
			$items = DB::select("
				SELECT DISTINCT b.item_name, b.formula_desc, uom.uom_name, b.structure_id, 
					c.structure_name, d.form_id, d.app_url, c.nof_target_score, a.*, e.perspective_name, 
					a.weigh_score, f.weigh_score total_weigh_score, a.contribute_percent, a.weight_percent, 
					g.weight_percent total_weight_percent, al.no_weight,
					IF(ifnull(a.target_value,0) = 0,0,(ifnull(a.actual_value,0)/a.target_value)*100) achievement, 
					a.percent_achievement, h.result_threshold_group_id, c.is_value_get_zero, 
					(select count(1) from appraisal_item_result_doc where a.item_result_id = item_result_id) files_amount,
					-- ((a.score*a.weight_percent*a.contribute_percent)/100) weigh_score_swc,
					-- ((a.percent_achievement*a.weight_percent*a.contribute_percent)/100) weigh_score_awc
					a.weigh_score as weigh_score_swc, a.weigh_score as weigh_score_awc
					, ".$allow_input_actual." as allow_input_actual
					, c.is_no_raise_value					
				FROM appraisal_item_result a
				LEFT OUTER JOIN appraisal_item b ON a.item_id = b.item_id
				INNER JOIN appraisal_item ai ON ai.item_id = a.item_id
				LEFT OUTER JOIN appraisal_structure c ON b.structure_id = c.structure_id
				LEFT OUTER JOIN form_type d ON c.form_id = d.form_id
				LEFT OUTER JOIN perspective e ON b.perspective_id = e.perspective_id
				LEFT OUTER JOIN structure_result f ON a.emp_result_id = f.emp_result_id AND c.structure_id = f.structure_id
				LEFT OUTER JOIN appraisal_criteria g ON c.structure_id = g.structure_id AND a.level_id = g.appraisal_level_id
				LEFT OUTER JOIN appraisal_level al ON a.level_id = al.level_id
				LEFT OUTER JOIN emp_result h ON a.emp_result_id = h.emp_result_id
				LEFT OUTER JOIN uom ON b.uom_id= uom.uom_id
				INNER JOIN assessor_group_structure ags ON ags.structure_id = b.structure_id AND ags.assessor_group_id = ?
				WHERE a.emp_result_id = ?
				GROUP BY a.item_result_id
				ORDER BY c.seq_no asc, b.item_id
			", array($request->assessor_group_id, $request->emp_result_id));
			*/
			$items = DB::select("
				SELECT
					ai.item_name, ai.formula_desc, uom.uom_name, ai.structure_id,
					str.structure_name, ft.form_id, ft.app_url, str.nof_target_score, air.*, e.perspective_name,
					air.weigh_score, f.weigh_score total_weigh_score, air.contribute_percent, air.weight_percent,
					g.weight_percent total_weight_percent, al.no_weight,
					IF(ifnull(air.target_value,0) = 0,0,(ifnull(air.actual_value,0)/air.target_value)*100) achievement,
					air.percent_achievement, h.result_threshold_group_id, str.is_value_get_zero,
					(select count(1) from appraisal_item_result_doc where air.item_result_id = item_result_id) files_amount,
					air.weigh_score as weigh_score_swc, air.weigh_score as weigh_score_awc
					, 1 as allow_input_actual
					, str.is_no_raise_value
					, str.is_derive
					, if(str.level_id={$level_id}, 1, 0) edit_derive
				FROM appraisal_item_result air
				INNER JOIN appraisal_item ai ON ai.item_id = air.item_id
				LEFT OUTER JOIN appraisal_structure str ON ai.structure_id = str.structure_id
				LEFT OUTER JOIN form_type ft ON ft.form_id = str.form_id
				LEFT OUTER JOIN perspective e ON e.perspective_id = ai.perspective_id 
				LEFT OUTER JOIN structure_result f ON f.emp_result_id = air.emp_result_id AND f.structure_id = str.structure_id
				LEFT OUTER JOIN appraisal_criteria g ON g.appraisal_form_id = air.appraisal_form_id AND g.structure_id = ai.structure_id AND g.appraisal_level_id = air.level_id
				LEFT OUTER JOIN appraisal_level al ON al.level_id = air.level_id 
				LEFT OUTER JOIN emp_result h ON h.emp_result_id = air.emp_result_id
				LEFT OUTER JOIN uom ON uom.uom_id = ai.uom_id
				INNER JOIN assessor_group_structure ags ON ags.structure_id = ai.structure_id AND ags.assessor_group_id = '{$request->assessor_group_id}'
				WHERE air.emp_result_id = '{$request->emp_result_id}'
				ORDER BY str.seq_no asc, ai.item_id
			");
		}
		
		$groups = array();
		foreach ($items as $item) {
			$key = $item->structure_name;
			$color = DB::select("
				select color_code
				from result_threshold
				where ? between begin_threshold and end_threshold
				and result_threshold_group_id = ?
			", array($item->percent_achievement, $item->result_threshold_group_id));

			if (empty($color)) {
				$minmax = DB::select("
					select min(begin_threshold) min_threshold, max(end_threshold) max_threshold
					from result_threshold
					where result_threshold_group_id = ?
				",array($item->result_threshold_group_id));

				if (empty($minmax)) {
					$item->color = 0;
				} else {
					if ($item->percent_achievement < $minmax[0]->min_threshold) {
						$get_color = DB::select("
							select color_code
							from result_threshold
							where result_threshold_group_id = ?
							and begin_threshold = ?
						", array($item->result_threshold_group_id, $minmax[0]->min_threshold));
						$item->color = $get_color[0]->color_code;
					} elseif ($item->percent_achievement > $minmax[0]->max_threshold) {
						$get_color = DB::select("
							select color_code
							from result_threshold
							where result_threshold_group_id = ?
							and end_threshold = ?
						", array($item->result_threshold_group_id, $minmax[0]->max_threshold));
						$item->color = $get_color[0]->color_code;
					} else {
						$item->color = 0;
					}
				}
			} else {
				$item->color = $color[0]->color_code;
			}

			$hint = array();
			if ($item->form_id == 2) {
				$hint = DB::select("
					select concat(a.target_score,' = ',a.threshold_name) hint
					from threshold a
					left outer join threshold_group b
					on a.threshold_group_id = b.threshold_group_id
					where b.is_active = 1
					and a.structure_id=?
					order by target_score asc
				", array($item->structure_id));
			}

			$check = DB::select("
				SELECT nof_target_score as max_no FROM
			appraisal_structure
			where  structure_id=?
			", array($item->structure_id));

			$total_weight = ($check[0]->max_no * $item->structure_weight_percent) / 100;

			if (!isset($groups[$key])) {
				$groups[$key] = array(
					'items' => array($item),
					'count' => 1,
					'form_id' => $item->form_id,
					'form_url' => $item->app_url,
					'structure_id' => $item->structure_id,
					'is_value_get_zero' => $item->is_value_get_zero,
					'nof_target_score' => $item->nof_target_score,
					'total_weight' => $total_weight,
					'hint' => $hint,
					'total_weigh_score' => $item->total_weigh_score,
					'total_weight_percent' => $item->structure_weight_percent,
					'no_weight' => $item->no_weight,
					'threshold' => $config->threshold,
					'result_type' => $config->result_type,
					'is_no_raise_value' => $item->is_no_raise_value
				);
			} else {
				$groups[$key]['items'][] = $item;
				$groups[$key]['count'] += 1;
			}
		}

		$stage = DB::select("
			SELECT a.created_by, a.created_dttm, b.from_action, b.to_action, a.remark
			FROM emp_result_stage a
			left outer join appraisal_stage b
			on a.stage_id = b.stage_id
			where a.emp_result_id = ?
			order by a.created_dttm asc
		", array($request->emp_result_id));

		return response()->json(['head' => $head, 'data' => $items, 'group' => $groups, 'stage' => $stage]);

	}


	public function show_type2 (Request $request)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		$auth = Auth::id();

		if($request->assessor_group_id == 5){
			/* ตัว all มีปัญหาเลยไม่เอามาใช้งาน (DHAS, 2018-11-28, p.wirun)
			$items = DB::select("
				SELECT * 
				FROM (
					SELECT DISTINCT com.item_id, ai.item_name, ai.formula_desc, ai.structure_id, aps.structure_name, aps.form_id, 
						ft.form_name, ft.app_url , com.competency_result_id, com.item_result_id, emp.emp_result_id, com.period_id, 
						com.emp_id, em.emp_code, com.assessor_group_id, gr.assessor_group_name, com.assessor_id, aps.is_value_get_zero, 
						CONCAT('#',com.assessor_group_id,emp.emp_result_id,em.emp_id,' (',gr.assessor_group_name,')') as emp_name, 
						com.org_id, com.position_id, com.level_id, com.chief_emp_id, com.target_value, com.score, com.threshold_group_id, 
						air.weight_percent, com.group_weight_percent, com.weigh_score, air.percent_achievement, 
						emp.result_threshold_group_id, aps.nof_target_score, le.no_weight, g.weight_percent total_weight_percent, 
						0 as total_weigh_score, air.structure_weight_percent
					FROM competency_result com
					LEFT OUTER JOIN appraisal_level le on com.level_id = le.level_id
					LEFT OUTER JOIN appraisal_item ai on com.item_id = ai.item_id
					LEFT OUTER JOIN	appraisal_structure aps on ai.structure_id = aps.structure_id
					LEFT OUTER JOIN	form_type ft on aps.form_id = ft.form_id
					LEFT OUTER JOIN	assessor_group gr on com.assessor_group_id = gr.assessor_group_id
					LEFT OUTER JOIN	employee em on com.assessor_id = em.emp_id
					LEFT OUTER JOIN	appraisal_item_result air on com.item_result_id = air.item_result_id
					LEFT OUTER JOIN	emp_result emp on air.emp_result_id = emp.emp_result_id
					LEFT OUTER JOIN	structure_result f on emp.emp_result_id = f.emp_result_id
					LEFT OUTER JOIN	competency_criteria g on aps.structure_id = g.structure_id 
						AND air.level_id = g.appraisal_level_id
						AND g.assessor_group_id = com.assessor_group_id
					INNER JOIN assessor_group_structure ags on ags.structure_id = ai.structure_id
						AND ags.assessor_group_id = '{$request->assessor_group_id}'
					WHERE aps.form_id = 2
					AND emp.emp_result_id = ?
					AND 5 = ?
					UNION ALL
					SELECT DISTINCT b.item_id, b.item_name, b.formula_desc, b.structure_id, c.structure_name, d.form_id, d.form_name, d.app_url,
						0 as competency_result_id, a.item_result_id, a.emp_result_id, a.period_id, 0 as emp_id, 'ALL' as emp_code, 
						0 as assessor_group_id, 'ทั้งหมด' as assessor_group_name, '0' as assessor_id, c.is_value_get_zero, 'ทั้งหมด' as emp_name, 
						a.org_id, a.position_id, a.level_id, a.chief_emp_id, a.target_value, a.score, a.threshold_group_id, 
						a.weight_percent, 0 as group_weight_percent, a.weigh_score, a.percent_achievement, h.result_threshold_group_id, 
						c.nof_target_score, al.no_weight, g.weight_percent total_weight_percent, f.weigh_score total_weigh_score, a.structure_weight_percent
					FROM appraisal_item_result a
					LEFT OUTER JOIN appraisal_item b on a.item_id = b.item_id
					INNER JOIN appraisal_item ai on ai.item_id = a.item_id
					LEFT OUTER JOIN appraisal_structure c on b.structure_id = c.structure_id
					LEFT OUTER JOIN form_type d on c.form_id = d.form_id
					LEFT OUTER JOIN perspective e on b.perspective_id = e.perspective_id
					LEFT OUTER JOIN structure_result f on a.emp_result_id = f.emp_result_id and c.structure_id = f.structure_id
					LEFT OUTER JOIN appraisal_criteria g on c.structure_id = g.structure_id and a.level_id = g.appraisal_level_id
					LEFT OUTER JOIN appraisal_level al on a.level_id = al.level_id
					LEFT OUTER JOIN emp_result h on a.emp_result_id = h.emp_result_id
					LEFT OUTER JOIN uom on  b.uom_id= uom.uom_id
					INNER JOIN assessor_group_structure ags on ags.structure_id = b.structure_id and ags.assessor_group_id = ?
					WHERE a.emp_result_id = ?
					AND c.form_id = 2
				) re
				ORDER BY re.structure_id ASC, re.assessor_group_id ASC, re.assessor_id ASC,  re.item_id ASC"
				,array($request->emp_result_id, $request->assessor_group_id, $request->assessor_group_id, $request->emp_result_id)
			);
			*/
			$items = DB::select("
				SELECT DISTINCT com.item_id, ai.item_name, ai.formula_desc, ai.structure_id, aps.structure_name, aps.form_id, 
					ft.form_name, ft.app_url , com.competency_result_id, com.item_result_id, emp.emp_result_id, com.period_id, 
					com.emp_id, em.emp_code, com.assessor_group_id, gr.assessor_group_name, com.assessor_id, aps.is_value_get_zero, 
					CONCAT('#',com.assessor_group_id,emp.emp_result_id,em.emp_id,' (',gr.assessor_group_name,')') as emp_name, 
					com.org_id, com.position_id, com.level_id, com.chief_emp_id, com.target_value, com.score, com.threshold_group_id, 
					air.weight_percent, com.group_weight_percent, com.weigh_score, air.percent_achievement, 
					emp.result_threshold_group_id, aps.nof_target_score, le.no_weight, g.weight_percent total_weight_percent, 
					0 as total_weigh_score, air.structure_weight_percent
				FROM competency_result com
				LEFT OUTER JOIN appraisal_level le on com.level_id = le.level_id
				LEFT OUTER JOIN appraisal_item ai on com.item_id = ai.item_id
				LEFT OUTER JOIN	appraisal_structure aps on ai.structure_id = aps.structure_id
				LEFT OUTER JOIN	form_type ft on aps.form_id = ft.form_id
				LEFT OUTER JOIN	assessor_group gr on com.assessor_group_id = gr.assessor_group_id
				LEFT OUTER JOIN	employee em on com.assessor_id = em.emp_id
				LEFT OUTER JOIN	appraisal_item_result air on com.item_result_id = air.item_result_id
				LEFT OUTER JOIN	emp_result emp on air.emp_result_id = emp.emp_result_id
				LEFT OUTER JOIN	structure_result f on emp.emp_result_id = f.emp_result_id
				LEFT OUTER JOIN	competency_criteria g on aps.structure_id = g.structure_id 
					AND air.level_id = g.appraisal_level_id
					AND g.assessor_group_id = com.assessor_group_id
				INNER JOIN assessor_group_structure ags on ags.structure_id = ai.structure_id
					AND ags.assessor_group_id = '{$request->assessor_group_id}'
				WHERE aps.form_id = 2
				AND emp.emp_result_id = '{$request->emp_result_id}'
				AND 5 = '{$request->assessor_group_id}'
				ORDER BY ai.structure_id ASC, com.assessor_group_id ASC, com.assessor_id ASC,  com.item_id ASC
			");

			if(empty($items)){
				$items = DB::select("
					SELECT DISTINCT b.item_id, b.item_name, b.formula_desc, b.structure_id, c.structure_name, d.form_id, d.form_name, d.app_url,
						0 as competency_result_id, a.item_result_id, a.emp_result_id, a.period_id, a.emp_id, emp.emp_code, 
						gg.assessor_group_id, gg.assessor_group_name, emp.emp_id as assessor_id, c.is_value_get_zero, emp.emp_name, 
						a.org_id, a.position_id, a.level_id, a.chief_emp_id, a.target_value, 0 as score, a.threshold_group_id, 
						a.weight_percent, 0 as group_weight_percent, a.weigh_score, a.percent_achievement, h.result_threshold_group_id, 
						c.nof_target_score, al.no_weight, g.weight_percent total_weight_percent, f.weigh_score total_weigh_score, a.structure_weight_percent
					FROM appraisal_item_result a
					LEFT OUTER JOIN appraisal_item b ON a.item_id = b.item_id
					INNER JOIN appraisal_item ai ON ai.item_id = a.item_id
					LEFT OUTER JOIN appraisal_structure c ON b.structure_id = c.structure_id
					LEFT OUTER JOIN form_type d ON c.form_id = d.form_id
					LEFT OUTER JOIN perspective e ON b.perspective_id = e.perspective_id
					LEFT OUTER JOIN structure_result f ON a.emp_result_id = f.emp_result_id AND c.structure_id = f.structure_id
					LEFT OUTER JOIN appraisal_level al ON a.level_id = al.level_id
					LEFT OUTER JOIN emp_result h ON a.emp_result_id = h.emp_result_id
					LEFT OUTER JOIN uom ON b.uom_id= uom.uom_id
					LEFT OUTER JOIN assessor_group gg ON gg.assessor_group_id = '{$request->assessor_group_id}'
					CROSS JOIN employee emp ON emp.emp_code = '{$auth}'
					LEFT OUTER JOIN competency_criteria g ON c.structure_id = g.structure_id AND a.level_id = g.appraisal_level_id AND g.assessor_group_id = '{$request->assessor_group_id}'
					INNER JOIN assessor_group_structure ags ON ags.structure_id = b.structure_id AND ags.assessor_group_id = '{$request->assessor_group_id}'
					WHERE a.emp_result_id = '{$request->emp_result_id}'
					AND c.form_id = 2
					UNION ALL
					SELECT * 
					FROM (
						SELECT DISTINCT com.item_id, ai.item_name, ai.formula_desc, ai.structure_id, aps.structure_name, aps.form_id, 
							ft.form_name, ft.app_url , com.competency_result_id, com.item_result_id, emp.emp_result_id, com.period_id, 
							com.emp_id, em.emp_code, com.assessor_group_id, gr.assessor_group_name, com.assessor_id, aps.is_value_get_zero, 
							CONCAT('#',com.assessor_group_id,emp.emp_result_id,em.emp_id,' (',gr.assessor_group_name,')') as emp_name, 
							com.org_id, com.position_id, com.level_id, com.chief_emp_id, com.target_value, com.score, com.threshold_group_id, 
							air.weight_percent, com.group_weight_percent, com.weigh_score, air.percent_achievement, 
							emp.result_threshold_group_id, aps.nof_target_score, le.no_weight, g.weight_percent total_weight_percent, 
							0 as total_weigh_score, air.structure_weight_percent
						FROM competency_result com
						LEFT OUTER JOIN appraisal_level le on com.level_id = le.level_id
						LEFT OUTER JOIN appraisal_item ai on com.item_id = ai.item_id
						LEFT OUTER JOIN	appraisal_structure aps on ai.structure_id = aps.structure_id
						LEFT OUTER JOIN	form_type ft on aps.form_id = ft.form_id
						LEFT OUTER JOIN	assessor_group gr on com.assessor_group_id = gr.assessor_group_id
						LEFT OUTER JOIN	employee em on com.assessor_id = em.emp_id
						LEFT OUTER JOIN	appraisal_item_result air on com.item_result_id = air.item_result_id
						LEFT OUTER JOIN	emp_result emp on air.emp_result_id = emp.emp_result_id
						LEFT OUTER JOIN	structure_result f on emp.emp_result_id = f.emp_result_id
						LEFT OUTER JOIN	competency_criteria g on aps.structure_id = g.structure_id 
							AND air.level_id = g.appraisal_level_id
							AND g.assessor_group_id = com.assessor_group_id
						INNER JOIN assessor_group_structure ags on ags.structure_id = ai.structure_id
							AND ags.assessor_group_id = '{$request->assessor_group_id}'
						WHERE aps.form_id = 2
						AND emp.emp_result_id = '{$request->emp_result_id}'
					) re
					ORDER BY structure_id ASC, assessor_group_id ASC, assessor_id ASC,  item_id ASC
				");
			}
		} elseif($request->assessor_group_id == 1){
			$check = DB::select("
				SELECT count(competency_result_id) AS num
				FROM competency_result c
				INNER JOIN appraisal_item_result i ON c.item_result_id = i.item_result_id
				INNER JOIN employee e ON c.assessor_id = e.emp_id
				WHERE i.emp_result_id = '{$request->emp_result_id}'
				AND assessor_group_id = '{$request->assessor_group_id}'
				AND e.emp_code = '{$auth}'");

			if($check[0]->num == 0){
				// Group 1 ไม่มีข้อมูล
				$items = DB::select("
					SELECT DISTINCT b.item_id, b.item_name, b.formula_desc, b.structure_id, c.structure_name, d.form_id, d.form_name, d.app_url,
						0 as competency_result_id, a.item_result_id, a.emp_result_id, a.period_id, a.emp_id, emp.emp_code, 
						gg.assessor_group_id, gg.assessor_group_name, emp.emp_id as assessor_id, c.is_value_get_zero, emp.emp_name, 
						a.org_id, a.position_id, a.level_id, a.chief_emp_id, a.target_value, 0 as score, a.threshold_group_id, 
						a.weight_percent, 0 as group_weight_percent, a.weigh_score, a.percent_achievement, h.result_threshold_group_id, 
						c.nof_target_score, al.no_weight, g.weight_percent total_weight_percent, f.weigh_score total_weigh_score, a.structure_weight_percent
					FROM appraisal_item_result a
					LEFT OUTER JOIN appraisal_item b ON a.item_id = b.item_id
					INNER JOIN appraisal_item ai ON ai.item_id = a.item_id
					LEFT OUTER JOIN appraisal_structure c ON b.structure_id = c.structure_id
					LEFT OUTER JOIN form_type d ON c.form_id = d.form_id
					LEFT OUTER JOIN perspective e ON b.perspective_id = e.perspective_id
					LEFT OUTER JOIN structure_result f ON a.emp_result_id = f.emp_result_id AND c.structure_id = f.structure_id
					LEFT OUTER JOIN appraisal_level al ON a.level_id = al.level_id
					LEFT OUTER JOIN emp_result h ON a.emp_result_id = h.emp_result_id
					LEFT OUTER JOIN uom ON b.uom_id= uom.uom_id
					LEFT OUTER JOIN assessor_group gg ON gg.assessor_group_id = '{$request->assessor_group_id}'
					CROSS JOIN employee emp ON emp.emp_code = '{$auth}'
					LEFT OUTER JOIN competency_criteria g ON c.structure_id = g.structure_id AND a.level_id = g.appraisal_level_id AND g.assessor_group_id = '{$request->assessor_group_id}'
					INNER JOIN assessor_group_structure ags ON ags.structure_id = b.structure_id AND ags.assessor_group_id = '{$request->assessor_group_id}'
					WHERE a.emp_result_id = '{$request->emp_result_id}'
					AND c.form_id = 2
					UNION ALL
					SELECT * 
					FROM (
						SELECT DISTINCT com.item_id, ai.item_name, ai.formula_desc, ai.structure_id, aps.structure_name, aps.form_id, 
							ft.form_name, ft.app_url , com.competency_result_id, com.item_result_id, emp.emp_result_id, com.period_id, 
							com.emp_id, em.emp_code, com.assessor_group_id, gr.assessor_group_name, com.assessor_id, aps.is_value_get_zero, 
							CONCAT('#',com.assessor_group_id,emp.emp_result_id,em.emp_id,' (',gr.assessor_group_name,')') as emp_name, 
							com.org_id, com.position_id, com.level_id, com.chief_emp_id, com.target_value, com.score, com.threshold_group_id, 
							air.weight_percent, com.group_weight_percent, com.weigh_score, air.percent_achievement, 
							emp.result_threshold_group_id, aps.nof_target_score, le.no_weight, g.weight_percent total_weight_percent, 
							0 as total_weigh_score, air.structure_weight_percent
						FROM competency_result com
						LEFT OUTER JOIN appraisal_level le on com.level_id = le.level_id
						LEFT OUTER JOIN appraisal_item ai on com.item_id = ai.item_id
						LEFT OUTER JOIN	appraisal_structure aps on ai.structure_id = aps.structure_id
						LEFT OUTER JOIN	form_type ft on aps.form_id = ft.form_id
						LEFT OUTER JOIN	assessor_group gr on com.assessor_group_id = gr.assessor_group_id
						LEFT OUTER JOIN	employee em on com.assessor_id = em.emp_id
						LEFT OUTER JOIN	appraisal_item_result air on com.item_result_id = air.item_result_id
						LEFT OUTER JOIN	emp_result emp on air.emp_result_id = emp.emp_result_id
						LEFT OUTER JOIN	structure_result f on emp.emp_result_id = f.emp_result_id
						LEFT OUTER JOIN	competency_criteria g on aps.structure_id = g.structure_id 
							AND air.level_id = g.appraisal_level_id
							AND g.assessor_group_id = com.assessor_group_id
						INNER JOIN assessor_group_structure ags on ags.structure_id = ai.structure_id
							AND ags.assessor_group_id = '{$request->assessor_group_id}'
						WHERE aps.form_id = 2
						AND emp.emp_result_id = '{$request->emp_result_id}'
						/*
						UNION ALL
						SELECT DISTINCT b.item_id, b.item_name, b.formula_desc, b.structure_id, c.structure_name, d.form_id, d.form_name, d.app_url,
							0 as competency_result_id, a.item_result_id, a.emp_result_id, a.period_id, 0 as emp_id, 'ALL' as emp_code, 
							0 as assessor_group_id, 'ทั้งหมด' as assessor_group_name, '0' as assessor_id, c.is_value_get_zero, 'ทั้งหมด' as emp_name, 
							a.org_id, a.position_id, a.level_id, a.chief_emp_id, a.target_value, a.score, a.threshold_group_id, 
							a.weight_percent, 0 as group_weight_percent, a.weigh_score, a.percent_achievement, h.result_threshold_group_id, 
							c.nof_target_score, al.no_weight, g.weight_percent total_weight_percent, f.weigh_score total_weigh_score, a.structure_weight_percent
						FROM appraisal_item_result a
						LEFT OUTER JOIN appraisal_item b on a.item_id = b.item_id
						INNER JOIN appraisal_item ai on ai.item_id = a.item_id
						LEFT OUTER JOIN appraisal_structure c on b.structure_id = c.structure_id
						LEFT OUTER JOIN form_type d on c.form_id = d.form_id
						LEFT OUTER JOIN perspective e on b.perspective_id = e.perspective_id
						LEFT OUTER JOIN structure_result f on a.emp_result_id = f.emp_result_id and c.structure_id = f.structure_id
						LEFT OUTER JOIN appraisal_criteria g on c.structure_id = g.structure_id and a.level_id = g.appraisal_level_id
						LEFT OUTER JOIN appraisal_level al on a.level_id = al.level_id
						LEFT OUTER JOIN emp_result h on a.emp_result_id = h.emp_result_id
						LEFT OUTER JOIN uom on  b.uom_id= uom.uom_id
						INNER JOIN assessor_group_structure ags on ags.structure_id = b.structure_id and ags.assessor_group_id = '{$request->assessor_group_id}'
						WHERE a.emp_result_id = '{$request->emp_result_id}'
						AND c.form_id = 2
						*/
					) re
					ORDER BY structure_id ASC, assessor_group_id ASC, assessor_id ASC,  item_id ASC");

			} else {
				// Group 1 พบข้อมูล

				/* ตัว all มีปัญหาเลยไม่เอามาใช้งาน (DHAS, 2018-11-28, p.wirun)
				$items = DB::select("
					SELECT * 
					FROM (
						SELECT DISTINCT com.item_id, ai.item_name, ai.formula_desc, ai.structure_id, aps.structure_name, aps.form_id, 
							ft.form_name, ft.app_url , com.competency_result_id, com.item_result_id, emp.emp_result_id, com.period_id, 
							com.emp_id, em.emp_code, com.assessor_group_id, gr.assessor_group_name, com.assessor_id, aps.is_value_get_zero, 
							CONCAT('#',com.assessor_group_id,emp.emp_result_id,em.emp_id,' (',gr.assessor_group_name,')') as emp_name, 
							com.org_id, com.position_id, com.level_id, com.chief_emp_id, com.target_value, com.score, com.threshold_group_id, 
							air.weight_percent, com.group_weight_percent, com.weigh_score, air.percent_achievement, 
							emp.result_threshold_group_id, aps.nof_target_score, le.no_weight, g.weight_percent total_weight_percent, 
							0 as total_weigh_score, air.structure_weight_percent
						FROM competency_result com
						LEFT OUTER JOIN appraisal_level le on com.level_id = le.level_id
						LEFT OUTER JOIN appraisal_item ai on com.item_id = ai.item_id
						LEFT OUTER JOIN	appraisal_structure aps on ai.structure_id = aps.structure_id
						LEFT OUTER JOIN	form_type ft on aps.form_id = ft.form_id
						LEFT OUTER JOIN	assessor_group gr on com.assessor_group_id = gr.assessor_group_id
						LEFT OUTER JOIN	employee em on com.assessor_id = em.emp_id
						LEFT OUTER JOIN	appraisal_item_result air on com.item_result_id = air.item_result_id
						LEFT OUTER JOIN	emp_result emp on air.emp_result_id = emp.emp_result_id
						LEFT OUTER JOIN	structure_result f on emp.emp_result_id = f.emp_result_id
						LEFT OUTER JOIN	competency_criteria g on aps.structure_id = g.structure_id 
							AND air.level_id = g.appraisal_level_id
							AND g.assessor_group_id = com.assessor_group_id
						INNER JOIN assessor_group_structure ags on ags.structure_id = ai.structure_id
							AND ags.assessor_group_id = '{$request->assessor_group_id}'
						WHERE aps.form_id = 2
						AND emp.emp_result_id = ?
						AND 1 = ?
						UNION ALL
						SELECT DISTINCT b.item_id, b.item_name, b.formula_desc, b.structure_id, c.structure_name, d.form_id, d.form_name, d.app_url,
							0 as competency_result_id, a.item_result_id, a.emp_result_id, a.period_id, 0 as emp_id, 'ALL' as emp_code, 
							0 as assessor_group_id, 'ทั้งหมด' as assessor_group_name, '0' as assessor_id, c.is_value_get_zero, 'ทั้งหมด' as emp_name, 
							a.org_id, a.position_id, a.level_id, a.chief_emp_id, a.target_value, a.score, a.threshold_group_id, 
							a.weight_percent, 0 as group_weight_percent, a.weigh_score, a.percent_achievement, h.result_threshold_group_id, 
							c.nof_target_score, al.no_weight, g.weight_percent total_weight_percent, f.weigh_score total_weigh_score, a.structure_weight_percent
						FROM appraisal_item_result a
						LEFT OUTER JOIN appraisal_item b on a.item_id = b.item_id
						INNER JOIN appraisal_item ai on ai.item_id = a.item_id
						LEFT OUTER JOIN appraisal_structure c on b.structure_id = c.structure_id
						LEFT OUTER JOIN form_type d on c.form_id = d.form_id
						LEFT OUTER JOIN perspective e on b.perspective_id = e.perspective_id
						LEFT OUTER JOIN structure_result f on a.emp_result_id = f.emp_result_id and c.structure_id = f.structure_id
						LEFT OUTER JOIN appraisal_criteria g on c.structure_id = g.structure_id and a.level_id = g.appraisal_level_id
						LEFT OUTER JOIN appraisal_level al on a.level_id = al.level_id
						LEFT OUTER JOIN emp_result h on a.emp_result_id = h.emp_result_id
						LEFT OUTER JOIN uom on  b.uom_id= uom.uom_id
						INNER JOIN assessor_group_structure ags on ags.structure_id = b.structure_id and ags.assessor_group_id = ?
						WHERE a.emp_result_id = ?
						AND c.form_id = 2
					) re
					ORDER BY re.structure_id ASC, re.assessor_group_id ASC, re.assessor_id ASC,  re.item_id ASC"
					,array($request->emp_result_id, $request->assessor_group_id, $request->assessor_group_id, $request->emp_result_id)
				);*/

				$items = DB::select("
					SELECT DISTINCT com.item_id, ai.item_name, ai.formula_desc, ai.structure_id, aps.structure_name, aps.form_id, 
						ft.form_name, ft.app_url , com.competency_result_id, com.item_result_id, emp.emp_result_id, com.period_id, 
						com.emp_id, em.emp_code, com.assessor_group_id, gr.assessor_group_name, com.assessor_id, aps.is_value_get_zero, 
						CONCAT('#',com.assessor_group_id,emp.emp_result_id,em.emp_id,' (',gr.assessor_group_name,')') as emp_name, 
						com.org_id, com.position_id, com.level_id, com.chief_emp_id, com.target_value, com.score, com.threshold_group_id, 
						air.weight_percent, com.group_weight_percent, com.weigh_score, air.percent_achievement, 
						emp.result_threshold_group_id, aps.nof_target_score, le.no_weight, g.weight_percent total_weight_percent, 
						0 as total_weigh_score, air.structure_weight_percent
					FROM competency_result com
					LEFT OUTER JOIN appraisal_level le on com.level_id = le.level_id
					LEFT OUTER JOIN appraisal_item ai on com.item_id = ai.item_id
					LEFT OUTER JOIN	appraisal_structure aps on ai.structure_id = aps.structure_id
					LEFT OUTER JOIN	form_type ft on aps.form_id = ft.form_id
					LEFT OUTER JOIN	assessor_group gr on com.assessor_group_id = gr.assessor_group_id
					LEFT OUTER JOIN	employee em on com.assessor_id = em.emp_id
					LEFT OUTER JOIN	appraisal_item_result air on com.item_result_id = air.item_result_id
					LEFT OUTER JOIN	emp_result emp on air.emp_result_id = emp.emp_result_id
					LEFT OUTER JOIN	structure_result f on emp.emp_result_id = f.emp_result_id
					LEFT OUTER JOIN	competency_criteria g on aps.structure_id = g.structure_id 
						AND air.level_id = g.appraisal_level_id
						AND g.assessor_group_id = com.assessor_group_id
					INNER JOIN assessor_group_structure ags on ags.structure_id = ai.structure_id
						AND ags.assessor_group_id = '{$request->assessor_group_id}'
					WHERE aps.form_id = 2
					AND emp.emp_result_id = '{$request->emp_result_id}'
					AND 1 = '{$request->assessor_group_id}'
					ORDER BY ai.structure_id ASC, com.assessor_group_id ASC, com.assessor_id ASC,  com.item_id ASC
				");
			}
		} else {
			// Group 2, 3, 4 จะต้องแสดงข้อมูลของหัวหน้าคนที่ 1 (group 1)
			$check = DB::select("
				SELECT count(competency_result_id) AS num
				FROM competency_result c
				INNER JOIN appraisal_item_result i ON c.item_result_id = i.item_result_id
				INNER JOIN employee e ON c.assessor_id = e.emp_id
				WHERE i.emp_result_id = '{$request->emp_result_id}'
				AND assessor_group_id = 1
			");

			if($check[0]->num == 0){
				$items = DB::select("
					SELECT DISTINCT b.item_id, b.item_name, b.formula_desc, b.structure_id, c.structure_name, d.form_id, d.form_name, d.app_url,
						0 as competency_result_id, a.item_result_id, a.emp_result_id, a.period_id, a.emp_id, emp.emp_code, 
						gg.assessor_group_id, gg.assessor_group_name, emp.emp_id as assessor_id, c.is_value_get_zero, emp.emp_name, 
						a.org_id, a.position_id, a.level_id, a.chief_emp_id, a.target_value, 0 as score, a.threshold_group_id, 
						a.weight_percent, 0 as group_weight_percent, a.weigh_score, a.percent_achievement, h.result_threshold_group_id, 
						c.nof_target_score, al.no_weight, g.weight_percent total_weight_percent, f.weigh_score total_weigh_score, 
						a.structure_weight_percent
					FROM appraisal_item_result a 
					LEFT OUTER JOIN appraisal_item b ON a.item_id = b.item_id
					INNER JOIN appraisal_item ai ON ai.item_id = a.item_id
					LEFT OUTER JOIN appraisal_structure c ON b.structure_id = c.structure_id
					LEFT OUTER JOIN form_type d ON c.form_id = d.form_id
					LEFT OUTER JOIN perspective e ON b.perspective_id = e.perspective_id
					LEFT OUTER JOIN structure_result f ON a.emp_result_id = f.emp_result_id AND c.structure_id = f.structure_id
					LEFT OUTER JOIN appraisal_level al ON a.level_id = al.level_id
					LEFT OUTER JOIN emp_result h ON a.emp_result_id = h.emp_result_id
					LEFT OUTER JOIN uom ON  b.uom_id= uom.uom_id
					LEFT OUTER JOIN assessor_group gg ON gg.assessor_group_id = '{$request->assessor_group_id}'
					CROSS JOIN employee emp ON emp.emp_code = '{$auth}'
					LEFT OUTER JOIN competency_criteria g ON c.structure_id = g.structure_id 
						AND a.level_id = g.appraisal_level_id 
						AND g.assessor_group_id = '{$request->assessor_group_id}'
					INNER JOIN assessor_group_structure ags ON ags.structure_id = b.structure_id
						AND ags.assessor_group_id = '{$request->assessor_group_id}'
					WHERE a.emp_result_id = '{$request->emp_result_id}'
					AND c.form_id = 2
					ORDER BY b.item_id ASC
				");
			} else {
				$displayAssessor = AssessorGroup::find($request->assessor_group_id);
				$items = DB::select("
					SELECT DISTINCT com.item_id, ai.item_name, ai.formula_desc, ai.structure_id, aps.structure_name, aps.form_id, 
						ft.form_name, ft.app_url , com.competency_result_id, com.item_result_id, emp.emp_result_id, com.period_id, 
						com.emp_id, em.emp_code, {$displayAssessor->assessor_group_id} assessor_group_id, '{$displayAssessor->assessor_group_name}' assessor_group_name, com.assessor_id, aps.is_value_get_zero, 
						CONCAT('#','{$displayAssessor->assessor_group_id}',emp.emp_result_id,em.emp_id,' (','{$displayAssessor->assessor_group_name}',')') as emp_name, 
						com.org_id, com.position_id, com.level_id, com.chief_emp_id, com.target_value, com.score, com.threshold_group_id, 
						air.weight_percent, com.group_weight_percent, com.weigh_score, air.percent_achievement, 
						emp.result_threshold_group_id, aps.nof_target_score, le.no_weight, g.weight_percent total_weight_percent, 
						0 as total_weigh_score, air.structure_weight_percent
					FROM competency_result com
					LEFT OUTER JOIN appraisal_level le on com.level_id = le.level_id
					LEFT OUTER JOIN appraisal_item ai on com.item_id = ai.item_id
					LEFT OUTER JOIN	appraisal_structure aps on ai.structure_id = aps.structure_id
					LEFT OUTER JOIN	form_type ft on aps.form_id = ft.form_id
					LEFT OUTER JOIN	assessor_group gr on com.assessor_group_id = gr.assessor_group_id
					LEFT OUTER JOIN	employee em on com.assessor_id = em.emp_id
					LEFT OUTER JOIN	appraisal_item_result air on com.item_result_id = air.item_result_id
					LEFT OUTER JOIN	emp_result emp on air.emp_result_id = emp.emp_result_id
					LEFT OUTER JOIN	structure_result f on emp.emp_result_id = f.emp_result_id
					LEFT OUTER JOIN	competency_criteria g on aps.structure_id = g.structure_id 
						AND air.level_id = g.appraisal_level_id
						AND g.assessor_group_id = com.assessor_group_id
					INNER JOIN assessor_group_structure ags on ags.structure_id = ai.structure_id
						AND ags.assessor_group_id = 1
					WHERE aps.form_id = 2
					AND emp.emp_result_id = '{$request->emp_result_id}'
					AND 1 = 1
					ORDER BY ai.structure_id ASC, com.assessor_group_id ASC, com.assessor_id ASC, com.item_id ASC
				");
			}
		}
		/* (DHAS, 2018-11-28, p.wirun)
		else {
			$check = DB::select("select count(competency_result_id) as num
				from competency_result c
				inner join appraisal_item_result i on c.item_result_id = i.item_result_id
				inner join employee e on c.assessor_id = e.emp_id
				where i.emp_result_id = ? 
				and assessor_group_id = ?
				and e.emp_code = '{$auth}' "
			,array($request->emp_result_id, $request->assessor_group_id));

			// if($check[0]->num == 0){
			// 	$items = DB::select("
			// 		select DISTINCT b.item_id, b.item_name, b.formula_desc, b.structure_id, c.structure_name, d.form_id, d.form_name, d.app_url
			// 		,0 as competency_result_id, a.item_result_id, a.emp_result_id, a.period_id, a.emp_id, emp.emp_code
			// 		, gg.assessor_group_id, gg.assessor_group_name, emp.emp_id as assessor_id, c.is_value_get_zero, emp.emp_name
			// 		, a.org_id, a.position_id, a.level_id, a.chief_emp_id, a.target_value, 0 as score, a.threshold_group_id
			// 		, a.weight_percent, 0 as group_weight_percent, a.weigh_score, a.percent_achievement, h.result_threshold_group_id
			// 		, c.nof_target_score, al.no_weight, g.weight_percent total_weight_percent, f.weigh_score total_weigh_score
			// 		, a.structure_weight_percent
			// 		from appraisal_item_result a
			// 		left outer join appraisal_item b
			// 		on a.item_id = b.item_id
			// 		INNER JOIN appraisal_item ai
			// 		on ai.item_id = a.item_id
			// 		left outer join appraisal_structure c
			// 		on b.structure_id = c.structure_id
			// 		left outer join form_type d
			// 		on c.form_id = d.form_id
			// 		left outer join perspective e
			// 		on b.perspective_id = e.perspective_id
			// 		left outer join structure_result f
			// 		on a.emp_result_id = f.emp_result_id
			// 		and c.structure_id = f.structure_id
			// 		-- left outer join appraisal_criteria g
			// 		-- on c.structure_id = g.structure_id
			// 		-- and a.level_id = g.appraisal_level_id
			// 		left outer join appraisal_level al
			// 		on a.level_id = al.level_id
			// 		left outer join emp_result h
			// 		on a.emp_result_id = h.emp_result_id
			// 		left join uom on  b.uom_id= uom.uom_id
			// 		left join assessor_group gg on gg.assessor_group_id = ?
			// 		cross join employee emp on emp.emp_code = '{$auth}'
			// 		left outer join competency_criteria g on c.structure_id = g.structure_id
			// 			and a.level_id = g.appraisal_level_id
			// 			and g.assessor_group_id = ?
			// 		-- cross join (select emp_id, emp_code, emp_name from employee where emp_code = '{$auth}') emp
			// 		inner join assessor_group_structure ags on ags.structure_id = b.structure_id
			// 				and ags.assessor_group_id = ?
			// 		where a.emp_result_id = ?
			// 		and c.form_id = 2
			// 		order by b.item_id asc"
			// 	,array($request->assessor_group_id, $request->assessor_group_id, $request->assessor_group_id, $request->emp_result_id));
				
				
				$items = DB::select("
					SELECT DISTINCT b.item_id, b.item_name, b.formula_desc, b.structure_id, c.structure_name, d.form_id, d.form_name, d.app_url,
						0 as competency_result_id, a.item_result_id, a.emp_result_id, a.period_id, a.emp_id, emp.emp_code, 
						gg.assessor_group_id, gg.assessor_group_name, emp.emp_id as assessor_id, c.is_value_get_zero, emp.emp_name, 
						a.org_id, a.position_id, a.level_id, a.chief_emp_id, a.target_value, 0 as score, a.threshold_group_id, 
						a.weight_percent, 0 as group_weight_percent, a.weigh_score, a.percent_achievement, h.result_threshold_group_id, 
						c.nof_target_score, al.no_weight, g.weight_percent total_weight_percent, f.weigh_score total_weigh_score, 
						a.structure_weight_percent
					FROM appraisal_item_result a 
					LEFT OUTER JOIN appraisal_item b ON a.item_id = b.item_id
					INNER JOIN appraisal_item ai ON ai.item_id = a.item_id
					LEFT OUTER JOIN appraisal_structure c ON b.structure_id = c.structure_id
					LEFT OUTER JOIN form_type d ON c.form_id = d.form_id
					LEFT OUTER JOIN perspective e ON b.perspective_id = e.perspective_id
					LEFT OUTER JOIN structure_result f ON a.emp_result_id = f.emp_result_id AND c.structure_id = f.structure_id
					LEFT OUTER JOIN appraisal_level al ON a.level_id = al.level_id
					LEFT OUTER JOIN emp_result h ON a.emp_result_id = h.emp_result_id
					LEFT OUTER JOIN uom ON  b.uom_id= uom.uom_id
					LEFT OUTER JOIN assessor_group gg ON gg.assessor_group_id = '{$request->assessor_group_id}'
					CROSS JOIN employee emp ON emp.emp_code = '{$auth}'
					LEFT OUTER JOIN competency_criteria g ON c.structure_id = g.structure_id 
						AND a.level_id = g.appraisal_level_id 
						AND g.assessor_group_id = '{$request->assessor_group_id}'
					INNER JOIN assessor_group_structure ags ON ags.structure_id = b.structure_id
						AND ags.assessor_group_id = '{$request->assessor_group_id}'
					WHERE a.emp_result_id = '{$request->emp_result_id}'
					AND c.form_id = 2
					ORDER BY b.item_id ASC
				");
			}else {
				// $items = DB::select("
				// 	select distinct com.item_id, ai.item_name, ai.formula_desc, ai.structure_id, aps.structure_name, aps.form_id
				// 	, ft.form_name, ft.app_url , com.competency_result_id, com.item_result_id, emp.emp_result_id, com.period_id
				// 	, com.emp_id, em.emp_code, com.assessor_group_id, gr.assessor_group_name, com.assessor_id, aps.is_value_get_zero
				// 	, CONCAT('#',com.assessor_group_id,emp.emp_result_id,em.emp_id,' (',gr.assessor_group_name,')') as emp_name
				// 	, com.org_id, com.position_id, com.level_id, com.chief_emp_id, com.target_value, com.score, com.threshold_group_id
				// 	, air.weight_percent, com.group_weight_percent, com.weigh_score, air.percent_achievement, air.structure_weight_percent
				// 	, emp.result_threshold_group_id, aps.nof_target_score, le.no_weight, g.weight_percent total_weight_percent
				// 	, 0 as total_weigh_score
				// 	from competency_result com
				// 	left outer join appraisal_level le on com.level_id = le.level_id
				// 	left outer join appraisal_item ai on com.item_id = ai.item_id
				// 	left outer join appraisal_structure aps on ai.structure_id = aps.structure_id
				// 	left outer join form_type ft on aps.form_id = ft.form_id
				// 	left outer join assessor_group gr on com.assessor_group_id = gr.assessor_group_id
				// 	left outer join employee em on com.assessor_id = em.emp_id
				// 	left outer join appraisal_item_result air on com.item_result_id = air.item_result_id
				// 	left outer join emp_result emp on air.emp_result_id = emp.emp_result_id
				// 	left outer join structure_result f on emp.emp_result_id = f.emp_result_id
				// 	-- left outer join appraisal_criteria g on aps.structure_id = g.structure_id
				// 	-- 	and air.level_id = g.appraisal_level_id
				// 	left outer join competency_criteria g on aps.structure_id = g.structure_id
				// 			and air.level_id = g.appraisal_level_id
				// 			and g.assessor_group_id = ?
				// 	inner join assessor_group_structure ags on ags.structure_id = ai.structure_id
				// 		and ags.assessor_group_id = ?
				// 	where aps.form_id = 2
				// 	and emp.emp_result_id = ?
				// 	and em.emp_code = '{$auth}'
				// 	order by ai.structure_id asc,com.assessor_group_id asc, com.assessor_id asc, com.item_id asc"
				// ,array($request->assessor_group_id, $request->assessor_group_id, $request->emp_result_id));

				$items = DB::select("
					SELECT DISTINCT com.item_id, ai.item_name, ai.formula_desc, ai.structure_id, aps.structure_name, aps.form_id, 
						ft.form_name, ft.app_url , com.competency_result_id, com.item_result_id, emp.emp_result_id, com.period_id, 
						com.emp_id, em.emp_code, com.assessor_group_id, gr.assessor_group_name, com.assessor_id, aps.is_value_get_zero, 
						CONCAT('#',com.assessor_group_id,emp.emp_result_id,em.emp_id,' (',gr.assessor_group_name,')') as emp_name, 
						com.org_id, com.position_id, com.level_id, com.chief_emp_id, com.target_value, com.score, com.threshold_group_id, 
						air.weight_percent, com.group_weight_percent, com.weigh_score, air.percent_achievement, air.structure_weight_percent, 
						emp.result_threshold_group_id, aps.nof_target_score, le.no_weight, g.weight_percent total_weight_percent, 
						0 as total_weigh_score
					FROM competency_result com
					LEFT OUTER JOIN appraisal_level le on com.level_id = le.level_id
					LEFT OUTER JOIN appraisal_item ai on com.item_id = ai.item_id
					LEFT OUTER JOIN appraisal_structure aps on ai.structure_id = aps.structure_id
					LEFT OUTER JOIN form_type ft on aps.form_id = ft.form_id
					LEFT OUTER JOIN assessor_group gr on com.assessor_group_id = gr.assessor_group_id
					LEFT OUTER JOIN employee em on com.assessor_id = em.emp_id
					LEFT OUTER JOIN appraisal_item_result air on com.item_result_id = air.item_result_id
					LEFT OUTER JOIN emp_result emp on air.emp_result_id = emp.emp_result_id
					LEFT OUTER JOIN structure_result f on emp.emp_result_id = f.emp_result_id
					LEFT OUTER JOIN competency_criteria g on aps.structure_id = g.structure_id
						AND air.level_id = g.appraisal_level_id
						AND g.assessor_group_id = '{$request->assessor_group_id}'
					INNER JOIN assessor_group_structure ags on ags.structure_id = ai.structure_id
						AND ags.assessor_group_id = '{$request->assessor_group_id}'
					WHERE aps.form_id = 2
					AND emp.emp_result_id = '{$request->emp_result_id}'
					AND em.emp_code = '{$auth}'
					ORDER BY ai.structure_id ASC,com.assessor_group_id ASC, com.assessor_id ASC, com.item_id ASC
				");
			}
		}
		*/

		// return response()->json($items);

		$groups = array();
		foreach($items as $item){

			$check_max = DB::select("
				SELECT nof_target_score as max_no FROM
			appraisal_structure
			where  structure_id=?
			", array($item->structure_id));

			$total_weight = ($check_max[0]->max_no * $item->total_weight_percent) / 100;

			$hint = array();
			
			if ($item->form_id == 2) {
				$hint = DB::select("
					select concat(a.target_score,' = ',a.threshold_name) hint
					from threshold a
					left outer join threshold_group b
					on a.threshold_group_id = b.threshold_group_id
					where b.is_active = 1
					and a.structure_id=?
					order by target_score asc
				", array($item->structure_id));
			}

			$key = $item->structure_name;
			$k = $item->assessor_group_name;
			$emp = $item->emp_name;
			if (!isset($groups[$key])) {
				$groups[$key] = array(
					'count' => 1,
					'form_id' => $item->form_id,
					'form_url' => $item->app_url,
					'structure_name' => $item->structure_name,
					'structure_id' => $item->structure_id,
					'hint' => $hint,
				);
				//in $key
				if (!isset($groups[$key][$k])) {
					$groups[$key][$k] = array(
						'group_name' => $item->assessor_group_name,
						'group_id' => $item->assessor_group_id,
						'total_weight_percent' => $item->total_weight_percent,
						'structure_weight_percent' => $item->structure_weight_percent,
						'result_type' => $config->result_type,
						'no_weight' => $item->no_weight,
						'total_weight' => $total_weight,
					);
					//in $key $k 
					if (!isset($groups[$key][$k][$emp])) {
						$groups[$key][$k][$emp] = array(
							'items' => array($item),
							'emp_name' => $item->emp_name,
							'emp_id' => $item->assessor_id,
							'total_weigh_score' => $item->total_weigh_score,
							'structure_weight_percent' => $item->structure_weight_percent,							
						);
					}else {
						$groups[$key][$k][$emp]['items'][] = $item;
					}
				}else {
					//in $key $k 
					if (!isset($groups[$key][$k][$emp])) {
						$groups[$key][$k][$emp] = array(
							'items' => array($item),
							'emp_name' => $item->emp_name,
							'emp_id' => $item->assessor_id,
							'total_weigh_score' => $item->total_weigh_score,
							'structure_weight_percent' => $item->structure_weight_percent,
						);
					}else {
						$groups[$key][$k][$emp]['items'][] = $item;
					}
				}
			} else {
				$groups[$key]['count'] += 1;
				//in $key
				if (!isset($groups[$key][$k])) {
					$groups[$key][$k] = array(
						'group_name' => $item->assessor_group_name,
						'group_id' => $item->assessor_group_id,
						'total_weigh_score' => $item->total_weigh_score,
						'total_weight_percent' => $item->total_weight_percent,
						'structure_weight_percent' => $item->structure_weight_percent,
						'result_type' => $config->result_type,
						'no_weight' => $item->no_weight,
						'total_weight' => $total_weight,
					);
					//in $key $k 
					if (!isset($groups[$key][$k][$emp])) {
						$groups[$key][$k][$emp] = array(
							'items' => array($item),
							'emp_name' => $item->emp_name,
							'emp_id' => $item->assessor_id,
							'total_weigh_score' => $item->total_weigh_score,
							'structure_weight_percent' => $item->structure_weight_percent,
						);
					}else {
						$groups[$key][$k][$emp]['items'][] = $item;
					}
				}else {
					//in $key $k 
					if (!isset($groups[$key][$k][$emp])) {
						$groups[$key][$k][$emp] = array(
							'items' => array($item),
							'emp_name' => $item->emp_name,
							'emp_id' => $item->assessor_id,
							'total_weigh_score' => $item->total_weigh_score,
							'structure_weight_percent' => $item->structure_weight_percent,
						);
					}else {
						$groups[$key][$k][$emp]['items'][] = $item;
					}
				}
			}
		}

		return response()->json($groups);
	}

	
	public function update(Request $request)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		$now = new DateTime();
		$datas = json_decode($request->data);
		$auth = Auth::id();

		foreach ($datas as $da){

			$chief_emp = $da->chief_emp_id;

			$item_result = DB::select("
					select air.item_result_id
					, air.period_id
					, air.emp_id
					, air.org_id
					, air.position_id
					, air.item_id
					, air.level_id
					, air.item_name
					, '{$chief_emp}' as chief_emp_id
					, air.threshold_group_id
					, emp.auth_emp_id
					from appraisal_item_result air
					cross join (select emp_id as auth_emp_id from employee where emp_code = '{$auth}') emp 
					where air.item_result_id = ? "
			,array($da->item_result_id));
			//เนื่องจาก chief_emp_id จากตาราง appraisal_item_result เป็นค่า null จึงต้องใช้ค่าจากหน้าจอ เพราะตาราง CompetencyResult มี type : chief_emp_id เป็น not null

			// return ($auth);
			// return response()->json($item_result);
		
			foreach ($item_result as $item){
				if($da->group_id == 0){

					// update to app..item_result
					$item_result = AppraisalItemResult::find($da->item_result_id);
					$item_result->score = $da->score;
					$item_result->updated_by = $auth;
					$item_result->updated_dttm = $now;
					$item_result->save();

				}else {
					$competency = CompetencyResult::find($da->competency_result_id);
					if(empty($competency)){
					
						$competency = new CompetencyResult;
						$competency->item_result_id = $item->item_result_id;
						$competency->period_id = $item->period_id;
						$competency->emp_id = $item->emp_id;
						$competency->org_id = $item->org_id;
						$competency->position_id = $item->position_id;
						$competency->item_id = $item->item_id;
						$competency->level_id = $item->level_id;
						$competency->item_name = $item->item_name;
						$competency->chief_emp_id = $item->chief_emp_id;
						$competency->assessor_id = $item->auth_emp_id;
						$competency->threshold_group_id = $item->threshold_group_id;
						$competency->assessor_group_id = $da->group_id;
						$competency->target_value = $da->target_value;
						$competency->score = $da->score;
						$competency->weight_percent = $da->weight_percent;
						$competency->group_weight_percent = $da->group_weight_percent ;
						
						if($config->result_type == 1){
							$competency->weigh_score = $da->score * $da->weight_percent;
						} elseif ($config->result_type == 2) {
							$competency->weigh_score = ($da->score * $da->weight_percent) / 100;
						}
						
						$competency->created_by = $auth;
						$competency->created_dttm = $now;
						$competency->save();
					} else {

						if($config->result_type == 1){
							$competency->weigh_score = $da->score * $da->weight_percent;
						} elseif ($config->result_type == 2) {
							$competency->weigh_score = ($da->score * $da->weight_percent) / 100;
						}
						$competency->weight_percent = $da->weight_percent;
						$competency->score = $da->score;
						$competency->group_weight_percent = $da->group_weight_percent ;
						$competency->updated_by = $auth;
						$competency->updated_dttm = $now;
						$competency->save();
					}
				}
			}//end for item
		}//end for datas

		
		return response()->json(['status' => 200]);
	}
	
	public function calculate_etl(Request $request){ 
	
		if(empty($request->emp_result_id)){
			return response()->json(["status"=>400, "data"=>"emp_result_id not found"]); 
		}
		
		$server = "10.7.200.176"; // server IP/hostname of the SSH server 
		$username = "root"; // username for the user you are connecting as on the SSH server 
		$password = "seekpi@1234"; // password for the user you are connecting as on the SSH server 
		$command = "sh /root/etl/batch_file/mainjob_appraisal360.sh {$request->emp_result_id} {$request->start_date}"; // could be anything available on the server you are SSH'ing to 

		// Establish a connection to the SSH Server. Port is the second param. 
		$connection = ssh2_connect($server, 22); 

		// Authenticate with the SSH server 
		//ssh2_auth_password($connection, $username, $password); 
		if ( ! ssh2_auth_password($connection, $username, $password)) { 
			return response()->json(["status"=>400, "data"=>'SSH Authentication Failed...']); 
		} 

		// Execute a command on the connected server and capture the response 
		$stream = ssh2_exec($connection, $command); 


		// Sets blocking mode on the stream 
		stream_set_blocking($stream, true); 

		// Get the response of the executed command in a human readable form 
		$output = stream_get_contents($stream); 

		// echo output 
		// echo "<pre>{$output}</pre>"; 
		return response()->json(["status"=>200, "data"=>$output]); 
	}
	

	public function getAssessorGroup($searchEmpCode)
	{
		$loginEmpCode = Auth::id();
		$assGroupId = 0; 
		$assGroupName = "";

		$isChief = $this->GetallChiefEmp($searchEmpCode)->contains($loginEmpCode);
		$isUnder = $this->GetallUnderEmp($searchEmpCode)->contains($loginEmpCode);

		$loginEmpLevel = collect(DB::select("
			SELECT is_all_employee, is_hr 
			FROM appraisal_level 
			WHERE level_id = (
				SELECT emp.level_id 
				FROM employee emp
				WHERE emp.emp_code = '{$loginEmpCode}'
			)
		"))->first();
		
		if($loginEmpLevel != null){
			if($loginEmpLevel->is_all_employee == 1 || $loginEmpLevel->is_hr == 1){
				$assGroupId = 5;
			} elseif ($isChief){
				$assGroupId = 1;
			} elseif ($isUnder){
				$assGroupId = 2;
			} elseif ($loginEmpCode == $searchEmpCode) {
				$assGroupId = 4;
			} else {
				$assGroupId = 3;
			}
		}

		return AssessorGroup::find($assGroupId);
	}

	private function GetallChiefEmp($paramEmp)
	{
		$chiefEmpCollect = collect([]);

		$initChiefEmp = DB::select("
			SELECT chief_emp_code
			FROM employee
			WHERE emp_code = '{$paramEmp}'
		");

		$initChiefEmp = DB::table("employee")->select("chief_emp_code")->where("emp_code", $paramEmp)->first();
		$chiefEmpCollect->push($initChiefEmp->chief_emp_code);
		$curChiefEmp = $initChiefEmp->chief_emp_code;

		while ($curChiefEmp != "0") {
			$getChief = DB::table("employee")->select("chief_emp_code")->where("emp_code", $curChiefEmp)->first();
			if(empty($getChief)) {
				$curChiefEmp = "0";
			} else {
				if($chiefEmpCollect->contains($getChief->chief_emp_code)) {
					$curChiefEmp = "0";
				} else {
					$chiefEmpCollect->push($getChief->chief_emp_code);
					$curChiefEmp = $getChief->chief_emp_code;
				}
			}
		} 
		
		return $chiefEmpCollect;
	}

	private function GetallUnderEmp($paramEmp)
	{
		$globalEmpCodeSet = "";
		$inLoop = true;
		$loopCnt = 1;

		while ($inLoop){
			if($loopCnt == 1){
				$LoopEmpCodeSet = $paramEmp.",";
			}
			
			// Check each under //
			$eachUnder = DB::select("
				SELECT emp_code
				FROM employee
				WHERE find_in_set(chief_emp_code, '{$LoopEmpCodeSet}')
			");

			if(empty($eachUnder)){
				$inLoop = false;
			} else {
				$LoopEmpCodeSet = "";
				foreach ($eachUnder as $emp) {
					$LoopEmpCodeSet .= $emp->emp_code.",";
				}
				$globalEmpCodeSet .= $LoopEmpCodeSet;
			}
			$loopCnt = $loopCnt + 1;
		}
		
		return collect(explode(',', $globalEmpCodeSet));
	}


	public function edit_action_to(Request $request)
	{
		$items = DB::select("
			SELECT stage_id, to_action
			FROM appraisal_stage 
			WHERE from_stage_id = {$request->stage_id}
			AND appraisal_flag = 1
			AND appraisal_type_id = {$request->appraisal_type_id}
			AND find_in_set({$request->appraisal_group_id}, assessor_see)
		");


		if (empty($items)) {
			$workflow = WorkflowStage::find($request->stage_id);
			empty($workflow->to_stage_id) ? $to_stage_id = "null" : $to_stage_id = $workflow->to_stage_id;
			$items = DB::select("	
				SELECT stage_id, to_action
				FROM appraisal_stage
				WHERE stage_id in ({$to_stage_id})
				AND appraisal_flag = 1
				AND appraisal_type_id = '{$request->appraisal_type_id}'
				AND find_in_set('{$request->appraisal_group_id}', assessor_see)
			");
		}

		return response()->json($items);
	}


	public function GetCompetencyInfo(Request $request){
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		if(in_array($request->assessor_group_id, [1,4,5])){
			$assessorGroupDataQrySrt = "";
		} else {
			$assessorGroupDataQrySrt = "AND com.assessor_group_id = '{$request->assessor_group_id}'";
		}
		if(in_array($request->assessor_group_id, [1,5])){
			$assessorGroupParamQrySrt = "";
		} else {
			$assessorGroupParamQrySrt = "AND com.assessor_group_id = '{$request->assessor_group_id}'";
		}

		// generate temp competency valeu if not exists
		$auth = Auth::id();
		$assessorGroup = AssessorGroup::find($request->assessor_group_id);
		$competencyResult = DB::select("
			SELECT com.*
			FROM competency_result com
			INNER JOIN appraisal_item_result air ON air.item_result_id = com.item_result_id
			WHERE emp_result_id = '{$request->emp_result_id}'
			AND com.assessor_group_id = '{$request->assessor_group_id}'
		");
		$orderbyQryStr = "ORDER BY ai.structure_id ASC, com.assessor_group_id ASC, com.assessor_id ASC,  com.item_id ASC";
		if($competencyResult){
			$unionTempDataSql = "";
		} else {
			$unionTempDataSql = "
				UNION
				SELECT DISTINCT
					0 competency_result_id, air.item_result_id, '{$assessorGroup->assessor_group_id}' assessor_group_id, '{$assessorGroup->assessor_group_name}' assessor_group_name,
					ai.structure_id, aps.structure_name, air.item_id, ai.item_name, ai.formula_desc,
					(
						SELECT emp_id 
						FROM employee 
						WHERE emp_code = '{$auth}'
					) AS assessor_id,
					CONCAT('#', '{$assessorGroup->assessor_group_id}', er.emp_result_id, er.emp_id,' (','{$assessorGroup->assessor_group_name}',')') as emp_name,
					air.structure_weight_percent, aps.nof_target_score, air.weight_percent,
					cc.weight_percent AS group_weight_percent, air.target_value, 0 score, 0 weigh_score,
					IFNULL(air.score, 0) AS item_result_score,
					IFNULL(air.weight_percent, 0) AS item_result_weight_percent,
					IFNULL(air.weigh_score, 0) AS item_result_weigh_score,
					IFNULL(sr.weigh_score, 0) AS structure_result_weigh_score,
					IFNULL(sr.nof_target_score, 0) AS structure_result_nof_target_score
				FROM appraisal_item_result air
				LEFT OUTER JOIN appraisal_item ai on air.item_id = ai.item_id
				LEFT OUTER JOIN	appraisal_structure aps on ai.structure_id = aps.structure_id
				LEFT OUTER JOIN	emp_result er on air.emp_result_id = er.emp_result_id
				LEFT OUTER JOIN	competency_criteria cc
					ON cc.structure_id = aps.structure_id
					AND cc.appraisal_level_id = air.level_id
					AND cc.assessor_group_id = '{$assessorGroup->assessor_group_id}'
				LEFT OUTER JOIN competency_result cr ON cr.item_result_id = air.item_result_id
				LEFT OUTER JOIN structure_result sr on er.emp_result_id = sr.emp_result_id AND sr.structure_id = ai.structure_id
				WHERE aps.form_id = 2
				AND er.emp_result_id = '{$request->emp_result_id}'
			";

			$orderbyQryStr = "ORDER BY structure_id ASC, assessor_group_id ASC, assessor_id ASC,  item_id ASC";
		}

		$items = DB::select("
			SELECT DISTINCT
				com.competency_result_id, com.item_result_id, com.assessor_group_id, gr.assessor_group_name,
				ai.structure_id, aps.structure_name, com.item_id, ai.item_name, ai.formula_desc, com.assessor_id,
				CONCAT('#',com.assessor_group_id,emp.emp_result_id,em.emp_id,' (',gr.assessor_group_name,')') as emp_name,
				air.structure_weight_percent, aps.nof_target_score, air.weight_percent,
				com.group_weight_percent, com.target_value, com.score, com.weigh_score,
				IFNULL(air.score, 0) AS item_result_score,
				IFNULL(air.weight_percent, 0) AS item_result_weight_percent,
				IFNULL(air.weigh_score, 0) AS item_result_weigh_score,
				IFNULL(sr.weigh_score, 0) AS structure_result_weigh_score,
				IFNULL(sr.nof_target_score, 0) AS structure_result_nof_target_score
			FROM competency_result com
			LEFT OUTER JOIN appraisal_level le on com.level_id = le.level_id
			LEFT OUTER JOIN appraisal_item ai on com.item_id = ai.item_id
			LEFT OUTER JOIN	appraisal_structure aps on ai.structure_id = aps.structure_id
			LEFT OUTER JOIN	form_type ft on aps.form_id = ft.form_id
			LEFT OUTER JOIN	assessor_group gr on com.assessor_group_id = gr.assessor_group_id
			LEFT OUTER JOIN	employee em on com.assessor_id = em.emp_id
			LEFT OUTER JOIN	appraisal_item_result air on com.item_result_id = air.item_result_id
			LEFT OUTER JOIN	emp_result emp on air.emp_result_id = emp.emp_result_id
			LEFT OUTER JOIN structure_result sr on emp.emp_result_id = sr.emp_result_id AND sr.structure_id = ai.structure_id
			LEFT OUTER JOIN	competency_criteria g on aps.structure_id = g.structure_id AND air.level_id = g.appraisal_level_id AND g.assessor_group_id = com.assessor_group_id
			WHERE aps.form_id = 2
			AND emp.emp_result_id = '{$request->emp_result_id}'
			".$assessorGroupDataQrySrt."
			".$unionTempDataSql."
			".$orderbyQryStr."
		");
		$items = collect($items)->groupBy('structure_name');
		
		$items = $items->map(function($item, $key) use($request, $assessorGroupParamQrySrt, $config){
			$itemTemp = $item;
			unset($item);

			$item['structure_id'] = $itemTemp[0]->structure_id;
			$item['structure_name'] = $itemTemp[0]->structure_name;
			$item['nof_target_score'] = $itemTemp[0]->nof_target_score;
			$item['structure_weight_percent'] = $itemTemp[0]->structure_weight_percent;
			$item['result_type'] = $config->result_type;
			$item['hint'] = DB::select("
				SELECT concat(a.target_score,' = ',a.threshold_name) hint
				FROM threshold a
				LEFT JOIN threshold_group b ON a.threshold_group_id = b.threshold_group_id
				WHERE b.is_active = 1
				AND a.structure_id = {$itemTemp[0]->structure_id}
				ORDER BY target_score ASC
			");
			$item['data'] = $itemTemp;
			$item['assessor_group'] = DB::select("
				SELECT DISTINCT com.assessor_group_id, ag.assessor_group_name
				FROM competency_result com
				LEFT OUTER JOIN	appraisal_item_result air on com.item_result_id = air.item_result_id
				INNER JOIN assessor_group ag ON ag.assessor_group_id = com.assessor_group_id
				WHERE air.emp_result_id = '{$request->emp_result_id}'
				AND com.assessor_group_id != 5
				".$assessorGroupParamQrySrt."
				UNION 
				SELECT ass.assessor_group_id, ass.assessor_group_name
				FROM assessor_group ass
				WHERE ass.assessor_group_id = '{$request->assessor_group_id}'
				AND ass.assessor_group_id != 5
			");
			return $item;
		});

		return response()->json($items);
	}
	
	public function calculate_api(Request $request)
	{
		// START Calculate main sum up individual
		$checkCDS = DB::select("
			SELECT GROUP_CONCAT(cds_id) cds_id from cds where is_sum_up = 1		
		");
		if (empty($checkCDS)) {
			// do nothing
		} else {
			$getCDSLevelId = DB::select("
				select level_id as param_level_id , 
				IFNULL((SELECT GROUP_CONCAT(cds_id) from cds where is_sum_up = 1),0) as param_cds_id 
				FROM appraisal_level o
				WHERE o.is_individual = 1 and level_id != 1
				ORDER BY o.seq_no asc;
			");
			
			foreach ($getCDSLevelId as $i) {
				// select cds_resule sum up
				$CDSSumUp = DB::select("
					SELECT 
					cr.appraisal_type_id,
					kc.item_id,
					cr.cds_id,
					e.org_id_owner as org_id,
					e.emp_id_owner as emp_id,
					e.position_id_owner as position_id,
					e.level_id_owner as level_id,
					cr.period_id,
					cr.year,
					cr.appraisal_month_no,
					cr.appraisal_month_name,
					SUM(cr.cds_value) as sum_cds_value,
					CASE WHEN (ai.function_type BETWEEN 2 AND 3) THEN AVG(cr.cds_value) ELSE SUM(cr.cds_value) END as cds_value,
					ai.function_type, -- 1 = sum , 2 = last , 3 = avg
					max(cr.etl_dttm) as etl_dttm
					FROM
							(SELECT 
							e.emp_id as 'emp_id_owner',
							e.emp_code as 'emp_code_owner',
							e.org_id as 'org_id_owner',
							e.position_id as 'position_id_owner',
							e.level_id as 'level_id_owner',
							w.emp_id as 'emp_id_worker',
							w.emp_code as 'emp_code_worker',
							w.org_id as 'org_id_worker',
							w.position_id as 'position_id_worker',
							w.level_id as 'level_id_worker'
							FROM employee e
							INNER JOIN employee w on w.chief_emp_code = e.emp_code
							WHERE e.emp_code != 'admin' and e.level_id = ? )e
					INNER JOIN cds_result AS cr ON cr.org_id = e.org_id_worker AND cr.emp_id = e.emp_id_worker AND cr.level_id = e.level_id_worker and cr.position_id = e.position_id_worker 
					INNER JOIN kpi_cds_mapping kc on kc.cds_id = cr.cds_id
					INNER JOIN appraisal_item ai on ai.item_id = kc.item_id
					where cr.cds_id in (?) 
					and cr.year = year(?) 
					and cr.appraisal_month_no = month(?) 
					and cr.appraisal_type_id = 2
					GROUP BY 
					cr.appraisal_type_id,
					kc.item_id,
					cr.cds_id,
					e.org_id_owner,
					e.emp_id_owner,
					e.position_id_owner,
					cr.period_id,
					cr.year,
					cr.appraisal_month_no,
					cr.appraisal_month_name,
					ai.function_type				
				",[$i->param_level_id, $i->param_cds_id, $request->start_date, $request->start_date]);
				
				foreach ($CDSSumUp as $c){
					$c->is_assignment = DB::select("
						SELECT DISTINCT
						air.emp_id as is_assignment
						FROM
						appraisal_item_result AS air
						where air.period_id in (
							SELECT ap.period_id
							FROM appraisal_period ap
							where ap.start_date <= ? and ap.end_date >= ?
							-- where ap.start_date <= '2018-02-01' and ap.end_date >= '2018-02-01'
							and ap.appraisal_year = (select current_appraisal_year from system_config)
						)AND air.emp_id = ? and air.period_id = ?  
						and air.org_id = ? and air.level_id = ? and air.position_id = ? and air.item_id = ?					
					",[$request->start_date, $request->start_date, $c->emp_id, $c->period_id, $c->org_id, $c->level_id, $c->position_id, $c->item_id]);
					
					$checkCDSResult = DB::select("
						select cds_result_id
						from cds_result
						where appraisal_type_id	= ?	
						and cds_id = ?	
						and org_id = ?	
						and emp_id = ?	
						and level_id = ?	
						and position_id = ?	
						and period_id = ?	
						and year = ?	
						and appraisal_month_no = ?	 
					", [$c->appraisal_type_id, $c->cds_id, $c->org_id, $c->emp_id, $c->level_id, $c->position_id, $c->period_id, $c->year, $c->appraisal_month_no]);
					
					if (empty($checkCDSResult)) {
						$CDSResult = new CDSResult;
						$CDSResult->appraisal_type_id = $c->appraisal_type_id;
						$CDSResult->cds_id = $c->cds_id;
						$CDSResult->org_id = $c->org_id;
						$CDSResult->emp_id = $c->emp_id;
						$CDSResult->position_id = $c->position_id;
						$CDSResult->level_id = $c->level_id;
						$CDSResult->period_id = $c->period_id;
						$CDSResult->year = $c->year;
						$CDSResult->appraisal_month_no = $c->appraisal_month_no;
						$CDSResult->appraisal_month_name = $c->appraisal_month_name;
						$CDSResult->cds_value = $c->cds_value;
						$CDSResult->created_by = 'ETL_SEE_KPI';
						$CDSResult->created_dttm = date('Y-m-d H:i:s');
						$CDSResult->updated_by = 'ETL_SEE_KPI';
						$CDSResult->updated_dttm = date('Y-m-d H:i:s');
						$CDSResult->etl_dttm = $c->etl_dttm;
						$CDSResult->save();
						
					} else {
						foreach ($checkCDSResult as $c) {
							$CDSResult = CDSResult::find($c->cds_result_id);
							$CDSResult->cds_value = $c->cds_value;
							$CDSResult->updated_by = 'ETL_SEE_KPI';
							$CDSResult->updated_dttm = date('Y-m-d H:i:s');
							$CDSResult->etl_dttm = $c->etl_dttm;
							$CDSResult->save();
						}
					}
					
				}
			}					
		}			
		// END Calculate main sum up invidual
		
		// START Calculate main sum up org
		$checkCDS = DB::select("
			SELECT GROUP_CONCAT(cds_id) cds_id from cds where is_sum_up = 1		
		");
		if (empty($checkCDS)) {
			// do nothing
		} else {
			$getCDSLevelId = DB::select("
				select level_id as param_level_id , 
				IFNULL((SELECT GROUP_CONCAT(cds_id) from cds where is_sum_up = 1),0) as param_cds_id 
				FROM appraisal_level o
				WHERE o.is_org = 1 and level_id != 1
				ORDER BY o.seq_no asc;
			");
			
			foreach ($getCDSLevelId as $i) {
				// select cds_result sum up
				$CDSSumUp = DB::select("
					SELECT 
					cr.appraisal_type_id,
					kc.item_id,
					cr.cds_id,
					o.org_id_parent as org_id,
					-- e.emp_id_owner as emp_id,
					-- e.position_id_owner as position_id,
					o.level_id_parent as level_id,
					cr.period_id,
					cr.year,
					cr.appraisal_month_no,
					cr.appraisal_month_name,
					SUM(cr.cds_value) as sum_cds_value,
					CASE WHEN (ai.function_type BETWEEN 2 AND 3) THEN AVG(cr.cds_value) ELSE SUM(cr.cds_value) END as cds_value,
					ai.function_type, -- 1 = sum , 2 = last , 3 = avg
					max(cr.etl_dttm) as etl_dttm
					FROM(
							SELECT  
							op.org_id as org_id_parent,
							op.org_code as org_code_parent,
							op.level_id as level_id_parent,
							o.org_id as org_id_child,
							o.org_code as org_code_child,
							o.level_id as level_id_child
							FROM org AS op
							INNER JOIN org o on o.parent_org_code = op.org_code
							WHERE 
							op.level_id = ? 
					)o
					INNER JOIN cds_result AS cr ON cr.org_id = o.org_id_child AND cr.level_id = o.level_id_child 
					INNER JOIN kpi_cds_mapping kc on kc.cds_id = cr.cds_id
					INNER JOIN appraisal_item ai on ai.item_id = kc.item_id
					where cr.cds_id in (?) 
					and cr.year = year(?) 
					and cr.appraisal_month_no = month(?) 
					and cr.appraisal_type_id = 1
					GROUP BY 
					cr.appraisal_type_id,
					kc.item_id,
					cr.cds_id,
					o.org_id_parent,
					o.level_id_parent,
					cr.year,
					cr.appraisal_month_no,
					cr.appraisal_month_name,
					ai.function_type			
				",[$i->param_level_id, $i->param_cds_id, $request->start_date, $request->start_date]);
				
				foreach ($CDSSumUp as $c){
					$c->is_assignment = DB::select("
						SELECT DISTINCT
						air.org_id as is_assignment
						FROM
						appraisal_item_result AS air
						where air.period_id in (
							SELECT ap.period_id
							FROM appraisal_period ap
							where ap.start_date <= ? and ap.end_date >= ?
							-- where ap.start_date <= '2018-02-01' and ap.end_date >= '2018-02-01'
							and ap.appraisal_year = (select current_appraisal_year from system_config)
						)AND air.period_id = ?  and air.org_id = ? and air.level_id = ? and air.item_id = ?			
					",[$request->start_date, $request->start_date, $c->period_id, $c->org_id, $c->level_id, $c->item_id]);
					
					$checkCDSResult = DB::select("
						select cds_result_id
						from cds_result
						where appraisal_type_id	= ?	
						and cds_id = ?	
						and org_id = ?	
						and level_id = ?	
						and period_id = ?	
						and year = ?	
						and appraisal_month_no = ?	 
					", [$c->appraisal_type_id, $c->cds_id, $c->org_id, $c->level_id, $c->period_id, $c->year, $c->appraisal_month_no]);
					
					if (empty($checkCDSResult)) {
						$CDSResult = new CDSResult;
						$CDSResult->appraisal_type_id = $c->appraisal_type_id;
						$CDSResult->cds_id = $c->cds_id;
						$CDSResult->org_id = $c->org_id;
						$CDSResult->emp_id = $c->emp_id;
						$CDSResult->position_id = $c->position_id;
						$CDSResult->level_id = $c->level_id;
						$CDSResult->period_id = $c->period_id;
						$CDSResult->year = $c->year;
						$CDSResult->appraisal_month_no = $c->appraisal_month_no;
						$CDSResult->appraisal_month_name = $c->appraisal_month_name;
						$CDSResult->cds_value = $c->cds_value;
						$CDSResult->created_by = 'ETL_SEE_KPI';
						$CDSResult->created_dttm = date('Y-m-d H:i:s');
						$CDSResult->updated_by = 'ETL_SEE_KPI';
						$CDSResult->updated_dttm = date('Y-m-d H:i:s');
						$CDSResult->etl_dttm = $c->etl_dttm;
						$CDSResult->save();
						
					} else {
						foreach ($checkCDSResult as $c){
							$CDSResult = CDSResult::find($c->cds_result_id);
							$CDSResult->cds_value = $c->cds_value;
							$CDSResult->updated_by = 'ETL_SEE_KPI';
							$CDSResult->updated_dttm = date('Y-m-d H:i:s');
							$CDSResult->etl_dttm = $c->etl_dttm;
							$CDSResult->save();
						}
					}
					
				}
			}				
		}			
		// END Calculate main sum up org
	
		// START Calculate main adjustment KPI of subordinate
		
		// START Adjustment KPI Chief Individual
		
		$chiefIndvKPIResult = DB::select("
			SELECT DISTINCT
				ir.appraisal_form_id,
				ir.org_id,
				ir.emp_id,
				e.emp_code,
				ir.position_id,
				ir.level_id,
				ir.period_id,
				m.cds_id -- , child 
				,s.level_id as parent_level_id,
				2 as appraisal_type_id,
			  l.is_org, l.is_individual -- parent
			FROM
				appraisal_item_result ir
			INNER JOIN employee e ON ir.emp_id = e.emp_id
			INNER JOIN appraisal_period p ON ir.period_id = p.period_id
			INNER JOIN appraisal_item i ON ir.item_id = i.item_id
			INNER JOIN appraisal_structure s ON i.structure_id = s.structure_id
			INNER JOIN kpi_cds_mapping m ON i.item_id = m.item_id
			INNER JOIN appraisal_level l ON s.level_id = l.level_id

			WHERE
			p.period_id in (
				SELECT ap.period_id
				FROM appraisal_period ap
				where ap.start_date <= ? and ap.end_date >= ?
				and ap.appraisal_year = (select current_appraisal_year from system_config)
			)
			AND s.form_id = 1
			AND l.is_individual = 1
			AND l.is_hr != 1
			AND s.is_derive = 1
			AND s.level_id != ir.level_id

			ORDER BY 3,6
		", [$request->start_date, $request->start_date]);
		
		foreach ($chiefIndvKPIResult as $c) {
			$c->chief_emp_id = DB::select("
				select getTopChiefEmp(?,?) chief_emp_id
			",[$c->emp_code, $c->level_id]);
			$c->chief_emp_id = $c->chief_emp_id[0]->chief_emp_id;
			$getChiefOrgPosition = DB::select("
				select org_id, position_id
				from employee
				where emp_id = ?
			", [$c->chief_emp_id]);
			$c->chief_org_id = $getChiefOrgPosition[0]->org_id;
			$c->chief_position_id = $getChiefOrgPosition[0]->position_id;
			
			$c->cds_result = DB::select("
				SELECT
				-- cr.appraisal_type_id,
				-- cr.cds_id,
				-- cr.org_id,
				-- cr.emp_id,
				-- cr.position_id,
				-- cr.level_id,
				-- cr.period_id,
				cr.`year`,
				cr.appraisal_month_no,
				cr.appraisal_month_name,
				cr.cds_value,
				cr.etl_dttm
				FROM
				cds_result AS cr
				WHERE
				cr.appraisal_type_id = ?
				and cr.cds_id = ?
				and cr.org_id = ?
				and cr.emp_id = ?
				and cr.position_id = ?
				and cr.level_id = ?
				and cr.period_id = ?
				and cr.`year` = year(?)
				and cr.appraisal_month_no = month(?)
			", [$c->appraisal_type_id, $c->cds_id, $c->chief_org_id, $c->chief_emp_id, $c->chief_position_id, $c->parent_level_id, $c->period_id, $request->start_date, $request->start_date]);

			foreach ($c->cds_result as $cds) {
				
				if (empty($cds->cds_value)) {
					// do nothing
				} else {
					$checkCDSResult = DB::select("
						select cds_result_id
						from cds_result
						where appraisal_type_id	= ?
						and cds_id = ?
						and org_id = ?
						and emp_id = ?
						and level_id = ?
						and position_id = ?
						and period_id = ?
						and year = ?
						and appraisal_month_no = ? 
					", [$c->appraisal_type_id, $c->cds_id, $c->org_id, $c->emp_id, $c->level_id, $c->position_id, $c->period_id, $cds->year, $cds->appraisal_month_no]);
					
					if (empty($checkCDSResult)) {
						$CDSResult = new CDSResult;
						$CDSResult->appraisal_type_id = $c->appraisal_type_id;
						$CDSResult->cds_id = $c->cds_id;
						$CDSResult->org_id = $c->org_id;
						$CDSResult->emp_id = $c->emp_id;
						$CDSResult->position_id = $c->position_id;
						$CDSResult->level_id = $c->level_id;
						$CDSResult->period_id = $c->period_id;
						$CDSResult->year = $cr->year;
						$CDSResult->appraisal_month_no = $cr->appraisal_month_no;
						$CDSResult->appraisal_month_name = $cr->appraisal_month_name;
						$CDSResult->cds_value = $cr->cds_value;
						$CDSResult->created_by = 'ETL_SEE_KPI';
						$CDSResult->created_dttm = date('Y-m-d H:i:s');
						$CDSResult->updated_by = 'ETL_SEE_KPI';
						$CDSResult->updated_dttm = date('Y-m-d H:i:s');
						$CDSResult->etl_dttm = $cr->etl_dttm;
						$CDSResult->save();
					} else {					
						foreach ($checkCDSResult as $cr) {
							$CDSResult = CDSResult::find($cr->cds_result_id);
							$CDSResult->cds_value = $cr->cds_value;
							$CDSResult->updated_by = 'ETL_SEE_KPI';
							$CDSResult->updated_dttm = date('Y-m-d H:i:s');
							$CDSResult->etl_dttm = $cr->etl_dttm;
							$CDSResult->save();
						}
					}
				}
				
			}
		
		}
		// END Adjustment KPI Chief Individual
		
		// START Adjustment Parent Org
		$ParentOrgKPIResult = DB::select("
			SELECT DISTINCT
				ir.appraisal_form_id,
				ir.org_id,
				ir.emp_id,
				ir.position_id,
				ir.level_id,
				ir.period_id,
				o.org_code,
				m.cds_id -- , child 
				,s.level_id as parent_level_id,
				2 as appraisal_type_id,
			    l.is_org, l.is_individual -- parent
			FROM
				appraisal_item_result ir
			INNER JOIN org o ON ir.org_id = o.org_id
			INNER JOIN appraisal_period p ON ir.period_id = p.period_id
			INNER JOIN appraisal_item i ON ir.item_id = i.item_id
			INNER JOIN appraisal_structure s ON i.structure_id = s.structure_id
			INNER JOIN kpi_cds_mapping m ON i.item_id = m.item_id
			INNER JOIN appraisal_level l ON s.level_id = l.level_id

			WHERE
			p.period_id in (
				SELECT ap.period_id
				FROM appraisal_period ap
				where ap.start_date <= ? and ap.end_date >= ?
				and ap.appraisal_year = (select current_appraisal_year from system_config)
			)
			AND s.form_id = 1
			-- AND ir.emp_id = 766
			AND l.is_org = 1
			AND l.is_hr != 1
			AND s.is_derive = 1
			AND s.level_id != ir.level_id

			ORDER BY 3,6
		", [$request->start_date, $request->start_date]);
		
		foreach ($ParentOrgKPIResult as $c) {
			$c->chief_org_id = DB::select("
				select getTopChiefOrg(?,?) chief_org_id
			",[$c->org_code, $c->level_id]);
			$c->chief_org_id = $c->chief_org_id[0]->chief_org_id;
			
			$c->cds_result = DB::select("
				SELECT
				-- cr.appraisal_type_id,
				-- cr.cds_id,
				-- cr.org_id,
				-- cr.emp_id,
				-- cr.position_id,
				-- cr.level_id,
				-- cr.period_id,
				cr.`year`,
				cr.appraisal_month_no,
				cr.appraisal_month_name,
				cr.cds_value,
				cr.etl_dttm
				FROM
				cds_result AS cr
				WHERE
				cr.appraisal_type_id = 1
				and cr.cds_id = ?
				and cr.org_id = ?
				-- and cr.emp_id = ?
				-- and cr.position_id = ?
				and cr.level_id = ?
				and cr.period_id = ?
				and cr.`year` = year(?)
				and cr.appraisal_month_no = month(?)
			", [$c->appraisal_type_id, $c->cds_id, $c->chief_org_id, $c->parent_level_id, $c->period_id, $request->start_date, $request->start_date]);

			foreach ($c->cds_result as $cds) {
				
				if (empty($cds->cds_value)) {
					// do nothing
				} else {
					$checkCDSResult = DB::select("
						select cds_result_id
						from cds_result
						where appraisal_type_id	= ?
						and cds_id = ?
						and org_id = ?
						and emp_id = ?
						and level_id = ?
						and position_id = ?
						and period_id = ?
						and year = ?
						and appraisal_month_no = ? 
					", [$c->appraisal_type_id, $c->cds_id, $c->org_id, $c->emp_id, $c->level_id, $c->position_id, $c->period_id, $cds->year, $cds->appraisal_month_no]);
					
					if (empty($checkCDSResult)) {
						$CDSResult = new CDSResult;
						$CDSResult->appraisal_type_id = $c->appraisal_type_id;
						$CDSResult->cds_id = $c->cds_id;
						$CDSResult->org_id = $c->org_id;
						$CDSResult->emp_id = $c->emp_id;
						$CDSResult->position_id = $c->position_id;
						$CDSResult->level_id = $c->level_id;
						$CDSResult->period_id = $c->period_id;
						$CDSResult->year = $cr->year;
						$CDSResult->appraisal_month_no = $cr->appraisal_month_no;
						$CDSResult->appraisal_month_name = $cr->appraisal_month_name;
						$CDSResult->cds_value = $cr->cds_value;
						$CDSResult->created_by = 'ETL_SEE_KPI';
						$CDSResult->created_dttm = date('Y-m-d H:i:s');
						$CDSResult->updated_by = 'ETL_SEE_KPI';
						$CDSResult->updated_dttm = date('Y-m-d H:i:s');
						$CDSResult->etl_dttm = $cr->etl_dttm;
						$CDSResult->save();
					} else {					
						foreach ($checkCDSResult as $cr) {
							$CDSResult = CDSResult::find($cr->cds_result_id);
							$CDSResult->cds_value = $cr->cds_value;
							$CDSResult->updated_by = 'ETL_SEE_KPI';
							$CDSResult->updated_dttm = date('Y-m-d H:i:s');
							$CDSResult->etl_dttm = $cr->etl_dttm;
							$CDSResult->save();
						}
					}
				}
				
			}
		
		}			
		// END Adjustment Parent Org
		// END Calculate main adjustment KPI of subordinate
		
		// START Calculate KPI Result by Form Type
		
		// START KPI Result Quantity
		$getKPIResult = DB::select("
			SELECT (SELECT result_type FROM system_config) as result_type,
			air.item_result_id,
			al.no_weight as flag_no_weight,
			(SELECT system_config.threshold FROM system_config) as flag_threshold,
			af.is_bonus,
			er.appraisal_type_id,
			air.item_id,
			ai.item_name,
			astr.form_id,
			-- kcm.cds_id,
			air.emp_id,
			air.org_id,
			air.period_id,
			-- SUBSTR(kcm.function_type, 1, 1) as cds_type,
			-- concat('[',kcm.function_type,':cds',kcm.cds_id,']') as cds_type_full,
			ai.formula_cds_id,
			0 as accum_flag,
			ap.appraisal_year,
			year(ap.start_date) as period_start_year,
			month(ap.start_date) as period_start_month_no,
			year(ap.end_date) as period_end_year,
			month(ap.end_date) as period_end_month_no,
			air.score0,
			air.score1,
			air.score2,
			air.score3,
			air.score4,
			air.score5,
			air.score,
			astr.nof_target_score,
			-- (7.99/100) as contribute_percent,
			(air.contribute_percent/100) as contribute_percent,
			ai.value_type_id,
			-- air.actual_value as actual_value_new,
			air.target_value,
			air.forecast_value,
			air.weight_percent
			-- SUBSTR(kcm.formula_type,2,1) as cds_type
			FROM appraisal_item_result air
			INNER JOIN emp_result er on er.emp_result_id = air.emp_result_id
			inner join appraisal_form af on af.appraisal_form_id = air.appraisal_form_id
			inner join appraisal_item ai on ai.item_id = air.item_id
			-- inner join appraisal_item_level ail on ail.item_id = ai.item_id and air.level_id = ail.level_id
			-- item ที่เป็น is_derive จะไม่มีใน appraisal_item_level
			inner join appraisal_level al on al.level_id = air.level_id 
			inner join appraisal_structure astr on astr.structure_id = ai.structure_id
			-- inner join kpi_cds_mapping kcm on kcm.item_id = ai.item_id
			inner join appraisal_period ap on ap.period_id = air.period_id
			where air.period_id in (
				SELECT ap.period_id
				FROM appraisal_period ap
				where ap.start_date <= ? and ap.end_date >= ?
				and ap.appraisal_year = (select current_appraisal_year from system_config)
			)
			and astr.form_id = 1
			and al.is_hr != 1
			-- and af.is_bonus != 1
			and (ai.formula_cds_id IS NOT NULL or ai.formula_cds_id != '')
			and (air.emp_result_id = ? or 'All' = ?)
			-- and air.item_id = 30
			-- and air.emp_id = 766
			-- and air.org_id = 3
			-- and air.item_result_id = 11
			group by 
			air.item_result_id,
			al.no_weight ,
			er.appraisal_type_id,
			air.item_id,
			ai.item_name,
			astr.form_id,
			-- kcm.cds_id,
			air.emp_id,
			air.org_id,
			air.period_id,
			-- kcm.function_type ,
			ai.formula_cds_id,
			ap.appraisal_year,
			ap.start_date,
			ap.end_date		
		", [$request->start_date, $request->start_date, $request->emp_result_id, $request->emp_result_id]);
		
		foreach ($getKPIResult as $r) {
			$abort_flag = 0;
			if (!empty($r->emp_id)) { // Invididual
				
				$formula = $r->formula_cds_id; 
				preg_match_all('/(sum|max|avg|last):cds[0-9]*/',$formula,$cds_type_list);
				preg_match_all('/cds(.*?)\]/',$formula,$cds_id_list);
				$cds_value = 0;
				$newformula = $formula;
				foreach($cds_type_list[0] as $i=>$a) {
					if($cds_type_list[1][$i] == 'sum'){
						$cds_result = DB::select("
							select cast(ifnull(result.cds_value,defaultValue.cds_value) as DECIMAL(12,2)) as cds_value
							from (select -9999999999 as cds_value) defaultValue left join
							(select sum(cds_value) as cds_value 
							from cds_result
							where cds_id= ?
							and emp_id= ?
							and period_id= ?
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'
							) result on defaultValue.cds_value != result.cds_value					
						", [$cds_id_list[1][$i], $r->emp_id, $r->period_id]);

					} elseif ($cds_type_list[1][$i]  == 'avg') {

						$cds_result = DB::select("
							select cast(ifnull(result.cds_value,defaultValue.cds_value) as DECIMAL(12,2)) as cds_value
							from (select -9999999999 as cds_value) defaultValue left join
							(select avg(cds_value) as cds_value 
							from cds_result
							where cds_id= ?
							and emp_id= ?
							and period_id= ?
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'
							) result on defaultValue.cds_value != result.cds_value					
						", [$cds_id_list[1][$i], $r->emp_id, $r->period_id]);

					}else{
						
						$cds_result = DB::select("
							select cast(ifnull(result.cds_value,defaultValue.cds_value) as DECIMAL(12,2)) as cds_value
							from (select -9999999999 as cds_value) defaultValue left join
							(select cds_value as cds_value 
							from cds_result
							where cds_id= ?
							and emp_id= ?
							and period_id= ?
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'
							ORDER BY cds_result.year DESC , cds_result.appraisal_month_no DESC LIMIT 1
							) result on defaultValue.cds_value != result.cds_value					
						", [$cds_id_list[1][$i], $r->emp_id, $r->period_id]);					
					}											
					
					if ($cds_result[0]->cds_value == -9999999999) {
						$abort_flag = 1;
					}
					
					$newformula = str_replace($a,$cds_result[0]->cds_value,$newformula);
				}
				$newformula = str_replace('[','',$newformula);
				$newformula = str_replace(']','',$newformula);
				
				$etl_dttm = DB::select("
						select max(etl_dttm) as etl_dttm 
						from cds_result
						where cds_id= ?
						and emp_id= ?
						and period_id= ?
						and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
						and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'				
				", [$cds_id_list[1][$i], $r->emp_id, $r->period_id]);
				
				if (!empty($etl_dttm)) {
					$etl_dttm = $etl_dttm[0]->etl_dttm;
				}			
				

			} else { // Org
				
				$formula = $r->formula_cds_id; 
				preg_match_all('/(sum|max|avg|last):cds[0-9]*/',$formula,$cds_type_list);
				preg_match_all('/cds(.*?)\]/',$formula,$cds_id_list);
				$cds_value = 0;
				$newformula = $formula;
				foreach($cds_type_list[0] as $i=>$a) {
					if($cds_type_list[1][$i] == 'sum'){
						$cds_result = DB::select("
							select cast(ifnull(result.cds_value,defaultValue.cds_value) as DECIMAL(12,2)) as cds_value
							from (select -9999999999 as cds_value) defaultValue left join
							(select sum(cds_value) as cds_value 
							from cds_result
							where cds_id= ?
							and org_id= ?
							and period_id= ?
							and appraisal_type_id = ?
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'
							) result on defaultValue.cds_value != result.cds_value					
						", [$cds_id_list[1][$i], $r->org_id, $r->period_id, $r->appraisal_type_id]);

					} elseif ($cds_type_list[1][$i]  == 'avg') {

						$cds_result = DB::select("
							select cast(ifnull(result.cds_value,defaultValue.cds_value) as DECIMAL(12,2)) as cds_value
							from (select -9999999999 as cds_value) defaultValue left join
							(select avg(cds_value) as cds_value 
							from cds_result
							where cds_id= ?
							and org_id= ?
							and period_id= ?
							and appraisal_type_id = ?
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'
							) result on defaultValue.cds_value != result.cds_value					
						", [$cds_id_list[1][$i], $r->org_id, $r->period_id, $r->appraisal_type_id]);

					}else{
						
						$cds_result = DB::select("
							select cast(ifnull(result.cds_value,defaultValue.cds_value) as DECIMAL(12,2)) as cds_value
							from (select -9999999999 as cds_value) defaultValue left join
							(select cds_value as cds_value 
							from cds_result
							where cds_id= ?
							and org_id= ?
							and period_id= ?
							and appraisal_type_id = ?
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
							and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'
							ORDER BY cds_result.year DESC , cds_result.appraisal_month_no DESC LIMIT 1
							) result on defaultValue.cds_value != result.cds_value					
						", [$cds_id_list[1][$i], $r->org_id, $r->period_id, $r->appraisal_type_id]);					
					}											
					
					if ($cds_result[0]->cds_value == -9999999999) {
						$abort_flag = 1;
					}
					
					$newformula = str_replace($a,$cds_result[0]->cds_value,$newformula);
				}
				$newformula = str_replace('[','',$newformula);
				$newformula = str_replace(']','',$newformula);
				
				$etl_dttm = DB::select("
						select max(etl_dttm) as etl_dttm 
						from cds_result
						where cds_id= ?
						and org_id= ?
						and period_id= ?
						and appraisal_type_id = ?
						and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
						and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'				
				", [$cds_id_list[1][$i], $r->org_id, $r->period_id, $r->appraisal_type_id]);
				
				if (!empty($etl_dttm)) {
					$etl_dttm = $etl_dttm[0]->etl_dttm;
				}			
				
			}
			
			if ($abort_flag == 1) {
				// do nothing
			} else {
				$formula_result = DB::select("select cast({$newformula} as decimal(12,2) ) result");
				
				
				$r->actual_value = $formula_result[0]->result;
				
				if ($r->flag_threshold == 1) {
					if ($r->flag_no_weight == 1) {
						// do nothing
					} elseif ($r->flag_no_weight == 0) {
						$java_percent_achievement = 0;
						$java_percent_forecast = 0;
						$java_score = 0;
						$java_weigh_score = 0;

						if($r->value_type_id == 1){ //Bigger is better

							if($r->target_value == 0 && $r->actual_value == 0){
								$java_percent_achievement = 100;
							}elseif($r->target_value == $r->actual_value){
								$java_percent_achievement = 100;
							}elseif($r->target_value == null){ 
								$java_percent_achievement = 0;
							}elseif($r->target_value == 0){ 
								$java_percent_achievement = 0;
							}else{
								$java_percent_achievement = ($r->actual_value / $r->target_value) * 100;
							}
						//---------------------------------------------------------------
							if($r->target_value == 0 && $r->actual_value == 0){
								$java_percent_forecast = 100;
							}elseif($r->target_value == $r->actual_value){
								$java_percent_forecast = 100;
							}elseif($r->forecast_value == null){ 
								$java_percent_forecast = 0;
							}elseif($r->forecast_value == 0){ 
								$java_percent_forecast = 0;
							}else{
								$java_percent_forecast = ($r->actual_value / $r->forecast_value) * 100;
							}
						if($r->form_id == 1){

							if($r->actual_value == 0 || $r->actual_value == null){
							  $java_score = 0;
							}
							elseif($r->actual_value > $r->score0 && $r->actual_value < $r->score1 && $r->nof_target_score >= 1 && $r->score0 != null && $r->score1 != null){
							  $java_score = 1;
							}
							elseif($r->actual_value >= $r->score1 && $r->actual_value < $r->score2 && $r->nof_target_score >= 2 && $r->score1 != null && $r->score2 != null){
							  $java_score = 2;
							}elseif($r->actual_value >= $r->score2 && $r->actual_value < $r->score3 && $r->nof_target_score >= 3 && $r->score2 != null && $r->score3 != null){
							  $java_score = 3;
							}elseif($r->actual_value >= $r->score3 && $r->actual_value < $r->score4 && $r->nof_target_score >= 4 && $r->score3 != null && $r->score4 != null){
							  $java_score = 4;
							}elseif($r->actual_value >= $r->score4 && $r->actual_value <= $r->score5 && $r->nof_target_score >= 5 && $r->score4 != null && $r->score5 != null){
							  $java_score = 5;
							}elseif($r->actual_value >= $r->score5 && $r->nof_target_score >= 5 && $r->score5 != null){
							  $java_score = 5;
							}elseif($r->actual_value >= $r->score4 && $r->nof_target_score >= 4 && $r->score4 != null){
							  $java_score = 4;
							}elseif($r->actual_value >= $r->score3 && $r->nof_target_score >= 3 && $r->score3 != null){
							  $java_score = 3;
							}elseif($r->actual_value >= $r->score2 && $r->nof_target_score >= 2 && $r->score2 != null){
							  $java_score = 2;
							}elseif($r->actual_value >= $r->score1 && $r->nof_target_score >= 1 && $r->score1 != null){
							  $java_score = 1;
							}else{
							  $java_score = 0;
							}
						  }

						}elseif( $r->value_type_id == 2){//Smaller is better 

							if($r->target_value == 0 && $r->actual_value == 0){
								$java_percent_achievement = 100;
							}elseif($r->target_value == $r->actual_value){
								$java_percent_achievement = 100;
							}elseif($r->target_value == null){ 
								$java_percent_achievement = 0;
							}elseif($r->target_value == 0){ 
								$java_percent_achievement = 0;
							}else{
								$java_percent_achievement = 100 + ((($r->target_value - $r->actual_value ) / $r->target_value )*100);
							}
						//----------------------------------------------------------------------------------
							if($r->forecast_value == 0 && $r->actual_value == 0){
								$java_percent_forecast = 100;
							}elseif($r->forecast_value == $r->actual_value){
								$java_percent_forecast = 100;
							}elseif($r->forecast_value == null){ 
								$java_percent_forecast = 0;
							}elseif($r->forecast_value == 0){ 
								$java_percent_forecast = 0;
							}else{
								$java_percent_forecast = 100 + ((($r->forecast_value - $r->actual_value ) / $r->forecast_value )*100);
							}



							if($r->form_id == 1){
						/*
							if(actual_value == 0){
								java_score = 0;
							}
							else */
							if($r->actual_value == null){
								$java_score = 0;
							}elseif($r->actual_value < $r->score0 && $r->actual_value > $r->score1 && $r->nof_target_score >= 1  && $r->score0 != null && $r->score1 != null){
								$java_score = 1;
							}
							elseif($r->actual_value <= $r->score1 && $r->actual_value > $r->score2 && $r->nof_target_score >= 2  && $r->score1 != null && $r->score2 != null){
								$java_score = 2;
							}elseif($r->actual_value <= $r->score2 && $r->actual_value > $r->score3 && $r->nof_target_score >= 3 && $r->score2 != null && $r->score3 != null){
								$java_score = 3;
							}elseif($r->actual_value <= $r->score3 && $r->actual_value > $r->score4 && $r->nof_target_score >= 4 && $r->score3 != null && $r->score4 != null){
								$java_score = 4;
							}elseif($r->actual_value <= $r->score4 && $r->actual_value >= $r->score5 && $r->nof_target_score >= 5 && $r->score4 != null && $r->score5 != null){
								$java_score = 5;
							}elseif($r->actual_value <= $r->score5 && $r->nof_target_score >= 5 && $r->score5 != null){
								$java_score = 5;
							}elseif($r->actual_value <= $r->score4 && $r->nof_target_score >= 4 && $r->score4 != null){
								$java_score = 4;
							}elseif($r->actual_value <= $r->score3 && $r->nof_target_score >= 3 && $r->score3 != null){
								$java_score = 3;
							}elseif($r->actual_value <= $r->score2 && $r->nof_target_score >= 2 && $r->score2 != null){
								$java_score = 2;
							}elseif($r->actual_value <= $r->score1 && $r->nof_target_score >= 1 && $r->score1 != null){
								$java_score = 1;
							}else{
								$java_score = 0;
							}

							}
						}

						if($r->result_type==1){
							//java_weigh_score = (java_score * weight_percent);
							$java_weigh_score = ($java_score * $r->weight_percent) * $r->contribute_percent;
						}elseif($r->result_type==2){
							//java_weigh_score = (java_score * weight_percent)/100;
							$java_weigh_score = (($java_score * $r->weight_percent)/100) * $r->contribute_percent;
						}else{
							$java_weigh_score = 0;
						}
						
						
						$item_result = AppraisalItemResult::find($r->item_result_id);
						$item_result->actual_value = $r->actual_value;
						$item_result->percent_achievement = $java_percent_achievement;
						$item_result->percent_forecast = $java_percent_forecast;
						$item_result->score = $java_score;
						$item_result->weigh_score = $java_weigh_score;
						$item_result->updated_by = 'ETL_SEE_KPI';
						$item_result->updated_dttm = date('Y-m-d H:i:s');
						$item_result->save();

					}
					
				} elseif ($r->flag_threshold == 0) {
					$java_percent_achievement = 0;
					$java_percent_forecast = 0;
					$java_weigh_score = 0;
					if($r->value_type_id == 1){ //Bigger is better

						if($r->target_value == 0 && $r->actual_value == 0){
							$java_percent_achievement = 100;
						}elseif($r->target_value == $r->actual_value){
							$java_percent_achievement = 100;
						}elseif($r->target_value == null){ 
							$java_percent_achievement = 0;
						}elseif($r->target_value == 0){ 
							$java_percent_achievement = 0;
						}else{
							$java_percent_achievement = ($r->actual_value / $r->target_value) * 100;
						}
					//---------------------------------------------------------------
						if($r->target_value == 0 && $r->actual_value == 0){
							$java_percent_forecast = 100;
						}elseif($r->target_value == $r->actual_value){
							$java_percent_forecast = 100;
						}elseif($r->forecast_value == null){ 
							$java_percent_forecast = 0;
						}elseif($r->forecast_value == 0){ 
							$java_percent_forecast = 0;
						}else{
							$java_percent_forecast = ($r->actual_value / $r->forecast_value) * 100;
						}


					}elseif( $r->value_type_id == 2){//Smaller is better 

						if($r->target_value == 0 && $r->actual_value == 0){
							$java_percent_achievement = 100;
						}elseif($r->target_value == $r->actual_value){
							$java_percent_achievement = 100;
						}elseif($r->target_value == null){ 
							$java_percent_achievement = 0;
						}elseif($r->target_value == 0){ 
							$java_percent_achievement = 0;
						}else{
							$java_percent_achievement = 100 + ((($r->target_value - $r->actual_value ) / $r->target_value )*100);
						}
					//----------------------------------------------------------------------------------
						if($r->forecast_value == 0 && $r->actual_value == 0){
							$java_percent_forecast = 100;
						}elseif($r->forecast_value == $r->actual_value){
							$java_percent_forecast = 100;
						}elseif($r->forecast_value == null){ 
							$java_percent_forecast = 0;
						}elseif($r->forecast_value == 0){ 
							$java_percent_forecast = 0;
						}else{
							$java_percent_forecast = 100 + ((($r->forecast_value - $r->actual_value ) / $r->forecast_value )*100);
						}
					}

					if($r->result_type==1){
						$java_weigh_score = (($java_percent_achievement * $r->weight_percent) / 100) * $r->contribute_percent;
						//java_weigh_score = ((java_percent_achievement * weight_percent) / 100);
					}elseif($r->result_type==2){
						$java_weigh_score = (($java_percent_achievement * $r->weight_percent) / 100) * $r->contribute_percent;
						//java_weigh_score = ((java_percent_achievement * weight_percent) / 100) ;
					}else{
						$java_weigh_score = 0;
					}

					$item_result = AppraisalItemResult::find($r->item_result_id);
					$item_result->actual_value = $r->actual_value;
					$item_result->percent_achievement = $java_percent_achievement;
					$item_result->percent_forecast = $java_percent_forecast;
					$item_result->weigh_score = $java_weigh_score;
					$item_result->updated_by = 'ETL_SEE_KPI';
					$item_result->updated_dttm = date('Y-m-d H:i:s');		
					$item_result->save();
				}
			}
			
		}
		// END KPI Result Quantity

		// START Recalculate Quantity
		
		$kpi_result = DB::select("
			SELECT (SELECT result_type FROM system_config) as result_type,
			air.item_result_id,
			al.no_weight as flag_no_weight,
			(SELECT system_config.threshold FROM system_config) as flag_threshold,
			air.score0,
			air.score1,
			air.score2,
			air.score3,
			air.score4,
			air.score5,
			air.score,
			(air.contribute_percent/100) as contribute_percent,
			-- (7.99/100) as contribute_percent,  test
			astr.nof_target_score,
			ai.value_type_id,
			air.actual_value,
			air.target_value,
			air.forecast_value,
			air.weight_percent
			FROM appraisal_item_result air
			INNER JOIN emp_result er on er.emp_result_id = air.emp_result_id
			inner join appraisal_item ai on ai.item_id = air.item_id
			-- inner join appraisal_item_level ail on ail.item_id = ai.item_id and air.level_id = ail.level_id
			inner join appraisal_level al on al.level_id = air.level_id 
			inner join appraisal_structure astr on astr.structure_id = ai.structure_id
			inner join appraisal_period ap on ap.period_id = air.period_id
			where air.period_id in (
				SELECT ap.period_id
				FROM appraisal_period ap
				where ap.start_date <= ? and ap.end_date >= ?
				and ap.appraisal_year = (select current_appraisal_year from system_config)
			)
			and astr.form_id = 1
			and al.is_hr != 1
			and air.actual_value IS NOT NULL
			-- and (ai.formula_cds_id IS NOT NULL or ai.formula_cds_id != '')
			and (air.emp_result_id = ? or 'All' = ?)
			-- and air.item_id = 30
			-- and air.emp_id = 8079
			-- and air.org_id = 3
			-- and air.item_result_id = 7496		
		",[$request->start_date, $request->start_date, $request->emp_result_id, $request->emp_result_id]);
		
		foreach ($kpi_result as $r) {
			if ($r->flag_threshold == 1) {
				if ($r->flag_no_weight == 0) {
					$java_percent_achievement = 0;
					$java_percent_forecast = 0;
					$java_score = 0;
					$java_weigh_score = 0;

					if( $r->value_type_id == 1){ //Bigger is better

						if($r->target_value == 0 && $r->actual_value == 0){
							$java_percent_achievement = 100;
						}elseif($r->target_value == $r->actual_value){
							$java_percent_achievement = 100;
						}elseif($r->target_value == null){ 
							$java_percent_achievement = 0;
						}elseif($r->target_value == 0){ 
							$java_percent_achievement = 0;
						}else{
							$java_percent_achievement = ($r->actual_value / $r->target_value) * 100;
						}
					//---------------------------------------------------------------
						if($r->target_value == 0 && $r->actual_value == 0){
							$java_percent_forecast = 100;
						}elseif($r->target_value == $r->actual_value){
							$java_percent_forecast = 100;
						}elseif($r->forecast_value == null){ 
							$java_percent_forecast = 0;
						}elseif($r->forecast_value == 0){ 
							$java_percent_forecast = 0;
						}else{
							$java_percent_forecast = ($r->actual_value / $r->forecast_value) * 100;
						}
					if($r->form_id == 1){

						if($r->actual_value == 0 || $r->actual_value == null){
						  $java_score = 0;
						}
						elseif($r->actual_value > $r->score0 && $r->actual_value < $r->score1 && $r->nof_target_score >= 1 && $r->score0 != null && $r->score1 != null){
						  $java_score = 1;
						}
						elseif($r->actual_value >= $r->score1 && $r->actual_value < $r->score2 && $r->nof_target_score >= 2 && $r->score1 != null && $r->score2 != null){
						  $java_score = 2;
						}elseif($r->actual_value >= $r->score2 && $r->actual_value < $r->score3 && $r->nof_target_score >= 3 && $r->score2 != null && $r->score3 != null){
						  $java_score = 3;
						}elseif($r->actual_value >= $r->score3 && $r->actual_value < $r->score4 && $r->nof_target_score >= 4 && $r->score3 != null && $r->score4 != null){
						  $java_score = 4;
						}elseif($r->actual_value >= $r->score4 && $r->actual_value <= $r->score5 && $r->nof_target_score >= 5 && $r->score4 != null && $r->score5 != null){
						  $java_score = 5;
						}elseif($r->actual_value >= $r->score5 && $r->nof_target_score >= 5 && $r->score5 != null){
						  $java_score = 5;
						}elseif($r->actual_value >= $r->score4 && $r->nof_target_score >= 4 && $r->score4 != null){
						  $java_score = 4;
						}elseif($r->actual_value >= $r->score3 && $r->nof_target_score >= 3 && $r->score3 != null){
						  $java_score = 3;
						}elseif($r->actual_value >= $r->score2 && $r->nof_target_score >= 2 && $r->score2 != null){
						  $java_score = 2;
						}elseif($r->actual_value >= $r->score1 && $r->nof_target_score >= 1 && $r->score1 != null){
						  $java_score = 1;
						}else{
						  $java_score = 0;
						}
					  }
						

					}elseif( $r->value_type_id == 2){//Smaller is better 

						if($r->target_value == 0 && $r->actual_value == 0){
							$java_percent_achievement = 100;
						}elseif($r->target_value == $r->actual_value){
							$java_percent_achievement = 100;
						}elseif($r->target_value == null){ 
							$java_percent_achievement = 0;
						}elseif($r->target_value == 0){ 
							$java_percent_achievement = 0;
						}else{
							$java_percent_achievement = 100 + ((($r->target_value - $r->actual_value ) / $r->target_value )*100);
						}
					//----------------------------------------------------------------------------------
						if($r->forecast_value == 0 && $r->actual_value == 0){
							$java_percent_forecast = 100;
						}elseif($r->forecast_value == $r->actual_value){
							$java_percent_forecast = 100;
						}elseif($r->forecast_value == null){ 
							$java_percent_forecast = 0;
						}elseif($r->forecast_value == 0){ 
							$java_percent_forecast = 0;
						}else{
							$java_percent_forecast = 100 + ((($r->forecast_value - $r->actual_value ) / $r->
							forecast_value )*100);
						}



						if($r->form_id == 1){
					/*
						if(actual_value == 0){
							java_score = 0;
						}
						else */
						if($r->actual_value == null){
							$java_score = 0;
						}elseif($r->actual_value < $r->score0 && $r->actual_value > $r->score1 && $r->nof_target_score >= 1  && $r->score0 != null && $r->score1 != null){
							$java_score = 1;
						}
						elseif($r->actual_value <= $r->score1 && $r->actual_value > $r->score2 && $r->nof_target_score >= 2  && $r->score1 != null && $r->score2 != null){
							$java_score = 2;
						}elseif($r->actual_value <= $r->score2 && $r->actual_value > $r->score3 && $r->nof_target_score >= 3 && $r->score2 != null && $r->score3 != null){
							$java_score = 3;
						}elseif($r->actual_value <= $r->score3 && $r->actual_value > $r->score4 && $r->nof_target_score >= 4 && $r->score3 != null && $r->score4 != null){
							$java_score = 4;
						}elseif($r->actual_value <= $r->score4 && $r->actual_value >= $r->score5 && $r->nof_target_score >= 5 && $r->score4 != null && $r->score5 != null){
							$java_score = 5;
						}elseif($r->actual_value <= $r->score5 && $r->nof_target_score >= 5 && $r->score5 != null){
							$java_score = 5;
						}elseif($r->actual_value <= $r->score4 && $r->nof_target_score >= 4 && $r->score4 != null){
							$java_score = 4;
						}elseif($r->actual_value <= $r->score3 && $r->nof_target_score >= 3 && $r->score3 != null){
							$java_score = 3;
						}elseif($r->actual_value <= $r->score2 && $r->nof_target_score >= 2 && $r->score2 != null){
							$java_score = 2;
						}elseif($r->actual_value <= $r->score1 && $r->nof_target_score >= 1 && $r->score1 != null){
							$java_score = 1;
						}else{
							$java_score = 0;
						}

						}
					}


					if($r->result_type==1){
						//java_weigh_score = (java_score * weight_percent);
						$java_weigh_score = ($java_score * $r->weight_percent) * $r->contribute_percent;
					}elseif($r->result_type==2){
						//java_weigh_score = (java_score * weight_percent)/100;
						$java_weigh_score = (($r->java_score * $r->weight_percent)/100) * $r->contribute_percent;
					}else{
						$java_weigh_score = 0;
					}

					
				} else {
					// do nothing
					
				}
			}	
			elseif ($r->flag_threshold == 0) {
				$java_percent_achievement = 0;
				$java_percent_forecast = 0;
				$java_weigh_score = 0;
				$java_score = $r->score;
				if( $r->value_type_id == 1){ //Bigger is better

					if($r->target_value == 0 && $r->actual_value == 0){
						$java_percent_achievement = 100;
					}elseif($r->target_value == $r->actual_value){
						$java_percent_achievement = 100;
					}elseif($r->target_value == null){ 
						$java_percent_achievement = 0;
					}elseif($r->target_value == 0){ 
						$java_percent_achievement = 0;
					}else{
						$java_percent_achievement = ($r->actual_value / $r->target_value) * 100;
					}
				//---------------------------------------------------------------
					if($r->target_value == 0 && $r->actual_value == 0){
						$java_percent_forecast = 100;
					}elseif($r->target_value == $r->actual_value){
						$java_percent_forecast = 100;
					}elseif($r->forecast_value == null){ 
						$java_percent_forecast = 0;
					}elseif($r->forecast_value == 0){ 
						$java_percent_forecast = 0;
					}else{
						$java_percent_forecast = ($r->actual_value / $r->forecast_value) * 100;
					}


				}elseif( $r->value_type_id == 2){//Smaller is better 

					if($r->target_value == 0 && $r->actual_value == 0){
						$java_percent_achievement = 100;
					}elseif($r->target_value == $r->actual_value){
						$java_percent_achievement = 100;
					}elseif($r->target_value == null){ 
						$java_percent_achievement = 0;
					}elseif($r->target_value == 0){ 
						$java_percent_achievement = 0;
					}else{
						$java_percent_achievement = 100 + ((($r->target_value - $r->actual_value ) / $r->target_value )*100);
					}
				//----------------------------------------------------------------------------------
					if($r->forecast_value == 0 && $r->actual_value == 0){
						$java_percent_forecast = 100;
					}elseif($r->forecast_value == $r->actual_value){
						$java_percent_forecast = 100;
					}elseif($r->forecast_value == null){ 
						$java_percent_forecast = 0;
					}elseif($r->forecast_value == 0){ 
						$java_percent_forecast = 0;
					}else{
						$java_percent_forecast = 100 + ((($r->forecast_value - $r->actual_value ) / $r->forecast_value )*100);
					}
				}

				if($r->result_type==1){
					$java_weigh_score = (($java_percent_achievement * $r->weight_percent) / 100) * $r->contribute_percent;
					//java_weigh_score = ((java_percent_achievement * weight_percent) / 100);
				}elseif($r->result_type==2){
					$java_weigh_score = (($java_percent_achievement * $r->weight_percent) / 100) * $r->contribute_percent;
					//java_weigh_score = ((java_percent_achievement * weight_percent) / 100) ;
				}else{
					$java_weigh_score = 0;
				}


			}
	
			$item_result = AppraisalItemResult::find($r->item_result_id);
			$item_result->actual_value = $r->actual_value;
			$item_result->percent_achievement = $java_percent_achievement;
			$item_result->percent_forecast = $java_percent_forecast;
			$item_result->weigh_score = $java_weigh_score;
			$item_result->updated_by = 'ETL_SEE_KPI';
			$item_result->updated_dttm = date('Y-m-d H:i:s');		
			$item_result->save();
			
		}
		
		// END Recalculate Quantity
		
		// START Competency Result
		
		$com_result = DB::select("
			SELECT period_id, level_id, org_id, position_id, emp_id, item_id,
				SUM((avg_group_scorce * group_weight_percent)) / 100 score
			FROM(
				SELECT cr.period_id, 
					cr.level_id, 
					cr.org_id, 
					cr.position_id, 
					cr.emp_id, 
					cr.item_id,
					cr.assessor_group_id,
					avg(cr.score) avg_group_scorce,
					MAX(cr.group_weight_percent) group_weight_percent
				FROM competency_result cr
				INNER JOIN appraisal_item_result air ON air.item_result_id = cr.item_result_id and (air.emp_result_id = ? or 'All' = ?)
				WHERE cr.period_id IN (
					SELECT ap.period_id
					FROM appraisal_period ap
					WHERE ap.start_date <= ? AND ap.end_date >= ?
					AND ap.appraisal_year = (SELECT current_appraisal_year FROM system_config)
				)
				GROUP BY cr.period_id, cr.level_id, cr.org_id, cr.position_id, cr.emp_id, cr.item_id, cr.assessor_group_id
			)q
			GROUP BY period_id, level_id, org_id, position_id, emp_id, item_id		
		",[$request->emp_result_id, $request->emp_result_id, $request->start_date, $request->start_date]);
		
		foreach ($com_result as $r) {
			AppraisalItemResult::where('period_id', $r->period_id)
			->where('level_id', $r->level_id)
			->where('org_id', $r->org_id)
			->where('position_id', $r->position_id)
			->where('emp_id', $r->emp_id)
			->where('item_id', $r->item_id)
			->update(['score' => $r->score]);
		}
		
		// END Competency Result
		
		// START Quality
		$kpi_result = DB::select("
			SELECT (SELECT result_type FROM system_config) as result_type,
			air.item_result_id,
			al.no_weight as flag_no_weight,
			(SELECT system_config.threshold FROM system_config) as flag_threshold,
			air.item_id,
			astr.form_id,
			air.emp_id,
			air.org_id,
			air.period_id,
			ap.appraisal_year,
			month(ap.start_date) as start_month_no,
			month(ap.end_date) as end_month_no,
			air.score,
			air.weight_percent
			FROM appraisal_item_result air
			inner join appraisal_item ai on ai.item_id = air.item_id
			-- inner join appraisal_item_level ail on ail.item_id = ai.item_id  and air.level_id = ail.level_id
			inner join appraisal_level al on al.level_id = air.level_id 
			inner join appraisal_structure astr on astr.structure_id = ai.structure_id
			inner join appraisal_period ap on ap.period_id = air.period_id
			where air.period_id in (
				SELECT ap.period_id
				FROM appraisal_period ap
				where ap.start_date <= ? and ap.end_date >= ?
				and ap.appraisal_year = (select current_appraisal_year from system_config)
			)
			and astr.form_id = 2
			and (air.emp_result_id = ? or 'All' = ?)
			-- and air.emp_id = 8079
			-- and air.item_result_id in (482)		
		",[$request->start_date, $request->start_date, $request->emp_result_id, $request->emp_result_id]);
		
		foreach ($kpi_result as $r) {
			if ($r->flag_threshold == 1) {
				if ($r->flag_no_weight == 1) {
					
					// do nothing
					
				} elseif ($r->flag_no_weight == 0) {
					$java_weigh_score  = 0;
					$java_score = 0;

					if($r->form_id == 2){
					// Quality
						if($r->result_type == 1){
							$java_weigh_score = ($r->score * $r->weight_percent);
						}else{
							$java_weigh_score = ($r->score * $r->weight_percent)/100;
						}


					}
					
					$item_result = AppraisalItemResult::find($r->item_result_id);
					$item_result->weigh_score = $java_weigh_score;
					$item_result->updated_by = 'ETL_SEE_KPI';
					$item_result->updated_dttm = date('Y-m-d H:i:s');
					$item_result->save();
					
				}
			} elseif ($r->flag_threshold == 0) {
				$java_weigh_score  = 0;
				$java_score = 0;

				if($r->form_id == 2){
				// Quality
					
					if($r->result_type == 1){
						$java_weigh_score = ($r->score * $r->weight_percent);
					}else{
						$java_weigh_score = ($r->score * $r->weight_percent)/100;
					}
					$item_result = AppraisalItemResult::find($r->item_result_id);
					$item_result->weigh_score = $java_weigh_score;
					$item_result->updated_by = 'ETL_SEE_KPI';
					$item_result->updated_dttm = date('Y-m-d H:i:s');	
					$item_result->save();
				}
			}
			
		}
		
		// END Quality
		
		// START Deduct
		$deduct = DB::select("
			SELECT (SELECT result_type FROM system_config) result_type,
			air.item_result_id,
			al.no_weight as flag_no_weight,
			(SELECT system_config.threshold FROM system_config) as flag_threshold,
			air.item_id,
			astr.form_id,
			air.emp_id,
			air.org_id,
			kcm.cds_id,
			air.period_id,
			--  ap.appraisal_year,
			-- month(ap.start_date) as start_month_no,
			-- month(ap.end_date) as end_month_no,
			year(ap.start_date) as period_start_year,
			month(ap.start_date) as period_start_month_no,
			year(ap.end_date) as period_end_year,
			month(ap.end_date) as period_end_month_no,
			-- air.actual_value,
			air.weight_percent,
			air.max_value,
			air.deduct_score_unit,
			ai.value_get_zero
			FROM appraisal_item_result air
			inner join appraisal_item ai on ai.item_id = air.item_id
			-- inner join appraisal_item_level ail on ail.item_id = ai.item_id
			inner join kpi_cds_mapping kcm on kcm.item_id = ai.item_id
			-- inner join appraisal_level al on al.level_id = ail.level_id and air.level_id = ail.level_id 
			inner join appraisal_level al on al.level_id = air.level_id
			inner join appraisal_structure astr on astr.structure_id = ai.structure_id
			inner join appraisal_period ap on ap.period_id = air.period_id
			where air.period_id in (
				SELECT ap.period_id
				FROM appraisal_period ap
				where ap.start_date <= ? and ap.end_date >= ?
				and ap.appraisal_year = (select current_appraisal_year from system_config)
			)
			and astr.form_id = 3 
			and (air.emp_result_id = ? or 'All' = ?)
			-- and air.emp_id = 859		
		",[$request->start_date, $request->start_date, $request->emp_result_id, $request->emp_result_id]);
		
		foreach ($deduct as $r) {
			if (!empty($r->emp_id)) { //individual
				$cds_result = DB::select("
					select cast(ifnull(result.cds_value,defaultValue.cds_value) as DECIMAL(12,2)) as cds_value
					from (select 0.00 as cds_value) defaultValue left join
					(select sum(cds_value) as cds_value 
					from cds_result
					where cds_id= ?
					and emp_id= ?
					and period_id= ?
					and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
					and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'
					) result on defaultValue.cds_value != result.cds_value					
				", [$r->cds_id, $r->emp_id, $r->period_id]);					
				
			} else { //org
				$cds_result = DB::select("
					select cast(ifnull(result.cds_value,defaultValue.cds_value) as DECIMAL(12,2)) as cds_value
					from (select 0.00 as cds_value) defaultValue left join
					(select sum(cds_value) as cds_value 
					from cds_result
					where cds_id= ?
					and org_id= ?
					and period_id= ?
					and appraisal_type_id = ?
					and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
					and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'
					) result on defaultValue.cds_value != result.cds_value					
				", [$r->cds_id, $r->org_id, $r->period_id, $r->appraisal_type_id]);				
			}

			$java_weigh_score = 0;
			$java_over_value = 0;

			if($r->max_value-$r->actual_value > 0){
				$java_over_value = 0;
			}
			elseif($r->max_value-$r->actual_value <= 0){
				$java_over_value = $r->max_value-$r->actual_value;
			}
			if($r->result_type==1){
				$java_weigh_score = ($java_over_value * $r->deduct_score_unit);
			}else{
				$java_weigh_score = ($java_over_value * $r->deduct_score_unit)/100;
			}
			
			$item_result = AppraisalItemResult::find($r->item_result_id);
			$item_result->actual_value = $r->actual_value;
			$item_result->over_value = $java_over_value;
			$item_result->weigh_score = $java_weigh_score;
			$item_result->updated_by = 'ETL_SEE_KPI';
			$item_result->updated_dttm = date('Y-m-d H:i:s');
			$item_result->save();
			
		}
		
		// END Deduct
		
		// START Reward
		$reward = DB::select("
			SELECT (SELECT result_type FROM system_config) result_type,
			air.item_result_id,
			al.no_weight as flag_no_weight,
			(SELECT system_config.threshold FROM system_config) as flag_threshold,
			air.item_id,
			astr.form_id,
			air.emp_id,
			air.org_id,
			kcm.cds_id,
			air.period_id,
			--  ap.appraisal_year,
			-- month(ap.start_date) as start_month_no,
			-- month(ap.end_date) as end_month_no,
			year(ap.start_date) as period_start_year,
			month(ap.start_date) as period_start_month_no,
			year(ap.end_date) as period_end_year,
			month(ap.end_date) as period_end_month_no,
			-- air.actual_value,
			air.weight_percent,
			air.max_value,
			air.deduct_score_unit,
			ai.value_get_zero
			FROM appraisal_item_result air
			inner join appraisal_item ai on ai.item_id = air.item_id
			-- inner join appraisal_item_level ail on ail.item_id = ai.item_id
			inner join kpi_cds_mapping kcm on kcm.item_id = ai.item_id
			-- inner join appraisal_level al on al.level_id = ail.level_id and air.level_id = ail.level_id 
			inner join appraisal_level al on al.level_id = air.level_id
			inner join appraisal_structure astr on astr.structure_id = ai.structure_id
			inner join appraisal_period ap on ap.period_id = air.period_id
			where air.period_id in (
				SELECT ap.period_id
				FROM appraisal_period ap
				where ap.start_date <= ? and ap.end_date >= ?
				and ap.appraisal_year = (select current_appraisal_year from system_config)
			)
			and astr.form_id = 3 
			and (air.emp_result_id = ? or 'All' = ?)
			-- and air.emp_id = 859
		",[$request->start_date, $request->start_date, $request->emp_result_id, $request->emp_result_id]);
		
		foreach ($reward as $r) {
			if (!empty($r->emp_id)) { //individual
				$cds_result = DB::select("
					select cast(ifnull(result.cds_value,defaultValue.cds_value) as DECIMAL(12,2)) as cds_value
					from (select 0.00 as cds_value) defaultValue left join
					(select sum(cds_value) as cds_value 
					from cds_result
					where cds_id= ?
					and emp_id= ?
					and period_id= ?
					and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
					and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'
					) result on defaultValue.cds_value != result.cds_value					
				", [$r->cds_id, $r->emp_id, $r->period_id]);					
				
			} else { //org
				$cds_result = DB::select("
					select cast(ifnull(result.cds_value,defaultValue.cds_value) as DECIMAL(12,2)) as cds_value
					from (select 0.00 as cds_value) defaultValue left join
					(select sum(cds_value) as cds_value 
					from cds_result
					where cds_id= ?
					and org_id= ?
					and period_id= ?
					and appraisal_type_id = ?
					and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) >= '{$r->period_start_year}-{$r->period_start_month_no}-01'
					and date(concat(cds_result.`year`,'-',cds_result.appraisal_month_no,'-',01)) <= '{$r->period_end_year}-{$r->period_end_month_no}-01'
					) result on defaultValue.cds_value != result.cds_value					
				", [$r->cds_id, $r->org_id, $r->period_id, $r->appraisal_type_id]);				
			}


			$java_weigh_score = 0;
			$java_over_value = 0;

			$java_over_value = ($r->actual_value-$r->max_value)> 0 ? $r->actual_value-$r->max_value : 0;

			if($r->result_type==1){
				$java_weigh_score = ($java_over_value * $r->reward_score_unit);
			}else{
				$java_weigh_score = ($java_over_value * $r->reward_score_unit)/100;
			}
	

			
			$item_result = AppraisalItemResult::find($r->item_result_id);
			$item_result->actual_value = $r->actual_value;
			$item_result->over_value = $java_over_value;
			$item_result->weigh_score = $java_weigh_score;
			$item_result->updated_by = 'ETL_SEE_KPI';
			$item_result->updated_dttm = date('Y-m-d H:i:s');		
			$item_result->save();
			
		}
		
		// END Reward
		
		// END Calculate KPI Result by Form Type
		
		// START Calculate KPI Result by Structure Result
		
		// START Structure Not Deduct
		$item_result = DB::select("
			SELECT er.emp_result_id
			, er.period_id
			, er.emp_id
			, ai.structure_id
			, sum(air.weigh_score) as weight_score_sum_detail
			, astr.nof_target_score
			, 0 as weight_percent_deduct_score
			, case when sc.result_type =1 and sc.threshold = 1 then sum(air.weigh_score) / astr.nof_target_score 
					when sc.result_type =1 and sc.threshold = 0 and fy.form_id = 1 then sum(air.weigh_score) --  / air.structure_weight_percent)*100
					when sc.result_type =1 and sc.threshold = 0 and fy.form_id = 2 then sum(air.weigh_score) / astr.nof_target_score 
					else sum(air.weigh_score) end as weigh_score
			, count(air.item_id) as count_of_item
			, air.structure_weight_percent
			-- , now() as etl_dttm
			FROM emp_result er
			inner join appraisal_item_result air on air.emp_result_id = er.emp_result_id
			inner join appraisal_item ai on ai.item_id = air.item_id
			inner join appraisal_structure astr on astr.structure_id = ai.structure_id
			inner join form_type fy on fy.form_id = astr.form_id
			inner join appraisal_period ap on  ap.period_id  = air.period_id 
			CROSS JOIN system_config sc
			where fy.form_id in (1,2)
			-- and status = 'accepted'
			and er.period_id in (
				SELECT ap.period_id
				FROM appraisal_period ap
				where ap.start_date <= ? and ap.end_date >= ?
				 and ap.appraisal_year = (select current_appraisal_year from system_config)
			)
			-- and er.emp_result_id = 83
			-- and er.emp_id = 8079
			and (er.emp_result_id = ? or 'All' = ?)
			group by er.emp_result_id
			, er.period_id
			, er.emp_id
			, ai.structure_id		
		", [$request->start_date, $request->start_date, $request->emp_result_id, $request->emp_result_id]);
	
		
		foreach ($item_result as $r) {
			
			if (!empty($r->emp_id)) { // individual
				// StructureResult::where('emp_result_id', $r->emp_result_id)
				// ->where('period_id', $r->period_id)
				// ->where('emp_id', $r->emp_id)
				// ->where('structure_id', $r->structure_id)
				// ->update([
					// 'weight_score_sum_detail' => $r->weight_score_sum_detail,
					// 'nof_target_score' => $r->nof_target_score,
					// 'count_of_item' => $r->count_of_item,
					// 'weight_percent_deduct_score' => $r->weight_percent_deduct_score,
					// 'weigh_score' => $r->weigh_score,
					// 'etl_dttm' => $r->etl_dttm,
				// ]);
				
				$sr = StructureResult::firstOrCreate([
					'emp_result_id' => $r->emp_result_id, 
					'period_id' => $r->period_id,
					'emp_id' => $r->emp_id,
					'structure_id' => $r->structure_id
				]);
				
				$sr->weight_score_sum_detail = $r->weight_score_sum_detail;
				$sr->nof_target_score = $r->nof_target_score;
				$sr->count_of_item = $r->count_of_item;
				$sr->weight_percent_deduct_score = $r->weight_percent_deduct_score;
				$sr->weigh_score = $r->weigh_score;
				$sr->etl_dttm = date('Y-m-d H:i:s');
				$sr->save();
				
				
			} else { // org
				// StructureResult::where('emp_result_id', $r->emp_result_id)
				// ->where('period_id', $r->period_id)
				// ->where('structure_id', $r->structure_id)
				// ->update([
					// 'weight_score_sum_detail' => $r->weight_score_sum_detail,
					// 'nof_target_score' => $r->nof_target_score,
					// 'count_of_item' => $r->count_of_item,
					// 'weight_percent_deduct_score' => $r->weight_percent_deduct_score,
					// 'weigh_score' => $r->weigh_score,
					// 'etl_dttm' => $r->etl_dttm,
				// ]);		
				$sr = StructureResult::firstOrCreate([
					'emp_result_id' => $r->emp_result_id, 
					'period_id' => $r->period_id,
					'structure_id' => $r->structure_id
				]);
				
				$sr->weight_score_sum_detail = $r->weight_score_sum_detail;
				$sr->nof_target_score = $r->nof_target_score;
				$sr->count_of_item = $r->count_of_item;
				$sr->weight_percent_deduct_score = $r->weight_percent_deduct_score;
				$sr->weigh_score = $r->weigh_score;
				$sr->etl_dttm = date('Y-m-d H:i:s');
				$sr->save();
				
			}
		}
		
		// END Structure Not Deduct
		
		// START Structure Deduct
		$item_result = DB::select("
			SELECT 
			emp_result_id
			,structure_id
			,period_id
			,level_id
			,org_id
			,emp_id
			,position_id
			,sum(weigh_score) as weight_score_sum_detail
			,nof_target_score
			, 0 as count_of_item
			,CASE WHEN unlimit = 1 and MAX(check_SVGZ) = 1
						THEN 0
						ELSE MAX(weight_percent)
						END as weight_percent_deduct_score
			,CASE WHEN unlimit = 0 and MAX(check_SVGZ) = 1 THEN 0
						WHEN sum(weigh_score) = 0 THEN (CASE WHEN unlimit = 1 and MAX(check_SVGZ) = 1 THEN 0 ELSE MAX(weight_percent) END)
						WHEN unlimit = 0 and (CASE WHEN unlimit = 1 and MAX(check_SVGZ) = 1 THEN 0 ELSE MAX(weight_percent) END) + (sum(weigh_score)) < 0 THEN 0
						WHEN unlimit = 1 and (CASE WHEN unlimit = 1 and MAX(check_SVGZ) = 1 THEN 0 ELSE MAX(weight_percent) END) + (sum(weigh_score)) < 0 THEN (CASE WHEN unlimit = 1 and MAX(check_SVGZ) = 1 THEN 0 ELSE MAX(weight_percent) END) + (sum(weigh_score))
						ELSE (CASE WHEN unlimit = 1 and MAX(check_SVGZ) = 1 THEN 0 ELSE MAX(weight_percent) END) + (sum(weigh_score))
						END as weigh_score
			FROM (
						SELECT er.emp_result_id 
						,ai.structure_id
						,er.period_id
						,er.level_id
						,er.org_id
						,er.emp_id
						,er.position_id
						,astr.is_unlimited_deduction as unlimit
						,air.actual_value
						,air.value_get_zero
						,CASE WHEN astr.is_unlimited_deduction = 1 
									THEN 
												CASE WHEN air.value_get_zero IS NULL THEN air.weigh_score
														 WHEN air.actual_value >= air.value_get_zero THEN 0
														 ELSE air.weigh_score
												END
									ELSE air.weigh_score
						 END as weigh_score
						,CASE WHEN  sc.result_type = 2 THEN astr.nof_target_score ELSE 0 END  as nof_target_score
						,CASE WHEN  sc.result_type = 2 THEN air.structure_weight_percent*astr.nof_target_score/100 ELSE air.structure_weight_percent END as weight_percent
						,CASE
							WHEN air.actual_value >= air.value_get_zero THEN 1
								ELSE 0
								END as check_SVGZ -- check_value_get_zero
						FROM emp_result er
						inner join appraisal_item_result air on air.emp_result_id = er.emp_result_id
						inner join appraisal_item ai on ai.item_id = air.item_id
						inner join appraisal_structure astr on astr.structure_id = ai.structure_id
						inner join form_type fy on fy.form_id = astr.form_id
						inner join appraisal_period ap on  ap.period_id  = air.period_id 
						CROSS JOIN system_config sc
						where fy.form_id = 3
						and (er.emp_result_id = ? or 'All' = ?)
						and er.period_id in (
								SELECT ap.period_id
								FROM appraisal_period ap
								where ap.start_date <= ? and ap.end_date >= ?
								and ap.appraisal_year = (select current_appraisal_year from system_config))
						-- and status = 'accepted'
						) sr
			group by structure_id
			,period_id
			,level_id
			,org_id
			,emp_id
			,position_id
	
		", [$request->emp_result_id, $request->emp_result_id,$request->start_date, $request->start_date]);
		
		foreach ($item_result as $r) {
			if (!empty($r->emp_id)) { // individual
				// StructureResult::where('emp_result_id', $r->emp_result_id)
				// ->where('period_id', $r->period_id)
				// ->where('emp_id', $r->emp_id)
				// ->where('structure_id', $r->structure_id)
				// ->update([
					// 'weight_score_sum_detail' => $r->weight_score_sum_detail,
					// 'nof_target_score' => $r->nof_target_score,
					// 'count_of_item' => $r->count_of_item,
					// 'weight_percent_deduct_score' => $r->weight_percent_deduct_score,
					// 'weigh_score' => $r->weigh_score,
					// 'etl_dttm' => $r->etl_dttm,
				// ]);
				$sr = StructureResult::firstOrCreate([
					'emp_result_id' => $r->emp_result_id, 
					'period_id' => $r->period_id,
					'emp_id' => $r->emp_id,
					'structure_id' => $r->structure_id
				]);
				
				$sr->weight_score_sum_detail = $r->weight_score_sum_detail;
				$sr->nof_target_score = $r->nof_target_score;
				$sr->count_of_item = $r->count_of_item;
				$sr->weight_percent_deduct_score = $r->weight_percent_deduct_score;
				$sr->weigh_score = $r->weigh_score;
				$sr->etl_dttm = date('Y-m-d H:i:s');
				$sr->save();				
			} else { // org
				// StructureResult::where('emp_result_id', $r->emp_result_id)
				// ->where('period_id', $r->period_id)
				// ->where('structure_id', $r->structure_id)
				// ->update([
					// 'weight_score_sum_detail' => $r->weight_score_sum_detail,
					// 'nof_target_score' => $r->nof_target_score,
					// 'count_of_item' => $r->count_of_item,
					// 'weight_percent_deduct_score' => $r->weight_percent_deduct_score,
					// 'weigh_score' => $r->weigh_score,
					// 'etl_dttm' => $r->etl_dttm,
				// ]);			
				$sr = StructureResult::firstOrCreate([
					'emp_result_id' => $r->emp_result_id, 
					'period_id' => $r->period_id,
					'structure_id' => $r->structure_id
				]);
				
				$sr->weight_score_sum_detail = $r->weight_score_sum_detail;
				$sr->nof_target_score = $r->nof_target_score;
				$sr->count_of_item = $r->count_of_item;
				$sr->weight_percent_deduct_score = $r->weight_percent_deduct_score;
				$sr->weigh_score = $r->weigh_score;
				$sr->etl_dttm = date('Y-m-d H:i:s');
				$sr->save();				
			}
		}		
		// END Structure Deduct
		
		// START Structure Reward
		$item_result = DB::select("
			SELECT 
			emp_result_id
			,structure_id
			,period_id
			,level_id
			,org_id
			,emp_id
			,position_id
			,sum(weigh_score) as weight_score_sum_detail
			,nof_target_score
			,MAX(weight_percent) as weight_percent_deduct_score
			,CASE WHEN unlimit = 0  THEN MAX(weight_percent)
				  ELSE MAX(weight_percent) + sum(weigh_score)
				  END as weigh_score 
			FROM (
						SELECT er.emp_result_id 
						,ai.structure_id
						,er.period_id
						,er.level_id
						,er.org_id
						,er.emp_id
						,er.position_id
						,astr.is_unlimited_reward as unlimit
						,air.actual_value
						,air.value_get_zero
						,air.weigh_score
						,0 as nof_target_score
						,air.structure_weight_percent as weight_percent
			--			,ac.weight_percent 
						FROM emp_result er
						inner join appraisal_item_result air on air.emp_result_id = er.emp_result_id
						inner join appraisal_item ai on ai.item_id = air.item_id
						inner join appraisal_structure astr on astr.structure_id = ai.structure_id
						inner join form_type fy on fy.form_id = astr.form_id
			--			inner join appraisal_criteria ac on ac.structure_id = astr.structure_id 
			--			 and ac.appraisal_level_id = er.level_id
						inner join appraisal_period ap on  ap.period_id  = air.period_id 
						CROSS JOIN system_config sc
						where fy.form_id = 4
						and (er.emp_result_id = ? or 'All' = ?)
						and er.period_id in (
								SELECT ap.period_id
								FROM appraisal_period ap
								where ap.start_date <= ? and ap.end_date >= ?
								and ap.appraisal_year = (select current_appraisal_year from system_config))
						-- and status = 'accepted'
						) sr
			group by emp_result_id
			,structure_id
			,period_id
			,level_id
			,org_id
			,emp_id
			,position_id
		", [$request->emp_result_id, $request->emp_result_id,$request->start_date, $request->start_date]);
		
		foreach ($item_result as $r) {
			if (!empty($r->emp_id)) { // individual
				// StructureResult::where('emp_result_id', $r->emp_result_id)
				// ->where('period_id', $r->period_id)
				// ->where('emp_id', $r->emp_id)
				// ->where('structure_id', $r->structure_id)
				// ->update([
					// 'weight_score_sum_detail' => $r->weight_score_sum_detail,
					// 'nof_target_score' => $r->nof_target_score,
					// 'weight_percent_deduct_score' => $r->weight_percent_deduct_score,
					// 'weigh_score' => $r->weigh_score,
					// 'etl_dttm' => $r->etl_dttm,
				// ]);
				$sr = StructureResult::firstOrCreate([
					'emp_result_id' => $r->emp_result_id, 
					'period_id' => $r->period_id,
					'emp_id' => $r->emp_id,
					'structure_id' => $r->structure_id
				]);
				
				$sr->weight_score_sum_detail = $r->weight_score_sum_detail;
				$sr->nof_target_score = $r->nof_target_score;
				$sr->weight_percent_deduct_score = $r->weight_percent_deduct_score;
				$sr->weigh_score = $r->weigh_score;
				$sr->etl_dttm = date('Y-m-d H:i:s');
				$sr->save();				
			} else { // org
				// StructureResult::where('emp_result_id', $r->emp_result_id)
				// ->where('period_id', $r->period_id)
				// ->where('structure_id', $r->structure_id)
				// ->update([
					// 'weight_score_sum_detail' => $r->weight_score_sum_detail,
					// 'nof_target_score' => $r->nof_target_score,
					// 'weight_percent_deduct_score' => $r->weight_percent_deduct_score,
					// 'weigh_score' => $r->weigh_score,
					// 'etl_dttm' => $r->etl_dttm,
				// ]);	
				$sr = StructureResult::firstOrCreate([
					'emp_result_id' => $r->emp_result_id, 
					'period_id' => $r->period_id,
					'structure_id' => $r->structure_id
				]);
				
				$sr->weight_score_sum_detail = $r->weight_score_sum_detail;
				$sr->nof_target_score = $r->nof_target_score;
				$sr->weight_percent_deduct_score = $r->weight_percent_deduct_score;
				$sr->weigh_score = $r->weigh_score;
				$sr->etl_dttm = date('Y-m-d H:i:s');
				$sr->save();							
			}
		}		
		// END Structure Reward
		
		
		
		
		
		// END Calculate KPI Result by Structure Result
		
		// START Emp Result Summary
		$emp_result = DB::select("
			SELECT sr.emp_result_id,
			sum(sr.weigh_score) as result_score
			FROM structure_result sr
			INNER JOIN emp_result er on er.emp_result_id = sr.emp_result_id
			INNER JOIN appraisal_criteria ac on ac.appraisal_level_id = er.level_id and ac.structure_id = sr.structure_id and ac.appraisal_form_id = er.appraisal_form_id
			WHERE
			sr.period_id IN (
				SELECT ap.period_id
				FROM appraisal_period ap
				where ap.start_date <= ? and ap.end_date >= ?
			)
			AND (sr.emp_result_id = ? or 'All' = ?)
			AND sr.emp_result_id IS NOT NUll
			group by sr.emp_result_id		
		",[$request->start_date, $request->start_date, $request->emp_result_id, $request->emp_result_id]);
		
		foreach ($emp_result as $r) {
			$result = EmpResult::find($r->emp_result_id);
			$result->result_score = $r->result_score;
			$result->updated_dttm = date('Y-m-d H:i:s');
			$result->save();
		}
	
	
		// END Emp Result Summary
		

		return response()->json(['status' => '200']);
	}
	
	
}

?>
