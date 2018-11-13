<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\AppraisalLevel;
use App\Employee;
use App\EmpResult;
use App\EmpResultJudgement;
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
        $defaultMonthlyBonusRate = SystemConfiguration::first()->monthly_bonus_rate;

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
        $buInfo = $this->GetBonusAppraisalOrgLevel($request->period_id, $buLevel, null);

        // 3. ตรวจสอบ Action ว่าเป็น "search" หรือ "re-calculate"
        if($request->action == 're-calculate'){
            // 3.1 กรณีเป็น "re-calculate"
            $clientData = collect($request->data); Log::info($clientData);
            $buInfo = $buInfo->map(function ($data, $key) use ($clientData){
                $clientData = $clientData
                    ->where('org_result_judgement_id', (String) $data->org_result_judgement_id)
                    ->where('emp_result_judgement_id', (String) $data->emp_result_judgement_id)
                    ->first();
                if( ! empty($clientData)){
                    // 3.1.1 นำข้อมูล adjust_result_score จาก client แทนค่าที่ได้ GetBonusAppraisalOrgLevel()
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
            $buData->bonus_percent = round((($buData->bonus_score * 100) / $buTotalBonusScore), 2);
            $buData->bonus_amount = ($buData->bonus_percent / 100) * $buTotalBonusAmount;
            return $buData;
        });

        // 5. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ department
        foreach ($buInfo as $bu) {
            // 5.1 Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
            $depInfo = $this->GetBonusAppraisalOrgLevel($request->period_id, null, $bu->org_code);
            
            // 5.2 หาแต้มสิทธ์ทั้งหมดโดย (sum แต้มสิทธ์ของทุก dep + แต้มสิทธิ์ของ bu manager (prorate)) **เนื่องการเฉลี่ยโบนัสของ dep จะต้องหักจาก bu manager เสียก่อน
            if( ! empty($bu->emp_result_judgement_id)){
                $bu->emp_net_salary = $this->GetNetSalaryByEmpId($bu->emp_id, $request->period_id);
                $bu->emp_bonus_score = $bu->emp_adjust_result_score * $bu->emp_net_salary;
            }
            $depTotalBonusScore = $depInfo->sum('bonus_score') + $bu->emp_bonus_score;

            // 5.3 กำหนดยอดรวมโบนัสในระดับ dep **ยอดจะเท่ากับเงินโบนัสของ bu ที่เป็น parent ของ dep นั้น ๆ
            $depTotalBonusAmount = $bu->bonus_amount;

            // 5.4 คำนวณหาเปอร์เซ็นของ dep ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ dep * 100) / แต้มสิทธิ์ dep ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ dep)
            $bu->departments = $depInfo->map(function ($depData) use($depTotalBonusScore, $depTotalBonusAmount){
                $depData->bonus_percent = round((($depData->bonus_score * 100) / $depTotalBonusScore), 2);
                $depData->bonus_amount = ($depData->bonus_percent / 100) * $depTotalBonusAmount;
                return $depData;
            });
            
        }

        return response()->json($this->SetPagination($request->page, $request->rpp, $buInfo->toArray()));
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
            $systemConfiguration = SystemConfiguration::first();
            $systemConfiguration->monthly_bonus_rate = $request->monthly_bonus_rate;
            $systemConfiguration->save();
            return response()->json($this->BonusCalculation($request->period_id, $systemConfiguration->monthly_bonus_rate));
        }
                
    }


    /** @ BonusCalculation
     * 1. Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
     * 2. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ business unit
     *    2.1 หา เงินเดือนทั้งหมดโดย sum เงินเดือนของทุก bu, หายอดรวมเงินโบนัสโดย (งินเดือนทั้งหมด * จำนวนเดือนที่จ่ายโบนัส), หาแต้มสิทธ์ทั้งหมดโดย sum แต้มสิทธ์ของทุก bu
     *    2.2 หาเปอร์เซ็นสิทธิ์ของแต่ละ bu ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ bu * 100) / แต้มสิทธิ์ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสทั้งหมด)
     * 3. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ department
     *    3.1 Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
     *    3.2 หาเงินเดือนสุทธิของ bu mgr. โดยการคิด pro rate และหาแต้มสิทธิ์ของ bu mgr.
     *    3.3 หาแต้มสิทธ์ทั้งหมดโดย (sum แต้มสิทธ์ของทุก dep + แต้มสิทธิ์ของ bu mgr.) **เนื่องการเฉลี่ยโบนัสของ dep จะต้องหักจาก bu manager เสียก่อน
     *    3.4 กำหนดยอดรวมโบนัสในระดับ dep **ยอดจะเท่ากับเงินโบนัสของ bu ที่เป็น parent ของ dep นั้น ๆ
     *    3.5 ตรวจสอบว่า bu มี bu mgr. หรือไม่ 
     *    3.6 คำนวณหาเปอร์เซ็นของ dep ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ dep * 100) / แต้มสิทธิ์ dep ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ dep)
     *    3.7 บันทึกผล dep ลงตาราง org_result_judgement ที่ bonus_score(แต้มสิทธิ์), bonus_percent(เปอร์เซ็นสิทธิ์)
     * 4. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ Operate
     *    4.1 Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalEmpLevel()
     *    4.2 หาเงินเดือนสุทธิของ dep mgr. โดยการคิด pro rate และหาแต้มสิทธิ์ของ dep mgr.
     *    4.3 หาเงินเดือนสุทธิของ oper โดยการคิด prorate และหาแต้มสิทธิ์ของท oper ทุกคน
     *    4.4 กำหนดยอดรวมโบนัสในระดับ oper **ยอดจะเท่ากับเงินโบนัสของ dep ที่เป็น parent ของ oper นั้น ๆ
     *    4.5 ตรวจสอบว่า dep มี dep manager หรือไม่ 
     *       4.5.1 กรณีมี dep manager 
     *          4.5.1.1 คำนวณหาเปอร์เซ็นของ dep manager และบันทึกผล 
     *          4.5.1.2 คำนวณเงินโบนัสของ dep manager และบันทึกผล
     *    4.6 คำนวณหาเปอร์เซ็นของ oper ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ oper * 100) / แต้มสิทธิ์ oper ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ oper)
     *    4.7 บันทึกผล oper ลงตาราง emp_result_judgement
     */
    private function BonusCalculation($period, $monthlyBonusRate)
    {
        // 1. Query หาข้อมูลที่ใช้ในการคำนวณ โดยทำผ่าน GetBonusAppraisalOrgLevel()
        $levelStartCalBonus = AppraisalLevel::where('is_start_cal_bonus', 1)->get()->first()->level_id;
        $buInfo = $this->GetBonusAppraisalOrgLevel($period, $levelStartCalBonus, null);

        // 2. ทำการคำนวณหาค่า bonus_score, bonus_percent, bonus_amount ในระดับ business unit
        // 2.1 หา เงินเดือนทั้งหมดโดย sum เงินเดือนของทุก bu, หายอดรวมเงินโบนัสโดย (งินเดือนทั้งหมด * จำนวนเดือนที่จ่ายโบนัส), หาแต้มสิทธ์ทั้งหมดโดย sum แต้มสิทธ์ของทุก bu
        $buTotalSalary = $buInfo->sum('total_salary');
        $buTotalBonusAmount = $monthlyBonusRate * $buTotalSalary;
        $buTotalBonusScore = $buInfo->sum('bonus_score');

        // 2.2 หาเปอร์เซ็นสิทธิ์ของแต่ละ bu ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ bu * 100) / แต้มสิทธิ์ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสทั้งหมด)
        $buInfo = $buInfo->map(function ($buData) use($buTotalBonusScore, $buTotalBonusAmount){
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
                // 3.5.1 กรณีมี bu manager 
                // 3.5.1.1 คำนวณหาเปอร์เซ็นของ bu mgr. และบันทึกผล 
                $bu->emp_bonus_percent = (($bu->emp_bonus_score * 100) / $depTotalBonusScore);
                $empResultJudgement = EmpResultJudgement::find($bu->emp_result_judgement_id);
                $empResultJudgement->percent_adjust = $bu->emp_bonus_percent;
                $empResultJudgement->save();

                // 3.5.1.2 คำนวณเงินโบนัสของ bu mgr. และบันทึกผล
                $bu->emp_bonus_amount = ($bu->emp_bonus_percent / 100) * $depTotalBonusAmount;
                $empResult = EmpResult::find($empResultJudgement->emp_result_id);
                $empResult->net_s_amount = $bu->emp_net_salary;
                $empResult->b_amount = $bu->emp_bonus_amount;
                $empResult->adjust_b_amount = $bu->emp_bonus_amount;
                $empResult->b_rate = $monthlyBonusRate;
                $empResult->adjust_b_rate = $monthlyBonusRate;
                $empResult->updated_by = Auth::id();
                $empResult->save();
            }
            
            // 3.6 คำนวณหาเปอร์เซ็นของ dep ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ dep * 100) / แต้มสิทธิ์ dep ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ dep)
            $bu->departments = $depInfo->map(function ($depData) use($depTotalBonusScore, $depTotalBonusAmount){
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
                    // 4.5.1 กรณีมี dep manager 
                    // 4.5.1.1 คำนวณหาเปอร์เซ็นของ dep manager และบันทึกผล 
                    $dep->emp_bonus_percent = (($dep->emp_bonus_score * 100) / $operTotalBonusScore);
                    $empResultJudgement = EmpResultJudgement::find($dep->emp_result_judgement_id);
                    $empResultJudgement->percent_adjust = $dep->emp_bonus_percent;
                    $empResultJudgement->save();

                    // 4.5.1.2 คำนวณเงินโบนัสของ dep manager และบันทึกผล
                    $dep->emp_bonus_amount = ($dep->emp_bonus_percent / 100) * $operTotalBonusAmount;
                    $empResult = EmpResult::find($empResultJudgement->emp_result_id);
                    $empResult->net_s_amount = $dep->emp_net_salary;
                    $empResult->b_amount = $dep->emp_bonus_amount;
                    $empResult->adjust_b_amount = $dep->emp_bonus_amount;
                    $empResult->b_rate = $monthlyBonusRate;
                    $empResult->adjust_b_rate = $monthlyBonusRate;
                    $empResult->updated_by = Auth::id();
                    $empResult->save();
                }

                // 4.6 คำนวณหาเปอร์เซ็นของ oper ที่จะได้เงินโบนัส ((แต้มสิทธิ์ของ oper * 100) / แต้มสิทธิ์ oper ทั้งหมด), คำนวณหาเงินโบนัสที่จะได้ ((เปอร์เซ็นก่อนหน้า / 100) * ยอดรวมโบนัสของ oper)
                $dep->employees = $operInfo->map(function ($operData) use($operTotalBonusScore, $operTotalBonusAmount, $monthlyBonusRate){
                    $operData->emp_bonus_percent = (($operData->emp_bonus_score * 100) / $operTotalBonusScore);
                    $operData->emp_bonus_amount = ($operData->emp_bonus_percent / 100) * $operTotalBonusAmount;

                    // 4.7 บันทึกผล oper ลงตาราง emp_result_judgement
                    $empResultJudgement = EmpResultJudgement::find($operData->emp_result_judgement_id);
                    $empResultJudgement->percent_adjust = $operData->emp_bonus_percent;
                    $empResultJudgement->adjust_result_score = $operData->emp_adjust_result_score;
                    $empResultJudgement->save();

                    $empResult = EmpResult::find($empResultJudgement->emp_result_id);
                    $empResult->net_s_amount = $operData->emp_net_salary;
                    $empResult->b_amount = $operData->emp_bonus_amount;
                    $empResult->adjust_b_amount = $operData->emp_bonus_amount;
                    $empResult->b_rate = $monthlyBonusRate;
                    $empResult->adjust_b_rate = $monthlyBonusRate;
                    $empResult->updated_by = Auth::id();
                    $empResult->save();

                    return $operData;
                });

            }
        }

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
	            0 AS emp_bonus_amount,
                IFNULL(erj.edit_flag, 0) AS edit_flag
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
                AND e.created_dttm = (
                    SELECT MAX(se.created_dttm)
                    FROM emp_result_judgement se
                    WHERE se.emp_result_id = e.emp_result_id
                )
            ) erj ON erj.level_id = org.level_id AND erj.org_id = orj.org_id
            WHERE orj.period_id = '{$period}'
            ".$buLevelQryStr."
            ".$parentOrgQryStr."
        ");

        return collect($items);
    }


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
                0 AS emp_bonus_amount,
                stg.edit_flag
            FROM emp_result_judgement e
            INNER JOIN emp_result er ON er.emp_result_id = e.emp_result_id
            INNER JOIN employee emp ON emp.emp_id = er.emp_id
            INNER JOIN org ON org.org_id = er.org_id
            LEFT OUTER JOIN appraisal_stage stg ON stg.stage_id = er.stage_id
            WHERE er.status = 'Bonus Evaluate' 
            AND e.created_dttm = (
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
        if($periodInfo->count() == 0) {
            // 2.1 ในกรณีที่ไม่พบ period ให้การ returm net salary เป็น 0
            return 0;
        }

        // 3. ทำการหา Net Salary
        // 3.1 ตรวจสอบวันเริ่มงานของพนักงาน
        if($empInfo->working_start_date <= $periodInfo->start_date){
            // 3.1.1 ถ้าวันเริ่มงานน้อยกว่าวันเริ่มของ period แสดงว่าทำงานครบปีใช้ basic salary ให้ใช้ basic amount
            return round($empInfo->s_amount, 2);
        } 
        else {
            // 3.1.2 หาจำนวนเดือนของ period            
            $periodEnd = Carbon::createFromFormat('Y-m-d', $periodInfo->end_date);
            $periodStart = Carbon::createFromFormat('Y-m-d', $periodEnd->year.'-01-01');
            $periodMonthCnt = ($periodStart->diffInMonths($periodEnd) + 1);

            // 3.1.3 หาจำนวนเดือนที่พนักงานทำงาน (เข้างานวันที่ 1-15 คิดเป็น 1 เดือน, เข้างานวันที่ 16 เป็นต้นไป ไปคิดเดือนหน้า)
            $empWorkStartDate = Carbon::createFromFormat('Y-m-d', $empInfo->working_start_date);
            if($empWorkStartDate->day > 15){
                $empMonthCnt = $empWorkStartDate->diffInMonths($periodEnd);
            } else {
                $empMonthCnt = ($empWorkStartDate->diffInMonths($periodEnd) + 1);
            }
            
            // 3.1.4 คำนวณหา net salary
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
            Log::info("SELECT org_code FROM org WHERE FIND_IN_SET(parent_org_code, '{$LoopOrgCodeSet}')");

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

}
