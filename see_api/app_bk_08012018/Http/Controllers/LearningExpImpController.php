<?php
/**
 * Created by PhpStorm.
 * User: imake
 * Date: 12/19/17
 * Time: 9:39 PM
 */

namespace App\Http\Controllers;
use App\Http\Services\AppraisalService;
use App\Http\Services\AppraialValidator;
use App\Model\AppraisalStructureModel;
use Excel;
use Illuminate\Http\Request;
use Log;

class LearningExpImpController extends Controller
{
    public function exportMaster(Request $request){
        $filename = "master_import_learning";
        $structure_id = $request->structure_id;
        Excel::create($filename, function($excel) use ($structure_id) {
            $appraisalStructure = AppraisalStructureModel::find($structure_id);
            //$excel->sheet('Learning', function($sheet) {
            $excel->sheet($appraisalStructure->structure_name, function($sheet) {
                $headers = ["ตัวชี้วัด","หน่วยวัด","มุมมองBSC","เกณฑ์ประเมิน","เงื่อนไขการแจ้งเตือน\n1=Monthly, 2=Quarterly",
                    "แสดงผลต่าง (0/1)","ฟังก์ชั่น\r1=Sum, 2=Last","รหัสหน่วยวัด\n(VLOOKUP)","รหัสมุมมองBSC\n(VLOOKUP)","รหัสเกณฑ์ประเมิน\n(VLOOKUP)"];
                $sheet->appendRow($headers);
                $sheet->setWidth('A', 38);
                $sheet->setWidth('B', 12);
                $sheet->setWidth('C', 18);
                $sheet->setWidth('D', 14);
                $sheet->setWidth('E', 22);
                $sheet->setWidth('F', 18);
                $sheet->setWidth('G', 18);
                $sheet->setWidth('H', 18);
                $sheet->setWidth('I', 18);
                $sheet->setWidth('J', 18);

                $sheet->getRowDimension(1)->setRowHeight(35);
                $sheet->getStyle('A1:J1')->getAlignment()->applyFromArray(
                    array('horizontal' => 'center','vertical' => 'center')
                );
            });
            AppraisalService::createMasterSheet($excel);
        })->export('xlsx');
    }

    public function importMaster(Request $request){
        set_time_limit(0);
        $bank_values = [];
        $number_values = ['E','F','G','H','I','J'];
        $all_number_values = [];
        $appraialValidator = new AppraialValidator();
        $result_obj = $appraialValidator->validateTemplate(1,$request,$bank_values,$number_values,$all_number_values);

        if($result_obj->result_status == 1 )
            AppraisalService::importMaster($request);

        return response()->json($result_obj, 200);
    }

    public function exportDetail(Request $request){
        $filename = "detail_import_learning";
        $structure_id = $request->structure_id;
        Excel::create($filename, function($excel) use ($structure_id) {
            $appraisalStructure = AppraisalStructureModel::find($structure_id);
            AppraisalService::createDetailSheet($structure_id,$appraisalStructure->structure_name,$excel);
            AppraisalService::createDetailMasterSheet($excel);
        })->export('xlsx');
    }

    public function importDetail(Request $request){
        set_time_limit(0);
        $bank_values = [];
        $number_values = ['A'];
        $all_number_values = ['D'];
        $appraialValidator = new AppraialValidator();
        $result_obj = $appraialValidator->validateTemplate(3,$request,$bank_values,$number_values,$all_number_values);

        if($result_obj->result_status == 1 )
            AppraisalService::importDetail($request);

        return response()->json($result_obj, 200);
    }
}