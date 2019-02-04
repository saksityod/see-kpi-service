<?php

namespace App\Http\Controllers\Bonus;

use App\AppraisalLevel;
use App\Employee;

use Auth;
use DB;
use Validator;
use Exception;
use Log;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AdvanceSearchController extends Controller
{
    public function __construct()
    {
        $this->middleware('jwt.auth');
    }

    function empAuth() {
        $empAuth = DB::table('employee')
        ->join('appraisal_level', 'appraisal_level.level_id', '=', 'employee.level_id')
        ->select('employee.level_id','employee.emp_id','appraisal_level.is_hr')
        ->where('emp_code', Auth::id())
        ->first();
        return $empAuth;
    }

    function orgAuth() {
        $orgAuth = DB::table('employee')
        ->join('org', 'org.org_id', '=', 'employee.org_id')
        ->join('appraisal_level', 'appraisal_level.level_id', '=', 'employee.level_id')
        ->select('org.level_id','employee.emp_id','appraisal_level.is_hr')
        ->where('emp_code', Auth::id())
        ->first();
        return $orgAuth;
    }

    function getFieldLevelStage($level_id, $level_id_org) {
        $level = DB::select("
            SELECT stage_id, level_id
            FROM appraisal_stage
            WHERE (level_id LIKE '%{$level_id}%' OR level_id LIKE '%{$level_id_org}%' OR level_id = 'all')
        ");

        $stage_id_array = [];
        foreach ($level as $key => $value) {
            $ex = explode(",",$value->level_id);
            foreach ($ex as $exv) {
                if($level_id==$exv || $level_id_org==$exv || $exv=='all') {
                    array_push($stage_id_array, $value->stage_id);
                }
            }
        }

        if(empty($stage_id_array)) {
            $stage_id_array = "''";
        } else {
            $stage_id_array = implode(",", $stage_id_array);
        }

        return $stage_id_array;
    }

    function getFieldFormStage($form) {
        $level = DB::select("
            SELECT stage_id, appraisal_form_id
            FROM appraisal_stage
            WHERE (appraisal_form_id LIKE '%{$form}%' OR appraisal_form_id = 'all')
        ");

        $stage_id_array = [];
        foreach ($level as $key => $value) {
            $ex = explode(",",$value->appraisal_form_id);
            foreach ($ex as $exv) {
                if($form==$exv || $exv=='all') {
                    array_push($stage_id_array, $value->stage_id);
                }
            }
        }

        if(empty($stage_id_array)) {
            $stage_id_array = "''";
        } else {
            $stage_id_array = implode(",", $stage_id_array);
        }

        return $stage_id_array;
    }

    function GetallUnderEmp($paramEmp) {
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

    function GetallUnderOrg($paramEmp) {
        $globalEmpCodeSet = "";
        $inLoop = true;
        $loopCnt = 1;

        while ($inLoop){
            if($loopCnt == 1){
                $LoopEmpCodeSet = $paramEmp.",";
            }
            
            // Check each under //
            $eachUnder = DB::select("
                SELECT org_code
                FROM org
                WHERE find_in_set(parent_org_code, '{$LoopEmpCodeSet}')
            ");
            log::info($LoopEmpCodeSet);

            if(empty($eachUnder)){
                $inLoop = false;
            } else {
                $LoopEmpCodeSet = "";
                foreach ($eachUnder as $emp) {
                    $LoopEmpCodeSet .= $emp->org_code.",";
                }
                $globalEmpCodeSet .= $LoopEmpCodeSet;
            }
            $loopCnt = $loopCnt + 1;
        }
        
        return $globalEmpCodeSet;
    }

    function GetallUnderLevel($paramEmp) {
        $globalEmpCodeSet = "";
        $inLoop = true;
        $loopCnt = 1;

        while ($inLoop){
            if($loopCnt == 1){
                $LoopEmpCodeSet = $paramEmp.",";
            }
            
            // Check each under //
            $eachUnder = DB::select("
                SELECT level_id
                FROM appraisal_level
                WHERE find_in_set(parent_id, '{$LoopEmpCodeSet}')
            ");
            log::info($LoopEmpCodeSet);

            if(empty($eachUnder)){
                $inLoop = false;
            } else {
                $LoopEmpCodeSet = "";
                foreach ($eachUnder as $emp) {
                    $LoopEmpCodeSet .= $emp->level_id.",";
                }
                $globalEmpCodeSet .= $LoopEmpCodeSet;
            }
            $loopCnt = $loopCnt + 1;
        }
        
        return $globalEmpCodeSet;
    }

    function GetAllParentLevel($levelId, $itself)
    {
        $resData = collect();

        // initial data
        $data = AppraisalLevel::select('level_id', 'appraisal_level_name', 'parent_id')
            ->where('level_id', $levelId)->where('is_org', 1)->first();
        if($itself === true){
            $resData = $resData->push($data);
        }

        $inLoop = true;
        while ($inLoop) {
            $data = AppraisalLevel::select('level_id', 'appraisal_level_name', 'parent_id')
                ->where('level_id', $data['parent_id'])->where('is_org', 1)->first();
            if($data){
                $resData = $resData->push($data);
                if($data['parent_id'] == 0){
                    $inLoop = false;
                }
            } else {
                $inLoop = false;
            }
        }
        
        return $resData;
    }

    function GetallUnderEmpByOrg($paramEmp) {
        $globalEmpCodeSet = "";
        $inLoop = true;
        $loopCnt = 1;

        $dataEmp = DB::table('org')
        ->join('employee', 'employee.org_id', '=', 'org.org_id')
        ->select('org.org_code')
        ->where('employee.emp_id', $paramEmp)
        ->first();

        while ($inLoop){
            if($loopCnt == 1){
                $LoopEmpCodeSet = $dataEmp->org_code.",";
            }
            
            // Check each under //
            $eachUnder = DB::select("
                SELECT org_code
                FROM org
                WHERE find_in_set(parent_org_code, '{$LoopEmpCodeSet}')
            ");
            log::info($LoopEmpCodeSet);

            if(empty($eachUnder)){
                $inLoop = false;
            } else {
                $LoopEmpCodeSet = "";
                foreach ($eachUnder as $emp) {
                    $LoopEmpCodeSet .= $emp->org_code.",";
                }
                $globalEmpCodeSet .= $LoopEmpCodeSet;
            }
            $loopCnt = $loopCnt + 1;
        }
        
        return $globalEmpCodeSet;
    }

    function GetallUnderOrgByOrg($paramEmp) {
        $globalEmpCodeSet = "";
        $inLoop = true;
        $loopCnt = 1;

        $dataParam = DB::table('org')->select('org_code')->where('org_id', $paramEmp)->first();

        while ($inLoop){
            if($loopCnt == 1){
                $LoopEmpCodeSet = $dataParam->org_code.",";
            }
            
            // Check each under //
            $eachUnder = DB::select("
                SELECT org_code
                FROM org
                WHERE find_in_set(parent_org_code, '{$LoopEmpCodeSet}')
            ");
            log::info($LoopEmpCodeSet);

            if(empty($eachUnder)){
                $inLoop = false;
            } else {
                $LoopEmpCodeSet = "";
                foreach ($eachUnder as $emp) {
                    $LoopEmpCodeSet .= $emp->org_code.",";
                }
                $globalEmpCodeSet .= $LoopEmpCodeSet;
            }
            $loopCnt = $loopCnt + 1;
        }
        
        return $globalEmpCodeSet;
    }

    function standard_deviation($aValues) {
        $fMean = array_sum($aValues) / count($aValues);
        //print_r($fMean);
        $fVariance = 0.0;
        foreach ($aValues as $i) {
            $fVariance += pow($i - $fMean, 2);

        }
        
        $size = empty(count($aValues) - 1) ? 1 : count($aValues) - 1;
        return (float) sqrt($fVariance)/sqrt($size);
    }

    function isAll() {
         $all_emp = DB::select("
            SELECT sum(b.is_all_employee) count_no
            FROM employee a
            LEFT OUTER JOIN appraisal_level b on a.level_id = b.level_id
            WHERE emp_code = ?
        ", array(Auth::id()));
         return $all_emp;
    }

    function GetChiefEmpDeriveLevel($paramEmp, $paramDeriveLevel) {
        $chiefEmpId = 0;
        $chiefEmpCode = '';
        $initChiefEmp = DB::table('employee')
        ->select('chief_emp_code','level_id','emp_id')
        ->where('emp_code', $paramEmp)
        ->first();

        // if($paramDeriveLevel==(int)$initChiefEmp[0]->level_id) {
        //  return ['emp_id' => $initChiefEmp[0]->emp_id, 'chief_emp_code' => $initChiefEmp[0]->chief_emp_code];
        // }

        if(empty($initChiefEmp)) {
            $curChiefEmp = null;
        } else {
            $curChiefEmp = $initChiefEmp->chief_emp_code;
        }

        while ($curChiefEmp != "0") {
            $getChief = DB::table('employee')
            ->select('emp_id', 'level_id', 'chief_emp_code')
            ->where('emp_code', $curChiefEmp)
            ->first();

            if(! empty($getChief) ){
                if($getChief->level_id == $paramDeriveLevel){ 
                    $chiefEmpId = $getChief->emp_id;
                    $chiefEmpCode = $getChief->chief_emp_code;
                    $curChiefEmp = "0";
                } else {
                    if($getChief->chief_emp_code != "0"){
                        $curChiefEmp = $getChief->chief_emp_code;
                    } else {
                        $curChiefEmp = "0";
                    }
                }
            } else {
                $curChiefEmp = "0";
            }
        }

        return ['emp_id' => $chiefEmpId, 'chief_emp_code' => $chiefEmpCode];
    }

    function GetParentOrgDeriveLevel($paramOrg, $paramDeriveLevel) {
        $parentOrgId = 0;
        $parentOrgCode = '';
        $initParentOrg = DB::table('org')
        ->select('parent_org_code','level_id','org_id')
        ->where('org_code', $paramOrg)
        ->first();

        // if($paramDeriveLevel==(int)$initParentOrg[0]->level_id) {
        //  return ['org_id' => $initParentOrg[0]->org_id, 'parent_org_code' => $initParentOrg[0]->parent_org_code];
        // }

        if(empty($initParentOrg)) {
            $curParentOrg = null;
        } else {
            $curParentOrg = $initParentOrg->parent_org_code;
        }

        while ($curParentOrg != "0") {
            $getChief = DB::table('org')
            ->select('org_id', 'level_id', 'parent_org_code')
            ->where('org_code', $curParentOrg)
            ->first();

            if(!empty($getChief)) {
                if($getChief->level_id == $paramDeriveLevel) {
                    $parentOrgId = $getChief->org_id;
                    $parentOrgCode = $getChief->parent_org_code;
                    $curParentOrg = "0";
                } else {
                    if($getChief->parent_org_code != "0" || $getChief->parent_org_code != "") {
                        $curParentOrg = $getChief->parent_org_code;
                    } else {
                        $curParentOrg = "0";
                    }
                }
            } else {
                $curParentOrg = "0";
            }
        }

        return ['org_id' => $parentOrgId, 'parent_org_code' => $parentOrgCode];
    }

    public function fakeAdjust(Request $request) {
        $appraisalLevel = AppraisalLevel::select('level_id', 'appraisal_level_name')->where('is_start_cal_bonus', 1)->where('is_org', 1)->first();
        $levelList = $this->GetAllParentLevel($appraisalLevel->level_id, true); // true คือแสดง level ตัวเองด้วย

        $levelArr = [];
        foreach ($levelList as $key => $value) {
            array_push($levelArr, $value->level_id);
        }

        $levelArr = implode(",", $levelArr);

        $hr_emp = DB::select("
            SELECT sum(b.is_hr) count_no
            FROM employee a
            LEFT OUTER JOIN appraisal_level b on a.level_id = b.level_id
            WHERE emp_code = ?
        ", array(Auth::id()));

        if ($hr_emp[0]->count_no > 0) {
            $stage = DB::table("appraisal_stage")
            ->select('edit_flag')
            ->where('stage_id', $request->stage_id)
            ->first();

            $items = DB::select("
                SELECT e.emp_id, e.emp_name, org.level_id org_level_id
                FROM employee e
                INNER JOIN org ON org.org_id = e.org_id
                WHERE find_in_set(org.level_id, '{$levelArr}')
                ORDER BY org.org_code
            ");

        }

        return response()->json([
            'data' => empty($items) ? [] : $items, 
            'edit_flag' => empty($stage->edit_flag) ? [] : $stage->edit_flag
        ]);
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

    public function YearSalaryList(Request $request)
    {
        $years = DB::select("
            SELECT DISTINCT appraisal_year appraisal_year_id,
            appraisal_year
            from appraisal_period
            LEFT OUTER JOIN system_config on system_config.current_appraisal_year = appraisal_period.appraisal_year
            where is_raise = 1
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

      public function PeriodListhr(Request $request)
    {
        $periods = DB::table("appraisal_period")->select('period_id', 'appraisal_period_desc')
            ->where('is_raise', 1)
            ->where('appraisal_year', $request->appraisal_year)
            ->get();
        return response()->json($periods);
    }

    public function PeriodSalaryList(Request $request)
    {
        $periods = DB::table("appraisal_period")->select('period_id', 'appraisal_period_desc')
            ->where('is_raise', 1)
            ->where('appraisal_year', $request->appraisal_year)
            ->get();
        return response()->json($periods);
    }

    public function FormList(Request $request)
    {
        $forms = DB::table('appraisal_form')->select('appraisal_form_id', 'appraisal_form_name')
            ->where('is_active', 1)
            // ->where('is_bonus', 1)
            ->get();
        return response()->json($forms);
    }
    
    public function FormListhr(Request $request)
    {
        $forms = DB::table('appraisal_form')->select('appraisal_form_id', 'appraisal_form_name')
            /*->where('is_active', 1)
            ->where('is_bonus', 1)*/
            ->where('is_raise', 1)
            ->get();
        return response()->json($forms);
    }

    public function FormSalaryList(Request $request)
    {
        $forms = DB::table('appraisal_form')->select('appraisal_form_id', 'appraisal_form_name')
            ->where('is_raise', 1)
            ->get();
        return response()->json($forms);
    }

    public function FormMpiList(Request $request)
    {
        $forms = DB::table('appraisal_form')->select('appraisal_form_id', 'appraisal_form_name')
            ->where('is_mpi', 1)
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
                ->orderBy('seq_no', 'asc')
                ->get();
        } else {
            $employee = Employee::find(Auth::id());
            $gue_emp_level = $this->GetallUnderLevel($employee->level_id);
            $underEmps = $this->GetallUnderEmp(Auth::id());
            $indLevels = DB::select("
                SELECT l.level_id, l.appraisal_level_name
                FROM appraisal_level l
                INNER JOIN employee e on e.level_id = l.level_id
                WHERE l.is_active = 1
                AND is_individual = 1
                AND find_in_set(e.level_id, '".$gue_emp_level."')
                GROUP BY l.level_id
                ORDER BY l.seq_no ASC
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
                GROUP BY org.level_id
            ");
        } else {
            $dataOrg = DB::select("
                SELECT org.level_id
                FROM org
                INNER JOIN employee e ON e.org_id = org.org_id
                WHERE e.emp_code = '".Auth::id()."'
            ");
            $gue_org_level = $this->GetallUnderLevel($dataOrg[0]->level_id);
            $indLevelStr = empty($request->individual_level) ? "": " AND emp.level_id = ".$request->individual_level;
            $orgLevels = DB::select("
                SELECT org.level_id, vel.appraisal_level_name
                FROM org 
                INNER JOIN appraisal_level vel ON vel.level_id = org.level_id
                WHERE org.is_active = 1
                AND vel.is_org = 1
                AND find_in_set(org.level_id, '{$gue_org_level}')
                AND org.org_id IN(
                    SELECT DISTINCT emp.org_id
                    FROM employee emp 
                    WHERE emp.is_active = 1
                    ".$indLevelStr."
                )
                GROUP BY org.level_id
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
            $employee = Employee::find(Auth::id());
            $gueOrgCodeByOrgId = $this->GetallUnderOrgByOrg($employee->org_id);
            $orgs = DB::select("
                SELECT org.org_id, org.org_name
                FROM org
                INNER JOIN employee emp ON emp.org_id = org.org_id
                WHERE org.is_active = 1
                AND (org.org_id = '{$employee->org_id}' OR find_in_set(org.org_code, '{$gueOrgCodeByOrgId}'))
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

        $indLevelQryStr = empty($request->individual_level) ? "" : " AND e.level_id = ".$request->individual_level;
        if($all_emp[0]->count_no > 0) {
            if(empty($request->organization_id)) {
                $gueOrgCodeByOrgId = '';
            } else {
                $findIn = $this->GetallUnderOrgByOrg($request->organization_id);
                $gueOrgCodeByOrgId = "and (e.org_id = '{$request->organization_id}' OR find_in_set(org.org_code, '".$findIn."'))";
            }

            $items = DB::select("
                Select e.emp_code, e.emp_name, e.emp_id
                From employee e
                inner join org on org.org_id = e.org_id
                Where e.emp_name like '%{$request->employee_name}%'
                " . $gueOrgCodeByOrgId . "
                " . $indLevelQryStr . "
                and e.is_active = 1
                Order by e.emp_name
                limit 15
            ");
        } else {
            $employee = Employee::find(Auth::id());
            $findIn = $this->GetallUnderOrgByOrg($employee->org_id);
            if(empty($request->organization_id)) {
                $gueOrgCodeByOrgId = "and (e.org_id = '{$employee->org_id}' OR find_in_set(org.org_code, '".$findIn."'))";
            } else {
                $gueOrgCodeByOrgId = "and (e.org_id = '{$request->organization_id}' OR find_in_set(org.org_code, '".$findIn."'))";
            }

            $items = DB::select("
                Select e.emp_code, e.emp_name, e.emp_id
                From employee e
                inner join org on org.org_id = e.org_id
                Where e.emp_name like '%{$request->employee_name}%'
                " . $gueOrgCodeByOrgId . "
                " . $indLevelQryStr . "
                and e.is_active = 1
                Order by e.emp_name
                limit 15
            ");
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

        $empIdQryStr = empty($request->employee_id) ? "" : " and a.emp_id = ".$request->employee_id;
        

        if ($all_emp[0]->count_no > 0) {
            if(empty($request->organization_id)) {
                $gueOrgCodeByOrgId = '';
            } else {
                $findIn = $this->GetallUnderOrgByOrg($request->organization_id);
                $gueOrgCodeByOrgId = "and (a.org_id = '{$request->organization_id}' OR find_in_set(org.org_code, '".$findIn."'))";
            }

            $items = DB::select("
                Select distinct b.position_id, b.position_name
                From employee a 
                left outer join position b on a.position_id = b.position_id
                left outer join org on org.org_id = a.org_id
                Where a.is_active = 1
                and b.is_active = 1
                ".$gueOrgCodeByOrgId."
                ".$empIdQryStr."
                Order by position_name
            ");
        } else {
            $employee = Employee::find(Auth::id());
            $findIn = $this->GetallUnderOrgByOrg($employee->org_id);
            if(empty($request->organization_id)) {
                $gueOrgCodeByOrgId = "and (a.org_id = '{$employee->org_id}' OR find_in_set(org.org_code, '".$findIn."'))";
            } else {
                $gueOrgCodeByOrgId = "and (a.org_id = '{$request->organization_id}' OR find_in_set(org.org_code, '".$findIn."'))";
            }

            $items = DB::select("
                Select distinct b.position_id, b.position_name
                From employee a 
                left outer join position b on a.position_id = b.position_id
                left outer join org on org.org_id = a.org_id
                Where a.is_active = 1
                and b.is_active = 1
                ".$gueOrgCodeByOrgId."
                ".$empIdQryStr."
                Order by position_name
            ");
        }
        
        return response()->json($items);
    }

    // public function OrganizationList(Request $request)
    // {
    //     $all_emp = DB::select("
    //         SELECT sum(l.is_all_employee) count_no
    //         FROM appraisal_level l
    //         INNER JOIN org o on o.level_id = l.level_id
    //         INNER JOIN employee e on e.org_id = o.org_id
    //         WHERE e.emp_code = ?
    //     ", array(Auth::id()));

    //     $indLevelStr = empty($request->individual_level) ? "": " AND emp.level_id = ".$request->individual_level;
    //     $orgLevelStr = empty($request->organization_level) ? "": " AND org.level_id = ".$request->organization_level;
    //     if ($all_emp[0]->count_no > 0) {
    //         $orgs = DB::select("
    //             SELECT org.org_id, org.org_name
    //             FROM org
    //             INNER JOIN employee emp ON emp.org_id = org.org_id
    //             WHERE org.is_active = 1
    //             ".$indLevelStr."
    //             ".$orgLevelStr."
    //             GROUP BY org.org_id
    //             ORDER BY org.org_code ASC
    //         ");
    //     } else {
    //         $employee = Employee::find(Auth::id());
    //         $gueOrgCodeByOrgId = $this->GetallUnderOrgByOrg($employee->org_id);
    //         $orgs = DB::select("
    //             SELECT org.org_id, org.org_name
    //             FROM org
    //             INNER JOIN employee emp ON emp.org_id = org.org_id
    //             WHERE org.is_active = 1
    //             AND find_in_set(org.org_code, '{$gueOrgCodeByOrgId}')
    //             ".$indLevelStr."
    //             ".$orgLevelStr."
    //             GROUP BY org.org_id
    //             ORDER BY org.org_code ASC
    //         ");
    //     }

    //     return response()->json($orgs);
    // }


    // public function GetEmployeeName(Request $request)
    // {
    //     $emp = Employee::find(Auth::id());
    //     $all_emp = DB::select("
    //         SELECT sum(b.is_all_employee) count_no
    //         FROM employee a
    //         LEFT OUTER JOIN appraisal_level b ON a.level_id = b.level_id
    //         WHERE emp_code = ?
    //         ", array(Auth::id())
    //     );

    //     $indLevelQryStr = empty($request->individual_level) ? "" : " AND e.level_id = ".$request->individual_level;
    //     $orgIdQryStr = empty($request->organization_id) ? "" : " AND e.org_id = ".$request->organization_id;
        
    //     if($all_emp[0]->count_no > 0) {
    //         $items = DB::select("
    //             Select e.emp_code, e.emp_name, e.emp_id
    //             From employee e
    //             Where e.emp_name like '%{$request->employee_name}%'
    //             and e.is_active = 1
    //             ".$indLevelQryStr."
    //             ".$orgIdQryStr."
    //             Order by e.emp_name
    //             limit 15
    //         ");
    //     } else {
    //         $employee = Employee::find(Auth::id());
    //         $gueOrgCodeByOrgId = $this->GetallUnderOrgByOrg($employee->org_id);
    //         $items = DB::select("
    //             Select e.emp_code, e.emp_name, e.emp_id
    //             From employee e
    //             inner join org on org.org_id = e.org_id
    //             Where find_in_set(org.org_code, '".$gueOrgCodeByOrgId."')
    //             And e.emp_name like '%{$request->employee_name}%'
    //             " . $indLevelQryStr . "
    //             " . $orgIdQryStr . "
    //             and e.is_active = 1
    //             Order by e.emp_name
    //             limit 15
    //         ");
    //     }
        
    //     return response()->json($items);
    // }
    

    // public function GetPositionName(Request $request)
    // {
    //     $emp = Employee::find(Auth::id());
    //     $all_emp = DB::select("
    //         SELECT sum(b.is_all_employee) count_no
    //         from employee a
    //         left outer join appraisal_level b
    //         on a.level_id = b.level_id
    //         where emp_code = ?
    //         ", array(Auth::id())
    //     );

    //     $orgIdQryStr = empty($request->organization_id) ? "" : " and a.org_id = ".$request->organization_id;
    //     $empIdQryStr = empty($request->employee_id) ? "" : " and a.emp_id = ".$request->employee_id;
        

    //     if ($all_emp[0]->count_no > 0) {
    //         $items = DB::select("
    //             Select distinct b.position_id, b.position_name
    //             From employee a 
    //             left outer join position b on a.position_id = b.position_id
    //             where a.is_active = 1
    //             and b.is_active = 1
    //             ".$orgIdQryStr."
    //             ".$empIdQryStr."
    //             Order by position_name
    //         ");
    //     } else {
    //         $employee = Employee::find(Auth::id());
    //         $gueOrgCodeByOrgId = $this->GetallUnderOrgByOrg($employee->org_id);
    //         $items = DB::select("
    //             Select distinct b.position_id, b.position_name
    //             From employee a 
    //             left outer join position b on a.position_id = b.position_id
    //             left outer join org on org.org_id = a.org_id
    //             Where find_in_set(org.org_code, '".$gueOrgCodeByOrgId."')
    //             and a.is_active = 1
    //             and b.is_active = 1
    //             ".$orgIdQryStr."
    //             ".$empIdQryStr."
    //             Order by position_name
    //         ");
    //     }
        
    //     return response()->json($items);
    // }


    public function StatusList_bakup20190124_byWirun(Request $request)
    {
        if(empty($request->flag)) {
            return response()->json(['status' => 400, 'data' => 'Parameter flag is required']);
        }
        $empAuth = $this->empAuth();
        //hard code ไว้ กรณีหาคนที่เข้ามาว่าอยู่ระดับไหนใน assessor_group
        if($empAuth->is_hr==1) {
            $in = 5; //คือ hr
        } else {
            $in = 1; //หัวหน้าของพนักงาน
        }
        $flag = "ast.".$request->flag." = 1";
        if($request->flag=='appraisal_flag') {
        	$appraisal_form_id = empty($request->appraisal_form_id) ? "" : " AND (find_in_set('{$request->appraisal_form_id}', appraisal_form_id) OR appraisal_form_id = 'all')";
	        $status = DB::select("
	            SELECT ast.stage_id, ast.status
	            FROM appraisal_stage ast
	            INNER JOIN emp_result er ON er.stage_id = ast.stage_id
	            WHERE {$flag}
	            AND (find_in_set('{$in}', ast.assessor_see) OR ast.assessor_see = 'all') 
	            {$appraisal_form_id}
	            AND (find_in_set('{$request->appraisal_type_id}', ast.appraisal_type_id) OR ast.appraisal_type_id = 'all')
	            GROUP BY ast.stage_id
	            ORDER BY ast.stage_id
	        ");
        } else {
        	$status = DB::select("
	            SELECT ast.stage_id, ast.status
	            FROM appraisal_stage ast
	            INNER JOIN emp_result er ON er.stage_id = ast.stage_id
	            WHERE {$flag}
	            AND (find_in_set('{$in}', ast.assessor_see) OR ast.assessor_see = 'all')
	            AND (find_in_set('{$request->appraisal_form_id}', ast.appraisal_form_id) OR ast.appraisal_form_id = 'all')
	            AND (find_in_set('{$request->appraisal_type_id}', ast.appraisal_type_id) OR ast.appraisal_type_id = 'all')
	            GROUP BY ast.stage_id
	            ORDER BY ast.stage_id
	        ");
        }
        
        return response()->json($status);
    }

    public function StatusList(Request $request)
    {
        if(empty($request->flag)) {
            return response()->json(['status' => 400, 'data' => 'Parameter flag is required']);
        }

        $empAuth = $this->empAuth();

        //hard code ไว้ กรณีหาคนที่เข้ามาว่าอยู่ระดับไหนใน assessor_group
        if($empAuth->is_hr==1) {
            $in = 5; //คือ hr
        } else {
            $in = 1; //หัวหน้าของพนักงาน
        }

        $flag = "ast.".$request->flag." = 1";

        // set parameter in sql where clause
        if(empty($request->appraisal_form_id)){
            $appraisalFormQryStr = " ";
            $stageFormQryStr = " ";
        } else {
            $appraisalFormQryStr = " AND er.appraisal_form_id = '{$request->appraisal_form_id}'";
            $stageFormQryStr = " AND (find_in_set('{$request->appraisal_form_id}', ast.appraisal_form_id) OR ast.appraisal_form_id = 'all')";
        }
        if(empty($request->appraisal_type_id)){
            $appraisalTypeQryStr = " ";
            $stageTypeQryStr = " ";
        } else {
            $appraisalTypeQryStr = " AND er.appraisal_type_id = '{$request->appraisal_type_id}'";
            $stageTypeQryStr = " AND (find_in_set('{$request->appraisal_type_id}', ast.appraisal_type_id) OR ast.appraisal_type_id = 'all')";
        }
        $empLevelQryStr = empty($request->emp_level) ? " ": " AND er.level_id = '{$request->emp_level}'";
        $orgLevelQryStr = empty($request->org_level) ? " ": " AND org.level_id = '{$request->org_level}'";
        $orgIdQryStr = empty($request->org_id) ? " ": " AND er.org_id = '{$request->org_id}'";
        $appraisalYearQryStr = empty($request->appraisal_year) ? " ": " AND ap.appraisal_year = '{$request->appraisal_year}'";
        $periodIdQryStr = empty($request->period_id) ? " ": " AND er.period_id = '{$request->period_id}'";
        $empIdQryStr = empty($request->emp_id) ? " ": " AND er.emp_id = '{$request->emp_id}'";
        if(gettype($request->position_id) == 'string'){ // Position String
            $positionIdQryStr = empty($request->position_id) ? " ": " AND er.position_id = '{$request->position_id}'";
        } else { // Position Array or Object
            $request->position_id = in_array('null', $request->position_id) ? "" : $request->position_id;
            $positionIdQryStr = empty($request->position_id) ? "" : " AND er.position_id IN (".implode(',', $request->position_id).")";
        } 

        // get status from db
	    $status = DB::select("
	        SELECT ast.stage_id, ast.status
	        FROM appraisal_stage ast
            INNER JOIN emp_result er ON er.stage_id = ast.stage_id
            LEFT OUTER JOIN org ON org.org_id = er.org_id
            LEFT OUTER JOIN appraisal_period ap ON ap.period_id = er.period_id
	        WHERE {$flag}
            AND (find_in_set('{$in}', ast.assessor_see) OR ast.assessor_see = 'all')
            {$stageFormQryStr}
            {$stageTypeQryStr}
            AND ast.stage_id IN(
                SELECT er.stage_id
                FROM emp_result er
                LEFT OUTER JOIN org ON org.org_id = er.org_id
                LEFT OUTER JOIN appraisal_period ap ON ap.period_id = er.period_id
                WHERE 1 = 1
                {$appraisalFormQryStr}
                {$appraisalTypeQryStr}
                {$empLevelQryStr}
                {$orgLevelQryStr}
                {$orgIdQryStr}
                {$appraisalYearQryStr}
                {$periodIdQryStr}
                {$empIdQryStr}
                {$positionIdQryStr}
            )
	        GROUP BY ast.stage_id
	        ORDER BY ast.stage_id
        ");
        
        return response()->json($status);
    }

    function to_action_call($request) {

        if(empty($request->flag)) {
            exit(json_encode(['status' => 400, 'data' => 'Parameter flag is required']));
        }

        $empAuth = $this->empAuth();
        $orgAuth = $this->orgAuth();
        // $stage_in_level = $this->getFieldLevelStage($empAuth->level_id, $orgAuth->level_id);
        // $stage_in_form = $this->getFieldFormStage($request->appraisal_form_id);

        //hard code ไว้ กรณีหาคนที่เข้ามาว่าอยู่ระดับไหนใน assessor_group
        if($empAuth->is_hr==1) {
            $in = 5; //คือ hr
        } else {
            $in = 1; //หัวหน้าของพนักงาน
        }

        $stage = DB::table("appraisal_stage")
        ->select("to_stage_id")
        ->where("stage_id", $request->stage_id)
        ->first();

        $stage = empty($stage->to_stage_id) || $stage == 'null' ? "''" : $stage->to_stage_id;

        if($request->flag=='appraisal_flag') {
            //ส่วนเฉพาะหน้า Appraisal360

            // $appraisal_form_id = empty($request->appraisal_form_id) ? "" : " AND (appraisal_form_id = '{$request->appraisal_form_id}' OR appraisal_form_id = 'all')";
            // $to_action = DB::select("
            //     SELECT stage_id, to_action
            //     FROM appraisal_stage
            //     WHERE stage_id IN ({$stage})
            //     AND stage_id IN ({$stage_in_level}) #แสดง stage เฉพาะ level ที่มีสิธิ์เห็น
            //     {$appraisal_form_id}
            //     AND (appraisal_type_id = '{$request->appraisal_type_id}' OR appraisal_type_id = 'all')
            //     AND {$request->flag} = 1
            //     AND find_in_set('{$request->appraisal_group_id}', assessor_see)
            //     ORDER BY stage_id
            // ");
            
            $appraisal_form_id = empty($request->appraisal_form_id) ? "" : " AND (find_in_set('{$request->appraisal_form_id}', appraisal_form_id) OR appraisal_form_id = 'all')";
            $to_action = DB::select("
                SELECT stage_id, to_action
                FROM appraisal_stage
                WHERE stage_id IN ({$stage})
                 AND (find_in_set('{$empAuth->level_id}', level_id) OR find_in_set('{$orgAuth->level_id}', level_id) OR level_id = 'all')
                {$appraisal_form_id}
                AND (find_in_set('{$request->appraisal_type_id}', appraisal_type_id) OR appraisal_type_id = 'all')
                AND find_in_set('{$request->appraisal_group_id}', assessor_see)
                ORDER BY stage_id
            ");
        } else {
            //หน้าที่ใช้ stage นี่
            //EmpJudgement
            //BonusJudgement
            //Assignment

            // $to_action = DB::select("
            //     SELECT stage_id, to_action
            //     FROM appraisal_stage
            //     WHERE stage_id IN ({$stage})
            //     AND stage_id IN ({$stage_in_level}) #แสดง stage เฉพาะ level ที่มีสิธิ์เห็น
            //     AND {$request->flag} = 1
            //     AND (assessor_see LIKE '%{$in}%' OR assessor_see = 'all')
            //     AND (appraisal_form_id = '{$request->appraisal_form_id}' OR appraisal_form_id = 'all')
            //     AND (appraisal_type_id = '{$request->appraisal_type_id}' OR appraisal_type_id = 'all')
            //     ORDER BY stage_id
            // ");

             $to_action = DB::select("
                SELECT stage_id, to_action
                FROM appraisal_stage
                WHERE stage_id IN ({$stage})
                AND (find_in_set('{$empAuth->level_id}', level_id) OR find_in_set('{$orgAuth->level_id}', level_id) OR level_id = 'all')
                AND (find_in_set('{$in}', assessor_see) OR assessor_see = 'all')
                AND (find_in_set('{$request->appraisal_form_id}', appraisal_form_id) OR appraisal_form_id = 'all')
                AND (find_in_set('{$request->appraisal_type_id}', appraisal_type_id) OR appraisal_type_id = 'all')
                ORDER BY stage_id
            ");
        }

        return $to_action;
    }

    public function to_action(Request $request) {

        if(empty($request->flag)) {
            return response()->json(['status' => 400, 'data' => 'Parameter flag is required']);
        }

        $empAuth = $this->empAuth();
        $orgAuth = $this->orgAuth();
        // $stage_in_level = $this->getFieldLevelStage($empAuth->level_id, $orgAuth->level_id);
        // $stage_in_form = $this->getFieldFormStage($request->appraisal_form_id);

        //hard code ไว้ กรณีหาคนที่เข้ามาว่าอยู่ระดับไหนใน assessor_group
        if($empAuth->is_hr==1) {
            $in = 5; //คือ hr
        } else {
            $in = 1; //หัวหน้าของพนักงาน
        }

        $stage = DB::table("appraisal_stage")
        ->select("to_stage_id")
        ->where("stage_id", $request->stage_id)
        ->first();

        $stage = empty($stage->to_stage_id) || $stage == 'null' ? "''" : $stage->to_stage_id;
        $appraisal_form_id = empty($request->appraisal_form_id) ? "" : " AND (find_in_set('{$request->appraisal_form_id}', appraisal_form_id) OR appraisal_form_id = 'all')";
        if($request->flag=='appraisal_flag') {
            //ส่วนเฉพาะหน้า Appraisal360
            $to_action = DB::select("
                SELECT stage_id, to_action
                FROM appraisal_stage
                WHERE stage_id IN ({$stage})
                 AND (find_in_set('{$empAuth->level_id}', level_id) OR find_in_set('{$orgAuth->level_id}', level_id) OR level_id = 'all')
                {$appraisal_form_id}
                AND (find_in_set('{$request->appraisal_type_id}', appraisal_type_id) OR appraisal_type_id = 'all')
                AND find_in_set('{$request->appraisal_group_id}', assessor_see)
                ORDER BY stage_id
            ");
        } else {
            //หน้าที่ใช้ stage นี่
            //EmpJudgement
            //BonusJudgement
            //Assignment
            $to_action = DB::select("
                SELECT stage_id, to_action
                FROM appraisal_stage
                WHERE stage_id IN ({$stage})
                AND (find_in_set('{$empAuth->level_id}', level_id) OR find_in_set('{$orgAuth->level_id}', level_id) OR level_id = 'all')
                AND (find_in_set('{$in}', assessor_see) OR assessor_see = 'all')
                {$appraisal_form_id}
                AND (find_in_set('{$request->appraisal_type_id}', appraisal_type_id) OR appraisal_type_id = 'all')
                ORDER BY stage_id
            ");
        }

        return response()->json($to_action);
    }
}
