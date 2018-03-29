<?php
/**
 * Created by PhpStorm.
 * User: imake
 * Date: 12/19/17
 * Time: 9:36 PM
 */

namespace App\Http\Controllers;
use App\Http\Services\AppraisalService;
use App\Http\Services\AppraialValidator;
use App\Model\AppraisalStructureModel;
use Excel;
use Illuminate\Http\Request;
use Log;

class AppraisalExpImpController extends Controller
{
    public function __construct()
    {
        $this->middleware('cors');
    }
    public function exportMaster(Request $request){
        $structure_id = $request->structure_id;
        $appraisalStructure = AppraisalStructureModel::find($structure_id);
        $structure_name = $appraisalStructure->structure_name;
        $form_id = $appraisalStructure->form_id;
        $filename = "master_import_".strtolower($structure_name);
        Excel::create($filename, function($excel) use ($form_id, $structure_name, $structure_id) {
            $excel->sheet($structure_name, function($sheet) use ($form_id) {
                if($form_id ==1 ) {
                    $headers = ["ตัวชี้วัด", "หน่วยวัด", "มุมมองBSC", "เกณฑ์ประเมิน", "เงื่อนไขการแจ้งเตือน\n1=Monthly, 2=Quarterly",
                        "แสดงผลต่าง (0/1)","แสดงใน Corporate \rDashboard (0/1)", "ฟังก์ชั่น\r1=Sum, 2=Last", "รหัสหน่วยวัด\n(VLOOKUP)", "รหัสมุมมองBSC\n(VLOOKUP)", "รหัสเกณฑ์ประเมิน\n(VLOOKUP)"];
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
                    $sheet->setWidth('K', 18);

                    $sheet->getRowDimension(1)->setRowHeight(35);
                }else if($form_id ==2 ) {
                    $headers = ["ตัวชี้วัด"];
                    $sheet->setWidth('A', 38);
                    $sheet->appendRow($headers);
                }else{
                    $headers = ["ตัวชี้วัด","ค่าสูงสุดก่อนหักคะแนน","คะแนนที่หักต่อหน่วย","ค่าเริ่มต้นที่จะได้คะแนนเป็นศูนย์"];
                    $sheet->setWidth('A', 38);
                    $sheet->setWidth('B', 20);
                    $sheet->setWidth('C', 20);
                    $sheet->setWidth('D', 25);
                    $sheet->appendRow($headers);
                }
                $sheet->getStyle('A1:J1')->getAlignment()->applyFromArray(
                    array('horizontal' => 'center','vertical' => 'center')
                );
            });
            if($form_id ==1 )
                AppraisalService::createMasterSheet($excel);
        })->export('xlsx');
    }

    public function importMaster(Request $request){
        set_time_limit(0);
        $structure_id = $request->structure_id;
        $appraisalStructure = AppraisalStructureModel::find($structure_id);
        //$structure_name = $appraisalStructure->structure_name;
        $form_id = $appraisalStructure->form_id;
        $bank_values = [];
        $number_values = ['E','F','G','H','I','J','K'];
        $all_number_values = [];
        $header_values = ['A','B','C','D','E','F','G','H','I','J','K'];
        $master_key = ['I','J','K'];
        $master_service = ['0','1','2'];

        $fixed_values =  ['E','F','G','H'];
        $fixed_key =  ['0','1','1','0'];
        if($form_id==2) {
            $header_values = ['A'];
            $number_values = [];
            $master_key = [];
            $master_service = [];
            $fixed_values =  [];
            $fixed_key =  [];
        }
        else if($form_id==3) {
            $header_values = ['A','B','C','D'];
            $number_values = ['B', 'C', 'D'];
            $master_key = [];
            $master_service = [];
            $fixed_values =  [];
            $fixed_key =  [];
        }
        Log::info('form id['.$form_id.']');
        $appraialValidator = new AppraialValidator();
        $result_obj = $appraialValidator->validateTemplate(1,$request,$fixed_values,$fixed_key,$master_key,$master_service,$header_values,$bank_values,$number_values,$all_number_values);
        /* */
        if($result_obj->result_status == 1 )
            AppraisalService::importMaster($request);
         /* */
        return response()->json($result_obj, 200);
    }

    public function exportDetail(Request $request){
        $structure_id = $request->structure_id;
        $appraisalStructure = AppraisalStructureModel::find($structure_id);
        $structure_name = $appraisalStructure->structure_name;
        //$form_id = $appraisalStructure->form_id;
        $filename = "detail_import_".strtolower($structure_name);;

        Excel::create($filename, function($excel) use ($structure_id,$structure_name) {
            AppraisalService::createDetailSheet($structure_id,$structure_name,$excel);
            AppraisalService::createDetailMasterSheet($excel);
        })->export('xlsx');
    }

    public function importDetail(Request $request){
        set_time_limit(0);
        $bank_values = [];
        $number_values = ['A'];
        $all_number_values = ['D'];
        $header_values = ['A','B','C','D'];
        $master_key = ['D'];
        $master_service = ['0']; // start with 3 ( numberoffSheet )
        $fixed_values =  [];
        $fixed_key =  [];
        $appraialValidator = new AppraialValidator();
        $result_obj = $appraialValidator->validateTemplate(3,$request,$fixed_values,$fixed_key,$master_key,$master_service,$header_values,$bank_values,$number_values,$all_number_values);

        if($result_obj->result_status == 1 )
            AppraisalService::importDetail($request);

        return response()->json($result_obj, 200);
    }

}