<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Bonus\BonusAppraisalController;
use App\Http\Controllers\Bonus\EmpResultJudgementController;
use App\Http\Controllers\Bonus\AdvanceSearchController;

use App\EmpResult;
use App\AppraisalStage;
use App\Employee;

use Auth;
use DB;
use Validator;
use Exception;
use Excel;
use Log;

class BonusAdjustmentController extends Controller
{
    public function __construct()
    {
       $this->middleware('jwt.auth');
       $this->advanSearch = new AdvanceSearchController;
    }


    public function index(Request $request)
    {
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
        $employee = Employee::where('is_active','1')->find(Auth::id());
        if ($all_emp[0]->count_no > 0) {
            if(empty($request->org_id)) {
                $qryOrgId = "";
            } else {
                $qryOrgId = "AND (er.org_id = '{$request->org_id}' OR find_in_set(org.org_code, '{$gueOrgCodeByOrgId}'))";
            }
        } else {
            if(empty($request->org_id)) {
                $gueOrgCodeByOrgId = $this->advanSearch->GetallUnderOrgByOrg($employee->org_id);
                $qryOrgId = "AND (er.org_id = '{$employee->org_id}' OR find_in_set(org.org_code, '{$gueOrgCodeByOrgId}') )";
            } else {
                $qryOrgId = "AND (er.org_id = '{$request->org_id}' OR find_in_set(org.org_code, '{$gueOrgCodeByOrgId}'))";
            }
        }
        
        $request->position_id = in_array('null', $request->position_id) ? "" : $request->position_id;
        $qryPositionId = empty($request->position_id) ? "" : " AND er.position_id IN (".implode(',', $request->position_id).")";
        $qryStageId = empty($request->stage_id) ? "": " AND er.stage_id = '{$request->stage_id}'";
        $qryFormId = empty($request->appraisal_form) ? "": " AND er.appraisal_form_id = '{$request->appraisal_form}'";

        $dataInfo = DB::select("
            SELECT er.emp_result_id,
                e.emp_code,
                e.emp_name,
                vel.appraisal_level_name,
                pos.position_name,
                org.org_name,
                IF(
                    IFNULL(CAST(FROM_BASE64(er.net_s_amount) AS DECIMAL(10,2)),0)=0, 
                    IF(
                        IFNULL(CAST(FROM_BASE64(er.s_amount) AS DECIMAL(10,2)),0)=0, 
                        CAST(FROM_BASE64(e.s_amount) AS DECIMAL(10,2)), 
                        CAST(FROM_BASE64(er.s_amount) AS DECIMAL(10,2))), 
                    CAST(FROM_BASE64(er.net_s_amount) AS DECIMAL(10,2))
                ) AS s_amount,
                CAST(FROM_BASE64(er.adjust_b_amount) AS DECIMAL(10,2)) AS b_amount,
                CAST(FROM_BASE64(er.adjust_b_amount) AS DECIMAL(10,2)) AS adjust_b_amount,
                er.adjust_b_rate AS b_rate,
                er.adjust_b_rate,
                er.status,
                sta.edit_flag
            FROM emp_result er 
            INNER JOIN employee e ON e.emp_id = er.emp_id
            INNER JOIN appraisal_level vel ON vel.level_id = er.level_id
            INNER JOIN org ON org.org_id = er.org_id
            INNER JOIN position pos ON pos.position_id = er.position_id
            INNER JOIN appraisal_stage sta ON sta.stage_id = er.stage_id
            WHERE er.period_id = '{$request->period_id}'
            ".$qryFormId ."
            ".$qryEmpLevel."
            ".$qryOrgLevel."
            ".$qryOrgId."
            ".$qryEmpId."
            ".$qryPositionId."
            ".$qryStageId."
            ORDER BY e.org_id, er.level_id, e.emp_code
        ");

        // $dataResult = [];
        // $a = [];
        // foreach ($dataInfo as $kInfo => $vInfo) { // loop level_id desc
        //     $a[] = $value->org_code;

        //     foreach ($a as $kA => $vA) {
                
        //     }
        //     unset($a[$value->org_code]);
        // }
          // Number of items per page
          if($request->rpp == 'All') {
            $perPage = count(empty($dataInfo) ? 10 : $dataInfo);
        } else {
            empty($request->rpp) ? $perPage = count(empty($dataInfo) ? 10 : $dataInfo) : $perPage = $request->rpp;
        }

        $dataInfo = (new BonusAppraisalController)->SetPagination($request->page, $perPage, $dataInfo);
        return response()->json($dataInfo);
    }


    public function SavedAndConfirm(Request $request)
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

        $errors_validator = [];
        $requestValid = Validator::make($request->all(), [
            'appraisal_year' => 'required',
            'period_id' => 'required',
            'confirm_flag' => 'required'
        ]);
        if ($requestValid->fails()) {
            $errors_validator[] = $requestValid->errors();
            return response()->json(['status' => 400, 'data' => $errors_validator]);
        }

        foreach ($request->data as $data) {
            try {
                $empResult = EmpResult::findOrFail($data['emp_result_id']);
            } catch (ModelNotFoundException $e) {
                return response()->json(['status' => 400, 'data' => "emp result not found"]);
            }

            $empResult->adjust_b_amount = base64_encode($data['adjust_b_amount']);
            $empResult->adjust_b_rate = $data['adjust_b_rate'];
            $empResult->updated_by = Auth::id();
            if($request->confirm_flag == "1"){
                $empResult->stage_id = $request->stage_id;
                $empResult->status = AppraisalStage::find($request->stage_id)->status;
            }
            $empResult->save();
        }

        return response()->json(['status' => 200, 'data' => "Saved Successfully"]);
    }


    public function Export(Request $request)
    {
        // set parameter for query 
        $request->period_id = empty($request->period_id) ? "": $request->period_id;
        $qryEmpLevel = empty($request->emp_level) ? "": " AND er.level_id = '{$request->emp_level}'";
        $qryOrgLevel = empty($request->org_level) ? "": " AND org.level_id = '{$request->org_level}'";
        $qryOrgId = empty($request->org_id) ? "": " AND er.org_id = '{$request->org_id}'";
        $qryEmpId = empty($request->emp_id) ? "": " AND er.emp_id = '{$request->emp_id}'";
        // $qryPositionId = empty($request->position_id) ? "": " AND er.position_id = '{$request->position_id}'";
        $qryPositionId = empty($request->position_id) ? "" : " AND er.position_id IN ({$request->position_id})";
        $qryStageId = empty($request->stage_id) ? "": " AND er.stage_id = '{$request->stage_id}'";

        /* old template
        $dataInfo = DB::select("
            SELECT 
                e.emp_code AS รหัส,
                e.emp_name AS ชื่อ,
                vel.appraisal_level_name AS ระดับ,
                pos.position_name AS หน่วยงาน,
                org.org_name AS ตำแหน่ง,
                er.s_amount AS เงินเดือน,
                er.b_amount AS เงินรางวัลพิเศษ,
                er.adjust_b_amount AS ปรับเงินรางวัลพิเศษ,
                er.adjust_b_rate AS จำนวนเดือน,
                er.status AS สถานะ
            FROM emp_result er
            INNER JOIN employee e ON e.emp_id = er.emp_id
            INNER JOIN appraisal_level vel ON vel.level_id = er.level_id
            INNER JOIN org ON org.org_id = er.org_id
            INNER JOIN position pos ON pos.position_id = er.position_id
            WHERE er.period_id = '{$request->period_id}'
            ".$qryEmpLevel."
            ".$qryOrgLevel."
            ".$qryOrgId."
            ".$qryEmpId."
            ".$qryPositionId."
            ".$qryStageId."
        ");
        */

        // new template
        $dataInfo = DB::select("
            SELECT 
                e.emp_code AS employeeid,
                100 AS companyid,
                'N123' AS tabid,
                CAST(FROM_BASE64(er.adjust_b_amount) AS DECIMAL(10,2)) AS data
            FROM emp_result er
            INNER JOIN employee e ON e.emp_id = er.emp_id
            INNER JOIN appraisal_level vel ON vel.level_id = er.level_id
            INNER JOIN org ON org.org_id = er.org_id
            INNER JOIN position pos ON pos.position_id = er.position_id
            WHERE er.period_id = '{$request->period_id}'
            ".$qryEmpLevel."
            ".$qryOrgLevel."
            ".$qryOrgId."
            ".$qryEmpId."
            ".$qryPositionId."
            ".$qryStageId."
            ORDER BY er.org_id DESC, er.level_id DESC, e.emp_code ASC
        ");


        $data = json_decode( json_encode($dataInfo), true);
        $fileName = 'Bonus Adjustment '.date('d-m-Y');

        return Excel::create($fileName, function($excel) use ($data) {
            $excel->sheet('Sheet1', function($sheet) use ($data){
                $sheet->fromArray($data);
            });
        })->download('xlsx');
    }

}
