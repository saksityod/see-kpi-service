<?php

namespace App\Http\Controllers;

use App\EmpResult;
use App\WorkflowStage;
use App\AppraisalItemResult;
use App\AppraisalLevel;
use App\EmpResultStage;
use App\ActionPlan;
use App\Reason;
use App\AttachFile;
use App\SystemConfiguration;
use App\Employee;
use App\Org;

use Auth;
use DB;
use File;
use Validator;
use Excel;
use Config;
use Mail;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AppraisalController extends Controller
{

	public function __construct()
	{
		$this->middleware('jwt.auth');
	}

	public function year_list()
	{
		$items = DB::select("
			Select distinct appraisal_year appraisal_year_id, appraisal_year
			from appraisal_period
			order by appraisal_year desc
		");
		return response()->json($items);
	}

	public function year_list_assignment()
	{
		$items = DB::select("
			SELECT DISTINCT appraisal_year appraisal_year_id,
			appraisal_year
			from appraisal_period
			LEFT OUTER JOIN system_config on system_config.current_appraisal_year = appraisal_period.appraisal_year
		");
		return response()->json($items);
	}

	public function period_list(Request $request)
	{
		$items = DB::select("
			Select period_id, period_no, appraisal_period_desc
			from appraisal_period
			where appraisal_year = ?
			order by period_id asc
		", array($request->appraisal_year));
		return response()->json($items);
	}
	
	public function period_list_salary(Request $request)
	{
		$items = DB::select("
			Select period_id, period_no, appraisal_period_desc
			from appraisal_period
			where appraisal_year = ? and is_raise = 1 
			order by period_id asc
		", array($request->appraisal_year));
		return response()->json($items);
	}


    public function al_list()
    {
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
		", array(Auth::id()));

		if ($all_emp[0]->count_no > 0) {
			$items = DB::select("
				Select level_id, appraisal_level_name
				From appraisal_level
				Where is_active = 1
				and is_hr = 0
				Order by level_id asc
			");
		} else {

			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee
				where chief_emp_code = ?
			", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and chief_emp_code != emp_code
					and is_active = 1
				");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;

			//echo $in_emp;
			$items = DB::select("
				select distinct al.level_id, al.appraisal_level_name
				from employee el, appraisal_level al
				where el.level_id = al.level_id
				and el.emp_code in ({$in_emp})
				and al.is_hr = 0
				order by al.level_id asc
			");
		}

		return response()->json($items);
    }

	public function auto_org_name(Request $request)
	{

		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
		", array(Auth::id()));

		if ($all_emp[0]->count_no > 0) {
			$qinput = array();
			$query = "
				Select distinct org_id, org_code, org_name
				From org
				Where org_name like ?
			";

			$qfooter = " Order by org_name limit 10";
			$qinput[] = '%'.$request->org_name.'%';

			$items = DB::select($query.$qfooter,$qinput);
		} else {

			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee
				where chief_emp_code = ?
			", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and is_active = 1
					and chief_emp_code != emp_code
				");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;

			$qinput = array();
			$query = "
				Select distinct b.org_id, b.org_code, b.org_name
				From employee a left outer join org b on a.org_id = b.org_id
				Where b.org_name like ?
				and a.emp_code in ({$in_emp})

			";

			$qfooter = " Order by b.org_name limit 10";
			$qinput[] = '%'.$request->org_name.'%';

			$items = DB::select($query.$qfooter,$qinput);

		}

		return response()->json($items);
	}

	public function auto_position_name(Request $request)
	{

		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
		", array(Auth::id()));

		if ($all_emp[0]->count_no > 0) {
			$qinput = array();
			$query = "
				Select distinct position_id, position_code, position_name
				From position
				Where position_name like ?
			";

			$qfooter = " Order by position_name limit 10";
			$qinput[] = '%'.$request->position_name.'%';

			$items = DB::select($query.$qfooter,$qinput);
		} else {

			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee
				where chief_emp_code = ?
			", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and is_active = 1
					and chief_emp_code != emp_code
				");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;

			$qinput = array();
			$query = "
				Select distinct b.position_id, b.position_code, b.position_name
				From employee a left outer join position b on a.position_id = b.position_id
				Where b.position_name like ?
				and a.emp_code in ({$in_emp})

			";

			$qfooter = " Order by b.position_name limit 10";
			$qinput[] = '%'.$request->position_name.'%';

			$items = DB::select($query.$qfooter,$qinput);

		}

		return response()->json($items);
	}

	public function auto_employee_name(Request $request)
	{

		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
		", array(Auth::id()));

		if ($all_emp[0]->count_no > 0) {
			$qinput = array();
			$query = "
				Select emp_id, emp_code, emp_name
				From employee
				Where emp_name like ?
			";

			$qfooter = " Order by emp_name limit 10 ";
			$qinput[] = '%'.$request->emp_name.'%';
			empty($request->org_id) ?: ($query .= " and org_id = ? " AND $qinput[] = $request->section_code);
			empty($request->position_id) ?: ($query .= " and position_id = ? " AND $qinput[] = $request->position_code);

			$items = DB::select($query.$qfooter,$qinput);
		} else {

			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee 
				where chief_emp_code = ?
			", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and chief_emp_code != emp_code
					and is_active = 1
				");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;
			$qinput = array();
			$query = "
				Selecst e.emp_id, e.emp_code, e.emp_name
				From employee e 
				inner join appraisal_item_result a
				on e.emp_id = a.emp_id
				Where e.emp_name like ?
				and e.emp_code in ({$in_emp})
			";

			$qfooter = " Order by emp_name limit 10 ";
			$qinput[] = '%'.$request->emp_name.'%';
			empty($request->org_id) ?: ($query .= " and a.org_id = ? " AND $qinput[] = $request->org_id);
			empty($request->position_id) ?: ($query .= " and a.position_id = ? " AND $qinput[] = $request->position_id);

			$items = DB::select($query.$qfooter,$qinput);
		}

		return response()->json($items);
	}

	public function index(Request $request)
	{

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
				select a.emp_result_id, a.emp_id, b.emp_code, b.emp_name, d.appraisal_level_name, e.appraisal_type_id, e.appraisal_type_name, p.position_name, o.org_code, o.org_name, po.org_name parent_org_name, f.to_action, a.stage_id, g.period_id, concat(g.appraisal_period_desc,' Start Date: ',g.start_date,' End Date: ',g.end_date) appraisal_period_desc
				from emp_result a
				left outer join employee b
				on a.emp_id = b.emp_id
				left outer join appraisal_level d
				on a.level_id = d.level_id
				left outer join appraisal_type e
				on a.appraisal_type_id = e.appraisal_type_id
				left outer join appraisal_stage f
				on a.stage_id = f.stage_id
				left outer join appraisal_period g
				on a.period_id = g.period_id
				left outer join position p
				on a.position_id = p.position_id
				left outer join org o
				on a.org_id = o.org_id
				left outer join org po
				on o.parent_org_code = po.org_code
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
			/*
			echo $query. " order by period_id,emp_code,org_code  asc ";
			print_r($qinput);
			*/
			$items = DB::select($query. " order by period_id,emp_code,org_code  asc ", $qinput);

		} else {

			$re_emp = array();

			$emp_list = array();

			$emps = DB::select("
				select distinct emp_code
				from employee
				where chief_emp_code = ?
			", array(Auth::id()));

			foreach ($emps as $e) {
				$emp_list[] = $e->emp_code;
				$re_emp[] = $e->emp_code;
			}

			$emp_list = array_unique($emp_list);

			// Get array keys
			$arrayKeys = array_keys($emp_list);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($emp_list as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			do {
				empty($in_emp) ? $in_emp = "null" : null;

				$emp_list = array();

				$emp_items = DB::select("
					select distinct emp_code
					from employee
					where chief_emp_code in ({$in_emp})
					and is_active = 1
					and chief_emp_code != emp_code
				");

				foreach ($emp_items as $e) {
					$emp_list[] = $e->emp_code;
					$re_emp[] = $e->emp_code;
				}

				$emp_list = array_unique($emp_list);

				// Get array keys
				$arrayKeys = array_keys($emp_list);
				// Fetch last array key
				$lastArrayKey = array_pop($arrayKeys);
				//iterate array
				$in_emp = '';
				foreach($emp_list as $k => $v) {
					if($k == $lastArrayKey) {
						//during array iteration this condition states the last element.
						$in_emp .= "'" . $v . "'";
					} else {
						$in_emp .= "'" . $v . "'" . ',';
					}
				}
			} while (!empty($emp_list));

			$re_emp[] = Auth::id();
			$re_emp = array_unique($re_emp);

			// Get array keys
			$arrayKeys = array_keys($re_emp);
			// Fetch last array key
			$lastArrayKey = array_pop($arrayKeys);
			//iterate array
			$in_emp = '';
			foreach($re_emp as $k => $v) {
				if($k == $lastArrayKey) {
					//during array iteration this condition states the last element.
					$in_emp .= "'" . $v . "'";
				} else {
					$in_emp .= "'" . $v . "'" . ',';
				}
			}

			empty($in_emp) ? $in_emp = "null" : null;
			$dotline_code = Auth::id();
			if ($request->appraisal_type_id == 2) {
				$query = "
					select a.emp_result_id, b.emp_code, b.emp_name, d.appraisal_level_name, e.appraisal_type_id, e.appraisal_type_name, p.position_name, o.org_code, o.org_name, po.org_name parent_org_name, f.to_action, a.stage_id, g.period_id, concat(g.appraisal_period_desc,' Start Date: ',g.start_date,' End Date: ',g.end_date) appraisal_period_desc
					from emp_result a
					left outer join employee b
					on a.emp_id = b.emp_id
					left outer join appraisal_level d
					on a.level_id = d.level_id
					left outer join appraisal_type e
					on a.appraisal_type_id = e.appraisal_type_id
					left outer join appraisal_stage f
					on a.stage_id = f.stage_id
					left outer join appraisal_period g
					on a.period_id = g.period_id
					left outer join position p
					on a.position_id = p.position_id
					left outer join org o
					on a.org_id = o.org_id
					left outer join org po
					on o.parent_org_code = po.org_code
					where d.is_hr = 0
					and (b.emp_code in ({$in_emp}) or b.dotline_code = '{$dotline_code}')
				";

				empty($request->appraisal_year) ?: ($query .= " and g.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
				empty($request->period_no) ?: ($query .= " and g.period_id = ? " AND $qinput[] = $request->period_no);
				empty($request->level_id) ?: ($query .= " and a.level_id = ? " AND $qinput[] = $request->level_id);
				empty($request->level_id_org) ?: ($query .= " and o.level_id = ? " AND $qinput[] = $request->level_id_org);
				empty($request->appraisal_type_id) ?: ($query .= " and a.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
				empty($request->org_id) ?: ($query .= " and a.org_id = ? " AND $qinput[] = $request->org_id);
				empty($request->position_id) ?: ($query .= " and a.position_id = ? " AND $qinput[] = $request->position_id);
				empty($request->emp_id) ?: ($query .= " And a.emp_id = ? " AND $qinput[] = $request->emp_id);

				/*
				echo $query. " order by period_id,emp_code,org_code  asc ";
				echo "<br>";
				print_r($qinput);
				*/

				$items = DB::select($query. " order by period_id,emp_code,org_code  asc ", $qinput);

			} else {

				$query = "
					select a.emp_result_id, b.emp_code, b.emp_name, d.appraisal_level_name, e.appraisal_type_id, e.appraisal_type_name, p.position_name, o.org_code, o.org_name, po.org_name parent_org_name, f.to_action, a.stage_id, g.period_id, concat(g.appraisal_period_desc,' Start Date: ',g.start_date,' End Date: ',g.end_date) appraisal_period_desc
					from emp_result a
					left outer join employee b
					on a.emp_id = b.emp_id
					left outer join appraisal_level d
					on a.level_id = d.level_id
					left outer join appraisal_type e
					on a.appraisal_type_id = e.appraisal_type_id
					left outer join appraisal_stage f
					on a.stage_id = f.stage_id
					left outer join appraisal_period g
					on a.period_id = g.period_id
					left outer join position p
					on a.position_id = p.position_id
					left outer join org o
					on a.org_id = o.org_id
					left outer join org po
					on o.parent_org_code = po.org_code
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

				$items = DB::select($query. " order by period_id,emp_code,org_code  asc ", $qinput);

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


		$groups = array();
		foreach ($itemsForCurrentPage as $item) {
			$key = "p".$item->period_id;
			if (!isset($groups[$key])) {
				$groups[$key] = array(
					'items' => array($item),
					'appraisal_period_desc' => $item->appraisal_period_desc,
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

	public function show(Request $request, $emp_result_id)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}
		$head = DB::select("
			SELECT b.emp_code, b.emp_name, b.working_start_date, p.position_name, o.org_code, o.org_name, po.org_name parent_org_name, b.chief_emp_code, b.has_second_line, e.emp_name chief_emp_name, s.emp_code second_chief_emp_code, s.emp_name second_chief_emp_name, c.appraisal_period_desc, a.appraisal_type_id, d.appraisal_type_name, a.stage_id, f.status, a.result_score, f.edit_flag, al.no_weight, a.position_id, a.org_id
			FROM emp_result a
			left outer join employee b
			on a.emp_id = b.emp_id
			left outer join appraisal_period c
			on c.period_id = a.period_id
			left outer join appraisal_type d
			on a.appraisal_type_id = d.appraisal_type_id
			left outer join employee e
			on b.chief_emp_code = e.emp_code
			left outer join employee s
			on e.chief_emp_code = s.emp_code
			left outer join appraisal_stage f
			on a.stage_id = f.stage_id
			left outer join position p
			on b.position_id = p.position_id
			left outer join org o
			on a.org_id = o.org_id
			left outer join org po
			on o.parent_org_code = po.org_code
			left outer join appraisal_level al
			on a.level_id = al.level_id
			where a.emp_result_id = ?
		", array($emp_result_id));
		
		if($head[0]->emp_code==Auth::id()) {
			$items = DB::select("
				select DISTINCT b.item_name,uom.uom_name, b.structure_id, c.structure_name, d.form_id, d.app_url, c.nof_target_score, a.*, e.perspective_name, a.weigh_score, f.weigh_score total_weigh_score, a.weight_percent, g.weight_percent total_weight_percent, al.no_weight,
				if(ifnull(a.target_value,0) = 0,0,(ifnull(a.actual_value,0)/a.target_value)*100) achievement, a.percent_achievement, h.result_threshold_group_id, c.is_value_get_zero, (select count(1) from appraisal_item_result_doc where a.item_result_id = item_result_id) files_amount
					from appraisal_item_result a
				left outer join appraisal_item b
				on a.item_id = b.item_id
				left outer join appraisal_structure c
				on b.structure_id = c.structure_id
				left outer join form_type d
				on c.form_id = d.form_id
				left outer join perspective e
				on b.perspective_id = e.perspective_id
				left outer join structure_result f
				on a.emp_result_id = f.emp_result_id
				and c.structure_id = f.structure_id
				left outer join appraisal_criteria g
				on c.structure_id = g.structure_id
				and a.level_id = g.appraisal_level_id
				left outer join appraisal_level al
				on a.level_id = al.level_id
				left outer join emp_result h
				on a.emp_result_id = h.emp_result_id
				left join uom on  b.uom_id= uom.uom_id
				where a.emp_result_id = ?
				and d.form_id != 2
				order by c.seq_no, b.item_name
				", array($emp_result_id));
		} else {
			$items = DB::select("
				select DISTINCT b.item_name,b.formula_desc,uom.uom_name, b.structure_id, c.structure_name, d.form_id, d.app_url, c.nof_target_score, a.*, e.perspective_name, a.weigh_score, f.weigh_score total_weigh_score, a.weight_percent, g.weight_percent total_weight_percent, al.no_weight,
				if(ifnull(a.target_value,0) = 0,0,(ifnull(a.actual_value,0)/a.target_value)*100) achievement, a.percent_achievement, h.result_threshold_group_id, c.is_value_get_zero, (select count(1) from appraisal_item_result_doc where a.item_result_id = item_result_id) files_amount
					from appraisal_item_result a
				left outer join appraisal_item b
				on a.item_id = b.item_id
				INNER JOIN appraisal_item ai
				on ai.item_id = a.item_id
				left outer join appraisal_structure c
				on b.structure_id = c.structure_id
				left outer join form_type d
				on c.form_id = d.form_id
				left outer join perspective e
				on b.perspective_id = e.perspective_id
				left outer join structure_result f
				on a.emp_result_id = f.emp_result_id
				and c.structure_id = f.structure_id
				left outer join appraisal_criteria g
				on c.structure_id = g.structure_id
				and a.level_id = g.appraisal_level_id
				left outer join appraisal_level al
				on a.level_id = al.level_id
				left outer join emp_result h
				on a.emp_result_id = h.emp_result_id
				left join uom on  b.uom_id= uom.uom_id
				where a.emp_result_id = ?
				order by c.seq_no asc, b.item_name
				", array($emp_result_id));
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

			/*
			$check = DB::select("
				select ifnull(max(a.end_threshold),0) max_no
				from result_threshold a left outer join result_threshold_group b
				on a.result_threshold_group_id = b.result_threshold_group_id
				where b.result_threshold_group_id = ?
				and b.result_type = 2
			", array($item->result_threshold_group_id));

			if ($check[0]->max_no == 0) {
				$total_weight = $item->structure_weight_percent;
			} else {
				$total_weight = ($check[0]->max_no * $item->structure_weight_percent) / 100;
			}

			*/

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
					'is_value_get_zero' => $item->is_value_get_zero,
					'nof_target_score' => $item->nof_target_score,
					'total_weight' => $total_weight,
					'hint' => $hint,
					'total_weigh_score' => $item->total_weigh_score,
					'total_weight_percent' => $item->structure_weight_percent,
					'no_weight' => $item->no_weight,
					'threshold' => $config->threshold,
					'result_type' => $config->result_type
				);
			} else {
				$groups[$key]['items'][] = $item;
			//	$groups[$key]['total_weight'] += $item->weight_percent;
				$groups[$key]['count'] += 1;
			}
		}
	//	$resultT = $items->toArray();
	//	$items['group'] = $groups;

		$stage = DB::select("
			SELECT a.created_by, a.created_dttm, b.from_action, b.to_action, a.remark
			FROM emp_result_stage a
			left outer join appraisal_stage b
			on a.stage_id = b.stage_id
			where a.emp_result_id = ?
			order by a.created_dttm asc
		", array($emp_result_id));

		return response()->json(['head' => $head, 'data' => $items, 'group' => $groups, 'stage' => $stage]);

	}

	public function edit_assign_to(Request $request)
	{

		$al = DB::select("
			select b.appraisal_level_id, b.is_hr
			from emp_level a
			left outer join appraisal_level b
			on a.appraisal_level_id = b.appraisal_level_id
			where a.emp_code = ?
		", array(Auth::id()));

		if (empty($al)) {
			$is_hr = null;
			$al_id = null;
		} else {
			$is_hr = $al[0]->is_hr;
			$al_id = $al[0]->appraisal_level_id;
		}

		$items = DB::select("
			select distinct a.to_appraisal_level_id, b.appraisal_level_name
			from workflow_stage a
			left outer join appraisal_level b
			on a.to_appraisal_level_id = b.appraisal_level_id
			where from_stage_id = ?
			and from_appraisal_level_id = ?
			and stage_id > 16
		", array($request->stage_id, $al_id));

		if (empty($items)) {
			$workflow = WorkflowStage::find($request->stage_id);
			empty($workflow->to_stage_id) ? $to_stage_id = "null" : $to_stage_id = $workflow->to_stage_id;
			$items = DB::select("
				select distinct a.to_appraisal_level_id, b.appraisal_level_name
				from workflow_stage a
				left outer join appraisal_level b
				on a.to_appraisal_level_id = b.appraisal_level_id
				where stage_id in ({$to_stage_id})
				and from_appraisal_level_id = ?
				and stage_id > 16
			", array($al_id));
		}

		return response()->json($items);
	}

	public function edit_action_to(Request $request)
	{
		// $al = DB::select("
			// select b.appraisal_level_id, b.is_hr
			// from emp_level a
			// left outer join appraisal_level b
			// on a.appraisal_level_id = b.appraisal_level_id
			// where a.emp_code = ?
		// ", array(Auth::id()));

		// if (empty($al)) {
			// $is_hr = null;
			// $al_id = null;
		// } else {
			// $is_hr = $al[0]->is_hr;
			// $al_id = $al[0]->appraisal_level_id;
		// }

		// $items = DB::select("
			// select stage_id, to_action
			// from workflow_stage
			// where from_stage_id = ?
			// and to_appraisal_level_id = ?
			// and from_appraisal_level_id = ?
			// and stage_id > 16
		// ", array($request->stage_id, $request->to_appraisal_level_id, $al_id));

		// if (empty($items)) {
			// $workflow = WorkflowStage::find($request->stage_id);
			// empty($workflow->to_stage_id) ? $to_stage_id = "null" : $to_stage_id = $workflow->to_stage_id;
			// $items = DB::select("
				// select a.stage_id, a.to_action
				// from workflow_stage a
				// left outer join appraisal_level b
				// on a.to_appraisal_level_id = b.appraisal_level_id
				// where stage_id in ({$to_stage_id})
				// and to_appraisal_level_id = ?
				// and from_appraisal_level_id = ?
				// and stage_id > 16
			// ", array($request->to_appraisal_level_id, $al_id));
		// }

		// $emp = DB::select("
			// select is_hr
			// from employee a
			// left outer join appraisal_level b
			// on a.level_id = b.level_id
			// where emp_code = ?
		// ", array(Auth::id()));

		// $is_hr = $emp[0]->is_hr;

		// if ($is_hr == 1) {
			// $hr_query = " and hr_see = 1 ";
		// } else {
			// $hr_query = "";
		// }

		// $items = DB::select("
			// select stage_id, to_action
			// from appraisal_stage
			// where from_stage_id = ?
			// and appraisal_flag = 1
		// " . $hr_query, array($request->stage_id));

		// if (empty($items)) {
			// $workflow = WorkflowStage::find($request->stage_id);
			// empty($workflow->to_stage_id) ? $to_stage_id = "null" : $to_stage_id = $workflow->to_stage_id;
			// $items = DB::select("
				// select stage_id, to_action
				// from appraisal_stage a
				// where stage_id in ({$to_stage_id})
				// and appraisal_flag = 1
			// " . $hr_query);
		// }
		$hr_see = null;
		$self_see = null;
		$first_see = null;
		$second_see = null;
		$has_second = null;
		
		$hr_see = DB::select("
			select b.is_hr
			from employee a left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?		
		", array(Auth::id()));
		
		empty($hr_see) ? $hr_see = null : $hr_see = $hr_see[0]->is_hr;
		
		if ($hr_see == 0) {
			$hr_see = null;
		}
		
		if ($request->appraisal_type_id == 2) {
			if ($request->emp_code == Auth::id()) {
				$self_see = 1;
			} else {
				$self_see = null;
			};
			
			$employee = Employee::find($request->emp_code);
			
			if (empty($employee)) { 
				$chief_emp_code = null;
			} else {		
				$chief_emp_code = $employee->chief_emp_code;
				if ($chief_emp_code == Auth::id()) {
					$first_see = 1;
				} else {
					$first_see = null;
				}			
				
				if ($employee->has_second_line == 1) {
					$has_second = 1;
					$check_second = DB::select("
						select chief_emp_code
						from employee
						where emp_code = ?
					", array($chief_emp_code));
					if (empty($check_second)) {
						$second_see = null;
					} else {
						if ($check_second[0]->chief_emp_code == Auth::id()) {
							$second_see = 1;
						} else {
							$second_see = null;
						}
					}
				} else {
					$second_see = null;
					$has_second = 0;
				}			
			}
		} else {
		
			$cu = Employee::find(Auth::id());
			$co = Org::find($cu->org_id);		
		
			if ($request->org_code == $co->org_code) {
				$self_see = 1;
			} else {
				$self_see = null;
			};
			
			$org = Org::where('org_code',$request->org_code)->first();
			
			if (empty($org)) { 
				$parent_org_code = null;
			} else {		
				$parent_org_code = $org->parent_org_code;
				if ($parent_org_code == $co->org_code) {
					$first_see = 1;
				} else {
					$first_see = null;
				}			
				$check_second = DB::select("
					select parent_org_code
					from org
					where org_code = ?
				", array($parent_org_code));
				if (empty($check_second)) {
					$second_see = null;
				} else {
					if ($check_second[0]->parent_org_code == $co->org_code) {
						$second_see = 1;
					} else {
						$second_see = null;
					}
				}	
			}
		
		}
		
		if ($has_second == 1) {
			$items = DB::select("
				select stage_id, to_action
				from appraisal_stage 
				where from_stage_id = ?
				and appraisal_flag = 1
				and appraisal_type_id = ?
				and (
					hr_see = ?
					or self_see = ?
					or first_see = ?
					or second_see = ?
				)
			", array($request->stage_id,$request->appraisal_type_id,$hr_see,$self_see,$first_see,$second_see));
			
			if (empty($items)) {
				$workflow = WorkflowStage::find($request->stage_id);
				empty($workflow->to_stage_id) ? $to_stage_id = "null" : $to_stage_id = $workflow->to_stage_id;
				$items = DB::select("	
					select stage_id, to_action
					from appraisal_stage a
					where stage_id in ({$to_stage_id})
					and appraisal_flag = 1
					and appraisal_type_id = ?
					and (
						hr_see = ?
						or self_see = ?
						or first_see = ?
						or second_see = ?
					)
				",array($request->appraisal_type_id,$hr_see,$self_see,$first_see,$second_see));
			}
		} else {
			$workflow = WorkflowStage::find($request->stage_id);
			if ($workflow->no_second_line_stage_id == 0) {
				$items = DB::select("
					select stage_id, to_action
					from appraisal_stage 
					where from_stage_id = ?
					and appraisal_flag = 1
					and appraisal_type_id = ?
					and (
						hr_see = ?
						or self_see = ?
						or first_see = ?
						or second_see = ?
					)
				", array($request->stage_id,$request->appraisal_type_id,$hr_see,$self_see,$first_see,$second_see));			
				if (empty($items)) {
					$workflow = WorkflowStage::find($request->stage_id);
					empty($workflow->to_stage_id) ? $to_stage_id = "null" : $to_stage_id = $workflow->to_stage_id;
					$items = DB::select("	
						select stage_id, to_action
						from appraisal_stage a
						where stage_id in ({$to_stage_id})
						and appraisal_flag = 1
						and appraisal_type_id = ?
						and (
							hr_see = ?
							or self_see = ?
							or first_see = ?
							or second_see = ?
						)
					",array($request->appraisal_type_id,$hr_see,$self_see,$first_see,$second_see));
				}				
			} else {
				empty($workflow->no_second_line_stage_id) ? $to_stage_id = "null" : $to_stage_id = $workflow->no_second_line_stage_id;
				$items = DB::select("	
					select stage_id, to_action
					from appraisal_stage a
					where stage_id in ({$to_stage_id})
					and appraisal_flag = 1
					and appraisal_type_id = ?
					and (
						hr_see = ?
						or self_see = ?
						or first_see = ?
						or second_see = ?
					)
				",array($request->appraisal_type_id,$hr_see,$self_see,$first_see,$second_see));			
			}
		}
		//return response()->json(['items'=>$items,'hr_see'=>$hr_see,'self_see'=>$self_see,'first_see'=>$first_see,'second_see'=>$second_see,'chief_emp_code'=>$chief_emp_code,'auth_id'=>Auth::id()]);
		return response()->json($items);

	}

	public function update(Request $request, $emp_result_id)
	{
		// if ($request->stage_id < 14) {
			// return response()->json(['status' => 400, 'data' => 'Invalid action.']);
		// }

		// $checklevel = DB::select("
			// select appraisal_level_id
			// from emp_level
			// where emp_code = ?
		// ", array(Auth::id()));

		// if (empty($checklevel)) {
			// return response()->json(['status' => 400, 'data' => 'Permission Denied.']);
		// } else {
			// $alevel = AppraisalLevel::find($checklevel[0]->appraisal_level_id);
			// if ($alevel->is_hr == 1) {
				// return response()->json(['status' => 400, 'data' => 'Permission Denied for HR user.']);
			// }

			// $checkop = DB::select("
				// select appraisal_level_id
				// from appraisal_level
				// where parent_id = ?
			// ", array($alevel->appraisal_level_id));

			// if (empty($checkop)) {
				// return response()->json(['status' => 400, 'data' => 'Permission Denied for Operation Level user.']);
			// }

		// }

		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		if (!empty($request->appraisal)) {
			foreach ($request->appraisal as $a) {
				$aresult = AppraisalItemResult::find($a['item_result_id']);
				if (empty($aresult)) {
				} else {
					array_key_exists('first_score', $a) ? $aresult->first_score = $a['first_score'] : null;
					array_key_exists('second_score', $a) ? $aresult->second_score = $a['second_score'] : null;
					array_key_exists('score', $a) ? $aresult->score = $a['score'] : null;
					array_key_exists('forecast_value', $a) ? $aresult->forecast_value = $a['forecast_value'] : null;
					array_key_exists('actual_value', $a) ? $aresult->actual_value = $a['actual_value'] : null;
					$aresult->updated_by = Auth::id();
					$aresult->save();
				}
			}
		}

		$stage = WorkflowStage::find($request->stage_id);
		$emp = EmpResult::find($emp_result_id);
		$emp->stage_id = $request->stage_id;
		$emp->status = $stage->status;
		$emp->updated_by = Auth::id();
		$emp->save();

		$emp_stage = new EmpResultStage;
		$emp_stage->emp_result_id = $emp_result_id;
		$emp_stage->stage_id = $request->stage_id;
		$emp_stage->remark = $request->remark;
		$emp_stage->created_by = Auth::id();
		$emp_stage->updated_by = Auth::id();
		$emp_stage->save();

		$mail_error = '';
		
		if ($config->email_reminder_flag == 1) {
			Config::set('mail.driver',$config->mail_driver);
			Config::set('mail.host',$config->mail_host);
			Config::set('mail.port',$config->mail_port);
			Config::set('mail.encryption',$config->mail_encryption);
			Config::set('mail.username',$config->mail_username);
			Config::set('mail.password',$config->mail_password);		
			$from = Config::get('mail.from');
			if ($emp->appraisal_type_id == 2) {

				try {
					$employee = Employee::where('emp_id',$emp->emp_id)->first();
					$chief_emp = Employee::where('emp_code',$employee->chief_emp_code)->first();

					$data = ["chief_emp_name" => $chief_emp->emp_name, "emp_name" => $employee->emp_name, "status" => $stage->status, "web_domain" => $config->web_domain,  'emp_result_id' => $emp->emp_result_id, 'appraisal_type_id' => $emp->appraisal_type_id, 'assignment_flag' => $stage->assignment_flag];
					$to = [$employee->email, $chief_emp->email];

					//$from = $config->mail_username;

					Mail::send('emails.status', $data, function($message) use ($from, $to)
					{
						$message->from($from['address'], $from['name']);
						$message->to($to)->subject('ระบบได้ทำการประเมิน');
					});
				} catch (Exception $e) {
					$mail_error = $e->getMessage();

				}
			}
		}

		//if ($request->stage_id == 22 || $request->stage_id == 27 || $request->stage_id == 29) {
		// if ($request->stage_id == 19 || $request->stage_id == 25 || $request->stage_id == 29) {
			// $items = DB::select("
				// select a.appraisal_item_result_id, ifnull(a.score,0) score, a.weight_percent
				// from appraisal_item_result a
				// left outer join emp_result b
				// on a.emp_result_id = b.emp_result_id
				// left outer join appraisal_item c
				// on a.appraisal_item_id = c.appraisal_item_id
				// left outer join appraisal_structure d
				// on c.structure_id = d.structure_id
				// where d.form_id = 2
				// and b.emp_result_id = ?
			// ", array($emp_result_id));

			// foreach ($items as $i) {
				// $uitem = AppraisalItemResult::find($i->appraisal_item_result_id);
				// $uitem->weigh_score = $i->score * $i->weight_percent;
				// $uitem->updated_by = Auth::id();
				// $uitem->save();
			// }
		// }

		return response()->json(['status' => 200, 'mail_error' => $mail_error]);
	}

	public function calculate_weight(Request $request)
	{
		$items = DB::select("
			select a.appraisal_item_result_id, ifnull(a.score,0) score, a.weight_percent
			from appraisal_item_result a
			left outer join emp_result b
			on a.emp_result_id = b.emp_result_id
			left outer join appraisal_item c
			on a.appraisal_item_id = c.appraisal_item_id
			left outer join appraisal_structure d
			on c.structure_id = d.structure_id
			where d.form_id = 2
			and b.appraisal_type_id = ?
			and a.period_id = ?
			and a.emp_code = ?
			and a.appraisal_item_id = ?
		", array($request->appraisal_type_id, $request->period_id, $request->emp_code, $request->appraisal_item_id));

		foreach ($items as $i) {
			$uitem = AppraisalItemResult::find($i->appraisal_item_result_id);
			$uitem->weigh_score = $i->score * $i->weight_percent;
			$uitem->updated_by = Auth::id();
			$uitem->save();
		}

		return response()->json(['status' => 200]);

	}

	public function phase_list(Request $request)
	{
		$items = DB::select("
			select phase_id, phase_name
			from phase
			where is_active = 1
			and item_result_id=?
			order by phase_id asc
		", array($request->item_result_id));

		return response()->json($items);
	}

	public function add_action(Request $request, $item_result_id)
	{
		try {
			$item_result = AppraisalItemResult::findOrFail($item_result_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Item Result not found.']);
		}

		$actions = $request->actions;

		if (empty($actions)) {
			return response()->json(['status' => 400, 'data' => "Require at least 1 Action"]);
		}

		$errors = array();
		$successes = array();

		foreach ($actions as $a) {

			$validator = Validator::make($a, [
				//'phase_id' => 'required|integer',
				'action_plan_name' => 'required|max:255',
				'plan_start_date' => 'date|date_format:Y-m-d',
				'plan_end_date' => 'date|date_format:Y-m-d',
				'actual_start_date' => 'date|date_format:Y-m-d',
				'actual_end_date' => 'date|date_format:Y-m-d',
				'completed_percent' => 'numeric'
			]);
			if ($validator->fails()) {
				$errors[] = ['action_plan_name' => $a['action_plan_name'], 'error' => $validator->errors()];
			} else {
				$item = new ActionPlan;
				$item->fill($a);
				$item->item_result_id = $item_result_id;
				$item->created_by = Auth::id();
				$item->updated_by = Auth::id();
				$item->save();
				$successes[] = ['action_plan_name' => $a['action_plan_name']];
			}
		}
		return response()->json(['status' => 200, 'data' => ["success" => $successes, "error" => $errors]]);

	}

	public function update_action(Request $request, $item_result_id)
	{
		$errors = array();
		$successes = array();

		$actions = $request->actions;


		if (empty($actions)) {
			return response()->json(['status' => 200, 'data' => ["success" => [], "error" => []]]);
		}

		foreach ($actions as $a) {
			$item = ActionPlan::find($a["action_plan_id"]);
			if (empty($item)) {
				$errors[] = ["action_plan_id" => $a["action_plan_id"]];
			} else {
				$validator = Validator::make($a, [
					'phase_id' => 'required|integer',
					'action_plan_name' => 'required|max:255',
					'plan_start_date' => 'date|date_format:Y-m-d',
					'plan_end_date' => 'date|date_format:Y-m-d',
					'actual_start_date' => 'date|date_format:Y-m-d',
					'actual_end_date' => 'date|date_format:Y-m-d',
					'completed_percent' => 'numeric'
				]);

				if ($validator->fails()) {
					$errors[] = ["action_plan_id" => $a["action_plan_id"], "error" => $validator->errors()];
				} else {
					$item->fill($a);
					$item->updated_by = Auth::id();
					$item->save();
					$sitem = ["action_plan_id" => $item->action_plan_id];
					$successes[] = $sitem;
				}

			}
		}

		return response()->json(['status' => 200, 'data' => ["success" => $successes, "error" => $errors]]);
	}

	public function show_action($item_result_id)
	{
		$header = DB::select("
			select a.item_result_id, a.threshold_group_id, b.item_name, c.emp_id, c.emp_code, c.emp_name, d.org_id, d.org_code, d.org_name, a.target_value, a.actual_value, a.forecast_value, e.appraisal_type_id,
			if(ifnull(a.forecast_value,0) = 0,0,(ifnull(a.actual_value,0)/a.forecast_value)*100) actual_vs_forecast,
			if(ifnull(a.target_value,0) = 0,0,(ifnull(a.actual_value,0)/a.target_value)*100) actual_vs_target
			from appraisal_item_result a
			left outer join appraisal_item b
			on a.item_id = b.item_id
			left outer join employee c
			on a.emp_id = c.emp_id
			left outer join org d
			on a.org_id = d.org_id
			left outer join emp_result e
			on a.emp_result_id = e.emp_result_id
			where item_result_id = ?

		",array($item_result_id));

		$threshold_color = DB::select("
			select color_code
			from threshold
			where threshold_group_id = ?
			order by target_score asc
		",array($header[0]->threshold_group_id));

		$result_threshold_color = DB::select("
			select begin_threshold, end_threshold, color_code
			from appraisal_item_result a
			left outer join emp_result b
			on a.emp_result_id = b.emp_result_id
			left outer join result_threshold c
			on b.result_threshold_group_id = c.result_threshold_group_id
			where a.item_result_id = ?
			order by begin_threshold desc
		", array($item_result_id));

		$header[0]->threshold_color = $threshold_color;
		$header[0]->result_threshold_color = $result_threshold_color;

		$actions = DB::select("
			select a.*, b.emp_name responsible, c.phase_name
			from action_plan a
			left outer join employee b
			on a.emp_id = b.emp_id
			left outer join phase c
			on a.phase_id = c.phase_id
			where a.item_result_id = ?
			order by a.action_plan_name asc
		", array($item_result_id));

		return response()->json(['header' => $header[0], 'actions' => $actions]);
	}

	public function delete_action(Request $request)
	{
		$errors = array();
		$successes = array();

		$actions = $request->actions;


		if (empty($actions)) {
			return response()->json(['status' => 200, 'data' => ["success" => [], "error" => []]]);
		}

		foreach ($actions as $a) {
			$item = ActionPlan::find($a["action_plan_id"]);
			if (empty($item)) {
				$errors[] = ["action_plan_id" => "Action Plan ID " . $a["action_plan_id"] . " not found."];
			} else {
				$item->delete();
				$successes[] = $a["action_plan_id"];
			}
		}

		return response()->json(['status' => 200, 'data' => ["success" => $successes, "error" => $errors]]);
	}

	public function add_reason(Request $request, $item_result_id)
	{
		try {
			$item_result = AppraisalItemResult::findOrFail($item_result_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Appraisal Item Result not found.']);
		}

		$validator = Validator::make($request->all(), [
			'reason_name' => 'required|max:255'
		]);

		if ($validator->fails()) {
			return response()->json(['status' => 400, 'data' => $validator->errors()]);
		} else {
			$item = new Reason;
			$item->reason_name = $request->reason_name;
			$item->item_result_id = $item_result_id;
			$item->created_by = Auth::id();
			$item->updated_by = Auth::id();
			$item->save();
		}

		return response()->json(['status' => 200, 'data' => $item]);

	}

	public function show_reason($item_result_id,$reason_id)
	{
		try {
			$item = Reason::findOrFail($reason_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Reason not found.']);
		}
		return response()->json($item);

	}

	public function list_reason($item_result_id)
	{
		$items = DB::select("
			SELECT @rownum := @rownum + 1 AS rank, a.reason_id, a.reason_name, a.created_dttm
			FROM reason a, (SELECT @rownum := 0) b
			where a.item_result_id = ?
			order by a.created_dttm asc
		", array($item_result_id));
		return response()->json($items);
	}

	public function update_reason(Request $request, $item_result_id)
	{
		try {
			$item = Reason::findOrFail($request->reason_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Reason not found.']);
		}

		$validator = Validator::make($request->all(), [
			'reason_name' => 'required|max:255'
		]);

		if ($validator->fails()) {
			return response()->json(['status' => 400, 'data' => $validator->errors()]);
		} else {
			$item->reason_name = $request->reason_name;
			$item->updated_by = Auth::id();
			$item->save();
		}

		return response()->json(['status' => 200, 'data' => $item]);

	}

	public function delete_reason(Request $request, $item_result_id)
	{
		try {
			$item = Reason::findOrFail($request->reason_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Reason not found.']);
		}

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Reason is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);

	}

	public function auto_action_employee_name(Request $request)
	{
		$qinput = array();
		$query = "
			Select emp_id, emp_code, emp_name
			From employee
			Where emp_name like ?
		";

		$qfooter = " Order by emp_name limit 10 ";
		$qinput[] = '%'.$request->emp_name.'%';


		$items = DB::select($query.$qfooter,$qinput);

		return response()->json($items);

	}


	public function appraisal_upload_files(Request $request,$item_result_id )
	{



		$result = array();

			$path = $_SERVER['DOCUMENT_ROOT'] . '/fmo_api/public/attach_files/' . $item_result_id . '/';
			foreach ($request->file() as $f) {
				$filename = iconv('UTF-8','windows-874',$f->getClientOriginalName());
				$f->move($path,$filename);
				//$f->move($path,$f->getClientOriginalName());
				//echo $filename;

				$item = AttachFile::firstOrNew(array('doc_path' => 'attach_files/' . $item_result_id . '/' . $f->getClientOriginalName()));

				$item->item_result_id = $item_result_id;
				$item->created_by = Auth::id();

				//print_r($item);
				$item->save();
				$result[] = $item;
				//echo "hello".$f->getClientOriginalName();

			}

		return response()->json(['status' => 200, 'data' => $result]);
	}

	public function upload_files_list(Request $request)
	{
		$items = DB::select("
			SELECT result_doc_id,doc_path
			FROM appraisal_item_result_doc
			where  item_result_id=?
			order by result_doc_id;
		", array($request->item_result_id));

		return response()->json($items);
	}


	public function delete_file(Request $request){

		try {
			$item = AttachFile::findOrFail($request->result_doc_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'File not found.']);
		}
		           //$_SERVER['DOCUMENT_ROOT'] . '/see_api/public/attach_files/' . $item_result_id . '/';
		//File::Delete($_SERVER['DOCUMENT_ROOT'] . '/fmo_api/public/'.$item->doc_path);
		
		$filename = iconv('UTF-8','windows-874',$item->doc_path);
		File::Delete($_SERVER['DOCUMENT_ROOT'] . '/fmo_api/public/'.$filename);	
		
		$item->delete();

		return response()->json(['status' => 200]);

	}



	/**
   * Get is all employee by employee code.
   *
   * @author P.Wirun (GJ)
   * @param  Employee Code
   * @return Info
   */
  public function is_all_employee($empCode){
		// Get user level
    $userlevelId = 0; $userlevelAllEmp = 0; $userParentId = 0;
    $userlevelDb = DB::select("
      SELECT org.level_id, al.appraisal_level_name, al.is_all_employee, al.parent_id
      FROM employee emp
      INNER JOIN org ON org.org_id = emp.org_id
      INNER JOIN appraisal_level al ON al.level_id = org.level_id
      WHERE emp_code = '{$empCode}'
      AND al.is_org = 1
      AND al.is_active = 1
      AND emp.is_active = 1
      AND org.is_active = 1
      LIMIT 1");

		if ($userlevelDb[0]->is_all_employee == '1' || $userlevelDb[0]->parent_id == 0) {
			return [
				"is_all" => true,
				"level_id" => $userlevelDb[0]->level_id,
				"is_all_employee" => $userlevelDb[0]->is_all_employee,
				"parent_id" => $userlevelDb[0]->parent_id];
		} else {
			return [
				"is_all" => false,
				"level_id" => $userlevelDb[0]->level_id,
				"is_all_employee" => $userlevelDb[0]->is_all_employee,
				"parent_id" => $userlevelDb[0]->parent_id];
		}
	}



	/**
   * Get Level list filter by Org for Organization Type.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request
   * @return \Illuminate\Http\Response
   */
  public function org_level_list(Request $request){
    $empLevInfo = $this->is_all_employee(Auth::id());

		if ($empLevInfo["is_all"]) {
      $result = DB::select("
        SELECT level_id, appraisal_level_name
        FROM appraisal_level
        WHERE is_active = 1
		and is_org = 1");
    } else {
      $result = DB::select("
      SELECT level_id, appraisal_level_name
      FROM appraisal_level
      WHERE is_active = 1
	  and is_org = 1
      AND level_id = {$empLevInfo["level_id"]}
      OR level_id in(
      	SELECT
      		@id := (
      			SELECT level_id
      			FROM appraisal_level
      			WHERE parent_id = @id
      		) AS level_id
      	FROM(
      		SELECT @id := {$empLevInfo["level_id"]}
      	) cur_id
      	STRAIGHT_JOIN appraisal_level
      	WHERE @id IS NOT NULL
      )");
    }

		return response()->json($result);
	}



	/**
   * Get Level list filter by Employee for Individual Type.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request
   * @return \Illuminate\Http\Response
   */
  public function emp_level_list(Request $request){
		$empCode = Auth::id();
		$empLevInfo = $this->is_all_employee($empCode);

		if ($empLevInfo["is_all"]) {
			$result = DB::select("
        		SELECT level_id, appraisal_level_name
				FROM appraisal_level
				WHERE is_active = 1
				AND is_individual = 1
				ORDER BY level_id DESC
			");
		} else {
			$result = DB::select("
				SELECT lev.level_id, lev.appraisal_level_name
				FROM employee emp
				INNER JOIN appraisal_level lev ON lev.level_id = emp.level_id
				WHERE emp.is_active = 1
				AND lev.is_active = 1
				AND lev.is_individual = 1
				AND (
					emp.emp_code = '{$empCode}'
					OR emp.chief_emp_code = '{$empCode}'
					OR emp.dotline_code = '{$empCode}'
					OR emp.emp_code IN(
						SELECT de.emp_code
						FROM employee de
						INNER JOIN employee ce ON ce.emp_code = de.chief_emp_code
						WHERE de.has_second_line = 1
						AND de.is_active = 1
						AND ce.is_active = 1
						AND ce.chief_emp_code = '{$empCode}'
					)
				)
				GROUP BY lev.level_id
				ORDER BY lev.level_id DESC
			");
		}

		return response()->json($result);
	}



	/**
   * Get employee list for auto complete search.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request (level_id, emp_name)
   * @return \Illuminate\Http\Response
   */
  public function auto_emp_list(Request $request){
		$empCode = Auth::id();
		$empLevInfo = $this->is_all_employee($empCode);
		
		empty($request->org_id_multi) ? $org_multi = "" : $org_multi = " and a.org_id in (" . $request->org_id_multi . ") ";
		empty($request->org_id) ? $org = " " : $org = " and a.org_id = " . $request->org_id . " ";
		$levelStr = (empty($request->level_id)) ? " " : " AND a.level_id = {$request->level_id} " ;
		
		if ($empLevInfo["is_all"]) {
			
			$result = DB::select("
				SELECT distinct emp.emp_id, emp.emp_code, emp.emp_name
				FROM employee emp inner join appraisal_item_result a
				on a.emp_id = emp.emp_id
				WHERE emp.is_active = 1
				" . $org_multi . $org . $levelStr ."
				AND emp.emp_name like '%{$request->emp_name}%' ");
		} else {
			$result = DB::select("
				SELECT distinct emp.emp_id, emp.emp_code, emp.emp_name
				FROM employee emp inner join appraisal_item_result a
				on a.emp_id = emp.emp_id
				WHERE emp.is_active = 1
				" . $org . $org_multi . "
				AND (
					emp.emp_code = '{$empCode}'
					OR emp.chief_emp_code = '{$empCode}'
					OR emp.dotline_code = '{$empCode}'
					OR emp.emp_code IN(
						SELECT de.emp_code
						FROM employee de
						INNER JOIN employee ce ON ce.emp_code = de.chief_emp_code
						WHERE de.has_second_line = 1
						AND de.is_active = 1
						AND ce.is_active = 1
						AND ce.chief_emp_code = '{$empCode}'
					)
				)
				".$levelStr."
				AND emp.emp_name LIKE '%{$request->emp_name}%' ");
		}

		return response()->json($result);
	}



	/**
   * Get position list for auto complete search.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request (level_id, position_name)
   * @return \Illuminate\Http\Response
   */
  public function auto_position_list(Request $request){
		$empCode = Auth::id();
		$empLevInfo = $this->is_all_employee($empCode);
		
		empty($request->org_id) ? $org = "" : $org = " and org_id = " . $request->org_id . " ";
		empty($request->org_id_multi) ? $org_multi = "" : $org_multi = " and org_id in (" . $request->org_id_multi . ") ";
		
		if ($empLevInfo["is_all"]) {
			$result = DB::select("
				SELECT distinct p.position_id, p.position_code, p.position_name
				FROM position p left outer join employee e
				on p.position_id = e.position_id
				WHERE p.is_active = 1
				" . $org . "
				" . $org_multi . "
				AND p.position_name LIKE '%{$request->position_name}%' 
				AND e.emp_name LIKE '%{$request->emp_name}%' ");
		} else {
			$levelStr = (empty($request->level_id)) ? " " : "AND emp.level_id = {$request->level_id}" ;
			$result = DB::select("
				SELECT distinct emp.position_id, pos.position_code, pos.position_name
				FROM employee emp
				INNER JOIN position pos ON pos.position_id = emp.position_id
				WHERE emp.is_active = 1
				" . $org . "
				" . $org_multi . "
				AND (
					emp.emp_code = '{$empCode}'
					OR emp.chief_emp_code = '{$empCode}'
					OR emp.dotline_code = '{$empCode}'
					OR emp.emp_code IN(
						SELECT de.emp_code
						FROM employee de
						INNER JOIN employee ce ON ce.emp_code = de.chief_emp_code
						WHERE de.has_second_line = 1
						AND de.is_active = 1
						AND ce.is_active = 1
						AND ce.chief_emp_code = '{$empCode}'
					)
				)
				".$levelStr."
				AND pos.position_name LIKE '%{$request->position_name}%' 
				AND emp.emp_name LIKE '%{$request->emp_name}%' ");
		}

		return response()->json($result);
	}



	/**
   * Get Level list filter by Org for Individual Type.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request (level_id)
   * @return \Illuminate\Http\Response
   */
  public function org_level_list_individual(Request $request){
    $empCode = Auth::id();
		$empLevInfo = $this->is_all_employee($empCode);
		$levelStr = (empty($request->level_id)) ? " " : "AND emp.level_id = {$request->level_id}" ;
		$periodStr = (empty($request->period_id)) ? " " : "AND emp.period_id = {$request->period_id}" ;
		if ($empLevInfo["is_all"]) {
	      $result = DB::select("
	        SELECT distinct org.level_id, lev.appraisal_level_name
					FROM appraisal_item_result emp
					INNER JOIN org ON org.org_id = emp.org_id
					INNER JOIN appraisal_level lev ON lev.level_id = org.level_id
					WHERE 1 = 1
					".$levelStr."
					".$periodStr."
					AND lev.is_org = 1
					ORDER BY org.level_id ASC");
	    } else {
	      $result = DB::select("
		      SELECT distinct org.level_id, lev.appraisal_level_name
					FROM appraisal_item_result emp
					INNER JOIN org ON org.org_id = emp.org_id
					INNER JOIN appraisal_level lev ON lev.level_id = org.level_id
					left outer join employee e on emp.emp_id = e.emp_id
					left outer join employee c on emp.chief_emp_id = c.emp_id
					left outer join employee d on emp.dotline_id = d.emp_id
					WHERE 1 = 1
					AND (
						e.emp_code = '{$empCode}'
						OR c.emp_code = '{$empCode}'
						OR d.emp_code = '{$empCode}'
						OR e.emp_code IN(
							SELECT de.emp_code
							FROM employee de
							INNER JOIN employee ce ON ce.emp_code = de.chief_emp_code
							WHERE de.has_second_line = 1
							AND de.is_active = 1
							AND ce.is_active = 1
							AND ce.chief_emp_code = '{$empCode}'
						)
					)
					".$levelStr."
					".$periodStr."
					AND lev.is_org = 1
					ORDER BY org.level_id ASC");
	    }

		return response()->json($result);
	}

	//add by toto
	public function org_level_by_empname(Request $request) {
		$emp_id = (empty($request->emp_id)) ? " " : "WHERE emp.emp_id = {$request->emp_id}";
		$period_id = (empty($request->period_id)) ? " " : "AND emp.period_id = {$request->period_id}";
		$items = DB::select("
	        SELECT distinct lev.level_id, lev.appraisal_level_name
	        FROM appraisal_item_result emp
			INNER JOIN org ON org.org_id = emp.org_id
			INNER JOIN appraisal_level lev ON lev.level_id = org.level_id
			".$emp_id."
			".$period_id."
		");
		return response()->json($items);
	}

	//add by toto
	public function organization_by_empname(Request $request) {
		$emp_id = (empty($request->emp_id)) ? " " : "WHERE emp.emp_id = {$request->emp_id}";
		$org_level = (empty($request->org_level)) ? " " : "AND org.level_id = {$request->org_level}";
		$period_id = (empty($request->period_id)) ? " " : "AND emp.period_id = {$request->period_id}";
		
		$items = DB::select("
	        SELECT distinct org.org_id, org.org_name
	        FROM appraisal_item_result emp
			INNER JOIN org ON org.org_id = emp.org_id
			".$emp_id."
			".$org_level."
			".$period_id."
		");
		return response()->json($items);
	}



	/**
   * Get org list for Individual Type.
   *
   * @author P.Wirun (GJ)
   * @param  \Illuminate\Http\Request (emp_level, org_level)
   * @return \Illuminate\Http\Response
   */
  public function org_individual(Request $request){
    // $empCode = Auth::id();
		// $empLevInfo = $this->is_all_employee($empCode);

		// // if ($empLevInfo["is_all"]) {
  // //     $result = DB::select("
  // //       SELECT org.org_id, org.org_name
		// // 		FROM org
		// // 		WHERE org.is_active = 1");
  // //   } else {
			// $orgLevelStr = (empty($request->org_level)) ? " " : "AND org.level_id = {$request->org_level} " ;
			// $empLevelStr = (empty($request->emp_level)) ? " " : "AND emp.level_id = {$request->emp_level} " ;
			// $empIdStr = (empty($request->emp_id)) ? " " : "AND emp.emp_id = {$request->emp_id} " ;
			// $periodStr = (empty($request->period_id)) ? " " : "AND emp.period_id = {$request->period_id} " ;
      // $result = DB::select("
				// SELECT distinct org.org_id, org.org_name
				// FROM org
				// INNER JOIN appraisal_item_result emp ON emp.org_id = org.org_id
				// WHERE org.is_active = 1
				// ".$orgLevelStr."
				// ".$empLevelStr."
				// ".$empIdStr."
				// ".$periodStr."
				// GROUP BY org.org_id ");
    // //}
	
		$empCode = Auth::id();
		$empLevInfo = $this->is_all_employee($empCode);
			$orgLevelStr = (empty($request->org_level)) ? " " : "AND org.level_id = {$request->org_level} " ;
			$empLevelStr = (empty($request->emp_level)) ? " " : "AND emp.level_id = {$request->emp_level} " ;
			$empIdStr = (empty($request->emp_id)) ? " " : "AND emp.emp_id = {$request->emp_id} " ;
			$periodStr = (empty($request->period_id)) ? " " : "AND emp.period_id = {$request->period_id} " ;
		if ($empLevInfo["is_all"]) {
	      $result = DB::select("
	        SELECT distinct emp.org_id, org.org_name
					FROM appraisal_item_result emp
					INNER JOIN org ON org.org_id = emp.org_id
					INNER JOIN appraisal_level lev ON lev.level_id = org.level_id
					WHERE 1 = 1
					".$orgLevelStr."
					".$empLevelStr."
					".$empIdStr."
					".$periodStr."
					AND lev.is_org = 1
					ORDER BY org.level_id ASC, org.org_code ASC");
	    } else {
	      $result = DB::select("
		      SELECT distinct emp.org_id, org.org_name
					FROM appraisal_item_result emp
					INNER JOIN org ON org.org_id = emp.org_id
					INNER JOIN appraisal_level lev ON lev.level_id = org.level_id
					left outer join employee e on emp.emp_id = e.emp_id
					left outer join employee c on emp.chief_emp_id = c.emp_id
					left outer join employee d on emp.dotline_id = d.emp_id
					WHERE 1 = 1
					AND (
						e.emp_code = '{$empCode}'
						OR c.emp_code = '{$empCode}'
						OR d.emp_code = '{$empCode}'
						OR e.emp_code IN(
							SELECT de.emp_code
							FROM employee de
							INNER JOIN employee ce ON ce.emp_code = de.chief_emp_code
							WHERE de.has_second_line = 1
							AND de.is_active = 1
							AND ce.is_active = 1
							AND ce.chief_emp_code = '{$empCode}'
						)
					)
					".$orgLevelStr."
					".$empLevelStr."
					".$empIdStr."
					".$periodStr."
					AND lev.is_org = 1
					ORDER BY org.level_id ASC, org.org_code ASC");
	    }	

		return response()->json($result);
	}

}
