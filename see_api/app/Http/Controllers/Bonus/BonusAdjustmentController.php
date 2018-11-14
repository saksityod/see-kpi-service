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

use App\EmpResult;

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
    }


    public function index(Request $request)
    {
        // set parameter for query 
        $request->period_id = empty($request->period_id) ? "": $request->period_id;
        $qryEmpLevel = empty($request->emp_level) ? "": " AND er.level_id = '{$request->emp_level}'";
        $qryOrgLevel = empty($request->org_level) ? "": " AND org.level_id = '{$request->org_level}'";
        $qryOrgId = empty($request->org_id) ? "": " AND er.org_id = '{$request->org_id}'";
        $qryEmpId = empty($request->emp_id) ? "": " AND er.emp_id = '{$request->emp_id}'";
        // $qryPositionId = empty($request->position_id) ? "": " AND er.position_id = '{$request->position_id}'";
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
                er.s_amount,
                er.adjust_b_amount AS b_amount,
                er.adjust_b_amount,
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
        ");

        $dataInfo = (new BonusAppraisalController)->SetPagination($request->page, $request->rpp, $dataInfo);
        return response()->json($dataInfo);
    }



    public function SavedAndConfirm(Request $request)
    {
        $errors_validator = [];
        $requestValid = Validator::make($request->all(), [
            'appraisal_year' => 'required',
            'period_id' => 'required',
            'confirm_flag' => 'required',
            'data' => 'required'
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

            $empResult->adjust_b_amount = $data['adjust_b_amount'];
            $empResult->adjust_b_rate = $data['adjust_b_rate'];
            $empResult->updated_by = Auth::id();
            if($request->confirm_flag == "1"){
                $stageInfo = (new EmpResultJudgementController)->to_action($request);
                $stageInfo = json_decode($stageInfo->content(), true);
                $empResult->stage_id = $stageInfo[0]['stage_id'];
                $empResult->status = $stageInfo[0]['to_action'];
            }
            $empResult->save();
        }

        return response()->json(['status' => 200, 'data' => "Saved Successfully"]);
    }



    public function Export(Request $request){
        // set parameter for query 
        $request->period_id = empty($request->period_id) ? "": $request->period_id;
        $qryEmpLevel = empty($request->emp_level) ? "": " AND er.level_id = '{$request->emp_level}'";
        $qryOrgLevel = empty($request->org_level) ? "": " AND org.level_id = '{$request->org_level}'";
        $qryOrgId = empty($request->org_id) ? "": " AND er.org_id = '{$request->org_id}'";
        $qryEmpId = empty($request->emp_id) ? "": " AND er.emp_id = '{$request->emp_id}'";
        // $qryPositionId = empty($request->position_id) ? "": " AND er.position_id = '{$request->position_id}'";
        $qryPositionId = empty($request->position_id) ? "" : " AND er.position_id IN ({$request->position_id})";
        $qryStageId = empty($request->stage_id) ? "": " AND er.stage_id = '{$request->stage_id}'";

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

        $data = json_decode( json_encode($dataInfo), true);
        $fileName = 'Bonus Adjustment '.date('y-M-d');

		return Excel::create($fileName, function($excel) use ($data) {
			$excel->sheet('Sheet1', function($sheet) use ($data){
                $sheet->fromArray($data);
            });
		})->download('xlsx');
    }

}
