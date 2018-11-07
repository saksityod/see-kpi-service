<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\SystemConfiguration;
use App\AppraisalLevel;
use Auth;
use DB;
use Validator;
use Exception;
use Log;


class BonusAppraisalController extends Controller
{
    private $defaultMonthlyBonusRate;

    public function __construct()
	{
        $this->middleware('jwt.auth');
        $this->defaultMonthlyBonusRate = SystemConfiguration::first()->monthly_bonus_rate;
    }


    function Index(Request $request)
    {
        // business unit (with point) //
        $buLevel = AppraisalLevel::where('is_start_cal_bonus', 1)->get()->first()->level_id;
        $datas = $this->GetBonusAppraisal($buLevel, null);

        // set all salary, total bonus, total_point //
        $AllSalary = $datas->sum('total_salary');
        $totalBonus = $this->GetDefaultBonusMonth() * $AllSalary;
        $totalBonusPoint = $datas->sum('bonus_point');

        // set bonus percent in the Object //
        $datas = $datas->map(function ($data) use($totalBonusPoint, $totalBonus){
            $data->bonus_percent = round((($data->bonus_point * 100) / $totalBonusPoint), 2);
            $data->bonus_amount = ($data->bonus_percent / 100) * $totalBonus;
            return $data;
        });

        // department //
        foreach ($datas as $data) {
            $data->departments = $this->GetBonusAppraisal(null, $data->org_code);
            
            // set total department bonus point + bu manager bonus point  //
            $totalDepBonusPoint = $data->departments->sum('bonus_point') + $data->emp_bonus_point;

            // set total department bonus //
            $totalDepBonus = $data->bonus_amount;

            // set bonus percent in the department Object //
            $data->departments = $data->departments->map(function ($depData) use($totalDepBonusPoint, $totalDepBonus){
                $depData->bonus_percent = round((($depData->bonus_point * 100) / $totalDepBonusPoint), 2, PHP_ROUND_HALF_UP);
                $depData->bonus_amount = ($depData->bonus_percent / 100) * $totalDepBonus;
                return $depData;
            });
            
        }

        return response()->json($this->SetPagination($request->page, $request->rpp, $datas->toArray()));
    }


    public function SavedAndCalculation(Request $request)
    {
        // save bonus adjust result score into org_result_judgement, emp_result_judgement //
        foreach ($request->data as $data) {
            $validator = Validator::make($data, [
                'org_result_judgement_id' => 'required|integer',
                'emp_result_judgement_id' => 'required|integer',
                'adjust_result_score' => 'numeric',
                'emp_adjust_result_score' => 'numeric'
            ]);
            if ($validator->fails()) {
                return response()->json(['status' => 400, 'data' => implode(" ", $validator->messages()->all())]);
            }

            try{
                DB::table('org_result_judgement')
                    ->where('org_result_judgement_id', $data['org_result_judgement_id'])
                    ->update([
                        'adjust_result_score' => $data['adjust_result_score'],
                        'updated_by' => Auth::id(),
                        'updated_dttm' => date('Y-m-d H:i:s')
                    ]);
                
                DB::table('emp_result_judgement')
                    ->where('emp_result_judgement_id', $data['emp_result_judgement_id'])
                    ->update([
                        'adjust_result_score' => $data['emp_adjust_result_score'],
                        'updated_by' => Auth::id(),
                        'updated_dttm' => date('Y-m-d H:i:s')
                    ]);

            } catch (QueryException $e) {
                return response()->json(['status' => 400, 'data' => $e->getMessage()]);
            }
        }

        
        if($request->calculate_flag == 0){
            // return response if not re-calculate bonus //
            return response()->json(['status' => 200, 'data' => ["success" => [], "error" => []]]);
        } else {
            // re-calculate bonus //
            return response()->json($this->BonusCalculation());
        }
                
    }


    private function BonusCalculation()
    {
        // business Unit (with point) //
        $buLevel = AppraisalLevel::where('is_start_cal_bonus', 1)->get()->first()->level_id;
        $datas = $this->GetBonusAppraisal($buLevel, null);

        // // set all salary, total bonus, total_point //
        // $AllSalary = $datas->sum('total_salary');
        // $totalBonus = $this->GetDefaultBonusMonth() * $AllSalary;
        // $totalBonusPoint = $datas->sum('bonus_point');

        // // set bonus percent in the Object //
        // $datas = $datas->map(function ($data) use($totalBonusPoint, $totalBonus){
        //     $data->bonus_percent = round((($data->bonus_point * 100) / $totalBonusPoint), 2, PHP_ROUND_HALF_UP);
        //     $data->bonus_amount = ($data->bonus_percent / 100) * $totalBonus;
        //     return $data;
        // });

        // // Department //
        // foreach ($datas as $data) {
        //     $data->departments = $this->GetBonusAppraisal(null, $data->org_code);
            
        //     // set total department bonus point + bu manager bonus point  //
        //     $totalDepBonusPoint = $data->departments->sum('bonus_point') + $data->emp_bonus_point;

        //     // set total department bonus //
        //     $totalDepBonus = $data->bonus_amount;

        //     // set bonus percent in the department Object //
        //     $data->departments = $data->departments->map(function ($depData) use($totalDepBonusPoint, $totalDepBonus){
        //         $depData->bonus_percent = round((($depData->bonus_point * 100) / $totalDepBonusPoint), 2, PHP_ROUND_HALF_UP);
        //         $depData->bonus_amount = ($depData->bonus_percent / 100) * $totalDepBonus;
        //         return $depData;
        //     });
            
        // }

        // return response()->json($this->SetPagination($request->page, $request->rpp, $datas->toArray()));
        return 1;
    }
    

    private function GetBonusAppraisal($buLevelId, $parentOrg)
    {
        $parentOrgQryStr = empty($parentOrg) ? "": " AND org.parent_org_code = '{$parentOrg}'";
        $buLevelQryStr = empty($buLevelId) ? "": " AND org.level_id = '{$buLevelId}'";

        $items = DB::select("
            SELECT orj.org_result_judgement_id, org.level_id, vel.appraisal_level_name,
                orj.org_id, org.org_code, org.org_name,
                orj.avg_result_score,
                IF(orj.adjust_result_score=0, orj.avg_result_score, orj.adjust_result_score) adjust_result_score,
                orj.total_salary, 
                ROUND(IF(orj.adjust_result_score=0, orj.avg_result_score, orj.adjust_result_score) * orj.total_salary, 2) bonus_point,
                0 bonus_percent,
                erj.emp_result_judgement_id,
                erj.emp_id, erj.emp_name,
                erj.adjust_result_score emp_result_score,
                erj.adjust_result_score emp_adjust_result_score,
                erj.s_amount emp_salary,
                ROUND(erj.adjust_result_score * erj.s_amount, 2) emp_bonus_point,
                IFNULL(erj.edit_flag, 0) edit_flag
            FROM org_result_judgement orj
            INNER JOIN org ON org.org_id = orj.org_id
            INNER JOIN appraisal_level vel ON vel.level_id = org.level_id
            LEFT OUTER JOIN(
                SELECT e.emp_result_judgement_id, er.org_id, er.level_id, 
                    IF(e.adjust_result_score=0, e.previous_result_score, e.adjust_result_score) adjust_result_score,
                    emp.emp_id, emp.emp_name, er.s_amount, stg.edit_flag
                FROM emp_result_judgement e
                INNER JOIN emp_result er ON er.emp_result_id = e.emp_result_id
                INNER JOIN employee emp ON emp.emp_id = er.emp_id
                                LEFT OUTER JOIN appraisal_stage stg ON stg.stage_id = er.stage_id
            ) erj ON erj.level_id = org.level_id AND erj.org_id = orj.org_id
            WHERE 1 = 1
            ".$buLevelQryStr."
            ".$parentOrgQryStr."
        ");

        return collect($items);
    }


    private function GetDefaultBonusMonth()
    {
        return doubleval($this->defaultMonthlyBonusRate);
    }


    private function SetPagination($page, $rpp, $itemArr)
    {
        // Get the current page from the url if it's not set default to 1
		empty($page) ? $page = 1 : $page = $page;
		
		// Number of items per page
		empty($rpp) ? $perPage = 10 : $perPage = $rpp;
		
		$offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

		// Get only the items you need using array_slice (only get 10 items since that's what you need)
        $itemsForCurrentPage = array_slice($itemArr, $offSet, $perPage, false);
		
		// Return the paginator with only 10 items but with the count of all items and set the it on the correct page
        return new LengthAwarePaginator($itemsForCurrentPage, count($itemArr), $perPage, $page);
    }

}
