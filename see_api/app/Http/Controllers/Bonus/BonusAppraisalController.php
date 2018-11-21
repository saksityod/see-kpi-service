<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\AppraisalLevel;
use App\AppraisalStage;
use App\Employee;
use App\EmpResult;
use App\EmpResultJudgement;
use App\AppraisalFrequency;
use App\Org;
use App\OrgResultJudgement;
use App\AppraisalPeriod;
use App\SystemConfiguration;
use Carbon\Carbon;
use Auth;
use DB;
use Validator;
use Exception;
use Log;


class BonusAppraisalController extends Controller
{

    public function __construct()
	{
        $this->middleware('jwt.auth');
    }


    /**
     * @ Search and Re-Calculate
     * 1. ตรวจสอบผลการประเมิณของ emp ทั้งหมดจะต้องผ่านการประเมิณจาก board เรียบร้อยแล้ว และเช็คสิทธิ์การทำงานจาก stage โดยเช็คจาก level ของ login
     *    1.1 กำหนด edit_flag และ message เพื่อส่งกลับไปยัง client
     * 2. Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
     *    2.1 ในกรณีไม่พบข้อมูลให้ return กลับด้วย status = 400, error message
     * 3. ตรวจสอบ Action ว่าเป็น "re-calculate"
     *    3.1 map ข้อมูลที่ส่งมาจากหน้าจอเพื่อนำไปคำนวนแบบไม่บันทึกข้อมูล ในระดับ bu
     *       3.1.1 นำข้อมูล adjust_result_score จาก client แทนค่าที่ได้จาก GetBonusAppraisalOrgLevel()
     *       3.1.2 คำนวณหาค่า bonus_score ใหม่
     * 4. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ business unit
     *    4.1 หาเงินเดือนทั้งหมดโดย sum เงินเดือนของทุก bu, 
     *        หายอดรวมของโบนัสโดย (งินเดือนทั้งหมด * จำนวนเดือนที่จ่ายโบนัส), 
     *        หาแต้มสิทธ์ทั้งหมดโดย sum แต้มสิทธ์ของทุก bu
     *    4.2 คำนวณหาเปอร์เซ็นของ bu ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ bu * 100) / แต้มสิทธิ์ทั้งหมด), 
     *        คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสทั้งหมด)
     * 5. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ department
     *    5.1 Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
     *    5.2 ตรวจสอบ Action ว่าเป็น "re-calculate"
     *       5.2.1 map ข้อมูลที่ส่งมาจากหน้าจอเพื่อนำไปคำนวนแบบไม่บัยทึกข้อมูล ในระดับ department
     *          5.2.1.1 นำข้อมูล adjust_result_score จาก client แทนค่าที่ได้จาก GetBonusAppraisalOrgLevel()
     *          5.2.1.2 คำนวณหาค่า bonus_score ใหม่
     * 6. หาแต้มสิทธ์ทั้งหมดโดย (sum แต้มสิทธ์ของทุก dep + แต้มสิทธิ์ของ bu manager (prorate)) **เนื่องการเฉลี่ยโบนัสของ dep จะต้องหักจาก bu manager เสียก่อน
     * 7. กำหนดยอดรวมโบนัสในระดับ dep **ยอดจะเท่ากับเงินโบนัสของ bu ที่เป็น parent ของ dep นั้น ๆ
     * 8. คำนวณหาเปอร์เซ็นของ dep ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ dep * 100) / แต้มสิทธิ์ dep ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ dep)
     * 9. return กลับไปยัง client ด้วย status = 200, ข้อมูลที่ใช้ในการแสดง, edit flag และ message ในหัวข้อ 1.1
     */
    function Index(Request $request)
    { 
        // เตรียมข้อมูลที่จำเป็นในการทำงาน
        // $employee = Employee::where('emp_code', Auth::id())->first();
        $employee = DB::table('employee')
            ->join('org', 'org.org_id', '=', 'employee.org_id')
            ->select('org.*')
            ->where('employee.emp_code', Auth::id())
            ->first();
        $defaultMonthlyBonusRate = SystemConfiguration::first()->monthly_bonus_rate;
        $appraisalStage = AppraisalStage::where('bonus_appraisal_flag', 1)->get()->first();
        $buLevel = AppraisalLevel::where('is_start_cal_bonus', 1)->where('is_org', 1)->get()->first();
        if( ! empty($buLevel)){
            $buLevel = $buLevel->level_id;
        } else {
            $responseData = ['status' => 400, 'data' => [], 'edit_flag'=> 0,
                'message' => 'ไม่พบข้อมูล Level ในระดับ Organization ที่ใช้ในการคำนวณเงินรางวัลพิเศษ (is_start_cal_bonus)'
            ];
            return response()->json($this->SetPagination($request->page, $request->rpp, $responseData));
        }

        // 1. ตรวจสอบผลการประเมิณของ emp ทั้งหมดจะต้องผ่านการประเมิณจาก board เรียบร้อยแล้ว และเช็คสิทธิ์การทำงานจาก stage โดยเช็คจาก level ของ login
        $empNotAdjust = DB::select("
            SELECT SUM(1) all_emp_result, 
                SUM(IF(bn.stage_id = er.stage_id, 1, 0)) all_emp_bonus, 
                MAX(er.stage_id) max_stage_id
            FROM emp_result er 
            INNER JOIN org ON org.org_id = er.org_id
            INNER JOIN appraisal_stage stg ON stg.stage_id = er.stage_id
            LEFT OUTER JOIN (
                SELECT stage_id, status
                FROM appraisal_stage sas 
                WHERE bonus_appraisal_flag = 1 
            ) bn ON bn.stage_id = er.stage_id
            WHERE er.period_id = '{$request->period_id}'
            AND FIND_IN_SET(org.org_code, '{$this->GetOrganizationsBonusCalculate()}')
        ");
        if(in_array($employee->level_id, explode(',', $appraisalStage->level_id))){
            // 1.1 กำหนด edit_flag และ message เพื่อส่งกลับไปยัง client
            if(!empty($empNotAdjust) && $empNotAdjust[0]->all_emp_result == $empNotAdjust[0]->all_emp_bonus){
                $editFlag = 1; 
                $editMessage = '';
            } elseif (!empty($empNotAdjust) && $empNotAdjust[0]->max_stage_id > $appraisalStage->stage_id) {
                $editFlag = 0;
                $editMessage = 'ไม่สามารถแก้ไขได้!! เนื่องจากได้ปรับผลคะแนนเรียบร้อยแล้ว';
            } else {
                $editFlag = 0;
                $editMessage = 'ไม่สามารถแก้ไขได้!! เนื่องจากพบพนักงานที่ยังไม่ผ่านการปรับผลประเมินจาก Board';
            }
        }  else {
            $editFlag = 0; 
            $editMessage = 'ผู้ใช้งานไม่ได้รับสิทธิ์ในการปรับผลคะแนน!!';
        }

        // 2. Query หาข้อมูลผลการประเมิณของ bu ที่ใช้ในการคำนวณ (เริ่มคำนวณจาก bu)
        $buInfo = $this->GetBonusAppraisalOrgLevel($request->period_id, $buLevel, null);
        if($buInfo->count() == 0){
            // 2.1 ในกรณีไม่พบข้อมูลให้ return กลับด้วย status = 400, error message
            $responseData = ['status' => 400, 'data' => [], 'edit_flag'=> 0,
                'message' => 'ไม่พบข้อมูลผลการประเมิณในระดับหน่วยงาน (Organization result judgement)'
            ];
            return response()->json($this->SetPagination($request->page, $request->rpp, $responseData));
        }

        // 3. ตรวจสอบ Action ว่าเป็น "re-calculate"
        if($request->action == 're-calculate'){

            $clientData = collect($request->data);
            
            // 3.1 map ข้อมูลที่ส่งมาจากหน้าจอเพื่อนำไปคำนวนแบบไม่บันทึกข้อมูล ในระดับ bu
            $buInfo = $buInfo->map(function ($data) use ($clientData){
                $clientData = $clientData
                    ->where('org_result_judgement_id', (empty($data->org_result_judgement_id))?'':(String)$data->org_result_judgement_id)
                    ->where('emp_result_judgement_id', (empty($data->emp_result_judgement_id))?'':(String)$data->emp_result_judgement_id)
                    ->first();
                if( ! empty($clientData)){
                    // 3.1.1 นำข้อมูล adjust_result_score จาก client แทนค่าที่ได้จาก GetBonusAppraisalOrgLevel()
                    $data->adjust_result_score = $clientData['adjust_result_score'];
                    $data->emp_adjust_result_score = $clientData['emp_adjust_result_score'];
                    // 3.1.2 คำนวณหาค่า bonus_score ใหม่
                    $data->bonus_score = $data->adjust_result_score * $data->total_salary;
                    $data->emp_bonus_score = $data->emp_adjust_result_score * $data->emp_salary;
                }

                return $data;
            });
        }

        // 4. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ business unit
        // 4.1 หา เงินเดือนทั้งหมดโดย sum เงินเดือนของทุก bu, หายอดรวมของโบนัสโดย (งินเดือนทั้งหมด * จำนวนเดือนที่จ่ายโบนัส), หาแต้มสิทธ์ทั้งหมดโดย sum แต้มสิทธ์ของทุก bu
        $buTotalSalary = $buInfo->sum('total_salary');
        $buTotalBonusAmount = $defaultMonthlyBonusRate * $buTotalSalary;
        $buTotalBonusScore = $buInfo->sum('bonus_score');

        // 4.2 คำนวณหาเปอร์เซ็นของ bu ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ bu * 100) / แต้มสิทธิ์ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสทั้งหมด)
        $buInfo = $buInfo->map(function ($buData) use($buTotalBonusScore, $buTotalBonusAmount){
            // Set Zoro to 1
            $buTotalBonusAmount = ($buTotalBonusAmount == 0) ? 1: $buTotalBonusAmount;
            $buTotalBonusScore = ($buTotalBonusScore == 0) ? 1: $buTotalBonusScore;

            $buData->bonus_percent = number_format((($buData->bonus_score * 100) / $buTotalBonusScore), 2);
            $buData->bonus_amount = number_format(($buData->bonus_percent / 100) * $buTotalBonusAmount, 2);
            $buData->bonus_score = number_format($buData->bonus_score, 2);

            return $buData;
        });

        // 5. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ department
        foreach ($buInfo as $bu) {
            // 5.1 Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
            $depInfo = $this->GetBonusAppraisalOrgLevel($request->period_id, null, $bu->org_code);

            // 5.2 ตรวจสอบ Action ว่าเป็น "re-calculate"
            if($request->action == 're-calculate'){

                $clientData = collect($request->data);
                
                // 5.2.1 map ข้อมูลที่ส่งมาจากหน้าจอเพื่อนำไปคำนวนแบบไม่บัยทึกข้อมูล ในระดับ department
                $depInfo = $depInfo->map(function ($data) use ($clientData){
                    $clientData = $clientData
                        ->where('org_result_judgement_id', (empty($data->org_result_judgement_id))?'':(String)$data->org_result_judgement_id)
                        ->where('emp_result_judgement_id', (empty($data->emp_result_judgement_id))?'':(String)$data->emp_result_judgement_id)
                        ->first();
                    if( ! empty($clientData)){
                        // 5.2.1.1 นำข้อมูล adjust_result_score จาก client แทนค่าที่ได้จาก GetBonusAppraisalOrgLevel()
                        $data->adjust_result_score = $clientData['adjust_result_score'];
                        $data->emp_adjust_result_score = $clientData['emp_adjust_result_score'];
                        // 5.2.1.2 คำนวณหาค่า bonus_score ใหม่
                        $data->bonus_score = $data->adjust_result_score * $data->total_salary;
                        $data->emp_bonus_score = $data->emp_adjust_result_score * $data->emp_salary;
                    }
    
                    return $data;
                });
            }
            
            // 6. หาแต้มสิทธ์ทั้งหมดโดย (sum แต้มสิทธ์ของทุก dep + แต้มสิทธิ์ของ bu manager (prorate)) **เนื่องการเฉลี่ยโบนัสของ dep จะต้องหักจาก bu manager เสียก่อน
            if( ! empty($bu->emp_result_judgement_id)){
                $bu->emp_net_salary = $this->GetNetSalaryByEmpId($bu->emp_id, $request->period_id);
                $bu->emp_bonus_score = $bu->emp_adjust_result_score * $bu->emp_net_salary;
            }
            $depTotalBonusScore = $depInfo->sum('bonus_score') + $bu->emp_bonus_score;

            // 7. กำหนดยอดรวมโบนัสในระดับ dep **ยอดจะเท่ากับเงินโบนัสของ bu ที่เป็น parent ของ dep นั้น ๆ
            $depTotalBonusAmount = $bu->bonus_amount;

            // 8. คำนวณหาเปอร์เซ็นของ dep ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ dep * 100) / แต้มสิทธิ์ dep ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ dep)
            $bu->departments = $depInfo->map(function ($depData) use($depTotalBonusScore, $depTotalBonusAmount){
                // set zero to 1
                $depTotalBonusScore = ($depTotalBonusScore == 0) ? 1: $depTotalBonusScore;
                $depTotalBonusAmount = ($depTotalBonusAmount == 0) ? 1: $depTotalBonusAmount;

                $depData->bonus_percent = number_format((($depData->bonus_score * 100) / $depTotalBonusScore), 2);
                $depData->bonus_amount = number_format(($depData->bonus_percent / 100) * $depTotalBonusAmount,2);
                $depData->bonus_score = number_format($depData->bonus_score, 2);
                return $depData;
            });
            
        }
        // 9. return กลับไปยัง client ด้วย status = 200, ข้อมูลที่ใช้ในการแสดง, edit flag และ message ในหัวข้อ 1.1
        // ทำขึ้นเพื่อให้สามารถแบ่ง page ได้
        $seq = 0;
        foreach ($buInfo as $info) {
            $seq = $seq+1;
            $info->seq = $seq;
            $info->parent_org_group = $info->org_code;

            foreach ($info->departments as $val) {
                $seq = $seq+1;
                $val->seq = $seq;
                $val->parent_org_group = $info->org_code;

                $buInfo->push($val);
            }
            unset($info->departments);
        }
        $buInfo = $buInfo->sortBy('seq')->values()->all();
        $buInfo = $this->SetPagination($request->page, $request->rpp, $buInfo);

        return response()->json(['status'=> 200, 'edit_flag'=>$editFlag, 'message'=>$editMessage, 'datas'=> $buInfo->toArray()]);
    }


    public function SavedAndCalculation(Request $request)
    {
        $requestValid = Validator::make($request->all(), [
            'appraisal_year' => 'required',
            'period_id' => 'required',
            'monthly_bonus_rate' => 'required_if:calculate_flag,==,1',
            'calculate_flag' => 'required',
            'data' => 'required'
        ]);
        if ($requestValid->fails()) {
            return response()->json(['status' => 400, 'data' => implode(" ", $requestValid->messages()->all())]);
        }


        // update bonus adjust result score on org_result_judgement, emp_result_judgement.
        foreach ($request->data as $data) {
            $validator = Validator::make($data, [
                'org_result_judgement_id' => 'required|integer',
                'adjust_result_score' => 'numeric',
            ]);
            if ($validator->fails()) {
                return response()->json(['status' => 400, 'data' => implode(" ", $validator->messages()->all())]);
            }

            try{
                $orgResultJudgement = OrgResultJudgement::find($data['org_result_judgement_id']);
                $orgResultJudgement->adjust_result_score = $data['adjust_result_score'];
                $orgResultJudgement->updated_by = Auth::id();
                $orgResultJudgement->save();

                try{
                    $empResultJudgement = EmpResultJudgement::findOrFail($data['emp_result_judgement_id']);
                    $empResultJudgement->adjust_result_score = $data['emp_adjust_result_score'];
                    $empResultJudgement->created_by = Auth::id();
                    $empResultJudgement->save();
                } catch (ModelNotFoundException $e) {
                    // not thing
                }
                
            } catch (ModelNotFoundException $e) {
                return response()->json(['status' => 400, 'data' => $e->getMessage()]);
            }
        }

        if($request->calculate_flag == "0"){
            // return response if not re-calculate bonus.
            return response()->json(['status' => 200, 'data' => ["success" => [], "error" => []]]);
        } else {
            // bonus bonuscalculation.
            // update bonus rate on system_config.
            $systemConfiguration = SystemConfiguration::first();
            $systemConfiguration->monthly_bonus_rate = $request->monthly_bonus_rate;
            $systemConfiguration->save();
            return response()->json($this->BonusCalculation($request->period_id, $systemConfiguration->monthly_bonus_rate));
        }
                
    }


    /** @ คำนวณโบนัสโดยกระจายยอดโบนัส จากระดับบนลงล่างโดยเฉลี่ยจากคะแนนโบนัส
     */
    private function BonusCalculation($period, $monthlyBonusRate)
    {
        $appraisalStage = AppraisalStage::where('bonus_appraisal_flag', 1)->get()->first();

        // 1. Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
        $levelStartCalBonus = AppraisalLevel::where('is_start_cal_bonus', 1)->where('is_org', 1)->get()->first()->level_id;
        $buInfo = $this->GetBonusAppraisalOrgLevel($period, $levelStartCalBonus, null);

        // 2. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ business unit
        // 2.1 หา เงินเดือนทั้งหมดโดย sum เงินเดือนของทุก bu, หายอดรวมเงินโบนัสโดย (งินเดือนทั้งหมด * จำนวนเดือนที่จ่ายโบนัส), หาแต้มสิทธ์ทั้งหมดโดย sum แต้มสิทธ์ของทุก bu
        $buTotalSalary = $buInfo->sum('total_salary');
        $buTotalBonusAmount = $monthlyBonusRate * $buTotalSalary;
        $buTotalBonusScore = $buInfo->sum('bonus_score');

        // 2.2 หาเปอร์เซ็นสิทธิ์ของแต่ละ bu ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ bu * 100) / แต้มสิทธิ์ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสทั้งหมด)
        $buInfo = $buInfo->map(function ($buData) use($buTotalBonusScore, $buTotalBonusAmount){
            // set zero to 1
            $buTotalBonusScore = ($buTotalBonusScore == 0) ? 1: $buTotalBonusScore;
            $buTotalBonusAmount = ($buTotalBonusAmount == 0) ? 1: $buTotalBonusAmount;

            $buData->bonus_percent = ($buData->bonus_score * 100) / $buTotalBonusScore;
            $buData->bonus_amount = ($buData->bonus_percent / 100) * $buTotalBonusAmount;

            // 2.3 บันทึกผลลงตาราง org_result_judgement ที่ bonus_score(แต้มสิทธิ์), bonus_percent(เปอร์เซ็นสิทธิ์)
            $orgResultJudgement = OrgResultJudgement::find($buData->org_result_judgement_id);
            $orgResultJudgement->bonus_score = $buData->bonus_score;
            $orgResultJudgement->bonus_percent = $buData->bonus_percent;
            $orgResultJudgement->save();

            return $buData;
        });

        // 3. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ department
        foreach ($buInfo as $bu) {
            // 3.1 Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
            $depInfo = $this->GetBonusAppraisalOrgLevel($period, null, $bu->org_code);
            
            // 3.2 หาเงินเดือนสุทธิของ bu mgr. โดยการคิด pro rate และหาแต้มสิทธิ์ของ bu mgr.
            if( ! empty($bu->emp_result_judgement_id)){
                $bu->emp_net_salary = $this->GetNetSalaryByEmpId($bu->emp_id, $period);
                $bu->emp_bonus_score = $bu->emp_adjust_result_score * $bu->emp_net_salary;
            }

            // 3.3 หาแต้มสิทธ์ทั้งหมดโดย (sum แต้มสิทธ์ของทุก dep + แต้มสิทธิ์ของ bu mgr.) **เนื่องการเฉลี่ยโบนัสของ dep จะต้องหักจาก bu manager เสียก่อน
            $depTotalBonusScore = $depInfo->sum('bonus_score') + $bu->emp_bonus_score;

            // 3.4 กำหนดยอดรวมโบนัสในระดับ dep **ยอดจะเท่ากับเงินโบนัสของ bu ที่เป็น parent ของ dep นั้น ๆ
            $depTotalBonusAmount = $bu->bonus_amount;

            // 3.5 ตรวจสอบว่า bu มี bu mgr. หรือไม่ 
            if( ! empty($bu->emp_result_judgement_id)){
                // set zero to 1
                $depTotalBonusScore = ($depTotalBonusScore == 0) ? 1: $depTotalBonusScore;

                // 3.5.1 กรณีมี bu manager 
                // 3.5.1.1 คำนวณหาเปอร์เซ็นของ bu mgr. และบันทึกผล 
                $bu->emp_bonus_percent = (($bu->emp_bonus_score * 100) / $depTotalBonusScore);
                $empResultJudgement = EmpResultJudgement::find($bu->emp_result_judgement_id);
                $empResultJudgement->percent_adjust = $bu->emp_bonus_percent;
                $empResultJudgement->is_bonus = 1;
                $empResultJudgement->save();

                // 3.5.1.2 คำนวณเงินโบนัสของ bu mgr. และบันทึกผล
                $bu->emp_bonus_amount = ($bu->emp_bonus_percent / 100) * $depTotalBonusAmount;
                $empResult = EmpResult::find($empResultJudgement->emp_result_id);
                $empResult->net_s_amount = $bu->emp_net_salary;
                $empResult->b_amount = $bu->emp_bonus_amount;
                $empResult->adjust_b_amount = $bu->emp_bonus_amount;
                $empResult->b_rate = $monthlyBonusRate;
                $empResult->adjust_b_rate = $monthlyBonusRate;
                $empResult->stage_id = $appraisalStage->to_stage_id;
                $empResult->status = $appraisalStage->to_action;
                $empResult->updated_by = Auth::id();
                $empResult->save();
            }
            
            // 3.6 คำนวณหาเปอร์เซ็นของ dep ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ dep * 100) / แต้มสิทธิ์ dep ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ dep)
            $bu->departments = $depInfo->map(function ($depData) use($depTotalBonusScore, $depTotalBonusAmount){
                // set zero to 1
                $depTotalBonusScore = ($depTotalBonusScore == 0) ? 1: $depTotalBonusScore;

                $depData->bonus_percent = (($depData->bonus_score * 100) / $depTotalBonusScore);
                $depData->bonus_amount = ($depData->bonus_percent / 100) * $depTotalBonusAmount;

                // 3.7 บันทึกผล dep ลงตาราง org_result_judgement ที่ bonus_score(แต้มสิทธิ์), bonus_percent(เปอร์เซ็นสิทธิ์)
                $orgResultJudgement = OrgResultJudgement::find($depData->org_result_judgement_id);
                $orgResultJudgement->bonus_score = $depData->bonus_score;
                $orgResultJudgement->bonus_percent = $depData->bonus_percent;
                $orgResultJudgement->updated_by = Auth::id();
                $orgResultJudgement->save();

                return $depData;
            });

            // 4. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ Operate
            foreach ($bu->departments as $dep) {
                // 4.1 Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalEmpLevel()
                $operInfo = $this->GetBonusAppraisalEmpLevel($period, $dep->org_code);
                
                // 4.2 หาเงินเดือนสุทธิของ dep mgr. โดยการคิด pro rate และหาแต้มสิทธิ์ของ dep mgr.
                if( ! empty($dep->emp_result_judgement_id)){
                    $dep->emp_net_salary = $this->GetNetSalaryByEmpId($dep->emp_id, $period);
                    $dep->emp_bonus_score = $dep->emp_adjust_result_score * $dep->emp_net_salary;
                }

                // 4.3 หาเงินเดือนสุทธิของ oper โดยการคิด prorate และหาแต้มสิทธิ์ของท oper ทุกคน
                $operInfo = $operInfo->map(function ($pRateData) use($period){
                    $pRateData->emp_net_salary = $this->GetNetSalaryByEmpId($pRateData->emp_id, $period);
                    $pRateData->emp_bonus_score = $pRateData->emp_adjust_result_score * $pRateData->emp_net_salary;
                    return $pRateData;
                });

                // 4.3 หาแต้มสิทธ์ทั้งหมดโดย (sum แต้มสิทธ์ของทุก oper + แต้มสิทธิ์ของ dep manager) **เนื่องการเฉลี่ยโบนัส oper จะต้องหักจาก dep manager เสียก่อน
                $operTotalBonusScore = $operInfo->sum('emp_bonus_score') + $dep->emp_bonus_score ;

                // 4.4 กำหนดยอดรวมโบนัสในระดับ oper **ยอดจะเท่ากับเงินโบนัสของ dep ที่เป็น parent ของ oper นั้น ๆ
                $operTotalBonusAmount = $dep->bonus_amount;

                // 4.5 ตรวจสอบว่า dep มี dep manager หรือไม่ 
                if( ! empty($dep->emp_result_judgement_id)){
                    // set zero to 1
                    $operTotalBonusScore = ($operTotalBonusScore == 0) ? 1: $operTotalBonusScore;

                    // 4.5.1 กรณีมี dep manager 
                    // 4.5.1.1 คำนวณหาเปอร์เซ็นของ dep manager และบันทึกผล 
                    $dep->emp_bonus_percent = (($dep->emp_bonus_score * 100) / $operTotalBonusScore);
                    $empResultJudgement = EmpResultJudgement::find($dep->emp_result_judgement_id);
                    $empResultJudgement->percent_adjust = $dep->emp_bonus_percent;
                    $empResultJudgement->is_bonus = 1;
                    $empResultJudgement->save();

                    // 4.5.1.2 คำนวณเงินโบนัสของ dep manager และบันทึกผล
                    $dep->emp_bonus_amount = ($dep->emp_bonus_percent / 100) * $operTotalBonusAmount;
                    $empResult = EmpResult::find($empResultJudgement->emp_result_id);
                    $empResult->net_s_amount = $dep->emp_net_salary;
                    $empResult->b_amount = $dep->emp_bonus_amount;
                    $empResult->adjust_b_amount = $dep->emp_bonus_amount;
                    $empResult->b_rate = $monthlyBonusRate;
                    $empResult->adjust_b_rate = $monthlyBonusRate;
                    $empResult->stage_id = $appraisalStage->to_stage_id;
                    $empResult->status = $appraisalStage->to_action;
                    $empResult->updated_by = Auth::id();
                    $empResult->save();
                }

                // 4.6 คำนวณหาเปอร์เซ็นของ oper ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ oper * 100) / แต้มสิทธิ์ oper ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ oper)
                $dep->employees = $operInfo->map(function ($operData) use($operTotalBonusScore, $operTotalBonusAmount, $monthlyBonusRate, $appraisalStage){
                    // set zero to 1
                    $operTotalBonusScore = ($operTotalBonusScore == 0) ? 1: $operTotalBonusScore;

                    $operData->emp_bonus_percent = (($operData->emp_bonus_score * 100) / $operTotalBonusScore);
                    $operData->emp_bonus_amount = ($operData->emp_bonus_percent / 100) * $operTotalBonusAmount;

                    // 4.7 บันทึกผล oper ลงตาราง emp_result_judgement
                    $empResultJudgement = EmpResultJudgement::find($operData->emp_result_judgement_id);
                    $empResultJudgement->percent_adjust = $operData->emp_bonus_percent;
                    $empResultJudgement->adjust_result_score = $operData->emp_adjust_result_score;
                    $empResultJudgement->is_bonus = 1;
                    $empResultJudgement->save();
                    
                    $empResult = EmpResult::find($empResultJudgement->emp_result_id);
                    $empResult->net_s_amount = $operData->emp_net_salary;
                    $empResult->b_amount = $operData->emp_bonus_amount;
                    $empResult->adjust_b_amount = $operData->emp_bonus_amount;
                    $empResult->b_rate = $monthlyBonusRate;
                    $empResult->adjust_b_rate = $monthlyBonusRate;
                    $empResult->stage_id = $appraisalStage->to_stage_id;
                    $empResult->status = $appraisalStage->to_action;
                    $empResult->updated_by = Auth::id();
                    $empResult->save();

                    return $operData;
                });
            }
        }

        return ['status'=>200, 'data'=>[]];
    }
    

    /** @ ดึงข้อมูลระดับ Organization ที่ใช้ในการคำนวณ Bonus
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
            SELECT orj.org_result_judgement_id, 
                org.level_id, 
                vel.appraisal_level_name,
                orj.org_id, 
                org.org_code, 
                org.org_name,
                orj.avg_result_score,
                IF(orj.adjust_result_score=0, orj.avg_result_score, orj.adjust_result_score) AS adjust_result_score,
                orj.total_salary, 
                IF(orj.adjust_result_score=0, orj.avg_result_score, orj.adjust_result_score) * orj.total_salary AS bonus_score,
                0 AS bonus_percent,
                0 AS bonus_amount,
                erj.emp_result_judgement_id,
                erj.emp_id, 
                erj.emp_name,
                erj.adjust_result_score AS emp_result_score,
                erj.adjust_result_score AS emp_adjust_result_score,
                erj.s_amount AS emp_salary,
                0 AS emp_net_salary,
                0 AS emp_bonus_score,
                0 AS emp_bonus_percent,
                0 AS emp_bonus_amount
            FROM org_result_judgement orj
            INNER JOIN org ON org.org_id = orj.org_id
            INNER JOIN appraisal_level vel ON vel.level_id = org.level_id
            LEFT OUTER JOIN(
                SELECT e.emp_result_judgement_id, e.org_level_id, er.org_id, 
                    IF(e.adjust_result_score=0, er.result_score, e.adjust_result_score) adjust_result_score,
                    emp.emp_id, emp.emp_name, emp.s_amount
                FROM emp_result_judgement e
                INNER JOIN emp_result er ON er.emp_result_id = e.emp_result_id
                INNER JOIN employee emp ON emp.emp_id = er.emp_id
                WHERE e.created_dttm = (
                    SELECT MAX(se.created_dttm)
                    FROM emp_result_judgement se
                    WHERE se.emp_result_id = e.emp_result_id
                )
                AND emp.emp_id = (
                    SELECT se.emp_id
                    FROM employee se 
                    INNER JOIN appraisal_level vel ON vel.level_id = se.level_id
                    WHERE se.org_id = er.org_id
                    ORDER BY vel.seq_no
                    LIMIT 1
                )
            ) erj ON erj.org_id = orj.org_id
            WHERE orj.period_id = '{$period}'
            ".$buLevelQryStr."
            ".$parentOrgQryStr."
        ");

        return collect($items);
    }


    /** @ ดึงข้อมูลระดับ Individual ที่ใช้ในการคำนวณ Bonus
     * arg1: Parameter Period
     * arg2: Parameter Org Code ที่ต้องการหาข้อมูลพนักงาน
     */
    private function GetBonusAppraisalEmpLevel($period, $orgCode)
    {
        
        $orgQryStr = empty($orgCode) ? "": " AND FIND_IN_SET(org.org_code, '{$this->GetAllUnderOrg($orgCode)}')";

        $items = DB::select("
            SELECT e.emp_result_judgement_id, 
                er.org_id, 
                er.level_id, 
                IF(e.adjust_result_score=0, er.result_score, e.adjust_result_score) AS emp_adjust_result_score,
                emp.emp_id, 
                emp.emp_name, 
                emp.s_amount AS emp_salary,
                0 AS emp_net_salary,
                0 AS emp_bonus_score,
                0 AS emp_bonus_percent,
                0 AS emp_bonus_amount
            FROM emp_result_judgement e
            INNER JOIN emp_result er ON er.emp_result_id = e.emp_result_id
            INNER JOIN employee emp ON emp.emp_id = er.emp_id
            INNER JOIN org ON org.org_id = er.org_id
            WHERE e.created_dttm = (
                SELECT MAX(se.created_dttm)
                FROM emp_result_judgement se
                WHERE se.emp_result_id = e.emp_result_id
            )
            AND er.period_id = '{$period}'
            ".$orgQryStr."
        ");

        return collect($items);
    }


    public function SetPagination($page, $rpp, $itemArr)
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


    public function GetNetSalaryByEmpId($empId, $periodId)
    { 
        // 1. ดึงข้อมูลของ emp เพื่อนำวันเริ่มต้นทำงานไปคิด prorate
        $empInfo = Employee::where('emp_id', $empId)->get()->first();
        if($empInfo->count() == 0) {
            // 1.1 ในกรณีที่ไม่พบ Emp ให้การ returm net salary เป็น 0
            return 0;
        }

        // 2. ดึงข้อมูล period เพื่อน้ำข้อมูลวันที่เริ่มต้น และสิ้นสุดของช่วงการคำนวณโบนัส
        $periodInfo = AppraisalPeriod::where('period_id', $periodId)->get()->first();
        // 2.1 ดึงข้อมูล frequency หาค่าความถี่ของ period นั้น เพื่อนำไปหาเดือนเริ่มต้นของการคำนวณ
        $appraisalFrequency = AppraisalFrequency::find($periodInfo->appraisal_frequency_id);
        if($periodInfo->count() == 0) {
            // 2.2 ในกรณีที่ไม่พบ period ให้การ returm net salary เป็น 0
            return 0;
        }

        // 3. หาจำนวนเดือนของ และวันเริ่มต้น ของ period         
        $periodEnd = Carbon::createFromFormat('Y-m-d', $periodInfo->end_date);
        $periodStart = Carbon::createFromFormat('Y-m-d', $periodEnd->year.'-'.(($periodEnd->month-$appraisalFrequency->frequency_month_value)+1).'-01');
        $periodMonthCnt = ($periodStart->diffInMonths($periodEnd) + 1);

        // 4. ทำการหา Net Salary
        // 4.1 ตรวจสอบวันเริ่มงานของพนักงาน
        if($empInfo->working_start_date <= $periodStart){
            // 4.1.1 ถ้าวันเริ่มงานน้อยกว่าวันเริ่มของ period แสดงว่าทำงานครบเดือนโบนัส นำ s_amount ไปใช้งาน
            return round($empInfo->s_amount, 2);
        } 
        else {       
            // 4.2 หาจำนวนเดือนที่พนักงานทำงาน (เข้างานวันที่ 1-15 คิดเป็น 1 เดือน, เข้างานวันที่ 16 เป็นต้นไป ไปคิดเดือนหน้า)
            $empWorkStartDate = Carbon::createFromFormat('Y-m-d', $empInfo->working_start_date);
            if($empWorkStartDate->day > 15){
                $empMonthCnt = $empWorkStartDate->diffInMonths($periodEnd);
            } else {
                $empMonthCnt = ($empWorkStartDate->diffInMonths($periodEnd) + 1);
            }
            
            // 4.3 คำนวณหา net salary
            // set zero to 1
            $periodMonthCnt = ($periodMonthCnt == 0) ? 1: $periodMonthCnt;
            return ($empInfo->s_amount * $empMonthCnt) / $periodMonthCnt;
        }

        return 0;
    }


    private function GetAllUnderOrg($orgCode)
	{
		$globalOrgCodeSet = "";
		$inLoop = true;
		$loopCnt = 1;

		while ($inLoop){
			if($loopCnt == 1){
				$LoopOrgCodeSet = $orgCode.",";
			}
			
			// Check each under //
			$eachUnder = DB::select("
                SELECT org_code
                FROM org
                WHERE parent_org_code != ''
                AND FIND_IN_SET(parent_org_code, '{$LoopOrgCodeSet}')
            ");

			if(empty($eachUnder)){
				$inLoop = false;
			} else {
				$LoopOrgCodeSet = "";
				foreach ($eachUnder as $under) {
					$LoopOrgCodeSet .= $under->org_code.",";
				}
				$globalOrgCodeSet .= $LoopOrgCodeSet;
			}
			$loopCnt = $loopCnt + 1;
		}
		
		return $globalOrgCodeSet;
    }


    /** @ ดึงข้อมูล org ทั้งหมดที่อยู่ภายใต้ bu เพื่อนำไปตรวจสอบหาสถานะการประเมิณของพนักงานทั้งหมด 
     * 
     */
    public function GetOrganizationsBonusCalculate()
    {
        $orgList = '';
        $curOrgList = '';
        $initOrgList = DB::select("
            SELECT CONCAT(GROUP_CONCAT(org_code), ',') AS org_code
            FROM org
            INNER JOIN appraisal_level vel ON vel.level_id = org.level_id
            WHERE parent_org_code != '' 
            AND vel.is_start_cal_bonus = 1
        ");
        if(empty($initOrgList)){ return "";} 
        else {
            $initOrgList = $initOrgList[0]; 
            $orgList .= $initOrgList->org_code;
            $curOrgList = $initOrgList->org_code;
        }

        $looping = true;
        while ($looping){ Log::info($curOrgList);
            $loopOrgList = DB::select("
                SELECT CONCAT(GROUP_CONCAT(org_code), ',') AS org_code
                FROM org
                INNER JOIN appraisal_level vel ON vel.level_id = org.level_id
                WHERE parent_org_code != '' 
                AND FIND_IN_SET(parent_org_code, '{$curOrgList}')
            ");

            if(empty($curOrgList)){
                $looping = false;
            } else {
                $orgList .= $loopOrgList[0]->org_code;
                $curOrgList = $loopOrgList[0]->org_code;
            }
        }

        return $orgList;
        // return explode(",",$orgList);
    }


}