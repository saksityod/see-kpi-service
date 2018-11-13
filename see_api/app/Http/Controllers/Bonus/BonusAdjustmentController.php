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
use Log;

class BonusAdjustmentController extends Controller
{
    public function __construct()
	{
       $this->middleware('jwt.auth');
    }


    public function index(Request $request){
        // set parameter for query 
        $request->period_id = empty($request->period_id) ? "": $request->period_id;
        $qryEmpLevel = empty($request->emp_level) ? "": " AND er.level_id = '{$request->emp_level}'";
        $qryOrgLevel = empty($request->org_level) ? "": " AND org.level_id = '{$request->org_level}'";
        $qryOrgId = empty($request->org_id) ? "": " AND er.org_id = '{$request->org_id}'";
        $qryEmpId = empty($request->emp_id) ? "": " AND er.emp_id = '{$request->emp_id}'";
        $qryPositionId = empty($request->position_id) ? "": " AND er.position_id = '{$request->position_id}'";
        $qryStageId = empty($request->stage_id) ? "": " AND er.stage_id = '{$request->stage_id}'";

        $dataInfo = DB::select("
            SELECT er.emp_result_id,
                e.emp_name,
                vel.appraisal_level_name,
                pos.position_name,
                org.org_name,
                er.s_amount,
                er.adjust_b_amount AS b_amount,
                er.adjust_b_amount,
                er.adjust_b_rate AS b_rate,
                er.adjust_b_rate,
                er.status
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

        $dataInfo = (new BonusAppraisalController)->SetPagination($request->page, $request->rpp, $dataInfo);
        return response()->json($dataInfo);
    }



    public function SavedAndConfirm(Request $request)
    {
        $requestValid = Validator::make($request->all(), [
            'appraisal_year' => 'required',
            'period_id' => 'required',
            'confirm_flag' => 'required',
            'data' => 'required'
        ]);
        if ($requestValid->fails()) {
            return response()->json(['status' => 400, 'data' => implode(" ", $requestValid->messages()->all())]);
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
                // $request->flag = 'bonus_adjustment_flag';
                // $stageInfo = redirect()->action(
                //     'Bonus\EmpResultJudgementController@to_action', 
                //     [
                //         'flag' => 'bonus_adjustment_flag',
                //         'emp_code' => Auth::id() 
                //     ]
                // );
            }
            $empResult->save();

            
        }

        return response()->json(['status' => 200, 'data' => "Saved Successfully"]);
    }



}
