<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use App\Http\Requests;
use App\Http\Controllers\Controller;

use Auth;
use DB;
use Validator;
use Exception;
use Log;

use App\Employee;


class AdvanceSearchController extends Controller
{

    public function __construct()
	{
		$this->middleware('jwt.auth');
	}


    public function YearList(Request $request)
    {
        $years = DB::select("
			SELECT DISTINCT appraisal_year appraisal_year_id,
			appraisal_year
			from appraisal_period
			LEFT OUTER JOIN system_config on system_config.current_appraisal_year = appraisal_period.appraisal_year
		");
		return response()->json($years);
    }


    public function PeriodList(Request $request)
    {
        $periods = DB::table("appraisal_period")->select('period_id', 'appraisal_period_desc')
            ->where('is_bonus', 1)
            ->where('appraisal_year', $request->appraisal_year)
            ->get();
		return response()->json($periods);
    }


    public function FormList(Request $request)
	{
        $forms = DB::table('appraisal_form')->select('appraisal_form_id', 'appraisal_form_name')
            ->where('is_active', 1)
            ->where('is_bonus', 1)            
            ->get();
		return response()->json($forms);
    }
    

    public function IndividualLevelList(Request $request)
    {
        $all_emp = DB::select("
            SELECT sum(b.is_all_employee) count_no
            FROM employee a
            LEFT OUTER JOIN appraisal_level b on a.level_id = b.level_id
            WHERE emp_code = ?
        ", array(Auth::id()));

        if ($all_emp[0]->count_no > 0) {
            $indLevels = DB::table('appraisal_level')->select('level_id', 'appraisal_level_name')
                ->where('is_active', 1)
                ->where('is_individual', 1)
                ->orderBy('level_id', 'asc')
                ->get();
        } else {
            $underEmps = $this->GetallUnderEmp(Auth::id());
            $indLevels = DB::select("
                SELECT l.level_id, l.appraisal_level_name
                FROM appraisal_level l
                INNER JOIN employee e on e.level_id = l.level_id
                WHERE l.is_active = 1
                AND is_individual = 1
                AND find_in_set(e.emp_code, '".$underEmps."')
                GROUP BY l.level_id
            ");
        }

        return response()->json($indLevels);
    }


    public function OrganizationLevelList(Request $request)
    {
        $all_emp = DB::select("
            SELECT sum(b.is_all_employee) count_no
            FROM employee a
            LEFT OUTER JOIN appraisal_level b on a.level_id = b.level_id
            WHERE emp_code = ?
        ", array(Auth::id()));

        if ($all_emp[0]->count_no > 0) {
            $orgLevels = DB::select("
                SELECT org.level_id, vel.appraisal_level_name
                FROM org 
                INNER JOIN appraisal_level vel ON vel.level_id = org.level_id
                WHERE org.is_active = 1
                AND vel.is_active = 1
                AND vel.is_org = 1
            ");
        } else {
            $underEmps = $this->GetallUnderEmp(Auth::id());
            $indLevelStr = empty($request->individual_level) ? "": " AND emp.level_id = ".$request->individual_level;
            $orgLevels = DB::select("
                SELECT org.level_id, vel.appraisal_level_name
                FROM org 
                INNER JOIN appraisal_level vel ON vel.level_id = org.level_id
                WHERE org.is_active = 1
                AND vel.is_org = 1
                AND org.org_id IN(
                    SELECT DISTINCT emp.org_id
                    FROM employee emp 
                    WHERE emp.is_active = 1 
                    AND find_in_set(emp.emp_code, '".$underEmps."') 
                    ".$indLevelStr."
                )
            ");
        }

        return response()->json($orgLevels);
    }


    public function OrganizationList(Request $request)
    {
        $all_emp = DB::select("
            SELECT sum(l.is_all_employee) count_no
            FROM appraisal_level l
            INNER JOIN org o on o.level_id = l.level_id
            INNER JOIN employee e on e.org_id = o.org_id
            WHERE e.emp_code = ?
		", array(Auth::id()));

        $indLevelStr = empty($request->individual_level) ? "": " AND emp.level_id = ".$request->individual_level;
        $orgLevelStr = empty($request->organization_level) ? "": " AND org.level_id = ".$request->organization_level;
        if ($all_emp[0]->count_no > 0) {
            $orgs = DB::select("
                SELECT org.org_id, org.org_name
                FROM org
                INNER JOIN employee emp ON emp.org_id = org.org_id
                WHERE org.is_active = 1
                ".$indLevelStr."
                ".$orgLevelStr."
                GROUP BY org.org_id
                ORDER BY org.org_code ASC
            ");
        } else {
            $underEmps = $this->GetallUnderEmp(Auth::id());
            $orgs = DB::select("
                SELECT org.org_id, org.org_name
                FROM org
                INNER JOIN employee emp ON emp.org_id = org.org_id
                WHERE org.is_active = 1
                AND org.org_id IN(
                    SELECT DISTINCT emp.org_id
                    FROM employee emp 
                    WHERE emp.is_active = 1 
                    AND find_in_set(emp.emp_code, '".$underEmps."')
                )
                ".$indLevelStr."
                ".$orgLevelStr."
                GROUP BY org.org_id
                ORDER BY org.org_code ASC
            ");
        }

        return response()->json($orgs);
    }


    public function GetEmployeeName(Request $request)
	{
	    $emp = Employee::find(Auth::id());
	    $all_emp = DB::select("
	    	SELECT sum(b.is_all_employee) count_no
	    	FROM employee a
	    	LEFT OUTER JOIN appraisal_level b ON a.level_id = b.level_id
	    	WHERE emp_code = ?
            ", array(Auth::id())
        );

        $indLevelQryStr = empty($request->individual_level) ? "" : " AND level_id = ".$request->individual_level;
	    $orgIdQryStr = empty($request->organization_id) ? "" : " AND org_id = ".$request->organization_id;
	    
        if($all_emp[0]->count_no > 0) {
	    	$items = DB::select("
	    		Select emp_code, emp_name
	    		From employee
	    		Where emp_name like ?
	    		and is_active = 1
	    		".$indLevelQryStr."
	    		".$orgIdQryStr."
	    		Order by emp_name
                ", array('%'.$request->employee_name.'%')
            );
        } else {
            $underEmps = $this->GetallUnderEmp(Auth::id());
	    	$items = DB::select("
	    		Select emp_code, emp_name
	    		From employee
	    		Where find_in_set(emp_code, '".$underEmps."')
	    		And emp_name like ?
	    		" . $indLevelQryStr . "
	    		" . $orgIdQryStr . "
	    		and is_active = 1
	    		Order by emp_name
                ", array($emp->emp_code, $emp->emp_code,'%'.$request->employee_name.'%')
            );
	    }
        
        return response()->json($items);
    }
    

    public function GetPositionName(Request $request)
	{
		$emp = Employee::find(Auth::id());
		$all_emp = DB::select("
			SELECT sum(b.is_all_employee) count_no
			from employee a
			left outer join appraisal_level b
			on a.level_id = b.level_id
			where emp_code = ?
            ", array(Auth::id())
        );

		$orgIdQryStr = empty($request->organization_id) ? "" : " and a.org_id = ".$request->organization_id;

		if ($all_emp[0]->count_no > 0) {
			$items = DB::select("
				Select distinct b.position_id, b.position_name
				From employee a left outer join position b
				on a.position_id = b.position_id
				Where position_name like ?
				and emp_name like ?
				and a.is_active = 1
				and b.is_active = 1
				" . $orgIdQryStr . "
				Order by position_name
				limit 10
                ",array('%'.$request->position_name.'%','%'.$request->employee_name.'%')
            );
		} else {
            $underEmps = $this->GetallUnderEmp(Auth::id());
			$items = DB::select("
				Select distinct b.position_id, b.position_name
                From employee a 
                left outer join position b on a.position_id = b.position_id
				Where find_in_set(a.emp_code, '".$underEmps."')
				and position_name like ?
				and emp_name like ?
				and a.is_active = 1
				" . $orgIdQryStr . "
				and b.is_active = 1
				Order by position_name
				limit 10
                ", array($emp->emp_code, $emp->emp_code,'%'.$request->position_name.'%','%'.$request->employee_name.'%')
            );
        }
        
		return response()->json($items);
	}


    public function StatusList(Request $request)
    {
        $status = DB::table('appraisal_stage')
            ->select('stage_id', 'status')
            ->where('bonus_appraisal_flag', 1)
            ->get();
        
        return response()->json($status);
    }
    
    
    private function GetallUnderEmp($paramEmp)
	{
        $paramEmp = 'dhas001';
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
            log::info($LoopEmpCodeSet);

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
		
		return $globalEmpCodeSet;
    }
    
}
