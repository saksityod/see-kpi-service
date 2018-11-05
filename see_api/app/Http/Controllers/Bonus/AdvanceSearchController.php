<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;

use Auth;
use DB;
use Validator;
use Exception;
use Log;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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
            $emps = DB::table('appraisal_level')->select('level_id', 'appraisal_level_name')
                ->where('is_active', 1)
                ->where('is_individual', 1)
                ->orderBy('level_id', 'asc')
                ->get();
        } else {
            $underEmps = $this->GetallUnderEmp(Auth::id());
            $emps = DB::select("
                SELECT l.level_id, l.appraisal_level_name
                FROM appraisal_level l
                INNER JOIN employee e on e.level_id = l.level_id
                WHERE l.is_active = 1
                AND is_individual = 1
                AND find_in_set(e.emp_code, '".$underEmps."')
                GROUP BY l.level_id
            ");
        }

        return response()->json($emps);
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
            
        } else {

        }


        // SELECT org.level_id, vel.appraisal_level_name
        // FROM org 
        // INNER JOIN appraisal_level vel ON vel.level_id = org.level_id
        // WHERE org.is_active = 1
        // AND org.org_id IN(
        //     SELECT DISTINCT emp.org_id
        //     FROM employee emp 
        //     WHERE emp.is_active = 1 
        //     AND emp.level_id = 10
        // )
    }



    public function GetallUnderEmp($paramEmp)
	{
        // $paramEmp = 'dhas001';
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



    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
