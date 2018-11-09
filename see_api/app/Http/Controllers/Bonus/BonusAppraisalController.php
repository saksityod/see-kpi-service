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
use App\Employee;
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
       //$this->middleware('jwt.auth');
        $this->defaultMonthlyBonusRate = SystemConfiguration::first()->monthly_bonus_rate;
    }


    /**
     * @ Search and Re-Calculate
     * 1. ตรวจสอบ emp ที่จะนำมาคำนวณว่าจะต้องมี Stage เท่ากับ "Bonus Evaluate" (**รอ Stage จากพี่ท๊อปอีกทีว่าต้องใช้อะไร)
     *    1.1 กรณีพบ emp ที่ยังมี Stage มาไม่ถึงตามที่กำหนด ทำการ retrun data:[], message:"xxxxxxxxxxxxx"
     * 2. Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
     * 3. ตรวจสอบ Action ว่าเป็น "search" หรือ "re-calculate"
     *    3.1 กรณีเป็น "re-calculate" 
     *       3.1.1 นำข้อมูล adjust_result_score จาก client แทนค่าที่ได้ GetBonusAppraisalOrgLevel()
     *       3.1.2 คำนวณหาค่า bonus_score ใหม่
     * 4. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ business unit
     *    4.1 หาเงินเดือนทั้งหมดโดย sum เงินเดือนของทุก bu, 
     *        หายอดรวมของโบนัสโดย (งินเดือนทั้งหมด * จำนวนเดือนที่จ่ายโบนัส), 
     *        หาแต้มสิทธ์ทั้งหมดโดย sum แต้มสิทธ์ของทุก bu
     *    4.2 คำนวณหาเปอร์เซ็นของ bu ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ bu * 100) / แต้มสิทธิ์ทั้งหมด), 
     *        คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสทั้งหมด)
     * 5. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ department
     *    5.1 Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
     *    5.2 หาแต้มสิทธ์ทั้งหมดโดย (sum แต้มสิทธ์ของทุก dep + แต้มสิทธิ์ของ bu manager) **เนื่องการเฉลี่ยโบนัสของ dep จะต้องหักจาก bu manager เสียก่อน
     *    5.3 กำหนดยอดรวมโบนัสในระดับ dep **ยอดจะเท่ากับเงินโบนัสของ bu ที่เป็น parent ของ dep นั้น ๆ
     *    5.4 คำนวณหาเปอร์เซ็นของ dep ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ dep * 100) / แต้มสิทธิ์ dep ทั้งหมด), 
     *        คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ dep)
     */
    function Index(Request $request)
    {
        // 1. ตรวจสอบ emp ที่จะนำมาคำนวณว่าจะต้องมี Stage เท่ากับ "Bonus Evaluate" (**รอ Stage จากพี่ท๊อปอีกทีว่าต้องใช้อะไร)
        $empNotAdjust = DB::select("
            SELECT COUNT(1) cnt
            FROM emp_result_judgement erj 
            INNER JOIN emp_result er ON er.emp_result_id = erj.emp_result_id
            WHERE er.status != 'Bonus Evaluate' 
        ");
        if((Integer)$empNotAdjust[0]->cnt > 0){
            return response()->json(['data'=>[], 'message'=>'มีคนที่ยังมาไม่ถึง stage ที่สามมารถปรับคะแนนได้ (ยังคิดไม่ออก เดียวให้พี่ท๊อปคิดให้)']);
        }

        // 2. Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
        $buLevel = AppraisalLevel::where('is_start_cal_bonus', 1)->get()->first()->level_id;
        $BuDatas = $this->GetBonusAppraisalOrgLevel($request->period_id, $buLevel, null);

        // 3. ตรวจสอบ Action ว่าเป็น "search" หรือ "re-calculate"
        if($request->action == 're-calculate'){

            // 3.1 กรณีเป็น "re-calculate"
            $clientData = collect($request->data); Log::info($clientData);
            $BuDatas = $BuDatas->map(function ($data, $key) use ($clientData){
                $clientData = $clientData
                    ->where('org_result_judgement_id', (String) $data->org_result_judgement_id)
                    ->where('emp_result_judgement_id', (String) $data->emp_result_judgement_id)
                    ->first();
                if( ! empty($clientData)){
                    // 3.1.1 นำข้อมูล adjust_result_score จาก client แทนค่าที่ได้ GetBonusAppraisalOrgLevel()
                    $data->adjust_result_score = $clientData['adjust_result_score'];
                    $data->emp_adjust_result_score = $clientData['emp_adjust_result_score'];

                    // 3.1.2 คำนวณหาค่า bonus_score ใหม่
                    $data->bonus_point = $data->adjust_result_score * $data->total_salary;
                    $data->emp_bonus_point = $data->emp_adjust_result_score * $data->emp_salary;
                }
                return $data;
            });
        }

        // 4. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ business unit
        // 4.1 หา เงินเดือนทั้งหมดโดย sum เงินเดือนของทุก bu, หายอดรวมของโบนัสโดย (งินเดือนทั้งหมด * จำนวนเดือนที่จ่ายโบนัส), หาแต้มสิทธ์ทั้งหมดโดย sum แต้มสิทธ์ของทุก bu
        $AllSalary = $BuDatas->sum('total_salary');
        $totalBonus = $this->GetDefaultBonusMonth() * $AllSalary;
        $totalBonusPoint = $BuDatas->sum('bonus_point');

        // 4.2 คำนวณหาเปอร์เซ็นของ bu ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ bu * 100) / แต้มสิทธิ์ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสทั้งหมด)
        $BuDatas = $BuDatas->map(function ($data) use($totalBonusPoint, $totalBonus){
            $data->bonus_percent = round((($data->bonus_point * 100) / $totalBonusPoint), 2);
            $data->bonus_amount = ($data->bonus_percent / 100) * $totalBonus;
            return $data;
        });

        // 5. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ department
        foreach ($BuDatas as $BuData) {
            // 5.1 Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
            $BuData->departments = $this->GetBonusAppraisalOrgLevel($request->period_id, null, $BuData->org_code);
            
            // 5.2 หาแต้มสิทธ์ทั้งหมดโดย (sum แต้มสิทธ์ของทุก dep + แต้มสิทธิ์ของ bu manager) **เนื่องการเฉลี่ยโบนัสของ dep จะต้องหักจาก bu manager เสียก่อน
            $totalDepBonusPoint = $BuData->departments->sum('bonus_point') + $BuData->emp_bonus_point;

            // 5.3 กำหนดยอดรวมโบนัสในระดับ dep **ยอดจะเท่ากับเงินโบนัสของ bu ที่เป็น parent ของ dep นั้น ๆ
            $totalDepBonus = $BuData->bonus_amount;

            // 5.4 คำนวณหาเปอร์เซ็นของ dep ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ dep * 100) / แต้มสิทธิ์ dep ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ dep)
            $BuData->departments = $BuData->departments->map(function ($depData) use($totalDepBonusPoint, $totalDepBonus){
                $depData->bonus_percent = round((($depData->bonus_point * 100) / $totalDepBonusPoint), 2);
                $depData->bonus_amount = ($depData->bonus_percent / 100) * $totalDepBonus;
                return $depData;
            });
            
        }

        return response()->json($this->SetPagination($request->page, $request->rpp, $BuDatas->toArray()));
    }


    public function SavedAndCalculation(Request $request)
    {
        $requestValid = Validator::make($request->all(), [
            'appraisal_year' => 'required',
            'period_id' => 'required',
            'monthly_bonus_rate' => 'required',
            'calculate_flag' => 'required',
            'data' => 'required'
        ]);
        if ($requestValid->fails()) {
            return response()->json(['status' => 400, 'data' => implode(" ", $requestValid->messages()->all())]);
        }


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
                        'created_by' => Auth::id(),
                        'created_dttm' => date('Y-m-d H:i:s')
                    ]);

            } catch (QueryException $e) {
                return response()->json(['status' => 400, 'data' => $e->getMessage()]);
            }
        }

        
        if($request->calculate_flag == "0"){
            // return response if not re-calculate bonus //
            return response()->json(['status' => 200, 'data' => ["success" => [], "error" => []]]);
        } else {
            // bonus bonuscalculation //
            return response()->json($this->BonusCalculation($request->period_id));
        }
                
    }


    /**
     * 
     */
    private function BonusCalculation($period)
    {
        // 1. Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
        $buLevel = AppraisalLevel::where('is_start_cal_bonus', 1)->get()->first()->level_id;
        $BuDatas = $this->GetBonusAppraisalOrgLevel($period, $buLevel, null);

        // 2. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ business unit
        // 2.1 หา เงินเดือนทั้งหมดโดย sum เงินเดือนของทุก bu, หายอดรวมของโบนัสโดย (งินเดือนทั้งหมด * จำนวนเดือนที่จ่ายโบนัส), หาแต้มสิทธ์ทั้งหมดโดย sum แต้มสิทธ์ของทุก bu
        $AllSalary = $BuDatas->sum('total_salary');
        $totalBonus = $this->GetDefaultBonusMonth() * $AllSalary;
        $totalBonusPoint = $BuDatas->sum('bonus_point');

        // 2.2 คำนวณหาเปอร์เซ็นสิทธิ์ของ bu ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ bu * 100) / แต้มสิทธิ์ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสทั้งหมด)
        $BuDatas = $BuDatas->map(function ($data) use($totalBonusPoint, $totalBonus){
            $data->bonus_percent = round((($data->bonus_point * 100) / $totalBonusPoint), 2);
            $data->bonus_amount = ($data->bonus_percent / 100) * $totalBonus;

            // 2.3 บันทึกผลลงตาราง org_result_judgement ที่ bonus_score(แต้มสิทธิ์), bonus_percent(เปอร์เซ็นสิทธิ์)
            DB::table('org_result_judgement')
                ->where('org_result_judgement_id', $data->org_result_judgement_id)
                ->update([
                    'bonus_score' => $data->bonus_point,
                    'bonus_percent' => $data->bonus_percent,
                    'updated_by' => Auth::id(),
                    'updated_dttm' => date('Y-m-d H:i:s')
                ]);

            return $data;
        });

        // 3. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ department
        foreach ($BuDatas as $BuData) {
            // 3.1 Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
            $depInfo = $this->GetBonusAppraisalOrgLevel($period, null, $BuData->org_code);
            
            // 3.2 หาแต้มสิทธ์ทั้งหมดโดย (sum แต้มสิทธ์ของทุก dep + แต้มสิทธิ์ของ bu manager) **เนื่องการเฉลี่ยโบนัสของ dep จะต้องหักจาก bu manager เสียก่อน
            $totalDepBonusPoint = $depInfo->sum('bonus_point') + $BuData->emp_bonus_point;

            // 3.3 กำหนดยอดรวมโบนัสในระดับ dep **ยอดจะเท่ากับเงินโบนัสของ bu ที่เป็น parent ของ dep นั้น ๆ
            $totalDepBonus = $BuData->bonus_amount;

            // 3.4 คำนวณหาเปอร์เซ็นของ bu manager (()), คำนวณเงินโบนัสของ bu manager (())
            // 3.5 บันทึกผล

            // // 3.4 คำนวณหาเปอร์เซ็นของ dep ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ dep * 100) / แต้มสิทธิ์ dep ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ dep)
            // $BuData->departments = $depInfo->map(function ($depData) use($totalDepBonusPoint, $totalDepBonus){
            //     $depData->bonus_percent = round((($depData->bonus_point * 100) / $totalDepBonusPoint), 2);
            //     $depData->bonus_amount = ($depData->bonus_percent / 100) * $totalDepBonus;
            //     return $depData;
            // });
            
        }

        // // Department //
        // foreach ($datas as $data) {
        //     $data->departments = $this->GetBonusAppraisalOrgLevel(null, $data->org_code);
            
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
        // return response()->json(['status'=>400, 'data'=>'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
        return ['status'=>200, 'data'=>[]];

    }
    

    /**
     * @ ดึงข้อมูลที่ใช้ในการคำนวณ Bonus
     * arg1: Parameter Period
     * arg2: level เริ่มต้นที่ใช้ในการคำนวณ bonus (กรณีต้องการ Query ข้อมูลของ BU)
     * arg3: Parent Org คือ Org Code ของ BU (กรณีต้องการ Query ข้อมูลของ Dep ที่อยู่ภายใต้ BU นั้น ๆ)
     * Note: arg2 กับ arg3 ใส่แค่ค่าใดค่าหนึ่ง อีกค่าให้เป็น null
     */
    private function GetBonusAppraisalOrgLevel($period, $buLevelId, $parentOrg)
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
                    IF(e.adjust_result_score=0, er.result_score, e.adjust_result_score) adjust_result_score,
                    emp.emp_id, emp.emp_name, emp.s_amount, stg.edit_flag
                FROM emp_result_judgement e
                INNER JOIN emp_result er ON er.emp_result_id = e.emp_result_id
                INNER JOIN employee emp ON emp.emp_id = er.emp_id
                LEFT OUTER JOIN appraisal_stage stg ON stg.stage_id = er.stage_id
                WHERE er.status = 'Bonus Evaluate' 
            ) erj ON erj.level_id = org.level_id AND erj.org_id = orj.org_id
            WHERE orj.period_id = '{$period}'
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

    public function GetNetSalaryByEmpId($empId)
    {
        try {
            $empInfo = Employee::findOrFail($empId);
        }
        catch(ModelNotFoundException $e) {
            return 0;
        }
    }

}
