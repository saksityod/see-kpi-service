<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Bonus\AdvanceSearchController;

use App\AppraisalItemResult;
use App\AppraisalItemResultLog;
use App\AppraisalFrequency;
use App\AppraisalPeriod;
use App\AppraisalStage;
use App\EmpResult;
use App\EmpResultStage;
use App\WorkflowStage;
use App\Employee;
use App\ResultThresholdGroup;
use App\ThresholdGroup;
use App\Org;
use App\SystemConfiguration;
use App\Phase;
use App\ActionPlan;
use App\AppraisalStructure;
use App\AppraisalCriteria;

use Auth;
use DB;
use File;
use Validator;
use Excel;
use Mail;
use Config;
use Exception;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AppraisalAssignmentController extends Controller
{
	public function __construct()
	{
		$this->middleware('jwt.auth');
		$this->advanSearch = new AdvanceSearchController;
	}

	public function email_link_assignment(Request $request) {
		$items = DB::select("
			select air.org_id, air.level_id as level_id_emp, o.level_id as level_id
			from appraisal_item_result air
			left join org o
			on o.org_id = air.org_id
			where air.emp_result_id = {$request->emp_result_id}
			limit 0,1
			");
		return response()->json($items);
	}

	public function appraisal_type_list()
	{
		$items = DB::select("
			Select appraisal_type_id, appraisal_type_name
			From appraisal_type
			Order by appraisal_type_id DESC
			");
		return response()->json($items);
	}


	public function new_assign_to(Request $request)
	{
		$items = DB::select("
			SELECT a.stage_id, a.to_appraisal_level_id, b.appraisal_level_name
			FROM workflow_stage a
			left outer join appraisal_level b
			on a.to_appraisal_level_id = b.appraisal_level_id
			where a.stage_id = 1
			union
			SELECT a.stage_id, a.to_appraisal_level_id, b.appraisal_level_name
			FROM workflow_stage a
			left outer join appraisal_level b
			on a.to_appraisal_level_id = b.appraisal_level_id
			where a.from_stage_id = 1
			and a.to_appraisal_level_id = (
			select parent_id
			from appraisal_level
			where appraisal_level_id = ?
			)
			", array($request->appraisal_level_id));

		return response()->json($items);
	}

	public function new_action_to(Request $request)
	{
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

		if (empty($request->org_code)) {
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

			$org = Org::find($request->org_code);

			if (empty($org)) {
				$parent_org_code = null;
			} else {
				$parent_org_code = $org->parent_org_code;
				if ($parent_org_code == $co->org_code) {
					$first_see = 1;
				} else {
					$first_see = null;
				}

				// if ($employee->has_second_line == 1) {
					// $has_second = 1;
					// $check_second = DB::select("
						// select chief_emp_code
						// from employee
						// where emp_code = ?
					// ", array($chief_emp_code));
					// if (empty($check_second)) {
						// $second_see = null;
					// } else {
						// if ($check_second[0]->chief_emp_code == Auth::id()) {
							// $second_see = 1;
						// } else {
							// $second_see = null;
						// }
					// }
				// } else {
					// $second_see = null;
					// $has_second = 0;
				// }
			}
		}

		$items = DB::select("
			select stage_id, to_action
			from appraisal_stage
			where stage_id in (1)
			and assignment_flag = 1
			and (
			hr_see = ?
			or self_see = ?
			or first_see = ?
			or second_see = ?
			)
			",array($hr_see,$self_see,$first_see,$second_see));

		// $items = DB::select("
			// select stage_id, to_action
			// from appraisal_stage
			// where appraisal_type_id=?
			// order by stage_id
			// limit 1
		// ",array($request->appraisal_type_id));
		return response()->json($items);
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
			and stage_id < 17
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
				and stage_id < 17
				", array($al_id));
		}

		return response()->json($items);
	}

	public function edit_action_to(Request $request)
	{
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
			// and assignment_flag = 1
		// " . $hr_query, array($request->stage_id));

		// if (empty($items)) {
			// $workflow = WorkflowStage::find($request->stage_id);
			// empty($workflow->to_stage_id) ? $to_stage_id = "null" : $to_stage_id = $workflow->to_stage_id;
			// $items = DB::select("
				// select stage_id, to_action
				// from appraisal_stage a
				// where stage_id in ({$to_stage_id})
				// and assignment_flag = 1
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

				// if ($employee->has_second_line == 1) {
					// $has_second = 1;
					// $check_second = DB::select("
						// select chief_emp_code
						// from employee
						// where emp_code = ?
					// ", array($chief_emp_code));
					// if (empty($check_second)) {
						// $second_see = null;
					// } else {
						// if ($check_second[0]->chief_emp_code == Auth::id()) {
							// $second_see = 1;
						// } else {
							// $second_see = null;
						// }
					// }
				// } else {
					// $second_see = null;
					// $has_second = 0;
				// }
			}
		}
		if ($has_second == 1) {
			$items = DB::select("
				select stage_id, to_action
				from appraisal_stage
				where from_stage_id = ?
				and appraisal_type_id = ?
				and assignment_flag = 1
				and (
				hr_see = ?
				or self_see = ?
				or first_see = ?
				or second_see = ?
				)
				", array($request->stage_id, $request->appraisal_type_id, $hr_see,$self_see,$first_see,$second_see));

			if (empty($items)) {
				$workflow = WorkflowStage::find($request->stage_id);
				empty($workflow->to_stage_id) ? $to_stage_id = "null" : $to_stage_id = $workflow->to_stage_id;
				$items = DB::select("
					select stage_id, to_action
					from appraisal_stage a
					where stage_id in ({$to_stage_id})
					and assignment_flag = 1
					and a.appraisal_type_id = ?
					and (
					hr_see = ?
					or self_see = ?
					or first_see = ?
					or second_see = ?
					)
					",array($request->appraisal_type_id, $hr_see,$self_see,$first_see,$second_see));
			}
		} else {
			$workflow = WorkflowStage::find($request->stage_id);
			if ($workflow['no_second_line_stage_id'] == 0) {
				$items = DB::select("
					select stage_id, to_action
					from appraisal_stage
					where from_stage_id = ?
					and appraisal_type_id = ?
					and assignment_flag = 1
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
						and a.appraisal_type_id = ?
						and assignment_flag = 1
						and (
						hr_see = ?
						or self_see = ?
						or first_see = ?
						or second_see = ?
						)
						",array($request->appraisal_type_id, $hr_see,$self_see,$first_see,$second_see));
				}
			} else {
				empty($workflow->no_second_line_stage_id) ? $to_stage_id = "null" : $to_stage_id = $workflow->no_second_line_stage_id;
				$items = DB::select("
					select stage_id, to_action
					from appraisal_stage a
					where stage_id in ({$to_stage_id})
					and assignment_flag = 1
					and a.appraisal_type_id = ?
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

	public function auto_position_name2(Request $request)
	{
		$emp = Employee::find(Auth::id());
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
			", array(Auth::id()));

		empty($request->org_id) ? $org = "" : $org = " and a.org_id = " . $request->org_id . " ";

		if ($all_emp[0]->count_no > 0) {
			$items = DB::select("
				Select distinct b.position_id, b.position_name
				From employee a left outer join position b
				on a.position_id = b.position_id
				Where position_name like ?
				and emp_name like ?
				and a.is_active = 1
				and b.is_active = 1
				" . $org . "
				Order by position_name
				limit 10
				",array('%'.$request->position_name.'%','%'.$request->emp_name.'%'));
		} else {
			$items = DB::select("
				Select distinct b.position_id, b.position_name
				From employee a left outer join position b
				on a.position_id = b.position_id
				Where (a.chief_emp_code = ? or a.emp_code = ?)
				and position_name like ?
				and emp_name like ?
				and a.is_active = 1
				" . $org . "
				and b.is_active = 1
				Order by position_name
				limit 10
				", array($emp->emp_code, $emp->emp_code,'%'.$request->position_name.'%','%'.$request->emp_name.'%'));
		}
		return response()->json($items);
	}

	public function auto_position_name2_bak(Request $request)
	{
		$items = DB::select("
			Select distinct b.position_id, b.position_name
			From employee a left outer join position b
			on a.position_id = b.position_id
			Where a.emp_code = ?
			and a.is_active = 1
			and b.is_active = 1
			",array($request->emp_code));
		return response()->json($items);
	}

    // public function al_list()
    // {
		// $all_emp = DB::select("
			// SELECT count(is_all_employee) count_no
			// FROM emp_level a
			// left outer join appraisal_level b
			// on a.appraisal_level_id = b.appraisal_level_id
			// where emp_code = ?
			// and is_all_employee = 1
		// ", array(Auth::id()));

		// if ($all_emp[0]->count_no > 0) {
			// $items = DB::select("
				// Select appraisal_level_id, appraisal_level_name
				// From appraisal_level
				// Where is_active = 1
				// Order by appraisal_level_name
			// ");
		// } else {
				// // select al.appraisal_level_id, al.appraisal_level_name
				// // from emp_level el, appraisal_level al
				// // where el.appraisal_level_id = al.appraisal_level_id
				// // and el.emp_code = 1
				// // union
			// $items = DB::select("
				// select distinct el.appraisal_level_id, al.appraisal_level_name
				// from employee e, emp_level el, appraisal_level al
				// where e.emp_code = el.emp_code
				// and el.appraisal_level_id = al.appraisal_level_id
				// and e.chief_emp_code = ?
				// and e.is_active = 1
			// ", array(Auth::id()));

			// $chief_list = array();

			// $chief_items = DB::select("
				// select distinct e.emp_code
				// from employee e, emp_level el, appraisal_level al
				// where e.emp_code = el.emp_code
				// and el.appraisal_level_id = al.appraisal_level_id
				// and e.chief_emp_code = ?
				// and e.is_active = 1
			// ", array(Auth::id()));

			// foreach ($chief_items as $i) {
				// $chief_list[] = $i->emp_code;
			// }

			// $chief_list = array_unique($chief_list);

			// // Get array keys
			// $arrayKeys = array_keys($chief_list);
			// // Fetch last array key
			// $lastArrayKey = array_pop($arrayKeys);
			// //iterate array
			// $in_chief = '';
			// foreach($chief_list as $k => $v) {
				// if($k == $lastArrayKey) {
					// //during array iteration this condition states the last element.
					// $in_chief .= $v;
				// } else {
					// $in_chief .= $v . ',';
				// }
			// }


			// do {
				// empty($in_chief) ? $in_chief = "null" : null;
				// $ritems = DB::select("
					// select distinct el.appraisal_level_id, al.appraisal_level_name
					// from employee e, emp_level el, appraisal_level al
					// where e.emp_code = el.emp_code
					// and el.appraisal_level_id = al.appraisal_level_id
					// and e.is_active = 1
					// and e.chief_emp_code in ({$in_chief})
				// ");

				// $chief_list = array();

				// foreach ($ritems as $r) {
					// $items[] = $r;
				// }

				// $chief_items = DB::select("
					// select distinct e.emp_code
					// from employee e, emp_level el, appraisal_level al
					// where e.emp_code = el.emp_code
					// and el.appraisal_level_id = al.appraisal_level_id
					// and e.chief_emp_code in ({$in_chief})
					// and e.is_active = 1
				// ");

				// foreach ($chief_items as $i) {
					// $chief_list[] = $i->emp_code;
				// }

				// $chief_list = array_unique($chief_list);

				// // Get array keys
				// $arrayKeys = array_keys($chief_list);
				// // Fetch last array key
				// $lastArrayKey = array_pop($arrayKeys);
				// //iterate array
				// $in_chief = '';
				// foreach($chief_list as $k => $v) {
					// if($k == $lastArrayKey) {
						// //during array iteration this condition states the last element.
						// $in_chief .= $v;
					// } else {
						// $in_chief .= $v . ',';
					// }
				// }
			// } while (!empty($chief_list));

		// }

		// $items = array_unique($items,SORT_REGULAR);

		// return response()->json($items);
    // }

	public function al_list()
	{
		$emp = Employee::find(Auth::id());
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
				Order by level_id
				");
		} else {
			$items = DB::select("
				select distinct al.level_id, al.appraisal_level_name
				from employee e, appraisal_level al
				where e.level_id = al.level_id
				and e.chief_emp_code = ?
				and e.is_active = 1
				and al.is_hr = 0
				Order by level_id
				", array($emp->emp_code));
		}

		return response()->json($items);
	}

	public function al_list_org_individual(Request $request)
	{
		$items = DB::select("
			select l.level_id, l.appraisal_level_name
			from org o
			inner join employee e
			on e.org_id = o.org_id
			inner join appraisal_level l
			on o.level_id = l.level_id
			where e.emp_code = ?
			and l.is_org = 1
			and l.is_active = 1
			and e.is_active = 1
			", array($request->emp_code));
		return response()->json($items);
	}

	    /**
	   * Get Level list filter by Org for Organization Type.
	   *
	   * @author P.Wirun (GJ)
	   * @param  \Illuminate\Http\Request   $request( emp_code )
	   * @return \Illuminate\Http\Response
	   */
	    public function al_list_org(Request $request) {

	    	$all_emp = DB::select("
	    		SELECT sum(b.is_all_employee) count_no
	    		from employee a
	    		left outer join appraisal_level b
	    		on a.level_id = b.level_id
	    		where emp_code = ?
	    		", array(Auth::id()));

	    	if ($all_emp[0]->count_no > 0) {
	    		$result = DB::select("
	    			Select level_id, appraisal_level_name
	    			From appraisal_level
	    			Where is_active = 1
	    			and is_org = 1
	    			Order by level_id
	    			");
	    	} else {

		    // Get user level
	    		$userlevelId = null; $userlevelAllEmp =null; $userParentId = null;
	    		$userlevelDb = DB::select("
	    			SELECT org.level_id, al.appraisal_level_name, al.is_all_employee, al.parent_id
	    			FROM employee emp
	    			INNER JOIN org ON org.org_id = emp.org_id
	    			INNER JOIN appraisal_level al ON al.level_id = org.level_id
	    			WHERE emp_code = '{$request->emp_code}'
	    			AND al.is_org = 1
	    			AND al.is_active = 1
	    			AND emp.is_active = 1
	    			AND org.is_active = 1
	    			LIMIT 1");
	    		if (!empty($userlevelDb)) {
	    			foreach ($userlevelDb as $value) {
	    				$userlevelId = $value->level_id;
	    				$userlevelAllEmp = $value->is_all_employee;
	    				$userParentId = $value->parent_id;
	    			}
	    		} else {
	    			return response()->json([]);
	    		}

	    		$resultQryStr = "";
	    		if ($userlevelAllEmp == '1' || $userParentId == '0') {
	    			$result = DB::select("
	    				SELECT level_id, appraisal_level_name
	    				FROM appraisal_level
	    				WHERE is_active = 1");
	    		} else {
	    			$result = DB::select("
	    				SELECT level_id, appraisal_level_name
	    				FROM appraisal_level
	    				WHERE is_active = 1
	    				AND level_id = {$userlevelId}
	    				OR level_id in(
	    				SELECT
	    				@id := (
	    				SELECT level_id
	    				FROM appraisal_level
	    				WHERE parent_id = @id
	    				) AS level_id
	    				FROM(
	    				SELECT @id := {$userlevelId}
	    				) cur_id
	    				STRAIGHT_JOIN appraisal_level
	    				WHERE @id IS NOT NULL
	    			)");
	    		}
	    	}

	    	return response()->json($result);
	    }

	    public function al_list_emp(Request $request)
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
	    			and is_individual = 1
	    			Order by level_id desc
	    			");
	    	} else {
	    		$items = DB::select("
	    			select l.level_id, l.appraisal_level_name
	    			from appraisal_level l
	    			inner join employee e
	    			on e.level_id = l.level_id
	    			where (e.chief_emp_code = ? or e.emp_code = ?)
	    			and is_individual = 1
	    			and l.is_active = 1
	    			group by l.level_id desc
	    			", array(Auth::id(), Auth::id()));
	    	}

	    	return response()->json($items);
	    }

	    public function al_list_emp_org(Request $request)
	    {
	    	$all_emp = DB::select("
	    		SELECT sum(b.is_all_employee) count_no
	    		from employee a
	    		left outer join appraisal_level b
	    		on a.level_id = b.level_id
	    		where emp_code = ?
	    		", array(Auth::id()));

	    	if ($all_emp[0]->count_no > 0 && empty($request->level_id) ) {
	    		$items = DB::select("
	    			Select level_id, appraisal_level_name
	    			From appraisal_level
	    			Where is_active = 1
	    			Order by level_id
	    			");
	    	} else if($all_emp[0]->count_no > 0 && !empty($request->level_id)){
	    		$items = DB::select("
	    			select l.level_id, l.appraisal_level_name
	    			from appraisal_level l
	    			inner join org o
	    			on l.level_id = o.level_id
	    			inner join employee e
	    			on o.org_id = e.org_id
	    			where e.level_id = ?
	    			and l.is_org = 1
	    			and l.is_active = 1
	    			and o.is_active = 1
	    			and e.is_active = 1
	    			group by l.level_id
	    			", array($request->level_id));
	    	} else {
	    		$items = DB::select("
	    			select l.level_id, l.appraisal_level_name
	    			from appraisal_level l
	    			inner join org o
	    			on l.level_id = o.level_id
	    			inner join employee e
	    			on o.org_id = e.org_id
	    			where (e.chief_emp_code = '".Auth::id()."' or e.emp_code = '".Auth::id()."')
	    			and e.level_id = ?
	    			and l.is_org = 1
	    			and l.is_active = 1
	    			and o.is_active = 1
	    			and e.is_active = 1
	    			group by l.level_id
	    			", array($request->level_id));
	    	}

	    	return response()->json($items);
	    }

	    public function al_list_emp_name(Request $request)
	    {
	    	$items = DB::select("
	    		select emp_code, emp_name
	    		from employee
	    		where (chief_emp_code = ? or emp_code = ?)
	    		and level_id = ?
	    		and is_active = 1
	    		", array($request->emp_code, $request->emp_code, $request->level_id));
	    	return response()->json($items);
	    }

	    public function al_list_emp_position(Request $request)
	    {
	    	$items = DB::select("
	    		select p.position_code, p.position_name
	    		from position p
	    		inner join employee e
	    		on p.position_id = e.position_id
	    		where e.emp_code = ?
	    		and e.is_active = 1
	    		and p.is_active = 1
	    		", array($request->emp_code));
	    	return response()->json($items);
	    }

	    public function frequency_list()
	    {
	    	$items = DB::select("
	    		Select frequency_id, frequency_name, frequency_month_value
	    		From  appraisal_frequency
	    		Order by frequency_month_value asc
	    		");
	    	return response()->json($items);
	    }

	    public function period_list (Request $request)
	    {
		// if ($request->assignment_frequency == 1) {
			// $items = DB::select("
				// select period_id, appraisal_period_desc
				// From appraisal_period
				// Where appraisal_year = (select current_appraisal_year from system_config)
				// order by appraisal_period_desc
			// ");
		// } else {
			// $items = DB::select("
				// select period_id, appraisal_period_desc
				// From appraisal_period
				// Where appraisal_year = (select current_appraisal_year from system_config)
				// And appraisal_frequency_id = ?
				// order by appraisal_period_desc
			// ", array($request->frequency_id));
		// }
	    	$items = DB::select("
	    		select period_id, appraisal_period_desc
	    		From appraisal_period
	    		Where appraisal_year = ?
	    		And appraisal_frequency_id = ?
	    		order by start_date asc
	    		", array($request->appraisal_year, $request->frequency_id));
	    	return response()->json($items);
	    }

	    public function auto_employee_name2(Request $request)
	    {
	    	$emp = Employee::find(Auth::id());
	    	$all_emp = DB::select("
	    		SELECT sum(b.is_all_employee) count_no
	    		from employee a
	    		left outer join appraisal_level b
	    		on a.level_id = b.level_id
	    		where emp_code = ?
	    		", array(Auth::id()));

	    	empty($request->org_id) ? $org = "" : $org = " and org_id = " . $request->org_id . " ";
	    	empty($request->level_id) ? $level_id = "" : $level_id = " and level_id = " . $request->level_id . " ";

	    	if ($all_emp[0]->count_no > 0) {
	    		$items = DB::select("
	    			Select emp_code, emp_name
	    			From employee
	    			Where emp_name like ?
	    			and is_active = 1
	    			" . $org . "
	    			" . $level_id . "
	    			Order by emp_name
	    			", array('%'.$request->emp_name.'%'));
	    	} else {
	    		$items = DB::select("
	    			Select emp_code, emp_name
	    			From employee
	    			Where (chief_emp_code = ? or emp_code = ?)
	    			And emp_name like ?
	    			" . $org . "
	    			" . $level_id . "
	    			and is_active = 1
	    			Order by emp_name
	    			", array($emp->emp_code, $emp->emp_code,'%'.$request->emp_name.'%'));
	    	}
	    	return response()->json($items);
	    }

	    public function auto_employee_name2_bak(Request $request)
	    {
	    	$items = DB::select("
	    		Select emp_code, emp_name
	    		From employee
	    		Where (chief_emp_code = '".$request->emp_code."' or emp_code = '".$request->emp_code."')
	    		and level_id = ?
	    		and emp_name like ?
	    		and is_active = 1
	    		Order by emp_name
	    		", array($request->level_id,'%'.$request->emp_name.'%'));

	    	return response()->json($items);
	    }

	    public function status_list(Request $request)
	    {
	    	$all_emp = DB::select("
				SELECT sum(b.is_all_employee) count_no
				from employee a
				left outer join appraisal_level b
				on a.level_id = b.level_id
				where emp_code = ?
			", array(Auth::id()));

	    	$emp_level = empty($request->emp_level) ? " ": "and e.level_id = {$request->emp_level}";
	    	$org_level = empty($request->org_level) ? " ": "and o.level_id = {$request->org_level}";
	    	$org_id = empty($request->org_id) ? " ": "and er.org_id = {$request->org_id}";
	    	$period_id = empty($request->period_id) ? " ": "and p.period_id = {$request->period_id}";
	    	$appraisal_frequency_id = empty($request->appraisal_frequency_id) ? " ": "and p.appraisal_frequency_id = {$request->appraisal_frequency_id}";
	    	$appraisal_year = empty($request->appraisal_year) ? " ": "and p.appraisal_year = {$request->appraisal_year}";
	    	$appraisal_type_id = empty($request->appraisal_type_id) ? " ": "and er.appraisal_type_id = {$request->appraisal_type_id}";
	    	$emp_code = empty($request->emp_code) ? " ": "and e.emp_code = '{$request->emp_code}'";
	    	$position_id = empty($request->position_id) ? " ": "and er.position_id = {$request->position_id}";
	    	$appraisal_form_id = empty($request->appraisal_form_id) ? " ": "and er.appraisal_form_id = {$request->appraisal_form_id}";

	    	if($all_emp[0]->count_no > 0) {

	    		if($request->appraisal_type_id==2) {
					$items = DB::select("
						select 'Unassigned' to_action, 'Unassigned' status
						union all
			    		select distinct CONCAT(ast.to_action,'-',ast.from_action) to_action, CONCAT(ast.to_action,' (',ast.from_action,')') status
						from emp_result er,
						employee e,
						appraisal_type t,
						appraisal_item_result ir,
						appraisal_item I,
						appraisal_period p,
						org o,
						appraisal_level al,
						appraisal_stage ast
						Where er.emp_id = e.emp_id
						and er.appraisal_type_id = t.appraisal_type_id
						And er.emp_result_id = ir.emp_result_id
						and ir.item_id = I.item_id
						and er.period_id = p.period_id
						and er.org_id = o.org_id
						and er.level_id = al.level_id
						and er.stage_id = ast.stage_id
						and ast.appraisal_type_id = 2
						#and ast.assignment_flag = 1
						".$emp_level."
						".$org_level."
						".$org_id."
						".$period_id."
						".$appraisal_frequency_id."
						".$appraisal_year."
						".$appraisal_type_id."
						".$emp_code."
						".$position_id."
						".$appraisal_form_id."
			    	");
				} else {
					$items = DB::select("
						select 'Unassigned' to_action, 'Unassigned' status
						union all
			    		select distinct CONCAT(ast.to_action,'-',ast.from_action) to_action, CONCAT(ast.to_action,' (',ast.from_action,')') status
						From emp_result er, org o, appraisal_type t, appraisal_item_result ir, appraisal_item I, appraisal_period p, appraisal_level al, appraisal_stage ast
	    				Where er.org_id = o.org_id and er.appraisal_type_id = t.appraisal_type_id
	    				And er.emp_result_id = ir.emp_result_id
	    				and ir.item_id = I.item_id
	    				and er.period_id = p.period_id
	    				and o.level_id = al.level_id
	    				and er.stage_id = ast.stage_id
						".$org_level."
						".$org_id."
						".$period_id."
						".$appraisal_frequency_id."
						".$appraisal_year."
						".$appraisal_type_id."
						".$appraisal_form_id."
			    	");
				}

	    	} else {

				if($request->appraisal_type_id==2) {

					$items = DB::select("
			    		select distinct CONCAT(ast.to_action,'-',ast.from_action) to_action, CONCAT(ast.to_action,' (',ast.from_action,')') status
						from emp_result er,
						employee e,
						appraisal_type t,
						appraisal_item_result ir,
						appraisal_item I,
						appraisal_period p,
						org o,
						appraisal_level al,
						appraisal_stage ast
						Where er.emp_id = e.emp_id
						and er.appraisal_type_id = t.appraisal_type_id
						And er.emp_result_id = ir.emp_result_id
						and ir.item_id = I.item_id
						and er.period_id = p.period_id
						and er.org_id = o.org_id
						and er.level_id = al.level_id
						and er.stage_id = ast.stage_id
						and ast.appraisal_type_id = 2
						#and ast.assignment_flag = 1
						".$emp_level."
						".$org_level."
						".$org_id."
						".$period_id."
						".$appraisal_frequency_id."
						".$appraisal_year."
						".$appraisal_type_id."
						".$position_id."
						".$appraisal_form_id."
						and (e.emp_code = '".Auth::id()."' or e.chief_emp_code = '".Auth::id()."')
						union all
						select 'Unassigned' to_action, 'Unassigned' status
			    	");
				} else {

					$items = DB::select("
			    		select distinct CONCAT(ast.to_action,'-',ast.from_action) to_action, CONCAT(ast.to_action,' (',ast.from_action,')') status
						From emp_result er, org o, appraisal_type t, appraisal_item_result ir, appraisal_item I, appraisal_period p, appraisal_level al, appraisal_stage ast
	    				Where er.org_id = o.org_id and er.appraisal_type_id = t.appraisal_type_id
	    				And er.emp_result_id = ir.emp_result_id
	    				and ir.item_id = I.item_id
	    				and er.period_id = p.period_id
	    				and o.level_id = al.level_id
	    				and er.stage_id = ast.stage_id
						".$org_level."
						".$org_id."
						".$period_id."
						".$appraisal_frequency_id."
						".$appraisal_year."
						".$appraisal_type_id."
						".$appraisal_form_id."
						and (o.org_code = '{$co->org_code}' or o.parent_org_code = '{$co->org_code}')
						union all
						select 'Unassigned' to_action, 'Unassigned' status
			    	");
				}
				// $items = DB::select("
		  //   		select CONCAT(to_action,'-',from_action) to_action, CONCAT(to_action,' (',from_action,')') status
		  //   		from appraisal_stage
		  //   		where appraisal_type_id = 2
		  //   		and assignment_flag = 1
		  //   		order by to_action asc
		  //   	");
	    	}

	    	return response()->json($items);
	    }

	    function query_index_org() {
	    	$query = "
	    				select distinct er.emp_result_id, er.status, er.stage_id, null emp_id, al.is_group_action, null emp_code,  null emp_name, o.org_id, o.org_code, o.org_name, null position_name, t.appraisal_type_name, t.appraisal_type_id, p.period_id, concat(p.appraisal_period_desc,' Start Date: ',p.start_date,' End Date: ',p.end_date) appraisal_period_desc, al.default_stage_id, af.appraisal_form_name, ast.edit_flag, ast.delete_flag, er.level_id
	    				From emp_result er, org o, appraisal_type t, appraisal_item_result ir, appraisal_item I, appraisal_period p, appraisal_level al, appraisal_stage ast, appraisal_form af
	    				Where er.org_id = o.org_id 
	    				and er.appraisal_type_id = t.appraisal_type_id
	    				And er.emp_result_id = ir.emp_result_id
	    				and ir.item_id = I.item_id
	    				and er.period_id = p.period_id
	    				and o.level_id = al.level_id
	    				and er.stage_id = ast.stage_id
	    				and er.appraisal_form_id = af.appraisal_form_id
	    	";
	    	return $query;
	    }

	    function query_index_emp() {
	    	$query = "
	    				select distinct er.emp_result_id, er.status, er.level_id, er.stage_id, e.emp_id, al.is_group_action, e.emp_code, e.emp_name, o.org_id, o.org_code, o.org_name, er.position_id, po.position_name, t.appraisal_type_name, t.appraisal_type_id, p.period_id, concat(p.appraisal_period_desc,' Start Date: ',p.start_date,' End Date: ',p.end_date) appraisal_period_desc, al.default_stage_id, af.appraisal_form_name, ast.edit_flag, ast.delete_flag
	    				From emp_result er, employee e, appraisal_type t, appraisal_item_result ir, appraisal_item I, appraisal_period p, org o, position po, appraisal_level al, appraisal_stage ast, appraisal_form af
	    				Where er.emp_id = e.emp_id 
	    				and er.appraisal_type_id = t.appraisal_type_id
	    				And er.emp_result_id = ir.emp_result_id
	    				and ir.item_id = I.item_id
	    				and er.period_id = p.period_id
	    				and er.org_id = o.org_id
	    				and er.position_id = po.position_id
	    				and er.level_id = al.level_id
	    				and er.stage_id = ast.stage_id
	    				and er.appraisal_form_id = af.appraisal_form_id
	    	";
	    	return $query;
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
	    	$query_unassign = "";

	    	if ($all_emp[0]->count_no > 0) {

	    		if ($request->appraisal_type_id == 2) {
	    			if($request->status=='Unassigned') {
	    				$query_unassign .= "
	    				Select distinct null as emp_result_id,  'Unassigned' as status, e.level_id, al.is_group_action, emp_id, emp_code, emp_name, o.org_id, o.org_code, o.org_name, e.position_id, p.position_name, 'Individual' as appraisal_type_name, 2 appraisal_type_id, 0 period_id, 'Unassigned' appraisal_period_desc, al.default_stage_id, '' appraisal_form_name, 0 edit_flag, 0 delete_flag, null stage_id, al.seq_no
	    				From employee e
	    				left outer join org o on e.org_id = o.org_id
	    				left outer join position p on e.position_id = p.position_id
	    				left outer join appraisal_level al on e.level_id = al.level_id
	    				Where e.is_active = 1
	    				";
	    				empty($request->position_id) ?: ($query_unassign .= " and e.position_id = ? " AND $qinput[] = $request->position_id);
	    				empty($request->org_id) ?: ($query_unassign .= " and e.org_id = ? " AND $qinput[] = $request->org_id);
	    				empty($request->emp_code) ?: ($query_unassign .= " and e.emp_code = ? " AND $qinput[] = $request->emp_code);
	    				empty($request->appraisal_level_id) ?: ($query_unassign .= " and e.level_id = ? " AND $qinput[] = $request->appraisal_level_id);
	    				empty($request->appraisal_level_id_org) ?: ($query_unassign .= " and o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);

	    				$query_unassign .= "
	    				and emp_code not in
	    					(
	    						SELECT emp_code
	    						FROM (
	    							SELECT e.emp_code, p.appraisal_year, p.appraisal_frequency_id, er.appraisal_type_id, Count(1) assigned_total, z.period_total
	    							FROM emp_result er, employee e, org o, appraisal_period p,
	    								(
	    									SELECT appraisal_year, appraisal_frequency_id, Count(1) period_total
	    									FROM   appraisal_period
	    				";
	    				empty($request->period_id) ?: ($query_unassign .= " where period_id = ? " AND $qinput[] = $request->period_id);
	    				$query_unassign .= "
	    									GROUP  BY appraisal_year, appraisal_frequency_id
	    								) z
	    							WHERE er.emp_id = e.emp_id
	    							AND er.period_id = p.period_id
	    							AND p.appraisal_year = z.appraisal_year
	    							AND p.appraisal_frequency_id = z.appraisal_frequency_id
	    							AND er.org_id = e.org_id
	    							and e.org_id = o.org_id
	    							AND er.position_id = e.position_id
	    							AND er.level_id = e.level_id
	    				";
	    				empty($request->position_id) ?: ($query_unassign .= " and er.position_id = ? " AND $qinput[] = $request->position_id);
	    				empty($request->org_id) ?: ($query_unassign .= " and er.org_id = ? " AND $qinput[] = $request->org_id);
	    				empty($request->emp_code) ?: ($query_unassign .= " and e.emp_code = ? " AND $qinput[] = $request->emp_code);
	    				empty($request->appraisal_level_id) ?: ($query_unassign .= " and er.level_id = ? " AND $qinput[] = $request->appraisal_level_id);
	    				empty($request->appraisal_level_id_org) ?: ($query_unassign .= " and o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);
	    				empty($request->appraisal_type_id) ?: ($query_unassign .= " and er.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
	    				empty($request->appraisal_year) ?: ($query_unassign .= " and p.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
	    				empty($request->frequency_id) ?: ($query_unassign .= " and p.appraisal_frequency_id = ? " AND $qinput[] = $request->frequency_id);
	    				empty($request->period_id) ?: ($query_unassign .= " and p.period_id = ? " AND $qinput[] = $request->period_id);
	    				empty($request->appraisal_form) ?: ($query_unassign .= " and er.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form);

	    				$query_unassign .= " 
	    							GROUP BY e.emp_code, p.appraisal_year, p.appraisal_frequency_id, er.appraisal_type_id
	    						) assigned
	    						WHERE assigned_total >= period_total
	    					)
	    				";
					} else {
						$query_unassign .= $this->query_index_emp();
						if($request->status=='afterAssignment') {
							$query_unassign .= " and (ast.appraisal_flag = 1 or ast.emp_result_judgement_flag = 1 or ast.bonus_appraisal_flag = 1 or ast.salary_adjustment_flag = 1 or ast.bonus_adjustment_flag = 1 or ast.mpi_judgement_flag = 1) ";
						} else {
							$query_unassign .= " and ast.stage_id = '{$request->status}' ";
						}

						empty($request->position_id) ?: ($query_unassign .= " and er.position_id = ? " AND $qinput[] = $request->position_id);
						empty($request->org_id) ?: ($query_unassign .= " and er.org_id = ? " AND $qinput[] = $request->org_id);
						empty($request->emp_code) ?: ($query_unassign .= " and e.emp_code = ? " AND $qinput[] = $request->emp_code);
						empty($request->appraisal_level_id) ?: ($query_unassign .= " And er.level_id = ? " AND $qinput[] = $request->appraisal_level_id);
						empty($request->appraisal_level_id_org) ?: ($query_unassign .= " And o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);
						empty($request->appraisal_type_id) ?: ($query_unassign .= " and er.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
						empty($request->appraisal_year) ?: ($query_unassign .= " and p.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
						empty($request->frequency_id) ?: ($query_unassign .= " and p.appraisal_frequency_id = ? " AND $qinput[] = $request->frequency_id);
						empty($request->period_id) ?: ($query_unassign .= " and p.period_id = ? " AND $qinput[] = $request->period_id);
						empty($request->appraisal_form) ?: ($query_unassign .= " and er.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form);
					}
					// end type = 2

	    		} else {
	    			if($request->status=='Unassigned') {
	    				$query_unassign .= "
	    				Select distinct null as emp_result_id,  'Unassigned' as status, null emp_id, al.is_group_action, null emp_code, null emp_name, o.org_id, o.org_code, o.org_name, null position_name, 'Organization' as appraisal_type_name, 1 appraisal_type_id, 0 period_id, 'Unassigned' appraisal_period_desc, al.default_stage_id, '' appraisal_form_name, 0 edit_flag, 0 delete_flag, null stage_id, o.level_id, al.seq_no
	    				From org o
	    				left outer join appraisal_level al on o.level_id = al.level_id
	    				Where o.is_active = 1
	    				";
						//empty($request->position_id) ?: ($query_unassign .= " and e.position_id = ? " AND $qinput[] = $request->position_id);
	    				empty($request->org_id) ?: ($query_unassign .= " and o.org_id = ? " AND $qinput[] = $request->org_id);
						//empty($request->emp_code) ?: ($query_unassign .= " and emp_code = ? " AND $qinput[] = $request->emp_code);
	    				empty($request->appraisal_level_id_org) ?: ($query_unassign .= " And o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);

	    				$query_unassign .= "
	    				and org_id not in
	    					(
	    						SELECT org_id
	    						FROM (
	    							SELECT o.org_id, p.appraisal_year, p.appraisal_frequency_id, er.appraisal_type_id, Count(1) assigned_total, z.period_total
	    							FROM emp_result er, org o, appraisal_period p,
	    								(
	    									SELECT appraisal_year, appraisal_frequency_id, Count(1) period_total
	    									FROM appraisal_period
	    				";
	    				empty($request->period_id) ?: ($query_unassign .= " where period_id = ? " AND $qinput[] = $request->period_id);
	    				$query_unassign .= "
	    									GROUP  BY appraisal_year, appraisal_frequency_id
	    								) z
	    							WHERE er.org_id = o.org_id
	    							AND er.period_id = p.period_id
	    							AND p.appraisal_year = z.appraisal_year
	    							AND p.appraisal_frequency_id = z.appraisal_frequency_id
	    				";
						//empty($request->position_id) ?: ($query_unassign .= " and er.position_id = ? " AND $qinput[] = $request->position_id);
	    				empty($request->org_id) ?: ($query_unassign .= " and er.org_id = ? " AND $qinput[] = $request->org_id);
						//empty($request->emp_code) ?: ($query_unassign .= " and e.emp_code = ? " AND $qinput[] = $request->emp_code);
	    				empty($request->appraisal_level_id_org) ?: ($query_unassign .= " and o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);
	    				empty($request->appraisal_type_id) ?: ($query_unassign .= " and er.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
	    				empty($request->appraisal_year) ?: ($query_unassign .= " and p.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
	    				empty($request->frequency_id) ?: ($query_unassign .= " and p.appraisal_frequency_id = ? " AND $qinput[] = $request->frequency_id);
	    				empty($request->period_id) ?: ($query_unassign .= " and p.period_id = ? " AND $qinput[] = $request->period_id);
	    				empty($request->appraisal_form) ?: ($query_unassign .= " and er.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form);

	    				$query_unassign .= "
	    							GROUP BY o.org_id, p.appraisal_year, p.appraisal_frequency_id, er.appraisal_type_id
	    						) assigned
	    						WHERE  assigned_total >= period_total
	    					)
	    				";
	    			} else {
						$query_unassign .= $this->query_index_org();
						if($request->status=='afterAssignment') {
							$query_unassign .= " and (ast.appraisal_flag = 1 or ast.emp_result_judgement_flag = 1 or ast.bonus_appraisal_flag = 1 or ast.salary_adjustment_flag = 1 or ast.bonus_adjustment_flag = 1 or ast.mpi_judgement_flag = 1) ";
						} else {
							$query_unassign .= " and ast.stage_id = '{$request->status}' ";
						}
						//empty($request->position_id) ?: ($query_unassign .= " and er.position_id = ? " AND $qinput[] = $request->position_id);
						empty($request->org_id) ?: ($query_unassign .= " and er.org_id = ? " AND $qinput[] = $request->org_id);
						//empty($request->emp_code) ?: ($query_unassign .= " and e.emp_code = ? " AND $qinput[] = $request->emp_code);
						empty($request->appraisal_level_id_org) ?: ($query_unassign .= " And o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);
						empty($request->appraisal_type_id) ?: ($query_unassign .= " and er.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
						empty($request->appraisal_year) ?: ($query_unassign .= " and p.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
						empty($request->frequency_id) ?: ($query_unassign .= " and p.appraisal_frequency_id = ? " AND $qinput[] = $request->frequency_id);
						empty($request->period_id) ?: ($query_unassign .= " and p.period_id = ? " AND $qinput[] = $request->period_id);
						empty($request->appraisal_form) ?: ($query_unassign .= " and er.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form);

					}
	    		}

	    	} else {

	    		if ($request->appraisal_type_id == 2) {
	    			if($request->status=='Unassigned') {
	    				$query_unassign .= "
	    				Select distinct null as emp_result_id,  'Unassigned' as status, e.level_id, e.emp_id, al.is_group_action, emp_code, emp_name, o.org_id, o.org_code, o.org_name, e.position_id, p.position_name, 'Individual' as appraisal_type_name, 2 appraisal_type_id, 0 period_id, 'Unassigned' appraisal_period_desc, al.default_stage_id, '' appraisal_form_name, 0 edit_flag, 0 delete_flag, null stage_id, al.seq_no
	    				From employee e 
	    				left outer join	org o on e.org_id = o.org_id
	    				left outer join position p on e.position_id = p.position_id
	    				left outer join appraisal_level al on e.level_id = al.level_id
	    				Where e.is_active = 1
	    				and (chief_emp_code = ? or emp_code = ?)
	    				";
	    				$qinput[] = Auth::id();
	    				$qinput[] = Auth::id();
	    				empty($request->position_id) ?: ($query_unassign .= " and e.position_id = ? " AND $qinput[] = $request->position_id);
	    				empty($request->org_id) ?: ($query_unassign .= " and e.org_id = ? " AND $qinput[] = $request->org_id);
	    				empty($request->emp_code) ?: ($query_unassign .= " and emp_code = ? " AND $qinput[] = $request->emp_code);
	    				empty($request->appraisal_level_id) ?: ($query_unassign .= " and e.level_id = ? " AND $qinput[] = $request->appraisal_level_id);
	    				empty($request->appraisal_level_id_org) ?: ($query_unassign .= " and o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);

	    				$query_unassign .= "
	    				and emp_code not in
	    					(
	    						SELECT emp_code
	    						FROM (
	    							SELECT e.emp_code, p.appraisal_year, p.appraisal_frequency_id, er.appraisal_type_id, Count(1) assigned_total, z.period_total
	    							FROM emp_result er, employee e, org o, appraisal_period p,
	    								(
	    									SELECT appraisal_year, appraisal_frequency_id, Count(1) period_total
	    									FROM appraisal_period
	    				";
	    				empty($request->period_id) ?: ($query_unassign .= " where period_id = ? " AND $qinput[] = $request->period_id);
	    				$query_unassign .= "
	    									GROUP BY appraisal_year, appraisal_frequency_id
	    								) z
	    							WHERE  er.emp_id = e.emp_id
	    							AND er.org_id = e.org_id
	    							and e.org_id = o.org_id
	    							and er.position_id = e.position_id
	    							AND er.level_id = e.level_id
	    							AND er.period_id = p.period_id
	    							AND p.appraisal_year = z.appraisal_year
	    							AND p.appraisal_frequency_id = z.appraisal_frequency_id
	    							AND (e.chief_emp_code = ? or e.emp_code = ?)
	    				";
	    				$qinput[] = Auth::id();
	    				$qinput[] = Auth::id();
	    				empty($request->position_id) ?: ($query_unassign .= " and er.position_id = ? " AND $qinput[] = $request->position_id);
	    				empty($request->org_id) ?: ($query_unassign .= " and er.org_id = ? " AND $qinput[] = $request->org_id);
	    				empty($request->emp_code) ?: ($query_unassign .= " and e.emp_code = ? " AND $qinput[] = $request->emp_code);
	    				empty($request->appraisal_level_id) ?: ($query_unassign .= " and er.level_id = ? " AND $qinput[] = $request->appraisal_level_id);
	    				empty($request->appraisal_level_id_org) ?: ($query_unassign .= " and o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);
	    				empty($request->appraisal_type_id) ?: ($query_unassign .= " and er.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
						//empty($request->period_id) ?: ($query_unassign .= " and er.period_id = ? " AND $qinput[] = $request->period_id);
	    				empty($request->appraisal_year) ?: ($query_unassign .= " and p.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
	    				empty($request->frequency_id) ?: ($query_unassign .= " and p.appraisal_frequency_id = ? " AND $qinput[] = $request->frequency_id);
	    				empty($request->period_id) ?: ($query_unassign .= " and p.period_id = ? " AND $qinput[] = $request->period_id);
	    				empty($request->appraisal_form) ?: ($query_unassign .= " and er.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form);

	    				$query_unassign .= "
	    							GROUP BY e.emp_code, p.appraisal_year, p.appraisal_frequency_id, er.appraisal_type_id
	    						) assigned
	    						WHERE assigned_total = period_total
	    					)
	    				";
	    			} else {
						$query_unassign .= $this->query_index_emp();
						if($request->status=='afterAssignment') {
							$query_unassign .= " and (ast.appraisal_flag = 1 or ast.emp_result_judgement_flag = 1 or ast.bonus_appraisal_flag = 1 or ast.salary_adjustment_flag = 1 or ast.bonus_adjustment_flag = 1 or ast.mpi_judgement_flag = 1) ";
						} else {
							$query_unassign .= " and ast.stage_id = '{$request->status}' ";
						}

						$query_unassign .= " and (e.chief_emp_code = ? or e.emp_code = ?)";

						$qinput[] = Auth::id();
						$qinput[] = Auth::id();

						empty($request->position_id) ?: ($query_unassign .= " and er.position_id = ? " AND $qinput[] = $request->position_id);
						empty($request->org_id) ?: ($query_unassign .= " and er.org_id = ? " AND $qinput[] = $request->org_id);
						empty($request->emp_code) ?: ($query_unassign .= " and e.emp_code = ? " AND $qinput[] = $request->emp_code);
						empty($request->appraisal_level_id) ?: ($query_unassign .= " and er.level_id = ? " AND $qinput[] = $request->appraisal_level_id);
						empty($request->appraisal_level_id_org) ?: ($query_unassign .= " and o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);
						empty($request->appraisal_type_id) ?: ($query_unassign .= " and er.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
						//empty($request->period_id) ?: ($query_unassign .= " and er.period_id = ? " AND $qinput[] = $request->period_id);
						empty($request->appraisal_year) ?: ($query_unassign .= " and p.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
						empty($request->frequency_id) ?: ($query_unassign .= " and p.appraisal_frequency_id = ? " AND $qinput[] = $request->frequency_id);
						empty($request->period_id) ?: ($query_unassign .= " and p.period_id = ? " AND $qinput[] = $request->period_id);
						empty($request->appraisal_form) ?: ($query_unassign .= " and er.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form);
					}

	    		} else {
	    			if($request->status=='Unassigned') {
	    				$query_unassign = "
	    				Select distinct null as emp_result_id,  'Unassigned' as status, null emp_id, al.is_group_action, null emp_code, null emp_name, o.org_id, o.org_code, o.org_name, null position_name, 'Organization' as appraisal_type_name, 1 appraisal_type_id, 0 period_id, 'Unassigned' appraisal_period_desc, al.default_stage_id, '' appraisal_form_name, 0 edit_flag, 0 delete_flag, null stage_id, o.level_id, al.seq_no
	    				From org o
	    				left outer join appraisal_level al on o.level_id = al.level_id
	    				Where o.is_active = 1
	    				";
						//empty($request->position_id) ?: ($query_unassign .= " and e.position_id = ? " AND $qinput[] = $request->position_id);
	    				empty($request->org_id) ?: ($query_unassign .= " and o.org_id = ? " AND $qinput[] = $request->org_id);
						//empty($request->emp_code) ?: ($query_unassign .= " and emp_code = ? " AND $qinput[] = $request->emp_code);
	    				empty($request->appraisal_level_id_org) ?: ($query_unassign .= " And o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);

	    				$query_unassign .= "
	    				and org_id not in
	    					(
	    						SELECT org_id
	    						FROM (
	    								SELECT o.org_id, p.appraisal_year, p.appraisal_frequency_id, er.appraisal_type_id, Count(1) assigned_total, z.period_total
	    								FROM emp_result er, org o, appraisal_period p,
	    									(
	    										SELECT appraisal_year, appraisal_frequency_id, Count(1) period_total
	    										FROM appraisal_period
	    				";
	    				empty($request->period_id) ?: ($query_unassign .= " where period_id = ? " AND $qinput[] = $request->period_id);
	    				$query_unassign .= "
	    										GROUP BY appraisal_year, appraisal_frequency_id
	    									) z
	    								WHERE er.org_id = o.org_id
	    								AND er.period_id = p.period_id
	    								AND p.appraisal_year = z.appraisal_year
	    								AND p.appraisal_frequency_id = z.appraisal_frequency_id
	    				";
						//empty($request->position_id) ?: ($query_unassign .= " and er.position_id = ? " AND $qinput[] = $request->position_id);
	    				empty($request->org_id) ?: ($query_unassign .= " and er.org_id = ? " AND $qinput[] = $request->org_id);
						//empty($request->emp_code) ?: ($query_unassign .= " and e.emp_code = ? " AND $qinput[] = $request->emp_code);
	    				empty($request->appraisal_level_id_org) ?: ($query_unassign .= " and o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);
	    				empty($request->appraisal_type_id) ?: ($query_unassign .= " and er.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
	    				empty($request->appraisal_year) ?: ($query_unassign .= " and p.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
	    				empty($request->frequency_id) ?: ($query_unassign .= " and p.appraisal_frequency_id = ? " AND $qinput[] = $request->frequency_id);
	    				empty($request->period_id) ?: ($query_unassign .= " and p.period_id = ? " AND $qinput[] = $request->period_id);
	    				empty($request->appraisal_form) ?: ($query_unassign .= " and er.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form);

	    				$query_unassign .= "
	    								GROUP  BY o.org_id, p.appraisal_year, p.appraisal_frequency_id, er.appraisal_type_id
	    							) assigned
	    						WHERE assigned_total >= period_total
	    					)
	    				";
	    			} else {
						$query_unassign .= $this->query_index_org();
						if($request->status=='afterAssignment') {
							$query_unassign .= " and (ast.appraisal_flag = 1 or ast.emp_result_judgement_flag = 1 or ast.bonus_appraisal_flag = 1 or ast.salary_adjustment_flag = 1 or ast.bonus_adjustment_flag = 1 or ast.mpi_judgement_flag = 1) ";
						} else {
							$query_unassign .= " and ast.stage_id = '{$request->status}' ";
						}
						//empty($request->position_id) ?: ($query_unassign .= " and er.position_id = ? " AND $qinput[] = $request->position_id);
	    				empty($request->org_id) ?: ($query_unassign .= " and er.org_id = ? " AND $qinput[] = $request->org_id);
						//empty($request->emp_code) ?: ($query_unassign .= " and e.emp_code = ? " AND $qinput[] = $request->emp_code);
	    				empty($request->appraisal_level_id_org) ?: ($query_unassign .= " And o.level_id = ? " AND $qinput[] = $request->appraisal_level_id_org);
	    				empty($request->appraisal_type_id) ?: ($query_unassign .= " and er.appraisal_type_id = ? " AND $qinput[] = $request->appraisal_type_id);
	    				empty($request->appraisal_year) ?: ($query_unassign .= " and p.appraisal_year = ? " AND $qinput[] = $request->appraisal_year);
	    				empty($request->frequency_id) ?: ($query_unassign .= " and p.appraisal_frequency_id = ? " AND $qinput[] = $request->frequency_id);
	    				empty($request->period_id) ?: ($query_unassign .= " and p.period_id = ? " AND $qinput[] = $request->period_id);
	    				empty($request->appraisal_form) ?: ($query_unassign .= " and er.appraisal_form_id = ? " AND $qinput[] = $request->appraisal_form);
	    			}

	    		}
	    	}

	    	$items = DB::select($query_unassign . " order by org_code asc, seq_no asc, emp_code asc", $qinput);

	    	$items2 = $this->find_derive($items, $request->appraisal_form, $request->period_id, $request->appraisal_type_id);

		// Number of items per page
        if($request->rpp == 'All' || empty($request->rpp)) {
            $request->rpp = empty($items2) ? 10 : count($items2);
        }

		// Get the current page from the url if it's not set default to 1
	    	empty($request->page) ? $page = 1 : $page = $request->page;

		// Number of items per page
	    	empty($request->rpp) ? $perPage = 10 : $perPage = $request->rpp;

		$offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

		// Get only the items you need using array_slice (only get 10 items since that's what you need)
		$itemsForCurrentPage = array_slice($items2, $offSet, $perPage, false);

		// Return the paginator with only 10 items but with the count of all items and set the it on the correct page
		$result = new LengthAwarePaginator($itemsForCurrentPage, count($items2), $perPage, $page);

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
		return response()->json($resultT);

	}

	/*
	function find_derive($items, $appraisal_form, $period_id, $appraisal_type_id) {
		// $findDerive = DB::select("
		// 	SELECT DISTINCT ast.level_id, al.is_org, al.is_individual
		// 	FROM appraisal_structure ast
		// 	INNER JOIN appraisal_criteria ac ON ac.structure_id = ast.structure_id
		// 	INNER JOIN appraisal_level al ON al.level_id = ast.level_id
		// 	WHERE ast.is_derive = 1
		// 	AND ac.appraisal_form_id = '{$appraisal_form}'
		// ");

		// if(empty($findDerive)) {
		// 	// หากไม่มี is derive เลย ให้สามารถ assigned ได้ตามปกติ
		// 	foreach ($items as $key => $item) {
		// 		$items[$key]->assigned = 1;
		//     	$items[$key]->assigned_msg = '';
		// 	}
		// }

		// foreach ($items as $key => $item) { // loop พนักงาน หรือ organization
		// 	foreach ($findDerive as $findDerives) { // loop หา level is derive แต่ละอัน
		// 	    if($findDerives->is_individual==1) {
		// 	    	//หา chief emp code ขึ้นไปเรื่อยๆว่ามีเลเวลตรงกับ is derive หรือไม่
		// 	    	$findChiefEmp = $this->GetChiefEmpDeriveLevel($item->emp_code, $findDerives->level_id);
		// 	    	if($findChiefEmp['emp_id']==0) { // กรณีไม่มี chief_emp_code
		// 	    		$items[$key]->assigned = 1;
		//     			$items[$key]->assigned_msg = '';
		// 	    	} else {
		// 		    	$findEmpResult = EmpResult::where("appraisal_form_id", $appraisal_form)
		// 		    	->where("period_id", $period_id)
		// 		    	->where("emp_id", $findChiefEmp['emp_id'])
		// 		    	->where("status", "Accepted")->first();

		// 			    if(empty($findEmpResult)) { // กรณี stage ยังไม่ complete
		// 		    		if(empty($items[$key]->assigned) || $items[$key]->assigned==0) {
		// 		    			// กรณี ยังไม่มีการ complete เลย
		// 			    		$items[$key]->assigned = 0;
		// 			    		$items[$key]->assigned_msg = 'Chief Employee not Assign to Stage Complete';
		// 		    		}
		// 		    	} else {
		// 				    $items[$key]->assigned = 1;
		// 				    $items[$key]->assigned_msg = 'Complete';
		// 				    $items[$key]->chief_id_array[] = $findEmpResult->emp_id;
		// 				}
		// 			}

		// 	    } else if($findDerives->is_org==1) {
		// 	    	//หา parent org code ขึ้นไปเรื่อยๆว่ามีเลเวลตรงกับ is derive หรือไม่
		// 	    	$findChiefEmp = $this->GetParentOrgDeriveLevel($item->org_code, $findDerives->level_id);
		// 	    	// exit(json_encode(['data' => $findChiefEmp]));
		// 	    	if($findChiefEmp['org_id']==0) { // กรณีไม่มี parent_org_code
		// 	    		$items[$key]->assigned = 1;
		//     			$items[$key]->assigned_msg = '';
		// 	    	} else {
		// 		    	$findEmpResult = EmpResult::where("appraisal_form_id", $appraisal_form)
		// 		    	->where("period_id", $period_id)
		// 		    	->where("org_id", $findChiefEmp['org_id'])
		// 		    	->where("status", "Accepted")->first();

		// 		    	if(empty($findEmpResult)) { // กรณี stage ยังไม่ complete
		// 		    		if(empty($items[$key]->assigned) || $items[$key]->assigned==0) { 
		// 		    			// กรณี ยังไม่มีการ complete เลย
		// 			    		$items[$key]->assigned = 0;
		// 			    		$items[$key]->assigned_msg = 'Parent Org not Assign to Stage Complete';
		// 		    		}
		// 		    	} else {
		// 		    		$items[$key]->assigned = 1;
		// 		    		$items[$key]->assigned_msg = 'Complete';
		// 		    		$items[$key]->chief_id_array[] = $findEmpResult->org_id;
		// 		    	}
		// 			}
		// 	    }
		// 	}
		// }

		foreach ($items as $key => $item) { // loop พนักงาน หรือ organization
			$findDerive = DB::select("
				SELECT ast.level_id, al.is_org, al.is_individual
				FROM appraisal_structure ast
				INNER JOIN appraisal_criteria ac ON ac.structure_id = ast.structure_id
				INNER JOIN appraisal_level al ON al.level_id = ast.level_id
				WHERE ast.is_derive = 1
				AND ac.appraisal_form_id = '{$appraisal_form}'
				AND ac.appraisal_level_id = '{$item->level_id}'
				GROUP BY ast.level_id
			");

			if(empty($findDerive)) {
				// หากไม่มี is derive เลย ให้สามารถ assigned ได้ตามปกติ
				$items[$key]->assigned = 1;
			    $items[$key]->assigned_msg = '';
			}

			foreach ($findDerive as $findDerives) { // loop หา level is derive แต่ละอัน
			    if($findDerives->is_individual==1) {
			    	//หา chief emp code ขึ้นไปเรื่อยๆว่ามีเลเวลตรงกับ is derive หรือไม่
			    	$findChiefEmp = $this->advanSearch->GetChiefEmpDeriveLevel($item->emp_code, $findDerives->level_id);
			    	// if($findChiefEmp['emp_id']==0) { // กรณีไม่มี chief_emp_code
			    	// 	$items[$key]->assigned = 1;
		    		// 	$items[$key]->assigned_msg = '';
			    	// } else {
				    	$findEmpResult = DB::table('emp_result')
				        ->join('appraisal_stage', 'appraisal_stage.stage_id', '=', 'emp_result.stage_id')
				        ->where('emp_result.period_id', $period_id)
				        ->where('emp_result.appraisal_form_id', $appraisal_form)
				        ->where('emp_result.emp_id', $findChiefEmp['emp_id'])
				        ->orWhere('emp_result.emp_id', $item->emp_id)
				        ->where('appraisal_stage.assignment_flag', 1)
				        ->where('appraisal_stage.edit_flag', 0)
				        ->first();

					    if(empty($findEmpResult)) { // กรณี stage ยังไม่ complete
				    		if(empty($items[$key]->assigned) || $items[$key]->assigned==0) {
				    			// กรณี ยังไม่มีการ complete เลย
					    		$items[$key]->assigned = 0;
					    		$items[$key]->assigned_msg = 'Chief Employee not Assign to Stage Complete';
				    		}
				    	} else {
						    $items[$key]->assigned = 1;
						    $items[$key]->assigned_msg = 'Complete';
						    $items[$key]->chief_id_array[] = $findEmpResult->emp_id;
						    $items[$key]->is_derive_check[] = 'emp';
						}
					// }

			    } else if($findDerives->is_org==1) {
			    	//หา parent org code ขึ้นไปเรื่อยๆว่ามีเลเวลตรงกับ is derive หรือไม่
			    	$getLv = Org::find($item->org_id)->level_id;
			    	if($appraisal_type_id==1 && $findDerives->level_id==$getLv) {
			    		$items[$key]->assigned = 1;
		    			$items[$key]->assigned_msg = '';
			    	} else {
			    		$findChiefEmp = $this->advanSearch->GetParentOrgDeriveLevel($item->org_code, $findDerives->level_id);

				    	// exit(json_encode(['level' => $findDerives->level_id, '$getLv' => $getLv, 'findChiefEmp' => $findChiefEmp]));
				    	// exit(json_encode(['data' => $findChiefEmp]));

				    	// exit(json_encode(['data' => $findChiefEmp, 'org_code' => $item->org_code]));
				    	// exit(json_encode(['data' => $findChiefEmp]));
				    	// if($findChiefEmp['org_id']==0) { // กรณีไม่มี parent_org_code
				    	// 	$items[$key]->assigned = 1;
			    		// 	$items[$key]->assigned_msg = '';
				    	// } else {
					    	$findEmpResult = DB::table('emp_result')
					        ->join('appraisal_stage', 'appraisal_stage.stage_id', '=', 'emp_result.stage_id')
					        ->where('emp_result.period_id', $period_id)
					        ->where('emp_result.appraisal_form_id', $appraisal_form)
					        ->where('emp_result.org_id', $findChiefEmp['org_id'])
					        ->orWhere('emp_result.org_id', $item->org_id)
					        ->where('emp_result.emp_id', null)
					        ->where('appraisal_stage.assignment_flag', 1)
					        ->where('appraisal_stage.edit_flag', 0)
					        ->first();

					        // $ddd = DB::select("
					        // 	selects * 
					        // 	from emp_result er
					        // 	inner join appraisal_stage ast on ast.stage_id = er.stage_id
					        // 	where er.period_id = 
					        // 	and er.appraisal_form_id = $appraisal_form
					        // 	and (er.org_id = '{$findChiefEmp['org_id']}' or er.org_id = {$item->org_id})
					        // 	and er.emp_id is null
					        // 	and ast.assignment_flag = 1
					        // 	and ast.edit_flag = 0 
					        // ");



					        // exit(json_encode(['data' => $findEmpResult]));

					    	if(empty($findEmpResult)) { // กรณี stage ยังไม่ complete
					    		if(empty($items[$key]->assigned) || $items[$key]->assigned==0) { 
					    			// กรณี ยังไม่มีการ complete เลย
						    		$items[$key]->assigned = 0;
						    		$items[$key]->assigned_msg = 'Parent Org not Assign to Stage Complete';
					    		}
					    	} else {
					    		$items[$key]->assigned = 1;
					    		$items[$key]->assigned_msg = 'Complete';
					    		$items[$key]->org_id_array[] = $findEmpResult->org_id;
					    		$items[$key]->is_derive_check[] = 'org';
					    	}
			    	}
			    	// exit(json_encode(['org_code' => $getLv, 'level' => $findDerives->level_id]));
			    	// $findChiefEmp = $this->advanSearch->GetParentOrgDeriveLevel($item->org_code, $findDerives->level_id);

			    	// exit(json_encode(['level' => $findDerives->level_id, '$getLv' => $getLv, 'findChiefEmp' => $findChiefEmp]));
			    	// // exit(json_encode(['data' => $findChiefEmp]));

			    	// // exit(json_encode(['data' => $findChiefEmp, 'org_code' => $item->org_code]));
			    	// // exit(json_encode(['data' => $findChiefEmp]));
			    	// // if($findChiefEmp['org_id']==0) { // กรณีไม่มี parent_org_code
			    	// // 	$items[$key]->assigned = 1;
		    		// // 	$items[$key]->assigned_msg = '';
			    	// // } else {
				    // 	$findEmpResult = DB::table('emp_result')
				    //     ->join('appraisal_stage', 'appraisal_stage.stage_id', '=', 'emp_result.stage_id')
				    //     ->where('emp_result.period_id', $period_id)
				    //     ->where('emp_result.appraisal_form_id', $appraisal_form)
				    //     ->where('emp_result.org_id', $findChiefEmp['org_id'])
				    //     ->orWhere('emp_result.org_id', $item->org_id)
				    //     ->where('emp_result.emp_id', null)
				    //     ->where('appraisal_stage.assignment_flag', 1)
				    //     ->where('appraisal_stage.edit_flag', 0)
				    //     ->first();

				    //     // exit(json_encode(['data' => $findEmpResult]));

				    // 	if(empty($findEmpResult)) { // กรณี stage ยังไม่ complete
				    // 		if(empty($items[$key]->assigned) || $items[$key]->assigned==0) { 
				    // 			// กรณี ยังไม่มีการ complete เลย
					   //  		$items[$key]->assigned = 0;
					   //  		$items[$key]->assigned_msg = 'Parent Org not Assign to Stage Complete';
				    // 		}
				    // 	} else {
				    // 		$items[$key]->assigned = 1;
				    // 		$items[$key]->assigned_msg = 'Complete';
				    // 		$items[$key]->org_id_array[] = $findEmpResult->org_id;
				    // 		$items[$key]->is_derive_check[] = 'org';
				    // 	}
					// }
			    }
			}

		}

		return $items;
	}
	*/

	function find_derive($items, $appraisal_form, $period_id, $appraisal_type_id) {
		$findDerive = DB::select("
			SELECT ast.level_id, al.is_org, al.is_individual
			FROM appraisal_structure ast
			INNER JOIN appraisal_criteria ac ON ac.structure_id = ast.structure_id
			INNER JOIN appraisal_level al ON al.level_id = ast.level_id
			WHERE ast.is_derive = 1
			AND ac.appraisal_form_id = '{$appraisal_form}'
			GROUP BY ast.level_id
		");

		// exit(json_encode(['data' => $findDerive]));

		foreach ($items as $key => $item) { // loop พนักงาน หรือ organization
			if(empty($findDerive)) {
				// หากไม่มี is derive เลย ให้สามารถ assigned ได้ตามปกติ
				$items[$key]->assigned = 1;
			    $items[$key]->assigned_msg = '';
			} else {
				foreach ($findDerive as $findDerives) { // loop หา level is derive แต่ละอัน
					if($findDerives->is_individual==1) { // ถ้า is_derive เป็นแบบ emp
						$getLv = Employee::find($item->emp_code)['level_id'];
						if($findDerives->level_id==$getLv) { 
							if(isset($items[$key]->assigned) && $items[$key]->assigned==0) {
								// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (แต่ยังไม่ครบทุก derive)
								$items[$key]->assigned = 0;
								$items[$key]->assigned_msg = 'Cannot Assign because Derive is not Complete';
							} else {
								// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (ผ่านทุก derive)
								// และ level_emp ของ emp ที่เลือกตรงกับ level_emp ใน is_derive
								// จึงเป็นการ derive ตัวเอง โดยการประเมินตนเอง
								$items[$key]->assigned = 1;
								$items[$key]->assigned_msg = '';
							}
						} else {
							$findChiefEmp = $this->advanSearch->GetChiefEmpDeriveLevel($item->emp_code, $findDerives->level_id);
							if ($findChiefEmp['have_derive']=='0') {
								if(isset($items[$key]->assigned) && $items[$key]->assigned==1) {
									// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (แต่ยังไม่ครบทุก derive)
									$items[$key]->assigned = 0;
									$items[$key]->assigned_msg = 'Cannot Assign because Derive is not Complete';
								} else {
									$items[$key]->assigned = 1;
									$items[$key]->assigned_msg = '';
								}
							} else if($findChiefEmp['emp_id']=='0') {
								//กรณี emp หาหัวหน้าที่ level_emp ตรงกับ derive ไม่เจอ แสดงว่า emp คนนี้ไม่ต้องไปเอา derive มา
								if(isset($items[$key]->assigned) && $items[$key]->assigned==0) {
									// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (แต่ยังไม่ครบทุก derive)
									$items[$key]->assigned = 0;
									$items[$key]->assigned_msg = 'Cannot Assign because Derive is not Complete';
								} else {
									// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (ผ่านทุก derive)
									$items[$key]->assigned = 1;
									$items[$key]->assigned_msg = '';
								}
							} else {
								$findEmpResult = DB::select("
									SELECT er.emp_id
									FROM emp_result er
									INNER JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
									WHERE er.period_id = '".$period_id."'
									AND er.appraisal_form_id = '".$appraisal_form."'
									AND er.emp_id = '".$findChiefEmp['emp_id']."'
									AND ast.assignment_flag = 0
								");

								if(empty($findEmpResult)) {
									// กรณี emp_result ของหัวหน้ายังไม่ผ่านหน้า assignment (ต้องผ่านทุก derive)
									$items[$key]->assigned = 0;
									$items[$key]->assigned_msg = 'Cannot Assign because Derive is not Complete';
								} else {
									if(isset($items[$key]->assigned) && $items[$key]->assigned==0) {
										// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (แต่ยังไม่ครบทุก derive)
										$items[$key]->assigned = 0;
										$items[$key]->assigned_msg = 'Cannot Assign because Derive is not Complete';
									} else {
										// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (ผ่านทุก derive)
										$items[$key]->assigned = 1;
										$items[$key]->assigned_msg = 'Complete';
										$items[$key]->chief_id_array[] = $findEmpResult[0]->emp_id;
										$items[$key]->is_derive_check[] = 'emp';
									}
								}
							}
						}

					} else if($findDerives->is_org==1) {
						//หา parent org code ขึ้นไปเรื่อยๆว่ามีเลเวลตรงกับ is derive หรือไม่
						$getLv = Org::find($item->org_id)->level_id;
						if($findDerives->level_id==$getLv) {
							//ถ้า level_org ของ org ที่เลือกตรงกับ level_org ใน is_derive แสดงว่า derive ตัวเอง
							if(isset($items[$key]->assigned) && $items[$key]->assigned==0) {
								// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (แต่ยังไม่ครบทุก derive)
								$items[$key]->assigned = 0;
								$items[$key]->assigned_msg = 'Cannot Assign because Derive is not Complete';
							} else {
								// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (ผ่านทุก derive)
								$items[$key]->assigned = 1;
								$items[$key]->assigned_msg = '';
							}

						} else {
							$findChiefEmp = $this->advanSearch->GetParentOrgDeriveLevel($item->org_code, $findDerives->level_id);
							if ($findChiefEmp['have_derive']=='0') {
								if(isset($items[$key]->assigned) && $items[$key]->assigned==1) {
									// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (แต่ยังไม่ครบทุก derive)
									$items[$key]->assigned = 0;
									$items[$key]->assigned_msg = 'Cannot Assign because Derive is not Complete';
								} else {
									$items[$key]->assigned = 1;
									$items[$key]->assigned_msg = '';
								}
							} else if($findChiefEmp['org_id']=='0') {
								if(isset($items[$key]->assigned) && $items[$key]->assigned==0) {
									// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (แต่ยังไม่ครบทุก derive)
									$items[$key]->assigned = 0;
									$items[$key]->assigned_msg = 'Cannot Assign because Derive is not Complete';
								} else {
									// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (ผ่านทุก derive)
									$items[$key]->assigned = 1;
									$items[$key]->assigned_msg = '';
								}
							} else {
								$findEmpResult = DB::select("
									SELECT er.org_id
									FROM emp_result er
									INNER JOIN appraisal_stage ast ON ast.stage_id = er.stage_id
									WHERE er.period_id = '".$period_id."'
									AND er.appraisal_form_id = '".$appraisal_form."'
									AND (er.org_id = '".$findChiefEmp['org_id']."')
									AND er.emp_id IS NULL
									AND ast.assignment_flag = 0
								");

								if(empty($findEmpResult)) {
									// กรณี emp_result ของหัวหน้ายังไม่ผ่านหน้า assignment (ต้องผ่านทุก derive)
									$items[$key]->assigned = 0;
									$items[$key]->assigned_msg = 'Cannot Assign because Derive is not Complete';
								} else {
									if(isset($items[$key]->assigned) && $items[$key]->assigned==0) {
										// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (แต่ยังไม่ครบทุก derive)
										$items[$key]->assigned = 0;
										$items[$key]->assigned_msg = 'Cannot Assign because Derive is not Complete';
									} else {
										// กรณี emp_result ของหัวหน้าผ่านหน้า assignment (ผ่านทุก derive)
										$items[$key]->assigned = 1;
										$items[$key]->assigned_msg = 'Complete';
										$items[$key]->org_id_array[] = $findEmpResult[0]->org_id;
										$items[$key]->is_derive_check[] = 'org';
									}
								}
							}
						}
					}
				}
			}

		}

		return $items;
	}

	function find_derive_item($is_derive_array, $chief_id_array, $org_id_array, $query_structure, $period_id, $appraisal_form_id) {
		$struc_array = [];
		foreach ($query_structure as $value) {
			array_push($struc_array, $value->structure_id);
		}

		$structure_in = empty($struc_array) ? "" : "and a.structure_id IN (".implode(",", $struc_array).")";

		$chief_id_array = in_array('null', $chief_id_array) || in_array('undefined', $chief_id_array) || in_array('', $chief_id_array) ? "" : $chief_id_array;
		$emp_in = empty($chief_id_array) ? "''" : implode(',',$chief_id_array);

		$org_id_array = in_array('null', $org_id_array) || in_array('undefined', $org_id_array) || in_array('', $org_id_array) ? "" : $org_id_array;
		$org_in = empty($org_id_array) ? "''" : implode(',',$org_id_array);

		//$is_derive_array
		//แปลงค่า implode ['org,org,org'] เป็น org,org,org
		//แปลงค่า explode org,org,org เป็น ['org','org','org']
		$derive_array = explode(',',implode(',',$is_derive_array));

		//เช็คว่า derive เป็ฯแบบ org หรือ emp
		if (count(array_unique($derive_array)) === 1 && end($derive_array) === 'org') {
			$ques = " and ar.org_id IN ({$org_in}) and ar.emp_id is null ";
		} else if(count(array_unique($derive_array)) === 1 && end($derive_array) === 'emp') {
			$ques = " and ar.emp_id IN ({$emp_in}) ";
		} else {
			$ques = " 
				and (ar.emp_id IN ({$emp_in}) OR ar.org_id IN (
							select org_id
							from appraisal_item_result
							where ar.item_result_id = item_result_id
							and emp_id is null
							and org_id IN ({$org_in})
					)
				)
			";
		}

			$query = "
				SELECT a.item_id, ar.item_result_id,
						a.item_name, 
						uom.uom_name,
						a.structure_id, 
						b.structure_name, 
						b.nof_target_score, 
						f.form_id, 
						f.form_name, 
						f.app_url,
						ar.weight_percent 'weight_percent_chief',
						ar.structure_weight_percent 'weight_percent', 
						a.max_value, 
						a.value_get_zero, 
						a.unit_deduct_score, 
						a.unit_reward_score, 
						e.no_weight, 
						a.kpi_type_id, 
						ar.structure_weight_percent, 
						b.is_value_get_zero, 
						a.no_raise_value, 
						b.is_no_raise_value,
						b.seq_no,
						1 form_chief,
						ar.actual_value,
						ar.score0,
						ar.score1,
						ar.score2,
						ar.score3,
						ar.score4,
						ar.score5,
						ar.target_value
				from appraisal_item a
				inner join appraisal_item_result ar on a.item_id = ar.item_id
				left outer join appraisal_structure b on a.structure_id = b.structure_id
				left outer join form_type f on b.form_id = f.form_id
				left outer join appraisal_level e on e.level_id = ar.level_id
				left join uom on a.uom_id = uom.uom_id
				where 1=1
				{$ques}
				and ar.period_id = '{$period_id}'
				and ar.appraisal_form_id = '{$appraisal_form_id}'
				{$structure_in}
				group by a.item_id order by b.seq_no, a.item_id, ar.structure_weight_percent desc
			";

			$findResult = DB::select($query);

		return $findResult;
	}

	public function assign_template(Request $request)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		$org_id = empty($request->org_id) ? "" : " and (o.org_id = '{$request->org_id}' or ar.org_id = '{$request->org_id}')";
		$appraisal_level_id = empty($request->appraisal_level_id) ? "" : " and (d.level_id = '{$request->appraisal_level_id}' or ar.level_id = '{$request->appraisal_level_id}')";
		$appraisal_level = empty($request->appraisal_level_id) ? "" : " and c.appraisal_level_id = '{$request->appraisal_level_id}'";

		$check_structure = DB::table('appraisal_criteria')->where('appraisal_level_id', $request->appraisal_level_id)->groupBy('structure_id')->get();
		/*
		ในกรณีที่มี is derive ให้นำเอา item ของหัวหน้ามาว่ามีหรือไม่
		chief_id_array คือ array org_id หรือ emp_id ของ item ที่มีการหา derive มาแล้ว
		check_structure คือ structureใน criteria ว่ามีกี่ structure
		*/

		$items_chief = $this->find_derive_item($request->is_derive_check, $request->chief_id_array,  $request->org_id_array, $check_structure, $request->period_id, $request->appraisal_form_id);

		$item_array = [];
		foreach ($items_chief as $value) {
			array_push($item_array, $value->structure_id);
		}

		$structure_not = empty($item_array) ? "" : "and a.structure_id NOT IN (".implode(",", array_unique($item_array)).")";

		$items = DB::select("
			SELECT a.item_id, 
					a.item_name, 
					uom.uom_name,
					a.structure_id, 
					b.structure_name, 
					b.nof_target_score, 
					f.form_id, 
					f.form_name, 
					f.app_url,
					#if(ar.structure_weight_percent is null,c.weight_percent,ar.structure_weight_percent) weight_percent,
					c.weight_percent,
					a.max_value, 
					a.value_get_zero, 
					a.unit_deduct_score, 
					a.unit_reward_score, 
					e.no_weight, 
					a.kpi_type_id, 
					ar.structure_weight_percent, 
					b.is_value_get_zero, 
					a.no_raise_value, 
					b.is_no_raise_value, 
					b.seq_no,
					0 form_chief
			from appraisal_item a
			left outer join appraisal_structure b on a.structure_id = b.structure_id
			left outer join form_type f on b.form_id = f.form_id
			left outer join appraisal_criteria c on b.structure_id = c.structure_id
			left outer join appraisal_item_level d on a.item_id = d.item_id and d.level_id = '{$request->appraisal_level_id}'
			left outer join appraisal_item_org o on a.item_id = o.item_id and o.org_id = '{$request->org_id}'
			left outer join appraisal_level e on d.level_id = e.level_id
			left outer join uom on a.uom_id = uom.uom_id
			left outer join appraisal_item_result ar on a.item_id = ar.item_id and ar.emp_result_id = '{$request->emp_result_id}'
			where e.is_active = 1
			and if(ar.item_id is not null, 1=1, a.is_active = 1)
			and c.appraisal_form_id = '{$request->appraisal_form_id}'
			".$structure_not."
			".$org_id.$appraisal_level_id.$appraisal_level."
			group by a.item_id order by b.seq_no, a.item_id, ar.structure_weight_percent desc
		");

		foreach ($items_chief as $value2) {
			foreach ($check_structure as $k_struc => $v_struc) {
				if($v_struc->structure_id==$value2->structure_id) {
					$value2->weight_percent_chief = number_format(($v_struc->weight_percent*$value2->weight_percent_chief)/$value2->weight_percent,2);
					$value2->weight_percent = $v_struc->weight_percent;
					$value2->structure_weight_percent = $v_struc->weight_percent;
				}
			}
		}

		/*
		หาก item_chief มีข้อมูล
		ให้นำค่ามาหา อัตนัยนิยม
		$value2->weight_percent 100%
			$value2->weight_percent_chief 50%
			$value2->weight_percent_chief 50%
		$value2->weight_percent 100%
			$value2->weight_percent_chief 50%
			$value2->weight_percent_chief 50%
		$value2->weight_percent 100%
			$value2->weight_percent_chief 50%
			$value2->weight_percent_chief 50%
		
		$value2->weight_percent 25%
			$value2->weight_percent_chief 12.5%
			$value2->weight_percent_chief 12.5%
		$value2->weight_percent 25%
			$value2->weight_percent_chief 12.5%
			$value2->weight_percent_chief 12.5%
		$value2->weight_percent 25%
			$value2->weight_percent_chief 12.5%
			$value2->weight_percent_chief 12.5%
		*/

		$items = collect($items_chief)->merge($items);

		$groups = array();
		foreach ($items as $item) {
			$key = $item->structure_name;
			if (!isset($groups[$key])) {
				if ($item->form_name == 'Quantity') {
					$columns = [
						[
							'column_display' => 'Appraisal Item Name',
							'column_name' => 'item_name',
							'data_type' => 'text',
						],
						[
							'column_display' => 'Appraisal Level',
							'column_name' => 'appraisal_level_name',
							'data_type' => 'text',
						],
						[
							'column_display' => 'Structure',
							'column_name' => 'structure_name',
							'data_type' => 'text',
						],
						[
							'column_display' => 'Perspective',
							'column_name' => 'perspective_name',
							'data_type' => 'text',
						],
						[
							'column_display' => 'UOM',
							'column_name' => 'uom_name',
							'data_type' => 'text',
						],
						[
							'column_display' => 'IsActive',
							'column_name' => 'is_active',
							'data_type' => 'checkbox',
						],
					];
				} elseif ($item->form_name == 'Quality') {
					$columns = [
						[
							'column_display' => 'Appraisal Item Name',
							'column_name' => 'item_name',
							'data_type' => 'text',
						],
						[
							'column_display' => 'Appraisal Level',
							'column_name' => 'appraisal_level_name',
							'data_type' => 'text',
						],
						[
							'column_display' => 'IsActive',
							'column_name' => 'is_active',
							'data_type' => 'checkbox',
						],
					];
				} elseif ($item->form_name == 'Deduct Score') {
					$columns = [
						[
							'column_display' => 'Appraisal Item Name',
							'column_name' => 'item_name',
							'data_type' => 'text',
						],
						[
							'column_display' => 'Appraisal Level',
							'column_name' => 'appraisal_level_name',
							'data_type' => 'text',
						],
						[
							'column_display' => 'Max Value',
							'column_name' => 'max_value',
							'data_type' => 'number',
						],
						[
							'column_display' => 'Value Get Zero',
							'column_name' => 'value_get_zero',
							'data_type' => 'number',
						],
						[
							'column_display' => 'Deduct Score/Unit',
							'column_name' => 'unit_deduct_score',
							'data_type' => 'number',
						],
						[
							'column_display' => 'IsActive',
							'column_name' => 'is_active',
							'data_type' => 'checkbox',
						],
					];
				} elseif ($item->form_name == 'Reward Score') {
					$columns = [
						[
							'column_display' => 'Appraisal Item Name',
							'column_name' => 'item_name',
							'data_type' => 'text',
						],
						[
							'column_display' => 'Appraisal Level',
							'column_name' => 'appraisal_level_name',
							'data_type' => 'text',
						],
						[
							'column_display' => 'Max Value',
							'column_name' => 'max_value',
							'data_type' => 'number',
						],					
						[
							'column_display' => 'Reward Score/Unit',
							'column_name' => 'unit_reward_score',
							'data_type' => 'number',
						],
						[
							'column_display' => 'IsActive',
							'column_name' => 'is_active',
							'data_type' => 'checkbox',
						],
					];
				}

				$tc = DB::select("
					SELECT b.target_score, b.color_code
					FROM threshold_group a
					inner join threshold b on a.threshold_group_id = b.threshold_group_id
					where a.is_active = 1
					and b.structure_id = ?
					order by b.target_score asc
					",array($item->structure_id));

				foreach (range(0,4,1) as $i) {
					if (array_key_exists($i,$tc)) {
					} else {
						$place_holder = ["target_score" => $i + 1, "color_code" => "DDDDDD"];
						$tc[] = $place_holder;
					}
				}

				// $check = DB::select("
					// select ifnull(max(a.end_threshold),0) max_no
					// from result_threshold a left outer join result_threshold_group b
					// on a.result_threshold_group_id = b.result_threshold_group_id
					// where b.is_active = 1
					// and b.result_type = 2
				// ");

				// if ($check[0]->max_no == 0) {
					// $total_weight = $item->weight_percent;
				// } else {
					// $total_weight = ($check[0]->max_no * $item->weight_percent) / 100;
				// }

				$total_weight = $item->weight_percent;

				$groups[$key] = array(
					'items' => array($item),
					'count' => 1,
					'columns' => $columns,
					'structure_id' => $item->structure_id,
					'form_id' => $item->form_id,
					'form_url' => $item->app_url,
					'is_value_get_zero' => $item->is_value_get_zero,
					'nof_target_score' => $item->nof_target_score,
					'total_weight' => $total_weight,
					'no_weight' => $item->no_weight,
					'threshold' => $config->threshold,
					'threshold_color' => $tc,
					'is_no_raise_value' => $item->is_no_raise_value,
					'no_raise_value' => $item->no_raise_value
				);
			} else {
				$groups[$key]['items'][] = $item;
				$groups[$key]['count'] += 1;
			}
		}
	//	$resultT = $items->toArray();
	//	$items['group'] = $groups
		$to_action = $this->advanSearch->to_action_call((object)$request->obj_stage);
		return response()->json(['data' => $items, 'group' => $groups, 'result_type' => $config->result_type, 'to_action' => $to_action]);

	}

	public function store(Request $request)
	{
		$errors = array();
		$semp_code = array();

		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}

		Config::set('mail.driver',$config->mail_driver);
		Config::set('mail.host',$config->mail_host);
		Config::set('mail.port',$config->mail_port);
		Config::set('mail.encryption',$config->mail_encryption);
		Config::set('mail.username',$config->mail_username);
		Config::set('mail.password',$config->mail_password);
		$from = Config::get('mail.from');
		// hr cannot assign
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
		// if (empty($is_hr)) {
			// return response()->json(['status' => 400, 'data' => ['Invalid action for HR.']]);
		// }


		// if ($request->head_params['action_to'] > 16) {
			// return response()->json(['status' => 400, 'data' => ['Invalid action.']]);
			// // if ($request->head_params['action_to'] == 17 || $request->head_params['action_to'] == 25 || $request->head_params['action_to'] == 29) {
			// // } else {
				// // return response()->json(['status' => 400, 'data' => ['Invalid action.']]);
			// // }
		// }

		// เอาข้อมูล จาก job_code ลง table emp_result โดย appraisal_form_id นั้นจะต้องมี is_raise เท่ากับ 1
		$job_code_query = "
			SELECT
				emp.emp_id
				, pos.position_code
				, CASE WHEN app.is_raise = 1 THEN job.knowledge_point ELSE 0 END AS knowledge_point
				, CASE WHEN app.is_raise = 1 THEN job.capability_point ELSE 0 END AS capability_point
				, CASE WHEN app.is_raise = 1 THEN job.total_point ELSE 0 END AS total_point
				, CASE WHEN app.is_raise = 1 THEN job.baht_per_point ELSE 0 END AS baht_per_point
				, CASE WHEN app.is_raise = 1 THEN emp.pqpi_amount ELSE 0 END AS pqpi_amount
				, CASE WHEN app.is_raise = 1 THEN emp.fix_other_amount ELSE 0 END AS fix_other_amount
				, CASE WHEN app.is_raise = 1 THEN emp.mpi_amount ELSE 0 END AS mpi_amount
				, CASE WHEN app.is_raise = 1 THEN emp.pi_amount ELSE 0 END AS pi_amount
				, CASE WHEN app.is_raise = 1 THEN emp.var_other_amount ELSE 0 END AS var_other_amount
			FROM
				employee emp
				INNER JOIN position pos ON emp.position_id = pos.position_id
				INNER JOIN job_code job ON pos.job_code = job.job_code
				CROSS JOIN ( SELECT appraisal_form_id, is_raise FROM appraisal_form WHERE appraisal_form_id = ? ) app 
			WHERE
				emp.emp_id = ? ";

				
		$validator = Validator::make($request->head_params, [
			'appraisal_type_id' => 'required',
			'appraisal_year' => 'required',
			'frequency_id' => 'required',
			'action_to' => 'required',
			'appraisal_form_id' => 'required'
		]);

		if ($validator->fails()) {
			$errors[] = ['item_id' => '', 'item_name' => '', 'data' => $validator->errors()];
		}

		$frequency = AppraisalFrequency::find($request->head_params['frequency_id']);

		if (empty($frequency)) {
			return response()->json(['status' => 400, 'data' => ['Frequency not found.']]);
		}

		//$period_count = 12 / $frequency->frequency_month_value;

		$period_errors = array();

		if (empty($request->head_params['period_id'])) {
			// foreach (range(1,$period_count,1) as $p) {
				// $appraisal_period = AppraisalPeriod::where('appraisal_year',$request->head_params['appraisal_year'])->where('period_no',$p)->where('appraisal_frequency_id',$request->head_params['frequency_id']);
				// if ($appraisal_period->count() == 0) {
					// $period_errors[] = 'Appraisal Period not found for Appraisal Year: ' . $request->head_params['appraisal_year'] . ' Period Number: ' . $p . ' Appraisal Frequency ID: ' . $request->head_params['frequency_id'];
				// }
			// }

			// if (!empty($period_errors)) {
				// return response()->json(['status' => 400, 'data' => $period_errors]);
			//}

			$period_check = DB::select("
				select period_id
				from appraisal_period
				where appraisal_year = ?
				and appraisal_frequency_id = ?
				", array($request->head_params['appraisal_year'], $request->head_params['frequency_id']));

			if (empty($period_check)) {
				return response()->json(['status' => 400, 'data' => ['Appraisal Period not found for Appraisal Year: ' . $request->head_params['appraisal_year'] . ' Appraisal Frequency ID: ' . $request->head_params['frequency_id']]]);
			}



		} else {
			$appraisal_period = AppraisalPeriod::where('appraisal_year',$request->head_params['appraisal_year'])->where('period_id',$request->head_params['period_id'])->where('appraisal_frequency_id',$request->head_params['frequency_id']);
			if ($appraisal_period->count() == 0) {
				$period_errors[] = 'Appraisal Period not found for Appraisal Year: ' . $request->head_params['appraisal_year'] . ' Period ID: ' . $request->head_params['period_id'] . ' Appraisal Frequency ID: ' . $request->head_params['frequency_id'];
				return response()->json(['status' => 400, 'data' => $period_errors]);
			}

		}



		foreach ($request->appraisal_items as $i) {
			if (array_key_exists ( 'form_id' , $i ) == false) {
				$i['form_id'] = 0;
			}

			if ($i['form_id'] == 1) {
				if (array_key_exists ( 'nof_target_score' , $i ) == false) {
					$i['nof_target_score'] = 0;
				}
				if ($i['nof_target_score'] == 1) {
					$validator = Validator::make($i, [
						'item_id' => 'required|integer',
						'target_value' => 'required|numeric',
					//	'weight_percent' => 'required|numeric',
					]);
					if ($validator->fails()) {
						$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
					}

				} elseif ($i['nof_target_score'] == 2) {
					$validator = Validator::make($i, [
						'item_id' => 'required|integer',
						'target_value' => 'required|numeric',
					//	'weight_percent' => 'required|numeric',
					]);
					if ($validator->fails()) {
						$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
					}

				} elseif ($i['nof_target_score'] == 3) {
					$validator = Validator::make($i, [
						'item_id' => 'required|integer',
						'target_value' => 'required|numeric',
					//	'weight_percent' => 'required|numeric',
					]);
					if ($validator->fails()) {
						$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
					}

				} elseif ($i['nof_target_score'] == 4) {
					$validator = Validator::make($i, [
						'item_id' => 'required|integer',
						'target_value' => 'required|numeric',
					//	'weight_percent' => 'required|numeric',
					]);
					if ($validator->fails()) {
						$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
					}

				} elseif ($i['nof_target_score'] == 5) {
					$validator = Validator::make($i, [
						'item_id' => 'required|integer',
						'target_value' => 'required|numeric',
					//	'weight_percent' => 'required|numeric',
					]);
					if ($validator->fails()) {
						$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
					}
				}
				else {
				//	$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => 'Invalid Number of Target Score.'];
					$validator = Validator::make($i, [
						'item_id' => 'required|integer',
						'target_value' => 'required|numeric',
					//	'weight_percent' => 'required|numeric',
					]);
					if ($validator->fails()) {
						$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
					}
				}

			} elseif ($i['form_id'] == 2) {

				$validator = Validator::make($i, [
					'item_id' => 'required|integer',
					'target_value' => 'required|numeric',
				//	'weight_percent' => 'required|numeric',
				]);

				if ($validator->fails()) {
					$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
				}

			} elseif ($i['form_id'] == 3) {

				$no = DB::select("
					select s.is_no_raise_value from appraisal_item i 
					inner join appraisal_structure s on i.structure_id = s.structure_id
					where i.item_id = ?"
				,array($i['item_id'])); // $request->item_id

				// return ($no[0]->is_no_raise_value);

				if ($no[0]->is_no_raise_value == 1){
					$validator = Validator::make($i, [
						'item_id' => 'required|integer',
						'max_value' => 'required|numeric',
						'deduct_score_unit' => 'required|numeric',
						'no_raise_value' => 'required|numeric',
					]);
				}else {
					$validator = Validator::make($i, [
						'item_id' => 'required|integer',
						'max_value' => 'required|numeric',
						'deduct_score_unit' => 'required|numeric'
					]);
				}	

				if ($validator->fails()) {
					$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
				}

			} elseif ($i['form_id'] == 4) {
				$validator = Validator::make($i, [
					'item_id' => 'required|integer',
					'max_value' => 'required|numeric',
					'reward_score_unit' => 'required|numeric',
				]);
				if ($validator->fails()) {
					$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
				}

			} else {
				$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => 'Invalid Form.'];
			}
		}

		if (count($errors) > 0) {
			return response()->json(['status' => 400, 'data' => $errors]);
		}

		if (empty($request->employees)) {
			return response()->json(['status' => 200, 'data' => []]);
		}

		$already_assigned = array();

		foreach ($request->employees as $e) {
			// $check_unassign = DB::select("
				// select emp_code
				// from emp_result
				// where emp_code = ?
			// ", array($e['emp_code']));



			if (empty($request->head_params['period_id'])) {
				foreach ($period_check as $p) {
					// $appraisal_period = AppraisalPeriod::where('appraisal_year',$request->head_params['appraisal_year'])->where('period_no',$p)->where('appraisal_frequency_id',$request->head_params['frequency_id']);
					$period_id = $p->period_id;
					$qinput = array();

					if ($request->head_params['appraisal_type_id'] == 2) {

						$query_unassign = "
						select emp_id
						from emp_result
						where emp_id = ?
						and org_id = ?
						and position_id = ?
						and period_id = ?
						and appraisal_type_id = 2
						";
						$qinput[] = $e['emp_id'];
						$qinput[] = $e['org_id'];
						$qinput[] = $e['position_id'];

					} else {
						$query_unassign = "
						select org_id
						from emp_result
						where org_id = ?
						and period_id = ?
						and appraisal_type_id = 1
						";
						$qinput[] = $e['org_id'];
					}

					$qinput[] = $period_id;
				//	empty($request->position_code) ?: ($query_unassign .= " and e.position_code = ? " AND $qinput[] = $request->position_code);
				//	empty($request->emp_code) ?: ($query_unassign .= " and e.emp_code = ? " AND $qinput[] = $request->emp_code);
				//	empty($request->appraisal_level_id) ?: ($query_unassign .= " and I.appraisal_level_id = ? " AND $qinput[] = $request->appraisal_level_id);
					//empty($request->head_params['appraisal_type_id']) ?: ($query_unassign .= " and appraisal_type_id = ? " AND $qinput[] = $request->head_params['appraisal_type_id']);

					$check_unassign = DB::select($query_unassign,$qinput);
					$rtg_id = ResultThresholdGroup::where('is_active',1)->first();
					empty($rtg_id) ? $rtg_id = null : $rtg_id = $rtg_id->result_threshold_group_id;
					if (empty($check_unassign)) {
						$stage = WorkflowStage::find($request->head_params['action_to']);

						if ($request->head_params['appraisal_type_id'] == 2) {
							$employee = Employee::find($e['emp_code']);
							if (empty($employee)) {
								$chief_emp_code = null;
								$chief_emp_id = null;
								$level_id = null;
								$org_id = null;
								$position_id = null;
								$emp_id = null;
							} else {
								$chief_emp_code = $employee->chief_emp_code;
								$chief_emp_id = Employee::where('emp_code',$chief_emp_code)->first();
								empty($chief_emp_id) ? $chief_emp_id = null : $chief_emp_id = $chief_emp_id->emp_id;
								$level_id = $employee->level_id;
								$org_id = $employee->org_id;
								$position_id = $employee->position_id;
								$emp_id = $e['emp_id'];
							}
						} else {
							$org = Org::find($e['org_id']);
							$chief_emp_code = null;
							$chief_emp_id = null;
							$level_id = $org->level_id;
							$org_id = $e['org_id'];
							$position_id = null;
							$emp_id = null;
						}
						
						$job_code_data = DB::select($job_code_query, array($request->head_params['appraisal_form_id'], $emp_id));
						
						$emp_result = new EmpResult;
						$emp_result->appraisal_type_id = $request->head_params['appraisal_type_id'];
						$emp_result->appraisal_form_id = $request->head_params['appraisal_form_id'];
						$emp_result->period_id = $period_id;
						$emp_result->emp_id = $emp_id;
						$emp_result->level_id = $level_id;
						$emp_result->org_id = $org_id;
						$emp_result->position_id = $position_id;
						$emp_result->chief_emp_id = $chief_emp_id;
						$emp_result->result_threshold_group_id = $rtg_id;
						$emp_result->result_score = 0;
						$emp_result->b_rate = 0;
						$emp_result->b_amount = 0;
						$emp_result->grade = null;
						$emp_result->knowledge_point = (empty($job_code_data[0]->knowledge_point)) ? 0 :$job_code_data[0]->knowledge_point;
						$emp_result->capability_point = (empty($job_code_data[0]->capability_point)) ? 0 :$job_code_data[0]->capability_point;
						$emp_result->total_point = (empty($job_code_data[0]->total_point)) ? 0 :$job_code_data[0]->total_point;
						$emp_result->baht_per_point = (empty($job_code_data[0]->baht_per_point)) ? 0 :$job_code_data[0]->baht_per_point;
						$emp_result->pqpi_amount = (empty($job_code_data[0]->pqpi_amount)) ? null :$job_code_data[0]->pqpi_amount;
						$emp_result->fix_other_amount = (empty($job_code_data[0]->fix_other_amount)) ? null :$job_code_data[0]->fix_other_amount;
						$emp_result->mpi_amount = (empty($job_code_data[0]->mpi_amount)) ? null :$job_code_data[0]->mpi_amount;
						$emp_result->pi_amount = (empty($job_code_data[0]->pi_amount)) ? null :$job_code_data[0]->pi_amount;
						$emp_result->var_other_amount = (empty($job_code_data[0]->var_other_amount)) ? null :$job_code_data[0]->var_other_amount;
						$emp_result->raise_amount = 0;
						$emp_result->new_s_amount = 0;
						$emp_result->status = $stage->status;
						$emp_result->stage_id = $stage->stage_id;
						$emp_result->created_by = Auth::id();
						$emp_result->updated_by = Auth::id();
						if ( ! empty($emp_result->emp_id)) {
							$emp_result->s_amount = $employee->s_amount;
							$emp_result->pqpi_amount = $employee->pqpi_amount;
						}
						$emp_result->save();

						$emp_stage = new EmpResultStage;
						$emp_stage->emp_result_id = $emp_result->emp_result_id;
						$emp_stage->stage_id = $stage->stage_id;
						$emp_stage->remark = $request->head_params['remark'];;
						$emp_stage->created_by = Auth::id();
						$emp_stage->updated_by = Auth::id();
						$emp_stage->save();

						$mail_error = '';
						if ($config->email_reminder_flag == 1) {
							if ($request->head_params['appraisal_type_id'] == 2) {
								try {
									$chief_emp = Employee::where('emp_code',$employee->chief_emp_code)->first();

									$data = ["chief_emp_name" => $chief_emp->emp_name, "emp_name" => $employee->emp_name, "status" => $stage->status, "web_domain" => $config->web_domain, 'emp_result_id' => $emp_result->emp_result_id, 'appraisal_type_id' => $emp_result->appraisal_type_id, 'assignment_flag' => $stage->assignment_flag];
									$to = [$employee->email, $chief_emp->email];

									//$from = $config->mail_username;

									Mail::send('emails.status', $data, function($message) use ($from, $to)
									{
										$message->from($from['address'], $from['name']);
										$message->to($to)->subject('ระบบได้ทำการประเมิน');
									});
								} catch (Exception $ExceptionError) {
								//	$mail_error[] = $e->getMessage();
									$mail_error = 'has error';
								}
							} else if($request->head_params['appraisal_type_id'] == 1) {
								try {

									$data = ["chief_emp_name" => $org->org_name, "emp_name" => $org->org_name, "status" => $stage->status, "web_domain" => $config->web_domain, 'emp_result_id' => $emp_result->emp_result_id, 'appraisal_type_id' => $emp_result->appraisal_type_id, 'assignment_flag' => $stage->assignment_flag];
									$to = [$org->org_email];

									//$from = $config->mail_username;

									Mail::send('emails.status', $data, function($message) use ($from, $to)
									{
										$message->from($from['address'], $from['name']);
										$message->to($to)->subject('ระบบได้ทำการประเมิน');
									});
								} catch (Exception $ExceptionError) {
								//	$mail_error[] = $e->getMessage();
									$mail_error = 'has error';
								}
							}
						}

						$semp_code[] = ['emp_id' => $e['emp_id'], 'org_id' => $org_id, 'period_id' => $period_id, 'mail_error' => $mail_error];

						$tg_id = ThresholdGroup::where('is_active',1)->first();
						empty($tg_id) ? $tg_id = null : $tg_id = $tg_id->threshold_group_id;

						foreach ($request->appraisal_items as $i) {
							if ($i['form_id'] == 1) {
								$aitem = new AppraisalItemResult;
								$aitem->emp_result_id = $emp_result->emp_result_id;
								$aitem->appraisal_form_id = $request->head_params['appraisal_form_id'];
								$aitem->kpi_type_id = $i['kpi_type_id'];
								$aitem->period_id = $period_id;
								$aitem->emp_id = $emp_id;
								$aitem->chief_emp_id = $chief_emp_id;
								$aitem->level_id = $level_id;
								$aitem->org_id = $org_id;
								$aitem->position_id = $position_id;
								$aitem->item_id = $i['item_id'];
								$aitem->item_name = $i['item_name'];
								$aitem->target_value = $i['target_value'];
								$aitem->weight_percent = $i['weight_percent'];
								array_key_exists('score0', $i) ? $aitem->score0 = $i['score0'] : null;
								array_key_exists('score1', $i) ? $aitem->score1 = $i['score1'] : null;
								array_key_exists('score2', $i) ? $aitem->score2 = $i['score2'] : null;
								array_key_exists('score3', $i) ? $aitem->score3 = $i['score3'] : null;
								array_key_exists('score4', $i) ? $aitem->score4 = $i['score4'] : null;
								array_key_exists('score5', $i) ? $aitem->score5 = $i['score5'] : null;
								array_key_exists('forecast_value', $i) ? $aitem->forecast_value = $i['forecast_value'] : null;
								$aitem->over_value = 0;
								$aitem->weigh_score = 0;
								$aitem->structure_weight_percent = $i['total_weight'];
								$aitem->threshold_group_id = $tg_id;
								$aitem->contribute_percent = 100;
								$aitem->derive_item_result_id = $i['item_result_id_derive'];
								$aitem->created_by = Auth::id();
								$aitem->updated_by = Auth::id();
								$aitem->save();

							} elseif ($i['form_id'] == 2) {

								$aitem = new AppraisalItemResult;
								$aitem->emp_result_id = $emp_result->emp_result_id;
								$aitem->appraisal_form_id = $request->head_params['appraisal_form_id'];
								$aitem->period_id = $period_id;
								$aitem->emp_id = $emp_id;
								$aitem->chief_emp_id = $chief_emp_id;
								$aitem->level_id = $level_id;
								$aitem->org_id = $org_id;
								$aitem->position_id = $position_id;
								$aitem->item_id = $i['item_id'];
								$aitem->item_name = $i['item_name'];
								$aitem->target_value = $i['target_value'];
								$aitem->weight_percent = $i['weight_percent'];
								$aitem->over_value = 0;
								$aitem->weigh_score = 0;
								$aitem->structure_weight_percent = $i['total_weight'];
								$aitem->threshold_group_id = $tg_id;
								$aitem->contribute_percent = 100;
								$aitem->created_by = Auth::id();
								$aitem->updated_by = Auth::id();
								$aitem->save();

							} elseif ($i['form_id'] == 3) {

								$aitem = new AppraisalItemResult;
								$aitem->emp_result_id = $emp_result->emp_result_id;
								$aitem->appraisal_form_id = $request->head_params['appraisal_form_id'];
								$aitem->period_id = $period_id;
								$aitem->emp_id = $emp_id;
								$aitem->chief_emp_id = $chief_emp_id;
								$aitem->level_id = $level_id;
								$aitem->org_id = $org_id;
								$aitem->position_id = $position_id;
								$aitem->item_id = $i['item_id'];
								$aitem->item_name = $i['item_name'];
								$aitem->max_value = $i['max_value'];
								$aitem->deduct_score_unit = $i['deduct_score_unit'];
								$aitem->weight_percent = 0;
								$aitem->over_value = 0;
								$aitem->weigh_score = 0;
								$aitem->structure_weight_percent = $i['total_weight'];
								$aitem->threshold_group_id = $tg_id;
								$aitem->no_raise_value =  $i['no_raise_value']; // $request->no_raise_value;
								$aitem->contribute_percent = 100;
								$aitem->created_by = Auth::id();
								$aitem->updated_by = Auth::id();
								$aitem->save();

							}elseif ($i['form_id'] == 4) {
								$aitem = new AppraisalItemResult;
								$aitem->emp_result_id = $emp_result->emp_result_id;
								$aitem->appraisal_form_id = $request->head_params['appraisal_form_id'];
								$aitem->period_id = $period_id;
								$aitem->emp_id = $emp_id;
								$aitem->chief_emp_id = $chief_emp_id;
								$aitem->level_id = $level_id;
								$aitem->org_id = $org_id;
								$aitem->position_id = $position_id;
								$aitem->item_id = $i['item_id'];
								$aitem->item_name = $i['item_name'];
								$aitem->max_value = $i['max_value'];
								$aitem->reward_score_unit = $i['reward_score_unit'];
								$aitem->weight_percent = 0;
								$aitem->over_value = 0;
								$aitem->weigh_score = 0;
								$aitem->structure_weight_percent = $i['total_weight'];
								$aitem->threshold_group_id = $tg_id;
								$aitem->contribute_percent = 100;
								$aitem->created_by = Auth::id();
								$aitem->updated_by = Auth::id();
								$aitem->save();
							}
						}
					} else {
						$already_assigned = ['emp_id' => $e['emp_id'], 'org_id' => $e['org_id'], 'period_id' => $period_id];
					}
				}
			} else {
				$appraisal_period = AppraisalPeriod::where('appraisal_year',$request->head_params['appraisal_year'])->where('period_id',$request->head_params['period_id'])->where('appraisal_frequency_id',$request->head_params['frequency_id']);
				$period_id = $appraisal_period->first()->period_id;
				$qinput = array();

				if ($request->head_params['appraisal_type_id'] == 2) {
					$query_unassign = "
					select emp_id
					from emp_result
					Where emp_id = ?
					and org_id = ?
					and position_id = ?
					and period_id = ?
					and appraisal_type_id = 2
					";
					$qinput[] = $e['emp_id'];
					$qinput[] = $e['org_id'];
					$qinput[] = $e['position_id'];
				} else {
					$query_unassign = "
					select org_id
					from emp_result
					Where org_id = ?
					and period_id = ?
					and appraisal_type_id = 1
					";
					$qinput[] = $e['org_id'];
				}

				$qinput[] = $period_id;
				//empty($request->position_code) ?: ($query_unassign .= " and e.position_code = ? " AND $qinput[] = $request->position_code);
				//empty($request->emp_code) ?: ($query_unassign .= " and e.emp_code = ? " AND $qinput[] = $request->emp_code);
				//empty($request->appraisal_level_id) ?: ($query_unassign .= " and I.appraisal_level_id = ? " AND $qinput[] = $request->appraisal_level_id);
				//empty($request->head_params['appraisal_type_id']) ?: ($query_unassign .= " and appraisal_type_id = ? " AND $qinput[] =  $request->head_params['appraisal_type_id']);

				$check_unassign = DB::select($query_unassign,$qinput);
				$rtg_id = ResultThresholdGroup::where('is_active',1)->first();
				empty($rtg_id) ? $rtg_id = null : $rtg_id = $rtg_id->result_threshold_group_id;
				if (empty($check_unassign)) {
					$stage = WorkflowStage::find($request->head_params['action_to']);

					if ($request->head_params['appraisal_type_id'] == 2) {
						$employee = Employee::find($e['emp_code']);
						if (empty($employee)) {
							$chief_emp_code = null;
							$chief_emp_id = null;
							$level_id = null;
							$org_id = null;
							$position_id = null;
							$emp_id = null;
						} else {
							$chief_emp_code = $employee->chief_emp_code;
							$chief_emp_id = Employee::where('emp_code',$chief_emp_code)->first();
							empty($chief_emp_id) ? $chief_emp_id = null : $chief_emp_id = $chief_emp_id->emp_id;
							$level_id = $employee->level_id;
							$org_id = $employee->org_id;
							$position_id = $employee->position_id;
							$emp_id = $e['emp_id'];
						}
					} else {
						$org = Org::find($e['org_id']);
						$chief_emp_code = null;
						$chief_emp_id = null;
						$level_id = $org->level_id;
						$org_id = $e['org_id'];
						$position_id = null;
						$emp_id = null;
					}
					
					$job_code_data = DB::select($job_code_query, array($request->head_params['appraisal_form_id'], $emp_id));

					$emp_result = new EmpResult; 
					// $emp_result->appraisal_type_id = $request->head_params['appraisal_type_id'];
					// $emp_result->period_id = $period_id;
					// $emp_result->emp_code = $e['emp_code'];
					// $emp_result->department_code = $employee->department_code;
					// $emp_result->department_name = $employee->department_name;
					// $emp_result->section_code = $employee->section_code;
					// $emp_result->section_name = $employee->section_name;
					// $emp_result->position_code = $employee->position_code;
					// $emp_result->position_name = $employee->position_name;
					// $emp_result->chief_emp_code = $chief_emp_code;
					// $emp_result->result_score = 0;
					// $emp_result->b_rate = 0;
					// $emp_result->b_amount = 0;
					// $emp_result->grade = null;
					// $emp_result->raise_amount = 0;
					// $emp_result->new_s_amount = 0;
					// $emp_result->status = $stage->status;
					// $emp_result->stage_id = $stage->stage_id;
					// $emp_result->created_by = Auth::id();
					// $emp_result->updated_by = Auth::id();
					// $emp_result->save();
					
					$emp_result->appraisal_type_id = $request->head_params['appraisal_type_id'];
					$emp_result->appraisal_form_id = $request->head_params['appraisal_form_id'];
					$emp_result->period_id = $period_id;
					$emp_result->emp_id = $emp_id;
					$emp_result->level_id = $level_id;
					$emp_result->org_id = $org_id;
					$emp_result->position_id = $position_id;
					$emp_result->chief_emp_id = $chief_emp_id;
					$emp_result->result_threshold_group_id = $rtg_id;
					$emp_result->result_score = 0;
					$emp_result->b_rate = 0;
					$emp_result->b_amount = 0;
					$emp_result->grade = null;
					$emp_result->raise_amount = 0;
					$emp_result->new_s_amount = 0;
					$emp_result->status = $stage->status;
					$emp_result->knowledge_point = (empty($job_code_data[0]->knowledge_point)) ? 0 :$job_code_data[0]->knowledge_point;
					$emp_result->capability_point = (empty($job_code_data[0]->capability_point)) ? 0 :$job_code_data[0]->capability_point;
					$emp_result->total_point = (empty($job_code_data[0]->total_point)) ? 0 :$job_code_data[0]->total_point;
					$emp_result->baht_per_point = (empty($job_code_data[0]->baht_per_point)) ? 0 :$job_code_data[0]->baht_per_point;
					$emp_result->pqpi_amount = (empty($job_code_data[0]->pqpi_amount)) ? null :$job_code_data[0]->pqpi_amount;
					$emp_result->fix_other_amount = (empty($job_code_data[0]->fix_other_amount)) ? null :$job_code_data[0]->fix_other_amount;
					$emp_result->mpi_amount = (empty($job_code_data[0]->mpi_amount)) ? null :$job_code_data[0]->mpi_amount;
					$emp_result->pi_amount = (empty($job_code_data[0]->pi_amount)) ? null :$job_code_data[0]->pi_amount;
					$emp_result->var_other_amount = (empty($job_code_data[0]->var_other_amount)) ? null :$job_code_data[0]->var_other_amount;
					$emp_result->stage_id = $stage->stage_id;
					$emp_result->created_by = Auth::id();
					$emp_result->updated_by = Auth::id();
					if ( ! empty($emp_result->emp_id)) {
						$emp_result->s_amount = $employee->s_amount;
						$emp_result->pqpi_amount = $employee->pqpi_amount;
					}
					$emp_result->save();

					$emp_stage = new EmpResultStage;
					$emp_stage->emp_result_id = $emp_result->emp_result_id;
					$emp_stage->stage_id = $stage->stage_id;
					$emp_stage->remark = $request->head_params['remark'];
					$emp_stage->created_by = Auth::id();
					$emp_stage->updated_by = Auth::id();
					$emp_stage->save();

					$mail_error = '';

					if ($config->email_reminder_flag == 1) {
						if ($request->head_params['appraisal_type_id'] == 2) {
							try {
								$chief_emp = Employee::where('emp_code',$employee->chief_emp_code)->first();

								$data = ["chief_emp_name" => $chief_emp->emp_name, "emp_name" => $employee->emp_name, "status" => $stage->status, "web_domain" => $config->web_domain, 'emp_result_id' => $emp_result->emp_result_id, 'appraisal_type_id' => $emp_result->appraisal_type_id, 'assignment_flag' => $stage->assignment_flag];
								$to = [$employee->email, $chief_emp->email];

								//$from = $config->mail_username;

								Mail::send('emails.status', $data, function($message) use ($from, $to)
								{
									$message->from($from['address'], $from['name']);
									$message->to($to)->subject('ระบบได้ทำการประเมิน');
								});
							} catch (Exception $ExceptionError) {
								//$mail_error = $e->getMessage();

								//return response()->json($e->getMessage());
								$mail_error = 'has error';
							}
						} else if ($request->head_params['appraisal_type_id'] == 1) {
							try {

								$data = ["chief_emp_name" => $org->org_name, "emp_name" => $org->org_name, "status" => $stage->status, "web_domain" => $config->web_domain, 'emp_result_id' => $emp_result->emp_result_id, 'appraisal_type_id' => $emp_result->appraisal_type_id, 'assignment_flag' => $stage->assignment_flag];
								$to = [$org->org_email];

								//$from = $config->mail_username;

								Mail::send('emails.status', $data, function($message) use ($from, $to)
								{
									$message->from($from['address'], $from['name']);
									$message->to($to)->subject('ระบบได้ทำการประเมิน');
								});
							} catch (Exception $ExceptionError) {
								//$mail_error = $e->getMessage();

								//return response()->json($e->getMessage());
								$mail_error = 'has error';
							}
						}
					}

					$semp_code[] = ['emp_id' => $e['emp_id'], 'org_id' => $org_id, 'period_id' => $period_id, 'mail_error' => $mail_error];

					$tg_id = ThresholdGroup::where('is_active',1)->first();
					empty($tg_id) ? $tg_id = null : $tg_id = $tg_id->threshold_group_id;

					foreach ($request->appraisal_items as $i) {
						if ($i['form_id'] == 1) {

							$aitem = new AppraisalItemResult;
							$aitem->emp_result_id = $emp_result->emp_result_id;
							$aitem->appraisal_form_id = $request->head_params['appraisal_form_id'];
							$aitem->kpi_type_id = $i['kpi_type_id'];
							$aitem->period_id = $period_id;
							$aitem->emp_id = $emp_id;
							$aitem->chief_emp_id = $chief_emp_id;
							$aitem->org_id = $org_id;
							$aitem->position_id = $position_id;
							$aitem->level_id = $level_id;
							$aitem->item_id = $i['item_id'];
							$aitem->item_name = $i['item_name'];
							$aitem->target_value = $i['target_value'];
							$aitem->weight_percent = $i['weight_percent'];
							array_key_exists('score0', $i) ? $aitem->score0 = $i['score0'] : null;
							array_key_exists('score1', $i) ? $aitem->score1 = $i['score1'] : null;
							array_key_exists('score2', $i) ? $aitem->score2 = $i['score2'] : null;
							array_key_exists('score3', $i) ? $aitem->score3 = $i['score3'] : null;
							array_key_exists('score4', $i) ? $aitem->score4 = $i['score4'] : null;
							array_key_exists('score5', $i) ? $aitem->score5 = $i['score5'] : null;
							array_key_exists('forecast_value', $i) ? $aitem->forecast_value = $i['forecast_value'] : null;
							$aitem->over_value = 0;
							$aitem->weigh_score = 0;
							$aitem->threshold_group_id = $tg_id;
							$aitem->structure_weight_percent = $i['total_weight'];
							$aitem->contribute_percent = 100;
							$aitem->derive_item_result_id = $i['item_result_id_derive'];
							$aitem->created_by = Auth::id();
							$aitem->updated_by = Auth::id();
							$aitem->save();

						} elseif ($i['form_id'] == 2) {

							$aitem = new AppraisalItemResult;
							$aitem->emp_result_id = $emp_result->emp_result_id;
							$aitem->appraisal_form_id = $request->head_params['appraisal_form_id'];
							$aitem->period_id = $period_id;
							$aitem->emp_id = $emp_id;
							$aitem->chief_emp_id = $chief_emp_id;
							$aitem->org_id = $org_id;
							$aitem->position_id = $position_id;
							$aitem->level_id = $level_id;
							$aitem->item_id = $i['item_id'];
							$aitem->item_name = $i['item_name'];
							$aitem->target_value = $i['target_value'];
							$aitem->weight_percent = $i['weight_percent'];
							$aitem->over_value = 0;
							$aitem->weigh_score = 0;
							$aitem->threshold_group_id = $tg_id;
							$aitem->structure_weight_percent = $i['total_weight'];
							$aitem->contribute_percent = 100;
							$aitem->created_by = Auth::id();
							$aitem->updated_by = Auth::id();
							$aitem->save();

						} elseif ($i['form_id'] == 3) {

							$aitem = new AppraisalItemResult;
							$aitem->emp_result_id = $emp_result->emp_result_id;
							$aitem->appraisal_form_id = $request->head_params['appraisal_form_id'];
							$aitem->period_id = $period_id;
							$aitem->emp_id = $emp_id;
							$aitem->chief_emp_id = $chief_emp_id;
							$aitem->org_id = $org_id;
							$aitem->position_id = $position_id;
							$aitem->level_id = $level_id;
							$aitem->item_id = $i['item_id'];
							$aitem->item_name = $i['item_name'];
							$aitem->max_value = $i['max_value'];
							$aitem->deduct_score_unit = $i['deduct_score_unit'];
							$aitem->weight_percent = 0;
							$aitem->over_value = 0;
							$aitem->weigh_score = 0;
							$aitem->threshold_group_id = $tg_id;
							$aitem->structure_weight_percent = $i['total_weight'];
							$aitem->no_raise_value =  $i['no_raise_value']; // $request->no_raise_value;
							$aitem->contribute_percent = 100;
							$aitem->created_by = Auth::id();
							$aitem->updated_by = Auth::id();
							$aitem->save();

						} elseif ($i['form_id'] == 4) {
							$aitem = new AppraisalItemResult;
							$aitem->emp_result_id = $emp_result->emp_result_id;
							$aitem->appraisal_form_id = $request->head_params['appraisal_form_id'];
							$aitem->period_id = $period_id;
							$aitem->emp_id = $emp_id;
							$aitem->chief_emp_id = $chief_emp_id;
							$aitem->org_id = $org_id;
							$aitem->position_id = $position_id;
							$aitem->level_id = $level_id;
							$aitem->item_id = $i['item_id'];
							$aitem->item_name = $i['item_name'];
							$aitem->max_value = $i['max_value'];
							$aitem->reward_score_unit = $i['reward_score_unit'];
							$aitem->weight_percent = 0;
							$aitem->over_value = 0;
							$aitem->weigh_score = 0;
							$aitem->threshold_group_id = $tg_id;
							$aitem->structure_weight_percent = $i['total_weight'];
							$aitem->contribute_percent = 100;
							$aitem->created_by = Auth::id();
							$aitem->updated_by = Auth::id();
							$aitem->save();
						}
					}

				} else {
					$already_assigned[] = ['emp_id' => $e['emp_id'], 'org_id' => $e['org_id'], 'period_id' => $period_id];
				}

			}
		}

		return response()->json(['status' => 200, 'data' => $semp_code, 'already_assigned' => $already_assigned]);
	}


	public function show(Request $request, $emp_result_id)
	{
		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}
		$head = DB::select("
			SELECT b.emp_id, b.emp_code, b.emp_name, b.working_start_date, 
				h.position_name, g.org_name, g.org_code, pg.org_name parent_org_name, 
				b.chief_emp_code, e.emp_name chief_emp_name, c.period_id, c.appraisal_period_desc, 
				d.appraisal_type_name, a.stage_id, f.status, f.edit_flag, a.position_id, a.org_id,
				a.appraisal_form_id, fm.appraisal_form_name
			FROM emp_result a
			LEFT OUTER JOIN employee b ON a.emp_id = b.emp_id
			LEFT OUTER JOIN appraisal_period c ON c.period_id = a.period_id
			LEFT OUTER JOIN appraisal_type d ON a.appraisal_type_id = d.appraisal_type_id
			LEFT OUTER JOIN employee e ON b.chief_emp_code = e.emp_code
			LEFT OUTER JOIN appraisal_stage f ON a.stage_id = f.stage_id
			LEFT OUTER JOIN org g ON a.org_id = g.org_id
			LEFT OUTER JOIN org pg ON g.parent_org_code = pg.org_code
			LEFT OUTER JOIN position h ON a.position_id = h.position_id
			LEFT OUTER JOIN appraisal_form fm ON fm.appraisal_form_id = a.appraisal_form_id
			WHERE a.emp_result_id = ?
			", array($emp_result_id));

		$items = DB::select("
			select b.item_name, b.structure_id, a.*
			from appraisal_item_result a
			left outer join appraisal_item b
			on a.item_id = b.item_id
			where a.emp_result_id = ?
			", array($emp_result_id));

		$stage = DB::select("
			SELECT a.created_by, a.created_dttm, b.from_action, b.to_action, a.remark
			FROM emp_result_stage a
			left outer join appraisal_stage b
			on a.stage_id = b.stage_id
			where a.emp_result_id = ?
			order by a.created_dttm asc
			", array($emp_result_id));

		return response()->json(['head' => $head, 'data' => $items, 'stage' => $stage, 'threshold' => $config->threshold]);
	}

	public function update(Request $request, $emp_result_id)
	{
		$errors = array();

		try {
			$config = SystemConfiguration::firstOrFail();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'System Configuration not found in DB.']);
		}
	
		Config::set('mail.driver',$config->mail_driver);
		Config::set('mail.host',$config->mail_host);
		Config::set('mail.port',$config->mail_port);
		Config::set('mail.encryption',$config->mail_encryption);
		Config::set('mail.username',$config->mail_username);
		Config::set('mail.password',$config->mail_password);
		$from = Config::get('mail.from');

		// if ($request->head_params['action_to'] > 16) {
			// if ($request->head_params['action_to'] == 17 || $request->head_params['action_to'] == 25 || $request->head_params['action_to'] == 29) {
			// } else {
				// return response()->json(['status' => 400, 'data' => 'Invalid action.']);
			// }
		// }

		$validator = Validator::make($request->head_params, [
			'appraisal_type_id' => 'required',
			'period_id' => 'required',
			'action_to' => 'required'
		]);

		if ($validator->fails()) {
			$errors[] = ['item_id' => '', 'item_name' => '', 'data' => $validator->errors()];
		}

		foreach ($request->appraisal_items as $i) {

			if ($i['select_flag'] == 1) {
				if (array_key_exists ( 'form_id' , $i ) == false) {
					$i['form_id'] = 0;
				}

				if ($i['form_id'] == 1) {
					if (array_key_exists ( 'nof_target_score' , $i ) == false) {
						$i['nof_target_score'] = 0;
					}
					if ($i['nof_target_score'] == 1) {
						$validator = Validator::make($i, [
							'item_id' => 'required|integer',
							'target_value' => 'required|numeric',
						//	'weight_percent' => 'required|numeric',
						]);
						if ($validator->fails()) {
							$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
						}

					} elseif ($i['nof_target_score'] == 2) {
						$validator = Validator::make($i, [
							'item_id' => 'required|integer',
							'target_value' => 'required|numeric',
						//	'weight_percent' => 'required|numeric',
						]);
						if ($validator->fails()) {
							$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
						}

					} elseif ($i['nof_target_score'] == 3) {
						$validator = Validator::make($i, [
							'item_id' => 'required|integer',
							'target_value' => 'required|numeric',
						//	'weight_percent' => 'required|numeric',
						]);
						if ($validator->fails()) {
							$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
						}

					} elseif ($i['nof_target_score'] == 4) {
						$validator = Validator::make($i, [
							'item_id' => 'required|integer',
							'target_value' => 'required|numeric',
						//	'weight_percent' => 'required|numeric',
						]);
						if ($validator->fails()) {
							$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
						}

					} elseif ($i['nof_target_score'] == 5) {
						$validator = Validator::make($i, [
							'item_id' => 'required|integer',
							'target_value' => 'required|numeric',
						//	'weight_percent' => 'required|numeric',
						]);
						if ($validator->fails()) {
							$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
						}
					}  else {
						// $errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => 'Invalid Number of Target Score.'];
						$validator = Validator::make($i, [
							'item_id' => 'required|integer',
							'target_value' => 'required|numeric',
						//	'weight_percent' => 'required|numeric',
						]);
						if ($validator->fails()) {
							$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
						}
					}

				} elseif ($i['form_id'] == 2) {

					$validator = Validator::make($i, [
						'item_id' => 'required|integer',
						'target_value' => 'required|numeric',
					//	'weight_percent' => 'required|numeric',
					]);

					if ($validator->fails()) {
						$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
					}

				} elseif ($i['form_id'] == 3) {

					$validator = Validator::make($i, [
						'item_id' => 'required|integer',
						'max_value' => 'required|numeric',
						'deduct_score_unit' => 'required|numeric',
					]);

					if ($validator->fails()) {
						$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => $validator->errors()];
					}

				} else {
					$errors[] = ['item_id' => $i['item_id'], 'item_name' => $i['item_name'], 'data' => 'Invalid Form.'];
				}
			} else {
				// select flag false
			}
		}

		if (count($errors) > 0) {
			return response()->json(['status' => 400, 'data' => $errors]);
		}

		$stage = WorkflowStage::find($request->head_params['action_to']);
		$emp_result = EmpResult::find($emp_result_id);
		$emp_result->status = $stage->status;
		$emp_result->stage_id = $stage->stage_id;
		$emp_result->updated_by = Auth::id();
		$emp_result->save();

		$emp_stage = new EmpResultStage;
		$emp_stage->emp_result_id = $emp_result->emp_result_id;
		$emp_stage->stage_id = $stage->stage_id;
		$emp_stage->remark = $request->head_params['remark'];
		$emp_stage->created_by = Auth::id();
		$emp_stage->updated_by = Auth::id();
		$emp_stage->save();

		$mail_error = '';

		if ($emp_result->appraisal_type_id == 2) {
			$employee = Employee::where('emp_id',$emp_result->emp_id)->first();
			if (empty($employee)) {
				$chief_emp_code = null;
				$chief_emp_id = null;
				$position_id = null;
				$level_id = null;
				$org_id = null;
			} else {
				$chief_emp_code = $employee->chief_emp_code;
				$chief_emp_id = Employee::where('emp_code',$chief_emp_code)->first();
				empty($chief_emp_id) ? $chief_emp_id = null : $chief_emp_id = $chief_emp_id->emp_id;
				$position_id = $employee->position_id;
				$level_id = $employee->level_id;
				$org_id = $employee->org_id;

				if ($config->email_reminder_flag == 1) {
					try {
						$chief_emp = Employee::where('emp_code',$employee->chief_emp_code)->first();

						$data = ["chief_emp_name" => $chief_emp->emp_name, "emp_name" => $employee->emp_name, "status" => $stage->status, "web_domain" => $config->web_domain, 'emp_result_id' => $emp_result->emp_result_id, 'appraisal_type_id' => $emp_result->appraisal_type_id, 'assignment_flag' => $stage->assignment_flag];
						$to = [$employee->email, $chief_emp->email];

						//$from = $config->mail_username;

						Mail::send('emails.status', $data, function($message) use ($from, $to)
						{
							$message->from($from['address'], $from['name']);
							$message->to($to)->subject('ระบบได้ทำการประเมิน');
						});
					} catch (Exception $ExceptionError) {
						$mail_error = $ExceptionError->getMessage();

					}
				}
			}
		} else {
			$org = Org::find($emp_result->org_id);
			$chief_emp_code = null;
			$chief_emp_id = null;
			$position_id = null;
			$level_id = $org->level_id;
			$org_id = $emp_result->org_id;

			if ($config->email_reminder_flag == 1) {
				try {

					$data = ["chief_emp_name" => $org->org_name, "emp_name" => $org->org_name, "status" => $stage->status, "web_domain" => $config->web_domain, 'emp_result_id' => $emp_result->emp_result_id, 'appraisal_type_id' => $emp_result->appraisal_type_id, 'assignment_flag' => $stage->assignment_flag];
					$to = [$org->org_email];

						//$from = $config->mail_username;

					Mail::send('emails.status', $data, function($message) use ($from, $to)
					{
						$message->from($from['address'], $from['name']);
						$message->to($to)->subject('ระบบได้ทำการประเมิน');
					});
				} catch (Exception $ExceptionError) {
					$mail_error = $ExceptionError->getMessage();

				}
			}
		}

		$tg_id = ThresholdGroup::where('is_active',1)->first();
		empty($tg_id) ? $tg_id = null : $tg_id = $tg_id->threshold_group_id;

		foreach ($request->appraisal_items as $i) {
			if ($i['select_flag'] == 1) {
				if ($i['form_id'] == 1) {
					$aitem = AppraisalItemResult::find($i['item_result_id']);
					if (empty($aitem)) {
						$aitem = new AppraisalItemResult;
						$aitem->org_id = $org_id;
						$aitem->position_id = $position_id;
						$aitem->level_id = $level_id;
						$aitem->chief_emp_id = $chief_emp_id;
						$aitem->kpi_type_id = $i['kpi_type_id'];
						$aitem->structure_weight_percent = $i['total_weight'];
						$aitem->created_by = Auth::id();
						$aitem->appraisal_form_id = $emp_result->appraisal_form_id;

						if($config->item_result_log == 1){
							$aitemlog = new AppraisalItemResultLog;
							$aitemlog->org_id = $org_id;
							$aitemlog->position_id = $position_id;
							$aitemlog->level_id = $level_id;
							$aitemlog->chief_emp_id = $chief_emp_id;
							$aitemlog->kpi_type_id = $i['kpi_type_id'];
							$aitemlog->structure_weight_percent = $i['total_weight'];
							$aitemlog->created_by = Auth::id();
							$aitemlog->modify_by = Auth::id();
							$aitemlog->modify_date = new DateTime();
							$aitemlog->modify_type = 'I';
							$aitemlog->emp_result_id = $emp_result->emp_result_id;
							$aitemlog->period_id = $request->head_params['period_id'];
							$aitemlog->emp_id = $emp_result->emp_id;
							$aitemlog->item_id = $i['item_id'];
							$aitemlog->item_name = $i['item_name'];
							$aitemlog->target_value = $i['target_value'];
							$aitemlog->weight_percent = $i['weight_percent'];
							array_key_exists('score0', $i) ? $aitemlog->score0 = $i['score0'] : null;
							array_key_exists('score1', $i) ? $aitemlog->score1 = $i['score1'] : null;
							array_key_exists('score2', $i) ? $aitemlog->score2 = $i['score2'] : null;
							array_key_exists('score3', $i) ? $aitemlog->score3 = $i['score3'] : null;
							array_key_exists('score4', $i) ? $aitemlog->score4 = $i['score4'] : null;
							array_key_exists('score5', $i) ? $aitemlog->score5 = $i['score5'] : null;
							array_key_exists('forecast_value', $i) ? $aitemlog->forecast_value = $i['forecast_value'] : null;
							$aitemlog->over_value = 0;
							$aitemlog->weigh_score = 0;
							$aitemlog->threshold_group_id = $tg_id;
							$aitemlog->updated_by = Auth::id();
							$aitemlog->appraisal_type_id = $emp_result->appraisal_type_id;
							$aitemlog->save();
						}
					} else {
						if($config->item_result_log == 1 &&
						((array_key_exists('score0', $i) ? $aitem->score0 != $i['score0'] : null)
						|| (array_key_exists('score1', $i) ? $aitem->score1 != $i['score1'] : null)
						|| (array_key_exists('score2', $i) ? $aitem->score2 != $i['score2'] : null)
						|| (array_key_exists('score3', $i) ? $aitem->score3 != $i['score3'] : null)
						|| (array_key_exists('score4', $i) ? $aitem->score4 != $i['score4'] : null)
						|| (array_key_exists('score5', $i) ? $aitem->score5 != $i['score5'] : null)
						|| $aitem->target_value != $i['target_value']
						|| (array_key_exists('forecast_value', $i) ? $aitem->forecast_value != $i['forecast_value'] : null)
						|| $aitem->weight_percent != $i['weight_percent'])
						// ($aitem->score0 != (array_key_exists('score0', $i) ? $i['score0'] : null)
						// || $aitem->score1 != (array_key_exists('score1', $i) ? $i['score1'] : null)
						// || $aitem->score2 != (array_key_exists('score2', $i) ? $i['score2'] : null)
						// || $aitem->score3 != (array_key_exists('score3', $i) ? $i['score3'] : null)
						// || $aitem->score4 != (array_key_exists('score4', $i) ? $i['score4'] : null)
						// || $aitem->score5 != (array_key_exists('score5', $i) ? $i['score5'] : null)
						// || $aitem->target_value != $i['target_value']
						// || $aitem->forecast_value != (array_key_exists('forecast_value', $i) ? $i['forecast_value'] : null)
						// || $aitem->weight_percent != $i['weight_percent'])
						){
							$aitemlog = new AppraisalItemResultLog;
							$aitemlog->org_id = $aitem->org_id;
							$aitemlog->position_id = $aitem->position_id;
							$aitemlog->level_id = $aitem->level_id;
							$aitemlog->chief_emp_id = $aitem->chief_emp_id;
							$aitemlog->kpi_type_id = $aitem->kpi_type_id;
							$aitemlog->structure_weight_percent = $aitem->structure_weight_percent;
							$aitemlog->created_by = Auth::id();
							$aitemlog->modify_by = Auth::id();
							$aitemlog->modify_date = new DateTime();
							$aitemlog->modify_type = 'U';
							$aitemlog->emp_result_id = $emp_result->emp_result_id;
							$aitemlog->period_id = $aitem->period_id;
							$aitemlog->emp_id = $aitem->emp_id;
							$aitemlog->item_id = $aitem->item_id;
							$aitemlog->item_name = $aitem->item_name;
							$aitemlog->target_value = $aitem->target_value;
							$aitemlog->weight_percent = $aitem->weight_percent;
							$aitemlog->score0 = $aitem->score0;
							$aitemlog->score1 = $aitem->score1;
							$aitemlog->score2 = $aitem->score2;
							$aitemlog->score3 = $aitem->score3;
							$aitemlog->score4 = $aitem->score4;
							$aitemlog->score5 = $aitem->score5;
							$aitemlog->forecast_value = $aitem->forecast_value;
							$aitemlog->over_value = $aitem->over_value;
							$aitemlog->weigh_score = $aitem->weigh_score;
							$aitemlog->threshold_group_id = $aitem->threshold_group_id;
							$aitemlog->updated_by = Auth::id();
							$aitemlog->appraisal_type_id = $emp_result->appraisal_type_id;
							$aitemlog->save();
						}
					}

					$aitem->emp_result_id = $emp_result->emp_result_id;
					$aitem->appraisal_form_id = $emp_result->appraisal_form_id;
					$aitem->period_id = $request->head_params['period_id'];
					$aitem->emp_id = $emp_result->emp_id;
					$aitem->item_id = $i['item_id'];
					$aitem->item_name = $i['item_name'];
					$aitem->target_value = $i['target_value'];
					$aitem->weight_percent = $i['weight_percent'];
					array_key_exists('score0', $i) ? $aitem->score0 = $i['score0'] : null;
					array_key_exists('score1', $i) ? $aitem->score1 = $i['score1'] : null;
					array_key_exists('score2', $i) ? $aitem->score2 = $i['score2'] : null;
					array_key_exists('score3', $i) ? $aitem->score3 = $i['score3'] : null;
					array_key_exists('score4', $i) ? $aitem->score4 = $i['score4'] : null;
					array_key_exists('score5', $i) ? $aitem->score5 = $i['score5'] : null;
					array_key_exists('forecast_value', $i) ? $aitem->forecast_value = $i['forecast_value'] : null;
					$aitem->over_value = 0;
					//$aitem->weigh_score = 0;
					$aitem->threshold_group_id = $tg_id;
					$aitem->updated_by = Auth::id();
					$aitem->save();

				} elseif ($i['form_id'] == 2) {

					$aitem = AppraisalItemResult::find($i['item_result_id']);
					if (empty($aitem)) {
						$aitem = new AppraisalItemResult;
						$aitem->org_id = $org_id;
						$aitem->position_id = $position_id;
						$aitem->level_id = $level_id;
						$aitem->chief_emp_id = $chief_emp_id;
						$aitem->structure_weight_percent = $i['total_weight'];
						$aitem->created_by = Auth::id();
						$aitem->appraisal_form_id = $emp_result->appraisal_form_id;

						if($config->item_result_log == 1){
							$aitemlog = new AppraisalItemResultLog;
							$aitemlog->org_id = $org_id;
							$aitemlog->position_id = $position_id;
							$aitemlog->level_id = $level_id;
							$aitemlog->chief_emp_id = $chief_emp_id;
							$aitemlog->structure_weight_percent = $i['total_weight'];
							$aitemlog->created_by = Auth::id();
							$aitemlog->modify_by = Auth::id();
							$aitemlog->modify_date = new DateTime();
							$aitemlog->modify_type = 'I';
							$aitemlog->emp_result_id = $emp_result->emp_result_id;
							$aitemlog->period_id = $request->head_params['period_id'];
							$aitemlog->emp_id = $emp_result->emp_id;
							$aitemlog->item_id = $i['item_id'];
							$aitemlog->item_name = $i['item_name'];
							$aitemlog->target_value = $i['target_value'];
							$aitemlog->weight_percent = $i['weight_percent'];
							$aitemlog->over_value = 0;
							$aitemlog->weigh_score = 0;
							$aitemlog->threshold_group_id = $tg_id;
							$aitemlog->updated_by = Auth::id();
							$aitemlog->appraisal_type_id = $emp_result->appraisal_type_id;
							$aitemlog->save();
						}
					}else {
						if($config->item_result_log == 1 &&
						( $aitem->target_value != $i['target_value']
						|| $aitem->weight_percent != $i['weight_percent'])
						){
							$aitemlog = new AppraisalItemResultLog;
							$aitemlog->org_id = $aitem->org_id;
							$aitemlog->position_id = $aitem->position_id;
							$aitemlog->level_id = $aitem->level_id;
							$aitemlog->chief_emp_id = $aitem->chief_emp_id;
							$aitemlog->structure_weight_percent = $aitem->structure_weight_percent;
							$aitemlog->created_by = Auth::id();
							$aitemlog->modify_by = Auth::id();
							$aitemlog->modify_date = new DateTime();
							$aitemlog->modify_type = 'U';
							$aitemlog->emp_result_id = $emp_result->emp_result_id;
							$aitemlog->period_id = $aitem->period_id;
							$aitemlog->emp_id = $aitem->emp_id;
							$aitemlog->item_id = $aitem->item_id;
							$aitemlog->item_name = $aitem->item_name;
							$aitemlog->target_value = $aitem->target_value;
							$aitemlog->weight_percent = $aitem->weight_percent;
							$aitemlog->over_value = $aitem->over_value;
							$aitemlog->weigh_score = $aitem->weigh_score;
							$aitemlog->threshold_group_id = $aitem->threshold_group_id;
							$aitemlog->updated_by = Auth::id();
							$aitemlog->appraisal_type_id = $emp_result->appraisal_type_id;
							$aitemlog->save();
						}
					}
					$aitem->emp_result_id = $emp_result->emp_result_id;
					$aitem->appraisal_form_id = $emp_result->appraisal_form_id;
					$aitem->period_id = $request->head_params['period_id'];
					$aitem->emp_id = $emp_result->emp_id;
					$aitem->item_id = $i['item_id'];
					$aitem->item_name = $i['item_name'];
					$aitem->target_value = $i['target_value'];
					$aitem->weight_percent = $i['weight_percent'];
					$aitem->over_value = 0;
					//$aitem->weigh_score = 0;
					$aitem->threshold_group_id = $tg_id;
					$aitem->updated_by = Auth::id();
					$aitem->save();



				} elseif ($i['form_id'] == 3) {

					$aitem = AppraisalItemResult::find($i['item_result_id']);
					if (empty($aitem)) {
						$aitem = new AppraisalItemResult;
						$aitem->org_id = $org_id;
						$aitem->position_id = $position_id;
						$aitem->level_id = $level_id;
						$aitem->chief_emp_id = $chief_emp_id;
						$aitem->structure_weight_percent = $i['total_weight'];
						$aitem->no_raise_value = $i['no_raise_value'];
						$aitem->created_by = Auth::id();
						$aitem->appraisal_form_id = $emp_result->appraisal_form_id;

						if($config->item_result_log == 1){
							$aitemlog = new AppraisalItemResultLog;
							$aitemlog->org_id = $org_id;
							$aitemlog->position_id = $position_id;
							$aitemlog->level_id = $level_id;
							$aitemlog->chief_emp_id = $chief_emp_id;
							$aitemlog->structure_weight_percent = $i['total_weight'];
							$aitemlog->created_by = Auth::id();
							$aitemlog->modify_by = Auth::id();
							$aitemlog->modify_date = new DateTime();
							$aitemlog->modify_type = 'I';
							$aitemlog->emp_result_id = $emp_result->emp_result_id;
							$aitemlog->period_id = $request->head_params['period_id'];
							$aitemlog->emp_id = $emp_result->emp_id;
							$aitemlog->item_id = $i['item_id'];
							$aitemlog->item_name = $i['item_name'];
							$aitemlog->max_value = $i['max_value'];
							$aitemlog->deduct_score_unit = $i['deduct_score_unit'];
							$aitemlog->weight_percent = 0;
							$aitemlog->over_value = 0;
							$aitemlog->weigh_score = 0;
							$aitemlog->threshold_group_id = $tg_id;
							$aitemlog->updated_by = Auth::id();
							$aitemlog->appraisal_type_id = $emp_result->appraisal_type_id;
							$aitemlog->value_get_zero = $aitem->value_get_zero;
							$aitemlog->save();
						}
					}else {
						if($config->item_result_log == 1 &&
						( $aitem->max_value != $i['max_value']
						|| $aitem->deduct_score_unit != $i['deduct_score_unit']
						|| ($aitem->value_get_zero != $i['value_get_zero'] && $i['value_get_zero'] != 'undefined'))
						){
							$aitemlog = new AppraisalItemResultLog;
							$aitemlog->org_id = $aitem->org_id;
							$aitemlog->position_id = $aitem->position_id;
							$aitemlog->level_id = $aitem->level_id;
							$aitemlog->chief_emp_id = $aitem->chief_emp_id;
							$aitemlog->structure_weight_percent = $aitem->structure_weight_percent;
							$aitemlog->created_by = Auth::id();
							$aitemlog->modify_by = Auth::id();
							$aitemlog->modify_date = new DateTime();
							$aitemlog->modify_type = 'U';
							$aitemlog->emp_result_id = $emp_result->emp_result_id;
							$aitemlog->period_id = $aitem->period_id;
							$aitemlog->emp_id = $emp_result->emp_id;
							$aitemlog->item_id = $aitem->item_id;
							$aitemlog->item_name = $aitem->item_name;
							$aitemlog->max_value = $aitem->max_value;
							$aitemlog->deduct_score_unit = $aitem->deduct_score_unit;
							$aitemlog->weight_percent = $aitem->weight_percent;
							$aitemlog->over_value = $aitem->over_value;
							$aitemlog->weigh_score = $aitem->weigh_score;
							$aitemlog->threshold_group_id = $aitem->threshold_group_id;
							$aitemlog->updated_by = Auth::id();
							$aitemlog->appraisal_type_id = $emp_result->appraisal_type_id;
							$aitemlog->value_get_zero = $aitem->value_get_zero;
							$aitemlog->save();
						}
					}
					$aitem->emp_result_id = $emp_result->emp_result_id;
					$aitem->appraisal_form_id = $emp_result->appraisal_form_id;
					$aitem->period_id = $request->head_params['period_id'];
					$aitem->emp_id = $emp_result->emp_id;
					$aitem->item_id = $i['item_id'];
					$aitem->item_name = $i['item_name'];
					$aitem->max_value = $i['max_value'];
					$aitem->deduct_score_unit = $i['deduct_score_unit'];
					$aitem->no_raise_value = $i['no_raise_value'];
					$aitem->weight_percent = 0;
					// $aitem->over_value = 0;
					// $aitem->weigh_score = 0;
					$aitem->threshold_group_id = $tg_id;
					$aitem->updated_by = Auth::id();
					$aitem->save();

				}
			} else {
				// select flag false
				$air = DB::select("
					select emp_id, level_id, position_id, org_id, period_id, item_result_id , item_id
					from appraisal_item_result
					where item_result_id = '".$i['item_result_id']."'
					");

				if(!empty($air)) {
					$cds = DB::select("
						select cds.cds_result_id, cds.cds_id
						from cds_result cds
						inner join kpi_cds_mapping kcm on kcm.cds_id = cds.cds_id
						inner join appraisal_item_result air on air.item_id = kcm.item_id
						where cds.level_id = '".$air[0]->level_id."'
						and cds.position_id = '".$air[0]->position_id."'
						and cds.org_id = '".$air[0]->org_id."'
						and cds.period_id = '".$air[0]->period_id."'
						and air.item_id = '".$air[0]->item_id."'
						");
				}

				$aitem_plan = DB::table('action_plan')->where('item_result_id', '=', $i['item_result_id']);
				$aitem_phase = DB::table('phase')->where('item_result_id', '=', $i['item_result_id']);

				$aitem = AppraisalItemResult::find($i['item_result_id']);
				$aitem_doc = DB::table('appraisal_item_result_doc')->where('item_result_id', '=', $i['item_result_id']);
				$aitem_month = DB::table('monthly_appraisal_item_result')->where('item_result_id', '=', $i['item_result_id']);

				if(!empty($cds)) $aitem_cds = DB::table('cds_result')->where('cds_id', '=', $cds[0]->cds_id);
				if(!empty($cds)) {
					foreach ($cds as $key => $value) {
						$aitem_cds_doc = DB::table('cds_result_doc')->where('cds_result_id', '=', $value->cds_result_id);
						if (!empty($aitem_cds_doc)) {
							$aitem_cds_doc->delete();
						}
					}
				}

				if(!empty($aitem_plan)) {
					$aitem_plan->delete();
				}

				if(!empty($aitem_phase)) {
					$aitem_phase->delete();
				}

				if (!empty($aitem)) {
					if($config->item_result_log == 1){
						$aitemlog = new AppraisalItemResultLog;
						$aitemlog->org_id = $aitem->org_id;
						$aitemlog->position_id = $aitem->position_id;
						$aitemlog->level_id = $aitem->level_id;
						$aitemlog->chief_emp_id = $aitem->chief_emp_id;
						$aitemlog->kpi_type_id = $aitem->kpi_type_id;
						$aitemlog->structure_weight_percent = $aitem->structure_weight_percent;
						$aitemlog->created_by = Auth::id();
						$aitemlog->modify_by = Auth::id();
						$aitemlog->modify_date = new DateTime();
						$aitemlog->modify_type = 'D';
						$aitemlog->emp_result_id = $aitem->emp_result_id;
						$aitemlog->period_id = $aitem->period_id;
						$aitemlog->emp_id = $aitem->emp_id;
						$aitemlog->item_id = $aitem->item_id;
						$aitemlog->item_name = $aitem->item_name;
						$aitemlog->target_value = $aitem->target_value;
						$aitemlog->weight_percent = $aitem->weight_percent;
						$aitemlog->score0 = $aitem->score0;
						$aitemlog->score1 = $aitem->score1;
						$aitemlog->score2 = $aitem->score2;
						$aitemlog->score3 = $aitem->score3;
						$aitemlog->score4 = $aitem->score4;
						$aitemlog->score5 = $aitem->score5;
						$aitemlog->forecast_value = $aitem->forecast_value;
						$aitemlog->over_value = $aitem->over_value;
						$aitemlog->weigh_score = $aitem->weigh_score;
						$aitemlog->threshold_group_id = $aitem->threshold_group_id;
						$aitemlog->max_value = $aitem->max_value;
						$aitemlog->deduct_score_unit = $aitem->deduct_score_unit;
						$aitemlog->updated_by = Auth::id();
						$aitemlog->appraisal_type_id = $emp_result->appraisal_type_id;
						$aitemlog->value_get_zero = $aitem->value_get_zero;
						$aitemlog->save();
					}

					$aitem->delete();
				}
				if (!empty($aitem_doc)) {
					$aitem_doc->delete();
				}
				if (!empty($aitem_month)) {
					$aitem_month->delete();
				}
				if (!empty($aitem_cds)) {
					$aitem_cds->delete();
				}
			}
		}

		return response()->json(['status' => 200, 'mail_error' => $mail_error]);

	}

	public function update_action(Request $request) {
		try {
			$stage = AppraisalStage::findOrFail($request->head_params['action_to']);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 400, 'data' => 'Appraisal Stage not found.']);
		}

		if(empty($request->head_params['emp_result_id'])) {
			return response()->json(['status' => 400, 'data' => 'Emp Result not found.']);
		}

		$emp_result_id = $request->head_params['emp_result_id'];

		foreach ($emp_result_id as $key => $value) {
			$item = EmpResult::find($value);
			$item->stage_id = $request->head_params['action_to'];
			$item->status = $stage->status;
			$item->updated_by = Auth::id();
			$item->save();

			$emp_stage = new EmpResultStage;
			$emp_stage->emp_result_id = $value;
			$emp_stage->stage_id = $request->head_params['action_to'];
			$emp_stage->remark = $request->head_params['remark'];;
			$emp_stage->created_by = Auth::id();
			$emp_stage->updated_by = Auth::id();
			$emp_stage->save();
		}

		return response()->json(['status' => 200]);
	}

	public function destroy($emp_result_id)
	{

		try {
			$item = EmpResult::findOrFail($emp_result_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 400, 'data' => 'Appraisal Assignment not found.']);
		}

		try {
			// if ($item->status == 'Assigned' || strpos(strtolower($item->status),'reject') !== false || $item->status == 'Draft' || strpos(strtolower($item->status),'review') !== false) {

				EmpResultStage::where('emp_result_id',$item->emp_result_id)->delete();
				AppraisalItemResultLog::where('emp_result_id',$item->emp_result_id)->delete();
				DB::table('structure_result')->where('emp_result_id', '=', $item->emp_result_id)->delete();
				DB::table('monthly_appraisal_item_result')->where('emp_result_id', '=', $item->emp_result_id)->delete();
				DB::table('emp_result_judgement')->where('emp_result_id', '=', $item->emp_result_id)->delete();
				DB::table('emp_judgement')->where('emp_result_id', '=', $item->emp_result_id)->delete();
				DB::table('assessment_opinion')->where('emp_result_id', '=', $item->emp_result_id)->delete();
				// DB::table('emp_result_log')->where('emp_result_id', '=', $item->emp_result_id)->delete();

				$air = DB::select("
					select emp_id, level_id, position_id, org_id, period_id, item_result_id
					from appraisal_item_result
					where emp_result_id = {$item->emp_result_id}
				");

				if(!empty($air)) {
					$cds = DB::select("
						select cds_result_id
						from cds_result
						where emp_id = '".$air[0]->emp_id."'
						and level_id = '".$air[0]->level_id."'
						and position_id = '".$air[0]->position_id."'
						and org_id = '".$air[0]->org_id."'
						and period_id = '".$air[0]->period_id."'
					");

					foreach ($cds as $key => $value) {
						DB::table('cds_result')->where('cds_result_id', '=', $value->cds_result_id)->delete();
						DB::table('cds_result_doc')->where('cds_result_id', '=', $value->cds_result_id)->delete();
					}

					foreach ($air as $key => $value) {
						DB::table('appraisal_item_result_doc')->where('item_result_id', '=', $value->item_result_id)->delete();
						DB::table('competency_result')->where('item_result_id', '=', $value->item_result_id)->delete();
						DB::table('action_plan')->where('item_result_id', '=', $value->item_result_id)->delete();
						DB::table('phase')->where('item_result_id', '=', $value->item_result_id)->delete();
						DB::table('reason')->where('item_result_id', '=', $value->item_result_id)->delete();
					}
				}

				AppraisalItemResult::where('emp_result_id',$item->emp_result_id)->delete();
				$item->delete();
			// } else {
			// 	return response()->json(['status' => 400, 'data' => 'Cannot delete Appraisal Assignment at this stage.']);
			// }
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Appraisal Assignment is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);

	}

}
